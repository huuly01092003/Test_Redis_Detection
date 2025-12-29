<?php
/**
 * System Management View
 * File: views/system/management.php
 */

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once dirname(__DIR__, 2) . '/middleware/AuthMiddleware.php';
$currentUser = AuthMiddleware::getCurrentUser();
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Quản Lý Hệ Thống - <?= htmlspecialchars($currentUser['full_name']) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        body { background: #f5f7fa; }
        
        .page-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 30px;
            border-radius: 15px;
            margin-bottom: 30px;
            box-shadow: 0 5px 20px rgba(0,0,0,0.1);
        }
        
        .stat-card {
            background: white;
            border-radius: 15px;
            padding: 20px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
            margin-bottom: 20px;
            border-left: 4px solid #667eea;
        }
        
        .data-card {
            background: white;
            border-radius: 15px;
            box-shadow: 0 5px 20px rgba(0,0,0,0.05);
            padding: 25px;
            margin-bottom: 25px;
        }
        
        .table-info {
            background: #f8f9fa;
            padding: 15px;
            border-radius: 10px;
            margin-bottom: 15px;
        }
        
        .danger-zone {
            border: 2px solid #dc3545;
            border-radius: 10px;
            padding: 20px;
            background: #fff5f5;
            margin-top: 20px;
        }
        
        .btn-danger-action {
            background: linear-gradient(135deg, #dc3545 0%, #c82333 100%);
            border: none;
            color: white;
            font-weight: 600;
        }
        
        .btn-danger-action:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(220, 53, 69, 0.4);
        }
        
        .redis-key-item {
            background: #f8f9fa;
            padding: 10px;
            border-radius: 5px;
            margin-bottom: 5px;
            font-family: monospace;
            font-size: 0.85rem;
        }
    </style>
</head>
<body>

<?php
// Load navbar
require_once __DIR__ . '/../components/navbar_loader.php';

$breadcrumb = [
    ['label' => 'Quản Lý Hệ Thống', 'url' => '']
];

renderSmartNavbar('system', ['breadcrumb' => $breadcrumb]);
?>

