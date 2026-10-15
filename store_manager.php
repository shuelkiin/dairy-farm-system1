
<?php
require_once __DIR__ . '/includes/auth.php';
requireRole(['store_manager']);

$pageTitle = 'Store Manager Dashboard';
require __DIR__ . '/includes/dashboard.php';
