<?php

declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

require_once __DIR__ . '/../config/database.php';

function requireLogin(): void
{
    if (empty($_SESSION['user_id'])) {
        header('Location: login.php');
        exit;
    }
}

function requireRole(array $roles): void
{
    requireLogin();

    if (!in_array($_SESSION['role_name'] ?? '', $roles, true)) {
        http_response_code(403);
        exit('Access denied. Your account cannot access this page.');
    }
}

function e(?string $value): string
{
    return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
}

function goToDashboard(): never
{
    $dashboards = [
        'super_admin' => 'super_admin.php',
        'admin' => 'admin.php',
        'farm_manager' => 'farm_manager.php',
        'store_manager' => 'store_manager.php'
    ];

    $role = $_SESSION['role_name'] ?? '';

    if (!isset($dashboards[$role])) {
        session_destroy();
        header('Location: login.php');
        exit;
    }

    header('Location: ' . $dashboards[$role]);
    exit;
}

function getFarmScope(string $column = 'farm_id'): array
{
    if (($_SESSION['role_name'] ?? '') === 'super_admin') {
        return ['condition' => '1=1', 'params' => []];
    }

    $farmId = $_SESSION['farm_id'] ?? null;

    if (!$farmId) {
        return ['condition' => '1=0', 'params' => []];
    }

    return [
        'condition' => "$column = :farm_id",
        'params' => ['farm_id' => (int)$farmId]
    ];
}
