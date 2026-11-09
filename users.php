<?php
require_once __DIR__ . '/includes/auth.php';

requireLogin();
requireRole(['super_admin', 'admin']);

$pdo = $pdo ?? null;
$currentRole = $_SESSION['role_name'] ?? '';
$currentFarmId = $_SESSION['farm_id'] ?? null;
$isSuperAdmin = ($currentRole === 'super_admin');
$role = $currentRole;

$allowedRoles = ($currentRole === 'super_admin')
    ? ['admin', 'store_manager']
    : ['farm_manager'];

$message = '';
$error = '';

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$farmQuery = $pdo->query(
    'SELECT farm_id, farm_name FROM farms ORDER BY farm_name'
);
$farms = $farmQuery->fetchAll(PDO::FETCH_ASSOC);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!hash_equals(
            $_SESSION['csrf_token'],
            $_POST['csrf_token'] ?? ''
        )) {
            throw new RuntimeException(
                'Security check failed. Refresh the page and try again.'
            );
        }

        $fullName = trim($_POST['full_name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $password = $_POST['password'] ?? '';
        $roleName = $_POST['role_name'] ?? '';

        if (
            $fullName === '' ||
            !filter_var($email, FILTER_VALIDATE_EMAIL) ||
            strlen($password) < 8
        ) {
            throw new RuntimeException(
                'Enter a name, valid email, and password of at least 8 characters.'
            );
        }

        if (!in_array($roleName, $allowedRoles, true)) {
            throw new RuntimeException(
                'You are not allowed to create that role.'
            );
        }

        if ($currentRole === 'super_admin') {
            $farmId = filter_var(
                $_POST['farm_id'] ?? '',
                FILTER_VALIDATE_INT
            );
        } else {
            $farmId = filter_var(
                $currentFarmId,
                FILTER_VALIDATE_INT
            );
        }

        if (!$farmId) {
            throw new RuntimeException(
                'Select a valid farm. The account must belong to a farm.'
            );
        }

        $checkFarm = $pdo->prepare(
            'SELECT farm_id FROM farms WHERE farm_id = :farm_id'
        );
        $checkFarm->execute(['farm_id' => $farmId]);

        if (!$checkFarm->fetch()) {
            throw new RuntimeException('The selected farm does not exist.');
        }

        $roleQuery = $pdo->prepare(
            'SELECT role_id FROM roles WHERE role_name = :role_name LIMIT 1'
        );
        $roleQuery->execute(['role_name' => $roleName]);
        $role = $roleQuery->fetch(PDO::FETCH_ASSOC);

        if (!$role) {
            throw new RuntimeException(
                'This role is missing from the roles table.'
            );
        }

        $insert = $pdo->prepare(
            'INSERT INTO users
                (role_id, farm_id, full_name, email, password_hash, phone, status)
             VALUES
                (:role_id, :farm_id, :full_name, :email, :password_hash, :phone, :status)'
        );

        $insert->execute([
            'role_id' => $role['role_id'],
            'farm_id' => $farmId,
            'full_name' => $fullName,
            'email' => $email,
            'password_hash' => password_hash($password, PASSWORD_DEFAULT),
            'phone' => $phone !== '' ? $phone : null,
            'status' => 'active'
        ]);

        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        $message = 'User created successfully.';
    } catch (PDOException $exception) {
        if ($exception->getCode() === '23000') {
            $error = 'That email address may already be registered.';
        } else {
            error_log($exception->getMessage());
            $error = 'Could not create the user. Check the database structure and try again.';
        }
    } catch (RuntimeException $exception) {
        $error = $exception->getMessage();
    }
}

