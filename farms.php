<?php
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';

requireLogin();
requireRole(['super_admin']);
$currentRole = $_SESSION['role_name'] ?? '';
$isSuperAdmin = ($currentRole === 'super_admin');
$role = $currentRole;

$message = '';
$error = '';

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Register a new farm
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $submittedToken = $_POST['csrf_token'] ?? '';

        if (
            !is_string($submittedToken) ||
            !hash_equals($_SESSION['csrf_token'], $submittedToken)
        ) {
            throw new RuntimeException(
                'Security check failed. Refresh the page and try again.'
            );
        }

        $farmName = trim($_POST['farm_name'] ?? '');
        $location = trim($_POST['location'] ?? '');
        $phone = trim($_POST['contact_phone'] ?? '');

        if ($farmName === '') {
            throw new RuntimeException('Farm name is required.');
        }

        if (strlen($farmName) > 150) {
            throw new RuntimeException(
                'Farm name must not exceed 150 characters.'
            );
        }

        if (strlen($location) > 255 || strlen($phone) > 30) {
            throw new RuntimeException(
                'Location or phone number is too long.'
            );
        }

        $stmt = $pdo->prepare(
            'INSERT INTO farms
                (farm_name, location, contact_phone, status)
             VALUES
                (:farm_name, :location, :contact_phone, :status)'
        );

        $stmt->execute([
            'farm_name' => $farmName,
            'location' => $location !== '' ? $location : null,
            'contact_phone' => $phone !== '' ? $phone : null,
            'status' => 'active'
        ]);

        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        $message = 'Farm registered successfully!';
    } catch (RuntimeException $exception) {
        $error = $exception->getMessage();
    } catch (PDOException $exception) {
        error_log('Farm registration error: ' . $exception->getMessage());

        if ($exception->getCode() === '23000') {
            $error = 'A farm with these details may already exist.';
        } else {
            $error = 'Could not register the farm. Check the database table structure.';
        }
    }
}

