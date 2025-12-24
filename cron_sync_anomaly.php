<?php
/**
 * ============================================
 * CRON SCRIPT: ANOMALY DETECTION - COMPLETE FIXED
 * ============================================
 */

ini_set('memory_limit', '512M');
ini_set('max_execution_time', 600);
set_time_limit(600);
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/config/database.php';

class AnomalySyncService {
    private $conn;
    private $redis;
    
    private const BATCH_SIZE = 500;
    private const REDIS_TTL = 86400;
    
    private const WEIGHTS = [
        'sudden_spike' => 20,
        'return_after_long_break' => 18,
        'checkpoint_rush' => 16,
        'product_concentration' => 14,
        'unusual_product_pattern' => 12,
        'burst_orders' => 15,
        'high_value_outlier' => 13,
        'no_purchase_after_spike' => 10
    ];
    
    public function __construct() {
        $database = new Database();
        $this->conn = $database->getConnection();
        $this->connectRedis();
    }
    
    private function connectRedis() {
        try {
            $this->redis = new Redis();
            $this->redis->connect('127.0.0.1', 6379, 2.5);
            $this->redis->ping();
            $this->log("✅ Redis connected successfully");
        } catch (Exception $e) {
            $this->log("❌ Redis connection failed: " . $e->getMessage());
            die("Redis not available");
        }
    }
    
    public function syncAnomalyData($years = [2025], $months = [1,2,3,4,5,6,7,8,9,10,11,12]) {
        $startTime = microtime(true);
        $cacheKey = $this->generateCacheKey($years, $months);
        
        $this->log("========================================");
        $this->log("🚀 STARTING ANOMALY SYNC");
        $this->log("Cache Key: $cacheKey");
        $this->log("Years: " . implode(',', $years));
        $this->log("Months: " . implode(',', $months));
        $this->log("========================================");
        
        $progressId = $this->initProgress($cacheKey);
        
        try {
            $customers = $this->getCustomersForAnalysis($years, $months);
            $totalCustomers = count($customers);
            
            $this->log("📊 Total customers to analyze: $totalCustomers");
            $this->updateProgress($progressId, $totalCustomers, 0);
            
            if ($totalCustomers === 0) {
                $this->log("⚠️  No customers found for analysis");
                $this->completeProgress($progressId, 'completed');
                return;
            }
            
            $this->cleanOldResults($cacheKey);
            
            $results = [];
            $processed = 0;
            
            foreach (array_chunk($customers, self::BATCH_SIZE) as $batchIndex => $batch) {
                $this->log("⏳ Processing batch " . ($batchIndex + 1) . "...");
                
                $batchResults = $this->processBatch($batch, $years, $months, $cacheKey);
                $results = array_merge($results, $batchResults);
                
                $processed += count($batch);
                $this->updateProgress($progressId, $totalCustomers, $processed);
                
                $percent = round(($processed / $totalCustomers) * 100, 1);
                $this->log("✓ Progress: $processed/$totalCustomers ($percent%) - Found " . count($batchResults) . " anomalies");
                
                gc_collect_cycles();
            }
            
            $this->log("💾 Saving " . count($results) . " anomaly records...");
            $this->saveSummaryResults($results);
            $this->syncToRedis($cacheKey, $results);
            
            $this->completeProgress($progressId, 'completed');
            
            $duration = round(microtime(true) - $startTime, 2);
            $this->log("========================================");
            $this->log("✅ SYNC COMPLETED SUCCESSFULLY!");
            $this->log("Total anomaly records: " . count($results));
            $this->log("Duration: {$duration}s");
            $this->log("========================================");
            
        } catch (Exception $e) {
            $this->log("❌ ERROR: " . $e->getMessage());
            $this->log("Stack trace: " . $e->getTraceAsString());
            $this->completeProgress($progressId, 'failed', $e->getMessage());
            throw $e;
        }
    }
    
