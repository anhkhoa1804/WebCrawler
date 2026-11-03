<?php
set_time_limit(300);

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

// Keep the name valid inside the quoted Content-Disposition value (no quotes, slashes, semicolons or control characters).
$filename = preg_replace('#[\x00-\x1F"\x5C/;]+#', '_', (string)$filename);

$parsed = parse_url($url);
$host = strtolower((string)($parsed['host'] ?? ''));

$tempDir = sys_get_temp_dir();
$tempFile = tempnam($tempDir, 'wc_dl_');
if (!$tempFile) {
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Failed to create temp file']);
    exit;
}

$fp = @fopen($tempFile, 'wb');
if (!$fp) {
    @unlink($tempFile);
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Failed to open temp file']);
    exit;
}

$ch = curl_init($url);
curl_setopt($ch, CURLOPT_FILE, $fp);
curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 60);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36');

// Some providers (notably TikTok) behave better with a proper Referer/Origin.
$headers = [
    'Accept: */*',
    'Accept-Language: en-US,en;q=0.9',
];
if ($host && (str_contains($host, 'tiktok.com') || str_contains($host, 'tiktokcdn') || str_contains($host, 'tiktokv') || str_contains($host, 'byteoversea') || str_contains($host, 'ibytedtos'))) {
    $headers[] = 'Referer: https://www.tiktok.com/';
    $headers[] = 'Origin: https://www.tiktok.com';
}
curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
curl_setopt($ch, CURLOPT_ENCODING, '');

$result = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
curl_close($ch);
fclose($fp);

$downloadSize = @filesize($tempFile);

if (!$result || $httpCode < 200 || $httpCode >= 300 || !$downloadSize || $downloadSize <= 0) {
    @unlink($tempFile);
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode([
        'error' => 'Failed to download file',
        'http_code' => $httpCode,
        'content_type' => $contentType,
    ]);
    exit;
}

// If the crawler had to guess an extension (e.g. .bin/.dat), try to improve it using Content-Type.
if (is_string($filename) && $filename !== '' && is_string($contentType) && $contentType !== '') {
    $lowerName = strtolower($filename);
    $needsFix = (
        !str_contains($lowerName, '.') ||
        str_ends_with($lowerName, '.bin') ||
        str_ends_with($lowerName, '.dat')
    );

    if ($needsFix) {
        $ext = null;
        $ct = strtolower(trim(explode(';', $contentType, 2)[0]));

        if ($ct === 'video/mp4' || $ct === 'application/mp4') {
            $ext = 'mp4';
        } elseif ($ct === 'video/webm') {
            $ext = 'webm';
        } elseif ($ct === 'audio/mpeg' || $ct === 'audio/mp3') {
            $ext = 'mp3';
        } elseif ($ct === 'audio/mp4' || $ct === 'audio/aac') {
            $ext = 'm4a';
        } elseif ($ct === 'video/mp2t') {
            $ext = 'ts';
        }

        if ($ext) {
            $base = preg_replace('/\.(bin|dat)$/i', '', $filename);
            $filename = $base . '.' . $ext;
        }
    }
}

// Protect users from downloading an HTML/JSON error page as .mp4/.mp3.
$ct = strtolower(trim((string)($contentType ?? '')));
$ctMain = $ct ? trim(explode(';', $ct, 2)[0]) : '';
$looksLikeText = ($ctMain === 'text/html' || $ctMain === 'application/json' || str_starts_with($ctMain, 'text/'));
if ($looksLikeText && $downloadSize > 0 && $downloadSize < 200000) {
    $snippet = '';
    $rfp = @fopen($tempFile, 'rb');
    if ($rfp) {
        $snippet = (string)@fread($rfp, 800);
        @fclose($rfp);
    }
    @unlink($tempFile);
    header('Content-Type: application/json');
    http_response_code(502);
    echo json_encode([
        'error' => 'Upstream did not return a media file',
        'message' => 'The source returned text/JSON instead of media (likely blocked, expired, or needs cookies).',
        'http_code' => $httpCode,
        'content_type' => $contentType,
        'body_snippet' => $snippet,
    ]);
    exit;
}

// Validate MP4 payloads: TikTok sometimes returns fMP4 fragments (moof/styp) or blocked HTML.
$lowerName = strtolower((string)$filename);
$ctLower = strtolower((string)($contentType ?? ''));
$expectsMp4 = str_ends_with($lowerName, '.mp4') || str_contains($ctLower, 'video/mp4') || str_contains($ctLower, 'application/mp4');
if ($expectsMp4 && $downloadSize >= 12) {
    $probe = '';
    $rfp = @fopen($tempFile, 'rb');
    if ($rfp) {
        $probe = (string)@fread($rfp, 64);
        @fclose($rfp);
    }

    $box = '';
    if (strlen($probe) >= 8) {
        $box = substr($probe, 4, 4);
    }

    // Common MP4/fMP4 boxes at start: ftyp (init), styp/moof (fragment), sidx (index)
    if ($box !== 'ftyp') {
        // If it looks like text/json but content-type lied, still block it.
        $trim = ltrim($probe);
        $looksLikeJsonOrHtml = ($trim !== '' && (str_starts_with($trim, '{') || str_starts_with($trim, '[') || str_starts_with($trim, '<')));
        $isFragment = in_array($box, ['styp', 'moof', 'sidx'], true);

        if ($looksLikeJsonOrHtml || $isFragment) {
            @unlink($tempFile);
            header('Content-Type: application/json');
            http_response_code(502);
            echo json_encode([
                'error' => 'Downloaded content is not a playable MP4 file',
                'message' => $isFragment
                    ? ('The URL returned an MP4 fragment (box=' . $box . '), not a complete file. This usually needs an init segment and muxing (FFmpeg) to become playable.')
                    : 'The source returned non-video content (likely blocked/expired or needs cookies).',
                'http_code' => $httpCode,
                'content_type' => $contentType,
                'size' => $downloadSize,
                'mp4_box' => $box ?: null,
                'probe_ascii' => substr($probe, 0, 120),
            ]);
            exit;
        }
    }
}

header('Content-Type: ' . ($contentType ?: 'application/octet-stream'));
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Content-Length: ' . $downloadSize);
header('Cache-Control: no-cache, must-revalidate');
header('Pragma: public');

$out = @fopen($tempFile, 'rb');
if ($out) {
    while (!feof($out)) {
        echo fread($out, 8192);
        flush();
    }
    fclose($out);
}

@unlink($tempFile);
?>