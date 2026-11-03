<?php
/**
 * FFmpeg Streaming Downloader
 * Converts HLS (.m3u8) and DASH (.mpd) streams to MP4/MP3
 * 
 * Requirements:
 * - FFmpeg installed: https://ffmpeg.org/download.html
 * - Add ffmpeg to PATH or set full path below
 */

class StreamingDownloader {
    // Limits which protocols FFmpeg may open, including URLs referenced from inside a playlist.
    // Without this, a crafted playlist could point FFmpeg at local files (file:) and copy them out.
    private const PROTOCOL_WHITELIST = 'http,https,tcp,tls,crypto';

    private $ffmpegPath = 'ffmpeg';
    private $tempDir;
    private $timeout = 300; // 5 minutes max per file
    
    public function __construct($options = []) {
        $this->ffmpegPath = $options['ffmpeg_path'] ?? $this->detectFfmpegPath();
        $this->tempDir = $options['temp_dir'] ?? sys_get_temp_dir();
        $this->timeout = $options['timeout'] ?? 300;
        
        // Check if FFmpeg is available
        if (!$this->isFFmpegAvailable()) {
            error_log("[STREAM] FFmpeg not found. Please install: https://ffmpeg.org/download.html");
        }
    }
    
    /**
     * Check if FFmpeg is installed and accessible
     */
    public function isFFmpegAvailable() {
        $cmd = escapeshellarg($this->ffmpegPath) . ' -version 2>&1';
        $output = shell_exec($cmd);
        return strpos($output, 'ffmpeg version') !== false;
    }

    private function detectFfmpegPath() {
        $envPath = getenv('FFMPEG_PATH');
        if ($envPath && file_exists($envPath)) {
            return $envPath;
        }

        $projectRoot = dirname(__DIR__);
        $candidates = glob($projectRoot . DIRECTORY_SEPARATOR . 'ffmpeg-*' . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'ffmpeg.exe');
        if ($candidates && file_exists($candidates[0])) {
            return $candidates[0];
        }

        // Fallback to PATH
        return 'ffmpeg';
    }
    
    /**
     * Download HLS stream (.m3u8) to MP4 or MP3
     * @param string $streamUrl - URL to .m3u8 file
     * @param string $outputFormat - 'mp4' for video, 'mp3' for audio
     * @return string|false - Path to downloaded file or false on error
     */
    public function downloadHLS($streamUrl, $outputFormat = 'mp4') {
        if (!$this->isFFmpegAvailable()) {
            error_log("[STREAM] FFmpeg not available");
            return false;
        }
        
        $outputFile = $this->tempDir . '/stream_' . uniqid() . '.' . $outputFormat;
        
        error_log("[STREAM] Downloading HLS: $streamUrl → $outputFile");
        
        // Build FFmpeg command
        // -i: input stream URL
        // -c copy: copy codec without re-encoding (fastest)
        // -bsf:a aac_adtstoasc: fix AAC stream format
        // -movflags +faststart: optimize for web streaming
        
        // Every externally supplied value is passed through escapeshellarg: the stream URL comes from the request.
        $command = sprintf(
            '%s -protocol_whitelist ' . self::PROTOCOL_WHITELIST . ' -i %s -c copy -bsf:a aac_adtstoasc -movflags +faststart %s 2>&1',
            escapeshellarg($this->ffmpegPath),
            escapeshellarg($streamUrl),
            escapeshellarg($outputFile)
        );
        
        // For audio extraction (MP3)
        if ($outputFormat === 'mp3') {
            $command = sprintf(
                '%s -protocol_whitelist ' . self::PROTOCOL_WHITELIST . ' -i %s -vn -acodec libmp3lame -q:a 2 %s 2>&1',
                escapeshellarg($this->ffmpegPath),
                escapeshellarg($streamUrl),
                escapeshellarg($outputFile)
            );
        }
        
        // Execute with timeout
        $output = $this->execWithTimeout($command, $this->timeout);
        
        if (file_exists($outputFile) && filesize($outputFile) > 1000) {
            error_log("[STREAM] Success! Downloaded " . filesize($outputFile) . " bytes");
            return $outputFile;
        }
        
        error_log("[STREAM] Download failed. FFmpeg output: " . substr($output, -500));
        return false;
    }
    
    /**
     * Download DASH stream (.mpd) to MP4
     */
    public function downloadDASH($streamUrl, $outputFormat = 'mp4') {
        // DASH is similar to HLS, FFmpeg handles it the same way
        return $this->downloadHLS($streamUrl, $outputFormat);
    }
    
    /**
     * Auto-detect stream type and download
     */
    public function download($streamUrl, $outputFormat = 'mp4') {
        if (strpos($streamUrl, '.m3u8') !== false) {
            return $this->downloadHLS($streamUrl, $outputFormat);
        } elseif (strpos($streamUrl, '.mpd') !== false) {
            return $this->downloadDASH($streamUrl, $outputFormat);
        }
        
        error_log("[STREAM] Unknown stream type: $streamUrl");
        return false;
    }
    
