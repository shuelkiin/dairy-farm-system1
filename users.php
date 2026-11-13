<?php
require_once __DIR__ . '/includes/auth.php';

// ========================================
// LIVE ATTENDANCE SEARCH (AJAX)
// ========================================

if (isset($_GET['ajax']) && $_GET['ajax'] === 'attendance_search') {

    requireLogin();
    requireRole(['super_admin', 'admin']);

    $currentRole = $_SESSION['role_name'];
    $currentFarmId = $_SESSION['farm_id'] ?? null;
    $isSuperAdmin = $currentRole === 'super_admin';

    $search = trim($_GET['search'] ?? '');

    if ($search === '') {
        exit;
    }

    $sql = "
        SELECT
            u.user_id,
            u.full_name,
            u.email,
            r.role_name,
            f.farm_name
        FROM users u
        JOIN roles r ON r.role_id = u.role_id
        LEFT JOIN farms f ON f.farm_id = u.farm_id
        WHERE u.status = 'active'
          AND r.role_name IN ('admin', 'farm_manager', 'store_manager')
          AND u.full_name LIKE :search
    ";

    $params = [
        'search' => '%' . $search . '%'
    ];

    if (!$isSuperAdmin) {
        $sql .= " AND u.farm_id = :farm_id";
        $params['farm_id'] = $currentFarmId;
    }

    $sql .= " ORDER BY u.full_name ASC LIMIT 50";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (!$users) {
        echo '<p class="empty-state">No matching staff members found.</p>';
        exit;
    }
?>

    <div class="attendance-search-results">
        <table>
            <thead>
                <tr>
                    <th>Staff Name</th>
                    <th>Role</th>

                    <?php if ($isSuperAdmin): ?>
                        <th>Farm</th>
                    <?php endif; ?>

                    <th>Action</th>
                </tr>
            </thead>

            <tbody>
                <?php foreach ($users as $user): ?>
                    <tr>
                        <td><?= e($user['full_name']) ?></td>
                        <td><?= e(ucwords(str_replace('_', ' ', $user['role_name']))) ?></td>

                        <?php if ($isSuperAdmin): ?>
                            <td><?= e($user['farm_name'] ?? 'N/A') ?></td>
                        <?php endif; ?>

                        <td>
                            <a
                                class="view-attendance-link"
                                href="users.php?attendance_user_id=<?= (int) $user['user_id'] ?>">
                                View Attendance
                            </a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

<?php
    exit;
}


date_default_timezone_set('Africa/Nairobi');

requireLogin();
requireRole(['super_admin', 'admin']);

$currentRole = $_SESSION['role_name'] ?? '';
$currentFarmId = $_SESSION['farm_id'] ?? null;
$isSuperAdmin = ($currentRole === 'super_admin');
$role = $currentRole;

$pageTitle = $isSuperAdmin
    ? 'Manage Users'
    : 'Manage Farm Users';

$pageDescription = $isSuperAdmin
    ? 'Manage system users, staff attendance and farm access.'
    : 'Manage your farm users and monitor daily staff attendance.';

$currentUserName = $_SESSION['full_name'] ?? 'User';

$allowedRoles = ($currentRole === 'super_admin')
    ? ['admin', 'store_manager']
    : ['farm_manager'];

$message = '';
$error = '';

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Automatically initialize today's attendance.
$today = date('Y-m-d');