if ($currentRole === 'super_admin') {
    $listQuery = $pdo->query(
        'SELECT u.user_id, u.full_name, u.email, u.phone, u.status,
                r.role_name, f.farm_name
         FROM users u
         JOIN roles r ON u.role_id = r.role_id
         LEFT JOIN farms f ON u.farm_id = f.farm_id
         ORDER BY u.user_id DESC'
    );
    $users = $listQuery->fetchAll(PDO::FETCH_ASSOC);
} else {
    $listQuery = $pdo->prepare(
        'SELECT u.user_id, u.full_name, u.email, u.phone, u.status,
                r.role_name, f.farm_name
         FROM users u
         JOIN roles r ON u.role_id = r.role_id
         LEFT JOIN farms f ON u.farm_id = f.farm_id
         WHERE u.farm_id = :farm_id
         ORDER BY u.user_id DESC'
    );
    $listQuery->execute(['farm_id' => $currentFarmId]);
    $users = $listQuery->fetchAll(PDO::FETCH_ASSOC);
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Users | Dairy Farm System</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700;800&family=Manrope:wght@400;500;600;700;800&display=swap" rel="stylesheet">
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

        body {
            margin: 0;
            font-family: 'DM Sans', sans-serif;
            background: var(--bg);
            color: var(--text);
        }

        .app-shell {
            min-height: 100vh;
            display: flex;
        }

        /* SIDEBAR */

        .sidebar {
            width: 248px;
            min-width: 248px;
            background: var(--navy);
            color: white;
            position: fixed;
            top: 0;
            left: 0;
            bottom: 0;
            padding: 27px 17px;
            display: flex;
            flex-direction: column;
            z-index: 1001;
            overflow-y: auto;
        }

        .brand {
            display: flex;
            align-items: center;
            gap: 12px;
            color: white;
            text-decoration: none;
            font-size: 19px;
            font-weight: 800;
            margin: 0 5px 42px;
        }

        .brand small {
            display: block;
            color: #9eb9aa;
            font-size: 9px;
            letter-spacing: 1.5px;
            margin-top: 3px;
        }

        .logo {
            width: 42px;
            height: 42px;
            display: grid;
            place-items: center;
            background: var(--green);
            border-radius: 12px;
            font-weight: 800;
            color: white;
        }

        .small-logo {
            flex-shrink: 0;
        }

        .nav-label {
            color: #78988a;
            font-size: 10px;
            font-weight: 800;
            letter-spacing: 1.7px;
            margin: 0 10px 13px;
        }

        .sidebar nav {
            display: flex;
            flex-direction: column;
            gap: 6px;
        }

        .sidebar nav a,
        .sidebar-bottom a {
            color: #c2d3ca;
            text-decoration: none;
            padding: 12px 13px;
            border-radius: 9px;
            font-size: 13px;
            font-weight: 600;
            transition: 0.2s ease;
        }

        .sidebar nav a:hover,
        .sidebar-bottom a:hover {
            background: var(--navy-light);
            color: white;
        }

        .sidebar nav a.active {
            background: var(--green);
            color: white;
        }

        .sidebar-bottom {
            margin-top: auto;
            border-top: 1px solid rgba(255, 255, 255, 0.1);
            padding-top: 17px;
        }

        .user-chip {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 8px 5px 15px;
            min-width: 0;
        }

        .avatar,
        .top-avatar {
            width: 35px;
            height: 35px;
            flex-shrink: 0;
            border-radius: 50%;
            display: grid;
            place-items: center;
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
            color: #9eb9aa;
            font-size: 10px;
            margin-top: 4px;
        }

        .sidebar-bottom a {
            display: block;
            font-size: 12px;
        }

        .sidebar-bottom .logout {
            color: #f0a6a6;
        }

        /* MOBILE OVERLAY AND MENU BUTTON */

        .sidebar-overlay {
            display: none;
        }

        .mobile-menu-btn {
            display: none;
            position: fixed;
            top: 13px;
            left: 13px;
            z-index: 999;
            width: 43px;
            height: 43px;
            margin: 0;
            padding: 0;
            border: none;
            border-radius: 9px;
            background: var(--navy);
            color: white;
            font-size: 23px;
            cursor: pointer;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
        }

        /* MAIN CONTENT */

        .main-area {
            width: calc(100% - 248px);
            min-height: 100vh;
            margin-left: 248px;
            min-width: 0;
        }

        .content {
            max-width: 1600px;
            margin: auto;
            padding: 30px 36px 40px;
        }

        /* HEADER */

        .header {
            background: var(--white);
            padding: 24px 30px;
            border-bottom: 1px solid var(--line);
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 20px;
            flex-wrap: wrap;
        }

        .header h1 {
            margin: 4px 0 5px;
            font-family: 'Manrope', sans-serif;
            font-size: 25px;
            font-weight: 800;
            color: var(--navy);
        }

        .header p {
            margin: 0;
            color: var(--muted);
            font-size: 13px;
        }

        .back {
            background: var(--mint);
            color: var(--green-dark);
            padding: 11px 16px;
            border-radius: 8px;
            text-decoration: none;
            font-size: 12px;
            font-weight: 700;
            white-space: nowrap;
        }

        .back:hover {
            background: #d8efdf;
        }

        /* PANELS */

        .panel {
            background: var(--white);
            border: 1px solid var(--line);
            border-radius: 13px;
            padding: 25px;
            margin: 25px 30px;
            box-shadow: var(--shadow);
        }

        .panel h2 {
            font-family: 'Manrope', sans-serif;
            font-size: 17px;
            font-weight: 800;
            color: var(--navy);
            margin: 0 0 22px;
        }

        /* FORM */

        .form-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 19px;
        }

        .field {
            margin-bottom: 3px;
        }

        label {
            display: block;
            font-size: 12px;
            font-weight: 700;
            color: var(--text);
            margin-bottom: 8px;
        }

        input,
        select {
            width: 100%;
            padding: 12px 13px;
            border: 1px solid #dce6df;
            border-radius: 8px;
            background: #fff;
            color: var(--text);
            font-family: inherit;
            font-size: 13px;
            outline: none;
            transition: 0.2s;
        }

        input:focus,
        select:focus {
            border-color: var(--green);
            box-shadow: 0 0 0 3px rgba(35, 134, 91, 0.1);
        }

        input:disabled {
            background: #f2f6f3;
            color: var(--muted);
        }

        .field small {
            display: block;
            color: var(--muted);
            font-size: 11px;
            margin-top: 7px;
        }

        button[type="submit"] {
            display: inline-block;
            margin-top: 22px;
            padding: 12px 21px;
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

        button[type="submit"]:hover {
            background: var(--green-dark);
            transform: translateY(-1px);
        }

        /* ALERTS */

        .notice {
            padding: 13px 15px;
            border-radius: 8px;
            margin-bottom: 18px;
            font-size: 13px;
        }

        .success {
            background: #e7f7ed;
            color: #176b46;
            border: 1px solid #c8ead5;
        }

        .error {
            background: #fff0f0;
            color: #a52b2b;
            border: 1px solid #f5d1d1;
        }

        /* TABLE */

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
            background: #f5f8f6;
            color: #708279;
            font-size: 10px;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 0.8px;
        }

        th,
        td {
            text-align: left;
            padding: 15px 13px;
            border-bottom: 1px solid var(--line);
        }

        td {
            color: #40564b;
            font-size: 12px;
        }

        tbody tr:hover {
            background: #f9fbf9;
        }

        tbody tr:last-child td {
            border-bottom: none;
        }

        /* TABLET */

        @media (max-width: 1100px) and (min-width: 761px) {
            .main-area {
                width: calc(100% - 248px);
            }

            .header {
                padding: 20px;
            }

            .panel {
                margin: 20px;
                padding: 20px;
            }
        }

        /* MOBILE */

        @media (max-width: 760px) {
            .app-shell {
                display: block;
            }

            .sidebar {
                position: fixed;
                top: 0;
                left: 0;
                bottom: 0;
                width: 248px;
                min-width: 248px;
                height: 100vh;
                transform: translateX(-100%);
                transition: transform 0.3s ease;
                z-index: 1001;
            }

            .sidebar.active {
                transform: translateX(0);
            }

            .sidebar nav {
                display: flex;
                flex-direction: column;
                gap: 6px;
            }

            .sidebar-bottom {
                margin-top: auto;
            }

            .sidebar-overlay {
                display: block;
                position: fixed;
                inset: 0;
                background: rgba(0, 0, 0, 0.45);
                opacity: 0;
                visibility: hidden;
                transition: 0.3s ease;
                z-index: 1000;
            }

            .sidebar-overlay.active {
                opacity: 1;
                visibility: visible;
            }

            .mobile-menu-btn {
                display: block;
            }

            .main-area {
                width: 100%;
                margin-left: 0;
            }

            .header {
                padding: 75px 18px 20px;
                align-items: flex-start;
                flex-direction: column;
                gap: 12px;
            }

            .header h1 {
                font-size: 22px;
            }

            .panel {
                margin: 18px 13px;
                padding: 19px;
            }

            .form-grid {
                grid-template-columns: 1fr;
                gap: 15px;
            }
        }

        @media (max-width: 420px) {
            .panel {
                padding: 15px;
            }

            .header {
                padding-left: 15px;
                padding-right: 15px;
            }

            .header h1 {
                font-size: 20px;
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
                    <a class="active" href="users.php">♙ &nbsp; Manage Users</a>
                    <a href="farms.php">⌂ &nbsp; Manage Farms</a>
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
        <div class="sidebar-overlay" id="sidebarOverlay"></div>
        <main class="main-area">
            <button type="button" class="mobile-menu-btn" id="mobileMenuBtn">
                ☰
            </button>
            <header class="header">
                <h1>Manage Users</h1>
                <p>
                    Signed in as <?= e($_SESSION['full_name'] ?? 'User') ?>.
                    Your role: <?= e(ucwords(str_replace('_', ' ', $currentRole))) ?>
                </p>
                <a class="back" href="<?= e($currentRole === 'super_admin'
                                            ? 'super_admin.php' : 'admin.php') ?>">Back to Dashboard</a>
            </header>

            <section class="panel">
                <h2>Create a User</h2>

                <?php if ($message !== ''): ?>
                    <div class="notice success"><?= e($message) ?></div>
                <?php endif; ?>

                <?php if ($error !== ''): ?>
                    <div class="notice error"><?= e($error) ?></div>
                <?php endif; ?>

                <form method="POST" action="users.php">
                    <input type="hidden" name="csrf_token"
                        value="<?= e($_SESSION['csrf_token']) ?>">

                    <div class="form-grid">
                        <div class="field">
                            <label for="full_name">Full name</label>
                            <input id="full_name" name="full_name"
                                required maxlength="150">
                        </div>

                        <div class="field">
                            <label for="email">Email address</label>
                            <input id="email" name="email" type="email"
                                required maxlength="190">
                        </div>

                        <div class="field">
                            <label for="phone">Phone number</label>
                            <input id="phone" name="phone" type="tel"
                                maxlength="30">
                        </div>

                        <div class="field">
                            <label for="password">Temporary password</label>
                            <input id="password" name="password" type="password"
                                required minlength="8" autocomplete="new-password">
                        </div>

                        <div class="field">
                            <label for="role_name">User role</label>
                            <select id="role_name" name="role_name" required>
                                <option value="">Select role</option>
                                <?php foreach ($allowedRoles as $roleOption): ?>
                                    <option value="<?= e($roleOption) ?>">
                                        <?= e(ucwords(str_replace('_', ' ', $roleOption))) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <?php if ($currentRole === 'super_admin'): ?>
                            <div class="field">
                                <label for="farm_id">Assign farm</label>
                                <select id="farm_id" name="farm_id" required>
                                    <option value="">Select farm</option>
                                    <?php foreach ($farms as $farm): ?>
                                        <option value="<?= e((string)$farm['farm_id']) ?>">
                                            <?= e($farm['farm_name']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        <?php else: ?>
                            <div class="field">
                                <label>Assigned farm</label>
                                <input value="Your assigned farm" disabled>
                                <small>The farm is assigned automatically.</small>
                            </div>
                        <?php endif; ?>
                    </div>

                    <button type="submit">Create User</button>
                </form>
            </section>

            <section class="panel">
                <h2>Existing Users</h2>
                <div class="table-wrap">
                    <table>
                        <thead>
                            <tr>
                                <th>Name</th>
                                <th>Email</th>
                                <th>Role</th>
                                <th>Farm</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!$users): ?>
                                <tr>
                                    <td colspan="5">No users found for this view.</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($users as $user): ?>
                                    <tr>
                                        <td><?= e($user['full_name']) ?></td>
                                        <td><?= e($user['email']) ?></td>
                                        <td><?= e(ucwords(str_replace('_', ' ', $user['role_name']))) ?></td>
                                        <td><?= e($user['farm_name'] ?? 'Not assigned') ?></td>
                                        <td><?= e($user['status'] ?? 'Unknown') ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
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