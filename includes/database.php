<?php
/**
 * SQLite Database Module - Lightweight Persistence Layer
 * Stores crawl history, visited URLs, and found files without MySQL
 */

class CrawlerDatabase {
    private $db;
    private $dbPath;
    
    public function __construct($dbPath = null) {
        $this->dbPath = $dbPath ?? __DIR__ . '/../data/crawler.db';
        
        // Create data directory if not exists
        $dir = dirname($this->dbPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        
        try {
            $this->db = new PDO('sqlite:' . $this->dbPath);
            $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $this->initTables();
            error_log("[DATABASE] SQLite initialized: {$this->dbPath}");
        } catch (PDOException $e) {
            error_log("[DATABASE] Failed to initialize: " . $e->getMessage());
            throw $e;
        }
    }
    
    /**
     * Create database tables
     */
    private function initTables() {
        // URLs table
        $this->db->exec('
            CREATE TABLE IF NOT EXISTS urls (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                url TEXT UNIQUE NOT NULL,
                base_url TEXT NOT NULL,
                status TEXT DEFAULT "pending",
                depth INTEGER DEFAULT 0,
                http_code INTEGER,
                crawled_at TIMESTAMP,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )
        ');
        
        // Files table
        $this->db->exec('
            CREATE TABLE IF NOT EXISTS files (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                url TEXT UNIQUE NOT NULL,
                filename TEXT,
                type TEXT,
                size INTEGER,
                source_url TEXT,
                found_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )
        ');
        
        // Crawl sessions table
        $this->db->exec('
            CREATE TABLE IF NOT EXISTS sessions (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                base_url TEXT NOT NULL,
                max_depth INTEGER,
                max_pages INTEGER,
                pages_crawled INTEGER DEFAULT 0,
                files_found INTEGER DEFAULT 0,
                status TEXT DEFAULT "running",
                started_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                completed_at TIMESTAMP
            )
        ');
        
        // Create indexes
        $this->db->exec('CREATE INDEX IF NOT EXISTS idx_urls_url ON urls(url)');
        $this->db->exec('CREATE INDEX IF NOT EXISTS idx_urls_status ON urls(status)');
        $this->db->exec('CREATE INDEX IF NOT EXISTS idx_files_url ON files(url)');
        $this->db->exec('CREATE INDEX IF NOT EXISTS idx_files_type ON files(type)');
    }
    
    /**
     * Check if URL has been visited
     */
    public function isVisited($url) {
        $stmt = $this->db->prepare('SELECT 1 FROM urls WHERE url = ? LIMIT 1');
        $stmt->execute([$url]);
        return $stmt->fetch() !== false;
    }
    
    /**
     * Mark URL as visited
     */
    public function markVisited($url, $baseUrl, $depth = 0, $httpCode = 200) {
        try {
            $stmt = $this->db->prepare('
                INSERT INTO urls (url, base_url, status, depth, http_code, crawled_at)
                VALUES (?, ?, "crawled", ?, ?, CURRENT_TIMESTAMP)
                ON CONFLICT(url) DO UPDATE SET
                    status = "crawled",
                    http_code = excluded.http_code,
                    crawled_at = CURRENT_TIMESTAMP
            ');
            $stmt->execute([$url, $baseUrl, $depth, $httpCode]);
            return true;
        } catch (PDOException $e) {
            error_log("[DATABASE] Failed to mark visited: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Add file to database
     */
    public function addFile($fileUrl, $filename, $type, $sourceUrl, $size = null) {
        try {
            $stmt = $this->db->prepare('
                INSERT OR IGNORE INTO files (url, filename, type, size, source_url)
                VALUES (?, ?, ?, ?, ?)
            ');
            $stmt->execute([$fileUrl, $filename, $type, $size, $sourceUrl]);
            return true;
        } catch (PDOException $e) {
            error_log("[DATABASE] Failed to add file: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Get all files
     */
    public function getFiles($type = null, $limit = 1000) {
        try {
            if ($type) {
                $stmt = $this->db->prepare('SELECT * FROM files WHERE type = ? ORDER BY found_at DESC LIMIT ?');
                $stmt->execute([$type, $limit]);
            } else {
                $stmt = $this->db->prepare('SELECT * FROM files ORDER BY found_at DESC LIMIT ?');
                $stmt->execute([$limit]);
            }
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log("[DATABASE] Failed to get files: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Get visited URLs count
     */
    public function getVisitedCount($baseUrl = null) {
        try {
            if ($baseUrl) {
                $stmt = $this->db->prepare('SELECT COUNT(*) FROM urls WHERE base_url = ?');
                $stmt->execute([$baseUrl]);
            } else {
                $stmt = $this->db->query('SELECT COUNT(*) FROM urls');
            }
            return (int)$stmt->fetchColumn();
        } catch (PDOException $e) {
            return 0;
        }
    }
    
    /**
     * Get files count
     */
    public function getFilesCount($type = null) {
        try {
            if ($type) {
                $stmt = $this->db->prepare('SELECT COUNT(*) FROM files WHERE type = ?');
                $stmt->execute([$type]);
            } else {
                $stmt = $this->db->query('SELECT COUNT(*) FROM files');
            }
            return (int)$stmt->fetchColumn();
        } catch (PDOException $e) {
            return 0;
        }
    }
    
    /**
     * Create new session
     */
    public function createSession($baseUrl, $maxDepth, $maxPages) {
        try {
            $stmt = $this->db->prepare('
                INSERT INTO sessions (base_url, max_depth, max_pages)
                VALUES (?, ?, ?)
            ');
            $stmt->execute([$baseUrl, $maxDepth, $maxPages]);
            return $this->db->lastInsertId();
        } catch (PDOException $e) {
            error_log("[DATABASE] Failed to create session: " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Update session stats
     */
    public function updateSession($sessionId, $pagesCrawled, $filesFound) {
        try {
            $stmt = $this->db->prepare('
                UPDATE sessions 
                SET pages_crawled = ?, files_found = ?
                WHERE id = ?
            ');
            $stmt->execute([$pagesCrawled, $filesFound, $sessionId]);
            return true;
        } catch (PDOException $e) {
            return false;
        }
    }
    
    /**
     * Complete session
     */
    public function completeSession($sessionId) {
        try {
            $stmt = $this->db->prepare('
                UPDATE sessions 
                SET status = "completed", completed_at = CURRENT_TIMESTAMP
                WHERE id = ?
            ');
            $stmt->execute([$sessionId]);
            return true;
        } catch (PDOException $e) {
            return false;
        }
    }
    
    /**
     * Clear all data for a base URL
     */
    public function clearData($baseUrl) {
        try {
            $this->db->beginTransaction();
            
            $stmt = $this->db->prepare('DELETE FROM urls WHERE base_url = ?');
            $stmt->execute([$baseUrl]);
            
            $stmt = $this->db->prepare('DELETE FROM sessions WHERE base_url = ?');
            $stmt->execute([$baseUrl]);
            
            $this->db->commit();
            return true;
        } catch (PDOException $e) {
            $this->db->rollBack();
            error_log("[DATABASE] Failed to clear data: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Vacuum database (optimize size)
     */
    public function vacuum() {
        try {
            $this->db->exec('VACUUM');
            return true;
        } catch (PDOException $e) {
            return false;
        }
    }
    
    /**
     * Get database size in bytes
     */
    public function getSize() {
        if (file_exists($this->dbPath)) {
            return filesize($this->dbPath);
        }
        return 0;
    }
    
    /**
     * Close database connection
     */
    public function close() {
        $this->db = null;
    }
}
