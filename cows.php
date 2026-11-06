<?php
require_once __DIR__ . '/includes/auth.php';

requireLogin();
requireRole(['super_admin', 'admin', 'farm_manager']);

$role = $_SESSION['role_name'];

$canRegisterCow = in_array(
    $role,
    ['super_admin', 'admin', 'farm_manager'],
    true
);

$userFarmId = $_SESSION['farm_id'] ?? null;
$isSuperAdmin = $role === 'super_admin';

$errors = [];
$success = '';

$allowedStatuses = [
    'active',
    'sick',
    'pregnant',
    'dry',
    'sold',
    'deceased'
];

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}


$farmId = $isSuperAdmin
    ? filter_var($_GET['farm_id'] ?? '', FILTER_VALIDATE_INT)
    : $userFarmId;

if (!$farmId || $farmId < 1) {
    $farmId = null;
}

$farms = [];
$parents = [];
$cows = [];
$managers = [];


try {
    if ($isSuperAdmin) {
        $farmStmt = $pdo->query(
            "SELECT farm_id, farm_name FROM farms
             WHERE status = 'active'
             ORDER BY farm_name"
        );
        $farms = $farmStmt->fetchAll(PDO::FETCH_ASSOC);
    }
    if (!empty($farmId)) {
        $managerStmt = $pdo->prepare(
            "SELECT u.user_id, u.full_name
         FROM users u
         INNER JOIN roles r ON r.role_id = u.role_id
         WHERE u.farm_id = ?
           AND u.status = 'active'
           AND r.role_name = 'farm_manager'
         ORDER BY u.full_name"
        );

        $managerStmt->execute([$farmId]);
        $managers = $managerStmt->fetchAll(PDO::FETCH_ASSOC);
    }
    if (!$isSuperAdmin && empty($userFarmId)) {
        throw new RuntimeException(
            'Your account has not been assigned to a farm.'
        );
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!$canRegisterCow) {
            http_response_code(403);
            exit('You are not permitted to register cows.');
        }
        if (
            !isset($_POST['csrf_token']) ||
            !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])
        ) {
            throw new RuntimeException(
                'Invalid form session. Refresh the page and try again.'
            );
        }

        $farmId = $isSuperAdmin
            ? filter_var($_POST['farm_id'] ?? '', FILTER_VALIDATE_INT)
            : $userFarmId;

        $tagNumber = trim($_POST['tag_number'] ?? '');
        $cowName = trim($_POST['cow_name'] ?? '');
        $dateOfBirth = $_POST['date_of_birth'] ?? '';
        $breed = trim($_POST['breed'] ?? '');
        $gender = $_POST['gender'] ?? '';
        $origin = $_POST['origin'] ?? '';
        $status = $_POST['status'] ?? 'active';
        $notes = trim($_POST['notes'] ?? '');
        $motherId = null;

        $allowedBreeds = [
            'Friesian',
            'Ayrshire',
            'Jersey',
            'Guernsey',
            'Brown Swiss',
            'Sahiwal',
            'Zebu',
            'Crossbreed',
            'Other'
        ];

        if (!$farmId || $farmId < 1) {
            $errors[] = 'Please select a valid farm.';
        } else {
            $checkFarm = $pdo->prepare(
                "SELECT farm_id FROM farms
             WHERE farm_id = ? AND status = 'active'"
            );
            $checkFarm->execute([$farmId]);

            if (!$checkFarm->fetch()) {
                $errors[] = 'The selected farm does not exist or is inactive.';
            }
        }

        if ($tagNumber === '') {
            $errors[] = 'Cow tag number is required.';
        }

        if (!in_array($breed, $allowedBreeds, true)) {
            $errors[] = 'Please select a valid breed.';
        }

        if (!in_array($gender, ['male', 'female'], true)) {
            $errors[] = 'Please select the cow gender.';
        }

        if (!in_array($origin, ['born_on_farm', 'acquired'], true)) {
            $errors[] = 'Please select a valid cow origin.';
        }

        if (!in_array($status, $allowedStatuses, true)) {
            $errors[] = 'Please select a valid cow status.';
        }

        if ($dateOfBirth !== '') {
            $date = DateTime::createFromFormat('!Y-m-d', $dateOfBirth);

            if (
                !$date ||
                $date->format('Y-m-d') !== $dateOfBirth ||
                $date > new DateTime('today')
            ) {
                $errors[] = 'Please enter a valid date of birth.';
            }
        } else {
            $dateOfBirth = null;
        }

        if ($origin === 'born_on_farm' && $farmId) {
            $submittedMotherId = filter_var(
                $_POST['mother_id'] ?? '',
                FILTER_VALIDATE_INT
            );

            if (!$submittedMotherId || $submittedMotherId < 1) {
                $errors[] = 'Please select the mother of this farm-born cow.';
            } else {
                $motherStmt = $pdo->prepare(
                    "SELECT cow_id FROM cows
                 WHERE cow_id = ?
                   AND farm_id = ?
                   AND gender = 'female'
                   AND status NOT IN ('sold', 'deceased')"
                );

                $motherStmt->execute([$submittedMotherId, $farmId]);

                if (!$motherStmt->fetch()) {
                    $errors[] = 'The mother must be an eligible female cow from the same farm.';
                } else {
                    $motherId = $submittedMotherId;
                }
            }
        }

        // Assign the cow to the correct farm manager.
        if ($role === 'farm_manager') {
            // Farm Managers are automatically assigned to their own cows.
            $managerId = (int) $_SESSION['user_id'];
        } else {
            // Admin and Super Admin must select a farm manager.
            $managerId = filter_var(
                $_POST['manager_id'] ?? '',
                FILTER_VALIDATE_INT
            );

            if (!$managerId || $managerId < 1) {
                $errors[] = 'Please select a farm manager.';
            } else {
                $managerCheck = $pdo->prepare(
                    "SELECT u.user_id
                     FROM users u
                     INNER JOIN roles r ON r.role_id = u.role_id
                     WHERE u.user_id = ?
                       AND u.farm_id = ?
                       AND u.status = 'active'
                       AND r.role_name = 'farm_manager'"
                );

                $managerCheck->execute([$managerId, $farmId]);

                if (!$managerCheck->fetch()) {
                    $errors[] = 'Please select an active manager assigned to this farm.';
                }
            }
        }

        // Register the cow if all validations pass.
        if (empty($errors)) {
            try {
                $stmt = $pdo->prepare(
                    "INSERT INTO cows (
                        farm_id,
                        manager_id,
                        tag_number,
                        cow_name,
                        date_of_birth,
                        breed,
                        gender,
                        origin,
                        mother_id,
                        status,
                        notes
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
                );

                $stmt->execute([
                    $farmId,
                    $managerId,
                    $tagNumber,
                    $cowName !== '' ? $cowName : null,
                    $dateOfBirth,
                    $breed,
                    $gender,
                    $origin,
                    $motherId,
                    $status,
                    $notes !== '' ? $notes : null
                ]);

                $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

                header('Location: cows.php?created=1');
                exit;
            } catch (PDOException $e) {
                if ($e->getCode() === '23000') {
                    $errors[] = 'That cow tag number already exists. Please use a unique tag number.';
                } else {
                    throw $e;
                }
            }
        }
    }

    // Display the success message after redirecting.
    if (isset($_GET['created'])) {
        $success = 'Cow registered successfully.';
    }



    if ($isSuperAdmin) {
        $stmt = $pdo->query(
            "SELECT c.*, f.farm_name
         FROM cows c
         INNER JOIN farms f ON f.farm_id = c.farm_id
         ORDER BY c.created_at DESC"
        );
    } elseif ($_SESSION['role_name'] === 'farm_manager') {
        $stmt = $pdo->prepare(
            "SELECT c.*, f.farm_name
         FROM cows c
         INNER JOIN farms f ON f.farm_id = c.farm_id
         WHERE c.farm_id = ?
           AND c.manager_id = ?
         ORDER BY c.created_at DESC"
        );

        $stmt->execute([
            $userFarmId,
            $_SESSION['user_id']
        ]);
    } else {
        $stmt = $pdo->prepare(
            "SELECT c.*, f.farm_name
         FROM cows c
         INNER JOIN farms f ON f.farm_id = c.farm_id
         WHERE c.farm_id = ?
         ORDER BY c.created_at DESC"
        );

        $stmt->execute([$userFarmId]);
    }

    $cows = $stmt->fetchAll(PDO::FETCH_ASSOC);



    // Load possible mothers from the permitted farm or farms.
    if ($isSuperAdmin) {
        $motherStmt = $pdo->query(
            "SELECT cow_id, farm_id, cow_name, tag_number
             FROM cows
             WHERE gender = 'female'
               AND status NOT IN ('sold', 'deceased')
             ORDER BY cow_name, tag_number"
        );
    } else {
        $motherStmt = $pdo->prepare(
            "SELECT cow_id, farm_id, cow_name, tag_number
             FROM cows
             WHERE farm_id = ?
               AND gender = 'female'
               AND status NOT IN ('sold', 'deceased')
             ORDER BY cow_name, tag_number"
        );
        $motherStmt->execute([$userFarmId]);
    }

    $parents = $motherStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $errors[] = $e instanceof RuntimeException
        ? $e->getMessage()
        : 'Something went wrong while loading cow records. Check your database configuration and table structure.';
}

