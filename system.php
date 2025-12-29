<?php
/**
 * ============================================
 * SYSTEM MANAGEMENT ROUTER
 * ============================================
 * Handles all system management operations
 */

session_start();

require_once 'middleware/AuthMiddleware.php';
require_once 'controllers/SystemManagementController.php';

// Require admin access
try {
    AuthMiddleware::requireAdmin();
} catch (Exception $e) {
    if (isset($_GET['action']) || isset($_POST['action'])) {
        // AJAX request
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false,
            'error' => 'Unauthorized access'
        ]);
    } else {
        // Normal request
        $_SESSION['error'] = 'Bạn không có quyền truy cập chức năng này';
        header('Location: dashboard.php');
    }
    exit;
}

$controller = new SystemManagementController();
$action = $_GET['action'] ?? $_POST['action'] ?? 'index';

switch ($action) {
    // Table operations
    case 'clear_table_all':
        $controller->clearTableAll();
        break;
        
    case 'clear_table_by_date':
        $controller->clearTableByDate();
        break;
    
    // Redis operations
    case 'clear_redis_all':
        $controller->clearRedisAll();
        break;
        
    case 'clear_redis_pattern':
        $controller->clearRedisByPattern();
        break;
        
    case 'get_redis_keys':
        $controller->getRedisKeys();
        break;
    
    // Default: show management interface
    default:
        $controller->index();
        break;
}
?>