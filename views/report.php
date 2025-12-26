<?php
/**
 * ============================================
 * BÁO CÁO KHÁCH HÀNG - PERFORMANCE OPTIMIZED
 * ============================================
 */

// Start session ONCE
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Define constants
define('PROJECT_ROOT', dirname(__DIR__));

// Load dependencies - ONLY WHAT'S NEEDED
require_once PROJECT_ROOT . '/middleware/AuthMiddleware.php';
require_once PROJECT_ROOT . '/controllers/ReportController.php';

// Load permission helpers if exists
$permissionHelpersPath = PROJECT_ROOT . '/helpers/permission_helpers.php';
if (file_exists($permissionHelpersPath)) {
    require_once $permissionHelpersPath;
}

// Require login
AuthMiddleware::requireLogin();

// ============================================
// HANDLE ACTION ROUTING
// ============================================
$action = $_GET['action'] ?? 'index';
$controller = new ReportController();

if ($action === 'detail') {
    $controller->detail();
    exit;
}

// ============================================
// INDEX ACTION - OPTIMIZED
// ============================================

// Get parameters
$selectedYears = isset($_GET['years']) ? (array)$_GET['years'] : [date('Y')];
$selectedMonths = isset($_GET['months']) ? (array)$_GET['months'] : [date('n')];

$selectedYears = array_map('intval', array_filter($selectedYears));
$selectedMonths = array_map('intval', array_filter($selectedMonths));

$filters = [
    'ma_tinh_tp' => $_GET['ma_tinh_tp'] ?? '',
    'ma_khach_hang' => $_GET['ma_khach_hang'] ?? '',
    'gkhl_status' => $_GET['gkhl_status'] ?? ''
];

// Initialize variables
$data = [];
$summary = [
    'total_khach_hang' => 0,
    'total_doanh_so' => 0,
    'total_san_luong' => 0,
    'total_gkhl' => 0
];

// Load model
require_once PROJECT_ROOT . '/models/OrderDetailModel.php';
$model = new OrderDetailModel();

// Get metadata ONCE (cached in model)
$provinces = $model->getProvinces();
$availableYears = $model->getAvailableYears();
$availableMonths = range(1, 12);

// Get data using CACHED model methods
if (!empty($selectedYears) && !empty($selectedMonths)) {
    $startTime = microtime(true);
    
    // Use cached methods from OrderDetailModel
    $data = $model->getCustomerSummary($selectedYears, $selectedMonths, $filters);
    $summary = $model->getSummaryStats($selectedYears, $selectedMonths, $filters);
    
    $duration = round((microtime(true) - $startTime) * 1000, 2);
    
    // Show cache notification
    if ($duration < 100) {
        $_SESSION['success'] = "✅ Dữ liệu từ Cache Redis ({$duration}ms) - Cực nhanh!";
    } else {
        $_SESSION['info'] = "✅ Dữ liệu từ Database ({$duration}ms) - Lần sau sẽ nhanh hơn!";
    }
}

// Build period display
$periodDisplay = '';
if (!empty($selectedYears) && !empty($selectedMonths)) {
    $yearsStr = count($selectedYears) > 1 ? 'Năm ' . implode(', ', $selectedYears) : 'Năm ' . $selectedYears[0];
    
    if (count($selectedMonths) == 12) {
        $monthsStr = 'Tất cả các tháng';
    } elseif (count($selectedMonths) > 1) {
        $monthsStr = 'Tháng ' . implode(', ', $selectedMonths);
    } else {
        $monthsStr = 'Tháng ' . $selectedMonths[0];
    }
    
    $periodDisplay = $monthsStr . ' - ' . $yearsStr;
}

