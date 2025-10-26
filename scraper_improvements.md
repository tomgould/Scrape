# Scraper Class Improvements Guide

## Executive Summary

Your current Scraper class uses **synchronous sequential downloads**, which is the primary performance bottleneck. The main improvements focus on:

1. **Parallel downloads using cURL multi-handle** (10-100x speed improvement)
2. **Better memory management** for large file operations
3. **Error handling and retry logic**
4. **Progress reporting and logging**
5. **Resume capability** for interrupted downloads
6. **Code organization and maintainability**

---

## 1. Major Performance Improvement: Parallel Downloads

### Current Issue
The `run()` method downloads files sequentially - one file completes before the next begins. This is extremely slow.

### Solution: Implement cURL Multi-Handle

Here's an improved version with parallel downloads:

```php
/**
 * Maximum number of concurrent downloads
 */
private $maxConcurrentDownloads = 10;

/**
 * Set maximum concurrent downloads
 */
public function setMaxConcurrentDownloads($count)
{
    $this->maxConcurrentDownloads = max(1, min(50, $count));
    return $this;
}

/**
 * Download files in parallel using cURL multi-handle
 */
private function downloadInParallel($links)
{
    $queue = $links;
    $active = [];
    $mh = curl_multi_init();
    $results = [];
    
    // Set max concurrent connections
    if (function_exists('curl_multi_setopt')) {
        curl_multi_setopt($mh, CURLMOPT_MAX_TOTAL_CONNECTIONS, $this->maxConcurrentDownloads);
    }
    
    while (!empty($queue) || !empty($active)) {
        // Fill the active pool
        while (count($active) < $this->maxConcurrentDownloads && !empty($queue)) {
            $link = array_shift($queue);
            
            // Create destination directory
            $this->destinationDirectory($link['destination']);
            
            if ($this->getMode() === 'download') {
                $ch = $this->createCurlHandle($link['link'], $link['save_path']);
                curl_multi_add_handle($mh, $ch);
                $active[(int)$ch] = [
                    'handle' => $ch,
                    'link' => $link,
                    'start_time' => microtime(true)
                ];
            } elseif ($this->getMode() === 'test') {
                // Test mode: just create empty files
                touch($link['save_path']);
                $results[] = ['success' => true, 'link' => $link];
            }
        }
        
        // Execute the multi-handle
        if (!empty($active)) {
            do {
                $status = curl_multi_exec($mh, $running);
            } while ($status === CURLM_CALL_MULTI_PERFORM);
            
            // Wait for activity with a timeout
            if ($running > 0) {
                curl_multi_select($mh, 0.5);
            }
            
            // Check for completed downloads
            while ($info = curl_multi_info_read($mh)) {
                if ($info['msg'] === CURLMSG_DONE) {
                    $ch = $info['handle'];
                    $key = (int)$ch;
                    
                    if (isset($active[$key])) {
                        $downloadInfo = $active[$key];
                        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                        $downloadTime = microtime(true) - $downloadInfo['start_time'];
                        $fileSize = curl_getinfo($ch, CURLINFO_SIZE_DOWNLOAD);
                        
                        $result = [
                            'success' => ($info['result'] === CURLE_OK && $httpCode === 200),
                            'link' => $downloadInfo['link'],
                            'http_code' => $httpCode,
                            'curl_error' => curl_error($ch),
                            'time' => $downloadTime,
                            'size' => $fileSize,
                            'speed' => $fileSize > 0 ? $fileSize / $downloadTime : 0
                        ];
                        
                        $results[] = $result;
                        
                        // Log the result
                        $this->logDownload($result);
                        
                        curl_multi_remove_handle($mh, $ch);
                        curl_close($ch);
                        unset($active[$key]);
                    }
                }
            }
        }
    }
    
    curl_multi_close($mh);
    return $results;
}

/**
 * Create a cURL handle for downloading
 */
private function createCurlHandle($url, $savePath)
{
    $ch = curl_init($url);
    $fp = fopen($savePath, 'wb');
    
    // Standard options
    curl_setopt($ch, CURLOPT_FILE, $fp);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 300);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 30);
    curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36');
    curl_setopt($ch, CURLOPT_ENCODING, 'gzip,deflate');
    
    // Low speed abort
    curl_setopt($ch, CURLOPT_LOW_SPEED_LIMIT, 10240); // 10KB/s
    curl_setopt($ch, CURLOPT_LOW_SPEED_TIME, 60);
    
    // Store file pointer for cleanup
    curl_setopt($ch, CURLOPT_PRIVATE, serialize(['fp' => $fp, 'path' => $savePath]));
    
    return $ch;
}

/**
 * Update the run() method to use parallel downloads
 */
public function run()
{
    $links = $this->getLinks($this->getLocations());
    
    // Apply random limit if set
    if ($this->getRandomLimit() > 0 && count($links) > $this->getRandomLimit()) {
        shuffle($links);
        $links = array_slice($links, 0, $this->getRandomLimit());
    }
    
    echo "Found " . count($links) . " files to download\n";
    
    if ($this->getMode() === 'search') {
        return $links; // Just return the list in search mode
    }
    
    // Download in parallel
    $results = $this->downloadInParallel($links);
    
    // Summary
    $successful = count(array_filter($results, function($r) { return $r['success']; }));
    $failed = count($results) - $successful;
    
    echo "\nDownload complete!\n";
    echo "Successful: $successful\n";
    echo "Failed: $failed\n";
    
    return $results;
}
```

