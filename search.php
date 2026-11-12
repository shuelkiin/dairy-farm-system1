<?php

declare(strict_types=1);

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';


requireLogin();
requireRole(['super_admin', 'admin', 'farm_manager']);

$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

$role = $_SESSION['role_name'];
$userId = (int) $_SESSION['user_id'];
$farmId = (int) ($_SESSION['farm_id'] ?? 0);
$isSuperAdmin = $role === 'super_admin';

$pageTitle = 'Cow Search';
$q = trim((string) ($_GET['q'] ?? ''));
$selectedId = filter_var(
    $_GET['cow_id'] ?? null,
    FILTER_VALIDATE_INT
);

$results = [];
$cow = null;
$mother = null;
$father = null;
$healthRecords = [];
$vaccinations = [];
$milkRecords = [];
$error = '';

/*
 * Build a permission condition for a cow table alias.
 * Every cow lookup uses this condition.
 */
function cowAccess(string $alias, string $role, int $farmId, int $userId): array
{
    if ($role === 'super_admin') {
        return ['1=1', []];
    }

    if ($farmId < 1) {
        return ['1=0', []];
    }

    if ($role === 'farm_manager') {
        return [
            "$alias.farm_id = ? AND $alias.manager_id = ?",
            [$farmId, $userId]
        ];
    }

    return ["$alias.farm_id = ?", [$farmId]];
}

/*
 * Load matching cows, limited to records the current user may see.
 */
