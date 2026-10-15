<?php
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

    <link rel="stylesheet" href="assets/style.css">

    <style>
        :root {
            --navy: #102A43;
            --navy-light: #183B56;
            --green: #176B45;
            --green-light: #228557;
            --soft-green: #E4F4E9;
            --mint: #F0F8F2;
            --lime: #C6E7A8;
            --border: #E2EAE4;
            --text: #243B30;
            --muted: #718078;
        }

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            background: var(--mint);
            color: var(--text);
            font-family: Arial, Helvetica, sans-serif;
        }

        .milk-layout {
            min-height: 100vh;
            display: flex;
        }

        /* Sidebar matching the main DairyFlow theme */
        .sidebar {
            width: 250px;
            min-width: 250px;
            background: var(--navy);
            color: #fff;
            padding: 26px 18px;
            display: flex;
            flex-direction: column;
        }

        .sidebar-brand {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 0 8px 26px;
            border-bottom: 1px solid rgba(255, 255, 255, .12);
        }

        .sidebar-logo {
            width: 42px;
            height: 42px;
            display: grid;
            place-items: center;
            border-radius: 12px;
            background: var(--lime);
            color: var(--navy);
            font-weight: 800;
            font-size: 18px;
        }

        .sidebar-brand h2 {
            font-size: 17px;
            margin: 0;
        }

        .sidebar-brand small {
            display: block;
            color: #C5D5E0;
            margin-top: 4px;
            font-size: 11px;
        }

        .sidebar nav {
            display: flex;
            flex-direction: column;
            gap: 7px;
            margin-top: 25px;
        }

        .sidebar nav a {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 13px 14px;
            color: #DCE8E0;
            border-radius: 9px;
            text-decoration: none;
            font-size: 14px;
            transition: background .2s ease;
        }

        .sidebar nav a:hover,
        .sidebar nav a.active {
            color: #fff;
            background: var(--green);
        }

        .sidebar nav a.active {
            box-shadow: inset 3px 0 var(--lime);
        }

        .sidebar-bottom {
            margin-top: auto;
            padding-top: 25px;
        }

        .sidebar-user {
            border-top: 1px solid rgba(255, 255, 255, .12);
            padding: 18px 8px 0;
            overflow-wrap: anywhere;
        }

        .sidebar-user strong {
            display: block;
            font-size: 13px;
        }

        .sidebar-user small {
            display: block;
            color: #C5D5E0;
            margin-top: 5px;
        }

        .sidebar-logout {
            display: block;
            color: #FFDADA;
            text-decoration: none;
            padding: 12px 8px 0;
            font-size: 13px;
        }

        .milk-main {
            flex: 1;
            min-width: 0;
            padding: 28px;
        }

        .milk-topbar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 16px;
            margin-bottom: 28px;
        }

        .milk-topbar h1 {
            margin: 0 0 8px;
            color: var(--navy);
            font-size: 27px;
        }

        .milk-subtitle {
            margin: 0;
            color: var(--muted);
            font-size: 14px;
        }

        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            border: 0;
            border-radius: 9px;
            padding: 11px 16px;
            background: var(--green);
            color: #fff;
            text-decoration: none;
            font-size: 14px;
            font-weight: 700;
            cursor: pointer;
        }

        .btn:hover {
            background: var(--green-light);
        }

        .btn-secondary {
            background: #fff;
            color: var(--navy);
            border: 1px solid var(--border);
        }

        .btn-secondary:hover {
            background: var(--soft-green);
        }

        .milk-stat {
            padding: 23px;
            background: linear-gradient(120deg, var(--green), var(--navy-light));
            color: #fff;
            border-radius: 15px;
            margin-bottom: 25px;
        }

        .milk-stat span {
            color: #E0F0E5;
            font-size: 14px;
        }

        .milk-stat strong {
            display: block;
            margin-top: 10px;
            font-size: 32px;
        }

        .milk-card {
            background: #fff;
            border: 1px solid var(--border);
            border-radius: 14px;
            padding: 24px;
            margin-bottom: 25px;
            box-shadow: 0 4px 15px rgba(16, 42, 67, .04);
        }

        .milk-card h2 {
            color: var(--navy);
            font-size: 19px;
            margin: 0 0 8px;
        }

        .card-description {
            color: var(--muted);
            font-size: 13px;
            margin: 0 0 22px;
        }

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
            font-weight: 700;
            color: var(--navy);
            font-size: 13px;
        }

        .milk-field input,
        .milk-field select,
        .milk-field textarea {
            width: 100%;
            padding: 12px;
            border: 1px solid #D5E0D8;
            border-radius: 8px;
            background: #fff;
            color: var(--text);
            font: inherit;
            font-size: 14px;
        }

        .milk-field input:focus,
        .milk-field select:focus,
        .milk-field textarea:focus {
            outline: 2px solid var(--soft-green);
            border-color: var(--green);
        }

        .milk-field textarea {
            resize: vertical;
        }

        .milk-help {
            color: var(--muted);
            font-size: 12px;
            margin: 0;
        }

        .milk-alert {
            padding: 14px 16px;
            border-radius: 9px;
            margin-bottom: 20px;
            font-size: 14px;
        }

        .milk-alert.error {
            background: #FFF0F0;
            border: 1px solid #F3CACA;
            color: #9D2828;
        }

        .milk-alert.success {
            background: var(--soft-green);
            border: 1px solid #C5E7D0;
            color: var(--green);
        }

        .milk-table-wrap {
            overflow-x: auto;
        }

        .milk-table {
            width: 100%;
            border-collapse: collapse;
            min-width: 720px;
        }

        .milk-table th {
            text-align: left;
            padding: 13px 12px;
            background: var(--mint);
            color: var(--navy);
            font-size: 12px;
            white-space: nowrap;
        }

        .milk-table td {
            padding: 14px 12px;
            border-bottom: 1px solid var(--border);
            font-size: 13px;
            vertical-align: top;
        }

        .milk-table tbody tr:hover {
            background: #FAFCFA;
        }

        .session-badge {
            display: inline-block;
            padding: 5px 9px;
            border-radius: 20px;
            background: var(--soft-green);
            color: var(--green);
            font-size: 11px;
            font-weight: 700;
        }

        .empty-state {
            text-align: center;
            padding: 30px 12px;
            color: var(--muted);
            font-size: 14px;
        }

        .milk-mobile-back {
            display: none;
        }

        @media (max-width: 850px) {
            .milk-layout {
                display: block;
            }

            .sidebar {
                width: 100%;
                min-width: 0;
                padding: 15px;
            }

            .sidebar-brand {
                padding-bottom: 15px;
            }

            .sidebar nav {
                flex-direction: row;
                flex-wrap: wrap;
                margin-top: 15px;
            }

            .sidebar nav a {
                padding: 10px;
                font-size: 12px;
            }

            .sidebar-bottom {
                margin-top: 15px;
                padding-top: 0;
            }

            .sidebar-user {
                padding-top: 12px;
            }

            .milk-main {
                padding: 20px 15px;
            }

            .milk-mobile-back {
                display: inline-flex;
                margin-bottom: 15px;
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

            .milk-topbar h1 {
                font-size: 23px;
            }
        }
    </style>
</head>

<body>

    <div class="milk-layout">

        <aside class="sidebar">
            <div class="sidebar-brand">
                <div class="sidebar-logo">DF</div>
                <div>
                    <h2>DairyFlow</h2>
                    <small>Farm Management System</small>
                </div>
            </div>

            <nav>
                <a href="<?= e($dashboardUrl) ?>">
                    <span>▦</span> Dashboard
                </a>

                <a href="cows.php">
                    <span>♧</span> Cows &amp; Records
                </a>

                <a href="milk.php" class="active" aria-current="page">
                    <span>◉</span> Milk Production
                </a>

                <a href="health.php">
                    <span>✚</span> Health &amp; Vaccinations
                </a>

                <a href="feeds.php">
                    <span>▤</span> Feed Inventory
                </a>

                <a href="reports.php">
                    <span>▥</span> Reports
                </a>

                <?php if ($isSuperAdmin): ?>
                    <a href="users.php">
                        <span>♙</span> Manage Users
                    </a>

                    <a href="farms.php">
                        <span>⌂</span> Manage Farms
                    </a>
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

        <main class="milk-main">

            <a
                class="btn btn-secondary milk-mobile-back"
                href="<?= e($dashboardUrl) ?>">
                ← Back to Dashboard
            </a>

            <header class="milk-topbar">
                <div>
                    <h1>Milk Production</h1>
                    <p class="milk-subtitle">
                        Record and monitor milk collected on your farm.
                    </p>
                </div>

                <a class="btn btn-secondary" href="<?= e($dashboardUrl) ?>">
                    ← Back to Dashboard
                </a>
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
                            value="<?= e($selectedDate) ?>"
                            max="<?= date('Y-m-d') ?>"
                            required>
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

</body>

</html>