    private function getCustomersForAnalysis($years, $months) {
        $yearPlaceholders = implode(',', array_fill(0, count($years), '?'));
        $monthPlaceholders = implode(',', array_fill(0, count($months), '?'));
        
        $sql = "SELECT DISTINCT 
                    o.CustCode,
                    d.TenKH as customer_name,
                    d.Tinh as province,
                    d.QuanHuyen as district,
                    g.KhopSDT as gkhl_status
                FROM orderdetail o
                LEFT JOIN dskh d ON o.CustCode = d.MaKH
                LEFT JOIN gkhl g ON o.CustCode = g.MaKHDMS
                WHERE o.RptYear IN ($yearPlaceholders)
                AND o.RptMonth IN ($monthPlaceholders)
                GROUP BY o.CustCode, d.TenKH, d.Tinh, d.QuanHuyen, g.KhopSDT
                HAVING COUNT(DISTINCT o.OrderNumber) >= 3
                ORDER BY o.CustCode";
        
        $params = array_merge($years, $months);
        $stmt = $this->conn->prepare($sql);
        $stmt->execute($params);
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    private function processBatch($batch, $years, $months, $cacheKey) {
        $results = [];
        $customerCodes = array_column($batch, 'CustCode');
        
        $metricsData = $this->getBatchMetrics($customerCodes, $years, $months);
        
        foreach ($batch as $customer) {
            $custCode = $customer['CustCode'];
            $metrics = $metricsData[$custCode] ?? null;
            
            if (!$metrics) continue;
            
            $metrics['years'] = $years;
            $metrics['months'] = $months;
            $metrics['CustCode'] = $custCode;
            
            $anomalyResult = $this->calculateAnomalyScore($metrics);
            
            if ($anomalyResult['total_score'] >= 10) {
                $results[] = [
                    'customer_code' => $custCode,
                    'customer_name' => $customer['customer_name'] ?? 'N/A',
                    'province' => $customer['province'] ?? 'N/A',
                    'district' => $customer['district'] ?? 'N/A',
                    'total_score' => $anomalyResult['total_score'],
                    'risk_level' => $anomalyResult['risk_level'],
                    'anomaly_count' => count($anomalyResult['details']),
                    'total_sales' => $metrics['total_sales'] ?? 0,
                    'total_orders' => $metrics['total_orders'] ?? 0,
                    'total_qty' => $metrics['total_qty'] ?? 0,
                    'gkhl_status' => ($customer['gkhl_status'] === 'Y') ? 'Y' : 'N',
                    'anomaly_details' => json_encode($anomalyResult['details'], JSON_UNESCAPED_UNICODE),
                    'calculated_for_years' => implode(',', $years),
                    'calculated_for_months' => implode(',', $months),
                    'cache_key' => $cacheKey
                ];
            }
        }
        
        return $results;
    }
    
    private function calculateAnomalyScore($metrics) {
        $scores = [
            'sudden_spike' => $this->checkSuddenSpike($metrics),
            'return_after_long_break' => $this->checkReturnAfterLongBreak($metrics),
            'checkpoint_rush' => $this->checkCheckpointRush($metrics),
            'product_concentration' => $this->checkProductConcentration($metrics),
            'unusual_product_pattern' => $this->checkUnusualProductPattern($metrics),
            'burst_orders' => $this->checkBurstOrders($metrics),
            'high_value_outlier' => $this->checkHighValueOutlier($metrics),
            'no_purchase_after_spike' => $this->checkNoPurchaseAfterSpike($metrics)
        ];
        
        $totalScore = 0;
        $details = [];
        
        foreach ($scores as $type => $score) {
            if ($score > 0) {
                $weightedScore = ($score / 100) * self::WEIGHTS[$type];
                $totalScore += $weightedScore;
                
                $details[] = [
                    'type' => $type,
                    'score' => $score,
                    'weighted_score' => round($weightedScore, 2),
                    'description' => $this->getAnomalyDescription($type, $score)
                ];
            }
        }
        
        usort($details, function($a, $b) {
            return $b['weighted_score'] <=> $a['weighted_score'];
        });
        
        return [
            'total_score' => round($totalScore, 2),
            'risk_level' => $this->getRiskLevel($totalScore),
            'details' => $details
        ];
    }
    
    // ============================================
    // DETECTION METHODS
    // ============================================
    
    private function checkSuddenSpike($metrics) {
        $currentSales = $metrics['total_sales'] ?? 0;
        $historical = $metrics['historical'] ?? [];
        $avgHistoricalSales = $historical['avg_sales'] ?? 0;
        
        if ($currentSales == 0 || $avgHistoricalSales == 0) return 0;
        
        $increaseRate = (($currentSales - $avgHistoricalSales) / $avgHistoricalSales) * 100;
        
        if ($increaseRate >= 500) return 100;
        if ($increaseRate >= 400) return 90;
        if ($increaseRate >= 300) return 80;
        if ($increaseRate >= 200) return 65;
        if ($increaseRate >= 150) return 50;
        
        return 0;
    }
    
    private function checkReturnAfterLongBreak($metrics) {
        $historical = $metrics['historical'] ?? [];
        $monthsGap = $historical['months_since_last_order'] ?? 0;
        
        if ($monthsGap < 3) return 0;
        
        $currentSales = $metrics['total_sales'] ?? 0;
        $lastSales = $historical['last_period_sales'] ?? 0;
        
        if ($lastSales == 0) {
            if ($currentSales >= 10000000) return 100;
            if ($currentSales >= 5000000) return 80;
            if ($currentSales >= 3000000) return 60;
            return 40;
        }
        
        $increaseRate = (($currentSales - $lastSales) / $lastSales) * 100;
        
        if ($monthsGap >= 6 && $increaseRate >= 200) return 100;
        if ($monthsGap >= 4 && $increaseRate >= 150) return 80;
        if ($monthsGap >= 3 && $increaseRate >= 100) return 60;
        
        return 0;
    }
    
    private function checkCheckpointRush($metrics) {
        $checkpointOrders = $metrics['total_checkpoint_orders'] ?? 0;
        $totalOrders = $metrics['total_orders'] ?? 0;
        
        if ($totalOrders == 0) return 0;
        
        $checkpointRatio = ($checkpointOrders / $totalOrders) * 100;
        $checkpointAmount = $metrics['checkpoint_amount'] ?? 0;
        $totalSales = $metrics['total_sales'] ?? 0;
        $amountRatio = $totalSales > 0 ? ($checkpointAmount / $totalSales) * 100 : 0;
        
        if ($checkpointRatio >= 80 && $amountRatio >= 80) return 100;
        if ($checkpointRatio >= 70 && $amountRatio >= 70) return 85;
        if ($checkpointRatio >= 60 && $amountRatio >= 60) return 70;
        if ($checkpointRatio >= 50 || $amountRatio >= 50) return 55;
        
        return 0;
    }
    
    private function checkProductConcentration($metrics) {
        $distinctTypes = $metrics['distinct_product_types'] ?? 0;
        $productDetails = $metrics['product_details'] ?? [];
        
        if ($distinctTypes <= 1 && !empty($productDetails)) {
            $topProduct = $productDetails[0] ?? [];
            $topQty = $topProduct['total_qty'] ?? 0;
            $totalQty = $metrics['total_qty'] ?? 0;
            
            if ($totalQty > 0) {
                $concentration = ($topQty / $totalQty) * 100;
                
                if ($concentration >= 95 && $topQty >= 100) return 100;
                if ($concentration >= 90) return 85;
                if ($concentration >= 80) return 70;
            }
        } elseif ($distinctTypes == 2) {
            if (!empty($productDetails)) {
                $topProduct = $productDetails[0] ?? [];
                $topQty = $topProduct['total_qty'] ?? 0;
                $totalQty = $metrics['total_qty'] ?? 0;
                
                if ($totalQty > 0) {
                    $concentration = ($topQty / $totalQty) * 100;
                    if ($concentration >= 85) return 60;
                    if ($concentration >= 75) return 45;
                }
            }
        }
        
        return 0;
    }
    
    private function checkUnusualProductPattern($metrics) {
        $usualProducts = $metrics['usual_products'] ?? [];
        $productDetails = $metrics['product_details'] ?? [];
        
        if (empty($usualProducts) || empty($productDetails)) return 0;
        
        $currentTypes = array_column($productDetails, 'product_type');
        $usualTypes = array_map(function($code) {
            return substr($code, 0, 2);
        }, $usualProducts);
        $usualTypes = array_unique($usualTypes);
        
        $newTypes = array_diff($currentTypes, $usualTypes);
        if (empty($newTypes)) return 0;
        
        $newRatio = (count($newTypes) / count($currentTypes)) * 100;
        
        $newProductSales = 0;
        $totalSales = $metrics['total_sales'] ?? 0;
        foreach ($productDetails as $product) {
            if (in_array($product['product_type'], $newTypes)) {
                $newProductSales += $product['total_amount'];
            }
        }
        
        $newSalesRatio = $totalSales > 0 ? ($newProductSales / $totalSales) * 100 : 0;
        
        if ($newRatio >= 80 && $newSalesRatio >= 70) return 100;
        if ($newRatio >= 60 && $newSalesRatio >= 50) return 80;
        if ($newRatio >= 40 || $newSalesRatio >= 40) return 60;
        
        return 0;
    }
    
    private function checkBurstOrders($metrics) {
        $dailyOrders = $metrics['daily_orders'] ?? [];
        if (empty($dailyOrders)) return 0;
        
        $maxOrdersInDay = 0;
        $consecutiveDays = 0;
        $maxConsecutive = 0;
        
        foreach ($dailyOrders as $day => $data) {
            $ordersCount = $data['order_count'];
            if ($ordersCount > $maxOrdersInDay) $maxOrdersInDay = $ordersCount;
            
            if ($ordersCount >= 3) {
                $consecutiveDays++;
                if ($consecutiveDays > $maxConsecutive) $maxConsecutive = $consecutiveDays;
            } else {
                $consecutiveDays = 0;
            }
        }
        
        $totalOrders = $metrics['total_orders'] ?? 0;
        $distinctDays = $metrics['distinct_order_days'] ?? 1;
        $avgOrdersPerDay = $totalOrders / $distinctDays;
        
        if ($maxOrdersInDay >= 10 && $maxConsecutive >= 3) return 100;
        if ($maxOrdersInDay >= 8 && $maxConsecutive >= 2) return 85;
        if ($maxOrdersInDay >= 6) return 70;
        if ($maxOrdersInDay >= 5 && $maxOrdersInDay > $avgOrdersPerDay * 3) return 60;
        
        return 0;
    }
    
    private function checkHighValueOutlier($metrics) {
        $maxOrderValue = $metrics['max_order_value'] ?? 0;
        $avgOrderValue = $metrics['avg_order_value'] ?? 0;
        $stddevOrderValue = $metrics['stddev_order_value'] ?? 0;
        
        if ($avgOrderValue == 0 || $stddevOrderValue == 0) return 0;
        
        $sigmaCount = ($maxOrderValue - $avgOrderValue) / $stddevOrderValue;
        
        if ($sigmaCount >= 5) return 100;
        if ($sigmaCount >= 4) return 85;
        if ($sigmaCount >= 3) return 70;
        if ($sigmaCount >= 2.5) return 50;
        
        return 0;
    }
    
    private function checkNoPurchaseAfterSpike($metrics) {
        $spikeScore = $this->checkSuddenSpike($metrics);
        if ($spikeScore < 50) return 0;
        
        $futureActivity = $metrics['future_activity'] ?? [];
        $hasActivity = $futureActivity['has_orders'] ?? false;
        
        if (!$hasActivity) return 100;
        
        $currentSales = $metrics['total_sales'] ?? 0;
        $futureSales = $futureActivity['total_sales'] ?? 0;
        
        if ($currentSales > 0) {
            $dropRate = (($currentSales - $futureSales) / $currentSales) * 100;
            
            if ($dropRate >= 90) return 85;
            if ($dropRate >= 80) return 70;
            if ($dropRate >= 70) return 55;
        }
        
        return 0;
    }
    
    // ============================================
    // DATA FETCHING METHODS
    // ============================================
    
    private function getBatchMetrics($customerCodes, $years, $months) {
        $placeholders = implode(',', array_fill(0, count($customerCodes), '?'));
        $yearPlaceholders = implode(',', array_fill(0, count($years), '?'));
        $monthPlaceholders = implode(',', array_fill(0, count($months), '?'));
        
        $sql = "SELECT 
                    o.CustCode,
                    COUNT(DISTINCT o.OrderNumber) as total_orders,
                    SUM(o.TotalNetAmount) as total_sales,
                    SUM(o.Qty) as total_qty,
                    AVG(o.TotalNetAmount) as avg_order_value,
                    MAX(o.TotalNetAmount) as max_order_value,
                    STDDEV(o.TotalNetAmount) as stddev_order_value,
                    COUNT(DISTINCT SUBSTRING(o.ProductCode, 1, 2)) as distinct_product_types,
                    
                    SUM(CASE WHEN DAY(o.OrderDate) BETWEEN 12 AND 14 THEN 1 ELSE 0 END) as mid_checkpoint_orders,
                    SUM(CASE WHEN DAY(o.OrderDate) BETWEEN 26 AND 28 THEN 1 ELSE 0 END) as end_checkpoint_orders,
                    SUM(CASE WHEN DAY(o.OrderDate) BETWEEN 12 AND 14 
                             OR DAY(o.OrderDate) BETWEEN 26 AND 28 THEN 1 ELSE 0 END) as total_checkpoint_orders,
                    SUM(CASE WHEN DAY(o.OrderDate) BETWEEN 12 AND 14 
                             OR DAY(o.OrderDate) BETWEEN 26 AND 28 
                             THEN o.TotalNetAmount ELSE 0 END) as checkpoint_amount,
                    
                    COUNT(DISTINCT DATE(o.OrderDate)) as distinct_order_days
                    
                FROM orderdetail o
                WHERE o.CustCode IN ($placeholders)
                AND o.RptYear IN ($yearPlaceholders)
                AND o.RptMonth IN ($monthPlaceholders)
                GROUP BY o.CustCode";
        
        $params = array_merge($customerCodes, $years, $months);
        $stmt = $this->conn->prepare($sql);
        $stmt->execute($params);
        
        $results = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $row['historical'] = $this->getHistoricalData($row['CustCode'], $years, $months);
            $row['product_details'] = $this->getProductDetails($row['CustCode'], $years, $months);
            $row['usual_products'] = $this->getUsualProducts($row['CustCode']);
            $row['daily_orders'] = $this->getDailyOrders($row['CustCode'], $years, $months);
            $row['future_activity'] = $this->getFutureActivity($row['CustCode'], $years, $months);
            
            $results[$row['CustCode']] = $row;
        }
        
        return $results;
    }
    
