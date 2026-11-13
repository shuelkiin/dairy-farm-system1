<?php

declare(strict_types=1);

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';

date_default_timezone_set('Africa/Nairobi');

$errors = [];
$success = '';

$token = trim($_GET['token'] ?? '');
$tokenHash = '';

if ($token === '' || !preg_match('/^[a-f0-9]{64}$/', $token)) {
    $errors[] = 'Invalid or missing password reset link.';
} else {
    $tokenHash = hash('sha256', $token);

    $stmt = $pdo->prepare("
        SELECT id, user_id
        FROM password_resets
        WHERE token_hash = ?
          AND used_at IS NULL
          AND expires_at > NOW()
        LIMIT 1
    ");

    $stmt->execute([$tokenHash]);
    $resetRequest = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$resetRequest) {
        $errors[] = 'This password reset link is invalid, expired, or already used.';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && empty($errors)) {

    $password = $_POST['password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';

    if (strlen($password) < 8) {
        $errors[] = 'Password must contain at least 8 characters.';
    }

    if ($password !== $confirmPassword) {
        $errors[] = 'Passwords do not match.';
    }

    if (empty($errors)) {
        try {
            $pdo->beginTransaction();

            // Lock and verify the reset request again.
            $stmt = $pdo->prepare("
                SELECT id, user_id
                FROM password_resets
                WHERE token_hash = ?
                  AND used_at IS NULL
                  AND expires_at > NOW()
                LIMIT 1
                FOR UPDATE
            ");

            $stmt->execute([$tokenHash]);
            $validReset = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$validReset) {
                throw new RuntimeException('This reset link is no longer valid.');
            }

            // Update the user's password.
            $hashedPassword = password_hash($password, PASSWORD_DEFAULT);

            $stmt = $pdo->prepare("
                UPDATE users
                SET password = ?
                WHERE user_id = ?
            ");

            $stmt->execute([
                $hashedPassword,
                $validReset['user_id']
            ]);

            if ($stmt->rowCount() !== 1) {
                throw new RuntimeException('Unable to update your password.');
            }

            // Mark this reset link as used.
            $stmt = $pdo->prepare("
                UPDATE password_resets
                SET used_at = NOW()
                WHERE id = ?
            ");

            $stmt->execute([$validReset['id']]);

            // Invalidate other outstanding reset links for this user.
            $stmt = $pdo->prepare("
                UPDATE password_resets
                SET used_at = NOW()
                WHERE user_id = ?
                  AND used_at IS NULL
            ");

            $stmt->execute([$validReset['user_id']]);

            $pdo->commit();

            $success = 'Your password has been changed successfully. You can now log in.';
        } catch (Throwable $e) {

            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            error_log('Password reset error: ' . $e->getMessage());

            $errors[] = 'Unable to reset your password. Please request a new reset link.';
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reset Password | DairyFlow</title>

    <link rel="stylesheet" href="assets/style.css">

    <style>
        body {
            background: #f5f8f6;
            font-family: Arial, sans-serif;
        }

        .reset-container {
            max-width: 430px;
            margin: 80px auto;
            padding: 35px;
            background: white;
            border-radius: 14px;
            box-shadow: 0 5px 25px rgba(0, 0, 0, 0.08);
        }

        .reset-container h2 {
            color: #102a25;
            margin-bottom: 10px;
        }

        .reset-container p {
            color: #64746d;
            margin-bottom: 25px;
        }

        .reset-container label {
            display: block;
            margin-bottom: 7px;
            font-weight: 600;
        }

        .reset-container input {
            width: 100%;
            padding: 12px;
            margin-bottom: 18px;
            border: 1px solid #d5ded9;
            border-radius: 7px;
            box-sizing: border-box;
        }

        .reset-container button {
            width: 100%;
            padding: 13px;
            border: none;
            border-radius: 7px;
            background: #23865b;
            color: white;
            font-weight: bold;
            cursor: pointer;
        }

        .reset-container button:hover {
            background: #176b46;
        }

        .message {
            padding: 12px;
            border-radius: 7px;
            margin-bottom: 18px;
        }

        .error {
            background: #fdecec;
            color: #a12626;
        }

        .success {
            background: #e9f7ef;
            color: #176b46;
        }

        .reset-container a {
            color: #23865b;
            text-decoration: none;
        }
    </style>
</head>

<body>

    <div class="reset-container">

        <h2>Reset Your Password</h2>
        <p>Enter your new password below.</p>

        <?php foreach ($errors as $error): ?>
            <div class="message error">
                <?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?>
            </div>
        <?php endforeach; ?>

        <?php if ($success): ?>

            <div class="message success">
                <?= htmlspecialchars($success, ENT_QUOTES, 'UTF-8') ?>
            </div>

            <a href="login.php">Return to Login</a>

        <?php elseif (empty($errors)): ?>

            <form method="POST">

                <label for="password">New Password</label>
                <input
                    type="password"
                    id="password"
                    name="password"
                    minlength="8"
                    required
                    autocomplete="new-password">

                <label for="confirm_password">Confirm New Password</label>
                <input
                    type="password"
                    id="confirm_password"
                    name="confirm_password"
                    minlength="8"
                    required
                    autocomplete="new-password">

                <button type="submit">Update Password</button>

            </form>

        <?php endif; ?>

    </div>

</body>

</html>