**Expected Performance Gain:** 10-100x faster depending on network latency and server response times.

---

## 2. Memory Optimization

### Current Issue
Loading entire directory listings into memory and processing them all at once.

### Solution: Stream Processing for Large Lists

```php
/**
 * Process links in batches to reduce memory usage
 */
private function processLinksInBatches($links, $batchSize = 100)
{
    $results = [];
    $batches = array_chunk($links, $batchSize);
    
    foreach ($batches as $batchIndex => $batch) {
        echo "Processing batch " . ($batchIndex + 1) . "/" . count($batches) . "\n";
        $batchResults = $this->downloadInParallel($batch);
        $results = array_merge($results, $batchResults);
        
        // Force garbage collection after each batch
        gc_collect_cycles();
    }
    
    return $results;
}
```

---

## 3. Retry Logic & Error Handling

```php
/**
 * Maximum number of retry attempts
 */
private $maxRetries = 3;

/**
 * Retry delay in seconds
 */
private $retryDelay = 2;

/**
 * Download with retry logic
 */
private function downloadWithRetry($link, $attempt = 1)
{
    try {
        $ch = $this->createCurlHandle($link['link'], $link['save_path']);
        curl_exec($ch);
        
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        
        if ($httpCode === 200 && empty($error)) {
            return ['success' => true, 'link' => $link];
        }
        
        // Retry on failure
        if ($attempt < $this->maxRetries) {
            echo "Retry attempt $attempt for {$link['file_name']}\n";
            sleep($this->retryDelay);
            return $this->downloadWithRetry($link, $attempt + 1);
        }
        
        return ['success' => false, 'link' => $link, 'error' => $error];
        
    } catch (Exception $e) {
        if ($attempt < $this->maxRetries) {
            sleep($this->retryDelay);
            return $this->downloadWithRetry($link, $attempt + 1);
        }
        return ['success' => false, 'link' => $link, 'error' => $e->getMessage()];
    }
}
```

---

## 4. Progress Reporting

```php
/**
 * Add progress callback
 */
private $progressCallback = null;

public function setProgressCallback($callback)
{
    $this->progressCallback = $callback;
    return $this;
}

/**
 * Enhanced progress tracking
 */
private function reportProgress($current, $total, $additionalData = [])
{
    if ($this->progressCallback && is_callable($this->progressCallback)) {
        call_user_func($this->progressCallback, [
            'current' => $current,
            'total' => $total,
            'percent' => round(($current / $total) * 100, 2),
            'data' => $additionalData
        ]);
    } else {
        // Default console output
        $percent = round(($current / $total) * 100, 2);
        echo "\rProgress: $current/$total ($percent%)";
        if ($current === $total) {
            echo "\n";
        }
    }
}
```

