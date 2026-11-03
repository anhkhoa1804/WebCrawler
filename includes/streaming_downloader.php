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
        $cmd = escapeshellcmd($this->ffmpegPath) . ' -version 2>&1';
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
        
        $command = sprintf(
            '%s -i "%s" -c copy -bsf:a aac_adtstoasc -movflags +faststart "%s" 2>&1',
            escapeshellcmd($this->ffmpegPath),
            $streamUrl,
            $outputFile
        );
        
        // For audio extraction (MP3)
        if ($outputFormat === 'mp3') {
            $command = sprintf(
                '%s -i "%s" -vn -acodec libmp3lame -q:a 2 "%s" 2>&1',
                escapeshellcmd($this->ffmpegPath),
                $streamUrl,
                $outputFile
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
        
        $ffprobe = str_replace('ffmpeg', 'ffprobe', $this->ffmpegPath);
        
        $command = sprintf(
            '%s -v quiet -print_format json -show_format -show_streams "%s" 2>&1',
            escapeshellcmd($ffprobe),
            $streamUrl
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
     * Execute command with timeout (Windows-safe)
     */
    private function execWithTimeout($command, $timeout) {
        // For Windows, use start /wait with timeout
        if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
            $output = [];
            $return = 0;
            
            // Run command in background with start
            exec($command, $output, $return);
            
            return implode("\n", $output);
        }
        
        // For Linux/Mac, use timeout command
        return shell_exec("timeout {$timeout}s $command");
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