<div class="container-fluid mt-4">
    <!-- Page Header -->
    <div class="page-header">
        <div class="d-flex justify-content-between align-items-center">
            <div>
                <h2><i class="fas fa-cogs me-2"></i>Quản Lý Hệ Thống</h2>
                <p class="mb-0">Quản lý dữ liệu, cache và bảng summary</p>
            </div>
            <div>
                <span class="badge bg-light text-dark px-3 py-2">
                    <i class="fas fa-user-shield me-2"></i>
                    <?= htmlspecialchars($currentUser['full_name']) ?>
                </span>
            </div>
        </div>
    </div>

    <?php if (isset($_SESSION['success'])): ?>
        <div class="alert alert-success alert-dismissible fade show">
            <i class="fas fa-check-circle me-2"></i><?= $_SESSION['success'] ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php unset($_SESSION['success']); ?>
    <?php endif; ?>

    <?php if (isset($_SESSION['error'])): ?>
        <div class="alert alert-danger alert-dismissible fade show">
            <i class="fas fa-exclamation-circle me-2"></i><?= $_SESSION['error'] ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php unset($_SESSION['error']); ?>
    <?php endif; ?>
    <!-- ✅ TAB NAVIGATION -->
    <ul class="nav nav-tabs mb-4" role="tablist">
        <li class="nav-item">
            <a class="nav-link active" href="users.php?tab=users">
                <i class="fas fa-users me-2"></i>Người Dùng
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link" href="users.php?tab=system">
                <i class="fas fa-cogs me-2"></i>Quản Lý Data & Cache
            </a>
        </li>
    </ul>
    <!-- System Statistics -->
    <div class="row mb-4">
        <div class="col-md-3">
            <div class="stat-card">
                <h3 class="mb-1"><?= number_format(array_sum(array_column($stats['tables'], 'count'))) ?></h3>
                <p class="mb-0 text-muted">
                    <i class="fas fa-database me-1"></i>Tổng Số Bản Ghi
                </p>
            </div>
        </div>
        <div class="col-md-3">
            <div class="stat-card">
                <h3 class="mb-1"><?= count($stats['tables']) ?></h3>
                <p class="mb-0 text-muted">
                    <i class="fas fa-table me-1"></i>Số Bảng Quản Lý
                </p>
            </div>
        </div>
        <div class="col-md-3">
            <div class="stat-card">
                <h3 class="mb-1">
                    <?= $stats['redis']['connected'] ? 
                        '<span class="text-success">Connected</span>' : 
                        '<span class="text-danger">Disconnected</span>' ?>
                </h3>
                <p class="mb-0 text-muted">
                    <i class="fas fa-server me-1"></i>Redis Status
                </p>
            </div>
        </div>
        <div class="col-md-3">
            <div class="stat-card">
                <h3 class="mb-1"><?= number_format($stats['redis']['total_keys']) ?></h3>
                <p class="mb-0 text-muted">
                    <i class="fas fa-key me-1"></i>Redis Keys
                </p>
            </div>
        </div>
    </div>

    <!-- Tabs Navigation -->
    <ul class="nav nav-tabs mb-4" role="tablist">
        <li class="nav-item">
            <a class="nav-link active" data-bs-toggle="tab" href="#tables">
                <i class="fas fa-table me-2"></i>Quản Lý Bảng
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link" data-bs-toggle="tab" href="#redis">
                <i class="fas fa-server me-2"></i>Redis Cache
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link" data-bs-toggle="tab" href="#summary">
                <i class="fas fa-chart-bar me-2"></i>Summary Tables
            </a>
        </li>
    </ul>

    <!-- Tab Content -->
    <div class="tab-content">
        <!-- ========================================
             TAB 1: QUẢN LÝ BẢNG
             ======================================== -->
        <div id="tables" class="tab-pane fade show active">
            <div class="data-card">
                <h5 class="mb-4">
                    <i class="fas fa-database me-2"></i>Quản Lý Dữ Liệu Bảng
                </h5>

                <?php foreach ($stats['tables'] as $table => $info): ?>
                    <div class="table-info">
                        <div class="row align-items-center">
                            <div class="col-md-4">
                                <h6 class="mb-1">
                                    <i class="fas fa-table me-2 text-primary"></i>
                                    <?= htmlspecialchars($info['name']) ?>
                                </h6>
                                <small class="text-muted">Table: <code><?= $table ?></code></small>
                            </div>
                            <div class="col-md-2 text-center">
                                <strong><?= number_format($info['count']) ?></strong>
                                <br><small class="text-muted">bản ghi</small>
                            </div>
                            <div class="col-md-2 text-center">
                                <strong><?= $info['size'] ?> MB</strong>
                                <br><small class="text-muted">dung lượng</small>
                            </div>
                            <div class="col-md-4 text-end">
                                <?php if (in_array($table, ['orderdetail', 'summary_anomaly_results', 'summary_report_cache', 'summary_nhanvien_report_cache', 'summary_nhanvien_kpi_cache'])): ?>
                                    <button class="btn btn-sm btn-warning me-2" 
                                            onclick="showClearByDateModal('<?= $table ?>', '<?= $info['name'] ?>')">
                                        <i class="fas fa-calendar me-1"></i>Xóa Theo Ngày
                                    </button>
                                <?php endif; ?>
                                <button class="btn btn-sm btn-danger" 
                                        onclick="confirmClearTable('<?= $table ?>', '<?= $info['name'] ?>')">
                                    <i class="fas fa-trash me-1"></i>Xóa Toàn Bộ
                                </button>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>

                <div class="danger-zone">
                    <h6 class="text-danger mb-3">
                        <i class="fas fa-exclamation-triangle me-2"></i>
                        Khu Vực Nguy Hiểm
                    </h6>
                    <p class="text-muted mb-3">
                        Các thao tác dưới đây sẽ <strong>XÓA VĨNH VIỄN</strong> dữ liệu và 
                        <strong>KHÔNG THỂ KHÔI PHỤC</strong>. Vui lòng thực hiện cẩn thận!
                    </p>
                    <button class="btn btn-danger-action" onclick="confirmClearAllData()">
                        <i class="fas fa-bomb me-2"></i>Xóa Toàn Bộ Dữ Liệu Hệ Thống
                    </button>
                </div>
            </div>
        </div>

        <!-- ========================================
             TAB 2: REDIS CACHE
             ======================================== -->
        <div id="redis" class="tab-pane fade">
            <div class="data-card">
                <h5 class="mb-4">
                    <i class="fas fa-server me-2"></i>Quản Lý Redis Cache
                </h5>

                <?php if ($stats['redis']['connected']): ?>
                    <div class="alert alert-info">
                        <i class="fas fa-info-circle me-2"></i>
                        <strong>Trạng thái:</strong> Connected | 
                        <strong>Keys:</strong> <?= number_format($stats['redis']['total_keys']) ?> | 
                        <strong>Memory:</strong> <?= $stats['redis']['memory_used'] ?>
                    </div>

                    <div class="mb-4">
                        <h6><i class="fas fa-search me-2"></i>Tìm Kiếm Keys</h6>
                        <div class="row g-3">
                            <div class="col-md-6">
                                <input type="text" class="form-control" id="redisPattern" 
                                       placeholder="Nhập pattern (VD: anomaly:*, *report*)" value="*">
                            </div>
                            <div class="col-md-3">
                                <button class="btn btn-primary w-100" onclick="searchRedisKeys()">
                                    <i class="fas fa-search me-2"></i>Tìm Kiếm
                                </button>
                            </div>
                            <div class="col-md-3">
                                <button class="btn btn-danger w-100" onclick="confirmClearRedisByPattern()">
                                    <i class="fas fa-trash me-2"></i>Xóa Theo Pattern
                                </button>
                            </div>
                        </div>
                    </div>

                    <div id="redisKeysList" class="mb-4">
                        <p class="text-muted">Nhấn "Tìm Kiếm" để xem danh sách keys</p>
                    </div>

                    <div class="danger-zone">
                        <h6 class="text-danger mb-3">
                            <i class="fas fa-exclamation-triangle me-2"></i>
                            Xóa Toàn Bộ Cache
                        </h6>
                        <p class="text-muted mb-3">
                            Xóa toàn bộ cache Redis. Hệ thống sẽ tính toán lại dữ liệu khi cần.
                        </p>
                        <button class="btn btn-danger-action" onclick="confirmClearRedisAll()">
                            <i class="fas fa-trash-alt me-2"></i>Xóa Toàn Bộ Redis Cache
                        </button>
                    </div>
                <?php else: ?>
                    <div class="alert alert-danger">
                        <i class="fas fa-exclamation-circle me-2"></i>
                        <strong>Lỗi:</strong> Không thể kết nối đến Redis server.
                        Vui lòng kiểm tra cấu hình và khởi động Redis.
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- ========================================
             TAB 3: SUMMARY TABLES
             ======================================== -->
        <div id="summary" class="tab-pane fade">
            <div class="data-card">
                <h5 class="mb-4">
                    <i class="fas fa-chart-bar me-2"></i>Quản Lý Bảng Summary
                </h5>

                <?php 
                $summaryTables = [
                    'summary_anomaly_results' => $stats['tables']['summary_anomaly_results'],
                    'summary_report_cache' => $stats['tables']['summary_report_cache'],
                    'summary_nhanvien_report_cache' => $stats['tables']['summary_nhanvien_report_cache'],
                    'summary_nhanvien_kpi_cache' => $stats['tables']['summary_nhanvien_kpi_cache']
                ];
                ?>

                <?php foreach ($summaryTables as $table => $info): ?>
                    <div class="table-info">
                        <div class="row align-items-center">
                            <div class="col-md-5">
                                <h6 class="mb-1">
                                    <i class="fas fa-database me-2 text-success"></i>
                                    <?= htmlspecialchars($info['name']) ?>
                                </h6>
                                <small class="text-muted">
                                    <code><?= $table ?></code> - Cache/Tính toán trước
                                </small>
                            </div>
                            <div class="col-md-2 text-center">
                                <strong><?= number_format($info['count']) ?></strong>
                                <br><small class="text-muted">records</small>
                            </div>
                            <div class="col-md-2 text-center">
                                <strong><?= $info['size'] ?> MB</strong>
                                <br><small class="text-muted">size</small>
                            </div>
                            <div class="col-md-3 text-end">
                                <button class="btn btn-sm btn-warning me-2" 
                                        onclick="showClearByDateModal('<?= $table ?>', '<?= $info['name'] ?>')">
                                    <i class="fas fa-calendar me-1"></i>Theo Ngày
                                </button>
                                <button class="btn btn-sm btn-danger" 
                                        onclick="confirmClearTable('<?= $table ?>', '<?= $info['name'] ?>')">
                                    <i class="fas fa-trash me-1"></i>Xóa Hết
                                </button>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>

                <div class="alert alert-info mt-4">
                    <h6><i class="fas fa-info-circle me-2"></i>Về Summary Tables:</h6>
                    <ul class="mb-0">
                        <li>Lưu kết quả tính toán trước để tăng tốc độ truy vấn</li>
                        <li>Tự động đồng bộ với Redis cache</li>
                        <li>Xóa khi dữ liệu cũ hoặc cần tính toán lại</li>
                        <li>Hệ thống tự động tạo lại khi cần thiết</li>
                    </ul>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Modal: Clear By Date -->