// Fetch all farms
try {
    $stmt = $pdo->query(
        'SELECT farm_id, farm_name, location, contact_phone,
                status, created_at
         FROM farms
         ORDER BY farm_id DESC'
    );

    $farms = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $exception) {
    error_log('Farm listing error: ' . $exception->getMessage());
    $farms = [];
    $error = $error ?: 'Could not load farms from the database.';
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Manage Farms | Dairy Farm System</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700;800&family=Manrope:wght@400;500;600;700;800&display=swap" rel="stylesheet">



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

        html {
            min-height: 100%;
        }

        body {
            margin: 0;
            min-height: 100vh;
            font-family: 'DM Sans', sans-serif;
            background: var(--bg);
            color: var(--text);
        }

        /* APPLICATION LAYOUT */

        .app-shell {
            display: flex;
            min-height: 100vh;
            width: 100%;
        }

        /* SIDEBAR */

        .sidebar {
            width: 248px;
            min-width: 248px;
            height: 100vh;
            position: fixed;
            top: 0;
            left: 0;
            z-index: 100;
            padding: 27px 17px;
            display: flex;
            flex-direction: column;
            background: var(--navy);
            color: white;
            overflow-y: auto;
        }

        .brand {
            display: flex;
            align-items: center;
            gap: 12px;
            margin: 0 5px 42px;
            color: white;
            text-decoration: none;
            font-size: 19px;
            font-weight: 800;
        }

        .brand small {
            display: block;
            margin-top: 3px;
            color: #9eb9aa;
            font-size: 9px;
            letter-spacing: 1.5px;
        }

        .logo {
            width: 42px;
            height: 42px;
            display: grid;
            place-items: center;
            flex-shrink: 0;
            border-radius: 12px;
            background: var(--green);
            color: white;
            font-weight: 800;
        }

        .nav-label {
            margin: 0 10px 13px;
            color: #78988a;
            font-size: 10px;
            font-weight: 800;
            letter-spacing: 1.7px;
        }

        .sidebar nav {
            display: flex;
            flex-direction: column;
            gap: 6px;
        }

        .sidebar nav a {
            display: flex;
            align-items: center;
            padding: 12px 13px;
            border-radius: 9px;
            color: #c2d3ca;
            text-decoration: none;
            font-size: 13px;
            font-weight: 600;
            transition: background 0.2s, color 0.2s;
        }

        .sidebar nav a:hover {
            background: var(--navy-light);
            color: white;
        }

        .sidebar nav a.active {
            background: var(--green);
            color: white;
        }

        .sidebar-bottom {
            margin-top: auto;
            padding-top: 17px;
            border-top: 1px solid rgba(255, 255, 255, 0.1);
        }

        .user-chip {
            display: flex;
            align-items: center;
            gap: 10px;
            min-width: 0;
            padding: 8px 5px 15px;
        }

        .avatar {
            width: 35px;
            height: 35px;
            flex-shrink: 0;
            display: grid;
            place-items: center;
            border-radius: 50%;
            background: #d7f1e1;
            color: var(--green-dark);
            font-weight: 800;
        }

        .user-chip strong {
            display: block;
            color: white;
            font-size: 12px;
        }

        .user-chip small {
            display: block;
            margin-top: 4px;
            color: #9eb9aa;
            font-size: 10px;
        }

        .sidebar-bottom a {
            display: block;
            padding: 12px 13px;
            border-radius: 9px;
            color: #c2d3ca;
            text-decoration: none;
            font-size: 12px;
            font-weight: 600;
        }

        .sidebar-bottom a:hover {
            background: var(--navy-light);
            color: white;
        }

        .sidebar-bottom .logout {
            color: #f0a6a6;
        }

        /* MAIN AREA */

        .main-area {
            width: calc(100% - 248px);
            min-width: 0;
            min-height: 100vh;
            margin-left: 248px;
            display: flex;
            flex-direction: column;
        }

        /* PAGE HEADER */

        .header {
            width: 100%;
            min-height: 105px;
            padding: 23px 36px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            background: white;
            border-bottom: 1px solid var(--line);
        }

        .header h1 {
            margin: 0;
            color: var(--navy);
            font-family: 'Manrope', sans-serif;
            font-size: 25px;
            font-weight: 800;
        }

        .header p {
            margin: 7px 0 0;
            color: var(--muted);
            font-size: 13px;
        }

        .eyebrow {
            display: block;
            margin-bottom: 6px;
            color: var(--green);
            font-size: 10px;
            font-weight: 800;
            letter-spacing: 1.5px;
        }

        /* PAGE CONTENT */

        .content {
            width: 100%;
            max-width: 1600px;
            margin: 0 auto;
            padding: 30px 36px 40px;
        }

        /* PANELS */

        .panel {
            width: 100%;
            padding: 26px;
            margin-bottom: 24px;
            background: white;
            border: 1px solid var(--line);
            border-radius: 13px;
            box-shadow: var(--shadow);
        }

        .panel h2 {
            margin: 0 0 23px;
            color: var(--navy);
            font-family: 'Manrope', sans-serif;
            font-size: 17px;
            font-weight: 800;
        }

        /* FORM */

        .form-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 20px;
        }

        .field {
            min-width: 0;
            margin-bottom: 3px;
        }

        label {
            display: block;
            margin-bottom: 8px;
            color: var(--text);
            font-size: 12px;
            font-weight: 700;
        }

        input {
            width: 100%;
            min-height: 44px;
            padding: 12px 14px;
            border: 1px solid #dce6df;
            border-radius: 8px;
            outline: none;
            background: white;
            color: var(--text);
            font-family: inherit;
            font-size: 13px;
            transition: 0.2s;
        }

        input::placeholder {
            color: #a4b2aa;
        }

        input:focus {
            border-color: var(--green);
            box-shadow: 0 0 0 3px rgba(35, 134, 91, 0.1);
        }

        /* SUBMIT BUTTON */

        button {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-height: 43px;
            margin-top: 22px;
            padding: 12px 22px;
            border: none;
            border-radius: 8px;
            background: var(--green);
            color: white;
            font-family: inherit;
            font-size: 13px;
            font-weight: 700;
            cursor: pointer;
            transition: 0.2s;
        }

        button:hover {
            background: var(--green-dark);
            transform: translateY(-1px);
        }

        /* NOTIFICATIONS */

        .notice {
            padding: 14px 16px;
            margin-bottom: 19px;
            border-radius: 8px;
            font-size: 13px;
        }

        .success {
            background: #e7f7ed;
            border: 1px solid #c8ead5;
            color: #176b46;
        }

        .error {
            background: #fff0f0;
            border: 1px solid #f5d1d1;
            color: #a52b2b;
        }

        /* FARMS TABLE */

        .table-wrap {
            width: 100%;
            overflow-x: auto;
            border-radius: 8px;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            white-space: nowrap;
        }

        thead th {
            padding: 15px 14px;
            background: #f5f8f6;
            color: #708279;
            text-align: left;
            font-size: 10px;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 0.8px;
            border-bottom: 1px solid var(--line);
        }

        tbody td {
            padding: 15px 14px;
            color: #40564b;
            font-size: 12px;
            border-bottom: 1px solid var(--line);
        }

        tbody tr {
            transition: background 0.2s;
        }

        tbody tr:hover {
            background: #f8fbf9;
        }

        tbody tr:last-child td {
            border-bottom: none;
        }

        .status {
            display: inline-block;
            padding: 5px 11px;
            border-radius: 20px;
            background: #e7f7ed;
            color: #176b46;
            font-size: 11px;
            font-weight: 700;
        }

        /* RESPONSIVE */

        @media (max-width: 1000px) {
            .content {
                padding: 25px 22px;
            }

            .header {
                padding: 22px;
            }
        }

        @media (max-width: 760px) {
            .app-shell {
                display: block;
            }

            .sidebar {
                position: relative;
                width: 100%;
                min-width: 100%;
                height: auto;
                min-height: auto;
            }

            .sidebar nav {
                display: grid;
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }

            .sidebar-bottom {
                margin-top: 20px;
            }

            .main-area {
                width: 100%;
                margin-left: 0;
            }

            .header {
                padding: 22px 18px;
            }

            .content {
                padding: 20px 16px;
            }
        }

        @media (max-width: 550px) {
            .form-grid {
                grid-template-columns: 1fr;
            }

            .panel {
                padding: 19px;
            }

            .header h1 {
                font-size: 21px;
            }

            .sidebar nav {
                grid-template-columns: 1fr;
            }

            .content {
                padding: 16px 12px;
            }
        }
    </style>


