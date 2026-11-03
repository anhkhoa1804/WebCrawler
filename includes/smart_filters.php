<?php
/**
 * Smart Filters Module - Heuristic-based File Filtering
 * Filter files by size, dimensions, quality without AI
 */

class SmartFilters {
    
    /**
     * Filter images by size (remove tiny icons, huge raw images)
     * OPTIMIZED: Batch HEAD requests using Multi-cURL
     */
    public static function filterImagesBySize($imageUrls, $minSize = 1000, $maxSize = 10485760) {
        $filtered = [];
        $needsSizeCheck = [];
        
        // First pass: Quick URL-based filtering
        foreach ($imageUrls as $url) {
            if (self::isLikelyIcon($url)) {
                continue; // Skip obvious icons
            }
            
            // If URL looks safe, check size
            $needsSizeCheck[] = $url;
        }
        
        if (empty($needsSizeCheck)) {
            return $filtered;
        }
        
        // FIX: Batch HEAD requests instead of sequential
        $sizes = self::batchGetFileSizes($needsSizeCheck);
        
        foreach ($needsSizeCheck as $url) {
            $size = $sizes[$url] ?? null;
            
            if ($size === null) {
                // Can't determine size, keep it
                $filtered[] = $url;
                continue;
            }
            
            // Filter by size range
            if ($size >= $minSize && $size <= $maxSize) {
                $filtered[] = $url;
            }
        }
        
        return $filtered;
    }
    
    /**
     * Batch get file sizes using Multi-cURL (FAST!)
     * Instead of 100 sequential requests, send 10 parallel batches of 10
     */
    private static function batchGetFileSizes($urls, $batchSize = 10) {
        $sizes = [];
        $batches = array_chunk($urls, $batchSize);
        
        foreach ($batches as $batch) {
            $multiHandle = curl_multi_init();
            $handles = [];
            
            foreach ($batch as $url) {
                $ch = curl_init($url);
                curl_setopt($ch, CURLOPT_NOBODY, true); // HEAD request
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
                curl_setopt($ch, CURLOPT_TIMEOUT, 5);
                curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
                
                curl_multi_add_handle($multiHandle, $ch);
                $handles[$url] = $ch;
            }
            
            // Execute all handles
            $running = null;
            do {
                curl_multi_exec($multiHandle, $running);
                curl_multi_select($multiHandle);
            } while ($running > 0);
            
            // Collect results
            foreach ($handles as $url => $ch) {
                $size = curl_getinfo($ch, CURLINFO_CONTENT_LENGTH_DOWNLOAD);
                $sizes[$url] = ($size > 0) ? (int)$size : false;
                
                curl_multi_remove_handle($multiHandle, $ch);
                curl_close($ch);
            }
            
            curl_multi_close($multiHandle);
        }
        
        return $sizes;
    }
    
    /**
     * Check if URL looks like an icon/logo
     */
    private static function isLikelyIcon($url) {
        $iconPatterns = [
            '/icon/i',
            '/logo/i',
            '/favicon/i',
            '/sprite/i',
            '/thumb/i',
            '/avatar/i',
            '/emoji/i',
            '/badge/i',
            '/button/i',
            '/-16x16\./i',
            '/-32x32\./i',
            '/-48x48\./i'
        ];
        
        foreach ($iconPatterns as $pattern) {
            if (preg_match($pattern, $url)) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * Filter documents by size (remove empty or corrupt files)
     */
    public static function filterDocumentsBySize($docUrls, $minSize = 500, $maxSize = 104857600) {
        $filtered = [];
        
        foreach ($docUrls as $url) {
            $size = self::getFileSize($url);
            
            if ($size === false) {
                $filtered[] = $url;
                continue;
            }
            
            // PDFs < 1KB are usually corrupted
            // Documents > 100MB are usually backups/archives
            if ($size >= $minSize && $size <= $maxSize) {
                $filtered[] = $url;
            }
        }
        
        return $filtered;
    }
    
    /**
     * Filter videos by size (remove previews, keep actual videos)
     */
    public static function filterVideosBySize($videoUrls, $minSize = 10000, $maxSize = 2147483648) {
        $filtered = [];
        
        foreach ($videoUrls as $url) {
            // Skip preview/thumbnail videos
            if (preg_match('/preview|thumb|sample/i', $url)) {
                continue;
            }
            
            $size = self::getFileSize($url);
            
            if ($size === false) {
                $filtered[] = $url;
                continue;
            }
            
            // Videos < 100KB are previews
            // Videos > 2GB might timeout
            if ($size >= $minSize && $size <= $maxSize) {
                $filtered[] = $url;
            }
        }
        
        return $filtered;
    }
    
    /**
     * Get file size using HEAD request (fast, doesn't download)
     */
    private static function getFileSize($url) {
        $ch = curl_init($url);
        
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HEADER, true);
        curl_setopt($ch, CURLOPT_NOBODY, true); // HEAD request
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 5);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        
        curl_exec($ch);
        $size = curl_getinfo($ch, CURLINFO_CONTENT_LENGTH_DOWNLOAD);
        curl_close($ch);
        
        return $size > 0 ? (int)$size : false;
    }
    