<div class="modal fade" id="clearByDateModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-warning text-dark">
                <h5 class="modal-title">
                    <i class="fas fa-calendar me-2"></i>Xóa Dữ Liệu Theo Ngày
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p>Bảng: <strong id="modalTableName"></strong></p>
                <input type="hidden" id="modalTable">
                
                <div class="row g-3">
                    <div class="col-md-4">
                        <label class="form-label fw-bold">Năm <span class="text-danger">*</span></label>
                        <input type="number" class="form-control" id="clearYear" 
                               min="2020" max="2030" placeholder="VD: 2024">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label fw-bold">Tháng</label>
                        <select class="form-select" id="clearMonth">
                            <option value="">-- Tất cả --</option>
                            <?php for ($i = 1; $i <= 12; $i++): ?>
                                <option value="<?= $i ?>">Tháng <?= $i ?></option>
                            <?php endfor; ?>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label fw-bold">Ngày</label>
                        <select class="form-select" id="clearDay">
                            <option value="">-- Tất cả --</option>
                            <?php for ($i = 1; $i <= 31; $i++): ?>
                                <option value="<?= $i ?>"><?= $i ?></option>
                            <?php endfor; ?>
                        </select>
                    </div>
                </div>

                <div class="alert alert-warning mt-3">
                    <i class="fas fa-exclamation-triangle me-2"></i>
                    Dữ liệu sẽ bị xóa vĩnh viễn và không thể khôi phục!
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Hủy</button>
                <button type="button" class="btn btn-warning" onclick="executeClearByDate()">
                    <i class="fas fa-trash me-2"></i>Xác Nhận Xóa
                </button>
            </div>
        </div>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
