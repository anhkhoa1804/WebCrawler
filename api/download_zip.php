<?php
require_once __DIR__ . '/../includes/http_safety.php';
set_time_limit(300);
ini_set('memory_limit', '256M'); // Still needed for ZIP operations

// Check ZIP extension
if (!class_exists('ZipArchive')) {
    header('Content-Type: application/json');
    http_response_code(500);
    echo json_encode(['error' => 'ZIP extension not enabled']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);

if (!isset($input['files']) || !is_array($input['files'])) {
    header('Content-Type: application/json');
    http_response_code(400);
    echo json_encode(['error' => 'Invalid request']);
    exit;
}

$files = $input['files'];

if (empty($files)) {
    header('Content-Type: application/json');
    http_response_code(400);
    echo json_encode(['error' => 'No files provided']);
    exit;
}

// SOLUTION: Use streaming download instead of loading into memory
$tempDir = sys_get_temp_dir();
$zipFilename = $tempDir . '/crawler_' . uniqid() . '.zip';
$zip = new ZipArchive();

if ($zip->open($zipFilename, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== TRUE) {
    header('Content-Type: application/json');
    http_response_code(500);
    echo json_encode(['error' => 'Could not create ZIP']);
    exit;
}

$successCount = 0;
$failedFiles = [];
$addedNames = [];

foreach ($files as $fileUrl) {
    try {
        $path = parse_url($fileUrl, PHP_URL_PATH);
        // Decode before basename so an encoded separator (%2F) cannot form a ../ entry name inside the ZIP.
        $filename = basename(urldecode((string)$path));
        
        if (empty($filename) || $filename == '/' || $filename == '.') {
            $filename = 'file_' . uniqid() . '.dat';
        }
        
        // FIX: Download to temp file instead of memory
        $tempFile = $tempDir . '/dl_' . uniqid() . '.tmp';
        $fp = fopen($tempFile, 'w+');
        
        if (!$fp) {
            error_log("[ZIP] Failed to create temp file: $tempFile");
            continue;
        }
        
        $ch = curl_init($fileUrl);
        curl_setopt($ch, CURLOPT_FILE, $fp); // Write directly to file
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        wc_apply_http_safety($ch);
        curl_setopt($ch, CURLOPT_TIMEOUT, 60);
        curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (compatible; WebCrawler/1.0)');
        
        $result = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        curl_close($ch);
        fclose($fp);
        
        if ($result && $httpCode >= 200 && $httpCode < 300 && file_exists($tempFile) && filesize($tempFile) > 0) {
            // If filename has no useful extension, infer from Content-Type.
            if (is_string($filename) && $filename !== '') {
                $lowerName = strtolower($filename);
                $hasDot = str_contains($lowerName, '.');
                $isBin = str_ends_with($lowerName, '.bin') || str_ends_with($lowerName, '.dat');
                if ((!$hasDot || $isBin) && is_string($contentType) && $contentType !== '') {
                    $ct = strtolower(trim(explode(';', $contentType, 2)[0]));
                    $ext = null;
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

            // Handle duplicate names
            $baseName = pathinfo($filename, PATHINFO_FILENAME);
            $extension = pathinfo($filename, PATHINFO_EXTENSION);
            $finalFilename = $filename;
            $counter = 1;
            
            while (isset($addedNames[$finalFilename])) {
                $finalFilename = $baseName . '_' . $counter . ($extension ? '.' . $extension : '');
                $counter++;
            }
            
            // Add from file (no memory spike!)
            if ($zip->addFile($tempFile, $finalFilename)) {
                $addedNames[$finalFilename] = $tempFile; // Track for cleanup
                $successCount++;
            } else {
                @unlink($tempFile);
                $failedFiles[] = $filename;
            }
        } else {
            @unlink($tempFile);
            $failedFiles[] = $filename;
        }
    } catch (Exception $e) {
        $failedFiles[] = basename($fileUrl);
    }
}

$zip->close();

// Cleanup temp files after ZIP is created
foreach ($addedNames as $finalName => $tempPath) {
    if (file_exists($tempPath)) {
        @unlink($tempPath);
    }
}

// Check if ZIP has content
if ($successCount === 0 || !file_exists($zipFilename) || filesize($zipFilename) < 10) {
    if(file_exists($zipFilename)) @unlink($zipFilename);
    header('Content-Type: application/json');
    http_response_code(500);
    echo json_encode([
        'error' => 'Failed to create ZIP. No files downloaded.',
        'failed' => $failedFiles
    ]);
    exit;
}

error_log("[ZIP] Created archive with $successCount files, size: " . filesize($zipFilename) . " bytes");

// Send ZIP file
header('Content-Type: application/zip');
header('Content-Disposition: attachment; filename="crawled_files_' . date('Y-m-d_H-i-s') . '.zip"');
header('Content-Length: ' . filesize($zipFilename));
header('Pragma: public');
header('Cache-Control: must-revalidate');
header('Expires: 0');

// Stream file in chunks (avoid loading entire ZIP into memory)
$handle = fopen($zipFilename, 'rb');
while (!feof($handle)) {
    echo fread($handle, 8192); // 8KB chunks
    flush();
}
fclose($handle);

@unlink($zipFilename);
exit;
?>