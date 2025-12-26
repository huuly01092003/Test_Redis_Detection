<?php
$currentPage = 'users';
require_once __DIR__ . '/../components/navbar.php';
renderNavbar($currentPage);
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Quản Lý Người Dùng</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap5.min.css" rel="stylesheet">
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
        .filter-card, .data-card {
            background: white;
            border-radius: 15px;
            box-shadow: 0 5px 20px rgba(0,0,0,0.05);
            padding: 25px;
            margin-bottom: 25px;
        }
        .table thead {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
        }
        .badge-admin { background: #dc3545; }
        .badge-user { background: #28a745; }
        .badge-viewer { background: #6c757d; }
        .btn-action {
            padding: 5px 10px;
            font-size: 0.85rem;
            margin: 2px;
        }
    </style>
</head>
<body>
    <div class="container-fluid mt-4">
        <!-- Page Header -->
        <div class="page-header">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <h2><i class="fas fa-users-cog me-2"></i>Quản Lý Người Dùng</h2>
                    <p class="mb-0">Quản lý tài khoản và phân quyền người dùng</p>
                </div>
                <a href="users.php?action=create" class="btn btn-light btn-lg">
                    <i class="fas fa-plus me-2"></i>Thêm Người Dùng
                </a>
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

        <!-- Filters -->
        <div class="filter-card">
            <h5 class="mb-4"><i class="fas fa-filter me-2"></i>Bộ Lọc</h5>
            <form method="GET" action="users.php">
                <div class="row g-3">
                    <div class="col-md-3">
                        <label class="form-label fw-bold">Vai Trò</label>
                        <select name="role" class="form-select">
                            <option value="">-- Tất cả --</option>
                            <option value="admin" <?= ($filters['role'] === 'admin') ? 'selected' : '' ?>>Admin</option>
                            <option value="user" <?= ($filters['role'] === 'user') ? 'selected' : '' ?>>User</option>
                            <option value="viewer" <?= ($filters['role'] === 'viewer') ? 'selected' : '' ?>>Viewer</option>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label fw-bold">Trạng Thái</label>
                        <select name="is_active" class="form-select">
                            <option value="">-- Tất cả --</option>
                            <option value="1" <?= ($filters['is_active'] === 1) ? 'selected' : '' ?>>Đang hoạt động</option>
                            <option value="0" <?= ($filters['is_active'] === 0) ? 'selected' : '' ?>>Đã khóa</option>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label fw-bold">Tìm Kiếm</label>
                        <input type="text" name="search" class="form-control" 
                               placeholder="Tên đăng nhập, họ tên, email..." 
                               value="<?= htmlspecialchars($filters['search']) ?>">
                    </div>
                    <div class="col-md-2 d-flex align-items-end">
                        <button type="submit" class="btn btn-primary w-100">
                            <i class="fas fa-search me-2"></i>Lọc
                        </button>
                    </div>
                </div>
            </form>
        </div>

        <!-- User Table -->
        <div class="data-card">
            <h5 class="mb-4">
                <i class="fas fa-list me-2"></i>Danh Sách Người Dùng
                <span class="badge bg-secondary"><?= count($users) ?></span>
            </h5>
            
            <div class="table-responsive">
                <table id="usersTable" class="table table-hover">
                    <thead>
                        <tr>
                            <th style="width: 50px;">ID</th>
                            <th style="width: 150px;">Tên Đăng Nhập</th>
                            <th>Họ Tên</th>
                            <th>Email</th>
                            <th style="width: 100px; text-align: center;">Vai Trò</th>
                            <th style="width: 120px; text-align: center;">Trạng Thái</th>
                            <th style="width: 150px;">Đăng Nhập Lần Cuối</th>
                            <th style="width: 200px; text-align: center;">Thao Tác</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($users as $user): ?>
                            <tr>
                                <td><?= $user['id'] ?></td>
                                <td><strong><?= htmlspecialchars($user['username']) ?></strong></td>
                                <td><?= htmlspecialchars($user['full_name']) ?></td>
                                <td><?= htmlspecialchars($user['email'] ?? '-') ?></td>
                                <td class="text-center">
                                    <?php 
                                    $roleClass = 'badge-' . $user['role'];
                                    $roleText = [
                                        'admin' => 'Admin',
                                        'user' => 'User',
                                        'viewer' => 'Viewer'
                                    ][$user['role']] ?? $user['role'];
                                    ?>
                                    <span class="badge <?= $roleClass ?>"><?= $roleText ?></span>
                                </td>
                                <td class="text-center">
                                    <?php if ($user['is_active']): ?>
                                        <span class="badge bg-success">
                                            <i class="fas fa-check-circle"></i> Hoạt động
                                        </span>
                                    <?php else: ?>
                                        <span class="badge bg-danger">
                                            <i class="fas fa-lock"></i> Đã khóa
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($user['last_login']): ?>
                                        <small><?= date('d/m/Y H:i', strtotime($user['last_login'])) ?></small>
                                    <?php else: ?>
                                        <small class="text-muted">Chưa đăng nhập</small>
                                    <?php endif; ?>
                                </td>
                                <td class="text-center">
                                    <a href="users.php?action=edit&id=<?= $user['id'] ?>" 
                                       class="btn btn-sm btn-primary btn-action">
                                        <i class="fas fa-edit"></i> Sửa
                                    </a>
                                    
                                    <?php if ($user['id'] != $_SESSION['user_id']): ?>
                                        <button onclick="toggleStatus(<?= $user['id'] ?>, <?= $user['is_active'] ? 0 : 1 ?>)" 
                                                class="btn btn-sm btn-<?= $user['is_active'] ? 'warning' : 'success' ?> btn-action">
                                            <i class="fas fa-<?= $user['is_active'] ? 'lock' : 'unlock' ?>"></i>
                                            <?= $user['is_active'] ? 'Khóa' : 'Mở' ?>
                                        </button>
                                        
                                        <button onclick="confirmDelete(<?= $user['id'] ?>, '<?= htmlspecialchars($user['username']) ?>')" 
                                                class="btn btn-sm btn-danger btn-action">
                                            <i class="fas fa-trash"></i> Xóa
                                        </button>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap5.min.js"></script>
    <script>
        $(document).ready(function() {
            $('#usersTable').DataTable({
                language: {
                    url: '//cdn.datatables.net/plug-ins/1.13.6/i18n/vi.json'
                },
                pageLength: 25,
                order: [[0, 'desc']]
            });
        });

        function toggleStatus(userId, status) {
            const action = status ? 'kích hoạt' : 'khóa';
            if (!confirm(`Bạn có chắc muốn ${action} tài khoản này?`)) return;

            $.post('users.php?action=toggle_status', {
                user_id: userId,
                status: status
            }, function(response) {
                if (response.success) {
                    alert(response.message);
                    location.reload();
                } else {
                    alert('Lỗi: ' + response.error);
                }
            }, 'json');
        }

        function confirmDelete(userId, username) {
            if (!confirm(`Bạn có chắc muốn xóa tài khoản "${username}"?\n\nHành động này không thể hoàn tác!`)) return;

            $.post('users.php?action=delete', {
                user_id: userId
            }, function(response) {
                if (response.success) {
                    alert(response.message);
                    location.reload();
                } else {
                    alert('Lỗi: ' + response.error);
                }
            }, 'json');
        }
    </script>
</body>
</html>