    private function getHistoricalData($custCode, $years, $months) {
        $minYear = min($years);
        $minMonth = min($months);
        
        $sql = "SELECT 
                    COALESCE(AVG(monthly_sales), 0) as avg_sales,
                    COALESCE(MAX(monthly_sales), 0) as max_sales,
                    COALESCE(AVG(monthly_orders), 0) as avg_orders,
                    COUNT(*) as months_count,
                    MAX(order_date) as last_order_date,
                    SUM(monthly_sales) as last_period_sales
                FROM (
                    SELECT 
                        RptYear, 
                        RptMonth,
                        SUM(TotalNetAmount) as monthly_sales,
                        COUNT(DISTINCT OrderNumber) as monthly_orders,
                        MAX(OrderDate) as order_date
                    FROM orderdetail
                    WHERE CustCode = ?
                    AND (
                        RptYear < ?
                        OR (RptYear = ? AND RptMonth < ?)
                    )
                    GROUP BY RptYear, RptMonth
                    ORDER BY RptYear DESC, RptMonth DESC
                    LIMIT 6
                ) as prev_months";
        
        $stmt = $this->conn->prepare($sql);
        $stmt->execute([$custCode, $minYear, $minYear, $minMonth]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!empty($result['last_order_date'])) {
            $lastDate = new DateTime($result['last_order_date']);
            $currentDate = new DateTime($minYear . '-' . str_pad($minMonth, 2, '0', STR_PAD_LEFT) . '-01');
            $interval = $lastDate->diff($currentDate);
            $result['months_since_last_order'] = $interval->m + ($interval->y * 12);
        } else {
            $result['months_since_last_order'] = 99;
        }
        
        return $result;
    }
    