    /**
     * Filter files by URL patterns (remove unwanted files)
     */
    public static function filterByUrlPattern($urls, $excludePatterns = []) {
        $defaultExcludes = [
            '/\\.tmp\\./i',               // Temp files
            '/\\.cache\\./i'              // Cache files (với .cache. trong tên)
        ];
        
        $patterns = array_merge($defaultExcludes, $excludePatterns);
        $filtered = [];
        
        foreach ($urls as $url) {
            $excluded = false;
            
            foreach ($patterns as $pattern) {
                if (@preg_match($pattern, $url)) {
                    $excluded = true;
                    break;
                }
            }
            
            if (!$excluded) {
                $filtered[] = $url;
            }
        }
        
        return $filtered;
    }
    
    /**
     * Deduplicate URLs (remove similar/duplicate files)
     */
    public static function deduplicateUrls($urls) {
        $unique = [];
        $seen = [];
        
        foreach ($urls as $url) {
            // Normalize URL
            $normalized = strtolower(trim($url));
            $normalized = preg_replace('/[?#].*$/', '', $normalized); // Remove query/fragment
            
            // Extract filename
            $filename = basename(parse_url($normalized, PHP_URL_PATH));
            
            // Check if similar file already seen
            if (!isset($seen[$filename])) {
                $unique[] = $url;
                $seen[$filename] = true;
            }
        }
        
        return $unique;
    }
    
    /**
     * Sort files by quality score (prioritize high-quality files)
     */
    public static function sortByQuality($files) {
        usort($files, function($a, $b) {
            $scoreA = self::calculateQualityScore($a);
            $scoreB = self::calculateQualityScore($b);
            return $scoreB - $scoreA; // Descending order
        });
        
        return $files;
    }
    
    /**
     * Calculate quality score for a file
     */
    private static function calculateQualityScore($url) {
        $score = 100;
        
        // Bonus for high-res images
        if (preg_match('/\d{3,4}x\d{3,4}|hd|4k|high[_-]?res/i', $url)) {
            $score += 50;
        }
        
        // Penalty for low-res images
        if (preg_match('/small|tiny|mini|thumb/i', $url)) {
            $score -= 50;
        }
        
        // Bonus for specific keywords
        if (preg_match('/original|master|full|download/i', $url)) {
            $score += 30;
        }
        
        // Penalty for thumbnails/previews
        if (preg_match('/thumb|preview|sample|demo/i', $url)) {
            $score -= 40;
        }
        
        // Bonus for common high-quality formats
        if (preg_match('/\.(png|tiff|wav|flac|pdf)$/i', $url)) {
            $score += 20;
        }
        
        return $score;
    }
    
    /**
     * Apply all filters in one pass
     */
    public static function applyAllFilters($files, $options = []) {
        $type = $options['type'] ?? 'image';
        
        // Step 1: Deduplicate (chỉ bỏ trùng lặp)
        $files = self::deduplicateUrls($files);
        
        // Step 2: Filter by size based on type (với ngưỡng thấp hơn)
        switch ($type) {
            case 'image':
                $files = self::filterImagesBySize($files, 
                    $options['min_size'] ?? 1000, 
                    $options['max_size'] ?? 10485760
                );
                break;
            case 'document':
                $files = self::filterDocumentsBySize($files,
                    $options['min_size'] ?? 500,
                    $options['max_size'] ?? 104857600
                );
                break;
            case 'video':
            case 'audio':
                $files = self::filterVideosBySize($files,
                    $options['min_size'] ?? 10000,
                    $options['max_size'] ?? 2147483648
                );
                break;
        }
        
        // Bỏ sort và filter by URL pattern để giữ nhiều file hơn
        
        return $files;
    }
}
