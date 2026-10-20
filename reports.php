<?php

declare(strict_types=1);

session_start();
require_once __DIR__ . '/config/database.php';

if (empty($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$db = $pdo ?? $conn ?? $mysqli ?? null;

if (!$db instanceof PDO && !$db instanceof mysqli) {
    exit('Database connection not found. Check config/database.php.');
}

function e(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function reportQuery(string $sql, array $params = []): array
{
    global $db;

    if ($db instanceof PDO) {
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    $stmt = $db->prepare($sql);

    if (!$stmt) {
        throw new RuntimeException('Unable to prepare report query.');
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

$userId = (int) $_SESSION['user_id'];

$users = reportQuery(
    "SELECT u.user_id, u.full_name, u.farm_id, r.role_name
     FROM users u
     JOIN roles r ON r.role_id = u.role_id
     WHERE u.user_id = ?
     LIMIT 1",
    [$userId]
);

if (!$users) {
    session_destroy();
    header('Location: login.php');
    exit;
}

$user = $users[0];
$role = $user['role_name'];
$isSuperAdmin = $role === 'super_admin';
$farmId = $user['farm_id'] === null ? null : (int) $user['farm_id'];

if (!in_array($role, ['super_admin', 'admin', 'farm_manager', 'store_manager'], true)) {
    http_response_code(403);
    exit('You do not have permission to view reports.');
}

if (!$isSuperAdmin && !$farmId) {
    exit('Your account is not assigned to a farm.');
}

$farms = $isSuperAdmin
    ? reportQuery("SELECT farm_id, farm_name FROM farms ORDER BY farm_name")
    : reportQuery(
        "SELECT farm_id, farm_name FROM farms WHERE farm_id = ?",
        [$farmId]
    );

$validFarmIds = array_map(
    static fn($farm) => (int) $farm['farm_id'],
    $farms
);

$selectedFarm = $isSuperAdmin
    ? (int) ($_GET['farm_id'] ?? 0)
    : (int) $farmId;

if ($isSuperAdmin && !in_array($selectedFarm, [0, ...$validFarmIds], true)) {
    $selectedFarm = 0;
}

$startDate = (string) ($_GET['start_date'] ?? date('Y-m-01'));
$endDate = (string) ($_GET['end_date'] ?? date('Y-m-d'));

$datePattern = '/^\d{4}-\d{2}-\d{2}$/';

if (
    !preg_match($datePattern, $startDate) ||
    !preg_match($datePattern, $endDate) ||
    !strtotime($startDate) ||
    !strtotime($endDate) ||
    $startDate > $endDate
) {
    $startDate = date('Y-m-01');
    $endDate = date('Y-m-d');
}

$farmSql = '';
$farmParams = [];

if ($selectedFarm > 0) {
    $farmSql = ' AND farm_id = ?';
    $farmParams[] = $selectedFarm;
} elseif (!$isSuperAdmin) {
    $farmSql = ' AND farm_id = ?';
    $farmParams[] = $farmId;
}

$cowFarmSql = '';
$cowFarmParams = [];

if ($selectedFarm > 0) {
    $cowFarmSql = ' AND c.farm_id = ?';
    $cowFarmParams[] = $selectedFarm;
} elseif (!$isSuperAdmin) {
    $cowFarmSql = ' AND c.farm_id = ?';
    $cowFarmParams[] = $farmId;
}

$milkParams = [$startDate, $endDate, ...$farmParams];
$healthParams = [$startDate, $endDate, ...$cowFarmParams];
$vaccineParams = [$startDate, $endDate, ...$cowFarmParams];

try {
    // Milk production during the selected period.
    $milkSummary = reportQuery(
        "SELECT
            COALESCE(SUM(quantity_litres), 0) AS total_litres,
            COUNT(*) AS total_entries,
            COALESCE(AVG(quantity_litres), 0) AS average_litres
         FROM milk_production
         WHERE production_date BETWEEN ? AND ? $farmSql",
        $milkParams
    )[0];

    // Daily milk production chart data.
    $dailyMilk = reportQuery(
        "SELECT production_date, SUM(quantity_litres) AS litres
         FROM milk_production
         WHERE production_date BETWEEN ? AND ? $farmSql
         GROUP BY production_date
         ORDER BY production_date",
        $milkParams
    );

    // Current cow population and health status.
    $cowSummary = reportQuery(
        "SELECT
            COUNT(*) AS total_cows,
            COALESCE(SUM(c.status = 'active'), 0) AS active_cows,
            COALESCE(SUM(c.status = 'sick'), 0) AS sick_cows,
            COALESCE(SUM(c.status = 'pregnant'), 0) AS pregnant_cows
         FROM cows c
         WHERE 1 = 1 $cowFarmSql",
        $cowFarmParams
    )[0];

    // Health records created during the selected period.
    $healthSummary = reportQuery(
        "SELECT
            COUNT(*) AS total_records,
            COALESCE(SUM(hr.status = 'under_treatment'), 0) AS under_treatment,
            COALESCE(SUM(hr.status = 'recovered'), 0) AS recovered
         FROM health_records hr
         JOIN cows c ON c.cow_id = hr.cow_id
         WHERE hr.record_date BETWEEN ? AND ? $cowFarmSql",
        $healthParams
    )[0];

    // Vaccinations administered during the selected period.
    $vaccineSummary = reportQuery(
        "SELECT COUNT(*) AS total_vaccinations
         FROM vaccinations v
         JOIN cows c ON c.cow_id = v.cow_id
         WHERE v.vaccination_date BETWEEN ? AND ? $cowFarmSql",
        $vaccineParams
    )[0];

    // Vaccination doses due soon, including overdue doses.
    $upcomingVaccines = reportQuery(
        "SELECT v.vaccination_id, v.vaccine_name, v.next_due_date,
                c.tag_number, c.cow_name, f.farm_name
         FROM vaccinations v
         JOIN cows c ON c.cow_id = v.cow_id
         JOIN farms f ON f.farm_id = c.farm_id
         WHERE v.next_due_date IS NOT NULL
           AND v.next_due_date <= DATE_ADD(CURDATE(), INTERVAL 30 DAY)
           $cowFarmSql
         ORDER BY v.next_due_date
         LIMIT 20",
        $cowFarmParams
    );

    // Current feed stock, grouped by feed type.
    $feedParams = [];
    $feedFarmSql = '';

    if ($selectedFarm > 0) {
        $feedFarmSql = ' WHERE fs.farm_id = ?';
        $feedParams[] = $selectedFarm;
    } elseif (!$isSuperAdmin) {
        $feedFarmSql = ' WHERE fs.farm_id = ?';
        $feedParams[] = $farmId;
    }

    $feedSummary = reportQuery(
        "SELECT COUNT(*) AS stock_items,
                COALESCE(SUM(fs.quantity <= fs.reorder_level), 0) AS low_stock
         FROM feed_stock fs
         $feedFarmSql",
        $feedParams
    )[0];

    $feedRows = reportQuery(
        "SELECT ft.feed_name, ft.unit,
                SUM(fs.quantity) AS quantity,
                SUM(fs.reorder_level) AS reorder_level
         FROM feed_stock fs
         JOIN feed_types ft ON ft.feed_id = fs.feed_id
         $feedFarmSql
         GROUP BY ft.feed_id, ft.feed_name, ft.unit
         ORDER BY ft.feed_name",
        $feedParams
    );

    // Detailed milk production report.
    $milkDetails = reportQuery(
        "SELECT mp.production_date, mp.milking_session,
                mp.quantity_litres, c.tag_number, c.cow_name,
                f.farm_name, u.full_name AS recorded_by
         FROM milk_production mp
         JOIN cows c ON c.cow_id = mp.cow_id
         JOIN farms f ON f.farm_id = mp.farm_id
         LEFT JOIN users u ON u.user_id = mp.recorded_by
         WHERE mp.production_date BETWEEN ? AND ? $farmSql
         ORDER BY mp.production_date DESC, mp.production_id DESC
         LIMIT 250",
        $milkParams
    );

    // Detailed health report.
    $healthDetails = reportQuery(
        "SELECT hr.record_date, hr.condition_name, hr.status,
                hr.treatment, c.tag_number, c.cow_name, f.farm_name
         FROM health_records hr
         JOIN cows c ON c.cow_id = hr.cow_id
         JOIN farms f ON f.farm_id = c.farm_id
         WHERE hr.record_date BETWEEN ? AND ? $cowFarmSql
         ORDER BY hr.record_date DESC, hr.health_id DESC
         LIMIT 150",
        $healthParams
    );
} catch (Throwable $exception) {
    error_log('DairyFlow reports error: ' . $exception->getMessage());
    http_response_code(500);
    exit('Unable to generate reports. Check that your database tables and column names match your project schema.');
}

$milkTotal = (float) $milkSummary['total_litres'];
$averageMilk = (float) $milkSummary['average_litres'];
$cowTotal = (int) $cowSummary['total_cows'];
$lowStock = (int) $feedSummary['low_stock'];

$farmLabel = 'All Farms';

foreach ($farms as $farm) {
    if ((int) $farm['farm_id'] === $selectedFarm) {
        $farmLabel = $farm['farm_name'];
        break;
    }
}

$initial = strtoupper(substr(trim((string) $user['full_name']), 0, 1));
$roleLabel = ucwords(str_replace('_', ' ', $role));
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Reports & Analytics | DairyFlow</title>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&family=Manrope:wght@400;600;700;800&display=swap" rel="stylesheet">

    <style>
        :root {
            --navy: #102a25;
            --green: #23865b;
            --green-dark: #176b46;
            --mint: #e9f7ef;
            --bg: #f5f8f6;
            --text: #1c3029;
            --muted: #78867f;
            --border: #e5ece7;
            --red: #c34242;
            --amber: #b87913;
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

        .sidebar {
            width: 240px;
            position: fixed;
            inset: 0 auto 0 0;
            background: var(--navy);
            color: white;
            padding: 25px 16px;
            display: flex;
            flex-direction: column;
        }

        .brand {
            display: flex;
            gap: 10px;
            align-items: center;
            padding: 0 9px 28px;
        }

        .logo {
            width: 42px;
            height: 42px;
            border-radius: 13px;
            background: #36a66e;
            display: grid;
            place-items: center;
            font-weight: 800;
            font-family: Manrope;
        }

        .brand-name {
            font: 800 19px Manrope;
        }

        .brand-sub {
            font-size: 9px;
            color: #a6c4b6;
            letter-spacing: 1.4px;
            margin-top: 3px;
        }

        .nav-label {
            color: #87a99a;
            font-size: 10px;
            letter-spacing: 1.4px;
            padding: 12px;
        }

        .nav-link {
            display: flex;
            gap: 11px;
            align-items: center;
            color: #c3d5cd;
            text-decoration: none;
            padding: 12px;
            border-radius: 9px;
            margin: 3px 0;
            font-size: 12px;
        }

        .nav-link:hover,
        .nav-link.active {
            background: #226b4d;
            color: white;
        }

        .sidebar-bottom {
            margin-top: auto;
            border-top: 1px solid #29463e;
            padding-top: 15px;
        }

        .user-mini {
            display: flex;
            gap: 10px;
            align-items: center;
            padding: 12px 7px;
        }

        .avatar {
            width: 36px;
            height: 36px;
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
            margin-left: 240px;
            padding: 30px 34px 45px;
        }

        .topbar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 15px;
            margin-bottom: 24px;
        }

        .eyebrow {
            color: var(--green);
            font-size: 10px;
            font-weight: 800;
            letter-spacing: 1.5px;
            text-transform: uppercase;
            margin-bottom: 7px;
        }

        h1 {
            font: 800 28px Manrope;
            letter-spacing: -.7px;
            margin: 0 0 7px;
        }

        .subtitle {
            color: var(--muted);
            font-size: 12px;
            margin: 0;
        }

        .actions {
            display: flex;
            gap: 9px;
            flex-wrap: wrap;
        }

        .btn {
            border: 0;
            border-radius: 9px;
            padding: 11px 15px;
            cursor: pointer;
            background: var(--green);
            color: white;
            text-decoration: none;
            font-weight: 700;
            font-size: 12px;
        }

        .btn:hover {
            background: var(--green-dark);
        }

        .btn.light {
            background: white;
            border: 1px solid var(--border);
            color: var(--text);
        }

        .panel {
            background: white;
            border: 1px solid #edf1ee;
            border-radius: 14px;
            padding: 21px;
            margin-bottom: 20px;
            box-shadow: 0 8px 28px rgba(16, 42, 37, .04);
        }

        .panel h2 {
            font: 800 15px Manrope;
            margin: 0 0 6px;
        }

        .panel-desc {
            color: var(--muted);
            font-size: 11px;
            margin-bottom: 18px;
        }

        .filter-grid {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 13px;
            align-items: end;
        }

        .field label {
            display: block;
            font-size: 11px;
            font-weight: 700;
            margin-bottom: 7px;
        }

        .field input,
        .field select {
            width: 100%;
            padding: 11px;
            border: 1px solid var(--border);
            border-radius: 8px;
            background: white;
            color: var(--text);
        }

        .stats {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 14px;
            margin-bottom: 20px;
        }

        .stat {
            background: white;
            border: 1px solid #edf1ee;
            border-radius: 13px;
            padding: 18px;
        }

        .stat-icon {
            font-size: 19px;
            margin-bottom: 12px;
        }

        .stat-label {
            font-size: 11px;
            color: var(--muted);
            font-weight: 600;
        }

        .stat-number {
            font: 800 25px Manrope;
            margin-top: 7px;
            overflow-wrap: anywhere;
        }

        .stat-note {
            color: var(--muted);
            font-size: 10px;
            margin-top: 5px;
        }

        .two-col {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
        }

        .table-wrap {
            overflow-x: auto;
        }

        table {
            border-collapse: collapse;
            width: 100%;
            white-space: nowrap;
        }

        th {
            background: #f7faf8;
            color: #77867d;
            text-align: left;
            font-size: 10px;
            text-transform: uppercase;
            padding: 12px;
            border-bottom: 1px solid var(--border);
        }

        td {
            padding: 13px 12px;
            border-bottom: 1px solid #edf1ee;
            font-size: 12px;
        }

        tbody tr:last-child td {
            border-bottom: 0;
        }

        .pill {
            display: inline-block;
            padding: 5px 8px;
            border-radius: 15px;
            font-size: 10px;
            font-weight: 800;
        }

        .good {
            background: #e5f7ec;
            color: #176b46;
        }

        .warn {
            background: #fff0dc;
            color: #9b6410;
        }

        .bad {
            background: #fff0f0;
            color: #b13c3c;
        }

        .chart {
            display: flex;
            align-items: end;
            gap: 8px;
            height: 180px;
            border-bottom: 1px solid var(--border);
            padding: 12px 3px 0;
            overflow-x: auto;
        }

        .bar-item {
            flex: 1;
            min-width: 25px;
            height: 100%;
            display: flex;
            flex-direction: column;
            justify-content: end;
            align-items: center;
            gap: 7px;
        }

        .bar {
            width: min(34px, 80%);
            min-height: 3px;
            border-radius: 6px 6px 0 0;
            background: linear-gradient(180deg, #56bd85, #23865b);
        }

        .bar-value {
            font-size: 9px;
            color: var(--muted);
        }

        .bar-date {
            font-size: 9px;
            color: var(--muted);
            white-space: nowrap;
        }

        .empty {
            text-align: center;
            padding: 25px;
            color: var(--muted);
            font-size: 12px;
        }

        .footer {
            text-align: center;
            color: #8b9991;
            font-size: 10px;
            padding: 15px;
        }

        @media(max-width:1100px) {
            .stats {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }

            .filter-grid {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }
        }

        @media(max-width:760px) {
            .sidebar {
                display: none;
            }

            .main {
                margin: 0;
                padding: 20px 13px;
            }

            .topbar {
                align-items: flex-start;
                flex-direction: column;
            }

            .two-col {
                grid-template-columns: 1fr;
            }

            .filter-grid {
                grid-template-columns: 1fr;
            }

            h1 {
                font-size: 24px;
            }
        }

        @media print {
            body {
                background: white;
            }

            .sidebar,
            .no-print,
            .filter-panel,
            .footer {
                display: none !important;
            }

            .main {
                margin: 0;
                padding: 0;
            }

            .panel,
            .stat {
                box-shadow: none;
                break-inside: avoid;
            }

            .stats {
                grid-template-columns: repeat(4, 1fr);
            }

            .two-col {
                grid-template-columns: 1fr 1fr;
            }

            h1 {
                font-size: 22px;
            }

            table {
                white-space: normal;
            }

            th,
            td {
                padding: 7px 5px;
                font-size: 9px;
            }
        }
    </style>
</head>

<body>

    <aside class="sidebar">
        <div class="brand">
            <div class="logo">DF</div>
            <div>
                <div class="brand-name">DairyFlow</div>
                <div class="brand-sub">FARM MANAGEMENT</div>
            </div>
        </div>

        <div class="nav-label">MAIN MENU</div>
        <a class="nav-link" href="index.php">▦ &nbsp; Dashboard</a>
        <a class="nav-link" href="farms.php">⌂ &nbsp; Farms</a>
        <a class="nav-link" href="cows.php">🐄 &nbsp; Cows</a>
        <a class="nav-link" href="milk.php">🥛 &nbsp; Milk Production</a>
        <a class="nav-link" href="health.php">✚ &nbsp; Health & Vaccines</a>
        <a class="nav-link" href="weights.php">⚖ &nbsp; Cow Weights</a>
        <a class="nav-link" href="feed_inventory.php">🌾 &nbsp; Feed Inventory</a>
        <a class="nav-link active" href="reports.php">▤ &nbsp; Reports & Analytics</a>

        <div class="sidebar-bottom">
            <a class="nav-link" href="users.php">♙ &nbsp; Users</a>
            <a class="nav-link" href="logout.php">↪ &nbsp; Sign Out</a>
            <div class="user-mini">
                <div class="avatar"><?= e($initial) ?></div>
                <div>
                    <div class="user-name"><?= e($user['full_name']) ?></div>
                    <div class="user-role"><?= e($roleLabel) ?></div>
                </div>
            </div>
        </div>
    </aside>

    <main class="main">
        <header class="topbar">
            <div>
                <div class="eyebrow">Insights & Performance</div>
                <h1>Reports & Analytics</h1>
                <p class="subtitle">Understand farm performance with your latest recorded data.</p>
            </div>
            <div class="actions no-print">
                <button class="btn light" onclick="window.print()">⤓ Print / Save PDF</button>
            </div>
        </header>

        <section class="panel filter-panel no-print">
            <h2>Report Filters</h2>
            <p class="panel-desc">Choose a farm and reporting period.</p>
            <form method="GET" class="filter-grid">
                <?php if ($isSuperAdmin): ?>
                    <div class="field">
                        <label for="farm_id">Farm</label>
                        <select name="farm_id" id="farm_id">
                            <option value="0">All farms</option>
                            <?php foreach ($farms as $farm): ?>
                                <option value="<?= (int) $farm['farm_id'] ?>"
                                    <?= $selectedFarm === (int) $farm['farm_id'] ? 'selected' : '' ?>>
                                    <?= e($farm['farm_name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                <?php else: ?>
                    <div class="field">
                        <label>Farm</label>
                        <input value="<?= e($farmLabel) ?>" readonly>
                    </div>
                <?php endif; ?>

                <div class="field">
                    <label for="start_date">Start Date</label>
                    <input type="date" id="start_date" name="start_date"
                        value="<?= e($startDate) ?>" max="<?= date('Y-m-d') ?>" required>
                </div>

                <div class="field">
                    <label for="end_date">End Date</label>
                    <input type="date" id="end_date" name="end_date"
                        value="<?= e($endDate) ?>" max="<?= date('Y-m-d') ?>" required>
                </div>

                <button class="btn" type="submit">Generate Report</button>
            </form>
        </section>

        <section class="panel">
            <h2>Report Summary</h2>
            <p class="panel-desc">
                <?= e($farmLabel) ?> · <?= e($startDate) ?> to <?= e($endDate) ?>
            </p>
            <div class="stats">
                <div class="stat">
                    <div class="stat-icon">🥛</div>
                    <div class="stat-label">Milk Produced</div>
                    <div class="stat-number"><?= number_format($milkTotal, 2) ?></div>
                    <div class="stat-note">Litres during selected period</div>
                </div>
                <div class="stat">
                    <div class="stat-icon">🐄</div>
                    <div class="stat-label">Current Cattle</div>
                    <div class="stat-number"><?= number_format($cowTotal) ?></div>
                    <div class="stat-note"><?= (int) $cowSummary['active_cows'] ?> active · <?= (int) $cowSummary['pregnant_cows'] ?> pregnant</div>
                </div>
                <div class="stat">
                    <div class="stat-icon">🩺</div>
                    <div class="stat-label">Health Records</div>
                    <div class="stat-number"><?= number_format((int) $healthSummary['total_records']) ?></div>
                    <div class="stat-note"><?= (int) $healthSummary['under_treatment'] ?> under treatment</div>
                </div>
                <div class="stat">
                    <div class="stat-icon">🌾</div>
                    <div class="stat-label">Low Stock Items</div>
                    <div class="stat-number"><?= number_format($lowStock) ?></div>
                    <div class="stat-note"><?= (int) $feedSummary['stock_items'] ?> tracked stock entries</div>
                </div>
            </div>
        </section>

        <section class="two-col">
            <div class="panel">
                <h2>Milk Production Trend</h2>
                <p class="panel-desc">Litres recorded on each production date.</p>

                <?php
                $maxMilk = 0.0;
                foreach ($dailyMilk as $day) {
                    $maxMilk = max($maxMilk, (float) $day['litres']);
                }
                ?>

                <?php if (!$dailyMilk): ?>
                    <div class="empty">No milk production recorded for this period.</div>
                <?php else: ?>
                    <div class="chart">
                        <?php foreach ($dailyMilk as $day): ?>
                            <?php
                            $litres = (float) $day['litres'];
                            $height = $maxMilk > 0 ? max(3, ($litres / $maxMilk) * 130) : 3;
                            ?>
                            <div class="bar-item" title="<?= e($day['production_date']) ?>: <?= number_format($litres, 2) ?> litres">
                                <div class="bar-value"><?= number_format($litres, 0) ?></div>
                                <div class="bar" style="height: <?= $height ?>px"></div>
                                <div class="bar-date"><?= e(date('d M', strtotime($day['production_date']))) ?></div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <p class="panel-desc" style="margin:12px 0 0">
                        Total: <?= number_format($milkTotal, 2) ?> litres ·
                        Average per entry: <?= number_format($averageMilk, 2) ?> litres
                    </p>
                <?php endif; ?>
            </div>

            <div class="panel">
                <h2>Cattle Overview</h2>
                <p class="panel-desc">Current recorded cattle status, not limited to the date filter.</p>
                <table>
                    <tbody>
                        <tr>
                            <td>Total cattle</td>
                            <td><strong><?= (int) $cowSummary['total_cows'] ?></strong></td>
                        </tr>
                        <tr>
                            <td>Active</td>
                            <td><span class="pill good"><?= (int) $cowSummary['active_cows'] ?></span></td>
                        </tr>
                        <tr>
                            <td>Sick</td>
                            <td><span class="pill bad"><?= (int) $cowSummary['sick_cows'] ?></span></td>
                        </tr>
                        <tr>
                            <td>Pregnant</td>
                            <td><span class="pill good"><?= (int) $cowSummary['pregnant_cows'] ?></span></td>
                        </tr>
                        <tr>
                            <td>Vaccinations in period</td>
                            <td><strong><?= (int) $vaccineSummary['total_vaccinations'] ?></strong></td>
                        </tr>
                        <tr>
                            <td>Recovered health records</td>
                            <td><strong><?= (int) $healthSummary['recovered'] ?></strong></td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>

        <section class="panel">
            <h2>Feed Stock Report</h2>
            <p class="panel-desc">Current inventory levels for <?= e($farmLabel) ?>.</p>
            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>Feed Type</th>
                            <th>Available Quantity</th>
                            <th>Reorder Level</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!$feedRows): ?>
                            <tr>
                                <td colspan="4" class="empty">No feed stock entries found.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($feedRows as $feed): ?>
                                <?php $isLow = (float) $feed['quantity'] <= (float) $feed['reorder_level']; ?>
                                <tr>
                                    <td><strong><?= e($feed['feed_name']) ?></strong></td>
                                    <td><?= number_format((float) $feed['quantity'], 2) ?> <?= e($feed['unit']) ?></td>
                                    <td><?= number_format((float) $feed['reorder_level'], 2) ?> <?= e($feed['unit']) ?></td>
                                    <td>
                                        <span class="pill <?= $isLow ? 'warn' : 'good' ?>">
                                            <?= $isLow ? 'Low Stock' : 'In Stock' ?>
                                        </span>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>

        <section class="panel">
            <h2>Milk Production Details</h2>
            <p class="panel-desc">Individual production entries during the selected period. Showing up to 250 entries.</p>
            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Farm</th>
                            <th>Cow Tag</th>
                            <th>Cow Name</th>
                            <th>Session</th>
                            <th>Litres</th>
                            <th>Recorded By</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!$milkDetails): ?>
                            <tr>
                                <td colspan="7" class="empty">No milk records found.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($milkDetails as $row): ?>
                                <tr>
                                    <td><?= e($row['production_date']) ?></td>
                                    <td><?= e($row['farm_name']) ?></td>
                                    <td><?= e($row['tag_number']) ?></td>
                                    <td><?= e($row['cow_name']) ?></td>
                                    <td><?= e(ucfirst($row['milking_session'])) ?></td>
                                    <td><strong><?= number_format((float) $row['quantity_litres'], 2) ?></strong></td>
                                    <td><?= e($row['recorded_by'] ?? 'Unknown') ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>

        <section class="panel">
            <h2>Health Records</h2>
            <p class="panel-desc">Health events recorded during the selected period.</p>
            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Farm</th>
                            <th>Cow Tag</th>
                            <th>Cow Name</th>
                            <th>Condition</th>
                            <th>Treatment</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!$healthDetails): ?>
                            <tr>
                                <td colspan="7" class="empty">No health records found.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($healthDetails as $row): ?>
                                <tr>
                                    <td><?= e($row['record_date']) ?></td>
                                    <td><?= e($row['farm_name']) ?></td>
                                    <td><?= e($row['tag_number']) ?></td>
                                    <td><?= e($row['cow_name']) ?></td>
                                    <td><?= e($row['condition_name']) ?></td>
                                    <td><?= e($row['treatment'] ?? '') ?></td>
                                    <td>
                                        <span class="pill <?= $row['status'] === 'recovered' ? 'good' : ($row['status'] === 'under_treatment' ? 'warn' : 'bad') ?>">
                                            <?= e(ucwords(str_replace('_', ' ', $row['status']))) ?>
                                        </span>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>

        <section class="panel">
            <h2>Upcoming & Overdue Vaccinations</h2>
            <p class="panel-desc">Vaccinations due now or within the next 30 days.</p>
            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>Due Date</th>
                            <th>Farm</th>
                            <th>Cow Tag</th>
                            <th>Cow Name</th>
                            <th>Vaccine</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!$upcomingVaccines): ?>
                            <tr>
                                <td colspan="6" class="empty">No upcoming or overdue vaccinations found.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($upcomingVaccines as $vaccine): ?>
                                <?php $overdue = $vaccine['next_due_date'] < date('Y-m-d'); ?>
                                <tr>
                                    <td><?= e($vaccine['next_due_date']) ?></td>
                                    <td><?= e($vaccine['farm_name']) ?></td>
                                    <td><?= e($vaccine['tag_number']) ?></td>
                                    <td><?= e($vaccine['cow_name']) ?></td>
                                    <td><?= e($vaccine['vaccine_name']) ?></td>
                                    <td><span class="pill <?= $overdue ? 'bad' : 'warn' ?>"><?= $overdue ? 'Overdue' : 'Due Soon' ?></span></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>

        <div class="footer">DairyFlow Farm Management System · Generated <?= e(date('d M Y, H:i')) ?></div>
    </main>
</body>

</html>