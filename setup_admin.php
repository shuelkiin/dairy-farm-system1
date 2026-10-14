<?php
require_once __DIR__ . '/config/database.php';

$message = '';

$count = (int)$pdo->query("
    SELECT COUNT(*)
    FROM users u
    JOIN roles r ON r.role_id = u.role_id
    WHERE r.role_name = 'super_admin'
")->fetchColumn();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $count === 0) {
    $name = trim($_POST['full_name'] ?? '');
    $email = strtolower(trim($_POST['email'] ?? ''));
    $password = $_POST['password'] ?? '';

    if (
        $name === '' ||
        !filter_var($email, FILTER_VALIDATE_EMAIL) ||
        strlen($password) < 12
    ) {
        $message = 'Enter a valid name, email and password of at least 12 characters.';
    } else {
        $roleId = (int)$pdo->query("
            SELECT role_id FROM roles
            WHERE role_name = 'super_admin'
        ")->fetchColumn();

        $stmt = $pdo->prepare("
            INSERT INTO users
                (role_id, farm_id, full_name, email, password_hash)
            VALUES
                (:role, NULL, :name, :email, :password)
        ");

        try {
            $stmt->execute([
                'role' => $roleId,
                'name' => $name,
                'email' => $email,
                'password' => password_hash(
                    $password,
                    PASSWORD_DEFAULT
                )
            ]);

            $message = 'Super Admin created. Delete setup_admin.php now.';
            $count = 1;
        } catch (PDOException $e) {
            $message = 'Account creation failed. Check the database and email.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Initial Setup</title>
    <link rel="stylesheet" href="assets/style.css">
</head>

<body class="login-page">
    <div class="login-card">
        <div class="logo">DF</div>
        <h1>Create Super Admin</h1>

        <?php if ($message): ?>
            <p><?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?></p>
        <?php endif; ?>

        <?php if ($count === 0): ?>
            <form method="POST" class="form">
                <label>Full name</label>
                <input name="full_name" required>

                <label>Email address</label>
                <input type="email" name="email" required>

                <label>Password (12 characters minimum)</label>
                <input type="password" name="password" minlength="12" required>

                <button class="btn">Create account</button>
            </form>
        <?php else: ?>
            <p><a href="login.php">Go to login</a></p>
        <?php endif; ?>
    </div>
</body>

</html>