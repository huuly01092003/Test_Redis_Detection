<?php
/**
 * ============================================
 * ROLE SWITCH AJAX HANDLER
 * ============================================
 */

session_start();
header('Content-Type: application/json');

require_once __DIR__ . '/../middleware/AuthMiddleware.php';

// Require admin
try {
    AuthMiddleware::requireAdmin();
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'error' => 'Unauthorized'
    ]);
    exit;
}

$action = $_POST['action'] ?? '';

if ($action === 'switch') {
    $targetRole = $_POST['role'] ?? '';
    
    $allowedRoles = ['admin', 'user', 'viewer'];
    if (!in_array($targetRole, $allowedRoles)) {
        echo json_encode([
            'success' => false,
            'error' => 'Invalid role'
        ]);
        exit;
    }
    
    if (AuthMiddleware::switchRole($targetRole)) {
        echo json_encode([
            'success' => true,
            'message' => "Đã chuyển sang quyền {$targetRole}",
            'current_role' => $targetRole,
            'is_switched' => true
        ]);
    } else {
        echo json_encode([
            'success' => false,
            'error' => 'Cannot switch role'
        ]);
    }
    
} elseif ($action === 'back') {
    
    if (AuthMiddleware::switchBackToAdmin()) {
        echo json_encode([
            'success' => true,
            'message' => 'Đã quay lại quyền Admin',
            'current_role' => 'admin',
            'is_switched' => false
        ]);
    } else {
        echo json_encode([
            'success' => false,
            'error' => 'Cannot switch back'
        ]);
    }
    
} else {
    echo json_encode([
        'success' => false,
        'error' => 'Invalid action'
    ]);
}
?>