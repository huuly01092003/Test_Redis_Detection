<?php
/**
 * ============================================
 * REDIS CACHE MANAGER
 * ============================================
 * 
 * Tiện ích quản lý Redis Cache cho Anomaly Detection
 * 
 * Sử dụng:
 * - php cache_manager.php --action=status
 * - php cache_manager.php --action=clear --key=anomaly:y2024:m1_2_3
 * - php cache_manager.php --action=list
 * - php cache_manager.php --action=warm --years=2024,2025 --months=1,2,3,4,5,6,7,8,9,10,11,12
 */

class CacheManager {
    private $redis;
    
    public function __construct() {
        $this->connectRedis();
    }
    
    private function connectRedis() {
        try {
            $this->redis = new Redis();
            $this->redis->connect('127.0.0.1', 6379);
            $this->redis->ping();
            echo "✅ Redis connected\n";
        } catch (Exception $e) {
            die("❌ Redis connection failed: " . $e->getMessage() . "\n");
        }
    }
    
    /**
     * Hiển thị trạng thái cache
     */
    public function status() {
        echo "========================================\n";
        echo "  REDIS CACHE STATUS\n";
        echo "========================================\n\n";
        
        $info = $this->redis->info();
        
        echo "📊 Server Info:\n";
        echo "   Version: " . ($info['redis_version'] ?? 'Unknown') . "\n";
        echo "   Used Memory: " . ($info['used_memory_human'] ?? 'Unknown') . "\n";
        echo "   Connected Clients: " . ($info['connected_clients'] ?? 0) . "\n";
        echo "   Total Keys: " . ($info['db0'] ?? 'empty') . "\n";
        echo "   Uptime: " . ($info['uptime_in_seconds'] ?? 0) . " seconds\n\n";
        
        // List anomaly keys
        $keys = $this->redis->keys('anomaly:*');
        
        if (empty($keys)) {
            echo "⚠️  No anomaly cache keys found\n";
        } else {
            echo "🔑 Anomaly Cache Keys (" . count($keys) . "):\n";
            foreach ($keys as $key) {
                $ttl = $this->redis->ttl($key);
                $size = strlen($this->redis->get($key));
                $sizeKB = round($size / 1024, 2);
                
                $ttlDisplay = $ttl > 0 ? $ttl . "s" : "no expiry";
                
                echo "   • $key\n";
                echo "     Size: {$sizeKB}KB | TTL: $ttlDisplay\n";
            }
        }
        
        echo "\n========================================\n";
    }
    
    /**
     * Liệt kê tất cả keys
     */
    public function listKeys($pattern = 'anomaly:*') {
        $keys = $this->redis->keys($pattern);
        
        if (empty($keys)) {
            echo "⚠️  No keys matching pattern: $pattern\n";
            return;
        }
        
        echo "🔑 Found " . count($keys) . " keys:\n\n";
        
        foreach ($keys as $key) {
            $ttl = $this->redis->ttl($key);
            $data = $this->redis->get($key);
            $decoded = json_decode($data, true);
            
            $count = $decoded['count'] ?? 0;
            $timestamp = $decoded['timestamp'] ?? 0;
            $age = $timestamp > 0 ? (time() - $timestamp) : 0;
            
            echo "Key: $key\n";
            echo "  Records: $count\n";
            echo "  Age: " . gmdate('H:i:s', $age) . "\n";
            echo "  TTL: " . ($ttl > 0 ? $ttl . "s" : "no expiry") . "\n";
            echo "  Size: " . round(strlen($data) / 1024, 2) . "KB\n";
            echo "\n";
        }
    }
    
    /**
     * Xóa cache key
     */
    public function clear($key = null) {
        if ($key) {
            if ($this->redis->del($key)) {
                echo "✅ Deleted key: $key\n";
            } else {
                echo "❌ Key not found: $key\n";
            }
        } else {
            // Clear all anomaly keys
            $keys = $this->redis->keys('anomaly:*');
            
            if (empty($keys)) {
                echo "⚠️  No keys to clear\n";
                return;
            }
            
            $deleted = $this->redis->del($keys);
            echo "✅ Deleted $deleted keys\n";
        }
    }
    
    /**
     * Warm up cache (trigger cron sync)
     */
    public function warmUp($years, $months) {
        echo "🔥 Warming up cache...\n";
        echo "Years: " . implode(',', $years) . "\n";
        echo "Months: " . implode(',', $months) . "\n\n";
        
        // Execute cron script
        $cmd = "php " . __DIR__ . "/cron_sync_anomaly.php";
        
        echo "Executing: $cmd\n";
        echo "========================================\n";
        
        passthru($cmd, $exitCode);
        
        if ($exitCode === 0) {
            echo "\n✅ Cache warmed up successfully!\n";
        } else {
            echo "\n❌ Cache warm up failed with exit code: $exitCode\n";
        }
    }
    
    /**
     * Test cache read performance
     */
    public function testPerformance() {
        $keys = $this->redis->keys('anomaly:*');
        
        if (empty($keys)) {
            echo "⚠️  No keys to test\n";
            return;
        }
        
        echo "⚡ Performance Test\n";
        echo "========================================\n\n";
        
        foreach ($keys as $key) {
            echo "Testing: $key\n";
            
            // Test 1: Read from Redis
            $start = microtime(true);
            $data = $this->redis->get($key);
            $redisTime = round((microtime(true) - $start) * 1000, 2);
            
            // Test 2: Decode JSON
            $start = microtime(true);
            $decoded = json_decode($data, true);
            $decodeTime = round((microtime(true) - $start) * 1000, 2);
            
            $totalTime = $redisTime + $decodeTime;
            $recordCount = $decoded['count'] ?? 0;
            
            echo "  Redis Read: {$redisTime}ms\n";
            echo "  JSON Decode: {$decodeTime}ms\n";
            echo "  Total: {$totalTime}ms\n";
            echo "  Records: $recordCount\n";
            echo "  Speed: " . ($recordCount > 0 ? round($recordCount / ($totalTime / 1000), 0) : 0) . " records/sec\n";
            echo "\n";
        }
    }
}

// ============================================
// CLI HANDLER
// ============================================

if (php_sapi_name() !== 'cli') {
    die("This script must be run from command line");
}

$options = getopt('', ['action:', 'key:', 'pattern:', 'years:', 'months:']);

$action = $options['action'] ?? 'status';
$key = $options['key'] ?? null;
$pattern = $options['pattern'] ?? 'anomaly:*';
$years = isset($options['years']) ? explode(',', $options['years']) : [2024, 2025];
$months = isset($options['months']) ? explode(',', $options['months']) : range(1, 12);

$manager = new CacheManager();

switch ($action) {
    case 'status':
        $manager->status();
        break;
    
    case 'list':
        $manager->listKeys($pattern);
        break;
    
    case 'clear':
        $manager->clear($key);
        break;
    
    case 'warm':
        $manager->warmUp($years, $months);
        break;
    
    case 'test':
        $manager->testPerformance();
        break;
    
    default:
        echo "❌ Unknown action: $action\n";
        echo "\nUsage:\n";
        echo "  php cache_manager.php --action=status\n";
        echo "  php cache_manager.php --action=list [--pattern=anomaly:*]\n";
        echo "  php cache_manager.php --action=clear [--key=specific_key]\n";
        echo "  php cache_manager.php --action=warm --years=2024,2025 --months=1,2,3...\n";
        echo "  php cache_manager.php --action=test\n";
        exit(1);
}

echo "\n✅ Done!\n";