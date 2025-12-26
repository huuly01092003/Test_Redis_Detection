<?php
/**
 * Enhanced Navbar with Authentication & Role Switching
 */

function renderAuthNavbar($currentPage = '') {
    require_once __DIR__ . '/../../middleware/AuthMiddleware.php';
    
    $currentUser = AuthMiddleware::getCurrentUser();
    $currentRole = AuthMiddleware::getCurrentRole();
    $isAdmin = $currentRole === 'admin' && !AuthMiddleware::isSwitchedRole();
    $isSwitched = AuthMiddleware::isSwitchedRole();
    
    $currentPath = $_SERVER['PHP_SELF'];
    $baseName = basename($currentPath, '.php');
?>
<nav class="navbar navbar-expand-lg navbar-custom navbar-dark sticky-top shadow-lg">
    <div class="container-fluid">
        <!-- Brand -->
        <a class="navbar-brand d-flex align-items-center" href="dashboard.php">
            <i class="fas fa-chart-line me-2"></i>
            <div>
                <strong style="font-size: 1.2rem;">HỆ THỐNG BÁO CÁO</strong>
                <div style="font-size: 0.7rem; opacity: 0.9;">Phân tích doanh số & phát hiện bất thường</div>
            </div>
        </a>
        
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#mainNavbar">
            <span class="navbar-toggler-icon"></span>
        </button>
        
        <div class="collapse navbar-collapse" id="mainNavbar">
            <!-- Main Navigation -->
            <ul class="navbar-nav me-auto mb-2 mb-lg-0">
                
                <!-- Reports Section (Always visible) -->
                <li class="nav-item dropdown">
                    <a class="nav-link dropdown-toggle <?= in_array($baseName, ['report', 'nhanvien_report', 'nhanvien_kpi']) ? 'active' : '' ?>" 
                       href="#" role="button" data-bs-toggle="dropdown">
                        <i class="fas fa-chart-bar me-1"></i>Báo Cáo
                    </a>
                    <ul class="dropdown-menu dropdown-menu-dark">
                        <li>
                            <a class="dropdown-item <?= $baseName === 'report' ? 'active' : '' ?>" href="report.php">
                                <i class="fas fa-users me-2"></i>Báo Cáo Khách Hàng
                            </a>
                        </li>
                        <li>
                            <a class="dropdown-item <?= $baseName === 'nhanvien_report' ? 'active' : '' ?>" 
                               href="nhanvien_report.php">
                                <i class="fas fa-user-tie me-2"></i>Doanh Số Nhân Viên
                            </a>
                        </li>
                        <li>
                            <a class="dropdown-item <?= $baseName === 'nhanvien_kpi' ? 'active' : '' ?>" 
                               href="nhanvien_kpi.php">
                                <i class="fas fa-chart-line me-2"></i>KPI Nhân Viên
                            </a>
                        </li>
                    </ul>
                </li>

                <!-- Anomaly Detection (Based on permission) -->
                <?php if ($isAdmin || AuthMiddleware::checkPermission('view_anomaly')): ?>
                <li class="nav-item">
                    <a class="nav-link <?= $baseName === 'anomaly' ? 'active' : '' ?>" href="anomaly.php">
                        <i class="fas fa-exclamation-triangle me-1"></i>
                        <span class="badge bg-warning text-dark ms-1">Phát Hiện BT</span>
                    </a>
                </li>
                <?php endif; ?>

                <!-- Admin Only Sections -->
                <?php if ($isAdmin): ?>
                <li class="nav-item dropdown">
                    <a class="nav-link dropdown-toggle <?= in_array($baseName, ['index', 'dskh', 'gkhl']) ? 'active' : '' ?>" 
                       href="#" role="button" data-bs-toggle="dropdown">
                        <i class="fas fa-upload me-1"></i>Import Dữ Liệu
                    </a>
                    <ul class="dropdown-menu dropdown-menu-dark">
                        <li>
                            <a class="dropdown-item <?= $baseName === 'index' ? 'active' : '' ?>" href="index.php">
                                <i class="fas fa-file-csv me-2"></i>Order Detail
                            </a>
                        </li>
                        <li><hr class="dropdown-divider"></li>
                        <li>
                            <a class="dropdown-item <?= $baseName === 'dskh' ? 'active' : '' ?>" href="dskh.php">
                                <i class="fas fa-users me-2"></i>Danh Sách Khách Hàng
                            </a>
                        </li>
                        <li>
                            <a class="dropdown-item <?= $baseName === 'gkhl' ? 'active' : '' ?>" href="gkhl.php">
                                <i class="fas fa-handshake me-2"></i>Gắn Kết Hoa Linh
                            </a>
                        </li>
                    </ul>
                </li>

                <li class="nav-item dropdown">
                    <a class="nav-link dropdown-toggle" href="#" role="button" data-bs-toggle="dropdown">
                        <i class="fas fa-database me-1"></i>Quản Lý
                    </a>
                    <ul class="dropdown-menu dropdown-menu-dark">
                        <li>
                            <a class="dropdown-item <?= $baseName === 'users' ? 'active' : '' ?>" href="users.php">
                                <i class="fas fa-users-cog me-2"></i>Người Dùng
                            </a>
                        </li>
                        <li>
                            <a class="dropdown-item" href="dskh.php?action=list">
                                <i class="fas fa-list me-2"></i>Xem DSKH
                            </a>
                        </li>
                        <li>
                            <a class="dropdown-item" href="gkhl.php?action=list">
                                <i class="fas fa-list me-2"></i>Xem GKHL
                            </a>
                        </li>
                    </ul>
                </li>
                <?php endif; ?>
            </ul>

            <!-- Right Side: User Info & Actions -->
            <ul class="navbar-nav">
                <!-- Role Switcher (Admin only) -->
                <?php if ($isAdmin || $isSwitched): ?>
                <li class="nav-item dropdown">
                    <a class="nav-link dropdown-toggle" href="#" role="button" data-bs-toggle="dropdown">
                        <i class="fas fa-user-shield me-1"></i>
                        <?php if ($isSwitched): ?>
                            <span class="badge bg-warning text-dark">
                                Đang xem: <?= ucfirst($currentRole) ?>
                            </span>
                        <?php else: ?>
                            <span class="badge bg-danger">Admin Mode</span>
                        <?php endif; ?>
                    </a>
                    <ul class="dropdown-menu dropdown-menu-end dropdown-menu-dark">
                        <?php if ($isSwitched): ?>
                            <li class="dropdown-item-text">
                                <small class="text-warning">
                                    <i class="fas fa-eye me-1"></i>
                                    Đang xem giao diện: <strong><?= ucfirst($currentRole) ?></strong>
                                </small>
                            </li>
                            <li><hr class="dropdown-divider"></li>
                            <li>
                                <a class="dropdown-item" href="#" onclick="switchBackToAdmin(); return false;">
                                    <i class="fas fa-undo me-2"></i>Quay Lại Admin Mode
                                </a>
                            </li>
                        <?php else: ?>
                            <li class="dropdown-item-text">
                                <small><strong>Chuyển sang xem giao diện:</strong></small>
                            </li>
                            <li><hr class="dropdown-divider"></li>
                            <li>
                                <a class="dropdown-item" href="#" onclick="switchRole('user'); return false;">
                                    <i class="fas fa-user me-2"></i>User View
                                </a>
                            </li>
                            <li>
                                <a class="dropdown-item" href="#" onclick="switchRole('viewer'); return false;">
                                    <i class="fas fa-eye me-2"></i>Viewer View
                                </a>
                            </li>
                        <?php endif; ?>
                    </ul>
                </li>
                <?php endif; ?>

                <!-- User Menu -->
                <li class="nav-item dropdown">
                    <a class="nav-link dropdown-toggle" href="#" role="button" data-bs-toggle="dropdown">
                        <i class="fas fa-user-circle me-1"></i>
                        <strong><?= htmlspecialchars($currentUser['full_name']) ?></strong>
                    </a>
                    <ul class="dropdown-menu dropdown-menu-end dropdown-menu-dark">
                        <li class="dropdown-item-text">
                            <div>
                                <strong><?= htmlspecialchars($currentUser['full_name']) ?></strong>
                            </div>
                            <small class="text-muted">@<?= htmlspecialchars($currentUser['username']) ?></small>
                            <div class="mt-1">
                                <span class="badge <?= $currentRole === 'admin' ? 'bg-danger' : ($currentRole === 'user' ? 'bg-success' : 'bg-secondary') ?>">
                                    <?= ucfirst($currentRole) ?>
                                </span>
                            </div>
                        </li>
                        <li><hr class="dropdown-divider"></li>
                        <li>
                            <a class="dropdown-item" href="profile.php">
                                <i class="fas fa-user-edit me-2"></i>Thông Tin Cá Nhân
                            </a>
                        </li>
                        <li>
                            <a class="dropdown-item" href="change_password.php">
                                <i class="fas fa-key me-2"></i>Đổi Mật Khẩu
                            </a>
                        </li>
                        <li><hr class="dropdown-divider"></li>
                        <li>
                            <a class="dropdown-item text-danger" href="logout.php">
                                <i class="fas fa-sign-out-alt me-2"></i>Đăng Xuất
                            </a>
                        </li>
                    </ul>
                </li>
            </ul>
        </div>
    </div>
</nav>

<style>
.navbar-custom {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    padding: 0.75rem 1rem;
}

.navbar-custom .nav-link {
    color: rgba(255,255,255,0.9);
    font-weight: 500;
    transition: all 0.3s ease;
    border-radius: 8px;
    margin: 0 3px;
    padding: 8px 15px;
}

.navbar-custom .nav-link:hover {
    background: rgba(255,255,255,0.15);
    color: white;
    transform: translateY(-1px);
}

.navbar-custom .nav-link.active {
    background: rgba(255,255,255,0.25);
    color: white;
    font-weight: 600;
    box-shadow: 0 2px 8px rgba(0,0,0,0.2);
}

.navbar-custom .dropdown-menu {
    border-radius: 12px;
    box-shadow: 0 8px 24px rgba(0,0,0,0.2);
    border: none;
    margin-top: 8px;
}

.navbar-custom .dropdown-item {
    padding: 10px 20px;
    transition: all 0.2s;
    border-radius: 8px;
    margin: 2px 8px;
}

.navbar-custom .dropdown-item:hover {
    background: rgba(102, 126, 234, 0.2);
    padding-left: 25px;
    transform: translateX(3px);
}

.navbar-custom .dropdown-item.active {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
    font-weight: 600;
}
</style>

<script>
// Role switching functions
function switchRole(role) {
    if (!confirm(`Bạn muốn xem giao diện dưới quyền "${role}"?`)) return;
    
    fetch('/ajax/role_switch.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: `action=switch&role=${role}`
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            alert(data.message);
            location.reload();
        } else {
            alert('Lỗi: ' + data.error);
        }
    });
}

function switchBackToAdmin() {
    if (!confirm('Quay lại chế độ Admin?')) return;
    
    fetch('/ajax/role_switch.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: 'action=back'
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            alert(data.message);
            location.reload();
        } else {
            alert('Lỗi: ' + data.error);
        }
    });
}
</script>

<?php
}
?>