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
$statusFilter = $_GET['status'] ?? '';
$filters = [];
if ($statusFilter) {
    $filters['status'] = $statusFilter;
}

// Get all user panic alerts
$alerts = $models->getUserPanicAlerts($userId, $filters);

// Calculate statistics
$totalAlerts = count($alerts);
$activeAlerts = count(array_filter($alerts, fn($a) => $a['status'] === 'active'));
$resolvedAlerts = count(array_filter($alerts, fn($a) => $a['status'] === 'resolved'));
$falseAlarms = count(array_filter($alerts, fn($a) => $a['status'] === 'false_alarm'));

// Message handling
$message = '';
$error = '';
if (isset($_GET['success'])) {
    $message = 'Status updated successfully!';
}
if (isset($_GET['error'])) {
    $error = 'Failed to update status.';
}
?>
<!DOCTYPE html>
<html lang="<?= $lang ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Emergency Alerts - SafeSpace Portal</title>

    <link rel="icon" type="image/svg+xml" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><text y='.9em' font-size='90'>🛡️</text></svg>">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&family=Poppins:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/lucide/0.263.1/lucide.min.css">
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="design-system.css">
    <link rel="stylesheet" href="dashboard-styles.css">
</head>
<body class="bg-gradient-to-br from-slate-900 via-red-900 to-slate-900 min-h-screen">
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
                    <a href="panic_button.php" class="text-white/70 hover:text-white transition-colors duration-200">Panic Button</a>
                    <a href="emergency_contacts.php" class="text-white/70 hover:text-white transition-colors duration-200">Emergency Contacts</a>
                </nav>
                <div class="flex items-center space-x-4">
                    <a href="panic_button.php" class="btn btn-ghost">
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
                    <div class="w-20 h-20 bg-gradient-to-r from-red-500 via-rose-500 to-pink-500 rounded-3xl flex items-center justify-center mx-auto mb-6 shadow-2xl ring-4 ring-red-500/20">
                        <i data-lucide="alert-triangle" class="w-10 h-10 text-white drop-shadow-lg"></i>
                    </div>
                    <h1 class="heading-1 mb-4 text-white">My Emergency Alerts</h1>
                    <p class="body-large max-w-2xl mx-auto text-white/80 leading-relaxed">
                        View and manage all your emergency alerts and their status.
                    </p>
                </div>
            </section>

            <?php if ($message): ?>
                <div class="mb-6 card card-glass border-l-4 border-green-500">
                    <div class="card-body">
                        <div class="flex items-center">
                            <i data-lucide="check-circle" class="w-5 h-5 text-green-500 mr-3"></i>
                            <p class="text-green-300"><?= htmlspecialchars($message) ?></p>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <?php if ($error): ?>
                <div class="mb-6 card card-glass border-l-4 border-red-500">
                    <div class="card-body">
                        <div class="flex items-center">
                            <i data-lucide="alert-circle" class="w-5 h-5 text-red-500 mr-3"></i>
                            <p class="text-red-300"><?= htmlspecialchars($error) ?></p>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Statistics -->
            <div class="grid grid-cols-1 md:grid-cols-4 gap-4 mb-8">
                <div class="card card-glass p-6">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-white/60 text-sm mb-1">Total Alerts</p>
                            <p class="heading-2 text-white"><?= $totalAlerts ?></p>
                        </div>
                        <div class="w-12 h-12 bg-gradient-to-r from-red-500 to-rose-500 rounded-lg flex items-center justify-center">
                            <i data-lucide="alert-triangle" class="w-6 h-6 text-white"></i>
                        </div>
                    </div>
                </div>
                <div class="card card-glass p-6">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-white/60 text-sm mb-1">Active</p>
                            <p class="heading-2 text-white"><?= $activeAlerts ?></p>
                        </div>
                        <div class="w-12 h-12 bg-gradient-to-r from-yellow-500 to-orange-500 rounded-lg flex items-center justify-center">
                            <i data-lucide="clock" class="w-6 h-6 text-white"></i>
                        </div>
                    </div>
                </div>
                <div class="card card-glass p-6">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-white/60 text-sm mb-1">Resolved</p>
                            <p class="heading-2 text-white"><?= $resolvedAlerts ?></p>
                        </div>
                        <div class="w-12 h-12 bg-gradient-to-r from-green-500 to-emerald-500 rounded-lg flex items-center justify-center">
                            <i data-lucide="check-circle" class="w-6 h-6 text-white"></i>
                        </div>
                    </div>
                </div>
                <div class="card card-glass p-6">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-white/60 text-sm mb-1">False Alarms</p>
                            <p class="heading-2 text-white"><?= $falseAlarms ?></p>
                        </div>
                        <div class="w-12 h-12 bg-gradient-to-r from-gray-500 to-slate-500 rounded-lg flex items-center justify-center">
                            <i data-lucide="x-circle" class="w-6 h-6 text-white"></i>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Filters -->
            <section class="mb-8">
                <div class="card card-glass p-6">
                    <form method="GET" class="flex items-center space-x-4">
                        <label class="form-label text-white">Filter by Status:</label>
                        <select name="status" class="form-input" onchange="this.form.submit()">
                            <option value="">All Statuses</option>
                            <option value="active" <?= $statusFilter === 'active' ? 'selected' : '' ?>>Active</option>
                            <option value="acknowledged" <?= $statusFilter === 'acknowledged' ? 'selected' : '' ?>>Acknowledged</option>
                            <option value="resolved" <?= $statusFilter === 'resolved' ? 'selected' : '' ?>>Resolved</option>
                            <option value="false_alarm" <?= $statusFilter === 'false_alarm' ? 'selected' : '' ?>>False Alarm</option>
                        </select>
                        <?php if ($statusFilter): ?>
                            <a href="my_emergencies.php" class="btn btn-outline btn-sm">
                                <i data-lucide="x" class="w-4 h-4 mr-2"></i>
                                Clear Filter
                            </a>
                        <?php endif; ?>
                    </form>
                </div>
            </section>

            <!-- Alerts List -->
            <section>
                <?php if (empty($alerts)): ?>
                    <div class="card card-glass text-center p-12">
                        <i data-lucide="alert-triangle" class="w-16 h-16 text-white/30 mx-auto mb-4"></i>
                        <h3 class="heading-3 text-white mb-3">No Emergency Alerts</h3>
                        <p class="text-white/60 mb-6">You haven't triggered any emergency alerts yet.</p>
                        <a href="panic_button.php" class="btn btn-primary">
                            <i data-lucide="alert-triangle" class="w-4 h-4 mr-2"></i>
                            Go to Panic Button
                        </a>
                    </div>
                <?php else: ?>
                    <div class="space-y-4">
                        <?php foreach ($alerts as $alert):
                            $statusColors = [
                                'active' => 'border-red-500/50 bg-red-500/10',
                                'acknowledged' => 'border-yellow-500/50 bg-yellow-500/10',
                                'resolved' => 'border-green-500/50 bg-green-500/10',
                                'false_alarm' => 'border-gray-500/50 bg-gray-500/10'
                            ];
                            $statusColor = $statusColors[$alert['status']] ?? 'border-gray-500/50 bg-gray-500/10';
                        ?>
                            <div class="card card-glass border-l-4 <?= $statusColor ?>">
                                <div class="card-body p-6">
                                    <div class="flex items-start justify-between mb-4">
                                        <div class="flex-1">
                                            <div class="flex items-center space-x-3 mb-2">
                                                <h3 class="heading-4 text-white">Emergency Alert #<?= $alert['id'] ?></h3>
                                                <span class="px-2 py-1 rounded-full text-xs font-semibold capitalize <?= $statusColor ?>">
                                                    <?= str_replace('_', ' ', $alert['status']) ?>
                                                </span>
                                            </div>
                                            <div class="space-y-2 text-sm text-white/70 mb-3">
                                                <p>
                                                    <i data-lucide="clock" class="w-4 h-4 inline mr-1"></i>
                                                    Triggered: <?= date('M j, Y g:i A', strtotime($alert['triggered_at'])) ?>
                                                </p>
                                                <p>
                                                    <i data-lucide="mouse-pointer-click" class="w-4 h-4 inline mr-1"></i>
                                                    Method: <?= ucfirst(str_replace('_', ' ', $alert['trigger_method'])) ?>
                                                </p>
                                                <?php if ($alert['location_name']): ?>
                                                    <p>
                                                        <i data-lucide="map-pin" class="w-4 h-4 inline mr-1"></i>
                                                        Location: <?= htmlspecialchars($alert['location_name']) ?>
                                                    </p>
                                                <?php endif; ?>
                                                <?php if ($alert['latitude'] && $alert['longitude']): ?>
                                                    <p>
                                                        <a href="https://maps.google.com/?q=<?= $alert['latitude'] ?>,<?= $alert['longitude'] ?>"
                                                           target="_blank" class="text-blue-400 hover:text-blue-300">
                                                            <i data-lucide="map" class="w-4 h-4 inline mr-1"></i>
                                                            View on Map
                                                        </a>
                                                    </p>
                                                <?php endif; ?>
                                                <?php if ($alert['message']): ?>
                                                    <p class="mt-2 p-3 bg-white/5 rounded-lg">
                                                        <?= nl2br(htmlspecialchars($alert['message'])) ?>
                                                    </p>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </div>

                                    <div class="flex items-center space-x-4 mb-4 pt-4 border-t border-white/10 text-sm">
                                        <span class="text-white/60">
                                            <i data-lucide="users" class="w-4 h-4 inline mr-1"></i>
                                            <?= $alert['emergency_contacts_notified'] ?> contacts notified
                                        </span>
                                        <?php if ($alert['police_notified']): ?>
                                            <span class="text-red-400">
                                                <i data-lucide="shield" class="w-4 h-4 inline mr-1"></i>
                                                Police
                                            </span>
                                        <?php endif; ?>
                                        <?php if ($alert['ambulance_notified']): ?>
                                            <span class="text-blue-400">
                                                <i data-lucide="heart" class="w-4 h-4 inline mr-1"></i>
                                                Ambulance
                                            </span>
                                        <?php endif; ?>
                                        <?php if ($alert['fire_service_notified']): ?>
                                            <span class="text-orange-400">
                                                <i data-lucide="flame" class="w-4 h-4 inline mr-1"></i>
                                                Fire Service
                                            </span>
                                        <?php endif; ?>
                                        <?php if ($alert['response_time_seconds']): ?>
                                            <span class="text-white/60">
                                                <i data-lucide="timer" class="w-4 h-4 inline mr-1"></i>
                                                Response: <?= $alert['response_time_seconds'] ?>s
                                            </span>
                                        <?php endif; ?>
                                    </div>

                                    <?php if ($alert['status'] === 'active'): ?>
                                        <div class="flex items-center space-x-2 pt-4 border-t border-white/10">
                                            <form method="POST" action="emergency_handler.php" class="flex-1" onsubmit="return confirm('Mark this alert as resolved?')">
                                                <input type="hidden" name="action" value="update_status">
                                                <input type="hidden" name="alert_id" value="<?= $alert['id'] ?>">
                                                <input type="hidden" name="status" value="resolved">
                                                <button type="submit" class="btn btn-primary w-full">
                                                    <i data-lucide="check-circle" class="w-4 h-4 mr-2"></i>
                                                    Mark as Resolved
                                                </button>
                                            </form>
                                            <form method="POST" action="emergency_handler.php" class="flex-1" onsubmit="return confirm('Mark this as a false alarm?')">
                                                <input type="hidden" name="action" value="update_status">
                                                <input type="hidden" name="alert_id" value="<?= $alert['id'] ?>">
                                                <input type="hidden" name="status" value="false_alarm">
                                                <button type="submit" class="btn btn-outline w-full">
                                                    <i data-lucide="x-circle" class="w-4 h-4 mr-2"></i>
                                                    False Alarm
                                                </button>
                                            </form>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
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

