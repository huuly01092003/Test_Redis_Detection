<?php
/**
 * ============================================
 * ANOMALY CONTROLLER - REDIS-FIRST APPROACH
 * ============================================
 * 
 * Luồng xử lý:
 * 1. Check Redis Cache first (< 200ms)
 * 2. Fallback to Summary Table (< 500ms)
 * 3. Nếu không có data → Hiển thị hướng dẫn chạy cron
 */

require_once 'models/OrderDetailModel.php';

class AnomalyController {
    private $orderModel;
    private $redis;
    private $conn;
    
    private const REDIS_HOST = '127.0.0.1';
    private const REDIS_PORT = 6379;
    private const REDIS_TTL = 86400; // 24 giờ
    
    public function __construct() {
        $this->orderModel = new OrderDetailModel();
        $this->connectRedis();
        
        $database = new Database();
        $this->conn = $database->getConnection();
    }
    
    private function connectRedis() {
        try {
            $this->redis = new Redis();
            $this->redis->connect(self::REDIS_HOST, self::REDIS_PORT, 2.5);
            $this->redis->ping();
        } catch (Exception $e) {
            $this->redis = null;
            error_log("Redis connection failed: " . $e->getMessage());
        }
    }
    
    /**
     * ============================================
     * MAIN METHOD: INDEX (View)
     * ============================================
     */
    public function index() {
        $startTime = microtime(true);
        
        // Parse request parameters
        $selectedYears = isset($_GET['years']) ? (array)$_GET['years'] : [];
        $selectedMonths = isset($_GET['months']) ? (array)$_GET['months'] : [];
        
        $selectedYears = array_map('intval', array_filter($selectedYears));
        $selectedMonths = array_map('intval', array_filter($selectedMonths));
        
        // Filters
        $filters = [
            'ma_tinh_tp' => $_GET['ma_tinh_tp'] ?? '',
            'gkhl_status' => $_GET['gkhl_status'] ?? ''
        ];
        
        // Get available options
        $availableYears = $this->orderModel->getAvailableYears();
        $availableMonths = $this->orderModel->getAvailableMonths();
        $provinces = $this->orderModel->getProvinces();
        
        $data = [];
        $summary = [
            'total_customers' => 0,
            'critical_count' => 0,
            'high_count' => 0,
            'medium_count' => 0,
            'low_count' => 0
        ];
        
        $dataSource = 'none'; // Track where data comes from
        
        // ============================================
        // STEP 1: TRY REDIS CACHE
        // ============================================
        if (!empty($selectedYears) && !empty($selectedMonths)) {
            $cacheKey = $this->generateCacheKey($selectedYears, $selectedMonths);
            
            if ($this->redis) {
                $cachedData = $this->redis->get($cacheKey);
                
                if ($cachedData) {
                    $decoded = json_decode($cachedData, true);
                    
                    if ($decoded && isset($decoded['results'])) {
                        $data = $decoded['results'];
                        $dataSource = 'redis';
                        
                        // Apply filters
                        $data = $this->applyFilters($data, $filters);
                        
                        $duration = round((microtime(true) - $startTime) * 1000, 2);
                        $_SESSION['info'] = "✅ Dữ liệu từ Redis Cache (${duration}ms) - " . count($data) . " bản ghi";
                    }
                }
            }
            
            // ============================================
            // STEP 2: FALLBACK TO SUMMARY TABLE
            // ============================================
            if (empty($data)) {
                $data = $this->getFromSummaryTable($cacheKey, $filters);
                
                if (!empty($data)) {
                    $dataSource = 'summary_table';
                    
                    // Re-populate Redis
                    if ($this->redis) {
                        $this->populateRedisCache($cacheKey, $data);
                    }
                    
                    $duration = round((microtime(true) - $startTime) * 1000, 2);
                    $_SESSION['info'] = "✅ Dữ liệu từ Summary Table (${duration}ms) - " . count($data) . " bản ghi";
                }
            }
            
            // ============================================
            // STEP 3: NO DATA AVAILABLE
            // ============================================
            if (empty($data)) {
                $_SESSION['warning'] = "⚠️ Chưa có dữ liệu phân tích. Vui lòng chạy script: <code>php cron_sync_anomaly.php</code>";
            }
            
            // Calculate summary
            if (!empty($data)) {
                $summary = $this->calculateSummary($data);
                
                // Limit to top 100
                $data = array_slice($data, 0, 100);
            }
        }
        
        $periodDisplay = $this->generatePeriodDisplay($selectedYears, $selectedMonths);
        
        // Performance metrics
        $loadTime = round((microtime(true) - $startTime) * 1000, 2);
        
        require_once 'views/anomaly/report.php';
    }
    