// Load navbar ONCE at the end
require_once PROJECT_ROOT . '/views/components/navbar_loader.php';
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Báo cáo Khách hàng</title>
    
    <!-- CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap5.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    <link href="https://cdn.jsdelivr.net/npm/select2-bootstrap-5-theme@1.3.0/dist/select2-bootstrap-5-theme.min.css" rel="stylesheet" />
    
    <style>
        body { background: #f5f7fa; }
        .filter-card, .data-card {
            background: white;
            border-radius: 15px;
            box-shadow: 0 5px 20px rgba(0,0,0,0.05);
            padding: 25px;
            margin-bottom: 25px;
        }
        .stat-box {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 20px;
            border-radius: 10px;
            text-align: center;
            transition: transform 0.3s;
        }
        .stat-box:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 25px rgba(102, 126, 234, 0.3);
        }
        .table thead {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
        }
        .badge-gkhl {
            background: #28a745;
            color: white;
            padding: 5px 12px;
            border-radius: 12px;
            font-size: 0.75rem;
            font-weight: 600;
        }
        .badge-no-gkhl {
            background: #dc3545;
            color: white;
            padding: 5px 12px;
            border-radius: 12px;
            font-size: 0.75rem;
            font-weight: 600;
        }
        .btn-detail {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border: none;
            color: white;
            padding: 5px 12px;
            border-radius: 20px;
            font-size: 0.8rem;
            transition: all 0.3s;
        }
        .btn-detail:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(102, 126, 234, 0.4);
            color: white;
        }
        tr.gkhl-row {
            background-color: rgba(40, 167, 69, 0.05);
        }
        tr.gkhl-row:hover {
            background-color: rgba(40, 167, 69, 0.1);
        }
        .period-display {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 15px 20px;
            border-radius: 10px;
            margin-bottom: 20px;
            text-align: center;
        }
        .export-section {
            background: linear-gradient(135deg, #28a745 0%, #20c997 100%);
            color: white;
            padding: 20px;
            border-radius: 15px;
            margin-bottom: 25px;
        }
    </style>
</head>
<body>
    
    <?php 
    // Render navbar with period info
    renderSmartNavbar('report', [
        'period' => $periodDisplay,
        'breadcrumb' => [
            ['label' => 'Báo Cáo', 'url' => ''],
            ['label' => 'Khách Hàng', 'url' => '']
        ]
    ]); 
    ?>

    <?php if (isset($_SESSION['success'])): ?>
        <div class="container-fluid mt-3">
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <i class="fas fa-check-circle me-2"></i><?= $_SESSION['success'] ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        </div>
        <?php unset($_SESSION['success']); ?>
    <?php endif; ?>

    <?php if (isset($_SESSION['info'])): ?>
        <div class="container-fluid mt-3">
            <div class="alert alert-info alert-dismissible fade show" role="alert">
                <i class="fas fa-info-circle me-2"></i><?= $_SESSION['info'] ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        </div>
        <?php unset($_SESSION['info']); ?>
    <?php endif; ?>

    <?php if (isset($_SESSION['error'])): ?>
        <div class="container-fluid mt-3">
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <i class="fas fa-exclamation-circle me-2"></i><?= $_SESSION['error'] ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        </div>
        <?php unset($_SESSION['error']); ?>
    <?php endif; ?>

    <div class="container-fluid mt-4">
        <!-- Filter Card -->
        <div class="filter-card">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h5 class="mb-0">
                    <i class="fas fa-filter me-2"></i>Bộ lọc dữ liệu
                </h5>
                <?php if (function_exists('getRoleBadge')): ?>
                    <div><?= getRoleBadge() ?></div>
                <?php endif; ?>
            </div>
            
            <form method="GET" action="report.php" id="filterForm">
                <div class="row g-3">
                    <!-- Năm -->
                    <div class="col-md-3">
                        <label class="form-label fw-bold">
                            <i class="fas fa-calendar me-1"></i>Năm <span class="text-danger">*</span>
                        </label>
                        <select name="years[]" id="yearSelect" class="form-select" multiple required>
                            <?php foreach ($availableYears as $year): ?>
                                <option value="<?= $year ?>" 
                                    <?= in_array($year, $selectedYears) ? 'selected' : '' ?>>
                                    <?= $year ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <div class="mt-2">
                            <button type="button" class="btn btn-sm btn-outline-primary quick-select-btn" onclick="selectAllYears()">
                                <i class="fas fa-check-double"></i> Tất cả
                            </button>
                            <button type="button" class="btn btn-sm btn-outline-secondary quick-select-btn" onclick="clearYears()">
                                <i class="fas fa-times"></i> Xóa
                            </button>
                        </div>
                    </div>

                    <!-- Tháng -->
                    <div class="col-md-3">
                        <label class="form-label fw-bold">
                            <i class="fas fa-calendar-alt me-1"></i>Tháng <span class="text-danger">*</span>
                        </label>
                        <select name="months[]" id="monthSelect" class="form-select" multiple required>
                            <?php foreach ($availableMonths as $month): ?>
                                <option value="<?= $month ?>" 
                                    <?= in_array($month, $selectedMonths) ? 'selected' : '' ?>>
                                    Tháng <?= $month ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <div class="mt-2">
                            <button type="button" class="btn btn-sm btn-outline-primary quick-select-btn" onclick="selectAllMonths()">
                                <i class="fas fa-check-double"></i> Tất cả
                            </button>
                            <button type="button" class="btn btn-sm btn-outline-secondary quick-select-btn" onclick="clearMonths()">
                                <i class="fas fa-times"></i> Xóa
                            </button>
                            <button type="button" class="btn btn-sm btn-outline-info quick-select-btn" onclick="selectQuarter(1)">Q1</button>
                            <button type="button" class="btn btn-sm btn-outline-info quick-select-btn" onclick="selectQuarter(2)">Q2</button>
                            <button type="button" class="btn btn-sm btn-outline-info quick-select-btn" onclick="selectQuarter(3)">Q3</button>
                            <button type="button" class="btn btn-sm btn-outline-info quick-select-btn" onclick="selectQuarter(4)">Q4</button>
                        </div>
                    </div>

                    <!-- Tỉnh -->
                    <div class="col-md-2">
                        <label class="form-label fw-bold">Tỉnh/Thành phố</label>
                        <select name="ma_tinh_tp" class="form-select">
                            <option value="">-- Tất cả --</option>
                            <?php foreach ($provinces as $province): ?>
                                <option value="<?= htmlspecialchars($province) ?>" 
                                    <?= ($filters['ma_tinh_tp'] === $province) ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($province) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <!-- Mã KH -->
                    <div class="col-md-2">
                        <label class="form-label fw-bold">Mã khách hàng</label>
                        <input type="text" name="ma_khach_hang" class="form-control" 
                               placeholder="Nhập mã KH..." value="<?= htmlspecialchars($filters['ma_khach_hang']) ?>">
                    </div>

                    <!-- GKHL -->
                    <div class="col-md-2">
                        <label class="form-label fw-bold">
                            <i class="fas fa-handshake me-1"></i>Trạng thái GKHL
                        </label>
                        <select name="gkhl_status" class="form-select">
                            <option value="" <?= ($filters['gkhl_status'] === '') ? 'selected' : '' ?>>-- Tất cả --</option>
                            <option value="1" <?= ($filters['gkhl_status'] === '1') ? 'selected' : '' ?>>
                                ✅ Đã tham gia
                            </option>
                            <option value="0" <?= ($filters['gkhl_status'] === '0') ? 'selected' : '' ?>>
                                ❌ Chưa tham gia
                            </option>
                        </select>
                    </div>
                </div>

                <div class="row mt-3">
                    <div class="col-md-10">
                        <button type="submit" class="btn btn-primary btn-lg w-100">
                            <i class="fas fa-search me-2"></i>Tìm kiếm
                        </button>
                    </div>
                    <div class="col-md-2">
                        <a href="report.php" class="btn btn-secondary btn-lg w-100">
                            <i class="fas fa-sync me-2"></i>Làm mới
                        </a>
                    </div>
                </div>
            </form>
        </div>

        <?php if (!empty($periodDisplay)): ?>
            <div class="period-display">
                <h5 class="mb-0">
                    <i class="fas fa-calendar-check me-2"></i>
                    Kỳ báo cáo: <strong><?= htmlspecialchars($periodDisplay) ?></strong>
                </h5>
            </div>
        <?php endif; ?>

        <?php if (!empty($data)): ?>
            <!-- Export Section -->
            <?php 
            // Build export URL with proper parameter format
            $exportParams = [];
            foreach ($selectedYears as $year) {
                $exportParams[] = 'years[]=' . $year;
            }
            foreach ($selectedMonths as $month) {
                $exportParams[] = 'months[]=' . $month;
            }
            $exportParams[] = 'ma_tinh_tp=' . urlencode($filters['ma_tinh_tp']);
            $exportParams[] = 'ma_khach_hang=' . urlencode($filters['ma_khach_hang']);
            $exportParams[] = 'gkhl_status=' . urlencode($filters['gkhl_status']);
            $exportUrl = "export.php?action=download&" . implode('&', $exportParams);
            
            // Check if user can export
            $canExportData = true; // Default true
            if (function_exists('canExport')) {
                $canExportData = canExport();
            } elseif (function_exists('isViewer')) {
                $canExportData = !isViewer();
            }
            ?>
            
            <?php if ($canExportData): ?>
            <!-- Export Section for Admin/User -->
            <div class="export-section">
                <div class="row align-items-center">
                    <div class="col-md-7">
                        <h5 class="mb-2">
                            <i class="fas fa-download me-2"></i>Xuất dữ liệu
                        </h5>
                        <p class="mb-1 opacity-90">
                            <i class="fas fa-info-circle me-1"></i>
                            Export <strong><?= number_format($summary['total_khach_hang']) ?></strong> khách hàng theo kết quả tìm kiếm
                        </p>
                        <small class="opacity-75 d-block">
                            <i class="fas fa-calendar-check me-1"></i>
                            <?= htmlspecialchars($periodDisplay) ?>
                            <?php if (!empty($filters['ma_tinh_tp'])): ?>
                                | <i class="fas fa-map-marker-alt me-1"></i><?= htmlspecialchars($filters['ma_tinh_tp']) ?>
                            <?php endif; ?>
                            <?php if (!empty($filters['ma_khach_hang'])): ?>
                                | <i class="fas fa-search me-1"></i>Mã: <?= htmlspecialchars($filters['ma_khach_hang']) ?>
                            <?php endif; ?>
                            <?php if ($filters['gkhl_status'] !== ''): ?>
                                | <i class="fas fa-handshake me-1"></i>GKHL: <?= $filters['gkhl_status'] === '1' ? 'Có' : 'Không' ?>
                            <?php endif; ?>
                        </small>
                        <small class="text-warning d-block mt-1">
                            <i class="fas fa-exclamation-triangle me-1"></i>
                            File CSV bao gồm thông tin bất thường (nếu có)
                        </small>
                    </div>
                    <div class="col-md-5 text-end">
                        <a href="<?= $exportUrl ?>" class="btn btn-light btn-lg shadow-sm" target="_blank">
                            <i class="fas fa-file-csv me-2"></i>
                            <strong>Tải Xuống CSV</strong>
                        </a>
                        <div class="mt-2">
                            <small class="text-white opacity-75">
                                <i class="fas fa-clock me-1"></i>
                                Định dạng: UTF-8 | Có thông tin GKHL & Anomaly
                            </small>
                        </div>
                    </div>
                </div>
            </div>
            <?php else: ?>
            <!-- Export Blocked for Viewer -->
            <div class="alert alert-warning d-flex align-items-center mb-4">
                <i class="fas fa-lock fa-3x me-3"></i>
                <div>
                    <h5 class="mb-1">
                        <i class="fas fa-eye me-2"></i>Chế độ Viewer - Không có quyền Export
                    </h5>
                    <p class="mb-0">
                        Bạn chỉ có thể xem dữ liệu trên màn hình. 
                        Để tải xuống file CSV, vui lòng liên hệ quản trị viên để được cấp quyền.
                    </p>
                </div>
            </div>
            <?php endif; ?>

            <!-- Stats Boxes -->
            <div class="row mb-4">
                <div class="col-md-3">
                    <div class="stat-box">
                        <h2><?= number_format($summary['total_khach_hang']) ?></h2>
                        <p class="mb-0"><i class="fas fa-users me-2"></i>Tổng số khách hàng</p>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="stat-box">
                        <h2><?= number_format($summary['total_doanh_so'], 0) ?></h2>
                        <p class="mb-0"><i class="fas fa-dollar-sign me-2"></i>Tổng doanh số</p>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="stat-box">
                        <h2><?= number_format($summary['total_san_luong'], 0) ?></h2>
                        <p class="mb-0"><i class="fas fa-boxes me-2"></i>Tổng sản lượng</p>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="stat-box">
                        <h2><?= number_format($summary['total_gkhl'], 0) ?></h2>
                        <p class="mb-0"><i class="fas fa-handshake me-2"></i>KH có GKHL</p>
                    </div>
                </div>
            </div>

            <!-- Data Table -->
            <div class="data-card">
                <h5 class="mb-4">
                    <i class="fas fa-users me-2"></i>Danh sách khách hàng 
                    <span class="badge bg-primary"><?= number_format(count($data)) ?> khách hàng (Top 100)</span>
                </h5>
                
                <div class="table-responsive">
                    <table id="customerTable" class="table table-hover table-sm">
                        <thead>
                            <tr>
                                <th>STT</th>
                                <th>Mã KH</th>
                                <th>Tên khách hàng</th>
                                <th>Địa chỉ</th>
                                <th>Tỉnh/TP</th>
                                <th class="text-end">Doanh số</th>
                                <th class="text-end">Sản lượng</th>
                                <th class="text-center"><i class="fas fa-handshake"></i> GKHL</th>
                                <th class="text-center">Thao tác</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($data as $index => $row): ?>
                                <tr <?= !empty($row['has_gkhl']) ? 'class="gkhl-row"' : '' ?>>
                                    <td class="text-center"><?= $index + 1 ?></td>
                                    <td><strong><?= htmlspecialchars($row['ma_khach_hang']) ?></strong></td>
                                    <td><?= htmlspecialchars($row['ten_khach_hang']) ?></td>
                                    <td><?= htmlspecialchars($row['dia_chi_khach_hang']) ?></td>
                                    <td><?= htmlspecialchars($row['ma_tinh_tp']) ?></td>
                                    <td class="text-end"><strong><?= number_format($row['total_doanh_so'], 0) ?></strong></td>
                                    <td class="text-end"><?= number_format($row['total_san_luong'], 0) ?></td>
                                    <td class="text-center">
                                        <?php if (!empty($row['has_gkhl'])): ?>
                                            <span class="badge badge-gkhl">
                                                <i class="fas fa-check-circle"></i> GKHL
                                            </span>
                                        <?php else: ?>
                                            <span class="badge badge-no-gkhl">
                                                <i class="fas fa-times-circle"></i> Chưa có
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-center">
                                        <?php 
                                        $yearsParam = http_build_query(['years' => $selectedYears]);
                                        $monthsParam = http_build_query(['months' => $selectedMonths]);
                                        $detailUrl = "report.php?action=detail&ma_khach_hang=" . urlencode($row['ma_khach_hang']);
                                        $detailUrl .= "&{$yearsParam}&{$monthsParam}";
                                        ?>
                                        <a href="<?= $detailUrl ?>" class="btn btn-detail btn-sm">
                                            <i class="fas fa-eye me-1"></i>Chi tiết
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

        <?php elseif (!empty($selectedYears) && !empty($selectedMonths)): ?>
            <div class="alert alert-warning">
                <i class="fas fa-info-circle me-2"></i>
                Không tìm thấy dữ liệu phù hợp với bộ lọc.
            </div>
        <?php else: ?>
            <div class="alert alert-info">
                <i class="fas fa-info-circle me-2"></i>
                Vui lòng chọn năm và tháng để xem báo cáo.
            </div>
        <?php endif; ?>
    </div>

    <!-- Scripts -->
    <script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap5.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    
    <script>
        $(document).ready(function() {
            // Initialize Select2
            $('#yearSelect, #monthSelect').select2({
                theme: 'bootstrap-5',
                width: '100%',
                placeholder: 'Chọn...',
                allowClear: false
            });

            // DataTable
            $('#customerTable').DataTable({
                language: {
                    url: '//cdn.datatables.net/plug-ins/1.13.6/i18n/vi.json'
                },
                pageLength: 25,
                order: [[5, 'desc']],
                columnDefs: [
                    { orderable: false, targets: 8 },
                    { className: "text-center", targets: [0, 7, 8] }
                ],
                autoWidth: false
            });
        });

        // Quick select functions
        function selectAllYears() {
            $('#yearSelect option').prop('selected', true);
            $('#yearSelect').trigger('change');
        }

        function clearYears() {
            $('#yearSelect').val(null).trigger('change');
        }

        function selectAllMonths() {
            $('#monthSelect option').prop('selected', true);
            $('#monthSelect').trigger('change');
        }

        function clearMonths() {
            $('#monthSelect').val(null).trigger('change');
        }

        function selectQuarter(quarter) {
            const quarters = {
                1: [1, 2, 3],
                2: [4, 5, 6],
                3: [7, 8, 9],
                4: [10, 11, 12]
            };
            
            $('#monthSelect').val(quarters[quarter]).trigger('change');
        }
    </script>
</body>
</html>