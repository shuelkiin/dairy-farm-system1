<?php

declare(strict_types=1);

session_start();

require_once __DIR__ . '/config/database.php';

if (empty($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

/*
|--------------------------------------------------------------------------
| Database compatibility: PDO or MySQLi
|--------------------------------------------------------------------------
*/

$db = $pdo ?? $conn ?? $mysqli ?? null;

if (!$db instanceof PDO && !$db instanceof mysqli) {
    die('Database connection not found. Check config/database.php.');
}

function dbAll(string $sql, array $params = []): array
{
    global $db;

    if ($db instanceof PDO) {
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    $stmt = $db->prepare($sql);

    if (!$stmt) {
        throw new RuntimeException('Database query could not be prepared.');
    }

    if ($params) {
        $types = str_repeat('s', count($params));
        $refs = [];

        foreach ($params as $key => $value) {
            $refs[$key] = &$params[$key];
        }

        $stmt->bind_param($types, ...$refs);
    }

    $stmt->execute();
    $result = $stmt->get_result();
    $rows = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    return $rows;
}

function dbExecute(string $sql, array $params = []): int
{
    global $db;

    if ($db instanceof PDO) {
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        return $stmt->rowCount();
    }

    $stmt = $db->prepare($sql);

    if (!$stmt) {
        throw new RuntimeException('Database query could not be prepared.');
    }

    if ($params) {
        $types = str_repeat('s', count($params));
        $refs = [];

        foreach ($params as $key => $value) {
            $refs[$key] = &$params[$key];
        }

        $stmt->bind_param($types, ...$refs);
    }

    $stmt->execute();
    $affected = $stmt->affected_rows;
    $stmt->close();

    return $affected;
}

function e(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function beginTransaction(): void
{
    global $db;

    if ($db instanceof PDO) {
        $db->beginTransaction();
    } else {
        $db->begin_transaction();
    }
}

function commitTransaction(): void
{
    global $db;

    if ($db instanceof PDO) {
        $db->commit();
    } else {
        $db->commit();
    }
}

function rollbackTransaction(): void
{
    global $db;

    try {
        if ($db instanceof PDO) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
        } else {
            $db->rollback();
        }
    } catch (Throwable $ignored) {
        // Preserve the original error.
    }
}

/*
|--------------------------------------------------------------------------
| User and permissions
|--------------------------------------------------------------------------
*/

$userId = (int) $_SESSION['user_id'];

$userRows = dbAll(
    "SELECT u.user_id, u.full_name, u.farm_id, r.role_name
     FROM users u
     JOIN roles r ON r.role_id = u.role_id
     WHERE u.user_id = ?
     LIMIT 1",
    [$userId]
);

if (!$userRows) {
    session_destroy();
    header('Location: login.php');
    exit;
}

$currentUser = $userRows[0];
$role = $currentUser['role_name'];
$userFarmId = $currentUser['farm_id'] !== null
    ? (int) $currentUser['farm_id']
    : null;

$allowedRoles = ['super_admin', 'admin', 'store_manager', 'farm_manager'];

if (!in_array($role, $allowedRoles, true)) {
    http_response_code(403);
    exit('You do not have permission to access feed inventory.');
}

$isSuperAdmin = $role === 'super_admin';

if (!$isSuperAdmin && !$userFarmId) {
    exit('Your account is not assigned to a farm. Ask an administrator to assign one.');
}

/*
|--------------------------------------------------------------------------
| CSRF protection
|--------------------------------------------------------------------------
*/

if (empty($_SESSION['feed_csrf'])) {
    $_SESSION['feed_csrf'] = bin2hex(random_bytes(32));
}

$csrfToken = $_SESSION['feed_csrf'];

$message = '';
$messageType = 'success';

$selectedFarmId = $isSuperAdmin
    ? (int) ($_GET['farm_id'] ?? $_POST['farm_id'] ?? 0)
    : (int) $userFarmId;

/*
|--------------------------------------------------------------------------
| Farms and feed types
|--------------------------------------------------------------------------
*/

if ($isSuperAdmin) {
    $farms = dbAll(
        "SELECT farm_id, farm_name
         FROM farms
         ORDER BY farm_name"
    );
} else {
    $farms = dbAll(
        "SELECT farm_id, farm_name
         FROM farms
         WHERE farm_id = ?
         LIMIT 1",
        [$userFarmId]
    );
}

$validFarmIds = array_map(
    static fn($farm) => (int) $farm['farm_id'],
    $farms
);

if (
    $isSuperAdmin && $selectedFarmId !== 0
    && !in_array($selectedFarmId, $validFarmIds, true)
) {
    $selectedFarmId = 0;
}

$feedTypes = dbAll(
    "SELECT feed_id, feed_name, unit, description
     FROM feed_types
     ORDER BY feed_name"
);

/*
|--------------------------------------------------------------------------
| Process forms
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $submittedToken = (string) ($_POST['csrf_token'] ?? '');

        if (!hash_equals($csrfToken, $submittedToken)) {
            throw new RuntimeException('Your session token expired. Refresh and try again.');
        }

        $action = (string) ($_POST['action'] ?? '');

        /*
        | Add a feed type
        */

        if ($action === 'add_feed') {
            if (!in_array($role, ['super_admin', 'admin', 'store_manager'], true)) {
                throw new RuntimeException('You cannot create feed types.');
            }

            $feedName = trim((string) ($_POST['feed_name'] ?? ''));
            $unit = trim((string) ($_POST['unit'] ?? 'kg'));
            $description = trim((string) ($_POST['description'] ?? ''));

            if ($feedName === '' || mb_strlen($feedName) > 120) {
                throw new RuntimeException('Enter a feed name of up to 120 characters.');
            }

            if ($unit === '' || mb_strlen($unit) > 30) {
                throw new RuntimeException('Enter a valid measurement unit.');
            }

            dbExecute(
                "INSERT INTO feed_types (feed_name, unit, description)
                 VALUES (?, ?, ?)",
                [$feedName, $unit, $description]
            );

            $message = 'Feed type added successfully.';
        }

        /*
        | Record a stock movement
        */ elseif ($action === 'record_movement') {
            if (!in_array($role, ['super_admin', 'admin', 'store_manager'], true)) {
                throw new RuntimeException('Only store managers and administrators can change feed stock.');
            }

            $farmId = $isSuperAdmin
                ? (int) ($_POST['farm_id'] ?? 0)
                : (int) $userFarmId;

            $feedId = (int) ($_POST['feed_id'] ?? 0);
            $movementType = (string) ($_POST['movement_type'] ?? '');
            $quantity = filter_var(
                $_POST['quantity'] ?? null,
                FILTER_VALIDATE_FLOAT
            );
            $reorderLevel = filter_var(
                $_POST['reorder_level'] ?? 0,
                FILTER_VALIDATE_FLOAT
            );
            $direction = (string) ($_POST['adjustment_direction'] ?? 'increase');
            $notes = trim((string) ($_POST['notes'] ?? ''));

            if (!in_array($farmId, $validFarmIds, true)) {
                throw new RuntimeException('Select a valid farm.');
            }

            if ($feedId <= 0) {
                throw new RuntimeException('Select a feed type.');
            }

            if (!in_array($movementType, ['purchase', 'usage', 'adjustment'], true)) {
                throw new RuntimeException('Select a valid stock movement.');
            }

            if ($quantity === false || $quantity <= 0 || !is_finite((float) $quantity)) {
                throw new RuntimeException('Quantity must be greater than zero.');
            }

            if (
                $reorderLevel === false || $reorderLevel < 0
                || !is_finite((float) $reorderLevel)
            ) {
                throw new RuntimeException('Reorder level cannot be negative.');
            }

            if (mb_strlen($notes) > 5000) {
                throw new RuntimeException('Notes are too long.');
            }

            if (!in_array($feedId, array_map(
                static fn($feed) => (int) $feed['feed_id'],
                $feedTypes
            ), true)) {
                throw new RuntimeException('That feed type does not exist.');
            }

            if (
                $movementType === 'adjustment'
                && !in_array($direction, ['increase', 'decrease'], true)
            ) {
                throw new RuntimeException('Choose an adjustment direction.');
            }

            $change = (float) $quantity;

            if (
                $movementType === 'usage' ||
                ($movementType === 'adjustment' && $direction === 'decrease')
            ) {
                $change = -$change;
            }

            beginTransaction();

            /*
             * The unique key on (farm_id, feed_id) lets us safely
             * create the stock row when the feed is first used.
             */
            dbExecute(
                "INSERT INTO feed_stock
                    (farm_id, feed_id, quantity, reorder_level)
                 VALUES (?, ?, 0, ?)
                 ON DUPLICATE KEY UPDATE
                    reorder_level = VALUES(reorder_level)",
                [$farmId, $feedId, $reorderLevel]
            );

            /*
             * Do not allow stock to fall below zero.
             */
            if ($change < 0) {
                $affected = dbExecute(
                    "UPDATE feed_stock
                     SET quantity = quantity + ?
                     WHERE farm_id = ?
                       AND feed_id = ?
                       AND quantity + ? >= 0",
                    [$change, $farmId, $feedId, $change]
                );

                if ($affected !== 1) {
                    throw new RuntimeException(
                        'Insufficient feed stock, or the stock update failed.'
                    );
                }
            } else {
                dbExecute(
                    "UPDATE feed_stock
                     SET quantity = quantity + ?
                     WHERE farm_id = ? AND feed_id = ?",
                    [$change, $farmId, $feedId]
                );
            }

            /*
             * Save the audit trail. Adjustment quantities are signed:
             * positive increases stock; negative decreases stock.
             */
            dbExecute(
                "INSERT INTO feed_movements
                    (farm_id, feed_id, recorded_by, movement_type,
                     quantity, movement_date, notes)
                 VALUES (?, ?, ?, ?, ?, NOW(), ?)",
                [
                    $farmId,
                    $feedId,
                    $userId,
                    $movementType,
                    $change,
                    $notes
                ]
            );

            commitTransaction();

            $selectedFarmId = $farmId;
            $message = 'Feed inventory updated successfully.';
        } else {
            throw new RuntimeException('Unknown action.');
        }

        /*
         * Refresh feed types after adding one.
         */
        $feedTypes = dbAll(
            "SELECT feed_id, feed_name, unit, description
             FROM feed_types
             ORDER BY feed_name"
        );
    } catch (Throwable $exception) {
        rollbackTransaction();
        $message = $exception->getMessage();
        $messageType = 'error';
    }
}

/*
|--------------------------------------------------------------------------
| Inventory queries
|--------------------------------------------------------------------------
*/

$inventory = [];
$movements = [];
$stats = [
    'feed_count' => 0,
    'low_stock' => 0,
    'total_stock' => 0,
    'today_movements' => 0
];

if ($isSuperAdmin && $selectedFarmId === 0) {
    $inventory = dbAll(
        "SELECT fs.stock_id, fs.farm_id, fs.feed_id,
                fs.quantity, fs.reorder_level, fs.last_updated,
                f.farm_name, ft.feed_name, ft.unit,
                CASE
                    WHEN fs.quantity <= fs.reorder_level THEN 1
                    ELSE 0
                END AS is_low
         FROM feed_stock fs
         JOIN farms f ON f.farm_id = fs.farm_id
         JOIN feed_types ft ON ft.feed_id = fs.feed_id
         ORDER BY f.farm_name, ft.feed_name"
    );

    $movements = dbAll(
        "SELECT fm.movement_id, fm.movement_type, fm.quantity,
                fm.movement_date, fm.notes,
                f.farm_name, ft.feed_name, ft.unit,
                u.full_name AS recorded_by_name
         FROM feed_movements fm
         JOIN farms f ON f.farm_id = fm.farm_id
         JOIN feed_types ft ON ft.feed_id = fm.feed_id
         LEFT JOIN users u ON u.user_id = fm.recorded_by
         ORDER BY fm.movement_date DESC
         LIMIT 12"
    );

    $statRows = dbAll(
        "SELECT
            COUNT(*) AS feed_count,
            COALESCE(SUM(quantity <= reorder_level), 0) AS low_stock,
            COALESCE(SUM(quantity), 0) AS total_stock
         FROM feed_stock"
    );

    $todayRows = dbAll(
        "SELECT COUNT(*) AS total
         FROM feed_movements
         WHERE movement_date >= CURDATE()
           AND movement_date < CURDATE() + INTERVAL 1 DAY"
    );
} else {
    $farmId = $selectedFarmId ?: (int) $userFarmId;

    $inventory = dbAll(
        "SELECT fs.stock_id, fs.farm_id, fs.feed_id,
                fs.quantity, fs.reorder_level, fs.last_updated,
                f.farm_name, ft.feed_name, ft.unit,
                CASE
                    WHEN fs.quantity <= fs.reorder_level THEN 1
                    ELSE 0
                END AS is_low
         FROM feed_stock fs
         JOIN farms f ON f.farm_id = fs.farm_id
         JOIN feed_types ft ON ft.feed_id = fs.feed_id
         WHERE fs.farm_id = ?
         ORDER BY ft.feed_name",
        [$farmId]
    );

    $movements = dbAll(
        "SELECT fm.movement_id, fm.movement_type, fm.quantity,
                fm.movement_date, fm.notes,
                f.farm_name, ft.feed_name, ft.unit,
                u.full_name AS recorded_by_name
         FROM feed_movements fm
         JOIN farms f ON f.farm_id = fm.farm_id
         JOIN feed_types ft ON ft.feed_id = fm.feed_id
         LEFT JOIN users u ON u.user_id = fm.recorded_by
         WHERE fm.farm_id = ?
         ORDER BY fm.movement_date DESC
         LIMIT 12",
        [$farmId]
    );

    $statRows = dbAll(
        "SELECT
            COUNT(*) AS feed_count,
            COALESCE(SUM(quantity <= reorder_level), 0) AS low_stock,
            COALESCE(SUM(quantity), 0) AS total_stock
         FROM feed_stock
         WHERE farm_id = ?",
        [$farmId]
    );

    $todayRows = dbAll(
        "SELECT COUNT(*) AS total
         FROM feed_movements
         WHERE farm_id = ?
           AND movement_date >= CURDATE()
           AND movement_date < CURDATE() + INTERVAL 1 DAY",
        [$farmId]
    );
}

if ($statRows) {
    $stats = array_merge($stats, $statRows[0]);
}

$stats['today_movements'] = (int) ($todayRows[0]['total'] ?? 0);

/*
|--------------------------------------------------------------------------
| Navigation
|--------------------------------------------------------------------------
*/

$initials = strtoupper(substr(
    trim((string) $currentUser['full_name']),
    0,
    1
));

$roleLabel = ucwords(str_replace('_', ' ', $role));
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Feed Inventory | DairyFlow</title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&family=Manrope:wght@400;500;600;700;800&display=swap" rel="stylesheet">

    <style>
        :root {
            --navy: #102a25;
            --navy-light: #183b33;
            --green: #23865b;
            --green-dark: #176b46;
            --mint: #e9f7ef;
            --bg: #f5f8f6;
            --white: #ffffff;
            --text: #1c3029;
            --muted: #78867f;
            --border: #e5ece7;
            --red: #c34242;
            --amber: #b87913;
            --shadow: 0 8px 28px rgba(16, 42, 37, .055);
        }

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            background: var(--bg);
            color: var(--text);
            font-family: 'DM Sans', sans-serif;
        }

        button,
        input,
        select,
        textarea {
            font: inherit;
        }

        .layout {
            min-height: 100vh;
        }

        .sidebar {
            width: 248px;
            background: var(--navy);
            color: white;
            position: fixed;
            inset: 0 auto 0 0;
            padding: 27px 17px;
            display: flex;
            flex-direction: column;
            z-index: 5;
        }

        .brand {
            display: flex;
            align-items: center;
            gap: 11px;
            padding: 0 9px 29px;
        }

        .brand-icon {
            width: 43px;
            height: 43px;
            border-radius: 14px;
            display: grid;
            place-items: center;
            background: #36a66e;
            font-family: Manrope, sans-serif;
            font-size: 18px;
            font-weight: 800;
        }

        .brand-name {
            font-family: Manrope, sans-serif;
            font-size: 19px;
            font-weight: 800;
        }

        .brand-sub {
            font-size: 9px;
            letter-spacing: 1.5px;
            color: #a6c4b6;
            margin-top: 3px;
        }

        .nav-label {
            color: #87a99a;
            font-size: 10px;
            font-weight: 700;
            letter-spacing: 1.5px;
            padding: 13px 12px 9px;
        }

        .nav-link {
            color: #c3d5cd;
            text-decoration: none;
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 12px 13px;
            border-radius: 10px;
            margin: 3px 0;
            font-size: 13px;
            transition: .2s;
        }

        .nav-link:hover {
            background: var(--navy-light);
            color: white;
        }

        .nav-link.active {
            background: #226b4d;
            color: white;
            font-weight: 700;
        }

        .nav-icon {
            width: 20px;
            text-align: center;
            font-size: 16px;
        }

        .sidebar-bottom {
            margin-top: auto;
        }

        .user-mini {
            border-top: 1px solid #29463e;
            padding: 19px 7px 0;
            display: flex;
            gap: 10px;
            align-items: center;
        }

        .avatar {
            width: 37px;
            height: 37px;
            border-radius: 50%;
            background: #d6f0df;
            color: #176b46;
            display: grid;
            place-items: center;
            font-weight: 800;
        }

        .user-name {
            font-size: 12px;
            font-weight: 700;
        }

        .user-role {
            font-size: 10px;
            color: #a6c4b6;
            margin-top: 4px;
        }

        .main {
            margin-left: 248px;
            padding: 31px 36px 45px;
        }

        .topbar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 18px;
            margin-bottom: 27px;
        }

        .eyebrow {
            font-size: 10px;
            letter-spacing: 1.7px;
            color: var(--green);
            font-weight: 800;
            text-transform: uppercase;
            margin-bottom: 8px;
        }

        h1,
        h2,
        h3,
        p {
            margin-top: 0;
        }

        h1 {
            font: 800 29px Manrope, sans-serif;
            letter-spacing: -.8px;
            margin-bottom: 7px;
        }

        .subtitle {
            color: var(--muted);
            font-size: 13px;
            margin: 0;
        }

        .top-actions {
            display: flex;
            gap: 10px;
            align-items: center;
        }

        .farm-select,
        .field input,
        .field select,
        .field textarea,
        .search-input {
            width: 100%;
            border: 1px solid var(--border);
            border-radius: 9px;
            padding: 11px 12px;
            background: white;
            color: var(--text);
            outline: none;
            font-size: 12px;
        }

        .farm-select {
            min-width: 180px;
        }

        input:focus,
        select:focus,
        textarea:focus {
            border-color: #65b78b;
            box-shadow: 0 0 0 3px rgba(35, 134, 91, .08);
        }

        .btn {
            border: 0;
            border-radius: 9px;
            padding: 11px 15px;
            background: var(--green);
            color: white;
            font-weight: 700;
            font-size: 12px;
            cursor: pointer;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 7px;
            transition: .2s;
        }

        .btn:hover {
            background: var(--green-dark);
        }

        .btn.secondary {
            background: white;
            border: 1px solid var(--border);
            color: var(--text);
        }

        .btn.secondary:hover {
            background: var(--mint);
        }

        .welcome-banner {
            background: linear-gradient(110deg, #12392e, #21724d);
            color: white;
            padding: 24px 27px;
            border-radius: 16px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 20px;
            margin-bottom: 23px;
            overflow: hidden;
            position: relative;
        }

        .welcome-banner:after {
            content: '🌾';
            position: absolute;
            right: 24%;
            top: -27px;
            font-size: 112px;
            opacity: .12;
            transform: rotate(-18deg);
        }

        .banner-label {
            color: #aee2c2;
            font-size: 10px;
            font-weight: 800;
            letter-spacing: 1.5px;
            text-transform: uppercase;
            margin-bottom: 8px;
        }

        .welcome-banner h2 {
            font: 800 21px Manrope, sans-serif;
            margin-bottom: 7px;
        }

        .welcome-banner p {
            margin: 0;
            color: #d0e8db;
            font-size: 12px;
        }

        .banner-symbol {
            font-size: 42px;
            position: relative;
            z-index: 1;
        }

        .stats {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 16px;
            margin-bottom: 24px;
        }

        .stat-card,
        .panel {
            background: white;
            border: 1px solid #edf1ee;
            border-radius: 14px;
            box-shadow: var(--shadow);
        }

        .stat-card {
            padding: 19px;
        }

        .stat-top {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 17px;
        }

        .stat-icon {
            width: 39px;
            height: 39px;
            border-radius: 11px;
            display: grid;
            place-items: center;
            background: var(--mint);
            font-size: 18px;
        }

        .stat-icon.warning {
            background: #fff3dc;
        }

        .stat-icon.blue {
            background: #e9f1ff;
        }

        .stat-icon.purple {
            background: #f1eaff;
        }

        .stat-label {
            color: var(--muted);
            font-size: 11px;
            font-weight: 600;
        }

        .stat-number {
            font: 800 26px Manrope, sans-serif;
            margin-top: 7px;
        }

        .stat-note {
            color: var(--muted);
            font-size: 10px;
            margin-top: 5px;
        }

        .content-grid {
            display: grid;
            grid-template-columns: minmax(0, 1.25fr) minmax(300px, .75fr);
            gap: 20px;
            align-items: start;
            margin-bottom: 22px;
        }

        .panel {
            padding: 22px;
            min-width: 0;
        }

        .panel-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 12px;
            margin-bottom: 20px;
        }

        .panel h3 {
            font: 800 15px Manrope, sans-serif;
            margin-bottom: 6px;
        }

        .panel-description {
            color: var(--muted);
            font-size: 11px;
            margin: 0;
        }

        .section-icon {
            width: 35px;
            height: 35px;
            border-radius: 10px;
            background: var(--mint);
            display: grid;
            place-items: center;
            flex-shrink: 0;
        }

        .field {
            margin-bottom: 14px;
        }

        .field label {
            display: block;
            color: #52645a;
            font-size: 11px;
            font-weight: 700;
            margin-bottom: 7px;
        }

        .field textarea {
            resize: vertical;
            min-height: 72px;
        }

        .field-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 12px;
        }

        .form-hint {
            font-size: 10px;
            color: var(--muted);
            line-height: 1.6;
            margin: -3px 0 15px;
        }

        .btn.full {
            width: 100%;
            padding: 13px;
        }

        .alert {
            padding: 13px 16px;
            border-radius: 10px;
            font-size: 12px;
            margin-bottom: 20px;
            line-height: 1.5;
        }

        .alert.success {
            color: #176b46;
            background: #e5f7ec;
            border: 1px solid #c6ead3;
        }

        .alert.error {
            color: #a22d2d;
            background: #fff0f0;
            border: 1px solid #f4cccc;
        }

        .inventory-panel {
            margin-bottom: 22px;
        }

        .table-toolbar {
            display: flex;
            gap: 10px;
            align-items: center;
            margin-bottom: 16px;
        }

        .search-input {
            max-width: 270px;
        }

        .table-wrap {
            width: 100%;
            overflow-x: auto;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            white-space: nowrap;
        }

        th {
            color: #7c8981;
            font-size: 10px;
            text-transform: uppercase;
            letter-spacing: .6px;
            text-align: left;
            padding: 12px 13px;
            background: #f7faf8;
            border-bottom: 1px solid var(--border);
        }

        td {
            padding: 14px 13px;
            font-size: 12px;
            border-bottom: 1px solid #edf1ee;
        }

        tbody tr:last-child td {
            border-bottom: 0;
        }

        tbody tr:hover {
            background: #fbfdfb;
        }

        .feed-name {
            font-weight: 700;
        }

        .feed-unit {
            font-size: 10px;
            color: var(--muted);
            margin-top: 4px;
        }

        .stock-pill {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 6px 9px;
            border-radius: 20px;
            font-size: 10px;
            font-weight: 800;
        }

        .stock-pill.ok {
            background: #e5f7ec;
            color: #176b46;
        }

        .stock-pill.low {
            background: #fff0dc;
            color: #9b6410;
        }

        .movement-pill {
            padding: 5px 8px;
            border-radius: 6px;
            font-size: 10px;
            font-weight: 800;
        }

        .movement-pill.purchase {
            background: #e5f7ec;
            color: #176b46;
        }

        .movement-pill.usage {
            background: #eaf1ff;
            color: #315ba8;
        }

        .movement-pill.adjustment {
            background: #fff0dc;
            color: #9b6410;
        }

        .empty {
            text-align: center;
            padding: 35px 15px;
            color: var(--muted);
            font-size: 12px;
        }

        .empty-icon {
            font-size: 30px;
            margin-bottom: 10px;
        }

        .history-meta {
            color: var(--muted);
            font-size: 10px;
            margin-top: 5px;
        }

        .amount-positive {
            color: #176b46;
            font-weight: 800;
        }

        .amount-negative {
            color: #b13c3c;
            font-weight: 800;
        }

        .footer {
            padding: 10px 0;
            text-align: center;
            font-size: 10px;
            color: #8b9991;
        }

        .mobile-menu {
            display: none;
        }

        @media (max-width: 1200px) {
            .stats {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }

            .content-grid {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 760px) {
            .sidebar {
                display: none;
            }

            .main {
                margin-left: 0;
                padding: 22px 15px 30px;
            }

            .topbar {
                align-items: flex-start;
                flex-direction: column;
            }

            .top-actions {
                width: 100%;
                flex-wrap: wrap;
            }

            .farm-select {
                min-width: 0;
                flex: 1;
            }

            h1 {
                font-size: 25px;
            }

            .welcome-banner {
                padding: 21px;
            }

            .banner-symbol {
                display: none;
            }

            .stats {
                gap: 10px;
            }

            .stat-card {
                padding: 14px;
            }

            .stat-number {
                font-size: 22px;
            }

            .panel {
                padding: 16px;
            }

            .field-grid {
                grid-template-columns: 1fr;
                gap: 0;
            }

            .table-toolbar {
                flex-direction: column;
                align-items: stretch;
            }

            .search-input {
                max-width: none;
            }
        }
    </style>
</head>

<body>
    <div class="layout">

        <aside class="sidebar">
            <div class="brand">
                <div class="brand-icon">DF</div>
                <div>
                    <div class="brand-name">DairyFlow</div>
                    <div class="brand-sub">FARM MANAGEMENT</div>
                </div>
            </div>

            <div class="nav-label">MAIN MENU</div>

            <a class="nav-link" href="index.php">
                <span class="nav-icon">▦</span> Dashboard
            </a>
            <a class="nav-link" href="farms.php">
                <span class="nav-icon">⌂</span> Farms
            </a>
            <a class="nav-link" href="cows.php">
                <span class="nav-icon">🐄</span> Cows
            </a>
            <a class="nav-link" href="milk.php">
                <span class="nav-icon">🥛</span> Milk Production
            </a>
            <a class="nav-link" href="health.php">
                <span class="nav-icon">✚</span> Health & Vaccines
            </a>
            <a class="nav-link" href="weights.php">
                <span class="nav-icon">⚖</span> Cow Weights
            </a>

            <div class="nav-label">INVENTORY</div>


            <?php if (in_array($role, ['super_admin', 'admin', 'store_manager'], true)): ?>
                <a class="nav-link active" href="feed_inventory.php">
                    <span class="nav-icon">🌾</span> Feed Inventory
                </a>
            <?php endif; ?>


            <div class="nav-label">MANAGEMENT</div>

            <a class="nav-link" href="users.php">
                <span class="nav-icon">♙</span> Users
            </a>

            <div class="sidebar-bottom">
                <a class="nav-link" href="logout.php">
                    <span class="nav-icon">↪</span> Sign Out
                </a>

                <div class="user-mini">
                    <div class="avatar"><?= e($initials) ?></div>
                    <div>
                        <div class="user-name"><?= e($currentUser['full_name']) ?></div>
                        <div class="user-role"><?= e($roleLabel) ?></div>
                    </div>
                </div>
            </div>
        </aside>

        <main class="main">

            <header class="topbar">
                <div>
                    <div class="eyebrow">Inventory Management</div>
                    <h1>Feed Inventory</h1>
                    <p class="subtitle">Keep feed stock healthy, organised and ready for your herd.</p>
                </div>

                <div class="top-actions">
                    <?php if ($isSuperAdmin && $farms): ?>
                        <form method="GET">
                            <select class="farm-select" name="farm_id" onchange="this.form.submit()">
                                <option value="0" <?= $selectedFarmId === 0 ? 'selected' : '' ?>>
                                    All farms
                                </option>
                                <?php foreach ($farms as $farm): ?>
                                    <option value="<?= (int) $farm['farm_id'] ?>"
                                        <?= $selectedFarmId === (int) $farm['farm_id'] ? 'selected' : '' ?>>
                                        <?= e($farm['farm_name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </form>
                    <?php elseif ($farms): ?>
                        <div class="farm-select">
                            Farm: <?= e($farms[0]['farm_name']) ?>
                        </div>
                    <?php endif; ?>
                </div>
            </header>

            <?php if ($message !== ''): ?>
                <div class="alert <?= $messageType === 'error' ? 'error' : 'success' ?>">
                    <?= e($message) ?>
                </div>
            <?php endif; ?>

            <section class="welcome-banner">
                <div>
                    <div class="banner-label">Feed store overview</div>
                    <h2>Every bag accounted for.</h2>
                    <p>Track purchases, daily feed usage and stock levels from one place.</p>
                </div>
                <div class="banner-symbol">🌾</div>
            </section>

            <section class="stats">
                <div class="stat-card">
                    <div class="stat-top">
                        <div class="stat-icon">🌿</div>
                    </div>
                    <div class="stat-label">Feed Stock Items</div>
                    <div class="stat-number"><?= number_format((int) $stats['feed_count']) ?></div>
                    <div class="stat-note">Tracked inventory entries</div>
                </div>

                <div class="stat-card">
                    <div class="stat-top">
                        <div class="stat-icon warning">⚠️</div>
                    </div>
                    <div class="stat-label">Low Stock Alerts</div>
                    <div class="stat-number"><?= number_format((int) $stats['low_stock']) ?></div>
                    <div class="stat-note">At or below reorder level</div>
                </div>

                <div class="stat-card">
                    <div class="stat-top">
                        <div class="stat-icon blue">📦</div>
                    </div>
                    <div class="stat-label">Total Stock Quantity</div>
                    <div class="stat-number"><?= number_format((float) $stats['total_stock'], 2) ?></div>
                    <div class="stat-note">Combined quantities, across units</div>
                </div>

                <div class="stat-card">
                    <div class="stat-top">
                        <div class="stat-icon purple">↻</div>
                    </div>
                    <div class="stat-label">Today's Movements</div>
                    <div class="stat-number"><?= number_format((int) $stats['today_movements']) ?></div>
                    <div class="stat-note">Purchases, usage and adjustments</div>
                </div>
            </section>

            <section class="content-grid">

                <div class="panel">
                    <div class="panel-header">
                        <div>
                            <h3>Record Stock Movement</h3>
                            <p class="panel-description">Record feed purchases, usage or stock corrections.</p>
                        </div>
                        <div class="section-icon">↻</div>
                    </div>

                    <?php if (in_array($role, ['super_admin', 'admin', 'store_manager'], true)): ?>
                        <form method="POST">
                            <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                            <input type="hidden" name="action" value="record_movement">

                            <?php if ($isSuperAdmin): ?>
                                <div class="field">
                                    <label for="movement_farm">Farm *</label>
                                    <select name="farm_id" id="movement_farm" required>
                                        <option value="">Select farm</option>
                                        <?php foreach ($farms as $farm): ?>
                                            <option value="<?= (int) $farm['farm_id'] ?>"
                                                <?= $selectedFarmId === (int) $farm['farm_id'] ? 'selected' : '' ?>>
                                                <?= e($farm['farm_name']) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            <?php else: ?>
                                <input type="hidden" name="farm_id" value="<?= (int) $userFarmId ?>">
                            <?php endif; ?>

                            <div class="field">
                                <label for="feed_id">Feed Type *</label>
                                <select name="feed_id" id="feed_id" required>
                                    <option value="">Choose feed type</option>
                                    <?php foreach ($feedTypes as $feed): ?>
                                        <option value="<?= (int) $feed['feed_id'] ?>">
                                            <?= e($feed['feed_name']) ?> (<?= e($feed['unit']) ?>)
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="field">
                                <label for="movement_type">Movement Type *</label>
                                <select name="movement_type" id="movement_type" required>
                                    <option value="purchase">Purchase / Stock Received</option>
                                    <option value="usage">Feed Used / Issued</option>
                                    <option value="adjustment">Stock Adjustment</option>
                                </select>
                            </div>

                            <div class="field-grid">
                                <div class="field">
                                    <label for="quantity" id="quantity_label">Quantity *</label>
                                    <input type="number" name="quantity" id="quantity"
                                        min="0.01" step="0.01" required placeholder="e.g. 50">
                                </div>

                                <div class="field">
                                    <label for="reorder_level">Low Stock Alert At</label>
                                    <input type="number" name="reorder_level" id="reorder_level"
                                        min="0" step="0.01" value="10" required>
                                </div>
                            </div>

                            <div class="field" id="adjustment_direction_field" hidden>
                                <label for="adjustment_direction">Adjustment Direction *</label>
                                <select name="adjustment_direction" id="adjustment_direction">
                                    <option value="increase">Increase stock</option>
                                    <option value="decrease">Decrease stock</option>
                                </select>
                            </div>

                            <div class="field">
                                <label for="notes">Notes / Reference</label>
                                <textarea name="notes" id="notes"
                                    placeholder="Supplier, reason for adjustment, or usage details"></textarea>
                            </div>

                            <p class="form-hint">
                                Usage reduces available stock. Stock adjustments should only be used to correct inventory records.
                            </p>

                            <button type="submit" class="btn full">＋ Save Stock Movement</button>
                        </form>
                    <?php else: ?>
                        <div class="empty">
                            <div class="empty-icon">🔒</div>
                            Your account can view inventory but cannot change stock.
                        </div>
                    <?php endif; ?>
                </div>

                <div class="panel">
                    <div class="panel-header">
                        <div>
                            <h3>Add Feed Type</h3>
                            <p class="panel-description">Create a feed item to track in your inventory.</p>
                        </div>
                        <div class="section-icon">＋</div>
                    </div>

                    <?php if (in_array($role, ['super_admin', 'admin', 'store_manager'], true)): ?>
                        <form method="POST">
                            <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                            <input type="hidden" name="action" value="add_feed">

                            <div class="field">
                                <label for="feed_name">Feed Name *</label>
                                <input type="text" name="feed_name" id="feed_name"
                                    maxlength="120" required placeholder="e.g. Dairy meal">
                            </div>

                            <div class="field">
                                <label for="unit">Measurement Unit *</label>
                                <select name="unit" id="unit" required>
                                    <option value="kg">Kilograms (kg)</option>
                                    <option value="bags">Bags</option>
                                    <option value="litres">Litres</option>
                                    <option value="tonnes">Tonnes</option>
                                    <option value="pieces">Pieces</option>
                                </select>
                            </div>

                            <div class="field">
                                <label for="description">Description</label>
                                <textarea name="description" id="description"
                                    placeholder="Optional feed details"></textarea>
                            </div>

                            <p class="form-hint">
                                Feed types are shared across farms. Stock quantities are tracked separately for each farm.
                            </p>

                            <button type="submit" class="btn secondary full">＋ Create Feed Type</button>
                        </form>
                    <?php else: ?>
                        <div class="empty">
                            <div class="empty-icon">🔒</div>
                            You do not have permission to create feed types.
                        </div>
                    <?php endif; ?>
                </div>

            </section>

            <section class="panel inventory-panel">
                <div class="panel-header">
                    <div>
                        <h3>Current Feed Stock</h3>
                        <p class="panel-description">Review quantities and identify items that need restocking.</p>
                    </div>
                    <div class="section-icon">▤</div>
                </div>

                <div class="table-toolbar">
                    <input type="search" id="inventorySearch" class="search-input"
                        placeholder="Search feed or farm...">
                    <span class="subtitle"><?= count($inventory) ?> inventory entries</span>
                </div>

                <div class="table-wrap">
                    <table id="inventoryTable">
                        <thead>
                            <tr>
                                <?php if ($isSuperAdmin && $selectedFarmId === 0): ?>
                                    <th>Farm</th>
                                <?php endif; ?>
                                <th>Feed Item</th>
                                <th>Available Stock</th>
                                <th>Reorder Level</th>
                                <th>Status</th>
                                <th>Last Updated</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!$inventory): ?>
                                <tr>
                                    <td colspan="<?= ($isSuperAdmin && $selectedFarmId === 0) ? 6 : 5 ?>">
                                        <div class="empty">
                                            <div class="empty-icon">📦</div>
                                            No feed stock recorded yet. Add a feed type and record a purchase to begin.
                                        </div>
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($inventory as $item): ?>
                                    <tr>
                                        <?php if ($isSuperAdmin && $selectedFarmId === 0): ?>
                                            <td><?= e($item['farm_name']) ?></td>
                                        <?php endif; ?>

                                        <td>
                                            <div class="feed-name"><?= e($item['feed_name']) ?></div>
                                            <div class="feed-unit">Measured in <?= e($item['unit']) ?></div>
                                        </td>

                                        <td>
                                            <strong>
                                                <?= number_format((float) $item['quantity'], 2) ?>
                                                <?= e($item['unit']) ?>
                                            </strong>
                                        </td>

                                        <td>
                                            <?= number_format((float) $item['reorder_level'], 2) ?>
                                            <?= e($item['unit']) ?>
                                        </td>

                                        <td>
                                            <?php if ((int) $item['is_low'] === 1): ?>
                                                <span class="stock-pill low">● Low Stock</span>
                                            <?php else: ?>
                                                <span class="stock-pill ok">● In Stock</span>
                                            <?php endif; ?>
                                        </td>

                                        <td><?= e($item['last_updated']) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </section>

            <section class="panel">
                <div class="panel-header">
                    <div>
                        <h3>Recent Stock Movements</h3>
                        <p class="panel-description">A record of the latest inventory changes.</p>
                    </div>
                    <div class="section-icon">◷</div>
                </div>

                <div class="table-wrap">
                    <table>
                        <thead>
                            <tr>
                                <?php if ($isSuperAdmin && $selectedFarmId === 0): ?>
                                    <th>Farm</th>
                                <?php endif; ?>
                                <th>Feed</th>
                                <th>Movement</th>
                                <th>Quantity Change</th>
                                <th>Recorded By</th>
                                <th>Date</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!$movements): ?>
                                <tr>
                                    <td colspan="<?= ($isSuperAdmin && $selectedFarmId === 0) ? 6 : 5 ?>">
                                        <div class="empty">
                                            <div class="empty-icon">🧾</div>
                                            No stock movements have been recorded yet.
                                        </div>
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($movements as $movement): ?>
                                    <tr>
                                        <?php if ($isSuperAdmin && $selectedFarmId === 0): ?>
                                            <td><?= e($movement['farm_name']) ?></td>
                                        <?php endif; ?>

                                        <td>
                                            <div class="feed-name"><?= e($movement['feed_name']) ?></div>
                                            <?php if (!empty($movement['notes'])): ?>
                                                <div class="history-meta"><?= e($movement['notes']) ?></div>
                                            <?php endif; ?>
                                        </td>

                                        <td>
                                            <span class="movement-pill <?= e($movement['movement_type']) ?>">
                                                <?= e(ucfirst($movement['movement_type'])) ?>
                                            </span>
                                        </td>

                                        <td>
                                            <?php
                                            $change = (float) $movement['quantity'];
                                            $positive = $change > 0;
                                            ?>
                                            <span class="<?= $positive ? 'amount-positive' : 'amount-negative' ?>">
                                                <?= $positive ? '+' : '' ?><?= number_format($change, 2) ?>
                                                <?= e($movement['unit']) ?>
                                            </span>
                                        </td>

                                        <td><?= e($movement['recorded_by_name'] ?? 'System') ?></td>
                                        <td><?= e($movement['movement_date']) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </section>

            <div class="footer">
                DairyFlow Farm Management System · Feed Inventory
            </div>
        </main>
    </div>

    <script>
        const movementType = document.getElementById('movement_type');
        const adjustmentField = document.getElementById('adjustment_direction_field');
        const adjustmentDirection = document.getElementById('adjustment_direction');
        const quantityLabel = document.getElementById('quantity_label');

        function updateMovementForm() {
            if (!movementType) return;

            const isAdjustment = movementType.value === 'adjustment';

            adjustmentField.hidden = !isAdjustment;
            adjustmentDirection.required = isAdjustment;

            if (movementType.value === 'usage') {
                quantityLabel.textContent = 'Quantity to Use *';
            } else if (isAdjustment) {
                quantityLabel.textContent = 'Quantity to Adjust *';
            } else {
                quantityLabel.textContent = 'Quantity Received *';
            }
        }

        if (movementType) {
            movementType.addEventListener('change', updateMovementForm);
            updateMovementForm();
        }

        const searchInput = document.getElementById('inventorySearch');
        const inventoryTable = document.getElementById('inventoryTable');

        if (searchInput && inventoryTable) {
            searchInput.addEventListener('input', function() {
                const query = this.value.toLowerCase();

                inventoryTable.querySelectorAll('tbody tr').forEach(row => {
                    row.style.display = row.innerText.toLowerCase().includes(query) ?
                        '' :
                        'none';
                });
            });
        }
    </script>

</body>

</html>