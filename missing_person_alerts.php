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

// Get filters
$filters = [
    'district' => $_GET['district'] ?? '',
    'limit' => 50
];

// Get missing person alerts
$alerts = $models->getMissingPersonAlerts($filters);

// Get unique districts
$allAlerts = $models->getMissingPersonAlerts([]);
$districts = array_unique(array_filter(array_column($allAlerts, 'district')));
sort($districts);
?>
<!DOCTYPE html>
<html lang="<?= $lang ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Missing Person Alerts - SafeSpace Portal</title>

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
                    <a href="community_groups.php" class="text-white/70 hover:text-white transition-colors duration-200">Community Groups</a>
                    <a href="missing_person_alerts.php" class="text-white font-medium">Missing Persons</a>
                </nav>
                <div class="flex items-center space-x-4">
                    <a href="community_groups.php" class="btn btn-ghost">
                        <i data-lucide="arrow-left" class="w-4 h-4"></i>
                        Back
                    </a>
                </div>
            </div>
        </div>
    </header>

    <main class="pt-20 pb-8">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <section class="mb-8 animate-fade-in">
                <div class="text-center">
                    <div class="w-20 h-20 bg-gradient-to-r from-orange-500 via-red-500 to-pink-500 rounded-3xl flex items-center justify-center mx-auto mb-6 shadow-2xl ring-4 ring-orange-500/20">
                        <i data-lucide="user-search" class="w-10 h-10 text-white drop-shadow-lg"></i>
                    </div>
                    <h1 class="heading-1 mb-4 text-white">Missing Person Alerts</h1>
                    <p class="body-large max-w-2xl mx-auto text-white/80 leading-relaxed">
                        View and share missing person alerts from community groups. Help reunite families by staying informed.
                    </p>
                </div>
            </section>

            <!-- Search and Filters -->
            <section class="mb-8">
                <div class="card card-glass p-6">
                    <form method="GET" action="missing_person_alerts.php" class="space-y-4">
                        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                            <div>
                                <label class="form-label text-white mb-2">District</label>
                                <select name="district" class="form-input w-full">
                                    <option value="">All Districts</option>
                                    <?php foreach ($districts as $district): ?>
                                        <option value="<?= htmlspecialchars($district) ?>" <?= $filters['district'] === $district ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($district) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>

                        <div class="flex items-center space-x-4">
                            <button type="submit" class="btn btn-primary">
                                <i data-lucide="search" class="w-4 h-4 mr-2"></i>
                                Search
                            </button>
                            <a href="missing_person_alerts.php" class="btn btn-outline">
                                <i data-lucide="refresh-cw" class="w-4 h-4 mr-2"></i>
                                Reset
                            </a>
                        </div>
                    </form>
                </div>
            </section>

            <!-- Alerts List -->
            <section>
                <div class="mb-6 flex items-center justify-between">
                    <h2 class="heading-2 text-white">Active Missing Person Alerts (<?= count($alerts) ?>)</h2>
                </div>

                <?php if (empty($alerts)): ?>
                    <div class="card card-glass text-center p-12">
                        <div class="w-20 h-20 bg-gradient-to-r from-gray-500 to-gray-600 rounded-full flex items-center justify-center mx-auto mb-6">
                            <i data-lucide="user-x" class="w-10 h-10 text-white"></i>
                        </div>
                        <h3 class="heading-3 text-white mb-3">No Missing Person Alerts</h3>
                        <p class="text-white/60 mb-6">There are currently no active missing person alerts.</p>
                        <a href="community_groups.php" class="btn btn-primary">
                            <i data-lucide="users" class="w-4 h-4 mr-2"></i>
                            Join Community Groups
                        </a>
                    </div>
                <?php else: ?>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <?php foreach ($alerts as $index => $alert): ?>
                            <div class="card card-glass border-l-4 border-red-500 animate-slide-in" style="animation-delay: <?= $index * 0.1 ?>s;">
                                <div class="card-body p-6">
                                    <div class="flex items-start justify-between mb-4">
                                        <div class="flex-1">
                                            <div class="flex items-center space-x-3 mb-2">
                                                <i data-lucide="user-search" class="w-6 h-6 text-red-400"></i>
                                                <h3 class="heading-4 text-white"><?= htmlspecialchars($alert['title']) ?></h3>
                                                <span class="px-2 py-1 rounded-full text-xs font-semibold bg-red-500/20 text-red-300 border border-red-500/30">
                                                    Missing Person
                                                </span>
                                            </div>
                                            <p class="text-sm text-white/60 mb-3">Posted by <?= htmlspecialchars($alert['poster_name'] ?? 'Unknown') ?> • <?= htmlspecialchars($alert['group_name'] ?? 'Unknown Group') ?></p>
                                            <?php if ($alert['location_details']): ?>
                                                <p class="text-sm text-white/70 mb-3 flex items-center">
                                                    <i data-lucide="map-pin" class="w-4 h-4 mr-2 text-blue-400"></i>
                                                    Last seen: <?= htmlspecialchars($alert['location_details']) ?>
                                                </p>
                                            <?php endif; ?>
                                            <p class="text-white/80 mb-4"><?= nl2br(htmlspecialchars($alert['message'])) ?></p>
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
                                                <?= $alert['acknowledgments'] ?> shared
                                            </span>
                                        </div>
                                        <div class="flex items-center space-x-2">
                                            <a href="group_detail.php?id=<?= $alert['group_id'] ?>" class="btn btn-sm btn-outline">
                                                <i data-lucide="arrow-right" class="w-4 h-4 mr-2"></i>
                                                View Group
                                            </a>
                                        </div>
                                    </div>

                                    <?php if ($alert['expires_at']): ?>
                                        <div class="mt-3 text-xs text-white/50">
                                            <i data-lucide="clock" class="w-3 h-3 inline mr-1"></i>
                                            Expires: <?= date('M j, Y h:i A', strtotime($alert['expires_at'])) ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </section>

            <!-- Important Notice -->
            <section class="mt-8">
                <div class="card card-glass border-l-4 border-blue-500 p-6">
                    <div class="flex items-start">
                        <i data-lucide="info" class="w-6 h-6 text-blue-400 mr-4 mt-1"></i>
                        <div>
                            <h4 class="font-semibold text-blue-300 mb-2">Important Information</h4>
                            <ul class="text-blue-200 text-sm space-y-1">
                                <li>• If you have information about a missing person, contact the group or local authorities immediately</li>
                                <li>• Share these alerts responsibly on social media to help spread awareness</li>
                                <li>• Report false or outdated alerts to group administrators</li>
                                <li>• Missing person alerts are time-sensitive - act quickly if you have information</li>
                            </ul>
                        </div>
                    </div>
                </div>
            </section>
        </div>
    </main>

    <script src="https://unpkg.com/lucide@latest/dist/umd/lucide.js"></script>
    <script src="dashboard-enhanced.js"></script>
    <script>
        lucide.createIcons();
    </script>
</body>
</html>

