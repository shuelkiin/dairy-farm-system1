<?php
require_once __DIR__ . '/config/database.php';
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
    <style>
        /* =========================================
   DAIRYFLOW LOGIN PAGE
   ========================================= */

        :root {
            --navy: #102a25;
            --navy-light: #183b33;
            --green: #23865b;
            --green-dark: #176b46;
            --mint: #e9f7ef;
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

        body.login-page {
            
    margin: 0;
    min-height: 100vh;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 24px;
    font-family: Arial, sans-serif;

    background:
        linear-gradient(rgba(0, 0, 0, 0.20), rgba(0, 0, 0, 0.20)),
        url('./assets/images/bg.png');

    background-size: cover;
    background-position: center;
    background-repeat: no-repeat;
    background-attachment: fixed;


            
        }

        /* Login Card */

        .login-card {
            width: 100%;
            max-width: 440px;
            background: var(--white);
            padding: 45px 40px;
            border-radius: 18px;
            border: 1px solid rgba(35, 134, 91, 0.1);
            box-shadow: 0 20px 60px rgba(16, 42, 37, 0.09);
            animation: fadeIn 0.6s ease;
        }

        /* Logo */

        .logo {
            width: 58px;
            height: 58px;
            display: flex;
            align-items: center;
            justify-content: center;
            background: var(--navy);
            color: var(--white);
            border-radius: 14px;
            font-size: 21px;
            font-weight: 800;
            letter-spacing: -1px;
            margin-bottom: 28px;
            box-shadow: 0 7px 18px rgba(16, 42, 37, 0.18);
        }

        /* Headings */

        .eyebrow {
            color: var(--green);
            font-size: 11px;
            font-weight: 800;
            letter-spacing: 2px;
            margin-bottom: 12px;
        }

        .login-card h1 {
            font-size: 32px;
            font-weight: 800;
            color: var(--navy);
            letter-spacing: -1px;
            margin-bottom: 10px;
        }

        .muted {
            color: var(--muted);
            font-size: 14px;
            line-height: 1.7;
        }

        /* Form */

        .form {
            display: flex;
            flex-direction: column;
            margin-top: 32px;
        }

        .form label {
            font-size: 13px;
            font-weight: 700;
            color: var(--navy);
            margin-bottom: 9px;
        }

        .form input {
            width: 100%;
            height: 49px;
            padding: 0 15px;
            margin-bottom: 22px;
            border: 1px solid var(--border);
            border-radius: 9px;
            background: #fbfdfb;
            color: var(--text);
            font-size: 14px;
            outline: none;
            transition: 0.25s ease;
        }

        .form input::placeholder {
            color: #a5b1aa;
        }

        .form input:focus {
            border-color: var(--green);
            background: var(--white);
            box-shadow: 0 0 0 4px rgba(35, 134, 91, 0.10);
        }

        /* Login Button */

        .btn {
            width: 100%;
            height: 51px;
            border: none;
            border-radius: 9px;
            background: var(--green);
            color: var(--white);
            font-size: 15px;
            font-weight: 700;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 12px;
            transition: 0.25s ease;
        }

        .btn span {
            font-size: 20px;
            transition: transform 0.25s ease;
        }

        .btn:hover {
            background: var(--green-dark);
            box-shadow: 0 8px 20px rgba(35, 134, 91, 0.2);
            transform: translateY(-2px);
        }

        .btn:hover span {
            transform: translateX(4px);
        }

        .btn:active {
            transform: translateY(0);
        }

        /* Error Message */

        .error {
            background: #fff0f0;
            color: #b42318;
            border: 1px solid #f5c6c6;
            border-radius: 8px;
            padding: 12px 14px;
            font-size: 13px;
            margin-top: 22px;
            line-height: 1.5;
        }

        /* Security Note */

        .secure-note {
            text-align: center;
            color: var(--muted);
            font-size: 12px;
            margin-top: 28px;
            padding-top: 20px;
            border-top: 1px solid #edf1ee;
        }

        /* Animation */

        @keyframes fadeIn {
            from {
                opacity: 0;
                transform: translateY(15px);
            }

            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        /* Responsive Design */

        @media (max-width: 480px) {
            body.login-page {
                padding: 16px;
            }

            .login-card {
                padding: 32px 24px;
            }

            .login-card h1 {
                font-size: 28px;
            }
        }


        .password-wrapper {
            position: relative;
            width: 100%;
        }

        .password-wrapper input {
            padding-right: 65px;
        }

        .toggle-password {
            position: absolute;
            right: 14px;
            top: 14px;
            border: none;
            background: transparent;
            color: var(--green);
            font-size: 12px;
            font-weight: 700;
            cursor: pointer;
        }

        .toggle-password:hover {
            color: var(--green-dark);
        }
    </style>
</head>

<body class="login-page">

    <div class="login-card">
        <div class="logo">MF</div>
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

            <div class="password-wrapper">
                <input
                    id="password"
                    type="password"
                    name="password"
                    placeholder="Enter your password"
                    autocomplete="current-password"
                    required>

                <button type="button" class="toggle-password" id="togglePassword">
                    Show
                </button>
            </div>
            <div style="text-align:right; margin-bottom:12px;">
                <a href="forgot-password.php"
                    style="color:#23865b; font-size:13px;
       font-weight:700; text-decoration:none;">
                    Forgot password?
                </a>
            </div>

            <button type="submit" class="btn">Sign in <span>→</span></button>
        </form>

        <p class="secure-note">
            Secure access with role-based permissions.
        </p>
    </div>
    <script>
        const passwordInput = document.getElementById('password');
        const togglePassword = document.getElementById('togglePassword');

        togglePassword.addEventListener('click', function() {
            const isPassword = passwordInput.type === 'password';

            passwordInput.type = isPassword ? 'text' : 'password';
            togglePassword.textContent = isPassword ? 'Hide' : 'Show';
        });
    </script>
</body>

</html>