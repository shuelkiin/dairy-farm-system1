<?php
require_once __DIR__ . '/includes/auth.php';

requireLogin();
requireRole(['super_admin', 'admin']);

$pdo = $pdo ?? null;
$currentRole = $_SESSION['role_name'] ?? '';
$currentFarmId = $_SESSION['farm_id'] ?? null;

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
    <style>
        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            padding: 30px;
            font-family: Arial, sans-serif;
            background: #f3f7f2;
            color: #19342a;
        }

        .container {
            max-width: 1100px;
            margin: auto;
        }

        .header,
        .panel {
            background: white;
            border-radius: 12px;
            padding: 24px;
            margin-bottom: 22px;
            box-shadow: 0 4px 16px rgba(0, 0, 0, .06);
        }

        h1,
        h2 {
            margin-top: 0;
        }

        .header {
            background: #173d2b;
            color: white;
        }

        .header p {
            margin-bottom: 0;
            color: #dce9df;
        }

        .form-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 16px;
        }

        label {
            display: block;
            font-weight: bold;
            margin-bottom: 7px;
        }

        input,
        select {
            width: 100%;
            padding: 12px;
            border: 1px solid #ccd8ce;
            border-radius: 7px;
            font-size: 15px;
        }

        .field {
            margin-bottom: 8px;
        }

        button,
        .back {
            display: inline-block;
            background: #287447;
            color: white;
            padding: 12px 18px;
            border: 0;
            border-radius: 7px;
            text-decoration: none;
            font-weight: bold;
            cursor: pointer;
        }

        button {
            margin-top: 18px;
        }

        .back {
            background: #31516b;
            margin-top: 14px;
        }

        .notice {
            padding: 12px;
            border-radius: 7px;
            margin-bottom: 15px;
        }

        .success {
            background: #e1f5e5;
            color: #17612d;
        }

        .error {
            background: #ffebeb;
            color: #9d2020;
        }

        .table-wrap {
            overflow-x: auto;
        }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        th,
        td {
            text-align: left;
            padding: 12px;
            border-bottom: 1px solid #e4ebe5;
        }

        th {
            background: #edf4ed;
        }

        @media (max-width: 650px) {
            body {
                padding: 14px;
            }

            .form-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>

<body>
    <div class="container">
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
    </div>
</body>

</html>