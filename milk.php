<?php
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';

requireLogin();
requireRole(['super_admin', 'admin', 'farm_manager']);

$role = $_SESSION['role_name'];
$userId = (int) $_SESSION['user_id'];
$userFarmId = $_SESSION['farm_id'] ?? null;
$isSuperAdmin = $role === 'super_admin';

$errors = [];
$farms = [];
$cows = [];
$records = [];
$todayTotal = 0.0;

$allowedSessions = ['morning', 'afternoon', 'evening'];

$dashboards = [
    'super_admin' => 'super_admin.php',
    'admin' => 'admin.php',
    'farm_manager' => 'farm_manager.php'
];

$dashboardUrl = $dashboards[$role] ?? 'index.php';

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$selectedFarmId = (int) ($userFarmId ?? 0);
$selectedCow = '';
$selectedDate = date('Y-m-d');
$selectedSession = 'morning';
$selectedQuantity = '';
$selectedNotes = '';

try {
    /*
     * Load active farms.
     */
    if ($isSuperAdmin) {
        $stmt = $pdo->query(
            "SELECT farm_id, farm_name
             FROM farms
             WHERE status = 'active'
             ORDER BY farm_name"
        );

        $farms = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $requestedFarmId = filter_var(
            $_GET['farm_id'] ?? '',
            FILTER_VALIDATE_INT
        );

        if ($requestedFarmId) {
            $selectedFarmId = (int) $requestedFarmId;
        } elseif (!empty($farms)) {
            $selectedFarmId = (int) $farms[0]['farm_id'];
        } else {
            $selectedFarmId = 0;
        }
    } elseif (!$userFarmId) {
        throw new RuntimeException(
            'Your account has not been assigned to a farm.'
        );
    }

    /*
     * Process milk production form.
     */
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $selectedFarmId = $isSuperAdmin
            ? (int) filter_var(
                $_POST['farm_id'] ?? '',
                FILTER_VALIDATE_INT
            )
            : (int) $userFarmId;

        $selectedCow = trim($_POST['cow_id'] ?? '');
        $selectedDate = trim($_POST['production_date'] ?? '');
        $selectedSession = $_POST['milking_session'] ?? '';
        $selectedQuantity = trim($_POST['quantity_litres'] ?? '');
        $selectedNotes = trim($_POST['notes'] ?? '');

        if (
            !isset($_POST['csrf_token']) ||
            !hash_equals(
                $_SESSION['csrf_token'],
                $_POST['csrf_token']
            )
        ) {
            $errors[] = 'Your session expired. Refresh the page and try again.';
        }

        /*
         * Verify that the selected farm is active.
         */
        $farmCheck = $pdo->prepare(
            "SELECT farm_id
             FROM farms
             WHERE farm_id = ?
               AND status = 'active'
             LIMIT 1"
        );

        $farmCheck->execute([$selectedFarmId]);

        if (
            $selectedFarmId < 1 ||
            !$farmCheck->fetch()
        ) {
            $errors[] = 'Please select a valid active farm.';
        }

        /*
         * Validate milking session.
         */
        if (!in_array($selectedSession, $allowedSessions, true)) {
            $errors[] = 'Please select a valid milking session.';
        }

        /*
         * Validate production date.
         */
        $dateObject = DateTime::createFromFormat(
            '!Y-m-d',
            $selectedDate
        );

        if (
            !$dateObject ||
            $dateObject->format('Y-m-d') !== $selectedDate ||
            $dateObject > new DateTime('today')
        ) {
            $errors[] = 'Please enter a valid date that is not in the future.';
        }

        /*
         * Validate milk quantity.
         */
        if (
            $selectedQuantity === '' ||
            !is_numeric($selectedQuantity) ||
            !is_finite((float) $selectedQuantity) ||
            (float) $selectedQuantity <= 0
        ) {
            $errors[] = 'Milk quantity must be greater than zero.';
        }

        if (strlen($selectedNotes) > 1000) {
            $errors[] = 'Notes cannot exceed 1000 characters.';
        }

        /*
         * If a cow is selected, it MUST be active
         * and belong to the selected farm.
         *
         * Leaving the cow empty records bulk milk.
         */

        /*
         * Validate the selected cow.
         * Farm Managers can only record milk for their assigned cows.
         * Admins and Super Admins can use any active cow
         * belonging to the selected farm.
         */
        $cowId = null;

        if ($selectedCow === '' && $role === 'farm_manager') {
            $errors[] = 'Please select one of your assigned cows.';
        } elseif ($selectedCow !== '') {
            $validatedCowId = filter_var(
                $selectedCow,
                FILTER_VALIDATE_INT
            );

            if (!$validatedCowId || $validatedCowId < 1) {
                $errors[] = 'Please select a valid cow.';
            } else {
                if ($role === 'farm_manager') {
                    $cowCheck = $pdo->prepare(
                        "SELECT cow_id
                         FROM cows
                         WHERE cow_id = ?
                           AND farm_id = ?
                           AND manager_id = ?
                           AND status = 'active'
                         LIMIT 1"
                    );

                    $cowCheck->execute([
                        (int) $validatedCowId,
                        (int) $userFarmId,
                        $userId
                    ]);
                } else {
                    $cowCheck = $pdo->prepare(
                        "SELECT cow_id
                         FROM cows
                         WHERE cow_id = ?
                           AND farm_id = ?
                           AND status = 'active'
                         LIMIT 1"
                    );

                    $cowCheck->execute([
                        (int) $validatedCowId,
                        $selectedFarmId
                    ]);
                }

                if (!$cowCheck->fetch()) {
                    $errors[] = 'The selected cow is not active or is not assigned to you.';
                } else {
                    $cowId = (int) $validatedCowId;
                }
            }
        }


        /*
         * Save the record only when validation succeeds.
         */
        if (empty($errors)) {
            $insert = $pdo->prepare(
                "INSERT INTO milk_production (
                    farm_id,
                    cow_id,
                    recorded_by,
                    production_date,
                    milking_session,
                    quantity_litres,
                    notes
                ) VALUES (?, ?, ?, ?, ?, ?, ?)"
            );

            $insert->execute([
                $selectedFarmId,
                $cowId,
                $userId,
                $selectedDate,
                $selectedSession,
                (float) $selectedQuantity,
                $selectedNotes !== '' ? $selectedNotes : null
            ]);

            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

            header('Location: milk.php?created=1');
            exit;
        }
    }

    /*
 * Load active cows available to the current user.
 */
    if ($selectedFarmId > 0) {
        if ($role === 'farm_manager') {
            $cowStmt = $pdo->prepare(
                "SELECT cow_id, tag_number, cow_name
             FROM cows
             WHERE farm_id = ?
               AND manager_id = ?
               AND status = 'active'
             ORDER BY tag_number"
            );

            $cowStmt->execute([
                (int) $userFarmId,
                $userId
            ]);
        } else {
            $cowStmt = $pdo->prepare(
                "SELECT cow_id, tag_number, cow_name
             FROM cows
             WHERE farm_id = ?
               AND status = 'active'
             ORDER BY tag_number"
            );

            $cowStmt->execute([$selectedFarmId]);
        }

        $cows = $cowStmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /*
  
      /*
     * Today's milk total and recent records.
     * Super Admin: all farms.
     * Admin: their own farm.
     * Farm Manager: their assigned cows only.
    
     * Super Admin: all farms.
     * Admin: their own farm.
     * Farm Manager: their assigned cows only.
     */
    if ($isSuperAdmin) {
        $totalStmt = $pdo->query(
            "SELECT COALESCE(SUM(quantity_litres), 0)
             FROM milk_production
             WHERE production_date = CURDATE()"
        );

        $recordsStmt = $pdo->query(
            "SELECT mp.production_id,
                    mp.production_date,
                    mp.milking_session,
                    mp.quantity_litres,
                    mp.notes,
                    f.farm_name,
                    c.tag_number,
                    c.cow_name,
                    u.full_name AS recorder
             FROM milk_production mp
             INNER JOIN farms f ON f.farm_id = mp.farm_id
             LEFT JOIN cows c ON c.cow_id = mp.cow_id
             LEFT JOIN users u ON u.user_id = mp.recorded_by
             ORDER BY mp.production_date DESC,
                      mp.production_id DESC
             LIMIT 100"
        );
    } elseif ($role === 'farm_manager') {
        $totalStmt = $pdo->prepare(
            "SELECT COALESCE(SUM(mp.quantity_litres), 0)
             FROM milk_production mp
             INNER JOIN cows c ON c.cow_id = mp.cow_id
             WHERE mp.farm_id = ?
               AND c.manager_id = ?
               AND mp.production_date = CURDATE()"
        );

        $totalStmt->execute([
            (int) $userFarmId,
            $userId
        ]);

        $recordsStmt = $pdo->prepare(
            "SELECT mp.production_id,
                    mp.production_date,
                    mp.milking_session,
                    mp.quantity_litres,
                    mp.notes,
                    f.farm_name,
                    c.tag_number,
                    c.cow_name,
                    u.full_name AS recorder
             FROM milk_production mp
             INNER JOIN cows c ON c.cow_id = mp.cow_id
             INNER JOIN farms f ON f.farm_id = mp.farm_id
             LEFT JOIN users u ON u.user_id = mp.recorded_by
             WHERE mp.farm_id = ?
               AND c.manager_id = ?
             ORDER BY mp.production_date DESC,
                      mp.production_id DESC
             LIMIT 100"
        );

        $recordsStmt->execute([
            (int) $userFarmId,
            $userId
        ]);
    } else {
        $totalStmt = $pdo->prepare(
            "SELECT COALESCE(SUM(quantity_litres), 0)
             FROM milk_production
             WHERE farm_id = ?
               AND production_date = CURDATE()"
        );

        $totalStmt->execute([(int) $userFarmId]);

        $recordsStmt = $pdo->prepare(
            "SELECT mp.production_id,
                    mp.production_date,
                    mp.milking_session,
                    mp.quantity_litres,
                    mp.notes,
                    f.farm_name,
                    c.tag_number,
                    c.cow_name,
                    u.full_name AS recorder
             FROM milk_production mp
             INNER JOIN farms f ON f.farm_id = mp.farm_id
             LEFT JOIN cows c ON c.cow_id = mp.cow_id
             LEFT JOIN users u ON u.user_id = mp.recorded_by
             WHERE mp.farm_id = ?
             ORDER BY mp.production_date DESC,
                      mp.production_id DESC
             LIMIT 100"
        );

        $recordsStmt->execute([(int) $userFarmId]);
    }


    $todayTotal = (float) $totalStmt->fetchColumn();
    $records = $recordsStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log('Milk production error: ' . $e->getMessage());

    $errors[] = 'A database error occurred. Please check your database connection.';
} catch (RuntimeException $e) {
    $errors[] = $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <title>Milk Production | DairyFlow</title>

    <link rel="stylesheet" href="assets/styles.css">


    <style>
        /* =========================================
   DAIRYFLOW MILK PRODUCTION
   Shared DairyFlow design system
========================================= */

        /* COLOR SYSTEM */
        :root {
            --navy: #102a25;
            --navy-light: #183b33;
            --green: #23865b;
            --green-dark: #176b46;
            --mint: #e9f7ef;
            --bg: #f5f8f6;
            --text: #1c3029;
            --muted: #788b81;
            --line: #e5ebe7;
            --white: #ffffff;
            --shadow: 0 12px 32px rgba(16, 42, 37, 0.055);
        }

        /* GENERAL */
        * {
            box-sizing: border-box;
        }

        html {
            min-height: 100%;
        }

        body {
            margin: 0;
            min-height: 100vh;
            background: var(--bg);
            color: var(--text);
            font-family: 'DM Sans', sans-serif;
            font-size: 14px;
        }

        a {
            color: inherit;
            text-decoration: none;
        }

        button,
        input,
        select,
        textarea {
            font: inherit;
        }

        /* =========================================
   APPLICATION LAYOUT
========================================= */

        .milk-layout {
            display: flex;
            min-height: 100vh;
            width: 100%;
        }

        /* =========================================
   SIDEBAR
========================================= */

        .milk-layout>.sidebar {
            position: fixed;
            top: 0;
            left: 0;
            bottom: 0;

            width: 248px;
            min-width: 248px;
            height: 100vh;

            display: flex;
            flex-direction: column;

            padding: 26px 15px 18px;

            background: var(--navy);
            color: var(--white);

            box-sizing: border-box;
            overflow-x: hidden;
            overflow-y: auto;

            z-index: 1000;
        }

        /* BRAND */
        .sidebar-brand {
            display: flex;
            align-items: center;
            gap: 12px;

            padding: 0 8px;
            margin-bottom: 38px;

            color: var(--white);
        }

        .sidebar-brand>div:last-child {
            min-width: 0;
        }

        .sidebar-brand h2 {
            margin: 0;

            color: var(--white);
            font-family: 'Manrope', sans-serif;
            font-size: 18px;
            font-weight: 800;
        }

        .sidebar-brand small {
            display: block;
            margin-top: 4px;

            color: #9db9aa;
            font-size: 9px;
            font-weight: 700;
            letter-spacing: 1.4px;
            white-space: nowrap;
        }

        /* LOGO */
        .sidebar-logo {
            width: 42px;
            height: 42px;
            min-width: 42px;

            display: flex;
            align-items: center;
            justify-content: center;

            border-radius: 12px;
            background: var(--green);

            color: var(--white);
            font-size: 16px;
            font-weight: 800;
        }

        /* NAVIGATION */
        .sidebar nav {
            display: flex;
            flex-direction: column;
            gap: 5px;
            width: 100%;
            margin: 0;
        }

        .sidebar nav a {
            display: flex;
            align-items: center;

            width: 100%;
            min-height: 44px;
            padding: 0 12px;

            border-radius: 9px;

            color: #c1d2c8;
            font-size: 12.5px;
            font-weight: 600;

            white-space: nowrap;
            transition: background 0.2s ease, color 0.2s ease;
        }

        .sidebar nav a:hover {
            background: var(--navy-light);
            color: var(--white);
        }

        .sidebar nav a.active {
            background: var(--green);
            color: var(--white);
        }

        /* SIDEBAR BOTTOM */
        .sidebar-bottom {
            margin-top: auto;
            padding-top: 20px;
        }

        .sidebar-user {
            padding: 15px 8px;
            margin-bottom: 8px;

            border-top: 1px solid rgba(255, 255, 255, 0.10);
            overflow-wrap: anywhere;
        }

        .sidebar-user strong {
            display: block;
            color: var(--white);
            font-size: 12px;
            font-weight: 700;
        }

        .sidebar-user small {
            display: block;
            margin-top: 4px;

            color: #9db5a7;
            font-size: 10px;
        }

        .sidebar-logout {
            display: flex;
            align-items: center;

            min-height: 42px;
            padding: 0 12px;

            border-radius: 9px;

            color: #ffb5b5;
            font-size: 12px;
            font-weight: 600;

            transition: background 0.2s ease;
        }

        .sidebar-logout:hover {
            background: var(--navy-light);
            color: var(--white);
        }

        /* =========================================
   MAIN CONTENT
========================================= */

        .milk-main {
            width: calc(100% - 248px);
            min-width: 0;
            min-height: 100vh;

            margin-left: 248px;
            padding: 28px 36px 34px;

            background: var(--bg);
        }

        /* TOP BAR */
        .milk-topbar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;

            margin-bottom: 28px;
            padding-bottom: 22px;

            border-bottom: 1px solid var(--line);
        }

        .milk-topbar h1 {
            margin: 0 0 7px;

            color: var(--navy);
            font-family: 'Manrope', sans-serif;
            font-size: 25px;
            font-weight: 800;
            letter-spacing: -0.5px;
        }

        .milk-subtitle {
            margin: 0;
            color: var(--muted);
            font-size: 12px;
            line-height: 1.6;
        }

        /* =========================================
   BUTTONS
========================================= */

        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;

            min-height: 43px;
            padding: 11px 17px;

            border: 0;
            border-radius: 9px;

            background: var(--green);
            color: var(--white);

            font-size: 13px;
            font-weight: 700;

            cursor: pointer;
            transition: background 0.2s ease, transform 0.2s ease;
        }

        .btn:hover {
            background: var(--green-dark);
            transform: translateY(-1px);
        }

        .btn-secondary {
            background: var(--white);
            color: var(--navy);
            border: 1px solid var(--line);
        }

        .btn-secondary:hover {
            background: var(--mint);
        }

        /* =========================================
   MILK STATISTICS
========================================= */

        .milk-stat {
            position: relative;
            overflow: hidden;

            padding: 25px 27px;
            margin-bottom: 23px;

            border-radius: 15px;

            background: linear-gradient(120deg,
                    var(--green),
                    var(--navy));

            color: var(--white);
            box-shadow: var(--shadow);
        }

        .milk-stat span {
            display: block;
            color: #e0f0e5;
            font-size: 12px;
            font-weight: 600;
        }

        .milk-stat strong {
            display: block;
            margin-top: 10px;

            color: var(--white);
            font-family: 'Manrope', sans-serif;
            font-size: 32px;
            font-weight: 800;
        }

        /* =========================================
   MILK CARDS
========================================= */

        .milk-card {
            width: 100%;
            min-width: 0;

            padding: 23px;
            margin-bottom: 22px;

            border: 1px solid var(--line);
            border-radius: 15px;

            background: var(--white);
            box-shadow: var(--shadow);
        }

        .milk-card h2 {
            margin: 0 0 8px;

            color: var(--navy);
            font-family: 'Manrope', sans-serif;
            font-size: 17px;
            font-weight: 800;
        }

        .card-description {
            margin: 0 0 22px;

            color: var(--muted);
            font-size: 12px;
            line-height: 1.6;
        }

        /* =========================================
   MILK REGISTRATION FORM
========================================= */

        .milk-form {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 19px;
        }

        .milk-field {
            min-width: 0;

            display: flex;
            flex-direction: column;
            gap: 8px;
        }

        .milk-field.full {
            grid-column: 1 / -1;
        }

        .milk-field label {
            color: var(--navy);
            font-size: 12px;
            font-weight: 700;
        }

        .milk-field input,
        .milk-field select,
        .milk-field textarea {
            width: 100%;
            min-height: 44px;
            padding: 11px 13px;

            border: 1px solid #d5e0d8;
            border-radius: 9px;

            background: var(--white);
            color: var(--text);

            font-family: 'DM Sans', sans-serif;
            font-size: 13px;

            transition: border-color 0.2s ease, box-shadow 0.2s ease;
        }

        .milk-field input:focus,
        .milk-field select:focus,
        .milk-field textarea:focus {
            outline: none;
            border-color: var(--green);
            box-shadow: 0 0 0 3px rgba(35, 134, 91, 0.10);
        }

        .milk-field input[readonly] {
            background: #f4f7f5;
            color: var(--muted);
            cursor: not-allowed;
        }

        .milk-field textarea {
            min-height: 90px;
            resize: vertical;
        }

        .milk-help {
            margin: 0;
            color: var(--muted);
            font-size: 11px;
            line-height: 1.5;
        }

        /* =========================================
   ALERT MESSAGES
========================================= */

        .milk-alert {
            padding: 14px 16px;
            margin-bottom: 18px;

            border-radius: 9px;
            font-size: 12px;
            line-height: 1.6;
        }

        .milk-alert.error {
            background: #fff0f0;
            border: 1px solid #f3caca;
            color: #9d2828;
        }

        .milk-alert.success {
            background: var(--mint);
            border: 1px solid #c5e7d0;
            color: var(--green-dark);
        }

        /* =========================================
   MILK RECORDS TABLE
========================================= */

        .milk-table-wrap {
            width: 100%;
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
        }

        .milk-table {
            width: 100%;
            min-width: 720px;

            border-collapse: collapse;
            text-align: left;
        }

        .milk-table th {
            padding: 13px 12px;

            background: #f5f8f6;
            border-bottom: 1px solid var(--line);

            color: var(--muted);
            font-size: 9px;
            font-weight: 700;
            letter-spacing: 0.8px;
            white-space: nowrap;
        }

        .milk-table td {
            padding: 14px 12px;

            border-bottom: 1px solid #eef2ef;

            color: #53645c;
            font-size: 11px;
            line-height: 1.6;
            vertical-align: middle;
        }

        .milk-table td strong {
            color: var(--navy);
        }

        .milk-table tbody tr:last-child td {
            border-bottom: 0;
        }

        .milk-table tbody tr:hover {
            background: #f8fbf9;
        }

        /* =========================================
   SESSION BADGES
========================================= */

        .session-badge {
            display: inline-block;
            padding: 5px 9px;

            border-radius: 20px;

            background: var(--mint);
            color: var(--green-dark);

            font-size: 10px;
            font-weight: 700;
            white-space: nowrap;
        }

        /* EMPTY STATE */
        .empty-state {
            min-height: 120px;

            display: flex;
            align-items: center;
            justify-content: center;

            padding: 25px 12px;

            color: var(--muted);
            font-size: 12px;
            text-align: center;
        }

        /* =========================================
   RESPONSIVE
========================================= */

        @media (max-width: 1100px) {
            .milk-main {
                padding-left: 24px;
                padding-right: 24px;
            }
        }

        @media (max-width: 760px) {
            .milk-layout>.sidebar {
                transform: translateX(-100%);
            }

            .milk-main {
                width: 100%;
                margin-left: 0;
                padding: 20px 16px;
            }

            .milk-topbar {
                margin-bottom: 20px;
            }

            .milk-topbar h1 {
                font-size: 22px;
            }
        }

        @media (max-width: 580px) {
            .milk-form {
                grid-template-columns: 1fr;
            }

            .milk-field.full {
                grid-column: auto;
            }

            .milk-card {
                padding: 17px;
            }

            .milk-stat {
                padding: 21px;
            }

            .milk-stat strong {
                font-size: 27px;
            }

            .milk-topbar h1 {
                font-size: 21px;
            }
        }

        @media (max-width: 400px) {
            .milk-main {
                padding: 16px 11px;
            }

            .milk-card {
                padding: 14px;
            }
        }

        /* MOBILE SIDEBAR MENU */
        .mobile-menu-btn {
            display: none;
            align-items: center;
            justify-content: center;
            width: 44px;
            height: 44px;
            margin: 0 0 15px;
            border: none;
            border-radius: 9px;
            background: var(--navy);
            color: #fff;
            font-size: 23px;
            cursor: pointer;
        }

        .sidebar-overlay {
            display: none;
        }

        @media (max-width: 760px) {
            .milk-layout>.sidebar {
                position: fixed;
                top: 0;
                left: 0;
                bottom: 0;
                width: 248px;
                min-width: 248px;
                height: 100vh;
                height: 100dvh;
                z-index: 1001;
                transform: translateX(-100%);
                transition: transform 0.3s ease;
            }

            .milk-layout>.sidebar.active {
                transform: translateX(0);
            }

            .sidebar-overlay {
                display: block;
                position: fixed;
                inset: 0;
                background: rgba(0, 0, 0, 0.45);
                z-index: 1000;
                opacity: 0;
                visibility: hidden;
                transition: opacity 0.3s ease, visibility 0.3s ease;
            }

            .sidebar-overlay.active {
                opacity: 1;
                visibility: visible;
            }

            .mobile-menu-btn {
                display: flex;
            }

            .milk-main {
                width: 100%;
                margin-left: 0;
            }
        }
    </style>


