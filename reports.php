<?php

declare(strict_types=1);

session_start();
require_once __DIR__ . '/config/database.php';

if (empty($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$db = $pdo ?? $conn ?? $mysqli ?? null;

if (!$db instanceof PDO && !$db instanceof mysqli) {
    exit('Database connection not found. Check config/database.php.');
}

function e(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function reportQuery(string $sql, array $params = []): array
{
    global $db;

    if ($db instanceof PDO) {
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    $stmt = $db->prepare($sql);

    if (!$stmt) {
        throw new RuntimeException('Unable to prepare report query.');
    }

    if ($params) {
        $types = str_repeat('s', count($params));
        $refs = [];

        foreach ($params as $key => $value) {
            $refs[$key] = &$params[$key];
        }

        $stmt->bind_param($types, ...$refs);
    }

    $stmt->execute();
    $result = $stmt->get_result();
    $rows = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    return $rows;
}

function reportExecute(string $sql, array $params = []): void
{
    global $db;

    if ($db instanceof PDO) {
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        return;
    }

    $stmt = $db->prepare($sql);

    if (!$stmt) {
        throw new RuntimeException('Unable to prepare database operation.');
    }

    if ($params) {
        $types = str_repeat('s', count($params));
        $refs = [];

        foreach ($params as $key => $value) {
            $refs[$key] = &$params[$key];
        }

        $stmt->bind_param($types, ...$refs);
    }

    $stmt->execute();
    $stmt->close();
}


// ==========================================
// SECURE CONSULTANT REPORT FILE VIEWER
// ==========================================

if (isset($_GET['consultant_file'])) {

    $reportId = filter_var(
        $_GET['consultant_file'],
        FILTER_VALIDATE_INT
    );

    if (!$reportId || $reportId < 1) {
        http_response_code(400);
        exit('Invalid report ID.');
    }

    // Only admins and super admins may view consultant reports.
    $viewer = reportQuery(
        "SELECT u.user_id, u.farm_id, r.role_name
         FROM users u
         JOIN roles r ON r.role_id = u.role_id
         WHERE u.user_id = ?
         LIMIT 1",
        [(int) $_SESSION['user_id']]
    );

    if (!$viewer || !in_array(
        $viewer[0]['role_name'],
        ['admin', 'super_admin'],
        true
    )) {
        http_response_code(403);
        exit('You are not allowed to view this file.');
    }

    $viewer = $viewer[0];

    $fileParams = [$reportId];
    $fileSql = "
        SELECT cr.file_name, cr.file_type, cr.original_file_name
        FROM consultant_reports cr
        WHERE cr.report_id = ?
    ";

    if ($viewer['role_name'] !== 'super_admin') {
        $fileSql .= " AND cr.farm_id = ?";
        $fileParams[] = (int) $viewer['farm_id'];
    }

    $fileRows = reportQuery($fileSql, $fileParams);

    if (!$fileRows) {
        http_response_code(404);
        exit('Consultant report not found.');
    }

    $file = $fileRows[0];

    $allowedExtensions = [
        'application/pdf' => 'pdf',
        'image/jpeg' => 'jpg',
        'image/png' => 'png'
    ];

    if (!isset($allowedExtensions[$file['file_type']])) {
        http_response_code(403);
        exit('Unsupported file type.');
    }

    $expectedExtension = $allowedExtensions[$file['file_type']];

    // Stored filenames are generated randomly during upload.
    if (!preg_match(
        '/^[a-f0-9]{40}\.' . preg_quote($expectedExtension, '/') . '$/',
        $file['file_name']
    )) {
        http_response_code(403);
        exit('Invalid stored filename.');
    }

    $filePath = __DIR__ . '/uploads/consultant_reports/' . $file['file_name'];

    if (!is_file($filePath)) {
        http_response_code(404);
        exit('Attachment file not found.');
    }

    header('Content-Type: ' . $file['file_type']);
    header('Content-Length: ' . filesize($filePath));
    header('Content-Disposition: inline; filename="' . $expectedExtension . '"');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: private, no-store');

    readfile($filePath);
    exit;
}



$userId = (int) $_SESSION['user_id'];

$users = reportQuery(
    "SELECT u.user_id, u.full_name, u.farm_id, r.role_name
     FROM users u
     JOIN roles r ON r.role_id = u.role_id
     WHERE u.user_id = ?
     LIMIT 1",
    [$userId]
);

if (!$users) {
    session_destroy();
    header('Location: login.php');
    exit;
}

$user = $users[0];
$role = $user['role_name'];
$isSuperAdmin = $role === 'super_admin';
$farmId = $user['farm_id'] === null ? null : (int) $user['farm_id'];

if (!in_array($role, ['super_admin', 'admin', 'farm_manager', 'store_manager'], true)) {
    http_response_code(403);
    exit('You do not have permission to view reports.');
}

if (!$isSuperAdmin && !$farmId) {
    exit('Your account is not assigned to a farm.');
}

$farms = $isSuperAdmin
    ? reportQuery("SELECT farm_id, farm_name FROM farms ORDER BY farm_name")
    : reportQuery(
        "SELECT farm_id, farm_name FROM farms WHERE farm_id = ?",
        [$farmId]
    );

$validFarmIds = array_map(
    static fn($farm) => (int) $farm['farm_id'],
    $farms
);

$selectedFarm = $isSuperAdmin
    ? (int) ($_GET['farm_id'] ?? 0)
    : (int) $farmId;
// CONSULTANT REPORTS
$consultantSuccess = '';
$consultantError = '';

$canUploadConsultantReports = $role === 'admin';
$canViewConsultantReports = in_array(
    $role,
    ['admin', 'super_admin'],
    true
);

if (empty($_SESSION['consultant_report_csrf'])) {
    $_SESSION['consultant_report_csrf'] = bin2hex(random_bytes(32));
}

// Handle consultant report upload.
if (
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    ($_POST['action'] ?? '') === 'upload_consultant_report'
) {
    if (!$canUploadConsultantReports) {
        http_response_code(403);
        exit('You are not allowed to upload consultant reports.');
    }

    try {
        if (
            !hash_equals(
                $_SESSION['consultant_report_csrf'],
                $_POST['csrf_token'] ?? ''
            )
        ) {
            throw new RuntimeException('Invalid form request. Refresh and try again.');
        }

        $consultantName = trim($_POST['consultant_name'] ?? '');
        $reportDate = trim($_POST['report_date'] ?? '');
        $comments = trim($_POST['comments'] ?? '');

        if ($consultantName === '' || strlen($consultantName) > 150) {
            throw new RuntimeException('Enter a valid consultant name.');
        }

        if (
            !preg_match('/^\d{4}-\d{2}-\d{2}$/', $reportDate) ||
            !checkdate(
                (int) substr($reportDate, 5, 2),
                (int) substr($reportDate, 8, 2),
                (int) substr($reportDate, 0, 4)
            )
        ) {
            throw new RuntimeException('Enter a valid report date.');
        }

        if (
            !isset($_FILES['consultant_file']) ||
            $_FILES['consultant_file']['error'] !== UPLOAD_ERR_OK
        ) {
            throw new RuntimeException('Please select a PDF or image to upload.');
        }

        $file = $_FILES['consultant_file'];

        // Maximum upload size: 10 MB.
        if ($file['size'] > 10 * 1024 * 1024) {
            throw new RuntimeException('The file must not exceed 10 MB.');
        }

        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mimeType = $finfo->file($file['tmp_name']);

        $allowedTypes = [
            'application/pdf' => 'pdf',
            'image/jpeg' => 'jpg',
            'image/png' => 'png'
        ];

        if (!isset($allowedTypes[$mimeType])) {
            throw new RuntimeException('Only PDF, JPG, JPEG and PNG files are allowed.');
        }

        $uploadDirectory = __DIR__ . '/uploads/consultant_reports/';

        if (!is_dir($uploadDirectory) || !is_writable($uploadDirectory)) {
            throw new RuntimeException('Consultant report upload folder is unavailable.');
        }

        $storedFileName = bin2hex(random_bytes(20))
            . '.' . $allowedTypes[$mimeType];

        $destination = $uploadDirectory . $storedFileName;

        if (!move_uploaded_file($file['tmp_name'], $destination)) {
            throw new RuntimeException('Unable to save the uploaded file.');
        }

        $originalFileName = basename($file['name']);

        // Limit the stored original filename.
        $originalFileName = substr($originalFileName, 0, 255);

        try {
            reportExecute(
                "INSERT INTO consultant_reports
                    (farm_id, consultant_name, report_date, comments,
                     file_name, original_file_name, file_type, uploaded_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)",
                [
                    $farmId,
                    $consultantName,
                    $reportDate,
                    $comments,
                    $storedFileName,
                    $originalFileName,
                    $mimeType,
                    $userId
                ]
            );
        } catch (Throwable $exception) {
            if (is_file($destination)) {
                unlink($destination);
            }

            throw $exception;
        }

        header('Location: reports.php?consultant_success=1');
        exit;
    } catch (Throwable $exception) {
        $consultantError = $exception->getMessage();
    }
}

if (isset($_GET['consultant_success'])) {
    $consultantSuccess = 'Consultant report uploaded successfully.';
}

// Retrieve consultant reports.
$consultantReports = [];

if ($canViewConsultantReports) {
    if ($isSuperAdmin) {
        $consultantReports = $selectedFarm > 0
            ? reportQuery(
                "SELECT cr.*, f.farm_name, u.full_name AS uploaded_by_name
                 FROM consultant_reports cr
                 JOIN farms f ON f.farm_id = cr.farm_id
                 JOIN users u ON u.user_id = cr.uploaded_by
                 WHERE cr.farm_id = ?
                 ORDER BY cr.report_date DESC, cr.report_id DESC",
                [$selectedFarm]
            )
            : reportQuery(
                "SELECT cr.*, f.farm_name, u.full_name AS uploaded_by_name
                 FROM consultant_reports cr
                 JOIN farms f ON f.farm_id = cr.farm_id
                 JOIN users u ON u.user_id = cr.uploaded_by
                 ORDER BY cr.report_date DESC, cr.report_id DESC"
            );
    } else {
        $consultantReports = reportQuery(
            "SELECT cr.*, f.farm_name, u.full_name AS uploaded_by_name
             FROM consultant_reports cr
             JOIN farms f ON f.farm_id = cr.farm_id
             JOIN users u ON u.user_id = cr.uploaded_by
             WHERE cr.farm_id = ?
             ORDER BY cr.report_date DESC, cr.report_id DESC",
            [$farmId]
        );
    }
}
if ($isSuperAdmin && !in_array($selectedFarm, [0, ...$validFarmIds], true)) {
    $selectedFarm = 0;
}


// ==========================================
// CONSULTANT REPORTS
// ==========================================

$consultantSuccess = '';
$consultantError = '';

$canUploadConsultantReports = $role === 'admin';
$canViewConsultantReports = in_array(
    $role,
    ['admin', 'super_admin'],
    true
);

if (empty($_SESSION['consultant_report_csrf'])) {
    $_SESSION['consultant_report_csrf'] = bin2hex(random_bytes(32));
}

// Handle consultant report upload.
if (
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    ($_POST['action'] ?? '') === 'upload_consultant_report'
) {
    if (!$canUploadConsultantReports) {
        http_response_code(403);
        exit('You are not allowed to upload consultant reports.');
    }

    try {
        if (
            !hash_equals(
                $_SESSION['consultant_report_csrf'],
                $_POST['csrf_token'] ?? ''
            )
        ) {
            throw new RuntimeException('Invalid form request. Refresh and try again.');
        }

        $consultantName = trim($_POST['consultant_name'] ?? '');
        $reportDate = trim($_POST['report_date'] ?? '');
        $comments = trim($_POST['comments'] ?? '');

        if ($consultantName === '' || strlen($consultantName) > 150) {
            throw new RuntimeException('Enter a valid consultant name.');
        }

        if (
            !preg_match('/^\d{4}-\d{2}-\d{2}$/', $reportDate) ||
            !checkdate(
                (int) substr($reportDate, 5, 2),
                (int) substr($reportDate, 8, 2),
                (int) substr($reportDate, 0, 4)
            )
        ) {
            throw new RuntimeException('Enter a valid report date.');
        }

        if (
            !isset($_FILES['consultant_file']) ||
            $_FILES['consultant_file']['error'] !== UPLOAD_ERR_OK
        ) {
            throw new RuntimeException('Please select a PDF or image to upload.');
        }

        $file = $_FILES['consultant_file'];

        if ($file['size'] > 10 * 1024 * 1024) {
            throw new RuntimeException('The file must not exceed 10 MB.');
        }

        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mimeType = $finfo->file($file['tmp_name']);

        $allowedTypes = [
            'application/pdf' => 'pdf',
            'image/jpeg' => 'jpg',
            'image/png' => 'png'
        ];

        if (!isset($allowedTypes[$mimeType])) {
            throw new RuntimeException('Only PDF, JPG, JPEG and PNG files are allowed.');
        }

        if (!$farmId) {
            throw new RuntimeException('Your account is not assigned to a farm.');
        }

        $uploadDirectory = __DIR__ . '/uploads/consultant_reports/';

        if (!is_dir($uploadDirectory) || !is_writable($uploadDirectory)) {
            throw new RuntimeException('Consultant report upload folder is unavailable.');
        }

        $storedFileName = bin2hex(random_bytes(20))
            . '.' . $allowedTypes[$mimeType];

        $destination = $uploadDirectory . $storedFileName;

        if (!move_uploaded_file($file['tmp_name'], $destination)) {
            throw new RuntimeException('Unable to save the uploaded file.');
        }

        $originalFileName = substr(basename($file['name']), 0, 255);

        try {
            reportExecute(
                "INSERT INTO consultant_reports
                    (farm_id, consultant_name, report_date, comments,
                     file_name, original_file_name, file_type, uploaded_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)",
                [
                    $farmId,
                    $consultantName,
                    $reportDate,
                    $comments,
                    $storedFileName,
                    $originalFileName,
                    $mimeType,
                    $userId
                ]
            );
        } catch (Throwable $exception) {
            if (is_file($destination)) {
                unlink($destination);
            }

            throw $exception;
        }

        header('Location: reports.php?consultant_success=1');
        exit;
    } catch (Throwable $exception) {
        $consultantError = $exception->getMessage();
    }
}

if (isset($_GET['consultant_success'])) {
    $consultantSuccess = 'Consultant report uploaded successfully.';
}

// Retrieve consultant reports.
$consultantReports = [];

if ($canViewConsultantReports) {
    if ($isSuperAdmin) {
        $consultantReports = $selectedFarm > 0
            ? reportQuery(
                "SELECT cr.*, f.farm_name, u.full_name AS uploaded_by_name
                 FROM consultant_reports cr
                 JOIN farms f ON f.farm_id = cr.farm_id
                 JOIN users u ON u.user_id = cr.uploaded_by
                 WHERE cr.farm_id = ?
                 ORDER BY cr.report_date DESC, cr.report_id DESC",
                [$selectedFarm]
            )
            : reportQuery(
                "SELECT cr.*, f.farm_name, u.full_name AS uploaded_by_name
                 FROM consultant_reports cr
                 JOIN farms f ON f.farm_id = cr.farm_id
                 JOIN users u ON u.user_id = cr.uploaded_by
                 ORDER BY cr.report_date DESC, cr.report_id DESC"
            );
    } else {
        $consultantReports = reportQuery(
            "SELECT cr.*, f.farm_name, u.full_name AS uploaded_by_name
             FROM consultant_reports cr
             JOIN farms f ON f.farm_id = cr.farm_id
             JOIN users u ON u.user_id = cr.uploaded_by
             WHERE cr.farm_id = ?
             ORDER BY cr.report_date DESC, cr.report_id DESC",
            [$farmId]
        );
    }
}



$startDate = (string) ($_GET['start_date'] ?? date('Y-m-01'));
$endDate = (string) ($_GET['end_date'] ?? date('Y-m-d'));

$datePattern = '/^\d{4}-\d{2}-\d{2}$/';

if (
    !preg_match($datePattern, $startDate) ||
    !preg_match($datePattern, $endDate) ||
    !strtotime($startDate) ||
    !strtotime($endDate) ||
    $startDate > $endDate
) {
    $startDate = date('Y-m-01');
    $endDate = date('Y-m-d');
}

$farmParams = [];

if ($selectedFarm > 0) {
    $farmSql = ' AND mp.farm_id = ?';
    $farmParams[] = $selectedFarm;
} elseif (!$isSuperAdmin) {
    $farmSql = ' AND mp.farm_id = ?';
    $farmParams[] = $farmId;
}

$farmSql = '';
$farmParams = [];

if ($role === 'farm_manager') {
    // Farm managers only see milk records for their assigned cows.
    $farmSql = ' AND EXISTS (
        SELECT 1
        FROM cows AS assigned_cow
        WHERE assigned_cow.cow_id = mp.cow_id
          AND assigned_cow.farm_id = ?
          AND assigned_cow.manager_id = ?
    )';

    $farmParams[] = $farmId;
    $farmParams[] = $userId;
} elseif ($selectedFarm > 0) {
    $farmSql = ' AND mp.farm_id = ?';
    $farmParams[] = $selectedFarm;
} elseif (!$isSuperAdmin) {
    $farmSql = ' AND mp.farm_id = ?';
    $farmParams[] = $farmId;
}

$cowFarmSql = '';
$cowFarmParams = [];

if ($role === 'farm_manager') {
    $cowFarmSql = ' AND c.farm_id = ? AND c.manager_id = ?';
    $cowFarmParams[] = $farmId;
    $cowFarmParams[] = $userId;
} elseif ($selectedFarm > 0) {
    $cowFarmSql = ' AND c.farm_id = ?';
    $cowFarmParams[] = $selectedFarm;
} elseif (!$isSuperAdmin) {
    $cowFarmSql = ' AND c.farm_id = ?';
    $cowFarmParams[] = $farmId;
}

$milkParams = [$startDate, $endDate, ...$farmParams];
$healthParams = [$startDate, $endDate, ...$cowFarmParams];
$vaccineParams = [$startDate, $endDate, ...$cowFarmParams];


try {

    // Milk production during the selected period.
    $milkSummary = reportQuery(
        "SELECT
            COALESCE(SUM(mp.quantity_litres), 0) AS total_litres,
            COUNT(*) AS total_entries,
            COALESCE(AVG(mp.quantity_litres), 0) AS average_litres
         FROM milk_production AS mp
         WHERE mp.production_date BETWEEN ? AND ? $farmSql",
        $milkParams
    )[0];

    // Daily milk production chart data.
    $dailyMilk = reportQuery(
        "SELECT
            mp.production_date,
            SUM(mp.quantity_litres) AS litres
         FROM milk_production AS mp
         WHERE mp.production_date BETWEEN ? AND ? $farmSql
         GROUP BY mp.production_date
         ORDER BY mp.production_date",
        $milkParams
    );

    // Current cow population and health status.
    $cowSummary = reportQuery(
        "SELECT
            COUNT(*) AS total_cows,
            COALESCE(SUM(c.status = 'active'), 0) AS active_cows,
            COALESCE(SUM(c.status = 'sick'), 0) AS sick_cows,
            COALESCE(SUM(c.status = 'pregnant'), 0) AS pregnant_cows
         FROM cows c
         WHERE 1 = 1 $cowFarmSql",
        $cowFarmParams
    )[0];

    // Health records created during the selected period.
    $healthSummary = reportQuery(
        "SELECT
            COUNT(*) AS total_records,
            COALESCE(SUM(hr.status = 'under_treatment'), 0) AS under_treatment,
            COALESCE(SUM(hr.status = 'recovered'), 0) AS recovered
         FROM health_records hr
         JOIN cows c ON c.cow_id = hr.cow_id
         WHERE hr.record_date BETWEEN ? AND ? $cowFarmSql",
        $healthParams
    )[0];

    // Vaccinations administered during the selected period.
    $vaccineSummary = reportQuery(
        "SELECT COUNT(*) AS total_vaccinations
         FROM vaccinations v
         JOIN cows c ON c.cow_id = v.cow_id
         WHERE v.vaccination_date BETWEEN ? AND ? $cowFarmSql",
        $vaccineParams
    )[0];

    // Vaccination doses due soon, including overdue doses.
    $upcomingVaccines = reportQuery(
        "SELECT v.vaccination_id, v.vaccine_name, v.next_due_date,
                c.tag_number, c.cow_name, f.farm_name
         FROM vaccinations v
         JOIN cows c ON c.cow_id = v.cow_id
         JOIN farms f ON f.farm_id = c.farm_id
         WHERE v.next_due_date IS NOT NULL
           AND v.next_due_date <= DATE_ADD(CURDATE(), INTERVAL 30 DAY)
           $cowFarmSql
         ORDER BY v.next_due_date
         LIMIT 20",
        $cowFarmParams
    );

    // Current feed stock, grouped by feed type.
    $feedParams = [];
    $feedFarmSql = '';

    if ($selectedFarm > 0) {
        $feedFarmSql = ' WHERE fs.farm_id = ?';
        $feedParams[] = $selectedFarm;
    } elseif (!$isSuperAdmin) {
        $feedFarmSql = ' WHERE fs.farm_id = ?';
        $feedParams[] = $farmId;
    }

    $feedSummary = reportQuery(
        "SELECT COUNT(*) AS stock_items,
                COALESCE(SUM(fs.quantity <= fs.reorder_level), 0) AS low_stock
         FROM feed_stock fs
         $feedFarmSql",
        $feedParams
    )[0];

    $feedRows = reportQuery(
        "SELECT ft.feed_name, ft.unit,
                SUM(fs.quantity) AS quantity,
                SUM(fs.reorder_level) AS reorder_level
         FROM feed_stock fs
         JOIN feed_types ft ON ft.feed_id = fs.feed_id
         $feedFarmSql
         GROUP BY ft.feed_id, ft.feed_name, ft.unit
         ORDER BY ft.feed_name",
        $feedParams
    );

    // Detailed milk production report.
    $milkDetails = reportQuery(
        "SELECT mp.production_date, mp.milking_session,
                mp.quantity_litres, c.tag_number, c.cow_name,
                f.farm_name, u.full_name AS recorded_by
         FROM milk_production mp
         JOIN cows c ON c.cow_id = mp.cow_id
         JOIN farms f ON f.farm_id = mp.farm_id
         LEFT JOIN users u ON u.user_id = mp.recorded_by
         WHERE mp.production_date BETWEEN ? AND ? $farmSql
         ORDER BY mp.production_date DESC, mp.production_id DESC
         LIMIT 250",
        $milkParams
    );

    // Detailed health report.
    $healthDetails = reportQuery(
        "SELECT hr.record_date, hr.condition_name, hr.status,
                hr.treatment, c.tag_number, c.cow_name, f.farm_name
         FROM health_records hr
         JOIN cows c ON c.cow_id = hr.cow_id
         JOIN farms f ON f.farm_id = c.farm_id
         WHERE hr.record_date BETWEEN ? AND ? $cowFarmSql
         ORDER BY hr.record_date DESC, hr.health_id DESC
         LIMIT 150",
        $healthParams
    );
} catch (Throwable $exception) {
    error_log('DairyFlow reports error: ' . $exception->getMessage());
    http_response_code(500);

    exit('Report error: ' . htmlspecialchars(
        $exception->getMessage(),
        ENT_QUOTES,
        'UTF-8'
    ));
}


$milkTotal = (float) $milkSummary['total_litres'];
$averageMilk = (float) $milkSummary['average_litres'];
$cowTotal = (int) $cowSummary['total_cows'];
$lowStock = (int) $feedSummary['low_stock'];

$farmLabel = 'All Farms';

foreach ($farms as $farm) {
    if ((int) $farm['farm_id'] === $selectedFarm) {
        $farmLabel = $farm['farm_name'];
        break;
    }
}

$initial = strtoupper(substr(trim((string) $user['full_name']), 0, 1));
$roleLabel = ucwords(str_replace('_', ' ', $role));
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Reports & Analytics | DairyFlow</title>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&family=Manrope:wght@400;600;700;800&display=swap" rel="stylesheet">
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
            --border: #e5ebe7;
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
        select {
            font: inherit;
        }

        /* MAIN LAYOUT */

        .main {
            width: calc(100% - 248px);
            min-height: 100vh;
            margin-left: 248px;
            padding: 0;
        }

        /* SIDEBAR */

        .sidebar {
            position: fixed;
            top: 0;
            left: 0;
            width: 248px;
            min-width: 248px;
            height: 100vh;
            background: var(--navy);
            color: #ffffff;
            padding: 25px 17px;
            display: flex;
            flex-direction: column;
            overflow-y: auto;
            z-index: 1001;
            transition: transform 0.3s ease;
        }

        .brand {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 5px 8px 28px;
            color: #ffffff;
        }

        .brand>span:last-child {
            font-size: 18px;
            font-weight: 800;
            letter-spacing: -0.5px;
        }

        .brand small {
            display: block;
            margin-top: 4px;
            color: #a6c1b5;
            font-size: 9px;
            font-weight: 700;
            letter-spacing: 1.5px;
        }

        .logo {
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

        .nav-link {
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

        .nav-link:hover {
            background: var(--navy-light);
            color: #ffffff;
        }

        .nav-link.active {
            background: var(--green);
            color: #ffffff;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.12);
        }

        .sidebar-bottom {
            margin-top: auto;
            padding-top: 20px;
            border-top: 1px solid rgba(255, 255, 255, 0.1);
        }

        .user-mini,
        .user-chip {
            display: flex;
            align-items: center;
            gap: 11px;
            padding: 10px 7px 17px;
            color: #ffffff;
        }

        .user-name,
        .user-mini strong,
        .user-chip strong {
            display: block;
            font-size: 12px;
            font-weight: 700;
        }

        .user-role,
        .user-mini small,
        .user-chip small {
            display: block;
            margin-top: 4px;
            color: #a6c1b5;
            font-size: 10px;
        }

        .avatar {
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

        .sidebar .logout,
        .sidebar-bottom>a {
            display: flex;
            align-items: center;
            gap: 11px;
            padding: 11px 13px;
            border-radius: 9px;
            color: #c3d5cd;
            font-size: 12px;
            font-weight: 600;
        }

        .sidebar .logout {
            color: #f4a5a5;
        }

        .sidebar .logout:hover {
            background: rgba(220, 70, 70, 0.12);
        }

        /* MOBILE MENU BUTTON AND OVERLAY */

        .mobile-menu-btn {
            display: none;
        }

        .sidebar-overlay {
            display: none;
        }

        /* TOPBAR */

        .topbar {
            min-height: 105px;
            padding: 20px 36px;
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

        .eyebrow {
            display: block;
            margin-bottom: 6px;
            color: var(--muted);
            font-size: 10px;
            font-weight: 800;
            letter-spacing: 1.4px;
            text-transform: uppercase;
        }

        h1 {
            color: var(--navy);
            font-family: 'Manrope', sans-serif;
            font-size: 23px;
            font-weight: 800;
            letter-spacing: -0.7px;
            margin: 0 0 5px;
        }

        .subtitle {
            color: var(--muted);
            font-size: 12px;
            margin: 0;
            line-height: 1.5;
        }

        /* ACTIONS */

        .actions {
            display: flex;
            align-items: center;
            gap: 9px;
            flex-wrap: wrap;
        }

        .btn {
            min-height: 41px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            padding: 10px 17px;
            border: none;
            border-radius: 8px;
            background: var(--green);
            color: #ffffff;
            text-decoration: none;
            font-size: 12px;
            font-weight: 700;
            cursor: pointer;
            transition: 0.2s ease;
        }

        .btn:hover {
            background: var(--green-dark);
            transform: translateY(-1px);
        }

        .btn.light {
            background: #ffffff;
            border: 1px solid var(--line);
            color: var(--navy);
        }

        .btn.light:hover {
            background: var(--mint);
            border-color: #c9e4d3;
        }

        /* PANELS */

        .panel {
            background: var(--white);
            border: 1px solid var(--line);
            border-radius: 13px;
            padding: 22px;
            margin: 24px 36px;
            box-shadow: var(--shadow);
            min-width: 0;
        }

        .panel h2 {
            color: var(--navy);
            font-family: 'Manrope', sans-serif;
            font-size: 15px;
            font-weight: 800;
            margin: 0 0 6px;
        }

        .panel-desc {
            color: var(--muted);
            font-size: 11px;
            line-height: 1.6;
            margin-bottom: 18px;
        }

        /* FILTERS */

        .filter-grid {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 13px;
            align-items: end;
        }

        .field {
            min-width: 0;
        }

        .field label {
            display: block;
            margin-bottom: 7px;
            color: var(--text);
            font-size: 12px;
            font-weight: 700;
        }

        .field input,
        .field select {
            width: 100%;
            min-height: 43px;
            padding: 10px 12px;
            border: 1px solid #dce5df;
            border-radius: 8px;
            background: #ffffff;
            color: var(--text);
            font-size: 13px;
            outline: none;
            transition: 0.2s ease;
        }

        .field input:focus,
        .field select:focus {
            border-color: var(--green);
            box-shadow: 0 0 0 3px rgba(35, 134, 91, 0.1);
        }

        .field input[readonly] {
            background: #f5f8f6;
            color: var(--muted);
        }

        /* STATISTICS */

        .stats {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 16px;
            margin: 20px 0 0;
        }

        .stat {
            background: #ffffff;
            border: 1px solid var(--line);
            border-radius: 12px;
            padding: 19px;
            min-width: 0;
            transition: 0.2s ease;
        }

        .stat:hover {
            transform: translateY(-3px);
            box-shadow: var(--shadow);
        }

        .stat-icon {
            width: 39px;
            height: 39px;
            border-radius: 10px;
            background: var(--mint);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 18px;
            margin-bottom: 14px;
        }

        .stat-label {
            color: var(--muted);
            font-size: 11px;
            font-weight: 700;
        }

        .stat-number {
            color: var(--navy);
            font-family: 'Manrope', sans-serif;
            font-size: 27px;
            font-weight: 800;
            letter-spacing: -0.8px;
            margin-top: 7px;
            overflow-wrap: anywhere;
        }

        .stat-note {
            color: var(--muted);
            font-size: 10px;
            margin-top: 6px;
            line-height: 1.5;
        }

        /* TWO COLUMN SECTIONS */

        .two-col {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 0;
            align-items: start;
        }

        .two-col .panel {
            margin-top: 0;
        }

        /* CHART */

        .chart {
            display: flex;
            align-items: end;
            gap: 8px;
            height: 180px;
            border-bottom: 1px solid var(--line);
            padding: 12px 3px 0;
            overflow-x: auto;
        }

        .bar-item {
            flex: 1;
            min-width: 25px;
            height: 100%;
            display: flex;
            flex-direction: column;
            justify-content: end;
            align-items: center;
            gap: 7px;
        }

        .bar {
            width: min(34px, 80%);
            min-height: 3px;
            border-radius: 6px 6px 0 0;
            background: linear-gradient(180deg, #56bd85, #23865b);
            transition: height 0.3s ease;
        }

        .bar-value,
        .bar-date {
            color: var(--muted);
            font-size: 9px;
        }

        .bar-date {
            white-space: nowrap;
        }

        /* TABLES */

        .table-wrap {
            width: 100%;
            overflow-x: auto;
            border: 1px solid var(--line);
            border-radius: 9px;
        }

        table {
            width: 100%;
            min-width: 600px;
            border-collapse: collapse;
            background: #ffffff;
            white-space: nowrap;
        }

        th {
            padding: 13px 14px;
            background: #f5f8f6;
            color: #65776d;
            text-align: left;
            font-size: 10px;
            font-weight: 800;
            letter-spacing: 0.7px;
            text-transform: uppercase;
            border-bottom: 1px solid var(--line);
        }

        td {
            padding: 14px;
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

        td strong {
            color: var(--navy);
        }

        /* STATUS PILLS */

        .pill {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 5px 10px;
            border-radius: 30px;
            font-size: 10px;
            font-weight: 800;
            white-space: nowrap;
        }

        .good {
            background: #e8f7ed;
            color: #176b46;
        }

        .warn {
            background: #fff6df;
            color: #946200;
        }

        .bad {
            background: #fff0ef;
            color: #b42318;
        }

        /* EMPTY STATES */

        .empty {
            padding: 25px !important;
            text-align: center;
            color: var(--muted);
            font-size: 12px;
        }

        /* FOOTER */

        .footer {
            margin: 25px 36px;
            padding: 15px;
            text-align: center;
            color: #8b9991;
            font-size: 10px;
        }

        /* TABLET */

        @media (max-width: 1100px) and (min-width: 761px) {
            .sidebar {
                width: 220px;
                min-width: 220px;
            }

            .main {
                width: calc(100% - 220px);
                margin-left: 220px;
            }

            .topbar {
                padding: 20px 23px;
            }

            .panel {
                margin-left: 23px;
                margin-right: 23px;
            }

            .footer {
                margin-left: 23px;
                margin-right: 23px;
            }

            .stats {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }

            .filter-grid {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }
        }

        /* MOBILE SIDEBAR */

        @media (max-width: 760px) {
            .sidebar {
                position: fixed;
                top: 0;
                left: 0;
                width: 248px;
                min-width: 248px;
                height: 100vh;
                min-height: 100vh;
                padding: 20px 15px;
                overflow-y: auto;
                z-index: 1001;
                transform: translateX(-100%);
                transition: transform 0.3s ease;
            }

            .sidebar.active {
                transform: translateX(0);
            }

            .sidebar nav {
                flex-direction: column;
                flex-wrap: nowrap;
            }

            .nav-link {
                padding: 12px 13px;
                font-size: 13px;
            }

            .sidebar-bottom {
                margin-top: auto;
                padding-top: 20px;
            }

            .sidebar-overlay {
                display: block;
                position: fixed;
                inset: 0;
                background: rgba(0, 0, 0, 0.45);
                z-index: 1000;
                opacity: 0;
                visibility: hidden;
                transition: 0.3s ease;
            }

            .sidebar-overlay.active {
                opacity: 1;
                visibility: visible;
            }

            .main {
                width: 100%;
                margin-left: 0;
            }

            .mobile-menu-btn {
                display: flex;
                align-items: center;
                justify-content: center;
                position: fixed;
                top: 13px;
                left: 13px;
                width: 42px;
                height: 42px;
                border: none;
                border-radius: 9px;
                background: var(--navy);
                color: #ffffff;
                font-size: 22px;
                cursor: pointer;
                z-index: 999;
            }

            .topbar {
                position: relative;
                min-height: auto;
                padding: 22px 16px 20px 68px;
                align-items: flex-start;
                flex-direction: column;
                gap: 15px;
            }

            .topbar h1 {
                font-size: 21px;
            }

            .actions {
                width: 100%;
            }

            .panel {
                margin: 16px 13px;
                padding: 17px;
            }

            .two-col {
                grid-template-columns: 1fr;
            }

            .stats {
                grid-template-columns: repeat(2, minmax(0, 1fr));
                gap: 11px;
            }

            .filter-grid {
                grid-template-columns: 1fr;
            }

            .footer {
                margin: 15px 13px;
            }
        }

        /* SMALL MOBILE */

        @media (max-width: 420px) {
            .stats {
                grid-template-columns: 1fr;
            }

            h1 {
                font-size: 20px;
            }

            .actions .btn {
                width: 100%;
            }

            .panel {
                padding: 14px;
            }
        }

        /* PRINT / SAVE AS PDF */

        @media print {
            body {
                background: #ffffff;
                font-size: 10px;
            }

            .sidebar,
            .sidebar-overlay,
            .mobile-menu-btn,
            .no-print,
            .filter-panel,
            .footer {
                display: none !important;
            }

            .main {
                width: 100%;
                margin: 0;
                padding: 0;
            }

            .topbar {
                position: static;
                padding: 10px 0;
                border-bottom: 1px solid #ddd;
            }

            .panel {
                margin: 12px 0;
                padding: 12px;
                box-shadow: none;
                border: 1px solid #ddd;
                break-inside: avoid;
            }

            .stats {
                grid-template-columns: repeat(4, 1fr);
                gap: 8px;
            }

            .stat {
                padding: 10px;
                box-shadow: none;
            }

            .two-col {
                grid-template-columns: 1fr 1fr;
            }

            .table-wrap {
                overflow: visible;
            }

            table {
                min-width: 0;
                white-space: normal;
            }

            th,
            td {
                padding: 7px 5px;
                font-size: 8px;
            }

            .chart {
                height: 130px;
            }
        }




        /* CONSULTANT REPORTS */

        .consultant-reports-panel {
            margin-bottom: 25px;
        }

        .consultant-reports-panel .panel-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 15px;
            margin-bottom: 20px;
        }

        .consultant-reports-panel .panel-header p {
            color: #718078;
            margin-top: 5px;
            font-size: 13px;
        }

        .report-count {
            background: #e9f7ef;
            color: #176b46;
            padding: 7px 13px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 700;
        }

        .consultant-upload-box {
            background: #f8fbf9;
            border: 1px solid #e1ebe5;
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 25px;
        }

        .consultant-upload-box h3,
        .consultant-table-wrapper h3 {
            color: #102a25;
            font-size: 16px;
            margin-bottom: 18px;
        }

        .consultant-form-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 17px;
        }

        .consultant-form-grid .form-group {
            display: flex;
            flex-direction: column;
            gap: 7px;
        }

        .consultant-form-grid .full-width {
            grid-column: 1 / -1;
        }

        .consultant-form-grid label {
            color: #344b40;
            font-size: 13px;
            font-weight: 600;
        }

        .consultant-form-grid input,
        .consultant-form-grid textarea {
            width: 100%;
            padding: 11px 13px;
            border: 1px solid #dce6df;
            border-radius: 8px;
            background: #fff;
            font: inherit;
            color: #1c3029;
        }

        .consultant-form-grid input:focus,
        .consultant-form-grid textarea:focus {
            outline: none;
            border-color: #23865b;
            box-shadow: 0 0 0 3px rgba(35, 134, 91, .10);
        }

        .consultant-form-grid small {
            color: #718078;
            font-size: 12px;
        }

        .consultant-upload-btn {
            margin-top: 18px;
            background: #23865b;
            color: #fff;
            border: none;
            border-radius: 8px;
            padding: 11px 18px;
            font-weight: 600;
            cursor: pointer;
        }

        .consultant-upload-btn:hover {
            background: #176b46;
        }

        .consultant-alert {
            padding: 12px 15px;
            border-radius: 8px;
            margin-bottom: 18px;
            font-size: 13px;
        }

        .consultant-alert.success {
            background: #e9f7ef;
            color: #176b46;
        }

        .consultant-alert.error {
            background: #fff0f0;
            color: #b42318;
        }

        .consultant-table-wrapper {
            overflow: hidden;
        }

        .consultant-table-wrapper table {
            width: 100%;
            border-collapse: collapse;
        }

        .consultant-table th,
        .consultant-table td {
            padding: 13px 12px;
            border-bottom: 1px solid #edf1ee;
            text-align: left;
            font-size: 13px;
            vertical-align: top;
        }

        .consultant-table th {
            background: #f5f8f6;
            color: #52645a;
            font-weight: 700;
        }

        .consultant-file-link {
            color: #23865b;
            text-decoration: none;
            font-weight: 600;
            overflow-wrap: anywhere;
        }

        .consultant-file-link:hover {
            text-decoration: underline;
        }

        .consultant-empty {
            text-align: center;
            padding: 35px 15px;
            color: #718078;
        }

        .consultant-empty i {
            font-size: 28px;
            margin-bottom: 10px;
        }

        .consultant-empty p {
            margin-top: 8px;
        }

        .consultant-table .muted {
            color: #9aa69f;
        }

        @media (max-width: 768px) {
            .consultant-form-grid {
                grid-template-columns: 1fr;
            }

            .consultant-form-grid .full-width {
                grid-column: auto;
            }

            .consultant-table-wrapper {
                overflow-x: auto;
            }

            .consultant-table {
                min-width: 750px;
            }
        }
    </style>
</head>>

<body>

    <aside class="sidebar">
        <a href="index.php" class="brand">
            <span class="logo small-logo">MF</span>
            <span>MwendAntu<small>FARM MANAGEMENT</small></span>
        </a>

        <p class="nav-label">WORKSPACE</p>
        <nav>
            <a href="index.php">▦ &nbsp; Dashboard</a>

            <?php if (in_array($role, ['super_admin', 'admin', 'farm_manager'], true)): ?>
                <a href="cows.php">♧ &nbsp; Cows & Records</a>
                <a href="milk.php">◉ &nbsp; Milk Production</a>
                <a href="search.php">🔍 &nbsp; Search cow</a>

                <a href="health.php">♡ &nbsp; Health & Vaccinations</a>
            <?php endif; ?>


            <?php if (in_array($role, ['super_admin', 'admin', 'store_manager'], true)): ?>
                <a href="feeds.php">🌾 &nbsp; Feed Inventory</a>

            <?php endif; ?>

            <a class="active" href="reports.php">▥ &nbsp; Reports</a>
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
    <div class="sidebar-overlay" id="sidebarOverlay"></div>
    <main class="main">
        <button type="button" class="mobile-menu-btn" id="mobileMenuBtn">
            ☰
        </button>
        <header class="topbar">
            <div>
                <div class="eyebrow">Insights & Performance</div>
                <h1>Reports & Analytics</h1>
                <p class="subtitle">Understand farm performance with your latest recorded data.</p>
            </div>
            <div class="actions no-print">
                <button class="btn light" onclick="window.print()">⤓ Print / Save PDF</button>
            </div>
        </header>

        <section class="panel filter-panel no-print">
            <h2>Report Filters</h2>
            <p class="panel-desc">Choose a farm and reporting period.</p>
            <form method="GET" class="filter-grid">
                <?php if ($isSuperAdmin): ?>
                    <div class="field">
                        <label for="farm_id">Farm</label>
                        <select name="farm_id" id="farm_id">
                            <option value="0">All farms</option>
                            <?php foreach ($farms as $farm): ?>
                                <option value="<?= (int) $farm['farm_id'] ?>"
                                    <?= $selectedFarm === (int) $farm['farm_id'] ? 'selected' : '' ?>>
                                    <?= e($farm['farm_name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                <?php else: ?>
                    <div class="field">
                        <label>Farm</label>
                        <input value="<?= e($farmLabel) ?>" readonly>
                    </div>
                <?php endif; ?>

                <div class="field">
                    <label for="start_date">Start Date</label>
                    <input type="date" id="start_date" name="start_date"
                        value="<?= e($startDate) ?>" max="<?= date('Y-m-d') ?>" required>
                </div>

                <div class="field">
                    <label for="end_date">End Date</label>
                    <input type="date" id="end_date" name="end_date"
                        value="<?= e($endDate) ?>" max="<?= date('Y-m-d') ?>" required>
                </div>

                <button class="btn" type="submit">Generate Report</button>
            </form>
        </section>

        <section class="panel">
            <h2>Report Summary</h2>
            <p class="panel-desc">
                <?= e($farmLabel) ?> · <?= e($startDate) ?> to <?= e($endDate) ?>
            </p>
            <div class="stats">
                <div class="stat">
                    <div class="stat-icon">🥛</div>
                    <div class="stat-label">Milk Produced</div>
                    <div class="stat-number"><?= number_format($milkTotal, 2) ?></div>
                    <div class="stat-note">Litres during selected period</div>
                </div>
                <div class="stat">
                    <div class="stat-icon">🐄</div>
                    <div class="stat-label">Current Cattle</div>
                    <div class="stat-number"><?= number_format($cowTotal) ?></div>
                    <div class="stat-note"><?= (int) $cowSummary['active_cows'] ?> active · <?= (int) $cowSummary['pregnant_cows'] ?> pregnant</div>
                </div>
                <div class="stat">
                    <div class="stat-icon">🩺</div>
                    <div class="stat-label">Health Records</div>
                    <div class="stat-number"><?= number_format((int) $healthSummary['total_records']) ?></div>
                    <div class="stat-note"><?= (int) $healthSummary['under_treatment'] ?> under treatment</div>
                </div>
                <div class="stat">
                    <div class="stat-icon">🌾</div>
                    <div class="stat-label">Low Stock Items</div>
                    <div class="stat-number"><?= number_format($lowStock) ?></div>
                    <div class="stat-note"><?= (int) $feedSummary['stock_items'] ?> tracked stock entries</div>
                </div>
            </div>
        </section>

        <section class="two-col">
            <div class="panel">
                <h2>Milk Production Trend</h2>
                <p class="panel-desc">Litres recorded on each production date.</p>

                <?php
                $maxMilk = 0.0;
                foreach ($dailyMilk as $day) {
                    $maxMilk = max($maxMilk, (float) $day['litres']);
                }
                ?>

                <?php if (!$dailyMilk): ?>
                    <div class="empty">No milk production recorded for this period.</div>
                <?php else: ?>
                    <div class="chart">
                        <?php foreach ($dailyMilk as $day): ?>
                            <?php
                            $litres = (float) $day['litres'];
                            $height = $maxMilk > 0 ? max(3, ($litres / $maxMilk) * 130) : 3;
                            ?>
                            <div class="bar-item" title="<?= e($day['production_date']) ?>: <?= number_format($litres, 2) ?> litres">
                                <div class="bar-value"><?= number_format($litres, 0) ?></div>
                                <div class="bar" style="height: <?= $height ?>px"></div>
                                <div class="bar-date"><?= e(date('d M', strtotime($day['production_date']))) ?></div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <p class="panel-desc" style="margin:12px 0 0">
                        Total: <?= number_format($milkTotal, 2) ?> litres ·
                        Average per entry: <?= number_format($averageMilk, 2) ?> litres
                    </p>
                <?php endif; ?>
            </div>

            <div class="panel">
                <h2>Cattle Overview</h2>
                <p class="panel-desc">Current recorded cattle status, not limited to the date filter.</p>
                <table>
                    <tbody>
                        <tr>
                            <td>Total cattle</td>
                            <td><strong><?= (int) $cowSummary['total_cows'] ?></strong></td>
                        </tr>
                        <tr>
                            <td>Active</td>
                            <td><span class="pill good"><?= (int) $cowSummary['active_cows'] ?></span></td>
                        </tr>
                        <tr>
                            <td>Sick</td>
                            <td><span class="pill bad"><?= (int) $cowSummary['sick_cows'] ?></span></td>
                        </tr>
                        <tr>
                            <td>in-calf</td>
                            <td><span class="pill good"><?= (int) $cowSummary['pregnant_cows'] ?></span></td>
                        </tr>
                        <tr>
                            <td>Vaccinations in period</td>
                            <td><strong><?= (int) $vaccineSummary['total_vaccinations'] ?></strong></td>
                        </tr>
                        <tr>
                            <td>Recovered health records</td>
                            <td><strong><?= (int) $healthSummary['recovered'] ?></strong></td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>


        <?php if ($canViewConsultantReports): ?>
            <section class="panel consultant-reports-panel">

                <div class="panel-header">
                    <div>
                        <h2>Consultant Reports</h2>
                        <p>Reports and recommendations submitted by farm consultants.</p>
                    </div>
                    <span class="report-count">
                        <?= count($consultantReports) ?> Reports
                    </span>
                </div>

                <?php if ($consultantSuccess): ?>
                    <div class="consultant-alert success">
                        <?= e($consultantSuccess) ?>
                    </div>
                <?php endif; ?>

                <?php if ($consultantError): ?>
                    <div class="consultant-alert error">
                        <?= e($consultantError) ?>
                    </div>
                <?php endif; ?>

                <?php if ($canUploadConsultantReports): ?>
                    <div class="consultant-upload-box">
                        <h3>Upload Consultant Report</h3>

                        <form method="POST"
                            action="reports.php"
                            enctype="multipart/form-data">

                            <input type="hidden" name="action"
                                value="upload_consultant_report">

                            <input type="hidden" name="csrf_token"
                                value="<?= e($_SESSION['consultant_report_csrf']) ?>">

                            <div class="consultant-form-grid">

                                <div class="form-group">
                                    <label for="consultant_name">Consultant Name</label>
                                    <input type="text"
                                        id="consultant_name"
                                        name="consultant_name"
                                        maxlength="150"
                                        placeholder="Enter consultant name"
                                        required>
                                </div>

                                <div class="form-group">
                                    <label for="report_date">Report Date</label>
                                    <input type="date"
                                        id="report_date"
                                        name="report_date"
                                        max="<?= date('Y-m-d') ?>"
                                        required>
                                </div>

                                <div class="form-group full-width">
                                    <label for="comments">Comments</label>
                                    <textarea id="comments"
                                        name="comments"
                                        rows="3"
                                        placeholder="Enter report comments or observations..."></textarea>
                                </div>

                                <div class="form-group full-width">
                                    <label for="consultant_file">Attach Report (PDF or Image)</label>
                                    <input type="file"
                                        id="consultant_file"
                                        name="consultant_file"
                                        accept=".pdf,.jpg,.jpeg,.png"
                                        required>
                                    <small>Maximum file size: 10 MB. Accepted formats: PDF, JPG and PNG.</small>
                                </div>

                            </div>

                            <button type="submit" class="consultant-upload-btn">
                                <i class="fas fa-upload"></i>
                                Upload Report
                            </button>

                        </form>
                    </div>
                <?php endif; ?>

                <div class="consultant-table-wrapper">
                    <h3>Uploaded Reports</h3>

                    <?php if (empty($consultantReports)): ?>

                        <div class="consultant-empty">
                            <i class="fas fa-file-circle-xmark"></i>
                            <p>No consultant reports have been uploaded yet.</p>
                        </div>

                    <?php else: ?>

                        <table class="consultant-table">
                            <thead>
                                <tr>
                                    <th>#</th>
                                    <?php if ($isSuperAdmin): ?>
                                        <th>Farm</th>
                                    <?php endif; ?>
                                    <th>Consultant</th>
                                    <th>Report Date</th>
                                    <th>Comments</th>
                                    <th>Attachment</th>
                                    <th>Uploaded By</th>
                                </tr>
                            </thead>

                            <tbody>
                                <?php foreach ($consultantReports as $index => $report): ?>
                                    <tr>
                                        <td><?= $index + 1 ?></td>

                                        <?php if ($isSuperAdmin): ?>
                                            <td><?= e($report['farm_name']) ?></td>
                                        <?php endif; ?>

                                        <td>
                                            <strong><?= e($report['consultant_name']) ?></strong>
                                        </td>

                                        <td>
                                            <?= e(date('d M Y', strtotime($report['report_date']))) ?>
                                        </td>

                                        <td>
                                            <?= $report['comments']
                                                ? nl2br(e($report['comments']))
                                                : '<span class="muted">No comments</span>' ?>
                                        </td>

                                        <td>
                                            <a class="consultant-file-link"
                                                href="reports.php?consultant_file=<?= (int) $report['report_id'] ?>"
                                                target="_blank">
                                                <i class="fas fa-file-arrow-up"></i>
                                                <?= e($report['original_file_name']) ?>
                                            </a>
                                        </td>

                                        <td><?= e($report['uploaded_by_name']) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>

                    <?php endif; ?>
                </div>

            </section>
        <?php endif; ?>


        <section class="panel">
            <h2>Feed Stock Report</h2>
            <p class="panel-desc">Current inventory levels for <?= e($farmLabel) ?>.</p>
            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>Feed Type</th>
                            <th>Available Quantity</th>
                            <th>Reorder Level</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!$feedRows): ?>
                            <tr>
                                <td colspan="4" class="empty">No feed stock entries found.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($feedRows as $feed): ?>
                                <?php $isLow = (float) $feed['quantity'] <= (float) $feed['reorder_level']; ?>
                                <tr>
                                    <td><strong><?= e($feed['feed_name']) ?></strong></td>
                                    <td><?= number_format((float) $feed['quantity'], 2) ?> <?= e($feed['unit']) ?></td>
                                    <td><?= number_format((float) $feed['reorder_level'], 2) ?> <?= e($feed['unit']) ?></td>
                                    <td>
                                        <span class="pill <?= $isLow ? 'warn' : 'good' ?>">
                                            <?= $isLow ? 'Low Stock' : 'In Stock' ?>
                                        </span>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>

        <section class="panel">
            <h2>Milk Production Details</h2>
            <p class="panel-desc">Individual production entries during the selected period. Showing up to 250 entries.</p>
            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Farm</th>
                            <th>Cow Tag</th>
                            <th>Cow Name</th>
                            <th>Session</th>
                            <th>Litres</th>
                            <th>Recorded By</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!$milkDetails): ?>
                            <tr>
                                <td colspan="7" class="empty">No milk records found.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($milkDetails as $row): ?>
                                <tr>
                                    <td><?= e($row['production_date']) ?></td>
                                    <td><?= e($row['farm_name']) ?></td>
                                    <td><?= e($row['tag_number']) ?></td>
                                    <td><?= e($row['cow_name']) ?></td>
                                    <td><?= e(ucfirst($row['milking_session'])) ?></td>
                                    <td><strong><?= number_format((float) $row['quantity_litres'], 2) ?></strong></td>
                                    <td><?= e($row['recorded_by'] ?? 'Unknown') ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>

        <section class="panel">
            <h2>Health Records</h2>
            <p class="panel-desc">Health events recorded during the selected period.</p>
            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Farm</th>
                            <th>Cow Tag</th>
                            <th>Cow Name</th>
                            <th>Condition</th>
                            <th>Treatment</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!$healthDetails): ?>
                            <tr>
                                <td colspan="7" class="empty">No health records found.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($healthDetails as $row): ?>
                                <tr>
                                    <td><?= e($row['record_date']) ?></td>
                                    <td><?= e($row['farm_name']) ?></td>
                                    <td><?= e($row['tag_number']) ?></td>
                                    <td><?= e($row['cow_name']) ?></td>
                                    <td><?= e($row['condition_name']) ?></td>
                                    <td><?= e($row['treatment'] ?? '') ?></td>
                                    <td>
                                        <span class="pill <?= $row['status'] === 'recovered' ? 'good' : ($row['status'] === 'under_treatment' ? 'warn' : 'bad') ?>">
                                            <?= e(ucwords(str_replace('_', ' ', $row['status']))) ?>
                                        </span>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>

        <section class="panel">
            <h2>Upcoming & Overdue Vaccinations</h2>
            <p class="panel-desc">Vaccinations due now or within the next 30 days.</p>
            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>Due Date</th>
                            <th>Farm</th>
                            <th>Cow Tag</th>
                            <th>Cow Name</th>
                            <th>Vaccine</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!$upcomingVaccines): ?>
                            <tr>
                                <td colspan="6" class="empty">No upcoming or overdue vaccinations found.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($upcomingVaccines as $vaccine): ?>
                                <?php $overdue = $vaccine['next_due_date'] < date('Y-m-d'); ?>
                                <tr>
                                    <td><?= e($vaccine['next_due_date']) ?></td>
                                    <td><?= e($vaccine['farm_name']) ?></td>
                                    <td><?= e($vaccine['tag_number']) ?></td>
                                    <td><?= e($vaccine['cow_name']) ?></td>
                                    <td><?= e($vaccine['vaccine_name']) ?></td>
                                    <td><span class="pill <?= $overdue ? 'bad' : 'warn' ?>"><?= $overdue ? 'Overdue' : 'Due Soon' ?></span></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>

        <div class="footer">DairyFlow Farm Management System · Generated <?= e(date('d M Y, H:i')) ?></div>
    </main>

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
</body>

</html>