---

## 5. Resume Capability

```php
/**
 * Check if file exists and get its size
 */
private function getLocalFileSize($path)
{
    return file_exists($path) ? filesize($path) : 0;
}

/**
 * Create cURL handle with resume support
 */
private function createCurlHandleWithResume($url, $savePath)
{
    $existingSize = $this->getLocalFileSize($savePath);
    $ch = curl_init($url);
    
    if ($existingSize > 0) {
        // Resume from where we left off
        $fp = fopen($savePath, 'ab');
        curl_setopt($ch, CURLOPT_RESUME_FROM, $existingSize);
        echo "Resuming {$savePath} from byte {$existingSize}\n";
    } else {
        $fp = fopen($savePath, 'wb');
    }
    
    curl_setopt($ch, CURLOPT_FILE, $fp);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 300);
    
    return $ch;
}
```

---

## 6. Improved Logging

```php
/**
 * Log file path
 */
private $logFile = null;
private $logHandle = null;

/**
 * Enable logging
 */
public function enableLogging($logFilePath)
{
    $this->logFile = $logFilePath;
    $this->logHandle = fopen($logFilePath, 'a');
    return $this;
}

/**
 * Log download result
 */
private function logDownload($result)
{
    if (!$this->logHandle) {
        return;
    }
    
    $timestamp = date('Y-m-d H:i:s');
    $status = $result['success'] ? 'SUCCESS' : 'FAILED';
    $url = $result['link']['link'];
    $size = isset($result['size']) ? round($result['size'] / 1024, 2) . ' KB' : 'N/A';
    $time = isset($result['time']) ? round($result['time'], 2) . 's' : 'N/A';
    
    $logLine = "[$timestamp] $status | $url | Size: $size | Time: $time\n";
    fwrite($this->logHandle, $logLine);
}

/**
 * Close log file on destruction
 */
public function __destruct()
{
    if ($this->logHandle) {
        fclose($this->logHandle);
    }
}
```

---

## 7. Bandwidth Limiting

```php
/**
 * Maximum download speed in bytes per second (0 = unlimited)
 */
private $maxDownloadSpeed = 0;

/**
 * Set bandwidth limit
 */
public function setMaxDownloadSpeed($bytesPerSecond)
{
    $this->maxDownloadSpeed = $bytesPerSecond;
    return $this;
}

/**
 * Apply to cURL handle
 */
curl_setopt($ch, CURLOPT_MAX_RECV_SPEED_LARGE, $this->maxDownloadSpeed);
```

---

## 8. Better Link Parsing (Performance)

```php
/**
 * Optimized link extraction using DOMDocument instead of regex
 */
private function parseLinksFromHtml($html, $baseUrl)
{
    $links = [];
    
    // Suppress warnings from malformed HTML
    libxml_use_internal_errors(true);
    
    $dom = new DOMDocument();
    $dom->loadHTML($html);
    libxml_clear_errors();
    
    $anchors = $dom->getElementsByTagName('a');
    
    foreach ($anchors as $anchor) {
        $href = $anchor->getAttribute('href');
        if (empty($href) || $href === '.' || $href === '..') {
            continue;
        }
        
        // Make absolute URL
        $absoluteUrl = $this->makeAbsoluteUrl($href, $baseUrl);
        $links[] = $absoluteUrl;
    }
    
    return $links;
}

private function makeAbsoluteUrl($url, $base)
{
    if (parse_url($url, PHP_URL_SCHEME) !== null) {
        return $url; // Already absolute
    }
    
    $baseParts = parse_url($base);
    
    if ($url[0] === '/') {
        return $baseParts['scheme'] . '://' . $baseParts['host'] . $url;
    }
    
    return rtrim($base, '/') . '/' . $url;
}
```

---

## 9. Connection Pooling