    /**
     * Get stream info using ffprobe
     */
    public function getStreamInfo($streamUrl) {
        if (!$this->isFFmpegAvailable()) {
            return null;
        }
        
        // Replace only the executable name; the directory may also contain "ffmpeg" (e.g. ffmpeg-8.0.1-full_build).
        $ffprobe = preg_replace('/ffmpeg(\.exe)?$/i', 'ffprobe$1', $this->ffmpegPath);
        
        $command = sprintf(
            '%s -v quiet -protocol_whitelist ' . self::PROTOCOL_WHITELIST . ' -print_format json -show_format -show_streams %s 2>&1',
            escapeshellarg($ffprobe),
            escapeshellarg($streamUrl)
        );
        
        $output = shell_exec($command);
        $info = json_decode($output, true);
        
        if (!$info) {
            return null;
        }
        
        // Extract useful info
        $result = [
            'duration' => $info['format']['duration'] ?? 0,
            'size' => $info['format']['size'] ?? 0,
            'bitrate' => $info['format']['bit_rate'] ?? 0,
            'has_video' => false,
            'has_audio' => false,
        ];
        
        foreach ($info['streams'] ?? [] as $stream) {
            if ($stream['codec_type'] === 'video') {
                $result['has_video'] = true;
                $result['video_codec'] = $stream['codec_name'] ?? 'unknown';
                $result['width'] = $stream['width'] ?? 0;
                $result['height'] = $stream['height'] ?? 0;
            } elseif ($stream['codec_type'] === 'audio') {
                $result['has_audio'] = true;
                $result['audio_codec'] = $stream['codec_name'] ?? 'unknown';
                $result['sample_rate'] = $stream['sample_rate'] ?? 0;
            }
        }
        
        return $result;
    }
    
    /**
     * Run a command with a hard time limit.
     * Non-Windows: the coreutils `timeout` command ends the process.
     * Windows: the command runs as a child process with its output redirected to a temp log.
     * When the limit is reached, only that child's process tree is stopped (taskkill /T on its PID).
     */
    private function execWithTimeout($command, $timeout) {
        if (PHP_OS_FAMILY !== 'Windows') {
            return shell_exec("timeout {$timeout}s $command");
        }

        // Redirect to a log file (not a pipe) so a chatty FFmpeg cannot block on a full buffer.
        $logFile = tempnam($this->tempDir, 'ffmpeg_log_');
        $base = preg_replace('/\s*2>&1$/', '', $command);
        $full = $base . ' > ' . escapeshellarg($logFile) . ' 2>&1';

        $descriptors = [
            0 => ['file', 'NUL', 'r'],
            1 => ['file', 'NUL', 'w'],
            2 => ['file', 'NUL', 'w'],
        ];
        $proc = proc_open($full, $descriptors, $pipes);
        if (!is_resource($proc)) {
            @unlink($logFile);
            return '';
        }

        $started = microtime(true);
        while (true) {
            $status = proc_get_status($proc);
            if (!$status['running']) {
                break;
            }
            if (microtime(true) - $started >= $timeout) {
                error_log("[STREAM] FFmpeg exceeded {$timeout}s and was stopped");
                exec('taskkill /F /T /PID ' . (int)$status['pid'] . ' >NUL 2>&1');
                break;
            }
            usleep(200000);
        }
        proc_close($proc);

        $output = (string)@file_get_contents($logFile);
        @unlink($logFile);
        return $output;
    }
    
    /**
     * Estimate download size before downloading
     */
    public function estimateSize($streamUrl) {
        $info = $this->getStreamInfo($streamUrl);
        
        if (!$info) {
            return 0;
        }
        
        // If size is known
        if (!empty($info['size'])) {
            return (int)$info['size'];
        }
        
        // Estimate from bitrate and duration
        if (!empty($info['bitrate']) && !empty($info['duration'])) {
            return (int)(($info['bitrate'] * $info['duration']) / 8);
        }
        
        return 0;
    }
    
    /**
     * Check if URL is a streaming URL
     * Matches .m3u8, .mpd, or streaming API endpoints
     */
    public static function isStreamingUrl($url) {
        // Be conservative: only accept URLs that clearly identify a streaming manifest.
        // This prevents false-positives like direct MP4 URLs containing "stream" in the path.
        if (!is_string($url) || $url === '') {
            return false;
        }

        // Only remote http(s) manifests are accepted; file:, ftp: and other schemes are refused.
        $scheme = strtolower((string)parse_url($url, PHP_URL_SCHEME));
        if ($scheme !== 'http' && $scheme !== 'https') {
            return false;
        }

        $u = $url;

        if (preg_match('/\.m3u8(\?|$)/i', $u)) {
            return true;
        }
        if (preg_match('/\.mpd(\?|$)/i', $u)) {
            return true;
        }
        if (preg_match('/[?&](format|type)=m3u8/i', $u)) {
            return true;
        }
        if (preg_match('/[?&](format|type)=mpd/i', $u)) {
            return true;
        }

        return false;
    }
}
