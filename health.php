<?php

declare(strict_types=1);

session_start();
date_default_timezone_set('Africa/Nairobi');

if (empty($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

require_once __DIR__ . '/config/database.php';

// Get logged-in user's role
$role = $_SESSION['role'] ?? '';

$isSuperAdmin = ($role === 'super_admin');

/*
* Database compatibility:
* Supports a PDO connection named $pdo or a MySQLi
* connection named $conn or $mysqli from database.php.
*/
$db = $pdo ?? $conn ?? $mysqli ?? null;

if (!$db instanceof PDO && !$db instanceof mysqli) {
    http_response_code(500);
    exit('Database connection not found. Check config/database.php.');
}

$isPDO = $db instanceof PDO;

if ($isPDO) {
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
}

function e($value): string
{
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

function dbAll($db, string $sql, array $params = []): array
{
    if ($db instanceof PDO) {
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    $stmt = $db->prepare($sql);

    if (!$stmt) {
        throw new RuntimeException($db->error);
    }

    if ($params) {
        $types = '';
        $values = [];

        foreach ($params as $value) {
            $types .= is_int($value) ? 'i' : 's';
            $values[] = $value;
        }

        $stmt->bind_param($types, ...$values);
    }

    $stmt->execute();
    $result = $stmt->get_result();
    $rows = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    return $rows;
}

function dbExecute($db, string $sql, array $params = []): int
{
    if ($db instanceof PDO) {
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        return $stmt->rowCount();
    }

    $stmt = $db->prepare($sql);

    if (!$stmt) {
        throw new RuntimeException($db->error);
    }

    if ($params) {
        $types = '';
        $values = [];

        foreach ($params as $value) {
            $types .= is_int($value) ? 'i' : 's';
            $values[] = $value;
        }

        $stmt->bind_param($types, ...$values);
    }

    $stmt->execute();
    $affected = $stmt->affected_rows;
    $stmt->close();

    return $affected;
}

function validDate(string $date): bool
{
    $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);

    return $parsed !== false
        && $parsed->format('Y-m-d') === $date;
}

$today = date('Y-m-d');
$userId = (int) $_SESSION['user_id'];

$userRows = dbAll(
    $db,
    "SELECT u.user_id, u.full_name, u.farm_id, r.role_name
FROM users u
JOIN roles r ON r.role_id = u.role_id
WHERE u.user_id = ? AND u.status = 'active'
LIMIT 1",
    [$userId]
);

if (!$userRows) {
    session_destroy();
    header('Location: login.php');
    exit;
}

$currentUser = $userRows[0];
$role = $currentUser['role_name'];
$userFarmId = $currentUser['farm_id'] === null
    ? null
    : (int) $currentUser['farm_id'];

$allowedRoles = ['super_admin', 'admin', 'farm_manager'];

if (!in_array($role, $allowedRoles, true)) {
    http_response_code(403);
    exit('You do not have permission to access cow health records.');
}

if ($role !== 'super_admin' && !$userFarmId) {
    http_response_code(403);
    exit('Your account is not assigned to a farm.');
}

/*
* Super admins can see all farms.
* Other roles are restricted to their assigned farm.
*/
$farmFilter = '';
$farmParams = [];

if ($role !== 'super_admin') {
    $farmFilter = ' AND c.farm_id = ? ';
    $farmParams[] = $userFarmId;
}

$farmRows = $role === 'super_admin'
    ? dbAll(
        $db,
        "SELECT farm_id, farm_name FROM farms
WHERE status = 'active' ORDER BY farm_name"
    )
    : dbAll(
        $db,
        "SELECT farm_id, farm_name FROM farms
WHERE farm_id = ? AND status = 'active'",
        [$userFarmId]
    );

$farmsById = [];

foreach ($farmRows as $farm) {
    $farmsById[(int)$farm['farm_id']] = $farm['farm_name'];
}

$selectedFarmId = $role === 'super_admin'
    ? (int)($_GET['farm_id'] ?? 0)
    : (int)$userFarmId;

if (
    $role === 'super_admin'
    && $selectedFarmId !== 0
    && !isset($farmsById[$selectedFarmId])
) {
    $selectedFarmId = 0;
}

$filterSql = $farmFilter;
$filterParams = $farmParams;

if ($role === 'super_admin' && $selectedFarmId > 0) {
    $filterSql .= ' AND c.farm_id = ? ';
    $filterParams[] = $selectedFarmId;
}

$success = '';
$error = '';

if (empty($_SESSION['health_csrf'])) {
    $_SESSION['health_csrf'] = bin2hex(random_bytes(32));
}

$csrfToken = $_SESSION['health_csrf'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $postedToken = (string)($_POST['csrf_token'] ?? '');

        if (!hash_equals($csrfToken, $postedToken)) {
            throw new RuntimeException(
                'Your form session expired. Refresh the page and try again.'
            );
        }

        $action = (string)($_POST['action'] ?? '');

        if ($action === 'health') {
            $cowId = filter_var(
                $_POST['cow_id'] ?? null,
                FILTER_VALIDATE_INT
            );

            $condition = trim((string)($_POST['condition_name'] ?? ''));
            $symptoms = trim((string)($_POST['symptoms'] ?? ''));
            $treatment = trim((string)($_POST['treatment'] ?? ''));
            $vet = trim((string)($_POST['veterinarian'] ?? ''));
            $recordDate = (string)($_POST['record_date'] ?? '');
            $nextCheckup = trim((string)($_POST['next_checkup'] ?? ''));
            $status = (string)($_POST['status'] ?? 'ongoing');

            if (!$cowId || $cowId < 1) {
                throw new RuntimeException('Please select a cow.');
            }

            if ($condition === '' || strlen($condition) > 150) {
                throw new RuntimeException(
                    'Enter a condition name of up to 150 characters.'
                );
            }

            if ($recordDate !== $today) {
                throw new RuntimeException(
                    'Health records can only be recorded for today.'
                );
            }

            if (
                $nextCheckup !== ''
                && (
                    !validDate($nextCheckup)
                    || $nextCheckup < $today
                )
            ) {
                throw new RuntimeException(
                    'The next checkup must be today or a future date.'
                );
            }

            if (!in_array(
                $status,
                ['under_treatment', 'recovered', 'ongoing'],
                true
            )) {
                throw new RuntimeException('Invalid health status.');
            }

            $cow = dbAll(
                $db,
                "SELECT c.cow_id
                 FROM cows c
                 WHERE c.cow_id = ?
                 $farmFilter
                 LIMIT 1",
                array_merge([$cowId], $farmParams)
            );

            if (!$cow) {
                throw new RuntimeException(
                    'That cow does not belong to your permitted farm.'
                );
            }

            dbExecute(
                $db,
                "INSERT INTO health_records
                    (cow_id, recorded_by, condition_name, symptoms,
                     treatment, veterinarian, record_date, next_checkup, status)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)",
                [
                    (int)$cowId,
                    $userId,
                    $condition,
                    $symptoms !== '' ? $symptoms : null,
                    $treatment !== '' ? $treatment : null,
                    $vet !== '' ? $vet : null,
                    $today,
                    $nextCheckup !== '' ? $nextCheckup : null,
                    $status
                ]
            );

            // Update the cow's status when a new health issue is recorded.
            if ($status === 'under_treatment' || $status === 'ongoing') {
                dbExecute(
                    $db,
                    "UPDATE cows SET status = 'sick' WHERE cow_id = ?",
                    [(int)$cowId]
                );
            }

            $success = 'Health record saved successfully.';
        } elseif ($action === 'vaccination') {
            $cowId = filter_var(
                $_POST['cow_id'] ?? null,
                FILTER_VALIDATE_INT
            );

            $vaccine = trim((string)($_POST['vaccine_name'] ?? ''));
            $administeredBy = trim(
                (string)($_POST['administered_by'] ?? '')
            );
            $vaccinationDate = (string)(
                $_POST['vaccination_date'] ?? ''
            );
            $nextDue = trim((string)($_POST['next_due_date'] ?? ''));
            $notes = trim((string)($_POST['vaccine_notes'] ?? ''));

            if (!$cowId || $cowId < 1) {
                throw new RuntimeException('Please select a cow.');
            }

            if ($vaccine === '' || strlen($vaccine) > 150) {
                throw new RuntimeException(
                    'Enter a vaccine name of up to 150 characters.'
                );
            }

            if ($vaccinationDate !== $today) {
                throw new RuntimeException(
                    'Vaccinations can only be recorded for today.'
                );
            }

            if (
                $nextDue !== ''
                && (
                    !validDate($nextDue)
                    || $nextDue < $today
                )
            ) {
                throw new RuntimeException(
                    'The next due date must be today or a future date.'
                );
            }

            $cow = dbAll(
                $db,
                "SELECT c.cow_id
                 FROM cows c
                 WHERE c.cow_id = ?
                 $farmFilter
                 LIMIT 1",
                array_merge([$cowId], $farmParams)
            );

            if (!$cow) {
                throw new RuntimeException(
                    'That cow does not belong to your permitted farm.'
                );
            }

            dbExecute(
                $db,
                "INSERT INTO vaccinations
                    (cow_id, vaccine_name, vaccination_date,
                     next_due_date, administered_by, notes)
                 VALUES (?, ?, ?, ?, ?, ?)",
                [
                    (int)$cowId,
                    $vaccine,
                    $today,
                    $nextDue !== '' ? $nextDue : null,
                    $administeredBy !== '' ? $administeredBy : null,
                    $notes !== '' ? $notes : null
                ]
            );

            $success = 'Vaccination recorded successfully.';
        } else {
            throw new RuntimeException('Invalid form submission.');
        }
    } catch (Throwable $exception) {
        $error = $exception instanceof PDOException
            || $exception instanceof mysqli_sql_exception
            ? 'The record could not be saved. Check your database connection and try again.'
            : $exception->getMessage();
    }
}

/*
            * Load cows available to this user.
            * Only active, sick, pregnant or dry cows are offered.
            * Sold and deceased cows are excluded.
            */
$cows = dbAll(
    $db,
    "SELECT c.cow_id, c.tag_number, c.cow_name, c.farm_id,
            f.farm_name
            FROM cows c
            JOIN farms f ON f.farm_id = c.farm_id
            WHERE c.status NOT IN ('sold', 'deceased')
            $filterSql
            ORDER BY f.farm_name, c.tag_number",
    $filterParams
);

/* Dashboard counts */
$stats = dbAll(
    $db,
    "SELECT
            COUNT(DISTINCT CASE
            WHEN h.status IN ('under_treatment', 'ongoing')
            THEN h.health_id END) AS open_cases,
            COUNT(DISTINCT CASE
            WHEN h.status = 'under_treatment'
            THEN h.health_id END) AS treatment_cases,
            COUNT(DISTINCT CASE
            WHEN h.status = 'recovered'
            THEN h.health_id END) AS recovered_cases
            FROM health_records h
            JOIN cows c ON c.cow_id = h.cow_id
            WHERE 1=1 $filterSql",
    $filterParams
)[0] ?? [
    'open_cases' => 0,
    'treatment_cases' => 0,
    'recovered_cases' => 0
];

$upcoming = dbAll(
    $db,
    "SELECT COUNT(*) AS total
            FROM vaccinations v
            JOIN cows c ON c.cow_id = v.cow_id
            WHERE v.next_due_date BETWEEN ? AND DATE_ADD(?, INTERVAL 30 DAY)
            $filterSql",
    array_merge([$today, $today], $filterParams)
)[0]['total'] ?? 0;

/* Health history */
$search = trim((string)($_GET['search'] ?? ''));
$healthParams = $filterParams;

$healthSearchSql = '';

if ($search !== '') {
    $healthSearchSql = "
            AND (
            c.tag_number LIKE ?
            OR c.cow_name LIKE ?
            OR h.condition_name LIKE ?
            OR h.status LIKE ?
            )
            ";

    $like = '%' . $search . '%';

    array_push(
        $healthParams,
        $like,
        $like,
        $like,
        $like
    );
}

$healthRecords = dbAll(
    $db,
    "SELECT h.*, c.tag_number, c.cow_name, f.farm_name,
            u.full_name AS recorder_name
            FROM health_records h
            JOIN cows c ON c.cow_id = h.cow_id
            JOIN farms f ON f.farm_id = c.farm_id
            LEFT JOIN users u ON u.user_id = h.recorded_by
            WHERE 1=1 $filterSql $healthSearchSql
            ORDER BY h.record_date DESC, h.health_id DESC
            LIMIT 200",
    $healthParams
);

$vaccineParams = $filterParams;

$vaccineSearchSql = '';

if ($search !== '') {
    $vaccineSearchSql = "
            AND (
            c.tag_number LIKE ?
            OR c.cow_name LIKE ?
            OR v.vaccine_name LIKE ?
            )
            ";

    $like = '%' . $search . '%';

    array_push(
        $vaccineParams,
        $like,
        $like,
        $like
    );
}

$vaccinations = dbAll(
    $db,
    "SELECT v.*, c.tag_number, c.cow_name, f.farm_name
            FROM vaccinations v
            JOIN cows c ON c.cow_id = v.cow_id
            JOIN farms f ON f.farm_id = c.farm_id
            WHERE 1=1 $filterSql $vaccineSearchSql
            ORDER BY v.vaccination_date DESC, v.vaccination_id DESC
            LIMIT 200",
    $vaccineParams
);

$dueVaccinations = dbAll(
    $db,
    "SELECT v.*, c.tag_number, c.cow_name, f.farm_name
            FROM vaccinations v
            JOIN cows c ON c.cow_id = v.cow_id
            JOIN farms f ON f.farm_id = c.farm_id
            WHERE v.next_due_date >= ?
            AND v.next_due_date <= DATE_ADD(?, INTERVAL 30 DAY)
                $filterSql
                ORDER BY v.next_due_date ASC
                LIMIT 10",
    array_merge([$today, $today], $filterParams)
);

$activeFarmName = $role === 'super_admin' && $selectedFarmId === 0
    ? 'All farms'
    : ($farmsById[$selectedFarmId] ?? 'Assigned farm');

$baseUrl = 'health.php';
$filterUrl = $role === 'super_admin' && $selectedFarmId > 0
    ? '?farm_id=' . $selectedFarmId
    : '';
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Health & Vaccination | DairyFlow</title>
    <link rel="stylesheet" href="assets/style.css">
    <style>
        :root {
            --navy: #102a25;
            --navy-light: #183b33;
            --green: #23865b;
            --green-dark: #176b46;
            --mint: #e9f7ef;
            --green-light: #e9f7ef;
            --bg: #f5f8f6;
            --text: #1c3029;
            --ink: #1c3029;
            --muted: #788b81;
            --line: #e5ebe7;
            --white: #ffffff;
            --red: #c0392b;
            --amber: #9a6700;
            --shadow: 0 12px 32px rgba(16, 42, 37, 0.055);
        }

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
            font-family: 'DM Sans', 'Manrope', sans-serif;
            background: var(--bg);
            color: var(--text);
            font-size: 14px;
        }

        a {
            color: inherit;
            text-decoration: none;
        }

        button,
        input,
        select,
        textarea {
            font: inherit;
        }

        /* MAIN LAYOUT */

        .layout {
            display: flex;
            min-height: 100vh;
            width: 100%;
        }

        .layout>.sidebar {
            position: fixed;
            top: 0;
            left: 0;
            width: 248px;
            height: 100vh;
            background: var(--navy);
            color: #ffffff;
            padding: 25px 17px;
            display: flex;
            flex-direction: column;
            overflow-y: auto;
            z-index: 1000;
        }

        .layout>.main {
            width: calc(100% - 248px);
            min-height: 100vh;
            margin-left: 248px;
            background: var(--bg);
        }

        /* SIDEBAR */

        .sidebar .brand {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 5px 8px 28px;
            color: #ffffff;
            font-size: 18px;
            font-weight: 800;
            letter-spacing: -0.5px;
        }

        .sidebar .brand small {
            display: block;
            margin-top: 4px;
            color: #a6c1b5;
            font-size: 9px;
            font-weight: 700;
            letter-spacing: 1.5px;
        }

        .logo,
        .small-logo {
            width: 39px;
            height: 39px;
            border-radius: 12px;
            background: var(--green);
            color: #ffffff;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 15px;
            font-weight: 900;
            flex-shrink: 0;
        }

        .nav-label {
            padding: 13px 10px 9px;
            color: #88a99a;
            font-size: 10px;
            font-weight: 800;
            letter-spacing: 1.5px;
        }

        .sidebar nav {
            display: flex;
            flex-direction: column;
            gap: 5px;
        }

        .sidebar nav a,
        .sidebar .nav-link,
        .sidebar .logout {
            display: flex;
            align-items: center;
            gap: 11px;
            padding: 12px 13px;
            border-radius: 9px;
            color: #c3d5cd;
            font-size: 13px;
            font-weight: 600;
            transition: 0.2s ease;
        }

        .sidebar nav a:hover,
        .sidebar .nav-link:hover {
            background: var(--navy-light);
            color: #ffffff;
        }

        .sidebar nav a.active {
            background: var(--green);
            color: #ffffff;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.12);
        }

        .sidebar-bottom {
            margin-top: auto;
            padding-top: 25px;
            border-top: 1px solid rgba(255, 255, 255, 0.1);
        }

        .user-chip {
            display: flex;
            align-items: center;
            gap: 11px;
            padding: 10px 7px 17px;
            color: #ffffff;
        }

        .user-chip strong {
            display: block;
            font-size: 12px;
            font-weight: 700;
        }

        .user-chip small {
            display: block;
            margin-top: 4px;
            color: #a6c1b5;
            font-size: 10px;
        }

        .user-chip .avatar {
            width: 36px;
            height: 36px;
            border-radius: 50%;
            background: #d7f2e3;
            color: var(--navy);
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 800;
            flex-shrink: 0;
        }

        .sidebar .logout {
            color: #f4a5a5;
        }

        .sidebar .logout:hover {
            background: rgba(220, 70, 70, 0.12);
        }

        /* TOPBAR */

        .topbar {
            min-height: 88px;
            padding: 17px 36px;
            background: #ffffff;
            border-bottom: 1px solid var(--line);
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 20px;
            position: sticky;
            top: 0;
            z-index: 100;
        }

        .topbar .eyebrow {
            display: block;
            margin-bottom: 5px;
            color: var(--muted);
            font-size: 10px;
            font-weight: 800;
            letter-spacing: 1.4px;
        }

        .topbar h1 {
            color: var(--navy);
            font-size: 22px;
            font-weight: 800;
            letter-spacing: -0.7px;
        }

        .topbar .user-pill,
        .topbar .topbar-right {
            display: flex;
            align-items: center;
            gap: 10px;
            color: var(--muted);
            font-size: 12px;
            font-weight: 600;
        }

        .status-dot {
            width: 8px;
            height: 8px;
            display: inline-block;
            border-radius: 50%;
            background: #22a06b;
        }

        .topbar .avatar,
        .topbar .top-avatar {
            width: 36px;
            height: 36px;
            border-radius: 50%;
            background: var(--mint);
            color: var(--green-dark);
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 800;
        }

        /* PAGE CONTENT */

        .content {
            width: 100%;
            max-width: 1600px;
            margin: 0 auto;
            padding: 29px 36px 40px;
        }

        .content h2 {
            color: var(--navy);
            font-size: 20px;
            font-weight: 800;
            margin-bottom: 8px;
        }

        .content h3 {
            color: var(--navy);
            font-size: 15px;
            font-weight: 800;
        }

        .content p {
            color: var(--muted);
            line-height: 1.6;
        }

        /* PAGE INTRO */

        .page-heading {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 15px;
            margin-bottom: 23px;
        }

        .page-heading h2 {
            margin-bottom: 5px;
        }

        .farm-label {
            display: inline-flex;
            align-items: center;
            gap: 7px;
            padding: 7px 12px;
            border-radius: 30px;
            background: var(--mint);
            color: var(--green-dark);
            font-size: 11px;
            font-weight: 700;
        }

        /* STATISTICS */

        .stats {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 17px;
            margin: 22px 0 25px;
        }

        .stat {
            background: var(--white);
            border: 1px solid var(--line);
            border-radius: 13px;
            padding: 20px;
            box-shadow: var(--shadow);
            min-width: 0;
            transition: transform 0.2s ease, box-shadow 0.2s ease;
        }

        .stat:hover {
            transform: translateY(-3px);
            box-shadow: 0 15px 35px rgba(16, 42, 37, 0.09);
        }

        .stat h3 {
            color: var(--muted);
            font-size: 11px;
            font-weight: 700;
            margin-bottom: 13px;
        }

        .stat strong {
            display: block;
            color: var(--navy);
            font-size: 29px;
            font-weight: 800;
            letter-spacing: -1px;
        }

        .stat small {
            display: block;
            color: var(--muted);
            font-size: 11px;
            margin-top: 7px;
        }

        /* PANELS AND CARDS */

        .panel,
        .card {
            background: var(--white);
            border: 1px solid var(--line);
            border-radius: 13px;
            padding: 22px;
            margin-bottom: 22px;
            box-shadow: var(--shadow);
            min-width: 0;
        }

        .panel-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 15px;
            margin-bottom: 20px;
        }

        .panel-header p {
            font-size: 12px;
            margin-top: 5px;
        }

        /* FORMS */

        .forms {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 20px;
            align-items: start;
        }

        .field-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 15px;
        }

        .field {
            display: flex;
            flex-direction: column;
            gap: 7px;
            min-width: 0;
            margin-bottom: 15px;
        }

        .field label {
            color: var(--text);
            font-size: 12px;
            font-weight: 700;
        }

        .field input,
        .field select,
        .field textarea,
        input,
        select,
        textarea {
            width: 100%;
            min-height: 43px;
            padding: 10px 12px;
            border: 1px solid #dce5df;
            border-radius: 8px;
            background: #ffffff;
            color: var(--text);
            font-size: 13px;
            outline: none;
            transition: border 0.2s ease, box-shadow 0.2s ease;
        }

        .field textarea,
        textarea {
            min-height: 95px;
            resize: vertical;
        }

        .field input:focus,
        .field select:focus,
        .field textarea:focus,
        input:focus,
        select:focus,
        textarea:focus {
            border-color: var(--green);
            box-shadow: 0 0 0 3px rgba(35, 134, 91, 0.1);
        }

        .field input::placeholder,
        .field textarea::placeholder,
        input::placeholder,
        textarea::placeholder {
            color: #a2afa8;
        }

        .field small {
            color: var(--muted);
            font-size: 11px;
        }

        /* BUTTONS */

        .btn,
        button,
        input[type="submit"] {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            min-height: 41px;
            padding: 10px 17px;
            border: none;
            border-radius: 8px;
            background: var(--green);
            color: #ffffff;
            font-size: 12px;
            font-weight: 700;
            cursor: pointer;
            transition: 0.2s ease;
        }

        .btn:hover,
        button:hover,
        input[type="submit"]:hover {
            background: var(--green-dark);
            transform: translateY(-1px);
        }

        .btn-secondary {
            background: #eef4f0;
            color: var(--navy);
        }

        .btn-secondary:hover {
            background: #dfece4;
            color: var(--navy);
        }

        .btn-danger {
            background: #fff0ef;
            color: #b42318;
        }

        .btn-danger:hover {
            background: #ffe0dd;
        }

        /* ALERTS AND MESSAGES */

        .alert {
            padding: 13px 16px;
            border-radius: 9px;
            margin-bottom: 18px;
            font-size: 13px;
            line-height: 1.5;
            border: 1px solid transparent;
        }

        .alert-success,
        .success {
            background: #eaf7ef;
            color: #176b46;
            border-color: #c8ead5;
        }

        .alert-error,
        .error {
            background: #fff0ef;
            color: #b42318;
            border-color: #ffd5d1;
        }

        .alert-warning,
        .warning {
            background: #fff8e6;
            color: #946200;
            border-color: #f5e2ad;
        }

        /* TABLES */

        .table-wrap {
            width: 100%;
            overflow-x: auto;
            border: 1px solid var(--line);
            border-radius: 10px;
        }

        table {
            width: 100%;
            min-width: 650px;
            border-collapse: collapse;
            background: #ffffff;
        }

        thead {
            background: #f5f8f6;
        }

        th {
            padding: 13px 15px;
            text-align: left;
            color: #65776d;
            font-size: 10px;
            font-weight: 800;
            letter-spacing: 0.7px;
            text-transform: uppercase;
            border-bottom: 1px solid var(--line);
            white-space: nowrap;
        }

        td {
            padding: 14px 15px;
            color: var(--text);
            font-size: 12px;
            border-bottom: 1px solid #edf1ee;
            vertical-align: middle;
        }

        tbody tr:last-child td {
            border-bottom: none;
        }

        tbody tr:hover {
            background: #f8fbf9;
        }

        /* STATUS LABELS */

        .status,
        .badge {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 5px 10px;
            border-radius: 30px;
            font-size: 10px;
            font-weight: 800;
            white-space: nowrap;
        }

        .status.active,
        .status.recovered,
        .badge.success {
            background: #e8f7ed;
            color: #176b46;
        }

        .status.sick,
        .status.ongoing,
        .badge.danger {
            background: #fff0ef;
            color: #b42318;
        }

        .status.pending,
        .status.due,
        .badge.warning {
            background: #fff6df;
            color: #946200;
        }

        .status.completed,
        .status.normal,
        .badge.info {
            background: #eaf2ff;
            color: #2458a6;
        }

        /* UPCOMING VACCINATIONS / DUE LIST */

        .due-list {
            display: flex;
            flex-direction: column;
            gap: 11px;
        }

        .due-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 15px;
            padding: 14px;
            background: #f8faf9;
            border: 1px solid var(--line);
            border-radius: 9px;
        }

        .due-item strong {
            display: block;
            color: var(--navy);
            font-size: 12px;
            margin-bottom: 5px;
        }

        .due-item small {
            color: var(--muted);
            font-size: 11px;
        }

        /* EMPTY STATES */

        .empty-state {
            padding: 35px 20px;
            text-align: center;
            color: var(--muted);
        }

        .empty-state strong {
            display: block;
            color: var(--navy);
            margin-bottom: 7px;
        }

        /* FILTERS AND SEARCH */

        .filters,
        .filter-row {
            display: flex;
            align-items: end;
            gap: 12px;
            flex-wrap: wrap;
            margin-bottom: 18px;
        }

        .filters .field,
        .filter-row .field {
            flex: 1;
            min-width: 160px;
            margin-bottom: 0;
        }

        /* DIVIDERS */

        hr {
            border: none;
            border-top: 1px solid var(--line);
            margin: 20px 0;
        }

        /* RESPONSIVE TABLETS */

        @media (max-width: 1100px) {
            .layout>.sidebar {
                width: 220px;
            }

            .layout>.main {
                width: calc(100% - 220px);
                margin-left: 220px;
            }

            .content {
                padding: 25px 23px;
            }

            .topbar {
                padding: 16px 23px;
            }

            .stats {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }

            .forms {
                grid-template-columns: 1fr;
            }
        }

        /* RESPONSIVE MOBILE */

        @media (max-width: 760px) {
            .layout {
                display: block;
            }

            .layout>.sidebar {
                position: relative;
                width: 100%;
                height: auto;
                min-height: auto;
                padding: 15px;
                overflow: visible;
            }

            .sidebar .brand {
                padding-bottom: 13px;
            }

            .sidebar .nav-label {
                padding-top: 8px;
            }

            .sidebar nav {
                flex-direction: row;
                flex-wrap: wrap;
                gap: 6px;
            }

            .sidebar nav a {
                padding: 9px 11px;
                font-size: 11px;
            }

            .sidebar-bottom {
                margin-top: 12px;
                padding-top: 10px;
            }

            .user-chip {
                padding-bottom: 8px;
            }

            .layout>.main {
                width: 100%;
                margin-left: 0;
            }

            .topbar {
                position: relative;
                min-height: 75px;
                padding: 15px 18px;
            }

            .topbar h1 {
                font-size: 19px;
            }

            .content {
                padding: 22px 16px;
            }

            .stats {
                grid-template-columns: repeat(2, minmax(0, 1fr));
                gap: 11px;
            }

            .stat {
                padding: 15px;
            }

            .stat strong {
                font-size: 24px;
            }

            .forms {
                grid-template-columns: 1fr;
            }

            .field-grid {
                grid-template-columns: 1fr;
                gap: 0;
            }

            .panel {
                padding: 17px;
            }

            .page-heading {
                align-items: flex-start;
                flex-direction: column;
            }
        }

        /* SMALL MOBILE */

        @media (max-width: 420px) {
            .stats {
                grid-template-columns: 1fr;
            }

            .topbar {
                align-items: flex-start;
                flex-direction: column;
            }

            .topbar .user-pill,
            .topbar .topbar-right {
                align-self: flex-start;
            }

            .due-item {
                align-items: flex-start;
                flex-direction: column;
            }

            .panel-header {
                align-items: flex-start;
                flex-direction: column;
            }
        }
    </style>

