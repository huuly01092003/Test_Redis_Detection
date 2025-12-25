<?php
/**
 * ✅ MODEL TỐI ƯU - Báo Cáo Doanh Số Nhân Viên + REDIS CACHE
 */

require_once 'config/database.php';

class NhanVienReportModel {
    private $conn;
    private $redis;
    
    private const REDIS_HOST = '127.0.0.1';
    private const REDIS_PORT = 6379;
    private const REDIS_TTL = 3600; // 1 giờ

    public function __construct() {
        $database = new Database();
        $this->conn = $database->getConnection();
        $this->connectRedis();
    }
    
    /**
     * ✅ KẾT NỐI REDIS
     */
    private function connectRedis() {
        try {
            $this->redis = new Redis();
            $this->redis->connect(self::REDIS_HOST, self::REDIS_PORT, 2.5);
            $this->redis->ping();
        } catch (Exception $e) {
            error_log("Redis connection failed: " . $e->getMessage());
            $this->redis = null;
        }
    }

    /**
     * ✅ LẤY TẤT CẢ NHÂN VIÊN - WITH REDIS CACHE
     */
    public function getAllEmployeesWithStats($tu_ngay, $den_ngay, $thang) {
        // 1️⃣ Tạo cache key
        $cacheKey = $this->generateCacheKey('employees', $thang, $tu_ngay, $den_ngay);
        
        // 2️⃣ Thử lấy từ Redis
        if ($this->redis) {
            try {
                $cached = $this->redis->get($cacheKey);
                if ($cached) {
                    return json_decode($cached, true);
                }
            } catch (Exception $e) {
                error_log("Redis get error: " . $e->getMessage());
            }
        }
        
        // 3️⃣ Query từ database
        list($year, $month) = explode('-', $thang);
        
        $sql = "SELECT 
                    o.DSRCode,
                    o.DSRTypeProvince,
                    
                    MAX(nv_info.TenNVBH) as ten_nhan_vien,
                    MAX(nv_info.MaGSBH) as ma_gsbh,
                    
                    SUM(CASE WHEN DATE(o.OrderDate) >= ? AND DATE(o.OrderDate) <= ? 
                        THEN o.TotalNetAmount ELSE 0 END) as ds_tien_do,
                    COUNT(DISTINCT CASE WHEN DATE(o.OrderDate) >= ? AND DATE(o.OrderDate) <= ? 
                        THEN DATE(o.OrderDate) END) as so_ngay_co_doanh_so_khoang,
                    COALESCE(MAX(ds_khoang.max_daily), 0) as ds_ngay_cao_nhat_nv_khoang,
                    
                    SUM(CASE WHEN o.RptYear = ? AND o.RptMonth = ? 
                        THEN o.TotalNetAmount ELSE 0 END) as ds_tong_thang_nv,
                    COUNT(DISTINCT CASE WHEN o.RptYear = ? AND o.RptMonth = ? 
                        THEN DATE(o.OrderDate) END) as so_ngay_co_doanh_so_thang,
                    COALESCE(MAX(ds_thang.max_daily), 0) as ds_ngay_cao_nhat_nv_thang
                    
                FROM orderdetail o
                
                LEFT JOIN (
                    SELECT DISTINCT MaNVBH, TenNVBH, MaGSBH
                    FROM dskh
                    WHERE MaNVBH IS NOT NULL AND MaNVBH != ''
                ) nv_info ON o.DSRCode = nv_info.MaNVBH
                
                LEFT JOIN (
                    SELECT 
                        DSRCode,
                        MAX(daily_amount) as max_daily
                    FROM (
                        SELECT 
                            DSRCode,
                            DATE(OrderDate) as order_date,
                            SUM(TotalNetAmount) as daily_amount
                        FROM orderdetail
                        WHERE DSRCode IS NOT NULL 
                        AND DSRCode != ''
                        AND DATE(OrderDate) >= ?
                        AND DATE(OrderDate) <= ?
                        GROUP BY DSRCode, DATE(OrderDate)
                    ) daily_khoang
                    GROUP BY DSRCode
                ) ds_khoang ON o.DSRCode = ds_khoang.DSRCode
                
                LEFT JOIN (
                    SELECT 
                        DSRCode,
                        MAX(daily_amount) as max_daily
                    FROM (
                        SELECT 
                            DSRCode,
                            DATE(OrderDate) as order_date,
                            SUM(TotalNetAmount) as daily_amount
                        FROM orderdetail
                        WHERE DSRCode IS NOT NULL 
                        AND DSRCode != ''
                        AND RptYear = ?
                        AND RptMonth = ?
                        GROUP BY DSRCode, DATE(OrderDate)
                    ) daily_thang
                    GROUP BY DSRCode
                ) ds_thang ON o.DSRCode = ds_thang.DSRCode
                
                WHERE o.DSRCode IS NOT NULL 
                AND o.DSRCode != ''
                AND (
                    (DATE(o.OrderDate) >= ? AND DATE(o.OrderDate) <= ?)
                    OR (o.RptYear = ? AND o.RptMonth = ?)
                )
                GROUP BY o.DSRCode, o.DSRTypeProvince
                HAVING ds_tien_do > 0 OR ds_tong_thang_nv > 0
                ORDER BY o.DSRCode";
        
        $stmt = $this->conn->prepare($sql);
        $stmt->execute([
            $tu_ngay, $den_ngay,
            $tu_ngay, $den_ngay,
            $year, $month,
            $year, $month,
            $tu_ngay, $den_ngay,
            $year, $month,
            $tu_ngay, $den_ngay,
            $year, $month
        ]);
        
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // 4️⃣ Lưu vào Redis
        if ($this->redis && !empty($results)) {
            try {
                $this->redis->setex(
                    $cacheKey, 
                    self::REDIS_TTL, 
                    json_encode($results, JSON_UNESCAPED_UNICODE)
                );
            } catch (Exception $e) {
                error_log("Redis set error: " . $e->getMessage());
            }
        }
        
        return $results;
    }

