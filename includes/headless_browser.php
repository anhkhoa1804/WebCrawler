<?php
require_once __DIR__ . '/http_safety.php';
// Load Composer packages (e.g. symfony/panther) when installed, so the Panther check can see them.
$wcAutoload = __DIR__ . '/../vendor/autoload.php';
if (is_file($wcAutoload)) {
    require_once $wcAutoload;
}

/**
 * Headless Browser Integration
 * Supports both Symfony Panther (PHP) and Puppeteer (Node.js bridge)
 * 
 * Installation:
 * 1. For Symfony Panther: composer require symfony/panther
 * 2. For Puppeteer: npm install puppeteer (requires Node.js)
 */

class HeadlessBrowser {
    private $mode = 'curl'; // 'panther', 'puppeteer', or 'curl'
    private $client = null;
    private $timeout = 30;
    private $waitForSelector = null;
    private $executeJavaScript = [];
    private $scrollToBottom = false;
    private $screenshotPath = null;
    private $cookieFile = null;
    private $userAgent = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/121.0.0.0 Safari/537.36';
    
    public function __construct($options = []) {
        $this->timeout = $options['timeout'] ?? 30;
        $this->cookieFile = $options['cookie_file'] ?? sys_get_temp_dir() . '/headless_cookies.txt';
        $this->userAgent = $options['user_agent'] ?? $this->userAgent;
        
        // Auto-detect available mode
        $this->mode = $this->detectAvailableMode($options['preferred_mode'] ?? null);
        
        error_log("[HEADLESS] Initialized with mode: {$this->mode}");
        
        if ($this->mode === 'panther') {
            $this->initializePanther($options);
        }
    }
    
    private function detectAvailableMode($preferredMode = null) {
        // Check if user specified a mode
        if ($preferredMode) {
            if ($preferredMode === 'panther' && class_exists('Symfony\Component\Panther\Client')) {
                return 'panther';
            }
            if ($preferredMode === 'puppeteer' && $this->isPuppeteerAvailable()) {
                return 'puppeteer';
            }
        }
        
        // Auto-detect: Panther first, then Puppeteer, fallback to cURL
        if (class_exists('Symfony\Component\Panther\Client')) {
            return 'panther';
        }
        
        if ($this->isPuppeteerAvailable()) {
            return 'puppeteer';
        }
        
        error_log("[HEADLESS] No headless browser available, falling back to cURL mode");
        return 'curl';
    }
    
    private function isPuppeteerAvailable() {
        // Prefer detecting the persistent Puppeteer server (doesn't require shell_exec/node PATH)
        if ($this->isPuppeteerServerAvailable()) {
            return true;
        }

        // Fallback: check if Node.js and Puppeteer bridge script exists
        $bridgePath = __DIR__ . '/puppeteer_bridge.js';
        return file_exists($bridgePath) && $this->isNodeJsInstalled();
    }
    
    private function isNodeJsInstalled() {
        if (!function_exists('shell_exec')) {
            return false;
        }
        $output = @shell_exec('node --version 2>&1');
        return is_string($output) && strpos($output, 'v') === 0;
    }

