<?php
/**
 * ============================================
 * ANOMALY DETECTION - ENTRY POINT
 * ============================================
 * Features:
 * - Session management
 * - Authentication check
 * - Role-based authorization
 * - Cache clearing
 */

// Clear opcache if available
if (function_exists('opcache_reset')) {
    opcache_reset();
}

// Start session
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// ✅ AUTHENTICATION: Require login
require_once __DIR__ . '/middleware/AuthMiddleware.php';

try {
    AuthMiddleware::requireLogin();
} catch (Exception $e) {
    $_SESSION['error'] = 'Vui lòng đăng nhập để truy cập trang này.';
    $_SESSION['redirect_after_login'] = $_SERVER['REQUEST_URI'];
    header('Location: login.php');
    exit;
}

// ✅ AUTHORIZATION: Check permission for anomaly detection
if (!AuthMiddleware::checkPermission('view_anomaly')) {
    $_SESSION['error'] = 'Bạn không có quyền truy cập chức năng Phát hiện Bất thường.';
    header('Location: dashboard.php');
    exit;
}

// Load controller
require_once __DIR__ . '/controllers/AnomalyController.php';

// Get action
$action = $_GET['action'] ?? 'index';

// ✅ Check export permission
if ($action === 'export') {
    // Viewers cannot export
    if (AuthMiddleware::getCurrentRole() === 'viewer') {
        $_SESSION['error'] = 'Tài khoản Viewer không có quyền export dữ liệu. Vui lòng liên hệ Admin.';
        header('Location: anomaly.php');
        exit;
    }
    
    // Log export action
    $currentUser = AuthMiddleware::getCurrentUser();
    error_log("Anomaly Export by user: {$currentUser['username']} ({$currentUser['role']})");
}

// Initialize controller
$controller = new AnomalyController();

// Route to action
try {
    if ($action === 'export') {
        $controller->exportCSV();
    } else {
        $controller->index();
    }
} catch (Exception $e) {
    $_SESSION['error'] = 'Lỗi: ' . $e->getMessage();
    error_log("Anomaly Detection Error: " . $e->getMessage());
    
    // Redirect back to index if export fails
    if ($action === 'export') {
        header('Location: anomaly.php');
        exit;
    }
}
?>