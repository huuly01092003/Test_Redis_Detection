<?php
/**
 * ============================================
 * USER MANAGEMENT ROUTER
 * ============================================
 */

session_start();

require_once 'middleware/AuthMiddleware.php';
require_once 'controllers/UserController.php';

// Require admin access
AuthMiddleware::requireAdmin();

$controller = new UserController();
$action = $_GET['action'] ?? 'index';

switch ($action) {
    case 'create':
        $controller->create();
        break;
        
    case 'store':
        $controller->store();
        break;
        
    case 'edit':
        $controller->edit();
        break;
        
    case 'update':
        $controller->update();
        break;
        
    case 'delete':
        $controller->delete();
        break;
        
    case 'toggle_status':
        $controller->toggleStatus();
        break;
        
    case 'reset_password':
        $controller->resetPassword();
        break;
        
    default:
        $controller->index();
        break;
}
?>