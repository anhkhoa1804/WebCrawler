<?php
set_time_limit(60);

$input = json_decode(file_get_contents('php://input'), true);

if (!isset($input['url'])) {
    http_response_code(400);
    echo json_encode(['error' => 'URL is required']);
    exit;
}

$url = $input['url'];
$filename = $input['filename'] ?? basename(parse_url($url, PHP_URL_PATH));

if (empty($filename) || $filename === '/' || $filename === '.') {
    $filename = 'download_' . time() . '.dat';
}

$ch = curl_init($url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 60);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36');

$content = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
curl_close($ch);

if ($httpCode >= 200 && $httpCode < 300 && $content !== false) {
    header('Content-Type: ' . ($contentType ?: 'application/octet-stream'));
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . strlen($content));
    header('Cache-Control: no-cache, must-revalidate');
    header('Pragma: public');
    
    echo $content;
} else {
    http_response_code(500);
    echo json_encode(['error' => 'Failed to download file']);
}
?>