<?php
require_once __DIR__ . '/../includes/http_safety.php';
header('Content-Type: application/json');
set_time_limit(120);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['error' => 'Invalid request method']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$urls = $input['urls'] ?? [];

if (empty($urls) || !is_array($urls)) {
    echo json_encode(['error' => 'No URLs provided']);
    exit;
}

// Giới hạn số lượng URLs để tránh timeout
$urls = array_slice($urls, 0, 50);
$results = [];

foreach ($urls as $url) {
    $size = getFileSize($url);
    $results[$url] = $size;
}

echo json_encode(['sizes' => $results]);

function getFileSize($url) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_NOBODY => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 3,
        CURLOPT_TIMEOUT => 5,
        CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36'
    ]);
    
    wc_apply_http_safety($ch);
    curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $contentLength = curl_getinfo($ch, CURLINFO_CONTENT_LENGTH_DOWNLOAD);
    curl_close($ch);
    
    if ($httpCode >= 200 && $httpCode < 400 && $contentLength > 0) {
        return formatFileSize($contentLength);
    }
    
    return 'Unknown';
}

function formatFileSize($bytes) {
    if ($bytes >= 1073741824) {
        return number_format($bytes / 1073741824, 2) . ' GB';
    } elseif ($bytes >= 1048576) {
        return number_format($bytes / 1048576, 2) . ' MB';
    } elseif ($bytes >= 1024) {
        return number_format($bytes / 1024, 2) . ' KB';
    } else {
        return $bytes . ' B';
    }
}
