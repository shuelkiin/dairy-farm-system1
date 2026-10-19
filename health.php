<?php

declare(strict_types=1);

session_start();
date_default_timezone_set('Africa/Nairobi');

if (empty($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

require_once __DIR__ . '/config/database.php';

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
    <style>
        :root {
            --green: #198754;
            --green-dark: #11643e;
            --green-light: #e9f7ef;
            --navy: #142b45;
            --ink: #263548;
            --muted: #788697;
            --line: #e6ebf0;
            --bg: #f5f8fa;
            --white: #fff;
            --red: #c0392b;
            --amber: #9a6700;
        }

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            background: var(--bg);
            color: var(--ink);
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
        }

        button,
        input,
        select,
        textarea {
            font: inherit;
        }

        button {
            cursor: pointer;
        }

        a {
            color: inherit;
            text-decoration: none;
        }

        .layout {
            display: flex;
            min-height: 100vh;
        }

        .sidebar {
            width: 245px;
            flex-shrink: 0;
            padding: 27px 17px;
            color: #fff;
            background: var(--navy);
        }

        .brand {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 0 8px 28px;
            font-size: 19px;
            font-weight: 800;
        }

        .brand-icon {
            display: grid;
            place-items: center;
            width: 42px;
            height: 42px;
            border-radius: 13px;
            background: var(--green);
            color: #fff;
        }

        .brand small {
            display: block;
            margin-top: 4px;
            color: #b8c6d6;
            font-size: 10px;
            letter-spacing: 2px;
        }

        .nav-label {
            padding: 15px 11px 9px;
            color: #a9b9ca;
            font-size: 10px;
            font-weight: 800;
            letter-spacing: 1.6px;
        }

        .nav-link {
            display: flex;
            gap: 12px;
            align-items: center;
            padding: 12px 13px;
            margin: 4px 0;
            border-radius: 9px;
            color: #dbe4ed;
            font-size: 13px;
        }

        .nav-link:hover,
        .nav-link.active {
            background: #25445b;
            color: #fff;
        }

        .nav-link.active {
            border-left: 3px solid #56d38c;
        }

        .main {
            flex: 1;
            min-width: 0;
        }

        .topbar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 15px;
            padding: 20px 32px;
            background: #fff;
            border-bottom: 1px solid var(--line);
        }

        .topbar small {
            color: var(--muted);
        }

        .user-pill {
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 13px;
            font-weight: 700;
        }

        .avatar {
            display: grid;
            place-items: center;
            width: 38px;
            height: 38px;
            background: var(--green-light);
            color: var(--green-dark);
            border-radius: 50%;
        }

        .content {
            max-width: 1500px;
            margin: auto;
            padding: 30px;
        }

        .page-heading {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            flex-wrap: wrap;
            gap: 15px;
            margin-bottom: 25px;
        }

        h1 {
            margin: 0 0 8px;
            font-size: 28px;
            color: var(--navy);
        }

        h2 {
            margin: 0 0 20px;
            font-size: 17px;
            color: var(--navy);
        }

        p {
            line-height: 1.6;
        }

        .muted {
            color: var(--muted);
            font-size: 13px;
        }

        .farm-label {
            display: inline-block;
            padding: 9px 13px;
            border-radius: 9px;
            background: var(--green-light);
            color: var(--green-dark);
            font-size: 12px;
            font-weight: 700;
        }

        .stats {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 17px;
            margin-bottom: 25px;
        }

        .stat,
        .panel {
            background: #fff;
            border: 1px solid var(--line);
            border-radius: 14px;
            box-shadow: 0 3px 15px rgba(20, 43, 69, .025);
        }

        .stat {
            padding: 21px;
        }

        .stat-top {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 10px;
            color: var(--muted);
            font-size: 12px;
        }

        .stat-icon {
            display: grid;
            place-items: center;
            width: 39px;
            height: 39px;
            border-radius: 11px;
            background: var(--green-light);
            font-size: 19px;
        }

        .stat-number {
            margin-top: 15px;
            font-size: 30px;
            font-weight: 800;
            color: var(--navy);
        }

        .stat-note {
            margin-top: 5px;
            color: var(--muted);
            font-size: 11px;
        }

        .forms {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 20px;
            margin-bottom: 25px;
        }

        .panel {
            padding: 24px;
            min-width: 0;
        }

        .panel-heading {
            display: flex;
            align-items: center;
            gap: 11px;
            margin-bottom: 22px;
        }

        .panel-heading .stat-icon {
            flex-shrink: 0;
        }

        .panel-heading h2 {
            margin: 0 0 4px;
        }

        .field-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 15px;
        }

        .field {
            min-width: 0;
            margin-bottom: 15px;
        }

        .field.full {
            grid-column: 1 / -1;
        }

        label {
            display: block;
            margin-bottom: 7px;
            font-size: 12px;
            font-weight: 700;
            color: #405166;
        }

        input,
        select,
        textarea {
            display: block;
            width: 100%;
            min-width: 0;
            padding: 11px 12px;
            border: 1px solid #dce4eb;
            border-radius: 8px;
            outline: none;
            color: var(--ink);
            background: #fff;
        }

        input:focus,
        select:focus,
        textarea:focus {
            border-color: var(--green);
            box-shadow: 0 0 0 3px rgba(25, 135, 84, .1);
        }

        input[readonly] {
            background: #f0f5f2;
            color: var(--green-dark);
            font-weight: 700;
        }

        textarea {
            min-height: 85px;
            resize: vertical;
        }

        .btn {
            display: inline-flex;
            justify-content: center;
            align-items: center;
            gap: 8px;
            padding: 11px 16px;
            border: 0;
            border-radius: 8px;
            background: var(--green);
            color: white;
            font-size: 12px;
            font-weight: 800;
        }

        .btn:hover {
            background: var(--green-dark);
        }

        .btn-secondary {
            background: #edf2f6;
            color: var(--navy);
        }

        .btn-secondary:hover {
            background: #dce5ed;
        }

        .btn-block {
            width: 100%;
        }

        .alert {
            padding: 14px 17px;
            margin-bottom: 20px;
            border-radius: 9px;
            font-size: 13px;
        }

        .alert.success {
            color: #17683b;
            background: #e8f7ed;
            border: 1px solid #c4ead0;
        }

        .alert.error {
            color: #9c2929;
            background: #fff0ef;
            border: 1px solid #f2ceca;
        }

        .section {
            margin-bottom: 25px;
        }

        .section-head {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 12px;
            margin-bottom: 15px;
        }

        .section-head h2 {
            margin: 0;
        }

        .filters {
            display: flex;
            gap: 9px;
            flex-wrap: wrap;
            align-items: center;
        }

        .filters input {
            width: 220px;
        }

        .filters select {
            width: auto;
            min-width: 155px;
        }

        .table-wrap {
            overflow-x: auto;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            white-space: nowrap;
        }

        th {
            padding: 13px 15px;
            text-align: left;
            background: #f8fafb;
            color: #7b8998;
            font-size: 10px;
            letter-spacing: .6px;
            text-transform: uppercase;
        }

        td {
            padding: 14px 15px;
            border-top: 1px solid #edf0f3;
            font-size: 12px;
        }

        td strong {
            color: var(--navy);
        }

        .status {
            display: inline-block;
            padding: 5px 9px;
            border-radius: 20px;
            font-size: 10px;
            font-weight: 800;
        }

        .status.under_treatment {
            background: #fff0dc;
            color: #9a5700;
        }

        .status.ongoing {
            background: #e5f0ff;
            color: #2456a6;
        }

        .status.recovered {
            background: #e5f7eb;
            color: #197343;
        }

        .due-list {
            display: grid;
            gap: 10px;
        }

        .due-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 15px;
            padding: 13px;
            border: 1px solid var(--line);
            border-radius: 9px;
        }

        .due-item strong {
            display: block;
            font-size: 12px;
            margin-bottom: 4px;
        }

        .due-date {
            color: var(--green-dark);
            font-size: 11px;
            font-weight: 800;
        }

        .empty {
            padding: 25px;
            text-align: center;
            color: var(--muted);
            font-size: 13px;
        }

        .footer {
            padding: 10px 0 25px;
            text-align: center;
            color: var(--muted);
            font-size: 11px;
        }

        @media(max-width:1100px) {
            .sidebar {
                width: 205px;
            }

            .stats {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }

            .forms {
                grid-template-columns: 1fr;
            }
        }

        @media(max-width:700px) {
            .layout {
                display: block;
            }

            .sidebar {
                width: 100%;
                padding: 14px;
            }

            .brand {
                padding-bottom: 12px;
            }

            .nav-label {
                padding-top: 9px;
            }

            .nav-link {
                display: inline-flex;
                margin: 2px;
                padding: 9px;
            }

            .topbar {
                padding: 15px;
            }

            .content {
                padding: 18px 12px;
            }

            h1 {
                font-size: 23px;
            }

            .stats {
                gap: 10px;
            }

            .stat {
                padding: 14px;
            }

            .stat-number {
                font-size: 25px;
            }

            .panel {
                padding: 16px;
            }

            .field-grid {
                grid-template-columns: 1fr;
            }

            .field.full {
                grid-column: auto;
            }

            .filters,
            .filters input,
            .filters select {
                width: 100%;
            }
        }
    </style>
