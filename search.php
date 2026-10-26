<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';

requireLogin();
requireRole(['super_admin', 'admin', 'farm_manager']);

$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

$role = $_SESSION['role_name'];
$userId = (int) $_SESSION['user_id'];
$farmId = (int) ($_SESSION['farm_id'] ?? 0);
$isSuperAdmin = $role === 'super_admin';

$pageTitle = 'Cow Search';
$q = trim((string) ($_GET['q'] ?? ''));
$selectedId = filter_var(
    $_GET['cow_id'] ?? null,
    FILTER_VALIDATE_INT
);

$results = [];
$cow = null;
$mother = null;
$father = null;
$healthRecords = [];
$vaccinations = [];
$milkRecords = [];
$error = '';

/*
 * Build a permission condition for a cow table alias.
 * Every cow lookup uses this condition.
 */
function cowAccess(string $alias, string $role, int $farmId, int $userId): array
{
    if ($role === 'super_admin') {
        return ['1=1', []];
    }

    if ($farmId < 1) {
        return ['1=0', []];
    }

    if ($role === 'farm_manager') {
        return [
            "$alias.farm_id = ? AND $alias.manager_id = ?",
            [$farmId, $userId]
        ];
    }

    return ["$alias.farm_id = ?", [$farmId]];
}

/*
 * Load matching cows, limited to records the current user may see.
 */
