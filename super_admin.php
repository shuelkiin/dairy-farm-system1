
<?php
require_once __DIR__ . '/includes/auth.php';
requireRole(['super_admin']);

$pageTitle = 'Super Admin Dashboard';
require __DIR__ . '/includes/dashboard.php';
