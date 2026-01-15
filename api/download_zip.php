<?php
set_time_limit(300); // 5 minutes
ini_set('memory_limit', '256M');

// Check if ZIP extension is available
if (!class_exists('ZipArchive')) {
    header('Content-Type: application/json');
    http_response_code(500);
    echo json_encode([
        'error' => 'ZIP extension is not enabled in PHP. Please enable it in php.ini and restart Apache.',
        'details' => 'Add or uncomment: extension=zip'
    ]);
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

$tempDir = sys_get_temp_dir();
$zipFilename = $tempDir . '/crawler_' . uniqid() . '.zip';
$zip = new ZipArchive();

if ($zip->open($zipFilename, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== TRUE) {
    header('Content-Type: application/json');
    http_response_code(500);
    echo json_encode(['error' => 'Could not create ZIP file']);
    exit;
}

$successCount = 0;
$failedFiles = [];
$addedNames = [];

foreach ($files as $fileUrl) {
    try {
        // Sanitize filename
        $path = parse_url($fileUrl, PHP_URL_PATH);
        $filename = urldecode(basename($path));
        
        // Fallback filename if empty
        if (empty($filename) || $filename == '/' || $filename == '.') {
            $filename = 'file_' . uniqid() . '.dat';
        }
        
        // Download file content
        $ch = curl_init($fileUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); // Allow generic downloads
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (compatible; WebCrawler/1.0)');
        
        $fileContent = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode >= 200 && $httpCode < 300 && $fileContent !== false) {
            // Handle duplicate filenames inside ZIP
            $baseName = pathinfo($filename, PATHINFO_FILENAME);
            $extension = pathinfo($filename, PATHINFO_EXTENSION);
            $finalFilename = $filename;
            $counter = 1;
            
            while (isset($addedNames[$finalFilename])) {
                $finalFilename = $baseName . '_' . $counter . ($extension ? '.' . $extension : '');
                $counter++;
            }
            
            $zip->addFromString($finalFilename, $fileContent);
            $addedNames[$finalFilename] = true;
            $successCount++;
        } else {
            $failedFiles[] = $filename;
        }
    } catch (Exception $e) {
        $failedFiles[] = basename($fileUrl);
    }
}

$zip->close();

// Check if ZIP has content
if ($successCount === 0 || !file_exists($zipFilename) || filesize($zipFilename) < 10) {
    if(file_exists($zipFilename)) @unlink($zipFilename);
    header('Content-Type: application/json');
    http_response_code(500);
    echo json_encode([
        'error' => 'Failed to create ZIP. No files could be downloaded.',
        'failed' => $failedFiles
    ]);
    exit;
}

// Send the ZIP file
header('Content-Type: application/zip');
header('Content-Disposition: attachment; filename="crawled_files_' . date('Y-m-d_H-i-s') . '.zip"');
header('Content-Length: ' . filesize($zipFilename));
header('Pragma: public');
header('Cache-Control: must-revalidate, post-check=0, pre-check=0');
header('Expires: 0');

readfile($zipFilename);
@unlink($zipFilename);
exit;
?>