// ========================================
// TABLE MANAGEMENT FUNCTIONS
// ========================================

function confirmClearTable(table, name) {
    if (!confirm(`⚠️ XÓA TOÀN BỘ DỮ LIỆU\n\nBảng: ${name}\nTable: ${table}\n\nHành động này KHÔNG THỂ HOÀN TÁC!\n\nBạn có chắc chắn muốn tiếp tục?`)) {
        return;
    }
    
    $.post('system.php?action=clear_table_all', {
        table: table
    }, function(response) {
        if (response.success) {
            alert('✅ ' + response.message);
            location.reload();
        } else {
            alert('❌ ' + response.error);
        }
    }, 'json').fail(function() {
        alert('❌ Lỗi kết nối server');
    });
}

function showClearByDateModal(table, name) {
    $('#modalTable').val(table);
    $('#modalTableName').text(name + ' (' + table + ')');
    $('#clearYear').val('');
    $('#clearMonth').val('');
    $('#clearDay').val('');
    new bootstrap.Modal(document.getElementById('clearByDateModal')).show();
}

function executeClearByDate() {
    const table = $('#modalTable').val();
    const year = $('#clearYear').val();
    const month = $('#clearMonth').val();
    const day = $('#clearDay').val();
    
    if (!year) {
        alert('Vui lòng chọn năm');
        return;
    }
    
    let dateStr = `Năm ${year}`;
    if (month) dateStr += ` Tháng ${month}`;
    if (day) dateStr += ` Ngày ${day}`;
    
    if (!confirm(`⚠️ XÓA DỮ LIỆU\n\nBảng: ${table}\nThời gian: ${dateStr}\n\nBạn có chắc chắn?`)) {
        return;
    }
    
    $.post('system.php?action=clear_table_by_date', {
        table: table,
        year: year,
        month: month,
        day: day
    }, function(response) {
        if (response.success) {
            alert('✅ ' + response.message);
            bootstrap.Modal.getInstance(document.getElementById('clearByDateModal')).hide();
            location.reload();
        } else {
            alert('❌ ' + response.error);
        }
    }, 'json').fail(function() {
        alert('❌ Lỗi kết nối server');
    });
}

