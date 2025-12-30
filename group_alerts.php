<?php
session_start();
$lang = $_SESSION['lang'] ?? 'en';
$lang_file = $lang === 'bn' ? 'lang_bn.php' : 'lang_en.php';
$L = include($lang_file);

require_once 'includes/Database.php';

$database = new Database();
$models = new SafeSpaceModels($database);

$userId = $_SESSION['user_id'] ?? null;

if (!$userId) {
    header('Location: login.html');
    exit;
}

$groupId = $_GET['id'] ?? null;

if (!$groupId) {
    header('Location: community_groups.php');
    exit;
}

// Get group details
$group = $models->getNeighborhoodGroupById($groupId);

if (!$group) {
    header('Location: community_groups.php?error=Group not found');
    exit;
}

// Check if user is member
$isMember = $models->isGroupMember($groupId, $userId);

// Get group alerts
$alertFilters = [
    'status' => $_GET['alert_status'] ?? 'active',
    'alert_type' => $_GET['alert_type'] ?? '',
    'severity' => $_GET['severity'] ?? '',
    'limit' => 20
];
$alerts = $models->getGroupAlerts($groupId, $alertFilters);

// Handle form submissions (like Acknowledge)
$message = '';
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'acknowledge_alert') {
        $alertId = intval($_POST['alert_id'] ?? 0);
        if ($alertId && $isMember) {
            $models->acknowledgeAlert($alertId, $userId);
            $message = 'Alert acknowledged!';
            // Refresh alerts
            $alerts = $models->getGroupAlerts($groupId, $alertFilters);
        } else {
            $error = 'Could not acknowledge alert.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="<?= $lang ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Alerts for <?= htmlspecialchars($group['group_name']) ?> - SafeSpace Portal</title>

    <link rel="icon" type="image/svg+xml" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><text y='.9em' font-size='90'>🛡️</text></svg>">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&family=Poppins:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/lucide/0.263.1/lucide.min.css">
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="design-system.css">
    <link rel="stylesheet" href="dashboard-styles.css">
</head>
<body class="bg-gradient-to-br from-slate-900 via-purple-900 to-slate-900 min-h-screen">
    <header class="fixed top-0 left-0 right-0 z-50 bg-white/10 backdrop-blur-xl border-b border-white/20">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="flex items-center justify-between h-16">
                <div class="flex items-center space-x-4">
                    <a href="dashboard.php" class="flex items-center space-x-2">
                        <div class="w-8 h-8 bg-gradient-to-r from-primary-500 to-secondary-500 rounded-lg flex items-center justify-center">
                            <i data-lucide="shield" class="w-5 h-5 text-white"></i>
                        </div>
                        <span class="text-xl font-bold text-white">SafeSpace</span>
                    </a>
                </div>
                <nav class="hidden md:flex items-center space-x-6">
                    <a href="dashboard.php" class="text-white/70 hover:text-white transition-colors duration-200">Dashboard</a>
                    <a href="community_groups.php" class="text-white font-medium">Community Groups</a>
                </nav>
                <div class="flex items-center space-x-4">
                    <a href="group_detail.php?id=<?= $groupId ?>" class="btn btn-ghost">
                        <i data-lucide="arrow-left" class="w-4 h-4"></i>
                        Back to Group
                    </a>
                </div>
            </div>
        </div>
    </header>

    <main class="pt-20 pb-8">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">

            <?php if ($message): ?>
                <div class="mb-4 card card-glass border-l-4 border-green-500">
                    <div class="card-body">
                        <div class="flex items-center">
                            <i data-lucide="check-circle" class="w-5 h-5 text-green-500 mr-3"></i>
                            <p class="text-green-300"><?= htmlspecialchars($message) ?></p>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <?php if ($error): ?>
                <div class="mb-4 card card-glass border-l-4 border-red-500">
                    <div class="card-body">
                        <div class="flex items-center">
                            <i data-lucide="alert-circle" class="w-5 h-5 text-red-500 mr-3"></i>
                            <p class="text-red-300"><?= htmlspecialchars($error) ?></p>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <div class="space-y-4">
                <div class="flex items-center justify-between mb-4">
                    <h2 class="heading-2 text-white">Group Alerts (<?= count($alerts) ?>)</h2>
                    <?php if ($group['status'] === 'active'): ?>
                        <div class="flex items-center space-x-2">
                            <select id="alertFilter" class="form-input text-sm" onchange="filterAlerts()">
                                <option value="active" <?= $alertFilters['status'] === 'active' ? 'selected' : '' ?>>Active</option>
                                <option value="resolved" <?= $alertFilters['status'] === 'resolved' ? 'selected' : '' ?>>Resolved</option>
                                <option value="all" <?= $alertFilters['status'] === 'all' ? 'selected' : '' ?>>All</option>
                            </select>
                        </div>
                    <?php endif; ?>
                </div>

                <?php if (empty($alerts)): ?>
                    <div class="card card-glass text-center p-12">
                        <i data-lucide="bell-off" class="w-16 h-16 text-white/30 mx-auto mb-4"></i>
                        <p class="text-white/60">No alerts posted yet.</p>
                    </div>
                <?php else: ?>
                    <div class="space-y-4">
                        <?php foreach ($alerts as $alert):
                            $severityColors = [
                                'critical' => 'border-red-500/50 bg-red-500/10',
                                'high' => 'border-orange-500/50 bg-orange-500/10',
                                'medium' => 'border-yellow-500/50 bg-yellow-500/10',
                                'low' => 'border-blue-500/50 bg-blue-500/10'
                            ];
                            $severityColor = $severityColors[$alert['severity']] ?? 'border-gray-500/50 bg-gray-500/10';

                            $typeIcons = [
                                'missing_person' => 'user-search',
                                'emergency' => 'alert-triangle',
                                'safety_warning' => 'shield-alert',
                                'suspicious_activity' => 'eye',
                                'general' => 'bell'
                            ];
                            $typeIcon = $typeIcons[$alert['alert_type']] ?? 'bell';

                            // Get media for this alert
                            $alertMedia = $models->getAlertMedia($alert['id']);
                        ?>
                            <div class="card card-glass border-l-4 <?= $severityColor ?>">
                                <div class="card-body p-6">
                                    <div class="flex items-start justify-between mb-4">
                                        <div class="flex-1">
                                            <div class="flex items-center space-x-3 mb-2">
                                                <i data-lucide="<?= $typeIcon ?>" class="w-5 h-5 text-orange-400"></i>
                                                <h3 class="heading-4 text-white"><?= htmlspecialchars($alert['title']) ?></h3>
                                                <span class="px-2 py-1 rounded-full text-xs font-semibold <?= $severityColor ?>">
                                                    <?= ucfirst($alert['severity']) ?>
                                                </span>
                                                <?php if ($alert['is_verified']): ?>
                                                    <i data-lucide="badge-check" class="w-4 h-4 text-blue-400" title="Verified"></i>
                                                <?php endif; ?>
                                            </div>
                                            <p class="text-sm text-white/60 mb-2">Posted by <?= htmlspecialchars($alert['poster_name'] ?? 'Unknown') ?> • <?= date('M j, Y h:i A', strtotime($alert['created_at'])) ?></p>
                                            <?php if ($alert['location_details']): ?>
                                                <p class="text-sm text-white/70 mb-3 flex items-center">
                                                    <i data-lucide="map-pin" class="w-4 h-4 mr-2 text-blue-400"></i>
                                                    <?= htmlspecialchars($alert['location_details']) ?>
                                                </p>
                                            <?php endif; ?>
                                            <p class="text-white/80 mb-4"><?= nl2br(htmlspecialchars($alert['message'])) ?></p>

                                            <?php if (!empty($alertMedia)): ?>
                                                <div class="mb-4 p-4 bg-white/5 rounded-lg border border-white/10">
                                                    <p class="text-sm font-semibold text-white/70 mb-3 flex items-center">
                                                        <i data-lucide="image" class="w-4 h-4 mr-2 text-purple-400"></i>
                                                        Attached Media (<?= count($alertMedia) ?>)
                                                    </p>
                                                    <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-3">
                                                        <?php foreach ($alertMedia as $media): ?>
                                                            <div class="relative group">
                                                                <?php if ($media['file_type'] === 'image'): ?>
                                                                    <a href="<?= htmlspecialchars($media['file_path']) ?>" target="_blank" class="block">
                                                                        <img src="<?= htmlspecialchars($media['thumbnail_path'] ?? $media['file_path']) ?>"
                                                                             alt="<?= htmlspecialchars($media['file_name']) ?>"
                                                                             class="w-full h-24 object-cover rounded-lg border border-white/10 hover:border-purple-400/50 transition-colors">
                                                                    </a>
                                                                <?php elseif ($media['file_type'] === 'video'): ?>
                                                                    <a href="<?= htmlspecialchars($media['file_path']) ?>" target="_blank" class="block relative">
                                                                        <div class="w-full h-24 bg-gradient-to-br from-purple-500/20 to-pink-500/20 rounded-lg border border-white/10 flex items-center justify-center hover:border-purple-400/50 transition-colors">
                                                                            <i data-lucide="play" class="w-8 h-8 text-white/70"></i>
                                                                        </div>
                                                                    </a>
                                                                <?php else: ?>
                                                                    <a href="group_media_handler.php?action=download&id=<?= $media['id'] ?>" class="block">
                                                                        <div class="w-full h-24 bg-gradient-to-br from-blue-500/20 to-cyan-500/20 rounded-lg border border-white/10 flex items-center justify-center hover:border-blue-400/50 transition-colors">
                                                                            <i data-lucide="file-text" class="w-8 h-8 text-white/70"></i>
                                                                        </div>
                                                                    </a>
                                                                <?php endif; ?>
                                                                <p class="text-xs text-white/60 mt-1 truncate" title="<?= htmlspecialchars($media['file_name']) ?>">
                                                                    <?= htmlspecialchars($media['file_name']) ?>
                                                                </p>
                                                            </div>
                                                        <?php endforeach; ?>
                                                    </div>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    </div>

                                    <div class="flex items-center justify-between pt-4 border-t border-white/10">
                                        <div class="flex items-center space-x-4 text-sm text-white/60">
                                            <span class="flex items-center">
                                                <i data-lucide="eye" class="w-4 h-4 mr-1"></i>
                                                <?= $alert['views_count'] ?> views
                                            </span>
                                            <span class="flex items-center">
                                                <i data-lucide="check-circle" class="w-4 h-4 mr-1"></i>
                                                <?= $alert['acknowledgments'] ?> acknowledged
                                            </span>
                                        </div>
                                        <?php if ($isMember): ?>
                                            <form method="POST" class="inline">
                                                <input type="hidden" name="action" value="acknowledge_alert">
                                                <input type="hidden" name="alert_id" value="<?= $alert['id'] ?>">
                                                <button type="submit" class="btn btn-sm btn-outline">
                                                    <i data-lucide="check" class="w-4 h-4 mr-2"></i>
                                                    Acknowledge
                                                </button>
                                            </form>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </main>

    <script src="https://unpkg.com/lucide@latest/dist/umd/lucide.js"></script>
    <script src="dashboard-enhanced.js"></script>
    <script>
        lucide.createIcons();

        function filterAlerts() {
            const status = document.getElementById('alertFilter').value;
            const url = new URL(window.location);
            url.searchParams.set('alert_status', status);
            window.location.href = url.toString();
        }

        // We include the other JS functions from group_detail.php
        // in case dashboard-enhanced.js needs them.
        function filterMedia() {}
        function incrementMediaView(mediaId) {}
        function removeFilePreview(button, inputId) {}
        function removeStandaloneFilePreview(index) {}
        function toggleDetails() {}
    </script>
</body>
</html>