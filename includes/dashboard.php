<?php
require_once __DIR__ . '/auth.php';
requireLogin();

$role = $_SESSION['role_name'];
$farmId = $_SESSION['farm_id'] ?? null;

$isSuperAdmin = $role === 'super_admin';

if ($isSuperAdmin) {
    $farmCount = (int)$pdo->query("
        SELECT COUNT(*) FROM farms WHERE status = 'active'
    ")->fetchColumn();

    $cowCount = (int)$pdo->query("
        SELECT COUNT(*) FROM cows
    ")->fetchColumn();

    $milkStmt = $pdo->query("
        SELECT COALESCE(SUM(quantity_litres), 0)
        FROM milk_production
        WHERE production_date = CURDATE()
    ");
    $milkToday = (float)$milkStmt->fetchColumn();

    $healthCount = (int)$pdo->query("
        SELECT COUNT(*)
        FROM health_records hr
        JOIN cows c ON c.cow_id = hr.cow_id
        WHERE hr.status IN ('ongoing', 'under_treatment')
    ")->fetchColumn();

    $farms = $pdo->query("
        SELECT f.farm_id, f.farm_name,
               COUNT(DISTINCT c.cow_id) AS cow_count,
               COALESCE((
                   SELECT SUM(mp.quantity_litres)
                   FROM milk_production mp
                   WHERE mp.farm_id = f.farm_id
                   AND mp.production_date = CURDATE()
               ), 0) AS milk_today
        FROM farms f
        LEFT JOIN cows c ON c.farm_id = f.farm_id
        GROUP BY f.farm_id, f.farm_name
        ORDER BY f.farm_name
    ")->fetchAll();
} else {
    $farmCount = $farmId ? 1 : 0;

    $stmt = $pdo->prepare("
        SELECT COUNT(*) FROM cows WHERE farm_id = :farm
    ");
    $stmt->execute(['farm' => $farmId ?: 0]);
    $cowCount = (int)$stmt->fetchColumn();

    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(quantity_litres), 0)
        FROM milk_production
        WHERE farm_id = :farm
        AND production_date = CURDATE()
    ");
    $stmt->execute(['farm' => $farmId ?: 0]);
    $milkToday = (float)$stmt->fetchColumn();

    $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM health_records hr
        JOIN cows c ON c.cow_id = hr.cow_id
        WHERE c.farm_id = :farm
        AND hr.status IN ('ongoing', 'under_treatment')
    ");
    $stmt->execute(['farm' => $farmId ?: 0]);
    $healthCount = (int)$stmt->fetchColumn();

    $stmt = $pdo->prepare("
        SELECT f.farm_id, f.farm_name,
               COUNT(DISTINCT c.cow_id) AS cow_count,
               COALESCE((
                   SELECT SUM(mp.quantity_litres)
                   FROM milk_production mp
                   WHERE mp.farm_id = f.farm_id
                   AND mp.production_date = CURDATE()
               ), 0) AS milk_today
        FROM farms f
        LEFT JOIN cows c ON c.farm_id = f.farm_id
        WHERE f.farm_id = :farm
        GROUP BY f.farm_id, f.farm_name
    ");
    $stmt->execute(['farm' => $farmId ?: 0]);
    $farms = $stmt->fetchAll();
}

$feedSql = $isSuperAdmin
    ? "SELECT COALESCE(SUM(quantity), 0) FROM feed_stock"
    : "SELECT COALESCE(SUM(quantity), 0) FROM feed_stock WHERE farm_id = :farm";

$stmt = $pdo->prepare($feedSql);
$stmt->execute($isSuperAdmin ? [] : ['farm' => $farmId ?: 0]);
$feedStock = (float)$stmt->fetchColumn();

$milkSql = $isSuperAdmin
    ? "SELECT mp.*, f.farm_name, c.tag_number, c.cow_name
       FROM milk_production mp
       JOIN farms f ON f.farm_id = mp.farm_id
       LEFT JOIN cows c ON c.cow_id = mp.cow_id
       ORDER BY mp.production_id DESC LIMIT 8"
    : "SELECT mp.*, f.farm_name, c.tag_number, c.cow_name
       FROM milk_production mp
       JOIN farms f ON f.farm_id = mp.farm_id
       LEFT JOIN cows c ON c.cow_id = mp.cow_id
       WHERE mp.farm_id = :farm
       ORDER BY mp.production_id DESC LIMIT 8";

$stmt = $pdo->prepare($milkSql);
$stmt->execute($isSuperAdmin ? [] : ['farm' => $farmId ?: 0]);
$recentMilk = $stmt->fetchAll();

$pageTitle = $pageTitle ?? 'Dashboard';
$firstName = explode(' ', trim($_SESSION['full_name']))[0] ?? 'there';
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($pageTitle) ?> | DairyFlow</title>
    <link rel="stylesheet" href="assets/style.css">
</head>

<body>
    <div class="app-shell">

        <aside class="sidebar">
            <a href="index.php" class="brand">
                <span class="logo small-logo">DF</span>
                <span>DairyFlow<small>FARM MANAGEMENT</small></span>
            </a>

            <p class="nav-label">WORKSPACE</p>
            <nav>
                <a class="active" href="<?= e(basename($_SERVER['PHP_SELF'])) ?>">▦ &nbsp; Dashboard</a>

                <?php if (in_array($role, ['super_admin', 'admin', 'farm_manager'], true)): ?>
                    <a href="cows.php">♧ &nbsp; Cows & Records</a>
                    <a href="milk.php">◉ &nbsp; Milk Production</a>
                    <a href="health.php">♡ &nbsp; Health & Vaccinations</a>
                <?php endif; ?>

                <a href="feeds.php">▤ &nbsp; Feed Inventory</a>
                <a href="reports.php">▥ &nbsp; Reports</a>

                <?php if ($isSuperAdmin): ?>
                    <a href="users.php">♙ &nbsp; Manage Users</a>
                    <a href="farms.php">⌂ &nbsp; Manage Farms</a>
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
                <a class="logout" href="logout.php">↪ &nbsp; Sign out</a>
            </div>
        </aside>

        <main class="main-area">
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

            <div class="content">
                <div class="welcome-row">
                    <div>
                        <span class="eyebrow">YOUR FARM AT A GLANCE</span>
                        <h2>Hello, <?= e($firstName) ?> <span class="wave">✳</span></h2>
                        <p class="muted">Here is the latest overview of your operations.</p>
                    </div>
                    <div class="date-chip"><?= date('D, d M Y') ?></div>
                </div>

                <div class="metric-grid">
                    <article class="metric-card">
                        <div class="metric-top"><span class="metric-icon green">♧</span><span class="metric-label">REGISTERED COWS</span></div>
                        <div class="metric-number"><?= number_format($cowCount) ?></div>
                        <p class="metric-foot">Cows in your access scope</p>
                    </article>

                    <article class="metric-card">
                        <div class="metric-top"><span class="metric-icon mint">◉</span><span class="metric-label">MILK TODAY</span></div>
                        <div class="metric-number"><?= number_format($milkToday, 1) ?><small> L</small></div>
                        <p class="metric-foot">Today's recorded production</p>
                    </article>

                    <article class="metric-card">
                        <div class="metric-top"><span class="metric-icon navy">♡</span><span class="metric-label">ACTIVE HEALTH CASES</span></div>
                        <div class="metric-number"><?= number_format($healthCount) ?></div>
                        <p class="metric-foot">Ongoing or under treatment</p>
                    </article>

                    <article class="metric-card">
                        <div class="metric-top"><span class="metric-icon lime">▤</span><span class="metric-label">FEED STOCK</span></div>
                        <div class="metric-number"><?= number_format($feedStock, 1) ?></div>
                        <p class="metric-foot">Combined stock quantities</p>
                    </article>
                </div>

                <?php if ($role === 'store_manager'): ?>
                    <div class="section-grid">
                        <section class="panel panel-wide">
                            <h3>Store manager workspace</h3>
                            <p class="muted">Manage feed inventory, milk stock movements, calf allocations and inventory reports.</p>
                            <div class="quick-actions">
                                <a href="feeds.php"><span class="action-icon">▤</span><span><strong>Manage feed stock</strong><small>Record purchases and feed usage</small></span><b>›</b></a>
                                <a href="milk.php"><span class="action-icon">◉</span><span><strong>Review milk records</strong><small>Check production and allocations</small></span><b>›</b></a>
                                <a href="reports.php"><span class="action-icon">▥</span><span><strong>Inventory reports</strong><small>Review available stock and movements</small></span><b>›</b></a>
                            </div>
                        </section>
                    </div>
                <?php else: ?>
                    <div class="section-grid">
                        <section class="panel panel-wide">
                            <div class="panel-heading">
                                <div>
                                    <h3><?= $isSuperAdmin ? 'Farm performance' : 'Farm overview' ?></h3>
                                    <p class="muted">Cow counts and milk recorded today</p>
                                </div>
                                <a class="text-link" href="reports.php">View reports ↗</a>
                            </div>

                            <?php if (!$farms): ?>
                                <div class="empty-state"><strong>No farm records yet</strong>
                                    <p>Create a farm before registering cows and production.</p>
                                </div>
                            <?php else: ?>
                                <div class="table-wrap">
                                    <table>
                                        <thead>
                                            <tr>
                                                <th>FARM</th>
                                                <th>COWS</th>
                                                <th>MILK TODAY</th>
                                                <th>STATUS</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($farms as $farm): ?>
                                                <tr>
                                                    <td><strong><?= e($farm['farm_name']) ?></strong></td>
                                                    <td><?= number_format((int)$farm['cow_count']) ?></td>
                                                    <td><?= number_format((float)$farm['milk_today'], 1) ?> L</td>
                                                    <td><span class="pill pill-green">Active</span></td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            <?php endif; ?>
                        </section>

                        <section class="panel">
                            <div class="panel-heading">
                                <div>
                                    <h3>Quick actions</h3>
                                    <p class="muted">Common daily tasks</p>
                                </div>
                            </div>
                            <div class="quick-actions">
                                <a href="cows.php"><span class="action-icon">♧</span><span><strong>Register a cow</strong><small>Add identification and breed</small></span><b>›</b></a>
                                <a href="milk.php"><span class="action-icon">◉</span><span><strong>Record milk</strong><small>Log a milking session</small></span><b>›</b></a>
                                <a href="health.php"><span class="action-icon">♡</span><span><strong>Health record</strong><small>Track health and vaccination</small></span><b>›</b></a>
                                <a href="feeds.php"><span class="action-icon">▤</span><span><strong>Feed inventory</strong><small>Review stock and usage</small></span><b>›</b></a>
                                <a href="reports.php"><span class="action-icon">▥</span><span><strong>Reports</strong><small>View production summaries</small></span><b>›</b></a>
                            </div>
                        </section>
                    </div>
                <?php endif; ?>

                <section class="panel recent-panel">
                    <div class="panel-heading">
                        <div>
                            <h3>Recent milk records</h3>
                            <p class="muted">Latest recorded production</p>
                        </div>
                        <a class="text-link" href="milk.php">All records ↗</a>
                    </div>

                    <?php if (!$recentMilk): ?>
                        <div class="empty-state compact">
                            <p>No milk records have been entered yet.</p>
                        </div>
                    <?php else: ?>
                        <div class="table-wrap">
                            <table>
                                <thead>
                                    <tr>
                                        <th>DATE</th><?php if ($isSuperAdmin): ?><th>FARM</th><?php endif; ?><th>COW / TAG</th>
                                        <th>SESSION</th>
                                        <th>QUANTITY</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($recentMilk as $record): ?>
                                        <tr>
                                            <td><?= e($record['production_date']) ?></td>
                                            <?php if ($isSuperAdmin): ?><td><?= e($record['farm_name']) ?></td><?php endif; ?>
                                            <td><?= e($record['cow_name'] ?: $record['tag_number'] ?: 'Farm total') ?></td>
                                            <td><?= e(ucfirst($record['milking_session'])) ?></td>
                                            <td><strong><?= number_format((float)$record['quantity_litres'], 1) ?> L</strong></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </section>
            </div>

            <footer class="footer">DairyFlow · Farm operations, made clearer.</footer>
        </main>
    </div>
</body>

</html>