</head>

<body>

    <div class="milk-layout">

        <aside class="sidebar">
            <div class="sidebar-brand">
                <div class="sidebar-logo">MF</div>
                <div>
                    <h2>MwendAntu</h2>
                    <small>Farm Management System</small>
                </div>
            </div>

            <nav>
                <a href="index.php">▦ &nbsp; Dashboard</a>

                <?php if (in_array($role, ['super_admin', 'admin', 'farm_manager'], true)): ?>
                    <a href="cows.php">♧ &nbsp; Cows & Records</a>
                    <a class="active" href="milk.php">◉ &nbsp; Milk Production</a>
                    <a href="search.php">🔍 &nbsp; Search cow</a>

                    <a href="health.php">♡ &nbsp; Health & Vaccinations</a>
                <?php endif; ?>


                <?php if (in_array($role, ['super_admin', 'admin', 'store_manager'], true)): ?>
                    <a href="feeds.php">🌾 &nbsp; Feed Inventory</a>

                <?php endif; ?>

                <a href="reports.php">▥ &nbsp; Reports</a>
                <?php if ($isSuperAdmin): ?>
                    <a href="users.php">♙ &nbsp; Manage Users</a>
                    <a href="farms.php">⌂ &nbsp; Manage Farms</a>
                <?php endif; ?>

                <?php if ($role === 'admin'): ?>
                    <a href="users.php">♙ &nbsp; Manage Farm Managers</a>
                <?php endif; ?>
            </nav>

            <div class="sidebar-bottom">
                <div class="sidebar-user">
                    <strong><?= e($_SESSION['full_name'] ?? 'User') ?></strong>
                    <small>
                        <?= e(ucwords(str_replace('_', ' ', $role))) ?>
                    </small>
                </div>

                <a class="sidebar-logout" href="logout.php">
                    ↪ Log Out
                </a>
            </div>
        </aside>
        <div class="sidebar-overlay" id="sidebarOverlay"></div>
        <main class="milk-main">
            <button type="button" class="mobile-menu-btn" id="mobileMenuBtn">
                ☰
            </button>

            <header class="milk-topbar">
                <div>
                    <h1>Milk Production</h1>
                    <p class="milk-subtitle">
                        Record and monitor milk collected on your farm.
                    </p>
                </div>
            </header>

            <?php if (isset($_GET['created'])): ?>
                <div class="milk-alert success">
                    Milk production record saved successfully.
                </div>
            <?php endif; ?>

            <?php foreach ($errors as $error): ?>
                <div class="milk-alert error">
                    <?= e($error) ?>
                </div>
            <?php endforeach; ?>

            <section class="milk-stat">
                <span>Today's total milk production</span>
                <strong>
                    <?= number_format($todayTotal, 2) ?> litres
                </strong>
            </section>

            <section class="milk-card">
                <h2>Record Milk Production</h2>
                <p class="card-description">
                    <?php if ($role === 'farm_manager'): ?>
                        Record milk collected from cows assigned to you.
                    <?php elseif ($isSuperAdmin): ?>
                        Select a farm and record milk production from its active cows.
                    <?php else: ?>
                        Record milk production from active cows on your farm.
                    <?php endif; ?>
                </p>

                <form method="POST" action="milk.php" class="milk-form">

                    <input
                        type="hidden"
                        name="csrf_token"
                        value="<?= e($_SESSION['csrf_token']) ?>">

                    <?php if ($isSuperAdmin): ?>
                        <div class="milk-field full">
                            <label for="farm_id">Farm *</label>

                            <select
                                id="farm_id"
                                name="farm_id"
                                required
                                onchange="window.location.href='milk.php?farm_id='+encodeURIComponent(this.value)">
                                <option value="">Select farm</option>

                                <?php foreach ($farms as $farm): ?>
                                    <option
                                        value="<?= (int) $farm['farm_id'] ?>"
                                        <?= $selectedFarmId === (int) $farm['farm_id'] ? 'selected' : '' ?>>
                                        <?= e($farm['farm_name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    <?php endif; ?>

                    <div class="milk-field">
                        <label for="production_date">Production Date *</label>

                        <input
                            type="date"
                            id="production_date"
                            name="production_date"
                            value="<?= date('Y-m-d') ?>"
                            min="<?= date('Y-m-d') ?>"
                            max="<?= date('Y-m-d') ?>"
                            required
                            readonly>
                    </div>

                    <div class="milk-field">
                        <label for="milking_session">Milking Session *</label>

                        <select
                            id="milking_session"
                            name="milking_session"
                            required>
                            <option value="morning" <?= $selectedSession === 'morning' ? 'selected' : '' ?>>
                                Morning
                            </option>

                            <option value="afternoon" <?= $selectedSession === 'afternoon' ? 'selected' : '' ?>>
                                Afternoon
                            </option>

                            <option value="evening" <?= $selectedSession === 'evening' ? 'selected' : '' ?>>
                                Evening
                            </option>
                        </select>
                    </div>

                    <div class="milk-field">
                        <label for="cow_id">Cow</label>

                        <select id="cow_id" name="cow_id">
                            <option value="">Bulk / unassigned milk</option>

                            <?php foreach ($cows as $cow): ?>
                                <option
                                    value="<?= (int) $cow['cow_id'] ?>"
                                    <?= (string) $selectedCow === (string) $cow['cow_id'] ? 'selected' : '' ?>>
                                    <?= e($cow['tag_number']) ?>
                                    <?= !empty($cow['cow_name']) ? ' - ' . e($cow['cow_name']) : '' ?>
                                </option>
                            <?php endforeach; ?>
                        </select>

                        <p class="milk-help">
                            Only active cows are listed. Leave unassigned for bulk milk.
                        </p>
                    </div>

                    <div class="milk-field">
                        <label for="quantity_litres">Quantity (litres) *</label>

                        <input
                            type="number"
                            id="quantity_litres"
                            name="quantity_litres"
                            min="0.01"
                            step="0.01"
                            value="<?= e($selectedQuantity) ?>"
                            placeholder="e.g. 25.50"
                            required>
                    </div>

                    <div class="milk-field full">
                        <label for="notes">Notes</label>

                        <textarea
                            id="notes"
                            name="notes"
                            rows="3"
                            maxlength="1000"
                            placeholder="Optional observations about milk collection"><?= e($selectedNotes) ?></textarea>
                    </div>

                    <div class="milk-field full">
                        <button type="submit" class="btn">
                            Save Milk Record
                        </button>
                    </div>

                </form>
            </section>

            <section class="milk-card">
                <h2>Recent Milk Production</h2>
                <p class="card-description">
                    The most recent milk collection records.
                </p>

                <?php if (empty($records)): ?>
                    <div class="empty-state">
                        No milk production records have been recorded yet.
                    </div>
                <?php else: ?>

                    <div class="milk-table-wrap">
                        <table class="milk-table">
                            <thead>
                                <tr>
                                    <th>Date</th>

                                    <?php if ($isSuperAdmin): ?>
                                        <th>Farm</th>
                                    <?php endif; ?>

                                    <th>Session</th>
                                    <th>Cow</th>
                                    <th>Quantity</th>
                                    <th>Recorded By</th>
                                    <th>Notes</th>
                                </tr>
                            </thead>

                            <tbody>
                                <?php foreach ($records as $record): ?>
                                    <tr>
                                        <td>
                                            <?= e($record['production_date']) ?>
                                        </td>

                                        <?php if ($isSuperAdmin): ?>
                                            <td><?= e($record['farm_name']) ?></td>
                                        <?php endif; ?>

                                        <td>
                                            <span class="session-badge">
                                                <?= e(ucfirst($record['milking_session'])) ?>
                                            </span>
                                        </td>

                                        <td>
                                            <?php if (!empty($record['tag_number'])): ?>
                                                <?= e($record['tag_number']) ?>

                                                <?php if (!empty($record['cow_name'])): ?>
                                                    <br>
                                                    <?= e($record['cow_name']) ?>
                                                <?php endif; ?>
                                            <?php else: ?>
                                                Bulk / unassigned
                                            <?php endif; ?>
                                        </td>

                                        <td>
                                            <strong>
                                                <?= number_format(
                                                    (float) $record['quantity_litres'],
                                                    2
                                                ) ?> L
                                            </strong>
                                        </td>

                                        <td>
                                            <?= e($record['recorder'] ?? 'Unknown') ?>
                                        </td>

                                        <td>
                                            <?= e($record['notes'] ?? '') ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                <?php endif; ?>
            </section>

        </main>
    </div>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const sidebar = document.querySelector('.sidebar');
            const menuBtn = document.getElementById('mobileMenuBtn');
            const overlay = document.getElementById('sidebarOverlay');

            if (!sidebar || !menuBtn || !overlay) return;

            function openMenu() {
                sidebar.classList.add('active');
                overlay.classList.add('active');
            }

            function closeMenu() {
                sidebar.classList.remove('active');
                overlay.classList.remove('active');
            }

            menuBtn.addEventListener('click', function() {
                sidebar.classList.contains('active') ? closeMenu() : openMenu();
            });

            overlay.addEventListener('click', closeMenu);

            sidebar.querySelectorAll('a').forEach(function(link) {
                link.addEventListener('click', closeMenu);
            });

            document.addEventListener('keydown', function(event) {
                if (event.key === 'Escape') closeMenu();
            });
        });
    </script>
</body>

</html>