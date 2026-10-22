<?php
session_start();

require_once __DIR__ . '/config/database.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$userId = (int) $_SESSION['user_id'];

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$message = '';
$messageType = '';
$currentPassword = '';
$newPassword = '';
$confirmPassword = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrfToken = $_POST['csrf_token'] ?? '';
    $currentPassword = $_POST['current_password'] ?? '';
    $newPassword = $_POST['new_password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';

    if (
        !is_string($csrfToken) ||
        !hash_equals($_SESSION['csrf_token'], $csrfToken)
    ) {
        $message = 'Your session verification failed. Refresh the page and try again.';
        $messageType = 'error';
    } elseif (
        !is_string($currentPassword) ||
        !is_string($newPassword) ||
        !is_string($confirmPassword) ||
        $currentPassword === '' ||
        $newPassword === '' ||
        $confirmPassword === ''
    ) {
        $message = 'Please fill in all password fields.';
        $messageType = 'error';
    } elseif ($newPassword !== $confirmPassword) {
        $message = 'The new passwords do not match.';
        $messageType = 'error';
    } elseif (strlen($newPassword) < 8) {
        $message = 'Your new password must contain at least 8 characters.';
        $messageType = 'error';
    } elseif ($newPassword === $currentPassword) {
        $message = 'Your new password must be different from your current password.';
        $messageType = 'error';
    } else {
        try {
            $stmt = $pdo->prepare(
                'SELECT password_hash FROM users WHERE user_id = ? LIMIT 1'
            );
            $stmt->execute([$userId]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$user || !password_verify($currentPassword, $user['password_hash'])) {
                $message = 'Your current password is incorrect.';
                $messageType = 'error';
            } else {
                $newHash = password_hash($newPassword, PASSWORD_DEFAULT);

                $update = $pdo->prepare(
                    'UPDATE users SET password_hash = ? WHERE user_id = ?'
                );
                $update->execute([$newHash, $userId]);

                session_regenerate_id(true);
                $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

                $_SESSION = [];
                session_regenerate_id(true);

                session_destroy();

                session_start();
                $_SESSION['password_change_success'] =
                    'Password changed successfully. Please log in with your new password.';

                header('Location: login.php');
                exit;
            }
        } catch (PDOException $e) {
            error_log('DairyFlow password change error: ' . $e->getMessage());

            $message = 'Unable to change your password right now. Please try again.';
            $messageType = 'error';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Change Password | DairyFlow</title>

    <style>
        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 24px;
            font-family: Arial, sans-serif;
            background: #f1f5f2;
            color: #172b25;
        }

        .password-card {
            width: 100%;
            max-width: 460px;
            padding: 32px;
            background: #fff;
            border-radius: 16px;
            box-shadow: 0 12px 40px rgba(16, 45, 35, 0.10);
        }

        .brand {
            color: #16834a;
            font-size: 14px;
            font-weight: bold;
            letter-spacing: 2px;
            text-transform: uppercase;
        }

        h1 {
            margin: 12px 0 8px;
            font-size: 27px;
        }

        .description {
            margin-bottom: 24px;
            color: #64736b;
            line-height: 1.6;
            font-size: 14px;
        }

        label {
            display: block;
            margin: 18px 0 8px;
            font-size: 14px;
            font-weight: bold;
        }

        input {
            width: 100%;
            padding: 13px 14px;
            border: 1px solid #d7e1da;
            border-radius: 8px;
            font-size: 15px;
        }

        input:focus {
            outline: 2px solid #16834a;
            border-color: transparent;
        }

        button {
            width: 100%;
            margin-top: 24px;
            padding: 14px;
            border: none;
            border-radius: 8px;
            background: #16834a;
            color: white;
            font-size: 15px;
            font-weight: bold;
            cursor: pointer;
        }

        button:hover {
            background: #10683a;
        }

        .message {
            padding: 12px;
            margin: 18px 0;
            border-radius: 8px;
            font-size: 14px;
            line-height: 1.5;
        }

        .error {
            color: #9c2626;
            background: #fff0f0;
        }

        .success {
            color: #126638;
            background: #eaf8ef;
        }

        .back-link {
            display: block;
            margin-top: 22px;
            text-align: center;
            color: #16834a;
            text-decoration: none;
            font-size: 14px;
        }
    </style>
</head>

<body>
    <main class="password-card">
        <div class="brand">DairyFlow</div>

        <h1>Change password</h1>

        <p class="description">
            Update your account password to keep your DairyFlow account secure.
        </p>

        <?php if ($message !== ''): ?>
            <div class="message <?= htmlspecialchars($messageType) ?>"
                role="status">
                <?= htmlspecialchars($message) ?>
            </div>
        <?php endif; ?>

        <form method="POST" action="change_password.php">
            <input
                type="hidden"
                name="csrf_token"
                value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">

            <label for="current_password">Current password</label>
            <input
                type="password"
                id="current_password"
                name="current_password"
                autocomplete="current-password"
                required>

            <label for="new_password">New password</label>
            <input
                type="password"
                id="new_password"
                name="new_password"
                minlength="8"
                autocomplete="new-password"
                required>

            <label for="confirm_password">Confirm new password</label>
            <input
                type="password"
                id="confirm_password"
                name="confirm_password"
                minlength="8"
                autocomplete="new-password"
                required>

            <button type="submit">Update Password</button>
        </form>

        <a class="back-link" href="index.php">← Back to dashboard</a>
    </main>
</body>

</html>