try {
    if ($q !== '') {
        [$accessSql, $accessParams] = cowAccess(
            'c',
            $role,
            $farmId,
            $userId
        );

        $stmt = $pdo->prepare("
            SELECT c.cow_id, c.tag_number, c.cow_name,
                   c.breed, c.gender, c.status, f.farm_name
            FROM cows c
            INNER JOIN farms f ON f.farm_id = c.farm_id
            WHERE ($accessSql)
              AND (
                  c.cow_name LIKE ?
                  OR c.tag_number LIKE ?
              )
            ORDER BY c.cow_name, c.tag_number
            LIMIT 30
        ");

        $searchTerm = '%' . $q . '%';
        $stmt->execute(array_merge(
            $accessParams,
            [$searchTerm, $searchTerm]
        ));

        $results = $stmt->fetchAll();
    }

    /*
     * Load the selected cow independently.
     * A cow_id in the URL does not bypass permissions.
     */
    if ($selectedId !== false && $selectedId !== null && $selectedId > 0) {
        [$accessSql, $accessParams] = cowAccess(
            'c',
            $role,
            $farmId,
            $userId
        );

        $stmt = $pdo->prepare("
            SELECT c.*, f.farm_name
            FROM cows c
            INNER JOIN farms f ON f.farm_id = c.farm_id
            WHERE c.cow_id = ?
              AND ($accessSql)
            LIMIT 1
        ");

        $stmt->execute(array_merge([$selectedId], $accessParams));
        $cow = $stmt->fetch() ?: null;

        if (!$cow) {
            $error = 'Cow not found, or you do not have permission to view it.';
        }
    }

    if ($cow) {
        /*
         * Parents are displayed only if the current user can also
         * access their records.
         */
        foreach (['mother_id' => 'mother', 'father_id' => 'father'] as $field => $key) {
            $parentId = (int) ($cow[$field] ?? 0);

            if ($parentId > 0) {
                [$parentAccess, $parentParams] = cowAccess(
                    'p',
                    $role,
                    $farmId,
                    $userId
                );

                $stmt = $pdo->prepare("
                    SELECT p.cow_id, p.cow_name, p.tag_number,
                           p.breed, p.gender
                    FROM cows p
                    WHERE p.cow_id = ?
                      AND ($parentAccess)
                    LIMIT 1
                ");

                $stmt->execute(array_merge([$parentId], $parentParams));
                $$key = $stmt->fetch() ?: null;
            }
        }

        /*
         * These records are retrieved only after the cow itself
         * has passed the permission check above.
         */
        $stmt = $pdo->prepare("
            SELECT health_id, condition_name, symptoms, treatment,
                   veterinarian, record_date, next_checkup, status
            FROM health_records
            WHERE cow_id = ?
            ORDER BY record_date DESC, health_id DESC
        ");
        $stmt->execute([(int) $cow['cow_id']]);
        $healthRecords = $stmt->fetchAll();

        $stmt = $pdo->prepare("
            SELECT vaccination_id, vaccine_name, vaccination_date,
                   next_due_date, administered_by, notes
            FROM vaccinations
            WHERE cow_id = ?
            ORDER BY vaccination_date DESC, vaccination_id DESC
        ");
        $stmt->execute([(int) $cow['cow_id']]);
        $vaccinations = $stmt->fetchAll();

        $stmt = $pdo->prepare("
            SELECT production_date, milking_session,
                   quantity_litres, notes
            FROM milk_production
            WHERE cow_id = ?
              AND farm_id = ?
           ORDER BY production_date DESC, production_id DESC
        ");
        $stmt->execute([
            (int) $cow['cow_id'],
            (int) $cow['farm_id']
        ]);
        $milkRecords = $stmt->fetchAll();

        // Prepare daily milk production chart data
        $dailyMilk = [];

        foreach ($milkRecords as $record) {
            $date = $record['production_date'];

            if (!isset($dailyMilk[$date])) {
                $dailyMilk[$date] = 0;
            }

            $dailyMilk[$date] += (float) $record['quantity_litres'];
        }

        // Sort dates from oldest to newest
        ksort($dailyMilk);

        $chartLabels = array_keys($dailyMilk);
        $chartValues = array_map(
            fn($quantity) => round($quantity, 2),
            array_values($dailyMilk)
        );
    }
} catch (Throwable $exception) {
    error_log('Cow search error: ' . $exception->getMessage());
    $error = 'Something went wrong while loading cow records. Please try again.';
}

function searchEscape(mixed $value): string
{
    return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES, 'UTF-8');
}

function displayDate(?string $date): string
{
    if (!$date || $date === '0000-00-00') {
        return 'Not recorded';
    }

    return date('d M Y', strtotime($date));
}

$dashboardUrl = match ($role) {
    'super_admin' => 'super_admin.php',
    'admin' => 'admin.php',
    'farm_manager' => 'farm_manager.php',
    default => 'index.php'
};
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= searchEscape($pageTitle) ?> | DairyFlow</title>
    <link rel="stylesheet" href="assets/styles.css">
    <style>
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

        * {
            box-sizing: border-box;
        }

        html,
        body {
            margin: 0;
            min-height: 100%;
        }

        body {
            background: var(--bg);
            color: var(--text);
            font-family: 'DM Sans', Arial, sans-serif;
            font-size: 14px;
        }

        /* Main dashboard layout */
        .app-shell {
            display: flex;
            min-height: 100vh;
            width: 100%;
        }

        .app-shell>.sidebar {
            position: fixed;
            top: 0;
            left: 0;
            bottom: 0;
            z-index: 1000;
            display: flex;
            flex-direction: column;
            width: 248px;
            height: 100vh;
            padding: 26px 15px 18px;
            overflow-y: auto;
            background: var(--navy);
            color: var(--white);
        }

        .sidebar .brand {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 0 9px;
            margin-bottom: 36px;
            color: var(--white);
            font-family: 'Manrope', sans-serif;
            font-size: 18px;
            font-weight: 800;
            text-decoration: none;
        }

        .sidebar .brand small {
            display: block;
            margin-top: 3px;
            color: #a7c8b6;
            font-size: 9px;
            font-weight: 700;
            letter-spacing: 1.2px;
        }

        .sidebar .logo {
            display: flex;
            align-items: center;
            justify-content: center;
            width: 42px;
            height: 42px;
            flex-shrink: 0;
            border-radius: 12px;
            background: var(--green);
            color: white;
            font-size: 15px;
            font-weight: 800;
        }

        .sidebar .nav-label {
            padding: 0 12px;
            margin: 0 0 12px;
            color: #83a596;
            font-size: 10px;
            font-weight: 800;
            letter-spacing: 1.5px;
        }

        .sidebar nav {
            display: flex;
            flex-direction: column;
            gap: 5px;
        }

        .sidebar nav a,
        .sidebar .nav-link,
        .sidebar .logout {
            display: flex;
            align-items: center;
            gap: 10px;
            min-height: 43px;
            padding: 11px 13px;
            border-radius: 9px;
            color: #c2d4ca;
            font-size: 13px;
            font-weight: 600;
            text-decoration: none;
            transition: background .2s, color .2s;
        }

        .sidebar nav a:hover,
        .sidebar .nav-link:hover,
        .sidebar .logout:hover {
            background: var(--navy-light);
            color: white;
        }

        .sidebar nav a.active {
            background: var(--green);
            color: white;
        }

        .sidebar-bottom {
            margin-top: auto;
            padding-top: 20px;
            border-top: 1px solid rgba(255, 255, 255, .10);
        }

        .user-chip {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 10px 7px 15px;
            min-width: 0;
        }

        .user-chip .avatar {
            display: flex;
            align-items: center;
            justify-content: center;
            width: 35px;
            height: 35px;
            flex-shrink: 0;
            border-radius: 50%;
            background: #d5eee0;
            color: var(--navy);
            font-weight: 800;
        }

        .user-chip strong {
            display: block;
            color: white;
            font-size: 12px;
            overflow-wrap: anywhere;
        }

        .user-chip small {
            display: block;
            margin-top: 4px;
            color: #a7c8b6;
            font-size: 10px;
        }

        .sidebar .logout {
            color: #f1b8b8;
        }

        /* Main content and topbar */
        .app-shell>.main-area {
            width: calc(100% - 248px);
            min-width: 0;
            min-height: 100vh;
            margin-left: 248px;
            background: var(--bg);
        }

        .topbar {
            position: sticky;
            top: 0;
            z-index: 100;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 20px;
            min-height: 83px;
            padding: 15px 36px;
            border-bottom: 1px solid var(--line);
            background: rgba(255, 255, 255, .96);
        }

        .eyebrow {
            display: block;
            margin-bottom: 5px;
            color: var(--green);
            font-size: 10px;
            font-weight: 800;
            letter-spacing: 1.4px;
        }

        .topbar h1 {
            margin: 0;
            color: var(--navy);
            font-family: 'Manrope', sans-serif;
            font-size: 23px;
            font-weight: 800;
        }

        .topbar-right {
            display: flex;
            align-items: center;
            gap: 9px;
            color: var(--muted);
            font-size: 12px;
            font-weight: 600;
        }

        .status-dot {
            width: 8px;
            height: 8px;
            border-radius: 50%;
            background: #27a66b;
        }

        .top-avatar {
            display: flex;
            align-items: center;
            justify-content: center;
            width: 34px;
            height: 34px;
            margin-left: 10px;
            border-radius: 50%;
            background: var(--mint);
            color: var(--green-dark);
            font-weight: 800;
        }

        .content {
            width: 100%;
            max-width: 1600px;
            padding: 29px 36px 36px;
            margin: 0 auto;
        }

        /* Cow search components */
        .search-wrap {
            width: 100%;
            max-width: 1600px;
            margin: 0 auto;
        }

        .search-panel,
        .profile-panel,
        .record-panel {
            padding: 24px;
            margin-bottom: 20px;
            border: 1px solid var(--line);
            border-radius: 15px;
            background: var(--white);
            box-shadow: var(--shadow);
        }

        .search-panel h2,
        .profile-panel h2,
        .record-panel h3,
        .profile-panel h3 {
            color: var(--navy);
            font-family: 'Manrope', sans-serif;
            font-weight: 750;
        }

        .search-panel h2 {
            margin: 0 0 8px;
            font-size: 20px;
        }

        .muted {
            color: var(--muted);
            font-size: 13px;
            line-height: 1.6;
        }

        .search-form {
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
            margin-top: 18px;
        }

        .search-form input {
            flex: 1;
            min-width: 220px;
            padding: 14px 16px;
            border: 1px solid #dce6df;
            border-radius: 10px;
            outline: none;
            background: #fbfdfb;
            color: var(--text);
            font: inherit;
            font-size: 14px;
        }

        .search-form input:focus {
            border-color: var(--green);
            box-shadow: 0 0 0 3px rgba(35, 134, 91, .10);
        }

        .search-button {
            padding: 13px 23px;
            border: 0;
            border-radius: 10px;
            background: var(--green);
            color: white;
            font: inherit;
            font-weight: 700;
            cursor: pointer;
            transition: background .2s;
        }

        .search-button:hover {
            background: var(--green-dark);
        }

        .cow-results {
            display: grid;
            gap: 11px;
            margin-top: 18px;
        }

        .cow-result {
            display: block;
            padding: 17px 18px;
            border: 1px solid var(--line);
            border-radius: 12px;
            background: white;
            color: var(--text);
            text-decoration: none;
            transition: .2s;
        }

        .cow-result:hover {
            border-color: var(--green);
            background: #f8fcf9;
        }

        .cow-result strong {
            color: var(--green-dark);
            font-size: 15px;
        }

        .cow-result .muted {
            margin: 7px 0 4px;
        }

        .tag {
            display: inline-flex;
            align-items: center;
            padding: 6px 11px;
            border-radius: 30px;
            background: var(--mint);
            color: var(--green-dark);
            font-size: 11px;
            font-weight: 700;
        }

        .profile-heading {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 15px;
        }

        .profile-heading h2 {
            margin: 4px 0 10px;
            font-size: 22px;
        }

        .detail-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(175px, 1fr));
            gap: 13px;
            margin-top: 22px;
        }

        .detail-item {
            min-width: 0;
            padding: 16px;
            overflow-wrap: anywhere;
            border: 1px solid #edf2ee;
            border-radius: 11px;
            background: #f7faf8;
        }

        .detail-item small {
            display: block;
            margin-bottom: 7px;
            color: var(--muted);
            font-size: 12px;
            font-weight: 600;
        }

        .detail-item strong {
            color: var(--navy);
            font-size: 14px;
        }

        .record-panel h3 {
            margin: 0 0 18px;
            font-size: 17px;
        }

        .profile-panel h2 {
            margin-top: 0;
        }

        .record-table {
            width: 100%;
            min-width: 600px;
            border-collapse: collapse;
        }

        .table-scroll {
            width: 100%;
            overflow-x: auto;
            border-radius: 10px;
        }

        .record-table th,
        .record-table td {
            padding: 14px 15px;
            border-bottom: 1px solid var(--line);
            text-align: left;
            vertical-align: top;
            font-size: 13px;
        }

        .record-table th {
            background: #f5f8f6;
            color: #64776c;
            font-size: 11px;
            font-weight: 750;
            letter-spacing: .5px;
            text-transform: uppercase;
            white-space: nowrap;
        }

        .record-table td {
            color: var(--text);
        }

        .record-table tbody tr:hover {
            background: #f9fcfa;
        }

        .empty-state {
            padding: 18px 0;
            color: var(--muted);
            font-size: 14px;
        }

        .notice {
            padding: 15px 17px;
            margin-bottom: 18px;
            border: 1px solid #f2dfc2;
            border-radius: 11px;
            background: #fff8ed;
            color: #874c00;
            font-size: 14px;
        }

        .family-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 14px;
        }

        .family-card {
            padding: 17px;
            border: 1px solid var(--line);
            border-radius: 12px;
            background: #fbfdfb;
        }

        .family-card strong {
            color: var(--navy);
        }

        .back-link {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            margin-bottom: 18px;
            color: var(--green-dark);
            font-size: 13px;
            font-weight: 700;
            text-decoration: none;
        }

        .back-link:hover {
            color: var(--navy);
        }

        /* Tablet and mobile */
        @media (max-width: 900px) {
            .content {
                padding: 24px 20px;
            }

            .topbar {
                padding: 15px 20px;
            }
        }

        @media (max-width: 760px) {
            .app-shell>.sidebar {
                transform: translateX(-100%);
            }

            .app-shell>.main-area {
                width: 100%;
                margin-left: 0;
            }

            .topbar {
                min-height: 72px;
            }

            .topbar h1 {
                font-size: 20px;
            }

            .content {
                padding: 20px 15px;
            }
        }

        @media (max-width: 600px) {

            .search-panel,
            .profile-panel,
            .record-panel {
                padding: 17px;
                border-radius: 12px;
            }

            .search-form {
                flex-direction: column;
            }

            .search-form input,
            .search-button {
                width: 100%;
                min-width: 0;
            }

            .profile-heading h2 {
                font-size: 19px;
            }

            .detail-grid {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }

            .topbar-right {
                font-size: 0;
            }

            .topbar-right .status-dot,
            .topbar-right .top-avatar {
                font-size: 12px;
            }
        }

        @media (max-width: 380px) {
            .detail-grid {
                grid-template-columns: 1fr;
            }
        }


        /* MOBILE SIDEBAR MENU */
        .mobile-menu-btn {
            display: none;
            align-items: center;
            justify-content: center;
            width: 44px;
            height: 44px;
            margin: 12px 0;
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
            .app-shell>.sidebar {
                position: fixed;
                top: 0;
                left: 0;
                bottom: 0;
                width: 248px;
                height: 100vh;
                height: 100dvh;
                z-index: 1001;
                transform: translateX(-100%);
                transition: transform 0.3s ease;
                overflow-y: auto;
            }

            .app-shell>.sidebar.active {
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

            .app-shell>.main-area {
                width: 100%;
                margin-left: 0;
            }

            .topbar {
                padding: 12px 15px;
            }
        }
    </style>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
</head>

<body>
    <div class="app-shell">
        <aside class="sidebar">
            <a href="<?= searchEscape($dashboardUrl) ?>" class="brand">
                <span class="logo small-logo">MF</span>
                <span>MwendAntu<small>FARM MANAGEMENT</small></span>
            </a>
            <p class="nav-label">WORKSPACE</p>
            <nav>
                <a href="index.php">▦ &nbsp; Dashboard</a>

                <?php if (in_array($role, ['super_admin', 'admin', 'farm_manager'], true)): ?>
                    <a href="cows.php">♧ &nbsp; Cows & Records</a>
                    <a href="milk.php">◉ &nbsp; Milk Production</a>
                    <a class="active" href="search.php">🔍 &nbsp; Search cow</a>

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
                    <div class="avatar"><?= searchEscape(strtoupper(substr((string) ($_SESSION['full_name'] ?? 'U'), 0, 1))) ?></div>
                    <div>
                        <strong><?= searchEscape($_SESSION['full_name'] ?? 'User') ?></strong>
                        <small><?= searchEscape(ucwords(str_replace('_', ' ', $role))) ?></small>
                    </div>
                </div>
                <a class="nav-link" href="change_password.php">🔐 Change Password</a>
                <a class="logout" href="logout.php">↪ Sign out</a>
            </div>
        </aside>
        <div class="sidebar-overlay" id="sidebarOverlay"></div>
        <main class="main-area">
            <button type="button" class="mobile-menu-btn" id="mobileMenuBtn">
                ☰
            </button>
            <header class="topbar">
                <div>
                    <span class="eyebrow">DAIRY FARM MANAGEMENT</span>
                    <h1><?= searchEscape($pageTitle) ?></h1>
                </div>

                <div class="topbar-right">
                    <span class="status-dot"></span>
                    System online
                    <span class="top-avatar">
                        <?= searchEscape(strtoupper(substr($_SESSION['full_name'] ?? 'U', 0, 1))) ?>
                    </span>
                </div>
            </header>

            <div class="content">
                <div class="search-wrap">
                    <section class="search-panel">
                        <span class="eyebrow">HERD RECORDS</span>
                        <h2>Find a cow</h2>
                        <p class="muted">Search using a cow's name or unique tag number.</p>

                        <form class="search-form" method="get" action="search.php">
                            <input
                                type="search"
                                name="q"
                                value="<?= searchEscape($q) ?>"
                                placeholder="Enter cow name or tag number..."
                                aria-label="Cow name or tag number"
                                required>
                            <button class="search-button" type="submit">Search cows</button>
                        </form>
                    </section>

                    <?php if ($error !== ''): ?>
                        <div class="notice"><?= searchEscape($error) ?></div>
                    <?php endif; ?>

                    <?php if ($q !== ''): ?>
                        <section class="record-panel">
                            <h3>Search results</h3>
                            <?php if (!$results): ?>
                                <p class="empty-state">No matching cows were found in your access scope.</p>
                            <?php else: ?>
                                <p class="muted"><?= count($results) ?> result(s), up to 30 shown.</p>
                                <div class="cow-results">
                                    <?php foreach ($results as $result): ?>
                                        <a class="cow-result"
                                            href="search.php?cow_id=<?= (int) $result['cow_id'] ?>&amp;q=<?= urlencode($q) ?>">
                                            <strong><?= searchEscape($result['cow_name'] ?: 'Unnamed cow') ?></strong>
                                            <span class="tag"><?= searchEscape($result['tag_number']) ?></span>
                                            <div class="muted">
                                                <?= searchEscape($result['breed'] ?: 'Breed not recorded') ?>
                                                · <?= searchEscape(ucfirst($result['gender'])) ?>
                                                · <?= searchEscape(ucfirst($result['status'] ?? 'Unknown')) ?>
                                            </div>
                                            <small><?= searchEscape($result['farm_name']) ?></small>
                                        </a>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </section>
                    <?php endif; ?>

                    <?php if ($cow): ?>
                        <section class="profile-panel">
                            <div class="profile-heading">
                                <div>
                                    <span class="eyebrow">COW PROFILE</span>
                                    <h2><?= searchEscape($cow['cow_name'] ?: 'Unnamed cow') ?></h2>
                                    <span class="tag">Tag: <?= searchEscape($cow['tag_number']) ?></span>
                                </div>
                                <a class="back-link" href="search.php<?= $q !== '' ? '?q=' . urlencode($q) : '' ?>">← Back to search</a>
                            </div>

                            <div class="detail-grid">
                                <div class="detail-item"><small>Farm</small><strong><?= searchEscape($cow['farm_name']) ?></strong></div>
                                <div class="detail-item"><small>Date of birth</small><strong><?= searchEscape(displayDate($cow['date_of_birth'])) ?></strong></div>
                                <div class="detail-item"><small>Breed</small><strong><?= searchEscape($cow['breed'] ?: 'Not recorded') ?></strong></div>
                                <div class="detail-item"><small>Gender</small><strong><?= searchEscape(ucfirst($cow['gender'])) ?></strong></div>
                                <div class="detail-item"><small>Origin</small><strong><?= searchEscape(ucwords(str_replace('_', ' ', $cow['origin']))) ?></strong></div>
                                <div class="detail-item"><small>Status</small><strong><?= searchEscape(ucfirst($cow['status'] ?? 'Unknown')) ?></strong></div>
                                <div class="detail-item"><small>Age</small>
                                    <strong>
                                        <?php
                                        $birth = new DateTimeImmutable($cow['date_of_birth']);
                                        $today = new DateTimeImmutable('today');
                                        echo searchEscape($birth->diff($today)->y . ' years, ' . $birth->diff($today)->m . ' months');
                                        ?>
                                    </strong>
                                </div>
                            </div>

                            <?php if (!empty($cow['notes'])): ?>
                                <h3>Additional notes</h3>
                                <p><?= nl2br(searchEscape($cow['notes'])) ?></p>
                            <?php endif; ?>
                        </section>

                        <section class="record-panel">
                            <h3>Family tree</h3>
                            <div class="family-grid">
                                <div class="family-card">
                                    <strong>Mother</strong>
                                    <?php if ($mother): ?>
                                        <p><?= searchEscape($mother['cow_name'] ?: 'Unnamed cow') ?></p>
                                        <small>Tag: <?= searchEscape($mother['tag_number']) ?></small>
                                        <?php if ($mother['cow_id'] !== $cow['cow_id']): ?>
                                            <p><a class="back-link" href="search.php?cow_id=<?= (int) $mother['cow_id'] ?>">View profile</a></p>
                                        <?php endif; ?>
                                    <?php elseif (!empty($cow['mother_id'])): ?>
                                        <p class="empty-state">Recorded parent is outside your access scope.</p>
                                    <?php else: ?>
                                        <p class="empty-state">Mother not recorded.</p>
                                    <?php endif; ?>
                                </div>
                                <div class="family-card">
                                    <strong>Father</strong>
                                    <?php if ($father): ?>
                                        <p><?= searchEscape($father['cow_name'] ?: 'Unnamed cow') ?></p>
                                        <small>Tag: <?= searchEscape($father['tag_number']) ?></small>
                                        <?php if ($father['cow_id'] !== $cow['cow_id']): ?>
                                            <p><a class="back-link" href="search.php?cow_id=<?= (int) $father['cow_id'] ?>">View profile</a></p>
                                        <?php endif; ?>
                                    <?php elseif (!empty($cow['father_id'])): ?>
                                        <p class="empty-state">Recorded parent is outside your access scope.</p>
                                    <?php else: ?>
                                        <p class="empty-state">Father not recorded.</p>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </section>

                        <section class="record-panel">
                            <h3>Health history</h3>
                            <?php if (!$healthRecords): ?>
                                <p class="empty-state">No health records found for this cow.</p>
                            <?php else: ?>
                                <div class="table-scroll">
                                    <table class="record-table">
                                        <thead>
                                            <tr>
                                                <th>Date</th>
                                                <th>Condition</th>
                                                <th>Symptoms</th>
                                                <th>Treatment</th>
                                                <th>Veterinarian</th>
                                                <th>Next checkup</th>
                                                <th>Status</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($healthRecords as $record): ?>
                                                <tr>
                                                    <td><?= searchEscape(displayDate($record['record_date'])) ?></td>
                                                    <td><?= searchEscape($record['condition_name']) ?></td>
                                                    <td><?= searchEscape($record['symptoms'] ?: 'Not recorded') ?></td>
                                                    <td><?= searchEscape($record['treatment'] ?: 'Not recorded') ?></td>
                                                    <td><?= searchEscape($record['veterinarian'] ?: 'Not recorded') ?></td>
                                                    <td><?= searchEscape(displayDate($record['next_checkup'])) ?></td>
                                                    <td><?= searchEscape(ucwords(str_replace('_', ' ', $record['status'] ?? 'Unknown'))) ?></td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            <?php endif; ?>
                        </section>

                        <section class="record-panel">
                            <h3>Vaccination history</h3>
                            <?php if (!$vaccinations): ?>
                                <p class="empty-state">No vaccination records found for this cow.</p>
                            <?php else: ?>
                                <div class="table-scroll">
                                    <table class="record-table">
                                        <thead>
                                            <tr>
                                                <th>Vaccine</th>
                                                <th>Date given</th>
                                                <th>Next due</th>
                                                <th>Administered by</th>
                                                <th>Notes</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($vaccinations as $record): ?>
                                                <tr>
                                                    <td><?= searchEscape($record['vaccine_name']) ?></td>
                                                    <td><?= searchEscape(displayDate($record['vaccination_date'])) ?></td>
                                                    <td><?= searchEscape(displayDate($record['next_due_date'])) ?></td>
                                                    <td><?= searchEscape($record['administered_by'] ?: 'Not recorded') ?></td>
                                                    <td><?= searchEscape($record['notes'] ?: 'None') ?></td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            <?php endif; ?>
                        </section>



                        <section class="record-panel">
                            <h3>Milk Production Trend</h3>
                            <p class="muted">
                                Daily milk production from the first recorded milking date.
                            </p>

                            <?php if (!$milkRecords): ?>
                                <p class="empty-state">No milk production data available for this cow.</p>
                            <?php else: ?>
                                <div style="position: relative; width: 100%; height: 350px;">
                                    <canvas id="milkProductionChart"></canvas>
                                </div>
                            <?php endif; ?>
                        </section>


                        <section class="record-panel">
                            <h3>Milk production history</h3>
                            <?php if (!$milkRecords): ?>
                                <p class="empty-state">No milk production records found for this cow.</p>
                            <?php else: ?>
                                <div class="table-scroll">
                                    <table class="record-table">
                                        <thead>
                                            <tr>
                                                <th>Date</th>
                                                <th>Session</th>
                                                <th>Quantity (litres)</th>
                                                <th>Notes</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($milkRecords as $record): ?>
                                                <tr>
                                                    <td><?= searchEscape(displayDate($record['production_date'])) ?></td>
                                                    <td><?= searchEscape(ucfirst($record['milking_session'])) ?></td>
                                                    <td><?= number_format((float) $record['quantity_litres'], 2) ?> L</td>
                                                    <td><?= searchEscape($record['notes'] ?: 'None') ?></td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                                <p class="muted">Showing up to the 100 most recent records.</p>
                            <?php endif; ?>
                        </section>
                    <?php endif; ?>
                </div>
            </div>
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
    <script>
        const milkLabels = <?= json_encode($chartLabels) ?>;
        const milkValues = <?= json_encode($chartValues) ?>;

        const chartCanvas = document.getElementById('milkProductionChart');

        if (chartCanvas) {
            new Chart(chartCanvas, {
                type: 'bar',

                data: {
                    labels: milkLabels,

                    datasets: [{
                        label: 'Milk Produced (Litres)',
                        data: milkValues,
                        backgroundColor: '#23865b',
                        hoverBackgroundColor: '#176b46',
                        borderRadius: 6,
                        maxBarThickness: 45
                    }]
                },

                options: {
                    responsive: true,
                    maintainAspectRatio: false,

                    plugins: {
                        legend: {
                            display: true,
                            position: 'top'
                        },

                        tooltip: {
                            callbacks: {
                                label: function(context) {
                                    return context.parsed.y.toFixed(2) + ' Litres';
                                }
                            }
                        }
                    },

                    scales: {
                        y: {
                            beginAtZero: true,
                            title: {
                                display: true,
                                text: 'Milk Produced (Litres)'
                            }
                        },

                        x: {
                            title: {
                                display: true,
                                text: 'Production Date'
                            }
                        }
                    }
                }
            });
        }
    </script>
</body>

</html>