```php
/**
 * Reusable cURL share handle for connection pooling
 */
private $curlShareHandle = null;

private function getCurlShareHandle()
{
    if ($this->curlShareHandle === null) {
        $this->curlShareHandle = curl_share_init();
        curl_share_setopt($this->curlShareHandle, CURLSHOPT_SHARE, CURL_LOCK_DATA_DNS);
        curl_share_setopt($this->curlShareHandle, CURLSHOPT_SHARE, CURL_LOCK_DATA_SSL_SESSION);
        curl_share_setopt($this->curlShareHandle, CURLSHOPT_SHARE, CURL_LOCK_DATA_CONNECT);
    }
    return $this->curlShareHandle;
}

// Add to cURL handle creation:
curl_setopt($ch, CURLOPT_SHARE, $this->getCurlShareHandle());
```

---

## 10. Additional Improvements

### A. Type Declarations (PHP 7+)

```php
public function setDestinationRoot(string $param): self
{
    $this->destinationRoot = $param;
    if ($this->right($this->destinationRoot, 1) !== '/') {
        $this->destinationRoot .= '/';
    }
    return $this;
}
```

### B. Exception Handling

```php
class ScraperException extends Exception {}

public function run()
{
    try {
        // ... existing code
    } catch (Exception $e) {
        throw new ScraperException("Scraping failed: " . $e->getMessage(), 0, $e);
    }
}
```

### C. Configuration Object

```php
class ScraperConfig
{
    public $destinationRoot = '/tmp/scraper/';
    public $cachePath = '/tmp/';
    public $maxConcurrentDownloads = 10;
    public $maxRetries = 3;
    public $timeout = 300;
    public $userAgent = 'Mozilla/5.0...';
    
    // ... etc
}

// In Scraper class:
private $config;

public function __construct(ScraperConfig $config = null)
{
    $this->config = $config ?? new ScraperConfig();
}
```

---

## Performance Comparison

### Before (Sequential):
- 100 files @ 2 seconds each = **200 seconds** (3.3 minutes)

### After (10 parallel):
- 100 files @ 2 seconds each = **20 seconds** (10x faster)

### After (25 parallel with optimizations):
- 100 files = **8-12 seconds** (20-25x faster)

---

## Implementation Priority

1. **High Priority (Immediate):**
   - Parallel downloads with cURL multi-handle
   - Error handling and retry logic
   - Progress reporting

2. **Medium Priority:**
   - Resume capability
   - Logging system
   - Memory optimization

3. **Low Priority (Nice to have):**
   - Bandwidth limiting
   - Connection pooling
   - Configuration object refactor

---

## Usage Example

```php
$scraper = new scraper();
$scraper->setDestinationRoot('/path/to/downloads/')
    ->setMaxConcurrentDownloads(15)
    ->enableLogging('/path/to/scraper.log')
    ->setProgressCallback(function($progress) {
        echo "Downloaded: {$progress['percent']}%\n";
    })
    ->addLocation('http://example.com/files/', 'example', ['pdf', 'jpg'])
    ->search('important')
    ->run();
```

---

## Security Considerations

1. **Validate URLs** before downloading
2. **Limit file sizes** to prevent disk space exhaustion
3. **Sanitize filenames** (already doing this)
4. **Use HTTPS** when possible
5. **Rate limiting** to be respectful to servers
6. **Whitelist allowed domains** for safety

```php
private $allowedDomains = [];
private $maxFileSize = 104857600; // 100MB

public function setAllowedDomains(array $domains): self
{
    $this->allowedDomains = $domains;
    return $this;
}

private function isUrlAllowed($url): bool
{
    if (empty($this->allowedDomains)) {
        return true;
    }
    
    $host = parse_url($url, PHP_URL_HOST);
    return in_array($host, $this->allowedDomains);
}
```

---

## Conclusion

The single most impactful change is implementing **parallel downloads with cURL multi-handle**. This alone will give you 10-100x performance improvement. Combined with the other optimizations, your scraper will be significantly faster, more reliable, and more maintainable.
