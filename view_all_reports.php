<?php
session_start();

require_once __DIR__ . '/includes/Database.php';

// Check admin authentication
$userId = $_SESSION['user_id'] ?? null;
if (!$userId) {
    header('Location: login.html');
    exit;
}

$database = new Database();
$user = $database->fetchOne("SELECT id, email, display_name FROM users WHERE id = ?", [$userId]);
if (!$user) {
    header('Location: login.html');
    exit;
}

$isAdmin = strpos(strtolower($user['email'] ?? ''), 'admin') !== false ||
           strtolower($user['email'] ?? '') === 'admin@safespace.com';

if (!$isAdmin) {
    header('Location: dashboard.php');
    exit;
}

$statusFilter = $_GET['status'] ?? 'all';
$severityFilter = $_GET['severity'] ?? 'all';
$searchTerm = $_GET['q'] ?? '';

$statusOptions = ['all', 'pending', 'under_review', 'investigating', 'resolved', 'closed', 'disputed'];
$severityOptions = ['all', 'low', 'medium', 'high', 'critical'];

if (!in_array($statusFilter, $statusOptions, true)) $statusFilter = 'all';
if (!in_array($severityFilter, $severityOptions, true)) $severityFilter = 'all';

$tableSql = "SELECT ir.*, u.email, u.display_name
            FROM incident_reports ir
            LEFT JOIN users u ON u.id = ir.user_id
            WHERE 1=1";
$tableParams = [];

if ($statusFilter !== 'all') {
    $tableSql .= " AND ir.status = ?";
    $tableParams[] = $statusFilter;
}

if ($severityFilter !== 'all') {
    $tableSql .= " AND ir.severity = ?";
    $tableParams[] = $severityFilter;
}

if ($searchTerm !== '') {
    // Use FULLTEXT search index (ft_report_search)
    // Mode: BOOLEAN MODE allows +required -exclude "exact phrase" operators
    $tableSql .= " AND MATCH(ir.title, ir.description, ir.location_name) AGAINST(? IN BOOLEAN MODE)";
    $tableParams[] = $searchTerm;
}

$tableSql .= " ORDER BY ir.reported_date DESC";
$tableData = $database->fetchAll($tableSql, $tableParams);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>All Reports - Admin Panel</title>
    <link rel="stylesheet" href="admin-dashboard.css">
    <style>
        body { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); }
        .container { max-width: 1400px; margin: 40px auto; padding: 0 20px; }
        .back-link { display: inline-block; margin-bottom: 20px; color: white; text-decoration: none; padding: 8px 16px; background: rgba(255,255,255,0.2); border-radius: 8px; }
    </style>
</head>
<body class="admin-body">
    <div class="container">
        <a href="admin_dashboard.php" class="back-link">← Back to Dashboard</a>
        <section class="panel panel--wide" style="max-height: none;">
            <header>
                <h2>All Incident Reports (<?= count($tableData); ?>)</h2>
            </header>
            <div class="table-wrap">
                <table class="data-table">
                    <thead>
                    <tr>
                        <th>ID</th>
                        <th>Title</th>
                        <th>Citizen</th>
                        <th>Category</th>
                        <th>Severity</th>
                        <th>Status</th>
                        <th>Location</th>
                        <th>Reported</th>
                        <th>Action</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($tableData as $row): ?>
                        <tr>
                            <td>#<?= (int)$row['id']; ?></td>
                            <td><strong><?= htmlspecialchars($row['title'], ENT_QUOTES, 'UTF-8'); ?></strong></td>
                            <td><?= htmlspecialchars($row['display_name'] ?: $row['email'] ?: 'Anonymous', ENT_QUOTES, 'UTF-8'); ?></td>
                            <td><?= htmlspecialchars(ucwords($row['category']), ENT_QUOTES, 'UTF-8'); ?></td>
                            <td><span class="tag severity-<?= htmlspecialchars($row['severity']); ?>"><?= ucwords($row['severity']); ?></span></td>
                            <td><span class="tag status-<?= htmlspecialchars($row['status']); ?>"><?= ucwords(str_replace('_', ' ', $row['status'])); ?></span></td>
                            <td><?= htmlspecialchars($row['location_name'] ?? '—', ENT_QUOTES, 'UTF-8'); ?></td>
                            <td><?= date('d M Y H:i', strtotime($row['reported_date'])); ?></td>
                            <td><a class="table-link" href="view_report.php?id=<?= (int)$row['id']; ?>" style="padding: 6px 12px; background: #4f46e5; color: white; border-radius: 6px; font-size: 0.8rem; display: inline-block;">View</a></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>
    </div>
</body>
</html>

