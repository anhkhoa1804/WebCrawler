<?php
set_time_limit(600);
ini_set('display_errors', 0);
error_reporting(E_ALL);

function isLocalRequest() {
    $addr = $_SERVER['REMOTE_ADDR'] ?? '';
    return ($addr === '127.0.0.1' || $addr === '::1' || $addr === '');
}

// Debug mode is only enabled for localhost requests
$debugMode = (isset($_GET['debug']) && $_GET['debug'] == '1' && isLocalRequest());

if ($debugMode) {
    ini_set('display_errors', 1);
}

// Log file for debugging
$logFile = __DIR__ . '/../data/crawl_debug.log';
function debugLog($message) {
    global $logFile;
    // Keep the log bounded: once it passes 1 MB, move it aside (one previous copy is kept as .1).
    if (is_file($logFile) && filesize($logFile) > 1048576) {
        @rename($logFile, $logFile . '.1');
    }
    $timestamp = date('Y-m-d H:i:s');
    file_put_contents($logFile, "[$timestamp] $message\n", FILE_APPEND);
}

debugLog("=== CRAWL REQUEST RECEIVED ===");

register_shutdown_function(function() {
    $error = error_get_last();
    if ($error && ($error['type'] === E_ERROR || $error['type'] === E_PARSE)) {
        while (ob_get_level()) ob_end_clean();
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Fatal Error: ' . $error['message']]);
        exit;
    }
});

ob_start();
header('Content-Type: application/json');

function tailTextFile($path, $maxBytes = 24576) {
    if (!$path || !is_string($path) || !file_exists($path)) return null;
    $size = filesize($path);
    if ($size === false || $size <= 0) return '';

    $readBytes = min((int)$maxBytes, (int)$size);
    $fp = @fopen($path, 'rb');
    if (!$fp) return null;
    @fseek($fp, -$readBytes, SEEK_END);
    $data = @fread($fp, $readBytes);
    @fclose($fp);
    if ($data === false) return null;
    return str_replace("\r\n", "\n", $data);
}

// FIX #7: Read-only session access for progress checks
if (isset($_GET['progress'])) {
    // IMPORTANT: load the session id from the PHPSESSID cookie.
    // Without session_start(), session_id() may be empty under Apache.
    if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
        // Read-only session open to avoid locking the crawler request.
        @session_start(['read_and_close' => true]);
    }

    $sid = session_id();

    // Optional sid override for debugging (avoids cookie/session issues)
    if ($debugMode && !$sid && !empty($_GET['sid']) && preg_match('/^[A-Za-z0-9,-]{8,128}$/', (string)$_GET['sid'])) {
        $sid = (string)$_GET['sid'];
    }
    if (!$sid && !empty($_COOKIE['PHPSESSID'])) {
        $sid = (string)$_COOKIE['PHPSESSID'];
    }

    // Session IDs are limited to safe characters; a crafted PHPSESSID cookie must not reach the file path.
    if (!preg_match('/^[A-Za-z0-9,-]{8,128}$/', (string)$sid)) {
        $sid = '';
    }

    // Use file-based progress instead of session to avoid locking
    $progressFile = sys_get_temp_dir() . '/crawler_progress_' . $sid . '.json';
    
    if ($sid && file_exists($progressFile)) {
        $progress = json_decode(file_get_contents($progressFile), true);
        if (!is_array($progress)) {
            $progress = ['current' => 0, 'total' => 0, 'status' => 'idle'];
        }
    } else {
        $progress = ['current' => 0, 'total' => 0, 'status' => 'idle'];
    }

    // Help debugging mismatched sessions
    $progress['sid'] = $sid ?: null;

    if ($debugMode) {
        $progress['debug'] = [
            'remote_addr' => $_SERVER['REMOTE_ADDR'] ?? null,
            'temp_dir' => sys_get_temp_dir(),
            'progress_file' => $progressFile,
            'progress_file_exists' => (bool)($sid && file_exists($progressFile)),
            'progress_file_mtime' => ($sid && file_exists($progressFile)) ? @filemtime($progressFile) : null,
        ];
        $tail = tailTextFile($logFile, 16384);
        if ($tail !== null) {
            $progress['debug_log_tail'] = $tail;
        }
    }
    
    ob_clean();
    echo json_encode($progress);
    exit;
}

