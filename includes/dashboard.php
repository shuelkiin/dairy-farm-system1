<?php
require_once __DIR__ . '/../config/database.php';
requireLogin();

$role = $_SESSION['role_name'];
$farmId = $_SESSION['farm_id'] ?? null;

$isSuperAdmin = $role === 'super_admin';

if ($isSuperAdmin) {
    $farmCount = (int)$pdo->query("
        SELECT COUNT(*) FROM farms WHERE status = 'active'
    ")->fetchColumn();

    $cowCount = (int)$pdo->query("
        SELECT COUNT(*) FROM cows
    ")->fetchColumn();

    $milkStmt = $pdo->query("
        SELECT COALESCE(SUM(quantity_litres), 0)
        FROM milk_production
        WHERE production_date = CURDATE()
    ");
    $milkToday = (float)$milkStmt->fetchColumn();

    $healthCount = (int)$pdo->query("
        SELECT COUNT(*)
        FROM health_records hr
        JOIN cows c ON c.cow_id = hr.cow_id
        WHERE hr.status IN ('ongoing', 'under_treatment')
    ")->fetchColumn();

    $farms = $pdo->query("
        SELECT f.farm_id, f.farm_name,
               COUNT(DISTINCT c.cow_id) AS cow_count,
               COALESCE((
                   SELECT SUM(mp.quantity_litres)
                   FROM milk_production mp
                   WHERE mp.farm_id = f.farm_id
                   AND mp.production_date = CURDATE()
               ), 0) AS milk_today
        FROM farms f
        LEFT JOIN cows c ON c.farm_id = f.farm_id
        GROUP BY f.farm_id, f.farm_name
        ORDER BY f.farm_name
    ")->fetchAll();
} elseif ($role === 'farm_manager') {
    $farmCount = $farmId ? 1 : 0;
    $managerId = (int) $_SESSION['user_id'];

    // Total cows assigned to this manager.
    $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM cows
        WHERE farm_id = :farm
          AND manager_id = :manager
    ");
    $stmt->execute([
        'farm' => $farmId ?: 0,
        'manager' => $managerId
    ]);
    $cowCount = (int) $stmt->fetchColumn();

    // Today's milk from this manager's cows.
    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(mp.quantity_litres), 0)
        FROM milk_production mp
        INNER JOIN cows c ON c.cow_id = mp.cow_id
        WHERE mp.farm_id = :farm
          AND c.manager_id = :manager
          AND mp.production_date = CURDATE()
    ");
    $stmt->execute([
        'farm' => $farmId ?: 0,
        'manager' => $managerId
    ]);
    $milkToday = (float) $stmt->fetchColumn();

    // Health records for this manager's cows.
    $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM health_records hr
        INNER JOIN cows c ON c.cow_id = hr.cow_id
        WHERE c.farm_id = :farm
          AND c.manager_id = :manager
          AND hr.status IN ('ongoing', 'under_treatment')
    ");
    $stmt->execute([
        'farm' => $farmId ?: 0,
        'manager' => $managerId
    ]);
    $healthCount = (int) $stmt->fetchColumn();

    // Farm card, showing only this manager's cows and milk.

    $stmt = $pdo->prepare("
    SELECT f.farm_id, f.farm_name,
           COUNT(DISTINCT c.cow_id) AS cow_count,
           COALESCE((
               SELECT SUM(mp.quantity_litres)
               FROM milk_production mp
               INNER JOIN cows mc ON mc.cow_id = mp.cow_id
               WHERE mp.farm_id = f.farm_id
                 AND mc.manager_id = :manager_milk
                 AND mp.production_date = CURDATE()
           ), 0) AS milk_today
    FROM farms f
    LEFT JOIN cows c
      ON c.farm_id = f.farm_id
     AND c.manager_id = :manager_cows
    WHERE f.farm_id = :farm
    GROUP BY f.farm_id, f.farm_name
");

    $stmt->execute([
        'manager_milk' => $managerId,
        'manager_cows' => $managerId,
        'farm' => $farmId ?: 0
    ]);

    $farms = $stmt->fetchAll();
} else {
    $farmCount = $farmId ? 1 : 0;
    $stmt = $pdo->prepare("
        SELECT COUNT(*) FROM cows WHERE farm_id = :farm
    ");
    $stmt->execute(['farm' => $farmId ?: 0]);
    $cowCount = (int)$stmt->fetchColumn();

    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(quantity_litres), 0)
        FROM milk_production
        WHERE farm_id = :farm
        AND production_date = CURDATE()
    ");
    $stmt->execute(['farm' => $farmId ?: 0]);
    $milkToday = (float)$stmt->fetchColumn();

    $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM health_records hr
        JOIN cows c ON c.cow_id = hr.cow_id
        WHERE c.farm_id = :farm
        AND hr.status IN ('ongoing', 'under_treatment')
    ");
    $stmt->execute(['farm' => $farmId ?: 0]);
    $healthCount = (int)$stmt->fetchColumn();

    $stmt = $pdo->prepare("
        SELECT f.farm_id, f.farm_name,
               COUNT(DISTINCT c.cow_id) AS cow_count,
               COALESCE((
                   SELECT SUM(mp.quantity_litres)
                   FROM milk_production mp
                   WHERE mp.farm_id = f.farm_id
                   AND mp.production_date = CURDATE()
               ), 0) AS milk_today
        FROM farms f
        LEFT JOIN cows c ON c.farm_id = f.farm_id
        WHERE f.farm_id = :farm
        GROUP BY f.farm_id, f.farm_name
    ");
    $stmt->execute(['farm' => $farmId ?: 0]);
    $farms = $stmt->fetchAll();
}

$feedSql = $isSuperAdmin
    ? "SELECT COALESCE(SUM(quantity), 0) FROM feed_stock"
    : "SELECT COALESCE(SUM(quantity), 0) FROM feed_stock WHERE farm_id = :farm";

$stmt = $pdo->prepare($feedSql);
$stmt->execute($isSuperAdmin ? [] : ['farm' => $farmId ?: 0]);
$feedStock = (float)$stmt->fetchColumn();


if ($isSuperAdmin) {
    $milkSql = "
        SELECT mp.*, f.farm_name, c.tag_number, c.cow_name
        FROM milk_production mp
        JOIN farms f ON f.farm_id = mp.farm_id
        LEFT JOIN cows c ON c.cow_id = mp.cow_id
        ORDER BY mp.production_id DESC
        LIMIT 8
    ";

    $stmt = $pdo->query($milkSql);
} elseif ($role === 'farm_manager') {
    $milkSql = "
        SELECT mp.*, f.farm_name, c.tag_number, c.cow_name
        FROM milk_production mp
        JOIN farms f ON f.farm_id = mp.farm_id
        INNER JOIN cows c ON c.cow_id = mp.cow_id
        WHERE mp.farm_id = :farm
          AND c.manager_id = :manager
        ORDER BY mp.production_id DESC
        LIMIT 8
    ";

    $stmt = $pdo->prepare($milkSql);
    $stmt->execute([
        'farm' => $farmId ?: 0,
        'manager' => (int) $_SESSION['user_id']
    ]);
} else {
    // Admin continues to see all milk records for their farm.
    $milkSql = "
        SELECT mp.*, f.farm_name, c.tag_number, c.cow_name
        FROM milk_production mp
        JOIN farms f ON f.farm_id = mp.farm_id
        LEFT JOIN cows c ON c.cow_id = mp.cow_id
        WHERE mp.farm_id = :farm
        ORDER BY mp.production_id DESC
        LIMIT 8
    ";

    $stmt = $pdo->prepare($milkSql);
    $stmt->execute(['farm' => $farmId ?: 0]);
}

$recentMilk = $stmt->fetchAll();


$pageTitle = $pageTitle ?? 'Dashboard';
$firstName = explode(' ', trim($_SESSION['full_name']))[0] ?? 'there';
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($pageTitle) ?> | DairyFlow</title>
    <link rel="stylesheet" href="assets/styles.css">


    <style>
        /* =========================================
   DAIRYFLOW DASHBOARD
   Consistent sidebar and dashboard layout
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

        .app-shell {
            display: flex;
            min-height: 100vh;
            width: 100%;
        }

        /* =========================================
   SIDEBAR
========================================= */

        .app-shell>.sidebar {
            position: fixed;
            top: 0;
            left: 0;
            bottom: 0;

            width: 248px;
            height: 100vh;
            flex: 0 0 248px;

            display: flex;
            flex-direction: column;

            padding: 26px 15px 18px;

            background: var(--navy);
            color: var(--white);

            overflow-x: hidden;
            overflow-y: auto;

            box-sizing: border-box;
            z-index: 1000;
        }

        /* BRAND */
        .sidebar .brand {
            display: flex;
            align-items: center;
            gap: 12px;

            padding: 0 8px;
            margin-bottom: 38px;

            color: var(--white);
            text-decoration: none;

            font-family: 'Manrope', sans-serif;
            font-size: 18px;
            font-weight: 800;
            white-space: nowrap;
        }

        .sidebar .brand>span:last-child {
            display: block;
            line-height: 1.3;
        }

        .sidebar .brand small {
            display: block;
            margin-top: 4px;

            color: #9db9aa;
            font-family: 'DM Sans', sans-serif;
            font-size: 9px;
            font-weight: 700;
            letter-spacing: 1.4px;
        }

        /* LOGO */
        .sidebar .logo {
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

        /* NAVIGATION LABEL */
        .sidebar .nav-label {
            margin: 0 0 12px;
            padding: 0 12px;

            color: #829d8e;
            font-size: 10px;
            font-weight: 700;
            letter-spacing: 1.5px;
        }

        /* NAVIGATION */
        .sidebar nav {
            display: flex;
            flex-direction: column;
            gap: 5px;
            width: 100%;
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

        /* USER PROFILE */
        .user-chip {
            display: flex;
            align-items: center;
            gap: 11px;

            padding: 14px 8px;
            margin-bottom: 8px;

            border-top: 1px solid rgba(255, 255, 255, 0.10);
        }

        .avatar {
            width: 37px;
            height: 37px;
            min-width: 37px;

            display: flex;
            align-items: center;
            justify-content: center;

            border-radius: 50%;
            background: #d9f2e3;

            color: var(--green-dark);
            font-size: 14px;
            font-weight: 800;
        }

        .user-chip strong {
            display: block;
            color: var(--white);
            font-size: 12px;
            font-weight: 700;
        }

        .user-chip small {
            display: block;
            margin-top: 4px;
            color: #9db5a7;
            font-size: 10px;
        }

        /* PASSWORD AND LOGOUT */
        .sidebar .nav-link,
        .sidebar .logout {
            display: flex;
            align-items: center;
            gap: 10px;

            width: 100%;
            min-height: 42px;
            padding: 0 12px;

            border-radius: 9px;

            color: #c1d2c8;
            font-size: 12px;
            font-weight: 600;
            white-space: nowrap;

            transition: background 0.2s ease, color 0.2s ease;
        }

        .sidebar .nav-link:hover,
        .sidebar .logout:hover {
            background: var(--navy-light);
            color: var(--white);
        }

        .sidebar .logout {
            margin-top: 4px;
            color: #ffb5b5;
        }

        /* =========================================
   MAIN AREA
========================================= */

        .app-shell>.main-area {
            width: calc(100% - 248px);
            min-width: 0;
            min-height: 100vh;

            margin-left: 248px;

            background: var(--bg);
            box-sizing: border-box;
        }

        /* EYEBROW */
        .eyebrow {
            display: block;
            color: var(--green);
            font-size: 9px;
            font-weight: 700;
            letter-spacing: 1.5px;
        }

        /* =========================================
   TOP BAR
========================================= */

        .topbar {
            min-height: 90px;
            padding: 20px 36px;

            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 15px;

            background: var(--white);
            border-bottom: 1px solid var(--line);
        }

        .topbar h1 {
            margin: 6px 0 0;

            color: var(--navy);
            font-family: 'Manrope', sans-serif;
            font-size: 23px;
            font-weight: 800;
            letter-spacing: -0.6px;
        }

        .topbar-right {
            display: flex;
            align-items: center;
            gap: 10px;

            color: var(--muted);
            font-size: 11px;
            white-space: nowrap;
        }

        .status-dot {
            width: 7px;
            height: 7px;

            display: inline-block;
            flex-shrink: 0;

            border-radius: 50%;
            background: var(--green);
            box-shadow: 0 0 0 4px var(--mint);
        }

        .top-avatar {
            width: 34px;
            height: 34px;
            flex-shrink: 0;

            display: grid;
            place-items: center;

            margin-left: 8px;
            border-radius: 50%;

            background: var(--mint);
            color: var(--green-dark);

            font-size: 13px;
            font-weight: 700;
        }

        /* =========================================
   MAIN CONTENT
========================================= */

        .content {
            width: 100%;
            max-width: 1600px;

            margin: 0 auto;
            padding: 29px 36px 34px;
        }

        .welcome-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;

            margin-bottom: 24px;
        }

        .welcome-row h2 {
            margin: 8px 0;

            color: var(--navy);
            font-family: 'Manrope', sans-serif;
            font-size: 25px;
            font-weight: 800;
        }

        .wave {
            color: var(--green);
        }

        .muted {
            margin: 5px 0 0;
            color: var(--muted);
            font-size: 12px;
            line-height: 1.65;
        }

        .date-chip {
            padding: 11px 14px;

            border: 1px solid var(--line);
            border-radius: 10px;

            background: var(--white);
            color: var(--muted);

            font-size: 11px;
            white-space: nowrap;
        }

        /* =========================================
   METRIC GRID
========================================= */

        .metric-grid {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 15px;

            margin-bottom: 22px;
        }

        .metric-card {
            min-width: 0;
            padding: 19px;

            border: 1px solid var(--line);
            border-radius: 15px;

            background: var(--white);
            box-shadow: var(--shadow);

            transition: transform 0.2s ease, box-shadow 0.2s ease;
        }

        .metric-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 15px 35px rgba(16, 42, 37, 0.09);
        }

        .metric-top {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 8px;
        }

        .metric-icon {
            width: 37px;
            height: 37px;
            flex-shrink: 0;

            display: grid;
            place-items: center;

            border-radius: 11px;
            font-size: 18px;
        }

        .metric-icon.green {
            background: var(--mint);
            color: var(--green-dark);
        }

        .metric-icon.mint {
            background: #e4f3ed;
            color: var(--green);
        }

        .metric-icon.navy {
            background: #e8efec;
            color: var(--navy);
        }

        .metric-icon.lime {
            background: #e9f4e8;
            color: var(--green-dark);
        }

        .metric-label {
            color: var(--muted);
            font-size: 9px;
            font-weight: 700;
            letter-spacing: 0.7px;
        }

        .metric-number {
            margin: 20px 0 5px;

            color: var(--navy);
            font-family: 'Manrope', sans-serif;
            font-size: 29px;
            font-weight: 800;

            overflow-wrap: anywhere;
        }

        .metric-number small {
            color: var(--muted);
            font-family: 'DM Sans', sans-serif;
            font-size: 12px;
            font-weight: 600;
        }

        .metric-foot {
            margin: 0;
            color: var(--muted);
            font-size: 10px;
        }

        /* =========================================
   DASHBOARD PANELS
========================================= */

        .section-grid {
            display: grid;
            grid-template-columns: minmax(0, 1.45fr) minmax(280px, 1fr);
            align-items: start;
            gap: 18px;

            margin-bottom: 19px;
        }

        .panel {
            min-width: 0;
            padding: 20px;
            margin-bottom: 19px;

            border: 1px solid var(--line);
            border-radius: 15px;

            background: var(--white);
            box-shadow: var(--shadow);
        }

        .panel-wide {
            width: 100%;
        }

        .panel h3 {
            margin: 0;

            color: var(--navy);
            font-family: 'Manrope', sans-serif;
            font-size: 15px;
            font-weight: 800;
        }

        .panel-heading {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;

            margin-bottom: 17px;
        }

        .text-link {
            color: var(--green);
            font-size: 11px;
            font-weight: 700;
            white-space: nowrap;
        }

        .text-link:hover {
            color: var(--green-dark);
            text-decoration: underline;
        }

        /* =========================================
   TABLES
========================================= */

        .table-wrap {
            width: 100%;
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
        }

        .table-wrap table {
            width: 100%;
            border-collapse: collapse;
            text-align: left;
            white-space: nowrap;
        }

        .table-wrap th {
            padding: 12px 10px;

            border-bottom: 1px solid var(--line);

            color: var(--muted);
            font-size: 9px;
            font-weight: 700;
            letter-spacing: 1px;
        }

        .table-wrap td {
            padding: 14px 10px;

            border-bottom: 1px solid #eef2ef;

            color: #53645c;
            font-size: 11px;
        }

        .table-wrap td strong {
            color: var(--text);
        }

        .table-wrap tbody tr:last-child td {
            border-bottom: 0;
        }

        .table-wrap tbody tr:hover {
            background: #f8fbf9;
        }

        /* =========================================
   STATUS PILLS
========================================= */

        .pill {
            display: inline-block;
            padding: 5px 9px;

            border-radius: 20px;

            font-size: 9px;
            font-weight: 700;
        }

        .pill-green {
            background: var(--mint);
            color: var(--green-dark);
        }

        /* =========================================
   QUICK ACTIONS
========================================= */

        .quick-actions {
            display: grid;
            gap: 5px;
        }

        .quick-actions a {
            min-width: 0;

            display: flex;
            align-items: center;
            gap: 11px;

            padding: 11px 5px;

            border-bottom: 1px solid #edf1ee;

            transition: background 0.2s ease;
        }

        .quick-actions a:hover {
            background: #f8fbf9;
        }

        .quick-actions a:last-child {
            border-bottom: 0;
        }

        .action-icon {
            width: 36px;
            height: 36px;
            flex-shrink: 0;

            display: grid;
            place-items: center;

            border-radius: 10px;
            background: var(--mint);
            color: var(--green);

            font-size: 16px;
        }

        .quick-actions strong {
            display: block;
            color: var(--navy);
            font-size: 11px;
        }

        .quick-actions small {
            display: block;
            margin-top: 4px;

            color: var(--muted);
            font-size: 10px;
            line-height: 1.5;
        }

        .quick-actions b {
            margin-left: auto;
            color: var(--muted);
            font-size: 18px;
            font-weight: 400;
        }

        /* =========================================
   EMPTY STATES
========================================= */

        .empty-state {
            min-height: 140px;

            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;

            padding: 20px;

            color: var(--muted);
            text-align: center;
        }

        .empty-state strong {
            color: var(--navy);
            font-size: 12px;
        }

        .empty-state p {
            max-width: 270px;
            font-size: 11px;
            line-height: 1.6;
        }

        .empty-state.compact {
            min-height: 70px;
        }

        /* =========================================
   FOOTER
========================================= */

        .footer {
            padding: 17px;

            color: var(--muted);
            text-align: center;
            font-size: 10px;
        }

        /* =========================================
   RESPONSIVE
========================================= */

        @media (max-width: 1100px) {
            .metric-grid {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }

            .section-grid {
                grid-template-columns: 1fr;
            }

            .content {
                padding-left: 24px;
                padding-right: 24px;
            }
        }

        @media (max-width: 760px) {
            .app-shell>.sidebar {
                width: 248px;
                transform: translateX(-100%);
            }

            .app-shell>.main-area {
                width: 100%;
                margin-left: 0;
            }

            .topbar {
                min-height: 75px;
                padding: 15px;
            }

            .topbar h1 {
                font-size: 19px;
            }

            .content {
                padding: 19px 13px;
            }

            .welcome-row {
                align-items: flex-start;
                flex-direction: column;
            }

            .metric-grid {
                gap: 10px;
            }

            .metric-card {
                padding: 13px;
            }

            .metric-number {
                font-size: 24px;
            }

            .panel {
                padding: 14px;
            }

            .topbar-right {
                gap: 7px;
                font-size: 10px;
            }
        }

        @media (max-width: 400px) {
            .metric-grid {
                grid-template-columns: 1fr;
            }

            .topbar h1 {
                font-size: 17px;
            }
        }
    </style>

</head>

<body>
    <div class="app-shell">

        <aside class="sidebar">
            <a href="index.php" class="brand">
                <span class="logo small-logo">DF</span>
                <span>DairyFlow<small>FARM MANAGEMENT</small></span>
            </a>

            <p class="nav-label">WORKSPACE</p>
            <nav>
                <a class="active" href="index.php">▦ &nbsp; Dashboard</a>

                <?php if (in_array($role, ['super_admin', 'admin', 'farm_manager'], true)): ?>
                    <a href="cows.php">♧ &nbsp; Cows & Records</a>
                    <a href="milk.php">◉ &nbsp; Milk Production</a>
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
                <div class="user-chip">
                    <div class="avatar"><?= e(strtoupper(substr($_SESSION['full_name'], 0, 1))) ?></div>
                    <div>
                        <strong><?= e($_SESSION['full_name']) ?></strong>
                        <small><?= e(ucwords(str_replace('_', ' ', $role))) ?></small>
                    </div>
                </div>
                <a class="nav-link" href="change_password.php">
                    <span class="nav-icon">🔐</span>
                    Change Password
                </a>
                <a class="logout" href="logout.php">↪ &nbsp; Sign out</a>
            </div>
        </aside>

        <main class="main-area">
            <header class="topbar">
                <div>
                    <span class="eyebrow">DAIRY FARM MANAGEMENT</span>
                    <h1><?= e($pageTitle) ?></h1>
                </div>
                <div class="topbar-right">
                    <span class="status-dot"></span> System online
                    <span class="top-avatar"><?= e(strtoupper(substr($_SESSION['full_name'], 0, 1))) ?></span>
                </div>
            </header>

            <div class="content">
                <div class="welcome-row">
                    <div>
                        <span class="eyebrow">YOUR FARM AT A GLANCE</span>
                        <h2>Hello, <?= e($firstName) ?> <span class="wave">✳</span></h2>
                        <p class="muted">Here is the latest overview of your operations.</p>
                    </div>
                    <div class="date-chip"><?= date('D, d M Y') ?></div>
                </div>

                <div class="metric-grid">
                    <article class="metric-card">
                        <div class="metric-top"><span class="metric-icon green">♧</span><span class="metric-label">REGISTERED COWS</span></div>
                        <div class="metric-number"><?= number_format($cowCount) ?></div>
                        <p class="metric-foot">Cows in your access scope</p>
                    </article>

                    <article class="metric-card">
                        <div class="metric-top"><span class="metric-icon mint">◉</span><span class="metric-label">MILK TODAY</span></div>
                        <div class="metric-number"><?= number_format($milkToday, 1) ?><small> L</small></div>
                        <p class="metric-foot">Today's recorded production</p>
                    </article>

                    <article class="metric-card">
                        <div class="metric-top"><span class="metric-icon navy">♡</span><span class="metric-label">ACTIVE HEALTH CASES</span></div>
                        <div class="metric-number"><?= number_format($healthCount) ?></div>
                        <p class="metric-foot">Ongoing or under treatment</p>
                    </article>

                    <article class="metric-card">
                        <div class="metric-top"><span class="metric-icon lime">▤</span><span class="metric-label">FEED STOCK</span></div>
                        <div class="metric-number"><?= number_format($feedStock, 1) ?></div>
                        <p class="metric-foot">Combined stock quantities</p>
                    </article>

                </div>

                <?php if ($role === 'store_manager'): ?>
                    <div class="section-grid">
                        <section class="panel panel-wide">
                            <h3>Store manager workspace</h3>
                            <p class="muted">Manage feed inventory, milk stock movements, calf allocations and inventory reports.</p>
                            <div class="quick-actions">
                                <a href="feeds.php"><span class="action-icon">▤</span><span><strong>Manage feed stock</strong><small>Record purchases and feed usage</small></span><b>›</b></a>
                                <a href="milk.php"><span class="action-icon">◉</span><span><strong>Review milk records</strong><small>Check production and allocations</small></span><b>›</b></a>
                                <a href="reports.php"><span class="action-icon">▥</span><span><strong>Inventory reports</strong><small>Review available stock and movements</small></span><b>›</b></a>
                            </div>
                        </section>
                    </div>
                <?php else: ?>
                    <div class="section-grid">
                        <section class="panel panel-wide">
                            <div class="panel-heading">
                                <div>
                                    <h3><?= $isSuperAdmin ? 'Farm performance' : 'Farm overview' ?></h3>
                                    <p class="muted">Cow counts and milk recorded today</p>
                                </div>
                                <a class="text-link" href="reports.php">View reports ↗</a>
                            </div>

                            <?php if (!$farms): ?>
                                <div class="empty-state"><strong>No farm records yet</strong>
                                    <p>Create a farm before registering cows and production.</p>
                                </div>
                            <?php else: ?>
                                <div class="table-wrap">
                                    <table>
                                        <thead>
                                            <tr>
                                                <th>FARM</th>
                                                <th>COWS</th>
                                                <th>MILK TODAY</th>
                                                <th>STATUS</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($farms as $farm): ?>
                                                <tr>
                                                    <td><strong><?= e($farm['farm_name']) ?></strong></td>
                                                    <td><?= number_format((int)$farm['cow_count']) ?></td>
                                                    <td><?= number_format((float)$farm['milk_today'], 1) ?> L</td>
                                                    <td><span class="pill pill-green">Active</span></td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            <?php endif; ?>
                        </section>

                        <section class="panel">
                            <div class="panel-heading">
                                <div>
                                    <h3>Quick actions</h3>
                                    <p class="muted">Common daily tasks</p>
                                </div>
                            </div>
                            <div class="quick-actions">
                                <a href="cows.php"><span class="action-icon">♧</span><span><strong>Register a cow</strong><small>Add identification and breed</small></span><b>›</b></a>
                                <a href="milk.php"><span class="action-icon">◉</span><span><strong>Record milk</strong><small>Log a milking session</small></span><b>›</b></a>
                                <a href="health.php"><span class="action-icon">♡</span><span><strong>Health record</strong><small>Track health and vaccination</small></span><b>›</b></a>
                                <?php if (in_array($role, ['super_admin', 'admin', 'store_manager'], true)): ?>
                                    <a href="feeds.php"><span class="action-icon">🌾</span><span><strong>Feed Inventory</strong><small>Track inventory</small></span><b>›</b></a>

                                <?php endif; ?>

                                <a href="reports.php"><span class="action-icon">▥</span><span><strong>Reports</strong><small>View production summaries</small></span><b>›</b></a>
                            </div>
                        </section>
                    </div>
                <?php endif; ?>


                <?php if ($role === 'admin'): ?>
                    <section class="panel" style="margin-bottom: 24px;">
                        <div class="panel-heading">
                            <div>
                                <h3>Farm Manager Management</h3>
                                <p class="muted">
                                    Create and manage the staff responsible for your farm.
                                </p>
                            </div>
                            <span class="metric-icon green">♙</span>
                        </div>

                        <div class="quick-actions">
                            <a href="users.php">
                                <span class="action-icon">＋</span>
                                <span>
                                    <strong>Add Farm Manager</strong>
                                    <small>Create an account and assign it to your farm</small>
                                </span>
                                <b>›</b>
                            </a>

                            <a href="users.php">
                                <span class="action-icon">♙</span>
                                <span>
                                    <strong>View Farm Managers</strong>
                                    <small>View the users assigned to your farm</small>
                                </span>
                                <b>›</b>
                            </a>
                        </div>
                    </section>
                <?php endif; ?>




                <section class="panel recent-panel">
                    <div class="panel-heading">
                        <div>
                            <h3>Recent milk records</h3>
                            <p class="muted">Latest recorded production</p>
                        </div>
                        <a class="text-link" href="milk.php">All records ↗</a>
                    </div>

                    <?php if (!$recentMilk): ?>
                        <div class="empty-state compact">
                            <p>No milk records have been entered yet.</p>
                        </div>
                    <?php else: ?>
                        <div class="table-wrap">
                            <table>
                                <thead>
                                    <tr>
                                        <th>DATE</th><?php if ($isSuperAdmin): ?><th>FARM</th><?php endif; ?><th>COW / TAG</th>
                                        <th>SESSION</th>
                                        <th>QUANTITY</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($recentMilk as $record): ?>
                                        <tr>
                                            <td><?= e($record['production_date']) ?></td>
                                            <?php if ($isSuperAdmin): ?><td><?= e($record['farm_name']) ?></td><?php endif; ?>
                                            <td><?= e($record['cow_name'] ?: $record['tag_number'] ?: 'Farm total') ?></td>
                                            <td><?= e(ucfirst($record['milking_session'])) ?></td>
                                            <td><strong><?= number_format((float)$record['quantity_litres'], 1) ?> L</strong></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </section>
            </div>

            <footer class="footer">DairyFlow · Farm operations, made clearer.</footer>
        </main>
    </div>
</body>

</html>