try {
    if ($q !== '') {
        [$accessSql, $accessParams] = cowAccess(
            'c',
            $role,
            $farmId,
            $userId
        );

        $stmt = $pdo->prepare("
            SELECT c.cow_id, c.tag_number, c.cow_name,
                   c.breed, c.gender, c.status, f.farm_name
            FROM cows c
            INNER JOIN farms f ON f.farm_id = c.farm_id
            WHERE ($accessSql)
              AND (
                  c.cow_name LIKE ?
                  OR c.tag_number LIKE ?
              )
            ORDER BY c.cow_name, c.tag_number
            LIMIT 30
        ");

        $searchTerm = '%' . $q . '%';
        $stmt->execute(array_merge(
            $accessParams,
            [$searchTerm, $searchTerm]
        ));

        $results = $stmt->fetchAll();
    }

    /*
     * Load the selected cow independently.
     * A cow_id in the URL does not bypass permissions.
     */
    if ($selectedId !== false && $selectedId !== null && $selectedId > 0) {
        [$accessSql, $accessParams] = cowAccess(
            'c',
            $role,
            $farmId,
            $userId
        );

        $stmt = $pdo->prepare("
            SELECT c.*, f.farm_name
            FROM cows c
            INNER JOIN farms f ON f.farm_id = c.farm_id
            WHERE c.cow_id = ?
              AND ($accessSql)
            LIMIT 1
        ");

        $stmt->execute(array_merge([$selectedId], $accessParams));
        $cow = $stmt->fetch() ?: null;

        if (!$cow) {
            $error = 'Cow not found, or you do not have permission to view it.';
        }
    }

    if ($cow) {
        /*
         * Parents are displayed only if the current user can also
         * access their records.
         */
        foreach (['mother_id' => 'mother', 'father_id' => 'father'] as $field => $key) {
            $parentId = (int) ($cow[$field] ?? 0);

            if ($parentId > 0) {
                [$parentAccess, $parentParams] = cowAccess(
                    'p',
                    $role,
                    $farmId,
                    $userId
                );

                $stmt = $pdo->prepare("
                    SELECT p.cow_id, p.cow_name, p.tag_number,
                           p.breed, p.gender
                    FROM cows p
                    WHERE p.cow_id = ?
                      AND ($parentAccess)
                    LIMIT 1
                ");

                $stmt->execute(array_merge([$parentId], $parentParams));
                $$key = $stmt->fetch() ?: null;
            }
        }

        /*
         * These records are retrieved only after the cow itself
         * has passed the permission check above.
         */
        $stmt = $pdo->prepare("
            SELECT health_id, condition_name, symptoms, treatment,
                   veterinarian, record_date, next_checkup, status
            FROM health_records
            WHERE cow_id = ?
            ORDER BY record_date DESC, health_id DESC
        ");
        $stmt->execute([(int) $cow['cow_id']]);
        $healthRecords = $stmt->fetchAll();

        $stmt = $pdo->prepare("
            SELECT vaccination_id, vaccine_name, vaccination_date,
                   next_due_date, administered_by, notes
            FROM vaccinations
            WHERE cow_id = ?
            ORDER BY vaccination_date DESC, vaccination_id DESC
        ");
        $stmt->execute([(int) $cow['cow_id']]);
        $vaccinations = $stmt->fetchAll();

        $stmt = $pdo->prepare("
            SELECT production_date, milking_session,
                   quantity_litres, notes
            FROM milk_production
            WHERE cow_id = ?
              AND farm_id = ?
            ORDER BY production_date DESC, production_id DESC
            LIMIT 100
        ");
        $stmt->execute([
            (int) $cow['cow_id'],
            (int) $cow['farm_id']
        ]);
        $milkRecords = $stmt->fetchAll();
    }
} catch (Throwable $exception) {
    error_log('Cow search error: ' . $exception->getMessage());
    $error = 'Something went wrong while loading cow records. Please try again.';
}

function searchEscape(mixed $value): string
{
    return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES, 'UTF-8');
}

function displayDate(?string $date): string
{
    if (!$date || $date === '0000-00-00') {
        return 'Not recorded';
    }

    return date('d M Y', strtotime($date));
}

$dashboardUrl = match ($role) {
    'super_admin' => 'super_admin.php',
    'admin' => 'admin.php',
    'farm_manager' => 'farm_manager.php',
    default => 'index.php'
};
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= searchEscape($pageTitle) ?> | DairyFlow</title>
    <link rel="stylesheet" href="assets/style.css">
    <style>
        .search-wrap {
            max-width: 1200px;
            margin: 0 auto;
        }

        .search-panel,
        .profile-panel,
        .record-panel {
            background: var(--card, #fff);
            border: 1px solid #e4ebe5;
            border-radius: 16px;
            padding: 22px;
            margin-bottom: 22px;
            box-shadow: 0 5px 18px rgba(20, 50, 30, .04);
        }

        .search-form {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
            margin-top: 15px;
        }

        .search-form input {
            flex: 1;
            min-width: 220px;
            padding: 13px 15px;
            border: 1px solid #d7e2d9;
            border-radius: 10px;
            font: inherit;
        }

        .search-button {
            border: 0;
            border-radius: 10px;
            padding: 13px 20px;
            background: #176b43;
            color: white;
            font-weight: 700;
            cursor: pointer;
        }

        .search-button:hover {
            background: #105536;
        }

        .cow-results {
            display: grid;
            gap: 10px;
            margin-top: 16px;
        }

        .cow-result {
            display: block;
            padding: 15px;
            border: 1px solid #e4ebe5;
            border-radius: 12px;
            color: inherit;
            text-decoration: none;
        }

        .cow-result:hover {
            border-color: #176b43;
            background: #f6fbf7;
        }

        .cow-result strong {
            color: #176b43;
        }

        .profile-heading {
            display: flex;
            justify-content: space-between;
            gap: 12px;
            flex-wrap: wrap;
        }

        .profile-heading h2 {
            margin: 4px 0;
        }

        .tag {
            display: inline-block;
            padding: 6px 10px;
            background: #eaf5ed;
            color: #176b43;
            border-radius: 20px;
            font-size: .85rem;
            font-weight: 700;
        }

        .detail-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(175px, 1fr));
            gap: 12px;
            margin-top: 20px;
        }

        .detail-item {
            padding: 13px;
            background: #f6f9f6;
            border-radius: 10px;
            overflow-wrap: anywhere;
        }

        .detail-item small {
            display: block;
            color: #68776d;
            margin-bottom: 5px;
        }

        .detail-item strong {
            color: #23382b;
        }

        .record-panel h3,
        .profile-panel h2 {
            margin-top: 0;
        }

        .record-table {
            width: 100%;
            border-collapse: collapse;
            min-width: 600px;
        }

        .table-scroll {
            overflow-x: auto;
        }

        .record-table th,
        .record-table td {
            text-align: left;
            padding: 12px;
            border-bottom: 1px solid #e7eee8;
            vertical-align: top;
        }

        .record-table th {
            color: #526457;
            background: #f6f9f6;
            font-size: .84rem;
        }

        .empty-state {
            color: #68776d;
            padding: 14px 0;
        }

        .notice {
            padding: 13px 15px;
            border-radius: 10px;
            margin-bottom: 16px;
            background: #fff4e6;
            color: #874c00;
        }

        .family-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 12px;
        }

        .family-card {
            padding: 15px;
            border: 1px solid #e4ebe5;
            border-radius: 12px;
        }

        .back-link {
            color: #176b43;
            text-decoration: none;
            font-weight: 700;
        }

        @media (max-width: 600px) {

            .search-panel,
            .profile-panel,
            .record-panel {
                padding: 16px;
            }

            .search-button {
                width: 100%;
            }
        }
    </style>
</head>

<body>
    <div class="app-shell">
        <aside class="sidebar">
            <a href="<?= searchEscape($dashboardUrl) ?>" class="brand">
                <span class="logo small-logo">DF</span>
                <span>DairyFlow<small>FARM MANAGEMENT</small></span>
            </a>
            <p class="nav-label">WORKSPACE</p>
            <nav>
                <a href="<?= searchEscape($dashboardUrl) ?>">▦ Dashboard</a>
                <a href="cows.php">♧ Cows &amp; Records</a>
                <a class="active" href="search.php">⌕ Cow Search</a>
                <a href="milk.php">◉ Milk Production</a>
                <a href="health.php">♡ Health &amp; Vaccinations</a>
                <a href="reports.php">▥ Reports</a>
            </nav>
            <div class="sidebar-bottom">
                <div class="user-chip">
                    <div class="avatar"><?= searchEscape(strtoupper(substr((string) ($_SESSION['full_name'] ?? 'U'), 0, 1))) ?></div>
                    <div>
                        <strong><?= searchEscape($_SESSION['full_name'] ?? 'User') ?></strong>
                        <small><?= searchEscape(ucwords(str_replace('_', ' ', $role))) ?></small>
                    </div>
                </div>
                <a class="nav-link" href="change_password.php">🔐 Change Password</a>
                <a class="logout" href="logout.php">↪ Sign out</a>
            </div>
        </aside>

        <main class="main-area">
            <header class="topbar">
                <div>
                    <span class="eyebrow">DAIRY FARM MANAGEMENT</span>
                    <h1>Cow Search</h1>
                </div>
                <div class="topbar-right">
                    <span class="status-dot"></span> System online
                </div>
            </header>

            <div class="content">
                <div class="search-wrap">
                    <section class="search-panel">
                        <span class="eyebrow">HERD RECORDS</span>
                        <h2>Find a cow</h2>
                        <p class="muted">Search using a cow's name or unique tag number.</p>

                        <form class="search-form" method="get" action="search.php">
                            <input
                                type="search"
                                name="q"
                                value="<?= searchEscape($q) ?>"
                                placeholder="Enter cow name or tag number..."
                                aria-label="Cow name or tag number"
                                required>
                            <button class="search-button" type="submit">Search cows</button>
                        </form>
                    </section>

                    <?php if ($error !== ''): ?>
                        <div class="notice"><?= searchEscape($error) ?></div>
                    <?php endif; ?>

                    <?php if ($q !== ''): ?>
                        <section class="record-panel">
                            <h3>Search results</h3>
                            <?php if (!$results): ?>
                                <p class="empty-state">No matching cows were found in your access scope.</p>
                            <?php else: ?>
                                <p class="muted"><?= count($results) ?> result(s), up to 30 shown.</p>
                                <div class="cow-results">
                                    <?php foreach ($results as $result): ?>
                                        <a class="cow-result"
                                            href="search.php?cow_id=<?= (int) $result['cow_id'] ?>&amp;q=<?= urlencode($q) ?>">
                                            <strong><?= searchEscape($result['cow_name'] ?: 'Unnamed cow') ?></strong>
                                            <span class="tag"><?= searchEscape($result['tag_number']) ?></span>
                                            <div class="muted">
                                                <?= searchEscape($result['breed'] ?: 'Breed not recorded') ?>
                                                · <?= searchEscape(ucfirst($result['gender'])) ?>
                                                · <?= searchEscape(ucfirst($result['status'] ?? 'Unknown')) ?>
                                            </div>
                                            <small><?= searchEscape($result['farm_name']) ?></small>
                                        </a>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </section>
                    <?php endif; ?>

                    <?php if ($cow): ?>
                        <section class="profile-panel">
                            <div class="profile-heading">
                                <div>
                                    <span class="eyebrow">COW PROFILE</span>
                                    <h2><?= searchEscape($cow['cow_name'] ?: 'Unnamed cow') ?></h2>
                                    <span class="tag">Tag: <?= searchEscape($cow['tag_number']) ?></span>
                                </div>
                                <a class="back-link" href="search.php<?= $q !== '' ? '?q=' . urlencode($q) : '' ?>">← Back to search</a>
                            </div>

                            <div class="detail-grid">
                                <div class="detail-item"><small>Farm</small><strong><?= searchEscape($cow['farm_name']) ?></strong></div>
                                <div class="detail-item"><small>Date of birth</small><strong><?= searchEscape(displayDate($cow['date_of_birth'])) ?></strong></div>
                                <div class="detail-item"><small>Breed</small><strong><?= searchEscape($cow['breed'] ?: 'Not recorded') ?></strong></div>
                                <div class="detail-item"><small>Gender</small><strong><?= searchEscape(ucfirst($cow['gender'])) ?></strong></div>
                                <div class="detail-item"><small>Origin</small><strong><?= searchEscape(ucwords(str_replace('_', ' ', $cow['origin']))) ?></strong></div>
                                <div class="detail-item"><small>Status</small><strong><?= searchEscape(ucfirst($cow['status'] ?? 'Unknown')) ?></strong></div>
                                <div class="detail-item"><small>Age</small>
                                    <strong>
                                        <?php
                                        $birth = new DateTimeImmutable($cow['date_of_birth']);
                                        $today = new DateTimeImmutable('today');
                                        echo searchEscape($birth->diff($today)->y . ' years, ' . $birth->diff($today)->m . ' months');
                                        ?>
                                    </strong>
                                </div>
                            </div>

                            <?php if (!empty($cow['notes'])): ?>
                                <h3>Additional notes</h3>
                                <p><?= nl2br(searchEscape($cow['notes'])) ?></p>
                            <?php endif; ?>
                        </section>

                        <section class="record-panel">
                            <h3>Family tree</h3>
                            <div class="family-grid">
                                <div class="family-card">
                                    <strong>Mother</strong>
                                    <?php if ($mother): ?>
                                        <p><?= searchEscape($mother['cow_name'] ?: 'Unnamed cow') ?></p>
                                        <small>Tag: <?= searchEscape($mother['tag_number']) ?></small>
                                        <?php if ($mother['cow_id'] !== $cow['cow_id']): ?>
                                            <p><a class="back-link" href="search.php?cow_id=<?= (int) $mother['cow_id'] ?>">View profile</a></p>
                                        <?php endif; ?>
                                    <?php elseif (!empty($cow['mother_id'])): ?>
                                        <p class="empty-state">Recorded parent is outside your access scope.</p>
                                    <?php else: ?>
                                        <p class="empty-state">Mother not recorded.</p>
                                    <?php endif; ?>
                                </div>
                                <div class="family-card">
                                    <strong>Father</strong>
                                    <?php if ($father): ?>
                                        <p><?= searchEscape($father['cow_name'] ?: 'Unnamed cow') ?></p>
                                        <small>Tag: <?= searchEscape($father['tag_number']) ?></small>
                                        <?php if ($father['cow_id'] !== $cow['cow_id']): ?>
                                            <p><a class="back-link" href="search.php?cow_id=<?= (int) $father['cow_id'] ?>">View profile</a></p>
                                        <?php endif; ?>
                                    <?php elseif (!empty($cow['father_id'])): ?>
                                        <p class="empty-state">Recorded parent is outside your access scope.</p>
                                    <?php else: ?>
                                        <p class="empty-state">Father not recorded.</p>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </section>

                        <section class="record-panel">
                            <h3>Health history</h3>
                            <?php if (!$healthRecords): ?>
                                <p class="empty-state">No health records found for this cow.</p>
                            <?php else: ?>
                                <div class="table-scroll">
                                    <table class="record-table">
                                        <thead>
                                            <tr>
                                                <th>Date</th>
                                                <th>Condition</th>
                                                <th>Symptoms</th>
                                                <th>Treatment</th>
                                                <th>Veterinarian</th>
                                                <th>Next checkup</th>
                                                <th>Status</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($healthRecords as $record): ?>
                                                <tr>
                                                    <td><?= searchEscape(displayDate($record['record_date'])) ?></td>
                                                    <td><?= searchEscape($record['condition_name']) ?></td>
                                                    <td><?= searchEscape($record['symptoms'] ?: 'Not recorded') ?></td>
                                                    <td><?= searchEscape($record['treatment'] ?: 'Not recorded') ?></td>
                                                    <td><?= searchEscape($record['veterinarian'] ?: 'Not recorded') ?></td>
                                                    <td><?= searchEscape(displayDate($record['next_checkup'])) ?></td>
                                                    <td><?= searchEscape(ucwords(str_replace('_', ' ', $record['status'] ?? 'Unknown'))) ?></td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            <?php endif; ?>
                        </section>

                        <section class="record-panel">
                            <h3>Vaccination history</h3>
                            <?php if (!$vaccinations): ?>
                                <p class="empty-state">No vaccination records found for this cow.</p>
                            <?php else: ?>
                                <div class="table-scroll">
                                    <table class="record-table">
                                        <thead>
                                            <tr>
                                                <th>Vaccine</th>
                                                <th>Date given</th>
                                                <th>Next due</th>
                                                <th>Administered by</th>
                                                <th>Notes</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($vaccinations as $record): ?>
                                                <tr>
                                                    <td><?= searchEscape($record['vaccine_name']) ?></td>
                                                    <td><?= searchEscape(displayDate($record['vaccination_date'])) ?></td>
                                                    <td><?= searchEscape(displayDate($record['next_due_date'])) ?></td>
                                                    <td><?= searchEscape($record['administered_by'] ?: 'Not recorded') ?></td>
                                                    <td><?= searchEscape($record['notes'] ?: 'None') ?></td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            <?php endif; ?>
                        </section>

                        <section class="record-panel">
                            <h3>Milk production history</h3>
                            <?php if (!$milkRecords): ?>
                                <p class="empty-state">No milk production records found for this cow.</p>
                            <?php else: ?>
                                <div class="table-scroll">
                                    <table class="record-table">
                                        <thead>
                                            <tr>
                                                <th>Date</th>
                                                <th>Session</th>
                                                <th>Quantity (litres)</th>
                                                <th>Notes</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($milkRecords as $record): ?>
                                                <tr>
                                                    <td><?= searchEscape(displayDate($record['production_date'])) ?></td>
                                                    <td><?= searchEscape(ucfirst($record['milking_session'])) ?></td>
                                                    <td><?= number_format((float) $record['quantity_litres'], 2) ?> L</td>
                                                    <td><?= searchEscape($record['notes'] ?: 'None') ?></td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                                <p class="muted">Showing up to the 100 most recent records.</p>
                            <?php endif; ?>
                        </section>
                    <?php endif; ?>
                </div>
            </div>
        </main>
    </div>
</body>

</html>