    /**
     * ✅ TỔNG THEO THÁNG - WITH REDIS CACHE
     */
    public function getSystemStatsForMonth($thang) {
        $cacheKey = "nhanvien:stats:month:{$thang}";
        
        if ($this->redis) {
            try {
                $cached = $this->redis->get($cacheKey);
                if ($cached) {
                    return json_decode($cached, true);
                }
            } catch (Exception $e) {
                error_log("Redis get error: " . $e->getMessage());
            }
        }
        
        list($year, $month) = explode('-', $thang);
        
        $sql = "SELECT 
                    COALESCE(SUM(o.TotalNetAmount), 0) as total,
                    COUNT(DISTINCT o.DSRCode) as emp_count,
                    DAY(LAST_DAY(CONCAT(?, '-', LPAD(?, 2, '0'), '-01'))) as so_ngay,
                    
                    COALESCE(SUM(o.TotalNetAmount), 0) / 
                    NULLIF(COUNT(DISTINCT o.DSRCode) * DAY(LAST_DAY(CONCAT(?, '-', LPAD(?, 2, '0'), '-01'))), 0) as ds_tb_chung_thang,
                    
                    AVG(emp_max.max_daily) as ds_ngay_cao_nhat_tb_thang
                    
                FROM orderdetail o
                LEFT JOIN (
                    SELECT 
                        DSRCode, 
                        MAX(daily_total) as max_daily
                    FROM (
                        SELECT 
                            DSRCode, 
                            DATE(OrderDate) as order_date,
                            SUM(TotalNetAmount) as daily_total
                        FROM orderdetail
                        WHERE RptYear = ? AND RptMonth = ?
                        AND DSRCode IS NOT NULL AND DSRCode != ''
                        GROUP BY DSRCode, DATE(OrderDate)
                    ) daily
                    GROUP BY DSRCode
                ) emp_max ON o.DSRCode = emp_max.DSRCode
                WHERE o.RptYear = ? AND o.RptMonth = ?
                AND o.DSRCode IS NOT NULL AND o.DSRCode != ''";
        
        $stmt = $this->conn->prepare($sql);
        $stmt->execute([$year, $month, $year, $month, $year, $month, $year, $month]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($this->redis && !empty($result)) {
            try {
                $this->redis->setex(
                    $cacheKey, 
                    self::REDIS_TTL, 
                    json_encode($result, JSON_UNESCAPED_UNICODE)
                );
            } catch (Exception $e) {
                error_log("Redis set error: " . $e->getMessage());
            }
        }
        
        return $result;
    }

    /**
     * ✅ TỔNG THEO KHOẢNG - WITH REDIS CACHE
     */
    public function getSystemStatsForRange($tu_ngay, $den_ngay) {
        $cacheKey = "nhanvien:stats:range:{$tu_ngay}:{$den_ngay}";
        
        if ($this->redis) {
            try {
                $cached = $this->redis->get($cacheKey);
                if ($cached) {
                    return json_decode($cached, true);
                }
            } catch (Exception $e) {
                error_log("Redis get error: " . $e->getMessage());
            }
        }
        
        $sql = "SELECT 
                    COALESCE(SUM(o.TotalNetAmount), 0) as total,
                    COUNT(DISTINCT o.DSRCode) as emp_count,
                    DATEDIFF(?, ?) + 1 as so_ngay,
                    
                    COALESCE(SUM(o.TotalNetAmount), 0) / 
                    NULLIF(COUNT(DISTINCT o.DSRCode) * (DATEDIFF(?, ?) + 1), 0) as ds_tb_chung_khoang,
                    
                    AVG(emp_max.max_daily) as ds_ngay_cao_nhat_tb_khoang
                    
                FROM orderdetail o
                LEFT JOIN (
                    SELECT 
                        DSRCode, 
                        MAX(daily_total) as max_daily
                    FROM (
                        SELECT 
                            DSRCode, 
                            DATE(OrderDate) as order_date,
                            SUM(TotalNetAmount) as daily_total
                        FROM orderdetail
                        WHERE DATE(OrderDate) >= ? AND DATE(OrderDate) <= ?
                        AND DSRCode IS NOT NULL AND DSRCode != ''
                        GROUP BY DSRCode, DATE(OrderDate)
                    ) daily
                    GROUP BY DSRCode
                ) emp_max ON o.DSRCode = emp_max.DSRCode
                WHERE DATE(o.OrderDate) >= ? AND DATE(o.OrderDate) <= ?
                AND o.DSRCode IS NOT NULL AND o.DSRCode != ''";
        
        $stmt = $this->conn->prepare($sql);
        $stmt->execute([
            $den_ngay, $tu_ngay,
            $den_ngay, $tu_ngay,
            $tu_ngay, $den_ngay,
            $tu_ngay, $den_ngay
        ]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($this->redis && !empty($result)) {
            try {
                $this->redis->setex(
                    $cacheKey, 
                    self::REDIS_TTL, 
                    json_encode($result, JSON_UNESCAPED_UNICODE)
                );
            } catch (Exception $e) {
                error_log("Redis set error: " . $e->getMessage());
            }
        }
        
        return $result;
    }

    /**
     * ✅ CHI TIẾT ĐƠN HÀNG NHÂN VIÊN
     */
    public function getEmployeeOrderDetails($dsr_code, $tu_ngay, $den_ngay) {
        // Không cache chi tiết đơn hàng vì có thể rất nhiều
        $sql = "SELECT 
                    o.OrderNumber as ma_don,
                    o.OrderDate as ngay_dat,
                    o.CustCode as ma_kh,
                    COALESCE(d.TenKH, 'N/A') as ten_kh,
                    COALESCE(d.DiaChi, '') as dia_chi_kh,
                    COALESCE(d.Tinh, '') as tinh_kh,
                    o.TotalNetAmount as so_tien,
                    o.Qty as so_luong
                FROM orderdetail o
                LEFT JOIN dskh d ON o.CustCode = d.MaKH
                WHERE o.DSRCode = ?
                AND DATE(o.OrderDate) >= ?
                AND DATE(o.OrderDate) <= ?
                ORDER BY o.OrderDate DESC, o.OrderNumber DESC";
        
        $stmt = $this->conn->prepare($sql);
        $stmt->execute([$dsr_code, $tu_ngay, $den_ngay]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * ✅ GENERATE CACHE KEY
     */
    private function generateCacheKey($type, $thang, $tu_ngay, $den_ngay) {
        return "nhanvien:{$type}:{$thang}:{$tu_ngay}:{$den_ngay}";
    }

    /**
     * ✅ XÓA CACHE (gọi từ controller khi cần)
     */
    public function clearCache($pattern = 'nhanvien:*') {
        if (!$this->redis) return false;
        
        try {
            $keys = $this->redis->keys($pattern);
            if (!empty($keys)) {
                $this->redis->del($keys);
                return count($keys);
            }
            return 0;
        } catch (Exception $e) {
            error_log("Redis clear cache error: " . $e->getMessage());
            return false;
        }
    }

    // ============================================
    // CÁC HÀM CŨ GIỮ NGUYÊN
    // ============================================

    public function getAvailableMonths() {
        $sql = "SELECT DISTINCT CONCAT(RptYear, '-', LPAD(RptMonth, 2, '0')) as thang
                FROM orderdetail
                WHERE RptYear IS NOT NULL AND RptMonth IS NOT NULL
                AND RptYear >= 2020
                ORDER BY RptYear DESC, RptMonth DESC
                LIMIT 24";
        
        $stmt = $this->conn->prepare($sql);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
    }

    public function getTotalByMonth($thang) {
        list($year, $month) = explode('-', $thang);
        $sql = "SELECT COALESCE(SUM(TotalNetAmount), 0) as total
                FROM orderdetail WHERE RptYear = ? AND RptMonth = ?";
        $stmt = $this->conn->prepare($sql);
        $stmt->execute([$year, $month]);
        return floatval($stmt->fetch()['total'] ?? 0);
    }

    public function getTotalByDateRange($tu_ngay, $den_ngay) {
        $sql = "SELECT COALESCE(SUM(TotalNetAmount), 0) as total
                FROM orderdetail WHERE DATE(OrderDate) >= ? AND DATE(OrderDate) <= ?";
        $stmt = $this->conn->prepare($sql);
        $stmt->execute([$tu_ngay, $den_ngay]);
        return floatval($stmt->fetch()['total'] ?? 0);
    }
}
?>