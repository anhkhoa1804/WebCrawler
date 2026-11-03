<?php
/**
 * Download Streaming File API
 * Handles .m3u8 (HLS) and .mpd (DASH) streams using FFmpeg
 */

set_time_limit(600); // 10 minutes for large streams
ini_set('memory_limit', '256M');

require_once __DIR__ . '/../includes/streaming_downloader.php';

header('Content-Type: application/json');

// Get URL from request
$url = $_GET['url'] ?? $_POST['url'] ?? null;
$format = $_GET['format'] ?? $_POST['format'] ?? 'mp4';

if (!$url) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing URL parameter']);
    exit;
}

// Validate format
if (!in_array($format, ['mp4', 'mp3'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid format. Use mp4 or mp3']);
    exit;
}

// Check if it's a streaming URL
if (!StreamingDownloader::isStreamingUrl($url)) {
    http_response_code(400);
    echo json_encode(['error' => 'Not a streaming URL (.m3u8 or .mpd)']);
    exit;
}

$downloader = new StreamingDownloader();

// Check FFmpeg availability
if (!$downloader->isFFmpegAvailable()) {
    http_response_code(500);
    echo json_encode([
        'error' => 'FFmpeg not installed',
        'message' => 'Please install FFmpeg: https://ffmpeg.org/download.html',
        'help' => 'Windows: Download from https://www.gyan.dev/ffmpeg/builds/ and add to PATH'
    ]);
    exit;
}

// Check if this is an info request
if (isset($_GET['info']) && $_GET['info'] == '1') {
    $info = $downloader->getStreamInfo($url);
    
    if (!$info) {
        http_response_code(500);
        echo json_encode(['error' => 'Failed to get stream info']);
        exit;
    }
    
    echo json_encode([
        'success' => true,
        'info' => $info,
        'estimated_size' => $downloader->estimateSize($url),
        'recommended_format' => $info['has_video'] ? 'mp4' : 'mp3'
    ]);
    exit;
}

// Download stream
error_log("[STREAM API] Downloading: $url → $format");

$tempFile = $downloader->download($url, $format);

if (!$tempFile) {
    http_response_code(500);
    echo json_encode([
        'error' => 'Download failed',
        'message' => 'FFmpeg could not convert the stream. Check if URL is valid.',
        'url' => $url
    ]);
    exit;
}

// Determine filename
$parsedUrl = parse_url($url);
$pathInfo = pathinfo($parsedUrl['path'] ?? 'stream');
$filename = ($pathInfo['filename'] ?? 'stream') . '.' . $format;
$filename = preg_replace('/[^a-zA-Z0-9_\-\.]/', '_', $filename);

// Send file to browser
header('Content-Type: ' . ($format === 'mp4' ? 'video/mp4' : 'audio/mpeg'));
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Content-Length: ' . filesize($tempFile));
header('Accept-Ranges: bytes');
header('Cache-Control: no-cache');

// Stream file in chunks
$handle = fopen($tempFile, 'rb');
while (!feof($handle)) {
    echo fread($handle, 8192);
    flush();
}
fclose($handle);

// Cleanup
@unlink($tempFile);

error_log("[STREAM API] Download complete: " . filesize($tempFile) . " bytes");
exit;