</head>

<body>
    <div class="app-shell">

        <aside class="sidebar">

            <a href="index.php" class="brand">
                <span class="logo small-logo">DF</span>
                <span>
                    DairyFlow
                    <small>FARM MANAGEMENT</small>
                </span>
            </a>

            <p class="nav-label">WORKSPACE</p>

            <nav>
                <a href="index.php">▦ &nbsp; Dashboard</a>

                <?php if (in_array($role, ['super_admin', 'admin', 'farm_manager'], true)): ?>
                    <a href="cows.php">♧ &nbsp; Cows & Records</a>
                    <a href="milk.php">◉ &nbsp; Milk Production</a>
                    <a href="search.php">🔍 &nbsp; Search Cow</a>
                    <a href="health.php">♡ &nbsp; Health & Vaccinations</a>
                <?php endif; ?>

                <?php if (in_array($role, ['super_admin', 'admin', 'store_manager'], true)): ?>
                    <a href="feeds.php">🌾 &nbsp; Feed Inventory</a>
                <?php endif; ?>

                <a href="reports.php">▥ &nbsp; Reports</a>

                <?php if ($isSuperAdmin): ?>
                    <a href="users.php">♙ &nbsp; Manage Users</a>
                    <a class="active" href="farms.php">⌂ &nbsp; Manage Farms</a>
                <?php endif; ?>

                <?php if ($role === 'admin'): ?>
                    <a class="active" href="users.php">♙ &nbsp; Manage Farm Managers</a>
                <?php endif; ?>
            </nav>

            <div class="sidebar-bottom">
                <div class="user-chip">
                    <div class="avatar">
                        <?= e(strtoupper(substr((string)($_SESSION['full_name'] ?? 'U'), 0, 1))) ?>
                    </div>

                    <div>
                        <strong><?= e($_SESSION['full_name'] ?? 'User') ?></strong>
                        <small><?= e(ucwords(str_replace('_', ' ', $role))) ?></small>
                    </div>
                </div>

                <a class="nav-link" href="change_password.php">
                    🔐 Change Password
                </a>

                <a class="logout" href="logout.php">
                    ↪ Sign out
                </a>
            </div>

        </aside>
        <main class="main-area">
            <header class="header">
                <h1>Manage Farms</h1>
                <p>Register farms and manage your dairy farm locations.</p>


            </header>
            <div class="content">
                <section class="panel">
                    <h2>Register a New Farm</h2>

                    <?php if ($message !== ''): ?>
                        <div class="notice success">
                            <?= e($message) ?>
                        </div>
                    <?php endif; ?>

                    <?php if ($error !== ''): ?>
                        <div class="notice error">
                            <?= e($error) ?>
                        </div>
                    <?php endif; ?>

                    <form method="POST" action="farms.php">
                        <input
                            type="hidden"
                            name="csrf_token"
                            value="<?= e($_SESSION['csrf_token']) ?>">

                        <div class="form-grid">
                            <div class="field">
                                <label for="farm_name">Farm Name *</label>
                                <input
                                    type="text"
                                    id="farm_name"
                                    name="farm_name"
                                    maxlength="150"
                                    placeholder="e.g. Green Valley Dairy Farm"
                                    required>
                            </div>

                            <div class="field">
                                <label for="location">Farm Location</label>
                                <input
                                    type="text"
                                    id="location"
                                    name="location"
                                    maxlength="255"
                                    placeholder="e.g. Nanyuki, Laikipia">
                            </div>

                            <div class="field">
                                <label for="contact_phone">Contact Phone</label>
                                <input
                                    type="tel"
                                    id="contact_phone"
                                    name="contact_phone"
                                    maxlength="30"
                                    placeholder="Farm contact number">
                            </div>
                        </div>

                        <button type="submit">Register Farm</button>
                    </form>
                </section>

                <section class="panel">
                    <h2>Registered Farms</h2>

                    <div class="table-wrap">
                        <table>
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Farm Name</th>
                                    <th>Location</th>
                                    <th>Contact</th>
                                    <th>Status</th>
                                    <th>Date Created</th>
                                </tr>
                            </thead>

                            <tbody>
                                <?php if (empty($farms)): ?>
                                    <tr>
                                        <td colspan="6">
                                            No farms registered yet. Add your first farm above.
                                        </td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($farms as $farm): ?>
                                        <tr>
                                            <td><?= e((string)$farm['farm_id']) ?></td>
                                            <td><?= e($farm['farm_name']) ?></td>
                                            <td><?= e($farm['location'] ?? 'Not provided') ?></td>
                                            <td><?= e($farm['contact_phone'] ?? 'Not provided') ?></td>
                                            <td>
                                                <span class="status">
                                                    <?= e(ucfirst($farm['status'] ?? 'Unknown')) ?>
                                                </span>
                                            </td>
                                            <td><?= e($farm['created_at'] ?? '') ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </section>

            </div>
</body>

</html>