<?php
require_once __DIR__ . '/includes/auth.php';

if (!empty($_SESSION['user_id'])) {
    goToDashboard();
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    $stmt = $pdo->prepare("
        SELECT u.user_id, u.full_name, u.email,
               u.password_hash, u.farm_id, u.status,
               r.role_name
        FROM users u
        JOIN roles r ON r.role_id = u.role_id
        WHERE u.email = :email
        LIMIT 1
    ");

    $stmt->execute(['email' => $email]);
    $user = $stmt->fetch();

    if (
        $user &&
        $user['status'] === 'active' &&
        password_verify($password, $user['password_hash'])
    ) {
        session_regenerate_id(true);

        $_SESSION['user_id'] = (int)$user['user_id'];
        $_SESSION['full_name'] = $user['full_name'];
        $_SESSION['email'] = $user['email'];
        $_SESSION['farm_id'] = $user['farm_id'];
        $_SESSION['role_name'] = $user['role_name'];

        goToDashboard();
    }

    $error = 'Invalid email or password, or account inactive.';
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Login | DairyFlow</title>
    <link rel="stylesheet" href="assets/style.css">
</head>

<body class="login-page">

    <div class="login-card">
        <div class="logo">DF</div>
        <p class="eyebrow">DAIRY FARM MANAGEMENT</p>
        <h1>Welcome back.</h1>
        <p class="muted">Sign in to manage your farm operations.</p>

        <?php if ($error): ?>
            <div class="error"><?= e($error) ?></div>
        <?php endif; ?>

        <form method="POST" class="form">
            <label for="email">Email address</label>
            <input
                id="email"
                type="email"
                name="email"
                placeholder="you@yourfarm.com"
                autocomplete="username"
                required>

            <label for="password">Password</label>
            <input
                id="password"
                type="password"
                name="password"
                placeholder="Enter your password"
                autocomplete="current-password"
                required>

            <button type="submit" class="btn">Sign in <span>→</span></button>
        </form>

        <p class="secure-note">
            Secure access with role-based permissions.
        </p>
    </div>

</body>

</html>