function confirmClearAllData() {
    const confirmation = prompt('⚠️⚠️⚠️ CẢNH BÁO CỰC KỲ NGUY HIỂM ⚠️⚠️⚠️\n\nĐIỀU NÀY SẼ XÓA TOÀN BỘ DỮ LIỆU HỆ THỐNG!\n\nGõ "XOA TAT CA" để xác nhận:');
    
    if (confirmation !== 'XOA TAT CA') {
        alert('Đã hủy thao tác');
        return;
    }
    
    alert('Chức năng này chưa được kích hoạt để tránh xóa nhầm.\nVui lòng xóa từng bảng riêng lẻ.');
}

// ========================================
// REDIS MANAGEMENT FUNCTIONS
// ========================================

function searchRedisKeys() {
    const pattern = $('#redisPattern').val() || '*';
    
    $.get('system.php?action=get_redis_keys', {
        pattern: pattern
    }, function(response) {
        if (response.success) {
            displayRedisKeys(response);
        } else {
            alert('❌ ' + response.error);
        }
    }, 'json').fail(function() {
        alert('❌ Lỗi kết nối server');
    });
}

function displayRedisKeys(data) {
    let html = `<h6>Tìm thấy ${data.total} keys (hiển thị ${data.showing}):</h6>`;
    
    if (data.keys.length === 0) {
        html += '<p class="text-muted">Không tìm thấy key nào</p>';
    } else {
        data.keys.forEach(key => {
            const ttlText = key.ttl > 0 ? `TTL: ${key.ttl}s` : 'No expire';
            html += `
                <div class="redis-key-item">
                    <strong>${key.key}</strong>
                    <span class="float-end">
                        <span class="badge bg-info">${key.type}</span>
                        <span class="badge bg-secondary">${ttlText}</span>
                        <span class="badge bg-primary">${(key.size/1024).toFixed(2)} KB</span>
                    </span>
                </div>
            `;
        });
    }
    
    $('#redisKeysList').html(html);
}

function confirmClearRedisByPattern() {
    const pattern = $('#redisPattern').val() || '*';
    
    if (!confirm(`⚠️ XÓA REDIS KEYS\n\nPattern: ${pattern}\n\nBạn có chắc chắn?`)) {
        return;
    }
    
    $.post('system.php?action=clear_redis_pattern', {
        pattern: pattern
    }, function(response) {
        if (response.success) {
            alert('✅ ' + response.message);
            searchRedisKeys();
        } else {
            alert('❌ ' + response.error);
        }
    }, 'json').fail(function() {
        alert('❌ Lỗi kết nối server');
    });
}

function confirmClearRedisAll() {
    if (!confirm('⚠️ XÓA TOÀN BỘ REDIS CACHE\n\nHành động này sẽ xóa tất cả cache.\nHệ thống sẽ tính toán lại khi cần.\n\nBạn có chắc chắn?')) {
        return;
    }
    
    $.post('system.php?action=clear_redis_all', {}, function(response) {
        if (response.success) {
            alert('✅ ' + response.message);
            location.reload();
        } else {
            alert('❌ ' + response.error);
        }
    }, 'json').fail(function() {
        alert('❌ Lỗi kết nối server');
    });
}

// Auto-hide alerts
setTimeout(function() {
    $('.alert').fadeOut('slow');
}, 5000);
</script>
</body>
</html>