<?php
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

require_once __DIR__ . '/includes/auth.php';

if (empty($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

goToDashboard();