    private function isPuppeteerServerAvailable() {
        $healthUrl = 'http://localhost:9222/health';

        // Prefer cURL (works even when allow_url_fopen is disabled in Apache).
        if (function_exists('curl_init')) {
            $ch = curl_init($healthUrl);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 1);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 1);
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Accept: application/json']);
            $raw = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if (is_string($raw) && $raw !== '' && $httpCode === 200) {
                $json = json_decode($raw, true);
                return is_array($json) && ($json['status'] ?? null) === 'ok';
            }
        }

        // Fallback: file_get_contents (requires allow_url_fopen).
        $ctx = stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => 1,
                'ignore_errors' => true,
                'header' => "Accept: application/json\r\n",
            ],
        ]);

        $raw = @file_get_contents($healthUrl, false, $ctx);
        if (!is_string($raw) || $raw === '') {
            return false;
        }

        $json = json_decode($raw, true);
        return is_array($json) && ($json['status'] ?? null) === 'ok';
    }
    
    private function initializePanther($options) {
        if (!class_exists('Symfony\Component\Panther\Client')) {
            error_log("[HEADLESS] Symfony Panther not installed. Run: composer require symfony/panther");
            $this->mode = 'curl';
            return;
        }
        
        try {
            // Create Panther client with Chrome/Firefox
            $pantherOptions = [
                'connection_timeout_in_ms' => $this->timeout * 1000,
                'request_timeout_in_ms' => $this->timeout * 1000,
            ];
            
            // Use headless Chrome
            $this->client = \Symfony\Component\Panther\Client::createChromeClient(null, [
                '--headless',
                '--disable-gpu',
                '--no-sandbox',
                '--disable-dev-shm-usage',
                '--disable-setuid-sandbox',
                '--window-size=1920,1080',
                '--user-agent=' . $this->userAgent
            ], $pantherOptions);
            
            error_log("[HEADLESS] Panther client initialized successfully");
        } catch (Exception $e) {
            error_log("[HEADLESS] Panther initialization failed: " . $e->getMessage());
            $this->mode = 'curl';
        }
    }
    
    /**
     * Fetch page content using headless browser
     */
    public function fetchPage($url, $options = []) {
        switch ($this->mode) {
            case 'panther':
                return $this->fetchWithPanther($url, $options);
            case 'puppeteer':
                return $this->fetchWithPuppeteer($url, $options);
            default:
                return $this->fetchWithCurl($url, $options);
        }
    }
    
    private function fetchWithPanther($url, $options = []) {
        try {
            if (!$this->client) {
                throw new Exception("Panther client not initialized");
            }
            
            error_log("[HEADLESS] Fetching with Panther: $url");
            
            // Navigate to page
            $crawler = $this->client->request('GET', $url);
            
            // Wait for specific selector if provided
            if (isset($options['wait_for_selector'])) {
                $this->client->waitFor($options['wait_for_selector'], $this->timeout);
            }
            
            // Execute custom JavaScript
            if (isset($options['execute_js'])) {
                foreach ((array)$options['execute_js'] as $script) {
                    $this->client->executeScript($script);
                }
            }
            
            // Scroll to bottom to trigger lazy loading
            if (isset($options['scroll_to_bottom']) && $options['scroll_to_bottom']) {
                $this->scrollToBottom();
            }
            
            // Wait for AJAX/JavaScript to complete (longer for heavy JS sites)
            $waitTime = $options['wait_time'] ?? 3; // Default 3 seconds
            if (stripos($url, 'zing') !== false || stripos($url, 'shopee') !== false) {
                $waitTime = 5; // 5 seconds for ZingMP3, Shopee
            }
            sleep($waitTime);
            error_log("[HEADLESS] Waited {$waitTime}s for JavaScript execution");
            
            // Take screenshot if requested
            if (isset($options['screenshot_path'])) {
                $this->client->takeScreenshot($options['screenshot_path']);
            }
            
            // Get rendered HTML
            $html = $this->client->getPageSource();
            
            error_log("[HEADLESS] Successfully fetched " . strlen($html) . " bytes");
            return $html;
            
        } catch (Exception $e) {
            error_log("[HEADLESS] Panther fetch failed: " . $e->getMessage());
            // Fallback to cURL
            return $this->fetchWithCurl($url, $options);
        }
    }
    
    private function fetchWithPuppeteer($url, $options = []) {
        try {
            error_log("[HEADLESS] Fetching with Puppeteer: $url");
            
            // Try persistent server first (faster), fallback to bridge
            $result = $this->fetchViaPuppeteerServer($url, $options);
            
            if (!$result) {
                // Fallback to old shell_exec method
                error_log("[HEADLESS] Server unavailable, using bridge script");
                $result = $this->fetchViaPuppeteerBridge($url, $options);
            }
            
            if (!$result) {
                throw new Exception("Both Puppeteer server and bridge failed");
            }
            
            // Parse JSON response
            if (is_string($result)) {
                $result = json_decode($result, true);
            }
            
            if (isset($result['error'])) {
                throw new Exception($result['error']);
            }
            
            if (isset($result['html'])) {
                $html = $result['html'];
                
                // If media URLs were captured, inject them into HTML as hidden links
                if (isset($result['capturedMedia'])) {
                    $media = $result['capturedMedia'];
                    $directCount = isset($media['direct']) ? count($media['direct']) : 0;
                    $streamCount = isset($media['streaming']) ? count($media['streaming']) : 0;
                    $totalCount = $directCount + $streamCount;
                    
                    if ($totalCount > 0) {
                        error_log("[HEADLESS] Captured $totalCount media URLs (Direct: $directCount, Streaming: $streamCount)");
                        
                        // Inject captured URLs as HTML comments and hidden links
                        $injectedLinks = "\n<!-- Captured Media by Puppeteer: $totalCount URLs -->\n";
                        
                        // Inject Direct Audio/Video URLs
                        if (isset($media['direct']) && !empty($media['direct'])) {
                            $injectedLinks .= "<!-- Direct Media: $directCount -->\n";
                            foreach ($media['direct'] as $item) {
                                // Support both object items ({url,type}) and raw URL strings
                                $mediaUrl = is_array($item) ? ($item['url'] ?? '') : (string)$item;
                                $type = is_array($item) ? ($item['type'] ?? 'media') : 'media';
                                $captureSource = is_array($item) ? ($item['source'] ?? '') : '';
                                $sourcePage = is_array($item) ? ($item['page'] ?? '') : '';
                                if (!$mediaUrl) continue;
                                $safeUrl = htmlspecialchars($mediaUrl, ENT_QUOTES);
                                $safeType = htmlspecialchars($type, ENT_QUOTES);
                                $attrs = "";
                                if ($captureSource) {
                                    $attrs .= " data-capture-source=\"" . htmlspecialchars($captureSource, ENT_QUOTES) . "\"";
                                }
                                if ($sourcePage) {
                                    $attrs .= " data-source-page=\"" . htmlspecialchars($sourcePage, ENT_QUOTES) . "\"";
                                }
                                $injectedLinks .= "<a href=\"$safeUrl\" data-type=\"$safeType\" data-source=\"puppeteer-direct\"$attrs style=\"display:none;\"></a>\n";
                                error_log("[HEADLESS] Injected direct $type: $mediaUrl");
                            }
                        }
                        
                        // Inject Streaming URLs (.m3u8, .mpd)
                        if (isset($media['streaming']) && !empty($media['streaming'])) {
                            $injectedLinks .= "<!-- Streaming Media: $streamCount -->\n";
                            foreach ($media['streaming'] as $item) {
                                // Support both object items ({url,type}) and raw URL strings
                                $mediaUrl = is_array($item) ? ($item['url'] ?? '') : (string)$item;
                                $streamType = is_array($item) ? ($item['type'] ?? 'stream') : 'stream';
                                if (!$mediaUrl) continue;
                                $safeUrl = htmlspecialchars($mediaUrl, ENT_QUOTES);
                                $safeStream = htmlspecialchars($streamType, ENT_QUOTES);
                                $injectedLinks .= "<a href=\"$safeUrl\" data-type=\"streaming\" data-stream=\"$safeStream\" data-source=\"puppeteer-stream\" style=\"display:none;\"></a>\n";
                                error_log("[HEADLESS] Injected $streamType stream: $mediaUrl");
                            }
                        }
                        
                        // Inject before </body> tag
                        if (stripos($html, '</body>') !== false) {
                            $html = str_ireplace('</body>', $injectedLinks . '</body>', $html);
                        } else {
                            $html .= $injectedLinks;
                        }
                    }
                }
                
                error_log("[HEADLESS] Successfully fetched " . strlen($html) . " bytes");
                return $html;
            }
            
            throw new Exception("Invalid response from Puppeteer");
            
        } catch (Exception $e) {
            error_log("[HEADLESS] Puppeteer fetch failed: " . $e->getMessage());
            // Fallback to cURL
            return $this->fetchWithCurl($url, $options);
        }
    }
    
    private function fetchWithCurl($url, $options = []) {
        error_log("[HEADLESS] Fetching with cURL (fallback): $url");
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_MAXREDIRS, 3);
        curl_setopt($ch, CURLOPT_TIMEOUT, $this->timeout);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 30);
        
        $headers = [
            'User-Agent: ' . $this->userAgent,
            'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,*/*;q=0.8',
            'Accept-Language: en-US,en;q=0.9,vi;q=0.8',
            'Accept-Encoding: gzip, deflate, br',
            'Cache-Control: max-age=0',
            'Connection: keep-alive',
        ];
        
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_ENCODING, "");
        wc_apply_http_safety($ch);
        
        if ($this->cookieFile) {
            curl_setopt($ch, CURLOPT_COOKIEJAR, $this->cookieFile);
            curl_setopt($ch, CURLOPT_COOKIEFILE, $this->cookieFile);
        }
        
        $content = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        
        if ($error || $httpCode < 200 || $httpCode >= 400) {
            error_log("[HEADLESS] cURL failed: $error (HTTP $httpCode)");
            return false;
        }
        
        return $content;
    }
    
    /**
     * Fetch via Puppeteer persistent server (FAST - reuses browser)
     */
    private function fetchViaPuppeteerServer($url, $options = []) {
        $serverUrl = 'http://localhost:9222/fetch';
        
        $puppeteerOptions = [
            'url' => $url,
            'timeout' => ($options['timeout'] ?? $this->timeout) * 1000,
            'wait_time' => ($options['wait_time'] ?? 2) * 1000,
            'execute_js' => $options['execute_js'] ?? [],
            'scroll_to_bottom' => $options['scroll_to_bottom'] ?? false,
        ];

        if (isset($options['zing_max_songs'])) {
            $puppeteerOptions['zing_max_songs'] = (int)$options['zing_max_songs'];
        }

        if (isset($options['nct_max_songs'])) {
            $puppeteerOptions['nct_max_songs'] = (int)$options['nct_max_songs'];
        }
        
        $ch = curl_init($serverUrl);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($puppeteerOptions));
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 180); // Longer timeout for album/playlist traversal
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        
        if ($error || $httpCode !== 200 || !$response) {
            error_log("[HEADLESS] Server request failed: $error (HTTP $httpCode)");
            return false;
        }
        
        return $response;
    }
    
    /**
     * Fetch via Puppeteer bridge script (SLOW - spawns new browser)
     */
    private function fetchViaPuppeteerBridge($url, $options = []) {
        $bridgePath = __DIR__ . '/puppeteer_bridge.js';
        
        if (!file_exists($bridgePath)) {
            return false;
        }
        
        $puppeteerOptions = [
            'url' => $url,
            'timeout' => ($options['timeout'] ?? $this->timeout) * 1000,
            'wait_time' => $options['wait_time'] ?? 2,
            'execute_js' => $options['execute_js'] ?? [],
            'scroll_to_bottom' => $options['scroll_to_bottom'] ?? false,
        ];

        if (isset($options['zing_max_songs'])) {
            $puppeteerOptions['zing_max_songs'] = (int)$options['zing_max_songs'];
        }

        if (isset($options['nct_max_songs'])) {
            $puppeteerOptions['nct_max_songs'] = (int)$options['nct_max_songs'];
        }
        
        $tempJsonFile = sys_get_temp_dir() . '/puppeteer_' . uniqid() . '.json';
        file_put_contents($tempJsonFile, json_encode($puppeteerOptions));
        
        // stderr is not merged: the bridge writes progress logs there, and mixing them into stdout breaks the JSON reply.
        $command = "node \"$bridgePath\" \"@$tempJsonFile\"";
        $output = shell_exec($command);
        
        @unlink($tempJsonFile);
        
        return $output ?: false;
    }
    
    /**
     * Scroll to bottom of page to trigger lazy loading
     */
    private function scrollToBottom() {
        if ($this->mode === 'panther' && $this->client) {
            // Scroll in steps to trigger all lazy load events
            for ($i = 0; $i < 5; $i++) {
                $this->client->executeScript('window.scrollBy(0, window.innerHeight)');
                usleep(500000); // Wait 500ms between scrolls
            }
            
            // Scroll to very bottom
            $this->client->executeScript('window.scrollTo(0, document.body.scrollHeight)');
            sleep(1);
        }
    }
    
    /**
     * Perform login on a website
     */
    public function login($loginUrl, $credentials, $options = []) {
        if ($this->mode !== 'panther') {
            error_log("[HEADLESS] Login requires Panther mode");
            return false;
        }
        
        try {
            error_log("[HEADLESS] Performing login at: $loginUrl");
            
            // Navigate to login page
            $crawler = $this->client->request('GET', $loginUrl);
            
            // Wait for form to load
            if (isset($options['form_selector'])) {
                $this->client->waitFor($options['form_selector'], $this->timeout);
            }
            
            // Fill in form fields
            foreach ($credentials as $field => $value) {
                $selector = $options['field_selectors'][$field] ?? "#$field";
                $this->client->executeScript("document.querySelector('$selector').value = '$value'");
            }
            
            // Submit form
            $submitSelector = $options['submit_selector'] ?? 'button[type="submit"]';
            $this->client->executeScript("document.querySelector('$submitSelector').click()");
            
            // Wait for redirect/login completion
            sleep($options['wait_after_submit'] ?? 3);
            
            error_log("[HEADLESS] Login completed");
            return true;
            
        } catch (Exception $e) {
            error_log("[HEADLESS] Login failed: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Get current mode
     */
    public function getMode() {
        return $this->mode;
    }
    
    /**
     * Check if headless mode is active (not cURL)
     */
    public function isHeadless() {
        return $this->mode !== 'curl';
    }
    
    /**
     * Close browser
     */
    public function close() {
        if ($this->client && $this->mode === 'panther') {
            try {
                $this->client->quit();
            } catch (Exception $e) {
                error_log("[HEADLESS] Error closing browser: " . $e->getMessage());
            }
        }
    }
    
    public function __destruct() {
        $this->close();
    }
}
