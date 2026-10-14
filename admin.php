
<?php
require_once __DIR__ . '/includes/auth.php';
requireRole(['admin']);

$pageTitle = 'Admin Dashboard';
require __DIR__ . '/includes/dashboard.php';
