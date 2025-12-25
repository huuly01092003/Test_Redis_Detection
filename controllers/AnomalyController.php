<?php
/**
 * ============================================
 * ANOMALY CONTROLLER - SHOW BY RISK LEVEL
 * ============================================
 */

require_once 'models/OrderDetailModel.php';
require_once 'models/AnomalyDetectionModel.php';
require_once 'models/DynamicCacheKeyGenerator.php';
require_once 'services/AnomalyCalculationService.php';

class AnomalyController {
    private $orderModel;
    private $anomalyModel;
    private $redis;
    private $conn;
    
    private const REDIS_HOST = '127.0.0.1';
    private const REDIS_PORT = 6379;
    private const REDIS_TTL = 86400;
    
    public function __construct() {
        $this->orderModel = new OrderDetailModel();
        $this->anomalyModel = new AnomalyDetectionModel();
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
        }
    }
    
    /**
     * ============================================
     * MAIN METHOD: INDEX - AUTO CACHE
     * ============================================
     */
    public function index() {
        $startTime = microtime(true);
        
        $filters = [
            'years' => isset($_GET['years']) ? array_map('intval', array_filter((array)$_GET['years'])) : [],
            'months' => isset($_GET['months']) ? array_map('intval', array_filter((array)$_GET['months'])) : [],
            'ma_tinh_tp' => $_GET['ma_tinh_tp'] ?? '',
            'gkhl_status' => $_GET['gkhl_status'] ?? ''
        ];
        
        $availableYears = $this->orderModel->getAvailableYears();
        $availableMonths = $this->orderModel->getAvailableMonths();
        $provinces = $this->orderModel->getProvinces();
        
        $allData = []; // ✅ Lưu toàn bộ data để tính summary
        $displayData = []; // ✅ Data để hiển thị (có giới hạn)
        $summary = [
            'total_customers' => 0,
            'critical_count' => 0,
            'high_count' => 0,
            'medium_count' => 0,
            'low_count' => 0
        ];
        
        $dataSource = 'none';
        
        if (!empty($filters['years']) && !empty($filters['months'])) {
            $cacheKey = DynamicCacheKeyGenerator::generate($filters);
            
            // Try Redis first
            if ($this->redis) {
                $cachedData = $this->redis->get($cacheKey);
                
                if ($cachedData) {
                    $decoded = json_decode($cachedData, true);
                    
                    if ($decoded && isset($decoded['results'])) {
                        $allData = $decoded['results'];
                        $allData = $this->normalizeDataStructure($allData);
                        
                        $dataSource = 'redis';
                        
                        $duration = round((microtime(true) - $startTime) * 1000, 2);
                        $_SESSION['info'] = "✅ Dữ liệu từ Cache ({$duration}ms) - " . count($allData) . " bản ghi";
                    }
                }
            }
            
            // Try Summary Table
            if (empty($allData)) {
                $allData = $this->getFromSummaryTable($cacheKey, $filters);
                
                if (!empty($allData)) {
                    $dataSource = 'summary_table';
                    
                    if ($this->redis) {
                        $this->populateRedisCache($cacheKey, $allData);
                    }
                    
                    $duration = round((microtime(true) - $startTime) * 1000, 2);
                    $_SESSION['info'] = "✅ Dữ liệu từ Database ({$duration}ms) - " . count($allData) . " bản ghi";
                }
            }
            
            // Calculate on-the-fly if no cache exists
            if (empty($allData)) {
                try {
                    set_time_limit(300);
                    ini_set('max_execution_time', 300);
                    
                    $_SESSION['warning'] = "⏳ Đang tính toán dữ liệu lần đầu tiên... Vui lòng chờ trong giây lát.";
                    
                    $calculationService = new AnomalyCalculationService($this->conn, $this->redis);
                    $allData = $calculationService->calculateAndCache($filters, $cacheKey);
                    $allData = $this->normalizeDataStructure($allData);
                    
                    $dataSource = 'calculated';
                    
                    $duration = round((microtime(true) - $startTime) * 1000, 2);
                    $_SESSION['success'] = "✅ Tính toán hoàn tất ({$duration}ms) - " . count($allData) . " bản ghi. Các lần truy cập tiếp theo sẽ rất nhanh!";
                    
                } catch (Exception $e) {
                    $_SESSION['error'] = "❌ Lỗi tính toán: " . $e->getMessage();
                    $allData = [];
                }
            }
            
            // ✅ Tính summary từ TOÀN BỘ data
            if (!empty($allData)) {
                $summary = $this->calculateSummary($allData);
                
                // ✅ Tạo displayData: Ưu tiên critical và high trước
                $displayData = $this->prepareDisplayData($allData);
            }
        }
        
        $periodDisplay = $this->generatePeriodDisplay($filters['years'], $filters['months']);
        $loadTime = round((microtime(true) - $startTime) * 1000, 2);
        
        $selectedYears = $filters['years'];
        $selectedMonths = $filters['months'];
        
        // ✅ Pass displayData thay vì allData
        $data = $displayData;
        
        require_once 'views/anomaly/report.php';
    }
    
    /**
     * ✅ NEW: Prepare display data with priority - BALANCED VERSION
     */
    private function prepareDisplayData($allData) {
        // Phân loại theo risk level
        $critical = [];
        $high = [];
        $medium = [];
        $low = [];
        
        foreach ($allData as $row) {
            switch ($row['risk_level']) {
                case 'critical':
                    $critical[] = $row;
                    break;
                case 'high':
                    $high[] = $row;
                    break;
                case 'medium':
                    $medium[] = $row;
                    break;
                case 'low':
                    $low[] = $row;
                    break;
            }
        }
        
        // Sắp xếp từng nhóm theo điểm
        usort($critical, function($a, $b) {
            return $b['total_score'] <=> $a['total_score'];
        });
        usort($high, function($a, $b) {
            return $b['total_score'] <=> $a['total_score'];
        });
        usort($medium, function($a, $b) {
            return $b['total_score'] <=> $a['total_score'];
        });
        usort($low, function($a, $b) {
            return $b['total_score'] <=> $a['total_score'];
        });
        
        // ✅ Logic phân bổ thông minh cho top 500:
        // 1. TẤT CẢ critical (ưu tiên tuyệt đối)
        // 2. TẤT CẢ high (ưu tiên cao)
        // 3. Nếu còn chỗ: phân bổ 70% medium + 30% low
        
        $result = [];
        $limit = 500; // ✅ Tăng từ 100 lên 500 để hiển thị đủ critical + high
        
        // Bước 1: Thêm tất cả critical
        $result = array_merge($result, $critical);
        $remaining = $limit - count($result);
        
        // Bước 2: Thêm tất cả high (hoặc đủ chỗ còn lại)
        if ($remaining > 0) {
            if (count($high) <= $remaining) {
                // Đủ chỗ cho tất cả high
                $result = array_merge($result, $high);
                $remaining = $limit - count($result);
                
                // Bước 3: Phân bổ medium và low
                if ($remaining > 0) {
                    // Tính toán phân bổ: 70% medium, 30% low
                    $mediumSlots = (int)ceil($remaining * 0.7);
                    $lowSlots = $remaining - $mediumSlots;
                    
                    // Lấy medium
                    $mediumToAdd = array_slice($medium, 0, $mediumSlots);
                    $result = array_merge($result, $mediumToAdd);
                    
                    // Lấy low
                    $lowToAdd = array_slice($low, 0, $lowSlots);
                    $result = array_merge($result, $lowToAdd);
                }
            } else {
                // Không đủ chỗ cho tất cả high, chỉ lấy top high
                $result = array_merge($result, array_slice($high, 0, $remaining));
            }
        }
        
        return $result;
    }
    
    /**
     * ✅ Normalize data structure to ensure consistency
     */
    private function normalizeDataStructure($data) {
        foreach ($data as &$row) {
            if (!isset($row['details'])) {
                if (isset($row['anomaly_details'])) {
                    $row['details'] = json_decode($row['anomaly_details'], true) ?? [];
                    unset($row['anomaly_details']);
                } else {
                    $row['details'] = [];
                }
            }
            
            if (is_string($row['details'])) {
                $row['details'] = json_decode($row['details'], true) ?? [];
            }
            
            if (!is_array($row['details'])) {
                $row['details'] = [];
            }
        }
        
        return $data;
    }
    
    /**
     * ============================================
     * GET DATA FROM SUMMARY TABLE
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
                WHERE cache_key = ?";
        
        $params = [$cacheKey];
        
        if (!empty($filters['ma_tinh_tp'])) {
            $sql .= " AND province = ?";
            $params[] = $filters['ma_tinh_tp'];
        }
        
        if (isset($filters['gkhl_status']) && $filters['gkhl_status'] !== '') {
            $sql .= " AND gkhl_status = ?";
            $params[] = ($filters['gkhl_status'] === '1') ? 'Y' : 'N';
        }
        
        $sql .= " ORDER BY total_score DESC";
        
        $stmt = $this->conn->prepare($sql);
        $stmt->execute($params);
        
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($results as &$row) {
            $row['details'] = json_decode($row['anomaly_details'], true) ?? [];
            unset($row['anomaly_details']);
        }
        
        return $results;
    }
    
    /**
     * ============================================
     * POPULATE REDIS CACHE
     * ============================================
     */
    private function populateRedisCache($cacheKey, $data) {
        if (!$this->redis) return;
        
        try {
            $normalizedData = [];
            foreach ($data as $row) {
                $rowCopy = $row;
                if (isset($rowCopy['anomaly_details'])) {
                    $rowCopy['details'] = json_decode($rowCopy['anomaly_details'], true) ?? [];
                    unset($rowCopy['anomaly_details']);
                }
                $normalizedData[] = $rowCopy;
            }
            
            $jsonData = json_encode([
                'timestamp' => time(),
                'count' => count($normalizedData),
                'results' => $normalizedData
            ], JSON_UNESCAPED_UNICODE);
            
            $this->redis->setex($cacheKey, self::REDIS_TTL, $jsonData);
        } catch (Exception $e) {
            // Silent fail
        }
    }
    
    /**
     * ============================================
     * EXPORT CSV
     * ============================================
     */
    public function exportCSV() {
        $filters = [
            'years' => isset($_GET['years']) ? array_map('intval', (array)$_GET['years']) : [],
            'months' => isset($_GET['months']) ? array_map('intval', (array)$_GET['months']) : [],
            'ma_tinh_tp' => $_GET['ma_tinh_tp'] ?? '',
            'gkhl_status' => $_GET['gkhl_status'] ?? ''
        ];
        
        if (empty($filters['years']) || empty($filters['months'])) {
            $_SESSION['error'] = 'Vui lòng chọn năm và tháng để export';
            header('Location: anomaly.php');
            exit;
        }
        
        $cacheKey = DynamicCacheKeyGenerator::generate($filters);
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
        
        $data = $this->normalizeDataStructure($data);
        
        if (empty($data)) {
            $_SESSION['error'] = 'Không có dữ liệu để export';
            header('Location: anomaly.php');
            exit;
        }
        
        $fileName = $this->generateFileName($filters);
        
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $fileName . '"');
        header('Pragma: no-cache');
        header('Expires: 0');
        
        $output = fopen('php://output', 'w');
        fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
        
        $headers = [
            'STT', 'Mã KH', 'Tên KH', 'Tỉnh', 'Điểm BT', 'Mức độ', 'Số dấu hiệu',
            'Tổng DS', 'Tổng đơn', 'Chi tiết'
        ];
        
        fputcsv($output, $headers);
        
        foreach ($data as $index => $row) {
            $details = [];
            if (!empty($row['details']) && is_array($row['details'])) {
                foreach ($row['details'] as $d) {
                    $details[] = $d['description'] ?? '';
                }
            }
            
            $csvRow = [
                $index + 1,
                $row['customer_code'],
                $row['customer_name'],
                $row['province'],
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
    
    // ============================================
    // HELPER METHODS
    // ============================================
    
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
                case 'critical': $summary['critical_count']++; break;
                case 'high': $summary['high_count']++; break;
                case 'medium': $summary['medium_count']++; break;
                case 'low': $summary['low_count']++; break;
            }
        }
        
        return $summary;
    }
    
    private function generatePeriodDisplay($years, $months) {
        if (empty($years) || empty($months)) return '';

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
    
    private function generateFileName($filters) {
        $fileName = "BaoCao_BatThuong";
        
        $years = $filters['years'];
        $months = $filters['months'];
        
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
        $text = mb_strtolower($text, 'UTF-8');
        $text = preg_replace('/[àáảãạăằắẳẵặâầấẩẫậ]/u', 'a', $text);
        $text = preg_replace('/[èéẻẽẹêềếểễệ]/u', 'e', $text);
        $text = preg_replace('/[đ]/u', 'd', $text);
        $text = preg_replace('/[^a-z0-9]+/', '_', $text);
        return trim($text, '_');
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