    /**
     * ============================================
     * EXPORT CSV
     * ============================================
     */
    public function exportCSV() {
        $selectedYears = isset($_GET['years']) ? (array)$_GET['years'] : [];
        $selectedMonths = isset($_GET['months']) ? (array)$_GET['months'] : [];
        
        $selectedYears = array_map('intval', array_filter($selectedYears));
        $selectedMonths = array_map('intval', array_filter($selectedMonths));
        
        if (empty($selectedYears) || empty($selectedMonths)) {
            $_SESSION['error'] = 'Vui lòng chọn năm và tháng để export';
            header('Location: anomaly.php');
            exit;
        }
        
        $filters = [
            'ma_tinh_tp' => $_GET['ma_tinh_tp'] ?? '',
            'gkhl_status' => $_GET['gkhl_status'] ?? ''
        ];
        
        // Get data (Redis or Summary Table)
        $cacheKey = $this->generateCacheKey($selectedYears, $selectedMonths);
        $data = [];
        
        if ($this->redis) {
            $cachedData = $this->redis->get($cacheKey);
            if ($cachedData) {
                $decoded = json_decode($cachedData, true);
                $data = $decoded['results'] ?? [];
            }
        }
        
        if (empty($data)) {
            $data = $this->getFromSummaryTable($cacheKey, $filters);
        }
        
        if (empty($data)) {
            $_SESSION['error'] = 'Không có dữ liệu để export';
            header('Location: anomaly.php');
            exit;
        }
        
        // Apply filters
        $data = $this->applyFilters($data, $filters);
        
        $fileName = $this->generateFileName($selectedYears, $selectedMonths, $filters);
        
        // CSV Export
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $fileName . '"');
        header('Pragma: no-cache');
        header('Expires: 0');
        
        $output = fopen('php://output', 'w');
        fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
        
        $headers = [
            'STT', 'Mã KH', 'Tên KH', 'Điểm BT', 'Mức độ', 'Số dấu hiệu',
            'Tổng DS', 'Tổng đơn', 'Chi tiết'
        ];
        
        fputcsv($output, $headers);
        
        foreach ($data as $index => $row) {
            $details = [];
            if (!empty($row['details'])) {
                foreach ($row['details'] as $d) {
                    $details[] = $d['description'] ?? '';
                }
            }
            
            $csvRow = [
                $index + 1,
                $row['customer_code'],
                $row['customer_name'],
                $row['total_score'],
                $this->getRiskLevelText($row['risk_level']),
                $row['anomaly_count'],
                $row['total_sales'],
                $row['total_orders'],
                implode(' | ', $details)
            ];
            
            fputcsv($output, $csvRow);
        }
        
