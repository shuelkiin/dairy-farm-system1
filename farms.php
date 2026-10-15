<?php
require_once __DIR__ . '/includes/auth.php';

requireLogin();
requireRole(['super_admin']);

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
            max-width: 1150px;
            margin: auto;
        }

        .header {
            background: #173d2b;
            color: white;
            padding: 26px;
            border-radius: 12px;
            margin-bottom: 24px;
        }

        .header p {
            color: #dce9df;
        }

        .panel {
            background: white;
            padding: 24px;
            border-radius: 12px;
            margin-bottom: 24px;
            box-shadow: 0 4px 16px rgba(0, 0, 0, 0.06);
        }

        .form-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 18px;
        }

        .field {
            margin-bottom: 8px;
        }

        label {
            display: block;
            margin-bottom: 7px;
            font-weight: bold;
        }

        input {
            width: 100%;
            padding: 12px;
            border: 1px solid #ccd8ce;
            border-radius: 7px;
            font-size: 15px;
        }

        input:focus {
            outline: 2px solid #83bd91;
            border-color: #287447;
        }

        button,
        .back {
            display: inline-block;
            border: none;
            border-radius: 7px;
            padding: 12px 18px;
            color: white;
            background: #287447;
            font-weight: bold;
            text-decoration: none;
            cursor: pointer;
        }

        .back {
            background: #31516b;
            margin-top: 8px;
        }

        button:hover {
            background: #1c5b35;
        }

        .notice {
            padding: 13px;
            border-radius: 7px;
            margin-bottom: 16px;
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
            padding: 13px;
            border-bottom: 1px solid #e4ebe5;
        }

        th {
            background: #edf4ed;
        }

        .status {
            display: inline-block;
            padding: 5px 9px;
            border-radius: 20px;
            background: #e1f5e5;
            color: #17612d;
            font-size: 12px;
            font-weight: bold;
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
            <h1>Manage Farms</h1>
            <p>Register farms and manage your dairy farm locations.</p>

            <a class="back" href="super_admin.php">
                Back to Dashboard
            </a>
        </header>

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