if (file_exists(__DIR__ . '/crawler.php')) {
    require_once __DIR__ . '/crawler.php';
} elseif (file_exists(__DIR__ . '/../includes/crawler.php')) {
    require_once __DIR__ . '/../includes/crawler.php';
} else {
    ob_clean();
    echo json_encode(['error' => 'crawler.php not found']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    ob_clean();
    echo json_encode(['error' => 'Invalid request method']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
if (!isset($input['url'])) {
    ob_clean();
    echo json_encode(['error' => 'Missing URL']);
    exit;
}

try {
    $url = $input['url'];
    $extensions = $input['extensions'] ?? [];
    $maxDepth = (int)($input['maxDepth'] ?? 2);
    $maxPages = (int)($input['maxPages'] ?? 50);
    
    debugLog("URL: $url");
    debugLog("Extensions: " . json_encode($extensions));
    debugLog("Depth: $maxDepth, Pages: $maxPages");
    
    // Enable database and parallel processing by default
    $options = [
        'use_database' => true,
        'use_parallel' => true,
        'parallel_connections' => 5,
        'timeout' => 15
    ];
    
    // Auto-detect if headless browser needed for JavaScript-heavy sites
    $jsHeavySites = ['zing.vn', 'zingmp3.vn', 'mp3.zing.vn', 'nhaccuatui.com', 'tiktok.com', 'shopee.vn', 'tiki.vn', 'lazada.vn', 'sendo.vn', 'facebook.com', 'instagram.com'];
    $needsHeadless = false;
    foreach ($jsHeavySites as $site) {
        if (stripos($url, $site) !== false) {
            $needsHeadless = true;
            break;
        }
    }
    
    // Enable headless browser if auto-detected or manually requested
    if ($needsHeadless || (isset($input['use_headless']) && $input['use_headless'])) {
        $options['use_headless'] = true;
        // Let HeadlessBrowser class auto-detect (Panther -> Puppeteer -> cURL)
        // Don't force a specific mode
        error_log("[CRAWL] Headless browser enabled for: $url");
    }

    // Strict Zing media mode: only return Puppeteer-captured media (reduces unrelated captures)
    $host = strtolower((string)(parse_url($url, PHP_URL_HOST) ?? ''));
    $isZing = (str_contains($host, 'zingmp3.vn') || str_contains($host, 'zing.vn') || str_contains($host, 'mp3.zing.vn'));
    $isNct = str_contains($host, 'nhaccuatui.com');
    $hasAudioExt = false;
    foreach ((array)$extensions as $ext) {
        $e = strtolower((string)$ext);
        if (in_array($e, ['.mp3', '.m4a', '.aac', '.flac', '.wav', '.ogg', '.opus', '.wma', '.mid', '.oga', '.ape', '.alac'], true)) {
            $hasAudioExt = true;
            break;
        }
    }
    $strictRequested = !empty($input['strict_media_origin']);
    if (($strictRequested || ($isZing && $hasAudioExt)) && !empty($options['use_headless'])) {
        $options['strict_media_origin'] = true;
        // Puppeteer may traverse multiple song pages inside a single fetch; increase timeout.
        $options['timeout'] = 60;
        // Use UI maxPages as a "max songs" hint (cap to keep runtime bounded)
        $options['zing_max_songs'] = max(1, min((int)$maxPages, 30));
    }

    // Nhaccuatui playlist/pages often require playback + API parsing; increase timeout and pass song cap.
    if ($isNct && $hasAudioExt && !empty($options['use_headless'])) {
        $options['timeout'] = max((int)($options['timeout'] ?? 15), 60);
        $options['nct_max_songs'] = max(1, min((int)$maxPages, 30));
    }
    
    // Queue mode and login are not available in this build. Requests for them are ignored and reported in "warnings".
    $warnings = [];
    if ($maxPages > 200 || (isset($input['use_queue']) && $input['use_queue'])) {
        $warnings[] = 'Queue mode is not available in this build; the crawl ran synchronously.';
    }
    if (isset($input['login']) && is_array($input['login'])) {
        $warnings[] = 'Login is not available in this build; the login settings were ignored.';
    }
    
    if ($maxPages > 100) {
        $warnings[] = 'maxPages was capped at 100.';
        $maxPages = 100;
    }
    
    if (!empty($input['proxy_list']) && !empty($input['use_proxy'])) {
        $lines = explode("\n", $input['proxy_list']);
        foreach ($lines as $line) {
            $line = trim($line);
            if (!$line) continue;
            $line = preg_replace('#^https?://#', '', $line);
            $parts = explode(':', $line);
            if (count($parts) >= 2) {
                $p = ['host' => $parts[0] . ':' . $parts[1]];
                if (count($parts) >= 4) { $p['user'] = $parts[2]; $p['pass'] = $parts[3]; }
                $options['proxies'][] = $p;
            }
        }
    }

    $sidForResponse = null;
    if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
        session_start();
    }
    if (session_status() === PHP_SESSION_ACTIVE) {
        $sidForResponse = session_id();
        $_SESSION['crawl_progress'] = [
            'current' => 0,
            'total' => $maxPages,
            'status' => 'crawling'
        ];
        session_write_close();
    }

    $crawler = new WebCrawler($url, $extensions, $maxDepth, $maxPages, $options);
    
    debugLog("Crawler initialized, starting crawl...");
    
    if (ob_get_level() > 0) {
        @ob_flush();
        @flush();
    }
    
    $crawler->crawl();
    
    debugLog("Crawl completed");
    
    // Avoid session_start() here: output may have been flushed; progress is primarily file-based.
    
    $stats = $crawler->getStats();
    $stats['errors'] = $crawler->getErrors();
    
    $files = $crawler->getFoundFiles();
    debugLog("Files found: " . count($files));
    
    $response = [
        'success' => true,
        'sid' => $sidForResponse,
        'files' => $files,
        'stats' => $stats
    ];

    if ($debugMode) {
        $response['debug'] = [
            'remote_addr' => $_SERVER['REMOTE_ADDR'] ?? null,
            'php_sapi' => php_sapi_name(),
            'temp_dir' => sys_get_temp_dir(),
            'progress_file' => $sidForResponse ? (sys_get_temp_dir() . '/crawler_progress_' . $sidForResponse . '.json') : null,
            'progress_file_exists' => $sidForResponse ? file_exists(sys_get_temp_dir() . '/crawler_progress_' . $sidForResponse . '.json') : false,
        ];
        $tail = tailTextFile($logFile, 24576);
        if ($tail !== null) {
            $response['debug_log_tail'] = $tail;
        }
    }
    
    if (!empty($warnings)) {
        $response['warnings'] = $warnings;
    }

    debugLog("Response: " . json_encode(['files_count' => count($files), 'stats' => $stats]));
    
    ob_clean();
    echo json_encode($response);
    
} catch (Exception $e) {
    ob_clean();
    echo json_encode(['error' => 'Error: ' . $e->getMessage()]);
}
ob_end_flush();
?>