function cowStatusClass(string $status): string
{
    return match ($status) {
        'active' => 'active',
        'pregnant' => 'pregnant',
        'sick' => 'sick',
        'dry' => 'dry',
        'sold' => 'sold',
        'deceased' => 'deceased',
        default => ''
    };
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Cow Management | Dairy Farm System</title>
    <link rel="stylesheet" href="assets/style.css">



    <link rel="stylesheet" href="assets/styles.css">

    <style>
        /* =========================================
   COWS PAGE - PAGE SPECIFIC STYLES
   Sidebar styles are in assets/styles.css
========================================= */

        /* GLOBAL PAGE SETTINGS */

        * {
            box-sizing: border-box;
        }

        :root {
            --background: #f5f8f6;
            --ink: #1c3029;
            --green: #23865b;
            --green-dark: #176b46;
            --muted: #788b81;
        }

        body {
            margin: 0;
            padding: 0;
            background: var(--background);
            color: var(--ink);
            font-family: 'DM Sans', sans-serif;
            font-size: 14px;
        }

        a {
            color: inherit;
            text-decoration: none;
        }

        /* MAIN CONTENT */

        .main-content {
            margin-left: 248px;
            width: calc(100% - 248px);
            min-width: 0;
            min-height: 100vh;
            background: var(--background);
        }

        /* COW MANAGEMENT PAGE */

        .cow-page {
            width: 100%;
            max-width: 1400px;
            margin: 0 auto;
            padding: 30px;
        }

        .cow-page h1 {
            margin: 0 0 8px;
            color: var(--ink);
            font-size: 26px;
            font-weight: 700;
        }

        .cow-page h2 {
            margin-top: 0;
            color: var(--ink);
        }

        /* COW CARDS */

        .cow-card {
            width: 100%;
            padding: 24px;
            margin-bottom: 24px;
            background: #ffffff;
            border: 1px solid #e8eee9;
            border-radius: 14px;
            box-shadow: 0 3px 14px rgba(16, 42, 37, 0.05);
        }

        /* REGISTRATION FORM */

        .cow-form {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 18px;
            width: 100%;
        }

        .cow-field {
            display: flex;
            flex-direction: column;
            gap: 7px;
            min-width: 0;
        }

        .cow-field label {
            color: #34483a;
            font-size: 13px;
            font-weight: 600;
        }

        .cow-field input,
        .cow-field select,
        .cow-field textarea {
            width: 100%;
            padding: 11px 12px;
            border: 1px solid #d5ddd7;
            border-radius: 8px;
            background: #ffffff;
            color: var(--ink);
            font-family: inherit;
            font-size: 13px;
            outline: none;
            transition: border-color 0.2s, box-shadow 0.2s;
        }

        .cow-field input:focus,
        .cow-field select:focus,
        .cow-field textarea:focus {
            border-color: var(--green);
            box-shadow: 0 0 0 3px rgba(35, 134, 91, 0.10);
        }

        .cow-field textarea {
            min-height: 90px;
            resize: vertical;
        }

        .cow-field.full,
        .cow-form button {
            grid-column: 1 / -1;
        }

        /* REGISTER BUTTON */

        .cow-button {
            display: inline-flex;
            justify-content: center;
            align-items: center;
            gap: 8px;
            padding: 12px 20px;
            border: none;
            border-radius: 8px;
            background: var(--green);
            color: #ffffff;
            font-family: inherit;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            transition: background 0.2s ease;
        }

        .cow-button:hover {
            background: var(--green-dark);
        }

        /* COW RECORDS TABLE */

        .cow-table-wrap {
            width: 100%;
            overflow-x: auto;
            border-radius: 9px;
        }

        .cow-table {
            width: 100%;
            min-width: 760px;
            border-collapse: collapse;
            background: #ffffff;
        }

        .cow-table th,
        .cow-table td {
            padding: 13px 14px;
            text-align: left;
            border-bottom: 1px solid #e6ebe7;
            font-size: 13px;
        }

        .cow-table th {
            background: #f3f7f4;
            color: var(--ink);
            font-weight: 700;
            white-space: nowrap;
        }

        .cow-table tbody tr {
            transition: background 0.2s ease;
        }

        .cow-table tbody tr:hover {
            background: #f8fbf8;
        }

        .cow-table tbody tr:last-child td {
            border-bottom: none;
        }

        /* COW STATUS */

        .cow-status {
            display: inline-block;
            padding: 5px 10px;
            border-radius: 20px;
            background: #eef2ef;
            font-size: 11px;
            font-weight: 600;
            text-transform: capitalize;
            white-space: nowrap;
        }

        .cow-status.active,
        .cow-status.pregnant {
            background: #e2f5e8;
            color: #176b45;
        }

        .cow-status.sick {
            background: #fff0d8;
            color: #885600;
        }

        .cow-status.deceased {
            background: #fce8e8;
            color: #9d2525;
        }

        .cow-status.sold {
            background: #e8eaf6;
            color: #3949ab;
        }

        .cow-status.dry {
            background: #fff3e0;
            color: #e65100;
        }

        /* ALERT MESSAGES */

        .cow-alert {
            padding: 13px 16px;
            margin-bottom: 18px;
            border-radius: 8px;
            font-size: 13px;
        }

        .cow-alert.success {
            background: #e2f5e8;
            color: #176b45;
        }

        .cow-alert.error {
            background: #fdeaea;
            color: #9d2525;
        }

        /* MUTED TEXT */

        .cow-muted {
            color: var(--muted);
        }

        /* TABLET */

        @media (max-width: 900px) {
            .main-content {
                margin-left: 248px;
                width: calc(100% - 248px);
            }

            .cow-page {
                padding: 22px;
            }
        }

        /* MOBILE */

        @media (max-width: 760px) {
            .main-content {
                width: 100%;
                margin-left: 0;
            }

            .cow-page {
                padding: 16px;
            }

            .cow-card {
                padding: 18px;
            }

            .cow-form {
                grid-template-columns: 1fr;
            }

            .cow-field.full,
            .cow-form button {
                grid-column: 1;
            }

            .cow-page h1 {
                font-size: 22px;
            }
        }
    </style>




</head>

<body>
    <div class="dashboard-layout">

        <aside class="sidebar">
            <a href="index.php" class="brand">
                <span class="logo small-logo">DF</span>
                <span>DairyFlow<small>FARM MANAGEMENT</small></span>
            </a>

            <p class="nav-label">WORKSPACE</p>
            <nav>
                <a href="index.php">▦ &nbsp; Dashboard</a>

                <?php if (in_array($role, ['super_admin', 'admin', 'farm_manager'], true)): ?>
                    <a class="active" href="cows.php">♧ &nbsp; Cows & Records</a>
                    <a href="milk.php">◉ &nbsp; Milk Production</a>
                    <a href="search.php">⌕ &nbsp; search cow</a>

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

        <main class="main-content">
            <div class="cow-page">
                <h1>Cow Management 🐄</h1>
                <p class="cow-muted">Register your herd and keep track of each animal.</p>

                <?php if ($success): ?>
                    <div class="cow-alert success">
                        <?= e($success) ?>
                    </div>
                <?php endif; ?>

                <?php foreach ($errors as $error): ?>
                    <div class="cow-alert error"><?= e($error) ?></div>
                <?php endforeach; ?>
                <?php if ($canRegisterCow): ?>
                    <section class="cow-card">
                        <h2>Register a New Cow</h2>


                        <form method="POST" class="cow-form">
                            <input
                                type="hidden"
                                name="csrf_token"
                                value="<?= e($_SESSION['csrf_token']) ?>">

                            <?php if ($isSuperAdmin): ?>
                                <div class="cow-field">
                                    <label for="farm_id">Select Farm *</label>

                                    <select
                                        id="farm_id"
                                        name="farm_id"
                                        required
                                        onchange="window.location.href='cows.php?farm_id=' + encodeURIComponent(this.value)">
                                        <option value="">Select a farm</option>

                                        <?php foreach ($farms as $farm): ?>
                                            <option
                                                value="<?= (int) $farm['farm_id'] ?>"
                                                <?= (string) $farmId === (string) $farm['farm_id'] ? 'selected' : '' ?>>
                                                <?= e($farm['farm_name']) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            <?php endif; ?>
                            <?php if (in_array($role, ['super_admin', 'admin'], true)): ?>

                                <div class="cow-field">
                                    <label for="manager_id">Assign Farm Manager *</label>

                                    <select id="manager_id" name="manager_id" required>
                                        <option value="">Select an active farm manager</option>

                                        <?php foreach ($managers as $manager): ?>
                                            <option
                                                value="<?= (int) $manager['user_id'] ?>"
                                                <?= (string) ($_POST['manager_id'] ?? '') === (string) $manager['user_id'] ? 'selected' : '' ?>>
                                                <?= e($manager['full_name']) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            <?php endif; ?>



                            <div class="cow-field">
                                <label for="tag_number">Cow Tag Number *</label>
                                <input
                                    type="text"
                                    id="tag_number"
                                    name="tag_number"
                                    maxlength="100"
                                    required
                                    value="<?= e($_POST['tag_number'] ?? '') ?>"
                                    placeholder="e.g. COW-001">
                            </div>

                            <div class="cow-field">
                                <label for="cow_name">Cow Name</label>
                                <input
                                    type="text"
                                    id="cow_name"
                                    name="cow_name"
                                    maxlength="100"
                                    value="<?= e($_POST['cow_name'] ?? '') ?>"
                                    placeholder="e.g. Bella">
                            </div>

                            <div class="cow-field">
                                <label for="date_of_birth">Date of Birth</label>
                                <input
                                    type="date"
                                    id="date_of_birth"
                                    name="date_of_birth"
                                    max="<?= date('Y-m-d') ?>"
                                    value="<?= e($_POST['date_of_birth'] ?? '') ?>">
                            </div>


                            <div class="cow-field">
                                <label for="breed">Breed *</label>
                                <select id="breed" name="breed" required>
                                    <option value="">Select breed</option>
                                    <?php
                                    $breeds = [
                                        'Friesian',
                                        'Ayrshire',
                                        'Jersey',
                                        'Guernsey',
                                        'Brown Swiss',
                                        'Sahiwal',
                                        'Zebu',
                                        'Crossbreed',
                                        'Other'
                                    ];

                                    foreach ($breeds as $breedOption):
                                    ?>
                                        <option value="<?= e($breedOption) ?>"
                                            <?= ($_POST['breed'] ?? '') === $breedOption ? 'selected' : '' ?>>
                                            <?= e($breedOption) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>


                            <div class="cow-field">
                                <label for="gender">Gender *</label>
                                <select id="gender" name="gender" required>
                                    <option value="">Select gender</option>
                                    <option value="female" <?= ($_POST['gender'] ?? '') === 'female' ? 'selected' : '' ?>>Female</option>
                                    <option value="male" <?= ($_POST['gender'] ?? '') === 'male' ? 'selected' : '' ?>>Male</option>
                                </select>
                            </div>

                            <div class="cow-field">
                                <label for="origin">Cow Origin *</label>
                                <select id="origin" name="origin" required>
                                    <option value="">Select origin</option>
                                    <option value="born_on_farm"
                                        <?= ($_POST['origin'] ?? '') === 'born_on_farm'
                                            ? 'selected' : '' ?>>
                                        Born on this farm
                                    </option>
                                    <option value="acquired"
                                        <?= ($_POST['origin'] ?? 'acquired') === 'acquired'
                                            ? 'selected' : '' ?>>
                                        Acquired
                                    </option>
                                </select>
                            </div>

                            <div class="cow-field full" id="mother-section" hidden>
                                <label for="mother_search">Mother Cow *</label>

                                <input
                                    type="text"
                                    id="mother_search"
                                    list="mother-options"
                                    placeholder="Search by mother name or tag number"
                                    autocomplete="off">

                                <datalist id="mother-options">
                                    <?php foreach ($parents as $parent): ?>
                                        <option
                                            value="<?= e(
                                                        ($parent['cow_name'] ?: 'Unnamed')
                                                            . ' (' . $parent['tag_number'] . ')'
                                                    ) ?>"
                                            data-mother-id="<?= (int) $parent['cow_id'] ?>"
                                            data-farm-id="<?= (int) $parent['farm_id'] ?>"></option>
                                    <?php endforeach; ?>
                                </datalist>

                                <input
                                    type="hidden"
                                    name="mother_id"
                                    id="mother_id"
                                    value="<?= e($_POST['mother_id'] ?? '') ?>">

                                <small class="cow-muted">
                                    Start typing the mother's name or tag number, then select
                                    the matching suggestion.
                                </small>
                            </div>

                            <div class="cow-field">
                                <label for="status">Status *</label>
                                <select id="status" name="status" required>
                                    <?php foreach ($allowedStatuses as $option): ?>
                                        <option
                                            value="<?= e($option) ?>"
                                            <?= ($_POST['status'] ?? 'active') === $option ? 'selected' : '' ?>>
                                            <?= e(ucfirst($option)) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="cow-field full">
                                <label for="notes">Notes</label>
                                <textarea
                                    id="notes"
                                    name="notes"
                                    maxlength="2000"
                                    placeholder="Additional information about the cow"><?= e($_POST['notes'] ?? '') ?></textarea>
                            </div>

                            <button type="submit" class="cow-button">Register Cow</button>
                        </form>
                    </section>
                <?php endif; ?>
                <section class="cow-card">
                    <h2>Registered Cows</h2>

                    <p class="cow-muted">
                        Total records: <?= count($cows) ?>
                    </p>

                    <div class="cow-table-wrap">
                        <table class="cow-table">
                            <thead>
                                <tr>
                                    <th>Tag Number</th>
                                    <th>Name</th>
                                    <th>Farm</th>
                                    <th>Breed</th>
                                    <th>Gender</th>
                                    <th>Date of Birth</th>
                                    <th>Status</th>
                                </tr>
                            </thead>

                            <tbody>
                                <?php if (empty($cows)): ?>
                                    <tr>
                                        <td colspan="7">
                                            No cow records found.
                                        </td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($cows as $cow): ?>
                                        <tr>
                                            <td>
                                                <?= e($cow['tag_number']) ?>
                                            </td>

                                            <td>
                                                <?= e($cow['cow_name'] ?: 'Unnamed') ?>
                                            </td>

                                            <td>
                                                <?= e($cow['farm_name']) ?>
                                            </td>

                                            <td>
                                                <?= e($cow['breed'] ?: 'Not recorded') ?>
                                            </td>

                                            <td>
                                                <?= e(ucfirst($cow['gender'])) ?>
                                            </td>

                                            <td>
                                                <?= e($cow['date_of_birth'] ?: 'Not recorded') ?>
                                            </td>

                                            <td>
                                                <span class="cow-status <?= e(cowStatusClass($cow['status'])) ?>">
                                                    <?= e($cow['status']) ?>
                                                </span>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </section>

                <script>
                    document.addEventListener('DOMContentLoaded', function() {
                        const origin = document.getElementById('origin');
                        const motherSection = document.getElementById('mother-section');
                        const motherSearch = document.getElementById('mother_search');
                        const motherId = document.getElementById('mother_id');
                        const motherOptions = Array.from(
                            document.querySelectorAll('#mother-options option')
                        );
                        const farmSelect = document.getElementById('farm_id');

                        function updateMotherOptions() {
                            const selectedFarm = farmSelect ? farmSelect.value : '';

                            motherOptions.forEach(function(option) {
                                const belongsToFarm = !selectedFarm ||
                                    option.dataset.farmId === selectedFarm;

                                option.disabled = !belongsToFarm;
                            });

                            // Clear a previously selected mother if it belongs to another farm.
                            const selectedOption = motherOptions.find(function(option) {
                                return option.dataset.motherId === motherId.value;
                            });

                            if (
                                selectedOption &&
                                selectedFarm &&
                                selectedOption.dataset.farmId !== selectedFarm
                            ) {
                                motherSearch.value = '';
                                motherId.value = '';
                            }
                        }

                        function updateMotherField() {
                            const isBornOnFarm = origin.value === 'born_on_farm';

                            motherSection.hidden = !isBornOnFarm;
                            motherSearch.required = isBornOnFarm;

                            if (!isBornOnFarm) {
                                motherSearch.value = '';
                                motherId.value = '';
                            }
                        }

                        motherSearch.addEventListener('input', function() {
                            const match = motherOptions.find(function(option) {
                                return option.value === motherSearch.value &&
                                    !option.disabled;
                            });

                            motherId.value = match ? match.dataset.motherId : '';

                            if (!match && motherSearch.value === '') {
                                motherId.value = '';
                            }
                        });

                        origin.addEventListener('change', updateMotherField);

                        if (farmSelect) {
                            farmSelect.addEventListener('change', updateMotherOptions);
                        }

                        updateMotherOptions();
                        updateMotherField();
                    });
                </script>
            </div>
        </main>
    </div>
</body>

</html>