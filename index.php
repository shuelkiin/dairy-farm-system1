
<?php
require_once __DIR__ . '/includes/auth.php';

if (empty($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

goToDashboard();
