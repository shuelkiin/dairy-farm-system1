
<?php
require_once __DIR__ . '/includes/auth.php';
requireRole(['farm_manager']);

$pageTitle = 'Farm Manager Dashboard';
require __DIR__ . '/includes/dashboard.php';