$initializeAttendance = $pdo->prepare("
    INSERT IGNORE INTO staff_attendance
        (user_id, farm_id, attendance_date, attendance_status, marked_by)
    SELECT
        u.user_id,
        u.farm_id,
        :attendance_date,
        CASE
            WHEN r.role_name = 'admin' THEN 'present'
            ELSE 'unmarked'
        END,
        NULL
    FROM users u
    JOIN roles r ON r.role_id = u.role_id
    WHERE u.status = 'active'
      AND u.farm_id IS NOT NULL
      AND r.role_name IN ('admin', 'farm_manager', 'store_manager')
");

$initializeAttendance->execute([
    'attendance_date' => $today
]);

$farmQuery = $pdo->query(
    'SELECT farm_id, farm_name FROM farms ORDER BY farm_name'
);
$farms = $farmQuery->fetchAll(PDO::FETCH_ASSOC);


// Handle staff attendance marking.
if (
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    ($_POST['action'] ?? '') === 'mark_attendance'
) {
    try {
        if ($currentRole !== 'admin') {
            throw new RuntimeException(
                'Only farm admins can mark staff attendance.'
            );
        }

        if (!hash_equals(
            $_SESSION['csrf_token'],
            $_POST['csrf_token'] ?? ''
        )) {
            throw new RuntimeException(
                'Security check failed. Refresh the page.'
            );
        }

        $targetUserId = filter_var(
            $_POST['user_id'] ?? '',
            FILTER_VALIDATE_INT
        );

        $attendanceStatus = $_POST['attendance_status'] ?? '';

        if (!$targetUserId) {
            throw new RuntimeException('Invalid staff member.');
        }

        if (!in_array(
            $attendanceStatus,
            ['present', 'absent'],
            true
        )) {
            throw new RuntimeException('Invalid attendance status.');
        }

        // Confirm the staff member belongs to this admin's farm.
        // Admins cannot modify another admin's attendance.
        $checkStaff = $pdo->prepare("
            SELECT u.user_id
            FROM users u
            JOIN roles r ON r.role_id = u.role_id
            WHERE u.user_id = :user_id
              AND u.farm_id = :farm_id
              AND u.status = 'active'
              AND r.role_name IN ('farm_manager', 'store_manager')
            LIMIT 1
        ");

        $checkStaff->execute([
            'user_id' => $targetUserId,
            'farm_id' => $currentFarmId
        ]);

        if (!$checkStaff->fetch()) {
            throw new RuntimeException(
                'You cannot modify this staff member.'
            );
        }

        $updateAttendance = $pdo->prepare("
            UPDATE staff_attendance
            SET attendance_status = :attendance_status,
                marked_by = :marked_by
            WHERE user_id = :user_id
              AND farm_id = :farm_id
              AND attendance_date = :attendance_date
        ");

        $updateAttendance->execute([
            'attendance_status' => $attendanceStatus,
            'marked_by' => $_SESSION['user_id'],
            'user_id' => $targetUserId,
            'farm_id' => $currentFarmId,
            'attendance_date' => $today
        ]);

        $message = 'Attendance updated successfully.';
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    } catch (RuntimeException $exception) {
        $error = $exception->getMessage();
    } catch (PDOException $exception) {
        error_log($exception->getMessage());
        $error = 'Could not update attendance. Please try again.';
    }
}

if (
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    ($_POST['action'] ?? '') === 'create_user'
) {
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
        $roleRecord = $roleQuery->fetch(PDO::FETCH_ASSOC);

        if (!$roleRecord) {
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
            'role_id' => $roleRecord['role_id'],
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

// Fetch today's attendance records.
if ($isSuperAdmin) {

    $attendanceQuery = $pdo->prepare("
        SELECT
            sa.attendance_id,
            sa.user_id,
            sa.farm_id,
            sa.attendance_status,
            sa.attendance_date,
            u.full_name,
            r.role_name,
            f.farm_name,
            marker.full_name AS marked_by_name
        FROM staff_attendance sa
        JOIN users u ON u.user_id = sa.user_id
        JOIN roles r ON r.role_id = u.role_id
        LEFT JOIN farms f ON f.farm_id = sa.farm_id
        LEFT JOIN users marker ON marker.user_id = sa.marked_by
        WHERE sa.attendance_date = :today
        ORDER BY f.farm_name, r.role_name, u.full_name
    ");

    $attendanceQuery->execute(['today' => $today]);
} else {

    $attendanceQuery = $pdo->prepare("
        SELECT
            sa.attendance_id,
            sa.user_id,
            sa.farm_id,
            sa.attendance_status,
            sa.attendance_date,
            u.full_name,
            r.role_name,
            f.farm_name,
            marker.full_name AS marked_by_name
        FROM staff_attendance sa
        JOIN users u ON u.user_id = sa.user_id
        JOIN roles r ON r.role_id = u.role_id
        LEFT JOIN farms f ON f.farm_id = sa.farm_id
        LEFT JOIN users marker ON marker.user_id = sa.marked_by
        WHERE sa.attendance_date = :today
          AND sa.farm_id = :farm_id
        ORDER BY r.role_name, u.full_name
    ");

    $attendanceQuery->execute([
        'today' => $today,
        'farm_id' => $currentFarmId
    ]);
}

$attendanceRecords = $attendanceQuery->fetchAll(PDO::FETCH_ASSOC);

$presentCount = 0;
$absentCount = 0;
$unmarkedCount = 0;

foreach ($attendanceRecords as $attendance) {
    if ($attendance['attendance_status'] === 'present') {
        $presentCount++;
    } elseif ($attendance['attendance_status'] === 'absent') {
        $absentCount++;
    } else {
        $unmarkedCount++;
    }
}

// ========================================
// INDIVIDUAL STAFF ATTENDANCE SEARCH
// ========================================

$attendanceSearch = trim($_GET['attendance_search'] ?? '');
$selectedAttendanceUserId = filter_var(
    $_GET['attendance_user_id'] ?? '',
    FILTER_VALIDATE_INT
);

$attendanceSearchResults = [];
$selectedAttendanceUser = null;
$individualAttendance = [];
$monthlyAttendance = [];

$individualPresent = 0;
$individualAbsent = 0;
$individualUnmarked = 0;
$individualTotal = 0;
$individualRate = 0;

// Search users by name.
if ($attendanceSearch !== '') {

    $searchSql = "
        SELECT
            u.user_id,
            u.full_name,
            u.email,
            r.role_name,
            f.farm_name
        FROM users u
        JOIN roles r ON r.role_id = u.role_id
        LEFT JOIN farms f ON f.farm_id = u.farm_id
        WHERE u.status = 'active'
          AND r.role_name IN ('admin', 'farm_manager', 'store_manager')
          AND u.full_name LIKE :search
    ";

    if (!$isSuperAdmin) {
        $searchSql .= " AND u.farm_id = :farm_id";
    }

    $searchSql .= " ORDER BY u.full_name ASC LIMIT 50";

    $searchQuery = $pdo->prepare($searchSql);

    $searchParams = [
        'search' => '%' . $attendanceSearch . '%'
    ];

    if (!$isSuperAdmin) {
        $searchParams['farm_id'] = $currentFarmId;
    }

    $searchQuery->execute($searchParams);

    $attendanceSearchResults = $searchQuery->fetchAll(PDO::FETCH_ASSOC);
}

// Load the selected user's attendance history.
if ($selectedAttendanceUserId) {

    $userSql = "
        SELECT
            u.user_id,
            u.full_name,
            u.email,
            u.farm_id,
            r.role_name,
            f.farm_name
        FROM users u
        JOIN roles r ON r.role_id = u.role_id
        LEFT JOIN farms f ON f.farm_id = u.farm_id
        WHERE u.user_id = :user_id
          AND r.role_name IN ('admin', 'farm_manager', 'store_manager')
    ";

    if (!$isSuperAdmin) {
        $userSql .= " AND u.farm_id = :farm_id";
    }

    $userSql .= " LIMIT 1";

    $userQuery = $pdo->prepare($userSql);

    $userParams = [
        'user_id' => $selectedAttendanceUserId
    ];

    if (!$isSuperAdmin) {
        $userParams['farm_id'] = $currentFarmId;
    }

    $userQuery->execute($userParams);

    $selectedAttendanceUser = $userQuery->fetch(PDO::FETCH_ASSOC);

    if ($selectedAttendanceUser) {

        // Get all attendance records.
        $historyQuery = $pdo->prepare("
            SELECT
                sa.attendance_date,
                sa.attendance_status,
                marker.full_name AS marked_by_name
            FROM staff_attendance sa
            LEFT JOIN users marker ON marker.user_id = sa.marked_by
            WHERE sa.user_id = :user_id
            ORDER BY sa.attendance_date DESC
        ");

        $historyQuery->execute([
            'user_id' => $selectedAttendanceUserId
        ]);

        $individualAttendance = $historyQuery->fetchAll(PDO::FETCH_ASSOC);

        // Calculate attendance statistics.
        foreach ($individualAttendance as $record) {

            if ($record['attendance_status'] === 'present') {
                $individualPresent++;
            } elseif ($record['attendance_status'] === 'absent') {
                $individualAbsent++;
            } else {
                $individualUnmarked++;
            }
        }

        $individualTotal = $individualPresent + $individualAbsent;

        if ($individualTotal > 0) {
            $individualRate = round(
                ($individualPresent / $individualTotal) * 100,
                1
            );
        }

        // Monthly attendance chart data.
        $monthlyQuery = $pdo->prepare("
            SELECT
                DATE_FORMAT(attendance_date, '%Y-%m') AS attendance_month,
                SUM(CASE
                    WHEN attendance_status = 'present' THEN 1
                    ELSE 0
                END) AS present_days,
                SUM(CASE
                    WHEN attendance_status = 'absent' THEN 1
                    ELSE 0
                END) AS absent_days
            FROM staff_attendance
            WHERE user_id = :user_id
            GROUP BY DATE_FORMAT(attendance_date, '%Y-%m')
            ORDER BY attendance_month ASC
        ");

        $monthlyQuery->execute([
            'user_id' => $selectedAttendanceUserId
        ]);

        $monthlyAttendance = $monthlyQuery->fetchAll(PDO::FETCH_ASSOC);
    }
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

        /* DAILY ATTENDANCE */

        .attendance-heading {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 15px;
            margin-bottom: 22px;
        }

        .attendance-heading h2 {
            margin-bottom: 6px;
        }

        .attendance-heading p {
            margin: 0;
            color: var(--muted);
            font-size: 12px;
        }

        .attendance-date {
            background: var(--mint);
            color: var(--green-dark);
            padding: 10px 14px;
            border-radius: 8px;
            font-size: 12px;
            font-weight: 700;
        }

        .attendance-stats {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 15px;
            margin-bottom: 24px;
        }

        .attendance-stat {
            padding: 17px;
            border-radius: 10px;
            display: flex;
            flex-direction: column;
            gap: 8px;
        }

        .attendance-stat span {
            font-size: 12px;
            font-weight: 600;
        }

        .attendance-stat strong {
            font-family: 'Manrope', sans-serif;
            font-size: 25px;
        }

        .present-stat {
            background: #e7f7ed;
            color: #176b46;
        }

        .absent-stat {
            background: #fff0f0;
            color: #b42318;
        }

        .unmarked-stat {
            background: #fff7e6;
            color: #986500;
        }

        .attendance-badge {
            display: inline-block;
            padding: 6px 10px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 700;
        }

        .attendance-badge.present {
            background: #e7f7ed;
            color: #176b46;
        }

        .attendance-badge.absent {
            background: #fff0f0;
            color: #b42318;
        }

        .attendance-badge.unmarked {
            background: #fff7e6;
            color: #986500;
        }

        .attendance-form {
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .attendance-form select {
            min-width: 110px;
            padding: 8px;
            font-size: 11px;
        }

        .attendance-form button {
            margin: 0;
            padding: 9px 13px;
            border: none;
            border-radius: 7px;
            background: var(--green);
            color: white;
            font-size: 11px;
            font-weight: 700;
            cursor: pointer;
        }

        .attendance-form button:hover {
            background: var(--green-dark);
        }

        .system-label {
            color: var(--green-dark);
            font-size: 11px;
            font-weight: 700;
        }

        @media(max-width: 600px) {
            .attendance-stats {
                grid-template-columns: 1fr;
            }

            .attendance-heading {
                align-items: flex-start;
                flex-direction: column;
            }

            .attendance-form {
                min-width: 190px;
            }
        }


        /* INDIVIDUAL ATTENDANCE SEARCH */

        .attendance-search-form {
            display: flex;
            align-items: flex-end;
            gap: 12px;
            margin-bottom: 22px;
        }

        .attendance-search-input {
            flex: 1;
        }

        .attendance-search-input label {
            margin-bottom: 8px;
        }

        .attendance-search-button {
            padding: 12px 20px;
            border: none;
            border-radius: 8px;
            background: var(--green);
            color: white;
            font-family: inherit;
            font-size: 13px;
            font-weight: 700;
            cursor: pointer;
            white-space: nowrap;
        }

        .attendance-search-button:hover {
            background: var(--green-dark);
        }

        .search-results {
            margin-top: 25px;
        }

        .search-results h3,
        .individual-attendance h3 {
            font-family: 'Manrope', sans-serif;
            font-size: 15px;
            color: var(--navy);
            margin: 0 0 15px;
        }

        .view-attendance-link {
            display: inline-block;
            padding: 8px 12px;
            background: var(--mint);
            color: var(--green-dark);
            border-radius: 7px;
            font-size: 11px;
            font-weight: 700;
            text-decoration: none;
        }

        .view-attendance-link:hover {
            background: #d8efdf;
        }

        .individual-attendance {
            margin-top: 30px;
            padding-top: 25px;
            border-top: 1px solid var(--line);
        }

        .individual-user-heading {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 15px;
            margin-bottom: 22px;
        }

        .individual-user-heading h3 {
            margin-bottom: 5px;
        }

        .individual-user-heading p {
            color: var(--muted);
            font-size: 12px;
            margin: 0;
        }

        .clear-search-link {
            color: var(--green-dark);
            background: var(--mint);
            text-decoration: none;
            padding: 9px 13px;
            border-radius: 7px;
            font-size: 11px;
            font-weight: 700;
        }

        .individual-stats {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 13px;
            margin-bottom: 30px;
        }

        .individual-stat {
            background: #f5f8f6;
            border: 1px solid var(--line);
            border-radius: 10px;
            padding: 17px;
        }

        .individual-stat span {
            display: block;
            color: var(--muted);
            font-size: 11px;
            font-weight: 600;
            margin-bottom: 9px;
        }

        .individual-stat strong {
            font-family: 'Manrope', sans-serif;
            font-size: 24px;
        }

        .stat-green {
            color: var(--green-dark);
        }

        .stat-red {
            color: #b42318;
        }

        .stat-orange {
            color: #986500;
        }

        .attendance-chart-container {
            margin: 25px 0 35px;
            padding: 20px;
            border: 1px solid var(--line);
            border-radius: 11px;
        }

        .chart-heading {
            margin-bottom: 15px;
        }

        .chart-heading h3 {
            margin-bottom: 5px;
        }

        .chart-heading p {
            color: var(--muted);
            font-size: 11px;
            margin: 0;
        }

        .attendance-chart-container canvas {
            width: 100% !important;
            height: 320px !important;
        }

        .history-table-section h3 {
            margin-bottom: 15px;
        }

        .empty-attendance {
            color: var(--muted);
            font-size: 12px;
            padding: 15px 0;
        }

        @media(max-width: 760px) {
            .individual-stats {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }

            .attendance-search-form {
                align-items: stretch;
                flex-direction: column;
            }

            .attendance-search-button {
                width: 100%;
            }
        }

        @media(max-width: 420px) {
            .individual-stats {
                grid-template-columns: 1fr 1fr;
                gap: 8px;
            }

            .individual-stat {
                padding: 12px;
            }

            .individual-stat strong {
                font-size: 20px;
            }

            .individual-user-heading {
                align-items: flex-start;
                flex-direction: column;
            }
        }


        /* TOPBAR */
        .topbar {
            width: 100%;
            min-height: 88px;
            padding: 20px 32px;
            background: #ffffff;
            border-bottom: 1px solid var(--line);
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 20px;
            flex-wrap: wrap;
            box-sizing: border-box;
        }

        .topbar>div:first-child {
            display: flex;
            flex-direction: column;
            gap: 6px;
        }

        .eyebrow {
            color: var(--green);
            font-size: 10px;
            font-weight: 800;
            letter-spacing: 1.5px;
            text-transform: uppercase;
        }

        .topbar h1 {
            margin: 0;
            color: var(--navy);
            font-family: 'Manrope', sans-serif;
            font-size: 25px;
            font-weight: 800;
            line-height: 1.3;
        }

        .topbar-right {
            display: flex;
            align-items: center;
            gap: 10px;
            color: var(--green-dark);
            font-size: 12px;
            font-weight: 700;
            white-space: nowrap;
        }

        .status-dot {
            width: 8px;
            height: 8px;
            display: inline-block;
            background: #25a568;
            border-radius: 50%;
            box-shadow: 0 0 0 3px rgba(37, 165, 104, 0.12);
        }

        .top-avatar {
            width: 40px;
            height: 40px;
            margin-left: 14px;
            display: flex;
            align-items: center;
            justify-content: center;
            background: var(--mint);
            color: var(--green-dark);
            border: 1px solid #d9eee1;
            border-radius: 50%;
            font-size: 14px;
            font-weight: 800;
        }

        /* MOBILE */
        @media (max-width: 600px) {
            .topbar {
                padding: 75px 18px 20px;
                align-items: flex-start;
            }

            .topbar h1 {
                font-size: 21px;
            }

            .topbar-right {
                align-self: flex-end;
            }
        }
    </style>

</head>

<body>
    <div class="app-shell">

        <aside class="sidebar">

            <a href="index.php" class="brand">
                <span class="logo small-logo">MF</span>
                <span>
                    MwendAntu
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
                    <a class="active" href="users.php">♙ &nbsp; Manage Farm Users</a>
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
            <header class="topbar">
                <div>
                    <span class="eyebrow">DAIRY FARM MANAGEMENT</span>
                    <h1><?= e($pageTitle) ?></h1>
                </div>
                <div class="topbar-right">
                    <span class="status-dot"></span> System online
                    <span class="top-avatar"><?= e(strtoupper(substr($_SESSION['full_name'], 0, 1))) ?></span>
                </div>
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
                    <input type="hidden" name="action" value="create_user">
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


            <section class="panel attendance-history-panel">

                <div class="attendance-heading">
                    <div>
                        <h2>Search Staff Attendance</h2>
                        <p>Search for a user to view their complete attendance history.</p>
                    </div>
                </div>

                <form method="GET" action="users.php" id="attendanceSearchForm" class="attendance-search-form">
                    <input
                        type="text"
                        name="attendance_search"
                        id="attendanceSearchInput"
                        placeholder="Start typing a staff name..."
                        value="<?= e($attendanceSearch) ?>"
                        autocomplete="off">
                </form>

                <div id="attendanceSearchResults">
                    <!-- Search results will appear here -->
                </div>

                <?php if ($attendanceSearch !== ''): ?>

                    <div class="search-results">

                        <h3>Search Results</h3>

                        <?php if (empty($attendanceSearchResults)): ?>

                            <p class="empty-attendance">
                                No matching users found.
                            </p>

                        <?php else: ?>

                            <div class="table-wrap">
                                <table>
                                    <thead>
                                        <tr>
                                            <th>Name</th>
                                            <th>Role</th>

                                            <?php if ($isSuperAdmin): ?>
                                                <th>Farm</th>
                                            <?php endif; ?>

                                            <th>Action</th>
                                        </tr>
                                    </thead>

                                    <tbody>
                                        <?php foreach ($attendanceSearchResults as $result): ?>

                                            <tr>
                                                <td>
                                                    <strong><?= e($result['full_name']) ?></strong>
                                                </td>

                                                <td>
                                                    <?= e(ucwords(str_replace(
                                                        '_',
                                                        ' ',
                                                        $result['role_name']
                                                    ))) ?>
                                                </td>

                                                <?php if ($isSuperAdmin): ?>
                                                    <td><?= e($result['farm_name'] ?? 'N/A') ?></td>
                                                <?php endif; ?>

                                                <td>
                                                    <a
                                                        class="view-attendance-link"
                                                        href="users.php?attendance_user_id=<?= (int)$result['user_id'] ?>">
                                                        View Attendance
                                                    </a>
                                                </td>
                                            </tr>

                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>

                        <?php endif; ?>

                    </div>

                <?php endif; ?>


                <?php if ($selectedAttendanceUser): ?>

                    <div class="individual-attendance">

                        <div class="individual-user-heading">
                            <div>
                                <h3><?= e($selectedAttendanceUser['full_name']) ?></h3>

                                <p>
                                    <?= e(ucwords(str_replace(
                                        '_',
                                        ' ',
                                        $selectedAttendanceUser['role_name']
                                    ))) ?>

                                    <?php if ($isSuperAdmin): ?>
                                        &nbsp; | &nbsp;
                                        <?= e($selectedAttendanceUser['farm_name'] ?? 'No farm') ?>
                                    <?php endif; ?>
                                </p>
                            </div>

                            <a href="users.php" class="clear-search-link">
                                Clear Search
                            </a>
                        </div>


                        <!-- ATTENDANCE STATISTICS -->

                        <div class="individual-stats">

                            <div class="individual-stat">
                                <span>Present Days</span>
                                <strong class="stat-green">
                                    <?= $individualPresent ?>
                                </strong>
                            </div>

                            <div class="individual-stat">
                                <span>Absent Days</span>
                                <strong class="stat-red">
                                    <?= $individualAbsent ?>
                                </strong>
                            </div>

                            <div class="individual-stat">
                                <span>Unmarked Days</span>
                                <strong class="stat-orange">
                                    <?= $individualUnmarked ?>
                                </strong>
                            </div>

                            <div class="individual-stat">
                                <span>Attendance Rate</span>
                                <strong class="stat-green">
                                    <?= number_format($individualRate, 1) ?>%
                                </strong>
                            </div>

                        </div>


                        <!-- MONTHLY ATTENDANCE CHART -->

                        <div class="attendance-chart-container">

                            <div class="chart-heading">
                                <div>
                                    <h3>Attendance History Chart</h3>
                                    <p>Monthly attendance records since registration.</p>
                                </div>
                            </div>

                            <?php if (!empty($monthlyAttendance)): ?>

                                <canvas id="individualAttendanceChart"></canvas>

                            <?php else: ?>

                                <p class="empty-attendance">
                                    No attendance records are available for this user yet.
                                </p>

                            <?php endif; ?>

                        </div>


                        <!-- COMPLETE ATTENDANCE HISTORY -->

                        <div class="history-table-section">

                            <h3>Complete Attendance Records</h3>

                            <div class="table-wrap">

                                <table>
                                    <thead>
                                        <tr>
                                            <th>Date</th>
                                            <th>Day</th>
                                            <th>Attendance</th>
                                            <th>Marked By</th>
                                        </tr>
                                    </thead>

                                    <tbody>

                                        <?php if (empty($individualAttendance)): ?>

                                            <tr>
                                                <td colspan="4">
                                                    No attendance records found.
                                                </td>
                                            </tr>

                                        <?php else: ?>

                                            <?php foreach ($individualAttendance as $record): ?>

                                                <tr>
                                                    <td>
                                                        <?= date(
                                                            'd M Y',
                                                            strtotime($record['attendance_date'])
                                                        ) ?>
                                                    </td>

                                                    <td>
                                                        <?= date(
                                                            'l',
                                                            strtotime($record['attendance_date'])
                                                        ) ?>
                                                    </td>

                                                    <td>
                                                        <span class="attendance-badge <?= e($record['attendance_status']) ?>">
                                                            <?= e(ucfirst($record['attendance_status'])) ?>
                                                        </span>
                                                    </td>

                                                    <td>
                                                        <?= e($record['marked_by_name'] ?? 'System') ?>
                                                    </td>
                                                </tr>

                                            <?php endforeach; ?>

                                        <?php endif; ?>

                                    </tbody>
                                </table>

                            </div>

                        </div>

                    </div>

                <?php elseif ($selectedAttendanceUserId): ?>

                    <div class="notice error">
                        User not found or you do not have permission to view their attendance.
                    </div>

                <?php endif; ?>

            </section>

            <section class="panel attendance-panel">

                <div class="attendance-heading">
                    <div>
                        <h2>Daily Staff Attendance</h2>
                        <p>
                            Attendance for <?= date('d M Y') ?>
                        </p>
                    </div>

                    <span class="attendance-date">
                        <i class="fas fa-calendar-day"></i>
                        <?= date('d M Y') ?>
                    </span>
                </div>

                <div class="attendance-stats">

                    <div class="attendance-stat present-stat">
                        <span>Present</span>
                        <strong><?= $presentCount ?></strong>
                    </div>

                    <div class="attendance-stat absent-stat">
                        <span>Absent</span>
                        <strong><?= $absentCount ?></strong>
                    </div>

                    <div class="attendance-stat unmarked-stat">
                        <span>Not Marked</span>
                        <strong><?= $unmarkedCount ?></strong>
                    </div>

                </div>

                <div class="table-wrap">

                    <table>
                        <thead>
                            <tr>
                                <th>Staff Name</th>

                                <?php if ($isSuperAdmin): ?>
                                    <th>Farm</th>
                                <?php endif; ?>

                                <th>Role</th>
                                <th>Attendance</th>

                                <?php if (!$isSuperAdmin): ?>
                                    <th>Action</th>
                                <?php else: ?>
                                    <th>Marked By</th>
                                <?php endif; ?>
                            </tr>
                        </thead>

                        <tbody>

                            <?php if (empty($attendanceRecords)): ?>

                                <tr>
                                    <td colspan="<?= $isSuperAdmin ? 5 : 4 ?>">
                                        No attendance records found for today.
                                    </td>
                                </tr>

                            <?php else: ?>

                                <?php foreach ($attendanceRecords as $attendance): ?>

                                    <tr>
                                        <td>
                                            <strong>
                                                <?= e($attendance['full_name']) ?>
                                            </strong>
                                        </td>

                                        <?php if ($isSuperAdmin): ?>
                                            <td>
                                                <?= e($attendance['farm_name'] ?? 'N/A') ?>
                                            </td>
                                        <?php endif; ?>

                                        <td>
                                            <?= e(ucwords(str_replace(
                                                '_',
                                                ' ',
                                                $attendance['role_name']
                                            ))) ?>
                                        </td>

                                        <td>
                                            <span class="attendance-badge <?= e($attendance['attendance_status']) ?>">
                                                <?= e(ucfirst($attendance['attendance_status'])) ?>
                                            </span>
                                        </td>

                                        <?php if (!$isSuperAdmin): ?>

                                            <td>

                                                <?php if (
                                                    $attendance['role_name'] === 'admin'
                                                ): ?>

                                                    <span class="system-label">
                                                        Automatic
                                                    </span>

                                                <?php else: ?>

                                                    <form method="POST" action="users.php"
                                                        class="attendance-form">

                                                        <input type="hidden"
                                                            name="csrf_token"
                                                            value="<?= e($_SESSION['csrf_token']) ?>">

                                                        <input type="hidden"
                                                            name="action"
                                                            value="mark_attendance">

                                                        <input type="hidden"
                                                            name="user_id"
                                                            value="<?= (int)$attendance['user_id'] ?>">

                                                        <select name="attendance_status" required>

                                                            <option value="" disabled
                                                                <?= $attendance['attendance_status'] === 'unmarked' ? 'selected' : '' ?>>
                                                                Select
                                                            </option>

                                                            <option value="present"
                                                                <?= $attendance['attendance_status'] === 'present' ? 'selected' : '' ?>>
                                                                Present
                                                            </option>

                                                            <option value="absent"
                                                                <?= $attendance['attendance_status'] === 'absent' ? 'selected' : '' ?>>
                                                                Absent
                                                            </option>

                                                        </select>

                                                        <button type="submit">
                                                            Save
                                                        </button>

                                                    </form>

                                                <?php endif; ?>

                                            </td>

                                        <?php else: ?>

                                            <td>
                                                <?= e($attendance['marked_by_name'] ?? 'System') ?>
                                            </td>

                                        <?php endif; ?>

                                    </tr>

                                <?php endforeach; ?>

                            <?php endif; ?>

                        </tbody>
                    </table>

                </div>

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


    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

    <?php if ($selectedAttendanceUser && !empty($monthlyAttendance)): ?>

        <script>
            document.addEventListener('DOMContentLoaded', function() {

                const attendanceData = <?= json_encode(
                                            $monthlyAttendance,
                                            JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP
                                        ) ?>;

                const labels = attendanceData.map(item => {

                    const date = new Date(item.attendance_month + '-02T00:00:00');

                    return date.toLocaleDateString('en-GB', {
                        month: 'short',
                        year: 'numeric'
                    });
                });

                const presentDays = attendanceData.map(item =>
                    Number(item.present_days)
                );

                const absentDays = attendanceData.map(item =>
                    Number(item.absent_days)
                );

                const canvas = document.getElementById(
                    'individualAttendanceChart'
                );

                if (!canvas) return;

                new Chart(canvas, {
                    type: 'bar',

                    data: {
                        labels: labels,

                        datasets: [{
                                label: 'Present Days',
                                data: presentDays,
                                backgroundColor: '#23865b',
                                borderRadius: 5
                            },
                            {
                                label: 'Absent Days',
                                data: absentDays,
                                backgroundColor: '#dc6262',
                                borderRadius: 5
                            }
                        ]
                    },

                    options: {
                        responsive: true,
                        maintainAspectRatio: false,

                        plugins: {
                            legend: {
                                position: 'top'
                            }
                        },

                        scales: {
                            y: {
                                beginAtZero: true,
                                ticks: {
                                    precision: 0
                                },
                                title: {
                                    display: true,
                                    text: 'Number of Days'
                                }
                            },

                            x: {
                                title: {
                                    display: true,
                                    text: 'Month'
                                }
                            }
                        }
                    }
                });

            });
        </script>

    <?php endif; ?>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const searchInput = document.getElementById('attendanceSearchInput');
            const resultsContainer = document.getElementById('attendanceSearchResults');

            let searchTimer;

            searchInput.addEventListener('input', function() {
                clearTimeout(searchTimer);

                const searchTerm = this.value.trim();

                if (searchTerm.length < 1) {
                    resultsContainer.innerHTML = '';
                    return;
                }

                searchTimer = setTimeout(function() {
                    fetch('users.php?ajax=attendance_search&search=' + encodeURIComponent(searchTerm))
                        .then(response => response.text())
                        .then(html => {
                            resultsContainer.innerHTML = html;
                        })
                        .catch(error => {
                            console.error(error);
                            resultsContainer.innerHTML =
                                '<p class="empty-state">Search failed. Please try again.</p>';
                        });
                }, 250);
            });
        });
    </script>
</body>

</html>