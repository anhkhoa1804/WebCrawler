<?php
set_time_limit(600);
ini_set('display_errors', 0);
error_reporting(E_ALL);

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

if (isset($_GET['progress'])) {
    session_start();
    $progress = isset($_SESSION['crawl_progress']) ? $_SESSION['crawl_progress'] : ['current' => 0, 'total' => 0, 'status' => 'idle'];
    session_write_close();
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
    
    // Enable database and parallel processing by default
    $options = [
        'use_database' => true,
        'use_parallel' => true,
        'parallel_connections' => 5,
        'timeout' => 15
    ];
    
    if ($maxPages > 100) $maxPages = 100;
    
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

    session_start();
    $_SESSION['crawl_progress'] = [
        'current' => 0,
        'total' => $maxPages,
        'status' => 'crawling'
    ];
    session_write_close();

    $crawler = new WebCrawler($url, $extensions, $maxDepth, $maxPages, $options);
    
    if (ob_get_level() > 0) {
        @ob_flush();
        @flush();
    }
    
    $crawler->crawl();
    
    session_start();
    $_SESSION['crawl_progress'] = [
        'current' => $maxPages,
        'total' => $maxPages,
        'status' => 'completed'
    ];
    session_write_close();
    
    $stats = $crawler->getStats();
    $stats['errors'] = $crawler->getErrors();
    
    $response = [
        'success' => true,
        'files' => $crawler->getFoundFiles(),
        'stats' => $stats
    ];
    
    ob_clean();
    echo json_encode($response);
    
} catch (Exception $e) {
    ob_clean();
    echo json_encode(['error' => 'Error: ' . $e->getMessage()]);
}
ob_end_flush();
?>
