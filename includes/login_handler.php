<?php
/**
 * Login Module - Simple Form Authentication
 * Handles POST/GET login forms without external services
 */

class LoginHandler {
    private $cookieFile;
    private $userAgent = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36';
    private $timeout = 30;
    
    public function __construct($cookieFile = null) {
        $this->cookieFile = $cookieFile ?? sys_get_temp_dir() . '/login_cookies_' . uniqid() . '.txt';
    }
    
    /**
     * Login with POST form
     * @param string $loginUrl - Login form URL
     * @param array $credentials - ['username' => 'user', 'password' => 'pass', ...]
     * @param array $options - Additional options
     * @return bool - Success status
     */
    public function login($loginUrl, $credentials, $options = []) {
        $method = $options['method'] ?? 'POST';
        $followRedirects = $options['follow_redirects'] ?? true;
        
        error_log("[LOGIN] Attempting login to: $loginUrl");
        
        // First, get the login page to capture any CSRF tokens
        if (isset($options['get_csrf']) && $options['get_csrf']) {
            $loginPage = $this->fetchPage($loginUrl);
            
            if ($loginPage) {
                // Try to extract CSRF token
                $csrfToken = $this->extractCsrfToken($loginPage, $options['csrf_field'] ?? 'csrf_token');
                if ($csrfToken) {
                    $credentials[$options['csrf_field'] ?? 'csrf_token'] = $csrfToken;
                    error_log("[LOGIN] CSRF token found and added");
                }
            }
        }
        
        // Perform login request
        $ch = curl_init();
        
        curl_setopt($ch, CURLOPT_URL, $loginUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, $followRedirects);
        curl_setopt($ch, CURLOPT_MAXREDIRS, 5);
        curl_setopt($ch, CURLOPT_TIMEOUT, $this->timeout);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
        
        // Set method
        if (strtoupper($method) === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($credentials));
        }
        
        // Headers
        $headers = [
            'User-Agent: ' . $this->userAgent,
            'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
            'Accept-Language: en-US,en;q=0.9',
            'Content-Type: application/x-www-form-urlencoded',
            'Referer: ' . $loginUrl
        ];
        
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        
        // Cookie handling
        curl_setopt($ch, CURLOPT_COOKIEJAR, $this->cookieFile);
        curl_setopt($ch, CURLOPT_COOKIEFILE, $this->cookieFile);
        
        // Execute
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        
        curl_close($ch);
        
        if ($error) {
            error_log("[LOGIN] Error: $error");
            return false;
        }
        
        // Check if login successful
        $success = $this->verifyLogin($response, $httpCode, $options);
        
        if ($success) {
            error_log("[LOGIN] Login successful! Cookie saved to: {$this->cookieFile}");
        } else {
            error_log("[LOGIN] Login failed (HTTP $httpCode)");
        }
        
        return $success;
    }
    
    /**
     * Fetch page with authentication cookies
     */
    public function fetchAuthenticated($url) {
        if (!file_exists($this->cookieFile)) {
            error_log("[LOGIN] No cookie file found. Please login first.");
            return false;
        }
        
        $ch = curl_init();
        
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, $this->timeout);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
        
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'User-Agent: ' . $this->userAgent
        ]);
        
        curl_setopt($ch, CURLOPT_COOKIEFILE, $this->cookieFile);
        
        $content = curl_exec($ch);
        curl_close($ch);
        
        return $content;
    }
    
    /**
     * Extract CSRF token from HTML
     */
    private function extractCsrfToken($html, $fieldName = 'csrf_token') {
        // Try to find hidden input with CSRF token
        $patterns = [
            '/<input[^>]*name=["\']' . preg_quote($fieldName, '/') . '["\'][^>]*value=["\']([^"\']+)["\'][^>]*>/i',
            '/<input[^>]*value=["\']([^"\']+)["\'][^>]*name=["\']' . preg_quote($fieldName, '/') . '["\'][^>]*>/i',
            '/<meta[^>]*name=["\']csrf-token["\'][^>]*content=["\']([^"\']+)["\'][^>]*>/i'
        ];
        
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $html, $matches)) {
                return $matches[1];
            }
        }
        
        return null;
    }
    
    /**
     * Verify if login was successful
     */
    private function verifyLogin($response, $httpCode, $options) {
        // Method 1: Check for success text
        if (isset($options['success_text'])) {
            return strpos($response, $options['success_text']) !== false;
        }
        
        // Method 2: Check for error text (absence means success)
        if (isset($options['error_text'])) {
            return strpos($response, $options['error_text']) === false;
        }
        
        // Method 3: Check HTTP redirect (302/303 usually means success)
        if ($httpCode >= 300 && $httpCode < 400) {
            return true;
        }
        
        // Method 4: Check cookie file has session cookies
        if (file_exists($this->cookieFile)) {
            $cookies = file_get_contents($this->cookieFile);
            return !empty($cookies) && strlen($cookies) > 100;
        }
        
        // Default: Consider 200 OK as success
        return $httpCode === 200;
    }
    
    /**
     * Fetch page (helper)
     */
    private function fetchPage($url) {
        $ch = curl_init();
        
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, $this->timeout);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'User-Agent: ' . $this->userAgent
        ]);
        
        curl_setopt($ch, CURLOPT_COOKIEJAR, $this->cookieFile);
        curl_setopt($ch, CURLOPT_COOKIEFILE, $this->cookieFile);
        
        $content = curl_exec($ch);
        curl_close($ch);
        
        return $content;
    }
    
    /**
     * Get cookie file path
     */
    public function getCookieFile() {
        return $this->cookieFile;
    }
    
    /**
     * Check if logged in (cookie exists)
     */
    public function isLoggedIn() {
        return file_exists($this->cookieFile) && filesize($this->cookieFile) > 0;
    }
    
    /**
     * Logout (delete cookies)
     */
    public function logout() {
        if (file_exists($this->cookieFile)) {
            unlink($this->cookieFile);
        }
    }
    
    /**
     * Destructor
     */
    public function __destruct() {
        // Keep cookie file for reuse
        // Uncomment to auto-delete on destroy:
        // $this->logout();
    }
}
