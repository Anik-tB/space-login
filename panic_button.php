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

// Get user's emergency contacts
$emergencyContacts = $models->getUserEmergencyContacts($userId, ['is_active' => 1]);

// Get recent panic alerts
$recentAlerts = $models->getUserPanicAlerts($userId, ['limit' => 5]);

// Get active walk sessions
$activeWalkSessions = $database->fetchAll(
    "SELECT * FROM walk_sessions WHERE user_id = ? AND status = 'active' ORDER BY start_time DESC LIMIT 1",
    [$userId]
);

// Message handling
$message = '';
$error = '';
if (isset($_GET['success'])) {
    $message = 'Emergency alert sent successfully!';
}
if (isset($_GET['error'])) {
    $error = 'Failed to send emergency alert. Please try again.';
}
?>
<!DOCTYPE html>
<html lang="<?= $lang ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Panic Button & Emergency SOS - SafeSpace Portal</title>

    <link rel="icon" type="image/svg+xml" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><text y='.9em' font-size='90'>🛡️</text></svg>">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&family=Poppins:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/lucide/0.263.1/lucide.min.css">
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="design-system.css">
    <link rel="stylesheet" href="dashboard-styles.css">
    <style>
        .panic-button {
            width: 200px;
            height: 200px;
            border-radius: 50%;
            background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%);
            border: 8px solid rgba(255, 255, 255, 0.3);
            box-shadow: 0 0 0 0 rgba(239, 68, 68, 0.7);
            animation: pulse 2s infinite;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.3s ease;
            position: relative;
        }

        .panic-button:hover {
            transform: scale(1.01);
            box-shadow: 0 0 0 20px rgba(239, 68, 68, 0);
        }

        .panic-button:active {
            transform: scale(0.95);
        }

        .panic-button.active {
            animation: pulse-active 1s infinite;
        }

        @keyframes pulse {
            0% {
                box-shadow: 0 0 0 0 rgba(239, 68, 68, 0.7);
            }
            70% {
                box-shadow: 0 0 0 20px rgba(239, 68, 68, 0);
            }
            100% {
                box-shadow: 0 0 0 0 rgba(239, 68, 68, 0);
            }
        }

        @keyframes pulse-active {
            0% {
                box-shadow: 0 0 0 0 rgba(239, 68, 68, 0.9);
            }
            50% {
                box-shadow: 0 0 0 30px rgba(239, 68, 68, 0);
            }
            100% {
                box-shadow: 0 0 0 0 rgba(239, 68, 68, 0);
            }
        }

        /* Modal Styles */
        #confirmModal {
            animation: fadeIn 0.2s ease-out;
        }

        #confirmModal .card {
            animation: slideUp 0.3s ease-out;
        }

        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }

        @keyframes slideUp {
            from {
                opacity: 0;
                transform: translateY(2px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        /* Custom Scrollbar for Modal */
        #confirmModal .card::-webkit-scrollbar {
            width: 6px;
        }

        #confirmModal .card::-webkit-scrollbar-track {
            background: rgba(255, 255, 255, 0.05);
            border-radius: 3px;
        }

        #confirmModal .card::-webkit-scrollbar-thumb {
            background: rgba(255, 255, 255, 0.2);
            border-radius: 3px;
        }

        #confirmModal .card::-webkit-scrollbar-thumb:hover {
            background: rgba(255, 255, 255, 0.3);
        }

        .emergency-service-btn {
            transition: all 0.3s ease;
        }

        .emergency-service-btn:hover {
            transform: translateY(-0.3px);
            box-shadow: 0 10px 20px rgba(0, 0, 0, 0.2);
        }
    </style>
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
                    <a href="my_emergencies.php" class="text-white/70 hover:text-white transition-colors duration-200">My Emergencies</a>
                    <a href="emergency_contacts.php" class="text-white/70 hover:text-white transition-colors duration-200">Emergency Contacts</a>
                </nav>
                <div class="flex items-center space-x-4">
                    <a href="dashboard.php" class="btn btn-ghost">
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
                    <h1 class="heading-1 mb-4 text-white">Emergency SOS</h1>
                    <p class="body-large max-w-2xl mx-auto text-white/80 leading-relaxed mb-6">
                        Press the panic button in case of emergency. Your location and alert will be sent to emergency contacts and services.
                    </p>

                    <!-- Why Use This Instead of Phone -->
                    <div class="max-w-4xl mx-auto mt-8">
                        <div class="card card-glass p-6 border-l-4 border-blue-500">
                            <div class="flex items-start space-x-4">
                                <div class="w-12 h-12 bg-blue-500/20 rounded-lg flex items-center justify-center flex-shrink-0">
                                    <i data-lucide="sparkles" class="w-6 h-6 text-blue-400"></i>
                                </div>
                                <div class="flex-1 text-left">
                                    <h3 class="heading-3 text-white mb-3">Why Use SafeSpace Panic Button Instead of Regular Phone?</h3>
                                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                        <div class="flex items-start space-x-3 p-3 bg-white/5 rounded-lg">
                                            <i data-lucide="users" class="w-5 h-5 text-green-400 flex-shrink-0 mt-0.5"></i>
                                            <div>
                                                <p class="text-white font-semibold text-sm mb-1">Notify Multiple Contacts Instantly</p>
                                                <p class="text-white/60 text-xs">Phone can only call one person. We notify ALL your emergency contacts simultaneously via SMS, call, email, and push notifications.</p>
                                            </div>
                                        </div>
                                        <div class="flex items-start space-x-3 p-3 bg-white/5 rounded-lg">
                                            <i data-lucide="map-pin" class="w-5 h-5 text-red-400 flex-shrink-0 mt-0.5"></i>
                                            <div>
                                                <p class="text-white font-semibold text-sm mb-1">Automatic Location Sharing</p>
                                                <p class="text-white/60 text-xs">Your exact GPS location is automatically sent with Google Maps link. No need to describe where you are.</p>
                                            </div>
                                        </div>
                                        <div class="flex items-start space-x-3 p-3 bg-white/5 rounded-lg">
                                            <i data-lucide="volume-x" class="w-5 h-5 text-purple-400 flex-shrink-0 mt-0.5"></i>
                                            <div>
                                                <p class="text-white font-semibold text-sm mb-1">Silent Mode Available</p>
                                                <p class="text-white/60 text-xs">Trigger alert silently without sound or vibration. Perfect when you can't make noise.</p>
                                            </div>
                                        </div>
                                        <div class="flex items-start space-x-3 p-3 bg-white/5 rounded-lg">
                                            <i data-lucide="file-text" class="w-5 h-5 text-orange-400 flex-shrink-0 mt-0.5"></i>
                                            <div>
                                                <p class="text-white font-semibold text-sm mb-1">Complete Incident Logging</p>
                                                <p class="text-white/60 text-xs">Every alert is logged with timestamp, location, and response time. Track patterns and improve safety.</p>
                                            </div>
                                        </div>
                                        <div class="flex items-start space-x-3 p-3 bg-white/5 rounded-lg">
                                            <i data-lucide="shield" class="w-5 h-5 text-cyan-400 flex-shrink-0 mt-0.5"></i>
                                            <div>
                                                <p class="text-white font-semibold text-sm mb-1">Nearby Safe Spaces</p>
                                                <p class="text-white/60 text-xs">Instantly shows closest verified safe locations and partner establishments near you.</p>
                                            </div>
                                        </div>
                                        <div class="flex items-start space-x-3 p-3 bg-white/5 rounded-lg">
                                            <i data-lucide="radio" class="w-5 h-5 text-pink-400 flex-shrink-0 mt-0.5"></i>
                                            <div>
                                                <p class="text-white font-semibold text-sm mb-1">Community Alerts</p>
                                                <p class="text-white/60 text-xs">Nearby SafeSpace users can see your alert and come to help. Community-powered safety network.</p>
                                            </div>
                                        </div>
                                        <div class="flex items-start space-x-3 p-3 bg-white/5 rounded-lg">
                                            <i data-lucide="mic" class="w-5 h-5 text-yellow-400 flex-shrink-0 mt-0.5"></i>
                                            <div>
                                                <p class="text-white font-semibold text-sm mb-1">Voice Activation</p>
                                                <p class="text-white/60 text-xs">Hands-free activation with voice commands like "Help" or "Emergency". Works even if phone is locked.</p>
                                            </div>
                                        </div>
                                        <div class="flex items-start space-x-3 p-3 bg-white/5 rounded-lg">
                                            <i data-lucide="activity" class="w-5 h-5 text-teal-400 flex-shrink-0 mt-0.5"></i>
                                            <div>
                                                <p class="text-white font-semibold text-sm mb-1">Auto-Trigger on Motion</p>
                                                <p class="text-white/60 text-xs">Detects if phone is dropped or thrown and automatically triggers alert. Works even if you can't press button.</p>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="mt-4 p-4 bg-gradient-to-r from-blue-500/20 to-purple-500/20 rounded-lg border border-blue-500/30">
                                        <p class="text-white text-sm">
                                            <i data-lucide="info" class="w-4 h-4 inline mr-2 text-blue-400"></i>
                                            <strong>Plus:</strong> Integration with Walk With Me, incident reporting, safety resources, and emergency service coordination - all in one platform.
                                        </p>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
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

            <!-- Active Walk Session Alert -->
            <?php if (!empty($activeWalkSessions)):
                $activeWalk = $activeWalkSessions[0];
                $trackingLink = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http") . "://" . $_SERVER['HTTP_HOST'] . dirname($_SERVER['PHP_SELF']) . "/track_walk.php?token=" . $activeWalk['session_token'];
            ?>
                <section class="mb-6">
                    <div class="card card-glass border-l-4 border-blue-500 animate-slide-up">
                        <div class="card-body p-6">
                            <div class="flex items-center justify-between">
                                <div class="flex items-center space-x-4">
                                    <div class="w-12 h-12 bg-blue-500/20 rounded-lg flex items-center justify-center">
                                        <i data-lucide="navigation" class="w-6 h-6 text-blue-400"></i>
                                    </div>
                                    <div>
                                        <h3 class="heading-4 text-white mb-1">Active Walk Session</h3>
                                        <p class="text-white/60 text-sm">
                                            Started <?= date('M j, Y H:i', strtotime($activeWalk['start_time'])) ?>
                                            <?php if ($activeWalk['destination']): ?>
                                                • To: <?= htmlspecialchars($activeWalk['destination']) ?>
                                            <?php endif; ?>
                                        </p>
                                        <p class="text-blue-400 text-xs mt-1">
                                            <i data-lucide="link" class="w-3 h-3 inline"></i>
                                            <a href="<?= htmlspecialchars($trackingLink) ?>" target="_blank" class="hover:underline">View Live Tracking</a>
                                        </p>
                                    </div>
                                </div>
                                <div class="flex items-center space-x-2">
                                    <a href="walk_with_me.php" class="btn btn-outline btn-sm">
                                        <i data-lucide="external-link" class="w-4 h-4 mr-2"></i>
                                        Open Walk
                                    </a>
                                </div>
                            </div>
                            <div class="mt-4 pt-4 border-t border-white/10">
                                <p class="text-white/70 text-sm">
                                    <i data-lucide="info" class="w-4 h-4 inline mr-2"></i>
                                    You have an active walk session. The SOS button in Walk With Me will create a panic alert linked to your walk.
                                </p>
                            </div>
                        </div>
                    </div>
                </section>
            <?php endif; ?>

            <!-- Panic Button -->
            <section class="mb-8">
                <div class="card card-glass p-8">
                    <div class="text-center">
                        <h2 class="heading-2 text-white mb-6">Emergency Panic Button</h2>
                        <p class="text-white/70 mb-8">Press and hold for 3 seconds to activate emergency alert</p>

                        <?php if (empty($activeWalkSessions)): ?>
                            <div class="mb-6 p-4 bg-blue-500/10 rounded-lg border border-blue-500/30 max-w-md mx-auto">
                                <p class="text-white/80 text-sm mb-2">
                                    <i data-lucide="navigation" class="w-4 h-4 inline mr-2"></i>
                                    <strong>Tip:</strong> Start a Walk With Me session for enhanced safety tracking
                                </p>
                                <a href="walk_with_me.php" class="btn btn-outline btn-sm mt-2">
                                    <i data-lucide="arrow-right" class="w-4 h-4 mr-2"></i>
                                    Start Walk With Me
                                </a>
                            </div>
                        <?php endif; ?>

                        <div class="flex justify-center mb-8">
                            <button id="panicButton" class="panic-button" onclick="confirmPanicAlert()">
                                <div class="text-center">
                                    <i data-lucide="alert-triangle" class="w-16 h-16 text-white mb-2"></i>
                                    <p class="text-white font-bold text-lg">SOS</p>
                                </div>
                            </button>
                        </div>

                        <div class="grid grid-cols-1 md:grid-cols-3 gap-4 max-w-3xl mx-auto mb-6">
                            <a href="tel:999" class="emergency-service-btn card card-glass p-6 text-center hover:bg-red-500/20">
                                <i data-lucide="phone" class="w-8 h-8 text-red-400 mx-auto mb-2"></i>
                                <p class="text-white font-semibold">Police</p>
                                <p class="text-white/60 text-sm">999</p>
                            </a>
                            <a href="tel:16163" class="emergency-service-btn card card-glass p-6 text-center hover:bg-blue-500/20">
                                <i data-lucide="phone" class="w-8 h-8 text-blue-400 mx-auto mb-2"></i>
                                <p class="text-white font-semibold">Ambulance</p>
                                <p class="text-white/60 text-sm">16163</p>
                            </a>
                            <a href="tel:16222" class="emergency-service-btn card card-glass p-6 text-center hover:bg-orange-500/20">
                                <i data-lucide="phone" class="w-8 h-8 text-orange-400 mx-auto mb-2"></i>
                                <p class="text-white font-semibold">Fire Service</p>
                                <p class="text-white/60 text-sm">16222</p>
                            </a>
                        </div>

                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4 max-w-2xl mx-auto mt-6">
                            <div class="flex items-center space-x-3 p-3 bg-green-500/10 rounded-lg border border-green-500/30">
                                <i data-lucide="check-circle" class="w-5 h-5 text-green-400 flex-shrink-0"></i>
                                <div>
                                    <p class="text-white font-semibold text-sm">Multi-Channel Alerts</p>
                                    <p class="text-white/60 text-xs">SMS, Call, Email, Push - all at once</p>
                                </div>
                            </div>
                            <div class="flex items-center space-x-3 p-3 bg-blue-500/10 rounded-lg border border-blue-500/30">
                                <i data-lucide="map-pin" class="w-5 h-5 text-blue-400 flex-shrink-0"></i>
                                <div>
                                    <p class="text-white font-semibold text-sm">Auto Location Share</p>
                                    <p class="text-white/60 text-xs">GPS coordinates + Google Maps link</p>
                                </div>
                            </div>
                            <div class="flex items-center space-x-3 p-3 bg-purple-500/10 rounded-lg border border-purple-500/30">
                                <i data-lucide="users" class="w-5 h-5 text-purple-400 flex-shrink-0"></i>
                                <div>
                                    <p class="text-white font-semibold text-sm">Community Network</p>
                                    <p class="text-white/60 text-xs">Nearby users can see and respond</p>
                                </div>
                            </div>
                            <div class="flex items-center space-x-3 p-3 bg-orange-500/10 rounded-lg border border-orange-500/30">
                                <i data-lucide="shield" class="w-5 h-5 text-orange-400 flex-shrink-0"></i>
                                <div>
                                    <p class="text-white font-semibold text-sm">Safe Spaces Nearby</p>
                                    <p class="text-white/60 text-xs">Shows closest verified safe locations</p>
                                </div>
                            </div>
                        </div>

                        <div class="text-sm text-white/60 space-y-2 mt-4">
                            <p>⚠️ This will notify ALL your emergency contacts simultaneously and send your location</p>
                            <p>📍 Make sure location services are enabled for accurate GPS tracking</p>
                            <p>🔇 Silent mode available - trigger without sound if needed</p>
                        </div>
                    </div>
                </div>
            </section>

            <!-- Emergency Contacts -->
            <section class="mb-8">
                <div class="mb-6 flex items-center justify-between">
                    <h2 class="heading-2 text-white">Emergency Contacts (<?= count($emergencyContacts) ?>)</h2>
                    <a href="emergency_contacts.php" class="btn btn-outline btn-sm">
                        <i data-lucide="plus" class="w-4 h-4 mr-2"></i>
                        Manage Contacts
                    </a>
                </div>

                <?php if (empty($emergencyContacts)): ?>
                    <div class="card card-glass text-center p-12">
                        <i data-lucide="users" class="w-16 h-16 text-white/30 mx-auto mb-4"></i>
                        <h3 class="heading-3 text-white mb-3">No Emergency Contacts</h3>
                        <p class="text-white/60 mb-6">Add emergency contacts to be notified when you trigger the panic button.</p>
                        <a href="emergency_contacts.php" class="btn btn-primary">
                            <i data-lucide="plus" class="w-4 h-4 mr-2"></i>
                            Add Emergency Contact
                        </a>
                    </div>
                <?php else: ?>
                    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                        <?php foreach ($emergencyContacts as $contact): ?>
                            <div class="card card-glass p-6">
                                <div class="flex items-start justify-between mb-4">
                                    <div class="flex-1">
                                        <h3 class="heading-4 text-white mb-1"><?= htmlspecialchars($contact['contact_name']) ?></h3>
                                        <?php if ($contact['relationship']): ?>
                                            <p class="text-sm text-white/60 mb-2"><?= htmlspecialchars($contact['relationship']) ?></p>
                                        <?php endif; ?>
                                        <p class="text-white/80 font-mono"><?= htmlspecialchars($contact['phone_number']) ?></p>
                                    </div>
                                    <div class="flex items-center space-x-2">
                                        <span class="px-2 py-1 rounded-full text-xs font-semibold bg-blue-500/20 text-blue-300 border border-blue-500/30">
                                            Priority <?= $contact['priority'] ?>
                                        </span>
                                    </div>
                                </div>
                                <div class="flex items-center space-x-2 text-sm text-white/60">
                                    <i data-lucide="bell" class="w-4 h-4"></i>
                                    <span><?= htmlspecialchars($contact['notification_methods']) ?></span>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </section>

            <!-- Recent Alerts -->
            <?php if (!empty($recentAlerts)): ?>
                <section>
                    <div class="mb-6 flex items-center justify-between">
                        <h2 class="heading-2 text-white">Recent Emergency Alerts</h2>
                        <a href="my_emergencies.php" class="btn btn-outline btn-sm">
                            View All
                        </a>
                    </div>

                    <div class="space-y-4">
                        <?php foreach ($recentAlerts as $alert):
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
                                    <div class="flex items-start justify-between">
                                        <div class="flex-1">
                                            <div class="flex items-center space-x-3 mb-2">
                                                <h3 class="heading-4 text-white">Emergency Alert</h3>
                                                <span class="px-2 py-1 rounded-full text-xs font-semibold capitalize <?= $statusColor ?>">
                                                    <?= str_replace('_', ' ', $alert['status']) ?>
                                                </span>
                                            </div>
                                            <p class="text-white/60 text-sm mb-2">
                                                <i data-lucide="clock" class="w-4 h-4 inline mr-1"></i>
                                                <?= date('M j, Y g:i A', strtotime($alert['triggered_at'])) ?>
                                            </p>
                                            <?php if ($alert['location_name']): ?>
                                                <p class="text-white/70 text-sm mb-2">
                                                    <i data-lucide="map-pin" class="w-4 h-4 inline mr-1"></i>
                                                    <?= htmlspecialchars($alert['location_name']) ?>
                                                </p>
                                            <?php endif; ?>
                                            <?php if ($alert['message']): ?>
                                                <p class="text-white/80 text-sm"><?= nl2br(htmlspecialchars($alert['message'])) ?></p>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    <div class="flex items-center space-x-4 mt-4 pt-4 border-t border-white/10 text-sm text-white/60">
                                        <span>
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
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </section>
            <?php endif; ?>
        </div>
    </main>

    <!-- Confirmation Modal -->
    <div id="confirmModal" class="hidden fixed inset-0 z-50 flex items-center justify-center bg-black/60 backdrop-blur-sm p-4">
        <div class="card card-glass p-5 max-w-sm w-full mx-auto animate-slide-up" style="max-height: 90vh; overflow-y: auto;">
            <div class="text-center mb-4">
                <div class="w-12 h-12 bg-red-500 rounded-full flex items-center justify-center mx-auto mb-3">
                    <i data-lucide="alert-triangle" class="w-6 h-6 text-white"></i>
                </div>
                <h3 class="text-lg font-bold text-white mb-1">Confirm Emergency Alert</h3>
                <p class="text-white/70 text-xs leading-relaxed">This will notify your emergency contacts and emergency services.</p>
            </div>
            <form id="panicForm" method="POST" action="emergency_handler.php" class="space-y-3">
                <input type="hidden" name="action" value="create_alert">
                <input type="hidden" name="trigger_method" value="app_button">
                <input type="hidden" name="location_name" id="location_name">
                <input type="hidden" name="latitude" id="latitude">
                <input type="hidden" name="longitude" id="longitude">

                <div>
                    <label class="block text-white text-xs font-medium mb-1.5">Additional Message (Optional)</label>
                    <textarea name="message" rows="2" class="form-input w-full text-sm p-2" placeholder="Describe your emergency..."></textarea>
                </div>

                <div class="space-y-2">
                    <div class="flex items-center space-x-2 p-2 rounded-lg bg-white/5 hover:bg-white/10 transition-colors">
                        <input type="checkbox" name="police_notified" id="police_notified" value="1" checked class="w-4 h-4 rounded border-white/30 bg-white/10 text-red-500 focus:ring-red-500">
                        <label for="police_notified" class="text-white/90 text-xs cursor-pointer flex-1">Notify Police (999)</label>
                    </div>

                    <div class="flex items-center space-x-2 p-2 rounded-lg bg-white/5 hover:bg-white/10 transition-colors">
                        <input type="checkbox" name="ambulance_notified" id="ambulance_notified" value="1" class="w-4 h-4 rounded border-white/30 bg-white/10 text-red-500 focus:ring-red-500">
                        <label for="ambulance_notified" class="text-white/90 text-xs cursor-pointer flex-1">Notify Ambulance (16163)</label>
                    </div>

                    <div class="flex items-center space-x-2 p-2 rounded-lg bg-white/5 hover:bg-white/10 transition-colors">
                        <input type="checkbox" name="fire_service_notified" id="fire_service_notified" value="1" class="w-4 h-4 rounded border-white/30 bg-white/10 text-red-500 focus:ring-red-500">
                        <label for="fire_service_notified" class="text-white/90 text-xs cursor-pointer flex-1">Notify Fire Service (16222)</label>
                    </div>

                    <div class="flex items-center space-x-2 p-2 rounded-lg bg-white/5 hover:bg-white/10 transition-colors">
                        <input type="checkbox" name="emergency_contacts_notified" id="emergency_contacts_notified" value="1" checked class="w-4 h-4 rounded border-white/30 bg-white/10 text-green-500 focus:ring-green-500">
                        <label for="emergency_contacts_notified" class="text-white/90 text-xs cursor-pointer flex-1">Notify Emergency Contacts (<?= count($emergencyContacts) ?> contact<?= count($emergencyContacts) != 1 ? 's' : '' ?>)</label>
                    </div>
                </div>

                <div class="flex items-center space-x-2 pt-3 border-t border-white/10">
                    <button type="button" onclick="closeModal()" class="btn btn-outline flex-1 text-sm py-2">
                        Cancel
                    </button>
                    <button type="submit" class="btn btn-primary flex-1 bg-red-500 hover:bg-red-600 text-sm py-2">
                        <i data-lucide="alert-triangle" class="w-3.5 h-3.5 mr-1.5"></i>
                        Send Alert
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script src="https://unpkg.com/lucide@latest/dist/umd/lucide.js"></script>
    <script src="dashboard-enhanced.js"></script>
    <script>
        lucide.createIcons();

        function confirmPanicAlert() {
            // Get user location
            if (navigator.geolocation) {
                navigator.geolocation.getCurrentPosition(
                    function(position) {
                        document.getElementById('latitude').value = position.coords.latitude;
                        document.getElementById('longitude').value = position.coords.longitude;

                        // Try to get location name (reverse geocoding would require an API)
                        document.getElementById('location_name').value =
                            position.coords.latitude + ', ' + position.coords.longitude;

                        document.getElementById('confirmModal').classList.remove('hidden');
                    },
                    function(error) {
                        console.error('Geolocation error:', error);
                        document.getElementById('location_name').value = 'Location unavailable';
                        document.getElementById('confirmModal').classList.remove('hidden');
                    }
                );
            } else {
                document.getElementById('location_name').value = 'Location not supported';
                document.getElementById('confirmModal').classList.remove('hidden');
            }
        }

        function closeModal() {
            document.getElementById('confirmModal').classList.add('hidden');
        }

        // Close modal on outside click
        document.getElementById('confirmModal').addEventListener('click', function(e) {
            if (e.target === this) {
                closeModal();
            }
        });
    </script>
</body>
</html>