        fclose($output);
        exit;
    }
    
    /**
     * ============================================
     * HELPER METHODS
     * ============================================
     */
    
    private function getFromSummaryTable($cacheKey, $filters = []) {
        $sql = "SELECT 
                    customer_code,
                    customer_name,
                    province,
                    district,
                    total_score,
                    risk_level,
                    anomaly_count,
                    total_sales,
                    total_orders,
                    total_qty,
                    gkhl_status,
                    anomaly_details
                FROM summary_anomaly_results
                WHERE cache_key = ?
                ORDER BY total_score DESC";
        
        $stmt = $this->conn->prepare($sql);
        $stmt->execute([$cacheKey]);
        
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Parse JSON details
        foreach ($results as &$row) {
            $row['details'] = json_decode($row['anomaly_details'], true) ?? [];
            unset($row['anomaly_details']);
        }
        
        return $results;
    }
    
    private function populateRedisCache($cacheKey, $data) {
        try {
            $jsonData = json_encode([
                'timestamp' => time(),
                'count' => count($data),
                'results' => $data
            ], JSON_UNESCAPED_UNICODE);
            
            $this->redis->setex($cacheKey, self::REDIS_TTL, $jsonData);
        } catch (Exception $e) {
            error_log("Redis cache population failed: " . $e->getMessage());
        }
    }
    
    private function applyFilters($data, $filters) {
        if (!empty($filters['ma_tinh_tp'])) {
            $data = array_filter($data, function($item) use ($filters) {
                return $item['province'] === $filters['ma_tinh_tp'];
            });
        }
        
        if (isset($filters['gkhl_status']) && $filters['gkhl_status'] !== '') {
            $data = array_filter($data, function($item) use ($filters) {
                $hasGkhl = ($item['gkhl_status'] === 'Y');
                return ($filters['gkhl_status'] === '1') ? $hasGkhl : !$hasGkhl;
            });
        }
        
        return array_values($data);
    }
    
    private function calculateSummary($data) {
        $summary = [
            'total_customers' => count($data),
            'critical_count' => 0,
            'high_count' => 0,
            'medium_count' => 0,
            'low_count' => 0
        ];
        
        foreach ($data as $item) {
            switch ($item['risk_level']) {
                case 'critical':
                    $summary['critical_count']++;
                    break;
                case 'high':
                    $summary['high_count']++;
                    break;
                case 'medium':
                    $summary['medium_count']++;
                    break;
                case 'low':
                    $summary['low_count']++;
                    break;
            }
        }
        
        return $summary;
    }
    
    private function generateCacheKey($years, $months) {
        return 'anomaly:y' . implode('_', $years) . ':m' . implode('_', $months);
    }
    
    private function generatePeriodDisplay($years, $months) {
        if (empty($years) || empty($months)) {
            return '';
        }

        $yearStr = count($years) > 1 ? 'Năm ' . implode(', ', $years) : 'Năm ' . $years[0];
        
        if (count($months) == 12) {
            $monthStr = 'Tất cả các tháng';
        } elseif (count($months) > 1) {
            $monthStr = 'Tháng ' . implode(', ', $months);
        } else {
            $monthStr = 'Tháng ' . $months[0];
        }

        return $monthStr . ' - ' . $yearStr;
    }
    
    private function generateFileName($years, $months, $filters) {
        $fileName = "BaoCao_BatThuong";
        
        if (count($years) > 1) {
            $fileName .= "_Nam" . min($years) . "-" . max($years);
        } else {
            $fileName .= "_Nam" . $years[0];
        }
        
        if (count($months) == 12) {
            $fileName .= "_TatCaThang";
        } elseif (count($months) > 1) {
            $fileName .= "_Thang" . min($months) . "-" . max($months);
        } else {
            $fileName .= "_Thang" . $months[0];
        }
        
        if (!empty($filters['ma_tinh_tp'])) {
            $fileName .= "_" . $this->slugify($filters['ma_tinh_tp']);
        }
        
        $fileName .= "_" . date('YmdHis') . ".csv";
        
        return $fileName;
    }
    
    private function slugify($text) {
        $text = strtolower($text);
        $text = preg_replace('/[àáảãạăằắẳẵặâầấẩẫậ]/u', 'a', $text);
        $text = preg_replace('/[èéẻẽẹêềếểễệ]/u', 'e', $text);
        $text = preg_replace('/[^a-z0-9]+/', '_', $text);
        $text = trim($text, '_');
        return $text;
    }
    
    private function getRiskLevelText($level) {
        $levels = [
            'critical' => 'CỰC KỲ NGHIÊM TRỌNG',
            'high' => 'NGHI VẤN CAO',
            'medium' => 'Nghi vấn trung bình',
            'low' => 'Nghi vấn thấp',
            'normal' => 'Bình thường'
        ];
        
        return $levels[$level] ?? 'Không xác định';
    }
}