<?php
session_start();

require_once 'middleware/AuthMiddleware.php';
require_once 'controllers/ReportController.php';

// Require login
AuthMiddleware::requireLogin();

// Redirect to report page (new homepage)
header('Location: report.php');
exit;
?>