    private function getProductDetails($custCode, $years, $months) {
        $yearPlaceholders = implode(',', array_fill(0, count($years), '?'));
        $monthPlaceholders = implode(',', array_fill(0, count($months), '?'));
        
        $sql = "SELECT 
                    SUBSTRING(ProductCode, 1, 2) as product_type,
                    SUM(Qty) as total_qty,
                    SUM(TotalNetAmount) as total_amount,
                    COUNT(DISTINCT OrderNumber) as order_count
                FROM orderdetail
                WHERE CustCode = ?
                AND RptYear IN ($yearPlaceholders)
                AND RptMonth IN ($monthPlaceholders)
                GROUP BY SUBSTRING(ProductCode, 1, 2)
                ORDER BY total_qty DESC";
        
        $params = array_merge([$custCode], $years, $months);
        $stmt = $this->conn->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    private function getUsualProducts($custCode) {
        $sql = "SELECT DISTINCT ProductCode
                FROM orderdetail
                WHERE CustCode = ?
                AND OrderDate >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)
                GROUP BY ProductCode
                HAVING COUNT(*) >= 2";
        
        $stmt = $this->conn->prepare($sql);
        $stmt->execute([$custCode]);
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }
    
    private function getDailyOrders($custCode, $years, $months) {
        $yearPlaceholders = implode(',', array_fill(0, count($years), '?'));
        $monthPlaceholders = implode(',', array_fill(0, count($months), '?'));
        
        $sql = "SELECT 
                    DATE(OrderDate) as order_date,
                    COUNT(DISTINCT OrderNumber) as order_count,
                    SUM(TotalNetAmount) as daily_sales
                FROM orderdetail
                WHERE CustCode = ?
                AND RptYear IN ($yearPlaceholders)
                AND RptMonth IN ($monthPlaceholders)
                GROUP BY DATE(OrderDate)
                ORDER BY order_date";
        
        $params = array_merge([$custCode], $years, $months);
        $stmt = $this->conn->prepare($sql);
        $stmt->execute($params);
        
        $results = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $results[$row['order_date']] = $row;
        }
        return $results;
    }
    
    private function getFutureActivity($custCode, $years, $months) {
        $maxYear = max($years);
        $maxMonth = max($months);
        
        $sql = "SELECT 
                    COUNT(DISTINCT OrderNumber) as order_count,
                    SUM(TotalNetAmount) as total_sales
                FROM orderdetail
                WHERE CustCode = ?
                AND (
                    (RptYear = ? AND RptMonth > ?)
                    OR (RptYear > ? AND RptYear <= ?)
                )
                LIMIT 100";
        
        $stmt = $this->conn->prepare($sql);
        $stmt->execute([$custCode, $maxYear, $maxMonth, $maxYear, $maxYear + 1]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        return [
            'has_orders' => ($result['order_count'] ?? 0) > 0,
            'order_count' => $result['order_count'] ?? 0,
            'total_sales' => $result['total_sales'] ?? 0
        ];
    }
    
    // ============================================
    // SAVE & SYNC METHODS
    // ============================================
    
    private function saveSummaryResults($results) {
        if (empty($results)) {
            $this->log("⚠️  No anomaly results to save");
            return;
        }
        
        $sql = "INSERT INTO summary_anomaly_results (
            customer_code, customer_name, province, district, total_score, risk_level,
            anomaly_count, total_sales, total_orders, total_qty, gkhl_status,
            anomaly_details, calculated_for_years, calculated_for_months, cache_key
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
        
        $stmt = $this->conn->prepare($sql);
        
        $saved = 0;
        foreach ($results as $row) {
            try {
                $stmt->execute([
                    $row['customer_code'],
                    $row['customer_name'],
                    $row['province'],
                    $row['district'],
                    $row['total_score'],
                    $row['risk_level'],
                    $row['anomaly_count'],
                    $row['total_sales'],
                    $row['total_orders'],
                    $row['total_qty'],
                    $row['gkhl_status'],
                    $row['anomaly_details'],
                    $row['calculated_for_years'],
                    $row['calculated_for_months'],
                    $row['cache_key']
                ]);
                $saved++;
            } catch (Exception $e) {
                if (strpos($e->getMessage(), 'Duplicate entry') === false) {
                    $this->log("⚠️  Error saving {$row['customer_code']}: " . $e->getMessage());
                }
            }
        }
        
        $this->log("✅ Saved $saved records to database");
    }
    
    private function syncToRedis($cacheKey, $results) {
        if (empty($results)) {
            $this->log("⚠️  No data to sync to Redis");
            return;
        }
        
        try {
            $formattedResults = [];
            foreach ($results as $row) {
                $rowCopy = $row;
                $rowCopy['details'] = json_decode($row['anomaly_details'], true);
                unset($rowCopy['anomaly_details']);
                $formattedResults[] = $rowCopy;
            }
            
            $jsonData = json_encode([
                'timestamp' => time(),
                'count' => count($formattedResults),
                'results' => $formattedResults
            ], JSON_UNESCAPED_UNICODE);
            
            $this->redis->setex($cacheKey, self::REDIS_TTL, $jsonData);
            
            $sizeKB = round(strlen($jsonData) / 1024, 2);
            $this->log("✅ Synced to Redis: $cacheKey ($sizeKB KB)");
            
        } catch (Exception $e) {
            $this->log("❌ Redis sync failed: " . $e->getMessage());
        }
    }
    
    // ============================================
    // HELPER METHODS
    // ============================================
    
    private function getAnomalyDescription($type, $score) {
        $descriptions = [
            'sudden_spike' => 'Doanh số tăng đột biến so với trung bình 3-6 tháng trước',
            'return_after_long_break' => 'Quay lại mua sau thời gian dài không hoạt động và mua với số lượng lớn',
            'checkpoint_rush' => 'Tập trung mua hàng vào thời điểm chốt số (12-14 và 26-28)',
            'product_concentration' => 'Chỉ mua 1 loại sản phẩm duy nhất',
            'unusual_product_pattern' => 'Mua sản phẩm mới khác lạ với thói quen trước đó',
            'burst_orders' => 'Mua dồn dập nhiều đơn trong thời gian rất ngắn',
            'high_value_outlier' => 'Có đơn hàng giá trị cao bất thường (>3σ)',
            'no_purchase_after_spike' => 'Không có hoạt động sau khi tăng đột biến'
        ];
        
        $severity = '';
        if ($score >= 90) $severity = ' (Mức độ: Cực kỳ nghiêm trọng)';
        elseif ($score >= 70) $severity = ' (Mức độ: Nghiêm trọng)';
        elseif ($score >= 50) $severity = ' (Mức độ: Cao)';
        
        return ($descriptions[$type] ?? 'Bất thường') . $severity;
    }
    
    private function getRiskLevel($score) {
        if ($score >= 60) return 'critical';
        if ($score >= 40) return 'high';
        if ($score >= 25) return 'medium';
        if ($score >= 10) return 'low';
        return 'normal';
    }
    
    private function generateCacheKey($years, $months) {
        return 'anomaly:y' . implode('_', $years) . ':m' . implode('_', $months);
    }
    
    private function cleanOldResults($cacheKey) {
        $sql = "DELETE FROM summary_anomaly_results WHERE cache_key = ?";
        $stmt = $this->conn->prepare($sql);
        $stmt->execute([$cacheKey]);
        $deleted = $stmt->rowCount();
        $this->log("🗑️  Cleaned $deleted old results for: $cacheKey");
    }
    
    private function initProgress($cacheKey) {
        $sql = "INSERT INTO sync_progress (sync_type, cache_key, status) VALUES (?, ?, ?)";
        $stmt = $this->conn->prepare($sql);
        $stmt->execute(['anomaly_detection', $cacheKey, 'running']);
        return $this->conn->lastInsertId();
    }
    
    private function updateProgress($id, $total, $processed) {
        $sql = "UPDATE sync_progress SET total_records = ?, processed_records = ? WHERE id = ?";
        $stmt = $this->conn->prepare($sql);
        $stmt->execute([$total, $processed, $id]);
    }
    
    private function completeProgress($id, $status, $error = null) {
        $sql = "UPDATE sync_progress SET status = ?, completed_at = NOW(), error_message = ? WHERE id = ?";
        $stmt = $this->conn->prepare($sql);
        $stmt->execute([$status, $error, $id]);
    }
    
    private function log($message) {
        echo "[" . date('Y-m-d H:i:s') . "] $message\n";
    }
}

// ============================================
// MAIN EXECUTION
// ============================================

if (php_sapi_name() === 'cli') {
    try {
        $service = new AnomalySyncService();
        
        $options = getopt('', ['years:', 'months:']);
        
        $years = isset($options['years']) 
            ? array_map('intval', explode(',', $options['years'])) 
            : [2025];
            
        $months = isset($options['months']) 
            ? array_map('intval', explode(',', $options['months'])) 
            : range(1, 12);
        
        echo "\n";
        echo "╔════════════════════════════════════════╗\n";
        echo "║  ANOMALY DETECTION SYNC SERVICE       ║\n";
        echo "║  Version: 2.0 (Fixed)                 ║\n";
        echo "╚════════════════════════════════════════╝\n";
        
        $service->syncAnomalyData($years, $months);
        
        echo "\n✅ Script completed successfully!\n";
        exit(0);
        
    } catch (Exception $e) {
        echo "\n❌ Script failed: " . $e->getMessage() . "\n";
        echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
        exit(1);
    }
} else {
    die("This script must be run from command line");
}