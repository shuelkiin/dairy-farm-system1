<?php

declare(strict_types=1);

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/config/mailer.php';

$error = '';
$success = '';

// Local project URL. Change when deployed online.
$baseUrl = 'http://localhost/dairy-farm-system1';

// Generate CSRF token.
if (empty($_SESSION['forgot_password_csrf'])) {
    $_SESSION['forgot_password_csrf'] = bin2hex(random_bytes(32));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $email = trim($_POST['email'] ?? '');
    $csrfToken = $_POST['csrf_token'] ?? '';

    if (
        !is_string($csrfToken) ||
        !hash_equals($_SESSION['forgot_password_csrf'], $csrfToken)
    ) {
        $error = 'Invalid request. Please refresh the page and try again.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {

        $error = 'Please enter a valid email address.';
    } else {

        try {

            $stmt = $pdo->prepare("
                SELECT user_id, full_name, email
                FROM users
                WHERE email = :email
                  AND status = 'active'
                LIMIT 1
            ");

            $stmt->execute(['email' => $email]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($user) {

                // Generate a secure random token.
                $token = bin2hex(random_bytes(32));

                // Store only the SHA-256 token hash.
                $tokenHash = hash('sha256', $token);

                $pdo->beginTransaction();

                // Invalidate previous unused reset requests.
                $invalidate = $pdo->prepare("
                    UPDATE password_resets
                    SET used_at = NOW()
                    WHERE user_id = :user_id
                      AND used_at IS NULL
                ");

                $invalidate->execute([
                    'user_id' => $user['user_id']
                ]);

                // Save token and let MySQL calculate expiry.
                $insert = $pdo->prepare("
                    INSERT INTO password_resets
                    (
                        user_id,
                        token_hash,
                        expires_at
                    )
                    VALUES
                    (
                        :user_id,
                        :token_hash,
                        DATE_ADD(NOW(), INTERVAL 30 MINUTE)
                    )
                ");

                $insert->execute([
                    'user_id' => $user['user_id'],
                    'token_hash' => $tokenHash
                ]);

                $pdo->commit();

                // Build reset link.
                $resetLink = $baseUrl .
                    '/reset-password.php?token=' .
                    urlencode($token);

                // Send email.
                $sent = sendPasswordResetEmail(
                    $user['email'],
                    $user['full_name'],
                    $resetLink
                );

                if (!$sent) {
                    error_log(
                        'DairyFlow: Password reset email could not be delivered.'
                    );
                }
            }

            // Same response whether account exists or not.
            $success = 'If an active account exists with that email address, a password reset link will be sent shortly.';

            // Rotate CSRF token.
            $_SESSION['forgot_password_csrf'] = bin2hex(random_bytes(32));
        } catch (Throwable $e) {

            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            error_log(
                'DairyFlow password reset error: ' . $e->getMessage()
            );

            $error = 'Something went wrong. Please try again later.';
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <title>Forgot Password | DairyFlow</title>

    <style>
        :root {
            --navy: #102a25;
            --green: #23865b;
            --green-dark: #176b46;
            --bg: #f5f8f6;
            --text: #1c3029;
            --muted: #718078;
            --border: #dce7e0;
            --white: #ffffff;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
            background:
                radial-gradient(circle at top left, #d9f0e2, transparent 38%),
                radial-gradient(circle at bottom right, #dceee4, transparent 35%),
                var(--bg);
            font-family: 'DM Sans', 'Segoe UI', sans-serif;
            color: var(--text);
        }

        .card {
            width: 100%;
            max-width: 440px;
            background: var(--white);
            padding: 42px 38px;
            border-radius: 18px;
            border: 1px solid rgba(35, 134, 91, 0.1);
            box-shadow: 0 20px 60px rgba(16, 42, 37, 0.09);
        }

        .logo {
            width: 58px;
            height: 58px;
            display: flex;
            align-items: center;
            justify-content: center;
            background: var(--navy);
            color: white;
            border-radius: 14px;
            font-size: 21px;
            font-weight: 800;
            margin-bottom: 28px;
        }

        .eyebrow {
            color: var(--green);
            font-size: 11px;
            font-weight: 800;
            letter-spacing: 2px;
            margin-bottom: 12px;
        }

        h1 {
            font-size: 30px;
            color: var(--navy);
            margin-bottom: 12px;
        }

        .muted {
            color: var(--muted);
            font-size: 14px;
            line-height: 1.7;
        }

        form {
            display: flex;
            flex-direction: column;
            margin-top: 30px;
        }

        label {
            font-size: 13px;
            font-weight: 700;
            color: var(--navy);
            margin-bottom: 9px;
        }

        input {
            width: 100%;
            height: 49px;
            padding: 0 15px;
            margin-bottom: 20px;
            border: 1px solid var(--border);
            border-radius: 9px;
            background: #fbfdfb;
            font-size: 14px;
            outline: none;
        }

        input:focus {
            border-color: var(--green);
            box-shadow: 0 0 0 4px rgba(35, 134, 91, 0.1);
        }

        button {
            width: 100%;
            height: 51px;
            border: none;
            border-radius: 9px;
            background: var(--green);
            color: white;
            font-size: 15px;
            font-weight: 700;
            cursor: pointer;
            transition: 0.25s;
        }

        button:hover {
            background: var(--green-dark);
            transform: translateY(-2px);
        }

        .message {
            padding: 13px 15px;
            margin-top: 22px;
            border-radius: 8px;
            font-size: 13px;
            line-height: 1.6;
        }

        .success {
            background: #e9f7ef;
            color: #176b46;
            border: 1px solid #c5e8d3;
        }

        .error {
            background: #fff0f0;
            color: #b42318;
            border: 1px solid #f5c6c6;
        }

        .back {
            display: block;
            text-align: center;
            margin-top: 25px;
            color: var(--green);
            font-size: 13px;
            font-weight: 700;
            text-decoration: none;
        }

        .back:hover {
            color: var(--green-dark);
        }

        @media(max-width: 480px) {
            .card {
                padding: 32px 24px;
            }
        }
    </style>
</head>

<body>

    <div class="card">

        <div class="logo">MF</div>

        <p class="eyebrow">DAIRY FARM MANAGEMENT</p>

        <h1>Forgot password?</h1>

        <p class="muted">
            Don't worry. Enter your registered email address
            and we'll send you instructions to reset your password.
        </p>

        <?php if ($error): ?>
            <div class="message error">
                <?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?>
            </div>
        <?php endif; ?>

        <?php if ($success): ?>
            <div class="message success">
                <?= htmlspecialchars($success, ENT_QUOTES, 'UTF-8') ?>
            </div>
        <?php endif; ?>

        <form method="POST">

            <input
                type="hidden"
                name="csrf_token"
                value="<?= htmlspecialchars(
                            $_SESSION['forgot_password_csrf'],
                            ENT_QUOTES,
                            'UTF-8'
                        ) ?>">

            <label for="email">Email address</label>

            <input
                type="email"
                id="email"
                name="email"
                placeholder="you@yourfarm.com"
                autocomplete="email"
                required>

            <button type="submit">
                Send Reset Link →
            </button>

        </form>

        <a href="login.php" class="back">
            ← Back to Login
        </a>

    </div>

</body>

</html>