</head>

<body>
    <div class="layout">

        <aside class="sidebar">
            <a href="index.php" class="brand">
                <span class="logo small-logo">DF</span>
                <span>DairyFlow<small>FARM MANAGEMENT</small></span>
            </a>

            <p class="nav-label">WORKSPACE</p>
            <nav>
                <a href="index.php">▦ &nbsp; Dashboard</a>

                <?php if (in_array($role, ['super_admin', 'admin', 'farm_manager'], true)): ?>
                    <a href="cows.php">♧ &nbsp; Cows & Records</a>
                    <a href="milk.php">◉ &nbsp; Milk Production</a>
                    <a href="search.php">⌕ &nbsp; search cow</a>

                    <a class="active" href="health.php">♡ &nbsp; Health & Vaccinations</a>
                <?php endif; ?>


                <?php if (in_array($role, ['super_admin', 'admin', 'store_manager'], true)): ?>
                    <a href="feeds.php">🌾 &nbsp; Feed Inventory</a>

                <?php endif; ?>

                <a href="reports.php">▥ &nbsp; Reports</a>
                <?php if ($isSuperAdmin): ?>
                    <a href="users.php">♙ &nbsp; Manage Users</a>
                    <a href="farms.php">⌂ &nbsp; Manage Farms</a>
                <?php endif; ?>

                <?php if ($role === 'admin'): ?>
                    <a href="users.php">♙ &nbsp; Manage Farm Managers</a>
                <?php endif; ?>
            </nav>

            <div class="sidebar-bottom">
                <div class="user-chip">
                    <div class="avatar"><?= e(strtoupper(substr($_SESSION['full_name'], 0, 1))) ?></div>
                    <div>
                        <strong><?= e($_SESSION['full_name']) ?></strong>
                        <small><?= e(ucwords(str_replace('_', ' ', $role))) ?></small>
                    </div>
                </div>
                <a class="nav-link" href="change_password.php">
                    <span class="nav-icon">🔐</span>
                    Change Password
                </a>
                <a class="logout" href="logout.php">↪ &nbsp; Sign out</a>
            </div>
        </aside>
        <main class="main">
            <header class="topbar">
                <div>
                    <span class="eyebrow">DAIRY FARM MANAGEMENT</span>
                    <h1>Health & Vaccination</h1>
                </div>

                <div class="user-pill">
                    <span class="status-dot"></span>
                    System online

                    <div class="avatar">
                        <?= e(strtoupper(substr($currentUser['full_name'], 0, 1))) ?>
                    </div>
                </div>
            </header>

            <div class="content">
                <div class="page-heading">
                    <div>
                        <h1>Health & Vaccination 🩺</h1>
                        <div class="muted">Keep every cow healthy, monitored, and up to date.</div>
                    </div>
                    <span class="farm-label">⌂ <?= e($activeFarmName) ?></span>
                </div>

                <?php if ($success !== ''): ?>
                    <div class="alert success">✓ <?= e($success) ?></div>
                <?php endif; ?>

                <?php if ($error !== ''): ?>
                    <div class="alert error">⚠ <?= e($error) ?></div>
                <?php endif; ?>

                <section class="stats">
                    <div class="stat">
                        <div class="stat-top"><span>Open Health Cases</span><span class="stat-icon">🩺</span></div>
                        <div class="stat-number"><?= (int)$stats['open_cases'] ?></div>
                        <div class="stat-note">Ongoing and treatment cases</div>
                    </div>
                    <div class="stat">
                        <div class="stat-top"><span>Under Treatment</span><span class="stat-icon">💊</span></div>
                        <div class="stat-number"><?= (int)$stats['treatment_cases'] ?></div>
                        <div class="stat-note">Cases needing treatment</div>
                    </div>
                    <div class="stat">
                        <div class="stat-top"><span>Recovered Cases</span><span class="stat-icon">✓</span></div>
                        <div class="stat-number"><?= (int)$stats['recovered_cases'] ?></div>
                        <div class="stat-note">Marked as recovered</div>
                    </div>
                    <div class="stat">
                        <div class="stat-top"><span>Vaccines Due Soon</span><span class="stat-icon">💉</span></div>
                        <div class="stat-number"><?= (int)$upcoming ?></div>
                        <div class="stat-note">Due within the next 30 days</div>
                    </div>
                </section>

                <section class="forms">
                    <div class="panel">
                        <div class="panel-heading">
                            <div class="stat-icon">🩺</div>
                            <div>
                                <h2>Record Health Issue</h2>
                                <div class="muted">Log a condition and its treatment.</div>
                            </div>
                        </div>

                        <form method="POST" action="health.php<?= $filterUrl !== '' ? e($filterUrl) : '' ?>">
                            <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                            <input type="hidden" name="action" value="health">

                            <div class="field-grid">
                                <div class="field full">
                                    <label for="health_cow">Cow *</label>
                                    <select name="cow_id" id="health_cow" required>
                                        <option value="">Select cow</option>
                                        <?php foreach ($cows as $cow): ?>
                                            <option value="<?= (int)$cow['cow_id'] ?>">
                                                <?= e($cow['tag_number']) ?>
                                                <?= $cow['cow_name'] ? ' · ' . e($cow['cow_name']) : '' ?>
                                                <?php if ($role === 'super_admin' && $selectedFarmId === 0): ?>
                                                    · <?= e($cow['farm_name']) ?>
                                                <?php endif; ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                                <div class="field full">
                                    <label for="condition_name">Condition / Diagnosis *</label>
                                    <input id="condition_name" name="condition_name"
                                        maxlength="150" required
                                        placeholder="e.g. Mastitis, fever">
                                </div>

                                <div class="field full">
                                    <label for="symptoms">Symptoms</label>
                                    <textarea id="symptoms" name="symptoms"
                                        placeholder="Describe the observed symptoms"></textarea>
                                </div>

                                <div class="field full">
                                    <label for="treatment">Treatment / Medication</label>
                                    <textarea id="treatment" name="treatment"
                                        placeholder="Treatment provided or recommended"></textarea>
                                </div>

                                <div class="field">
                                    <label for="veterinarian">Veterinarian</label>
                                    <input id="veterinarian" name="veterinarian"
                                        maxlength="150" placeholder="Vet's name">
                                </div>

                                <div class="field">
                                    <label for="health_status">Status *</label>
                                    <select id="health_status" name="status" required>
                                        <option value="ongoing">Ongoing</option>
                                        <option value="under_treatment">Under treatment</option>
                                        <option value="recovered">Recovered</option>
                                    </select>
                                </div>

                                <div class="field">
                                    <label for="record_date">Record Date *</label>
                                    <input type="date" id="record_date" name="record_date"
                                        value="<?= e($today) ?>"
                                        min="<?= e($today) ?>" max="<?= e($today) ?>"
                                        readonly required>
                                </div>

                                <div class="field">
                                    <label for="next_checkup">Next Checkup</label>
                                    <input type="date" id="next_checkup" name="next_checkup"
                                        min="<?= e($today) ?>">
                                </div>
                            </div>

                            <button class="btn btn-block" type="submit">＋ Save Health Record</button>
                        </form>
                    </div>

                    <div class="panel">
                        <div class="panel-heading">
                            <div class="stat-icon">💉</div>
                            <div>
                                <h2>Record Vaccination</h2>
                                <div class="muted">Track vaccines and upcoming doses.</div>
                            </div>
                        </div>

                        <form method="POST" action="health.php<?= $filterUrl !== '' ? e($filterUrl) : '' ?>">
                            <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                            <input type="hidden" name="action" value="vaccination">

                            <div class="field">
                                <label for="vaccine_cow">Cow *</label>
                                <select name="cow_id" id="vaccine_cow" required>
                                    <option value="">Select cow</option>
                                    <?php foreach ($cows as $cow): ?>
                                        <option value="<?= (int)$cow['cow_id'] ?>">
                                            <?= e($cow['tag_number']) ?>
                                            <?= $cow['cow_name'] ? ' · ' . e($cow['cow_name']) : '' ?>
                                            <?php if ($role === 'super_admin' && $selectedFarmId === 0): ?>
                                                · <?= e($cow['farm_name']) ?>
                                            <?php endif; ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="field">
                                <label for="vaccine_name">Vaccine Name *</label>
                                <input id="vaccine_name" name="vaccine_name"
                                    maxlength="150" required
                                    placeholder="e.g. FMD vaccine">
                            </div>

                            <div class="field-grid">
                                <div class="field">
                                    <label for="vaccination_date">Vaccination Date *</label>
                                    <input type="date" id="vaccination_date"
                                        name="vaccination_date"
                                        value="<?= e($today) ?>"
                                        min="<?= e($today) ?>" max="<?= e($today) ?>"
                                        readonly required>
                                </div>
                                <div class="field">
                                    <label for="next_due_date">Next Due Date</label>
                                    <input type="date" id="next_due_date" name="next_due_date"
                                        min="<?= e($today) ?>">
                                </div>
                            </div>

                            <div class="field">
                                <label for="administered_by">Administered By</label>
                                <input id="administered_by" name="administered_by"
                                    maxlength="150" placeholder="Veterinarian or administrator">
                            </div>

                            <div class="field">
                                <label for="vaccine_notes">Notes</label>
                                <textarea id="vaccine_notes" name="vaccine_notes"
                                    placeholder="Dose details or observations"></textarea>
                            </div>

                            <button class="btn btn-block" type="submit">＋ Save Vaccination</button>
                        </form>
                    </div>
                </section>

                <section class="panel section">
                    <div class="section-head">
                        <div>
                            <h2>Health Records</h2>
                            <div class="muted">Latest 200 matching records.</div>
                        </div>

                        <form method="GET" action="health.php" class="filters">
                            <?php if ($role === 'super_admin'): ?>
                                <select name="farm_id" aria-label="Filter farm">
                                    <option value="0">All farms</option>
                                    <?php foreach ($farmRows as $farm): ?>
                                        <option value="<?= (int)$farm['farm_id'] ?>"
                                            <?= $selectedFarmId === (int)$farm['farm_id'] ? 'selected' : '' ?>>
                                            <?= e($farm['farm_name']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            <?php endif; ?>
                            <input type="search" name="search" value="<?= e($search) ?>"
                                placeholder="Search cow or condition">
                            <button class="btn" type="submit">Search</button>
                            <a class="btn btn-secondary" href="health.php">Reset</a>
                        </form>
                    </div>

                    <div class="table-wrap">
                        <table>
                            <thead>
                                <tr>
                                    <th>Cow</th>
                                    <th>Farm</th>
                                    <th>Condition</th>
                                    <th>Treatment</th>
                                    <th>Record Date</th>
                                    <th>Next Checkup</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (!$healthRecords): ?>
                                    <tr>
                                        <td colspan="7" class="empty">No health records found. Add your first record above.</td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($healthRecords as $record): ?>
                                        <tr>
                                            <td>
                                                <strong><?= e($record['tag_number']) ?></strong>
                                                <?php if ($record['cow_name']): ?>
                                                    <br><span class="muted"><?= e($record['cow_name']) ?></span>
                                                <?php endif; ?>
                                            </td>
                                            <td><?= e($record['farm_name']) ?></td>
                                            <td>
                                                <strong><?= e($record['condition_name']) ?></strong>
                                                <?php if ($record['symptoms']): ?>
                                                    <br><span class="muted"><?= e($record['symptoms']) ?></span>
                                                <?php endif; ?>
                                            </td>
                                            <td><?= e($record['treatment'] ?: 'Not recorded') ?></td>
                                            <td><?= e($record['record_date']) ?></td>
                                            <td><?= e($record['next_checkup'] ?: 'Not scheduled') ?></td>
                                            <td>
                                                <span class="status <?= e($record['status']) ?>">
                                                    <?= e(ucwords(str_replace('_', ' ', $record['status']))) ?>
                                                </span>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </section>

                <section class="panel section">
                    <div class="section-head">
                        <div>
                            <h2>Vaccination History</h2>
                            <div class="muted">Recorded vaccinations and scheduled doses.</div>
                        </div>
                    </div>

                    <div class="table-wrap">
                        <table>
                            <thead>
                                <tr>
                                    <th>Cow</th>
                                    <th>Farm</th>
                                    <th>Vaccine</th>
                                    <th>Date Given</th>
                                    <th>Next Due</th>
                                    <th>Administered By</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (!$vaccinations): ?>
                                    <tr>
                                        <td colspan="6" class="empty">No vaccinations found.</td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($vaccinations as $vaccine): ?>
                                        <tr>
                                            <td>
                                                <strong><?= e($vaccine['tag_number']) ?></strong>
                                                <?php if ($vaccine['cow_name']): ?>
                                                    <br><span class="muted"><?= e($vaccine['cow_name']) ?></span>
                                                <?php endif; ?>
                                            </td>
                                            <td><?= e($vaccine['farm_name']) ?></td>
                                            <td>
                                                <strong><?= e($vaccine['vaccine_name']) ?></strong>
                                                <?php if ($vaccine['notes']): ?>
                                                    <br><span class="muted"><?= e($vaccine['notes']) ?></span>
                                                <?php endif; ?>
                                            </td>
                                            <td><?= e($vaccine['vaccination_date']) ?></td>
                                            <td><?= e($vaccine['next_due_date'] ?: 'Not scheduled') ?></td>
                                            <td><?= e($vaccine['administered_by'] ?: 'Not recorded') ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </section>

                <section class="panel section">
                    <div class="section-head">
                        <div>
                            <h2>Upcoming Vaccinations</h2>
                            <div class="muted">Doses due within the next 30 days.</div>
                        </div>
                    </div>

                    <?php if (!$dueVaccinations): ?>
                        <div class="empty">No vaccinations are scheduled in the next 30 days.</div>
                    <?php else: ?>
                        <div class="due-list">
                            <?php foreach ($dueVaccinations as $due): ?>
                                <div class="due-item">
                                    <div>
                                        <strong><?= e($due['vaccine_name']) ?> · <?= e($due['tag_number']) ?></strong>
                                        <span class="muted">
                                            <?= e($due['cow_name'] ?: 'Unnamed cow') ?>
                                            · <?= e($due['farm_name']) ?>
                                        </span>
                                    </div>
                                    <div class="due-date"><?= e($due['next_due_date']) ?></div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </section>

                <div class="footer">DairyFlow · Farm Management System · <?= date('Y') ?></div>
            </div>
        </main>
    </div>
</body>

</html>