</head>

<body>
    <div class="layout">

        <aside class="sidebar">
            <div class="brand">
                <div class="brand-icon">DF</div>
                <div>DairyFlow<small>FARM MANAGEMENT</small></div>
            </div>

            <div class="nav-label">MAIN MENU</div>
            <a class="nav-link" href="index.php">▦ &nbsp; Dashboard</a>
            <a class="nav-link" href="cows.php">🐄 &nbsp; Cow Management</a>
            <a class="nav-link" href="milk.php">🥛 &nbsp; Milk Production</a>
            <a class="nav-link active" href="health.php">✚ &nbsp; Health & Vaccination</a>
            <a class="nav-link" href="weights.php">⚖ &nbsp; Cow Weights</a>

            <?php if (in_array($role, ['admin', 'super_admin'], true)): ?>
                <div class="nav-label">MANAGEMENT</div>
                <a class="nav-link" href="users.php">♙ &nbsp; Manage Users</a>
                <a class="nav-link" href="farms.php">⌂ &nbsp; Farms</a>
            <?php endif; ?>

            <div class="nav-label">ACCOUNT</div>
            <a class="nav-link" href="logout.php">↪ &nbsp; Logout</a>
        </aside>

        <main class="main">
            <header class="topbar">
                <div>
                    <strong>Health & Vaccination</strong><br>
                    <small>Animal wellness and medical records</small>
                </div>
                <div class="user-pill">
                    <div class="avatar"><?= e(strtoupper(substr($currentUser['full_name'], 0, 1))) ?></div>
                    <div>
                        <?= e($currentUser['full_name']) ?><br>
                        <small><?= e(ucwords(str_replace('_', ' ', $role))) ?></small>
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