<?php
/**
 * Emergency Notifications Test & Debug Page
 * This page helps you verify that panic button and Walk With Me are working in real-time
 */

session_start();
require_once 'includes/Database.php';

$database = new Database();
$models = new SafeSpaceModels($database);

$userId = $_SESSION['user_id'] ?? null;

if (!$userId) {
    header('Location: login.html');
    exit;
}

// Get user info
$user = $database->fetchOne("SELECT * FROM users WHERE id = ?", [$userId]);

// Get emergency contacts
$contacts = $models->getUserEmergencyContacts($userId);

// Get recent panic alerts
$recentAlerts = $models->getUserPanicAlerts($userId, ['limit' => 10]);

// Get recent panic notifications
$recentNotifications = $database->fetchAll(
    "SELECT pn.*, pa.triggered_at, pa.message as alert_message, ec.contact_name, ec.phone_number
     FROM panic_notifications pn
     LEFT JOIN panic_alerts pa ON pn.panic_alert_id = pa.id
     LEFT JOIN emergency_contacts ec ON pn.contact_id = ec.id
     WHERE pa.user_id = ?
     ORDER BY pn.sent_at DESC, pa.triggered_at DESC
     LIMIT 20",
    [$userId]
);

// Get active walk sessions
$activeWalks = $database->fetchAll(
    "SELECT * FROM walk_sessions WHERE user_id = ? AND status = 'active' ORDER BY start_time DESC",
    [$userId]
);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Emergency Notifications Test - SafeSpace</title>
    <link rel="stylesheet" href="https://cdn.tailwindcss.com">
    <link rel="stylesheet" href="dashboard-styles.css">
    <style>
        .status-badge {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 12px;
            font-size: 0.75rem;
            font-weight: 600;
        }
        .status-pending { background: #fbbf24; color: #78350f; }
        .status-sent { background: #10b981; color: #064e3b; }
        .status-failed { background: #ef4444; color: #7f1d1d; }
    </style>
</head>
<body class="bg-gradient-to-br from-slate-900 via-red-900 to-slate-900 min-h-screen p-8">
    <div class="max-w-7xl mx-auto">
        <div class="mb-8">
            <h1 class="text-3xl font-bold text-white mb-2">🔍 Emergency Notifications Test & Debug</h1>
            <p class="text-white/70">Verify that panic button and Walk With Me are working in real-time</p>
        </div>

        <!-- Current Status -->
        <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-8">
            <div class="card card-glass p-6">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-white/60 text-sm mb-1">Emergency Contacts</p>
                        <p class="text-3xl font-bold text-white"><?= count($contacts) ?></p>
                        <p class="text-white/50 text-xs mt-1"><?= count(array_filter($contacts, fn($c) => ($c['is_active'] ?? 1) == 1)) ?> active</p>
                    </div>
                    <div class="w-12 h-12 bg-blue-500/20 rounded-lg flex items-center justify-center">
                        <span class="text-2xl">👥</span>
                    </div>
                </div>
            </div>

            <div class="card card-glass p-6">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-white/60 text-sm mb-1">Panic Alerts</p>
                        <p class="text-3xl font-bold text-white"><?= count($recentAlerts) ?></p>
                        <p class="text-white/50 text-xs mt-1">Total alerts created</p>
                    </div>
                    <div class="w-12 h-12 bg-red-500/20 rounded-lg flex items-center justify-center">
                        <span class="text-2xl">🚨</span>
                    </div>
                </div>
            </div>

            <div class="card card-glass p-6">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-white/60 text-sm mb-1">Notifications Sent</p>
                        <p class="text-3xl font-bold text-white"><?= count($recentNotifications) ?></p>
                        <p class="text-white/50 text-xs mt-1">Database records</p>
                    </div>
                    <div class="w-12 h-12 bg-green-500/20 rounded-lg flex items-center justify-center">
                        <span class="text-2xl">📧</span>
                    </div>
                </div>
            </div>
        </div>

        <!-- Important Notice -->
        <div class="card card-glass border-l-4 border-yellow-500 mb-8">
            <div class="card-body p-6">
                <div class="flex items-start space-x-4">
                    <div class="w-12 h-12 bg-yellow-500/20 rounded-lg flex items-center justify-center flex-shrink-0">
                        <span class="text-2xl">⚠️</span>
                    </div>
                    <div class="flex-1">
                        <h3 class="text-lg font-bold text-white mb-2">Current Status: Database Logging Only</h3>
                        <p class="text-white/80 mb-3">
                            <strong>What's Working:</strong>
                        </p>
                        <ul class="text-white/70 text-sm space-y-1 mb-3 list-disc list-inside">
                            <li>✅ Panic alerts are created in real-time</li>
                            <li>✅ Walk With Me location tracking works (Firebase)</li>
                            <li>✅ Notifications are logged in database</li>
                            <li>✅ Emergency contacts are retrieved correctly</li>
                        </ul>
                        <p class="text-white/80 mb-3">
                            <strong>What's NOT Working Yet:</strong>
                        </p>
                        <ul class="text-white/70 text-sm space-y-1 mb-4 list-disc list-inside">
                            <li>❌ SMS messages are NOT actually sent to phone numbers</li>
                            <li>❌ Email notifications are NOT actually sent</li>
                            <li>❌ Phone calls are NOT actually made</li>
                        </ul>
                        <p class="text-yellow-400 text-sm font-semibold">
                            📝 Notifications are currently only saved in the database. To send real SMS/Email, you need to integrate with SMS/Email services (Twilio, Nexmo, SMTP, etc.)
                        </p>
                    </div>
                </div>
            </div>
        </div>

        <!-- Emergency Contacts -->
        <div class="card card-glass mb-8">
            <div class="card-body p-6">
                <h2 class="text-xl font-bold text-white mb-4">Your Emergency Contacts</h2>
                <?php if (empty($contacts)): ?>
                    <p class="text-white/60 text-center py-8">No emergency contacts added yet.</p>
                    <div class="text-center">
                        <a href="emergency_contacts.php" class="btn btn-primary">Add Emergency Contact</a>
                    </div>
                <?php else: ?>
                    <div class="overflow-x-auto">
                        <table class="w-full">
                            <thead>
                                <tr class="border-b border-white/10">
                                    <th class="text-left text-white/70 text-sm font-semibold p-3">Name</th>
                                    <th class="text-left text-white/70 text-sm font-semibold p-3">Phone</th>
                                    <th class="text-left text-white/70 text-sm font-semibold p-3">Methods</th>
                                    <th class="text-left text-white/70 text-sm font-semibold p-3">Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($contacts as $contact): ?>
                                <tr class="border-b border-white/5 hover:bg-white/5">
                                    <td class="p-3 text-white"><?= htmlspecialchars($contact['contact_name']) ?></td>
                                    <td class="p-3 text-white/80 font-mono"><?= htmlspecialchars($contact['phone_number']) ?></td>
                                    <td class="p-3 text-white/70 text-sm"><?= htmlspecialchars($contact['notification_methods'] ?? 'sms,call') ?></td>
                                    <td class="p-3">
                                        <?php if (($contact['is_active'] ?? 1) == 1): ?>
                                            <span class="status-badge status-sent">Active</span>
                                        <?php else: ?>
                                            <span class="status-badge status-pending">Inactive</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Recent Panic Alerts -->
        <div class="card card-glass mb-8">
            <div class="card-body p-6">
                <h2 class="text-xl font-bold text-white mb-4">Recent Panic Alerts</h2>
                <?php if (empty($recentAlerts)): ?>
                    <p class="text-white/60 text-center py-8">No panic alerts yet. Try triggering the panic button to test.</p>
                <?php else: ?>
                    <div class="space-y-4">
                        <?php foreach ($recentAlerts as $alert): ?>
                        <div class="p-4 bg-white/5 rounded-lg border border-white/10">
                            <div class="flex items-start justify-between mb-2">
                                <div>
                                    <h3 class="text-white font-semibold">Alert #<?= $alert['id'] ?></h3>
                                    <p class="text-white/60 text-sm"><?= date('Y-m-d H:i:s', strtotime($alert['triggered_at'])) ?></p>
                                </div>
                                <span class="px-3 py-1 rounded-full text-xs font-semibold bg-<?= $alert['status'] === 'active' ? 'red' : ($alert['status'] === 'resolved' ? 'green' : 'yellow') ?>-500/20 text-<?= $alert['status'] === 'active' ? 'red' : ($alert['status'] === 'resolved' ? 'green' : 'yellow') ?>-400">
                                    <?= ucfirst($alert['status']) ?>
                                </span>
                            </div>
                            <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mt-3 text-sm">
                                <div>
                                    <p class="text-white/60">Method</p>
                                    <p class="text-white"><?= htmlspecialchars($alert['trigger_method']) ?></p>
                                </div>
                                <div>
                                    <p class="text-white/60">Contacts Notified</p>
                                    <p class="text-white"><?= $alert['emergency_contacts_notified'] ?></p>
                                </div>
                                <div>
                                    <p class="text-white/60">Location</p>
                                    <p class="text-white text-xs"><?= htmlspecialchars($alert['location_name'] ?? 'N/A') ?></p>
                                </div>
                                <div>
                                    <p class="text-white/60">GPS</p>
                                    <p class="text-white text-xs"><?= $alert['latitude'] ? $alert['latitude'] . ', ' . $alert['longitude'] : 'N/A' ?></p>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Recent Notifications -->
        <div class="card card-glass mb-8">
            <div class="card-body p-6">
                <h2 class="text-xl font-bold text-white mb-4">Recent Notifications (Database Log)</h2>
                <?php if (empty($recentNotifications)): ?>
                    <p class="text-white/60 text-center py-8">No notifications logged yet.</p>
                <?php else: ?>
                    <div class="overflow-x-auto">
                        <table class="w-full">
                            <thead>
                                <tr class="border-b border-white/10">
                                    <th class="text-left text-white/70 text-sm font-semibold p-3">Time</th>
                                    <th class="text-left text-white/70 text-sm font-semibold p-3">Contact</th>
                                    <th class="text-left text-white/70 text-sm font-semibold p-3">Type</th>
                                    <th class="text-left text-white/70 text-sm font-semibold p-3">Recipient</th>
                                    <th class="text-left text-white/70 text-sm font-semibold p-3">Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($recentNotifications as $notif): ?>
                                <tr class="border-b border-white/5 hover:bg-white/5">
                                    <td class="p-3 text-white/80 text-sm"><?= date('H:i:s', strtotime($notif['sent_at'] ?? $notif['triggered_at'])) ?></td>
                                    <td class="p-3 text-white"><?= htmlspecialchars($notif['contact_name'] ?? 'N/A') ?></td>
                                    <td class="p-3 text-white/80 text-sm"><?= htmlspecialchars($notif['notification_type']) ?></td>
                                    <td class="p-3 text-white/80 font-mono text-sm"><?= htmlspecialchars($notif['recipient']) ?></td>
                                    <td class="p-3">
                                        <span class="status-badge status-<?= $notif['status'] ?>">
                                            <?= ucfirst($notif['status']) ?>
                                        </span>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <div class="mt-4 p-4 bg-yellow-500/10 rounded-lg border border-yellow-500/30">
                        <p class="text-yellow-400 text-sm">
                            <strong>Note:</strong> These notifications are logged in the database but NOT actually sent via SMS/Email.
                            The status shows "sent" because the database record was created, but no actual message was delivered.
                        </p>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Active Walk Sessions -->
        <?php if (!empty($activeWalks)): ?>
        <div class="card card-glass mb-8">
            <div class="card-body p-6">
                <h2 class="text-xl font-bold text-white mb-4">Active Walk Sessions</h2>
                <div class="space-y-4">
                    <?php foreach ($activeWalks as $walk): ?>
                    <div class="p-4 bg-blue-500/10 rounded-lg border border-blue-500/30">
                        <div class="flex items-center justify-between">
                            <div>
                                <h3 class="text-white font-semibold">Walk Session #<?= $walk['id'] ?></h3>
                                <p class="text-white/60 text-sm">Started: <?= date('Y-m-d H:i:s', strtotime($walk['start_time'])) ?></p>
                                <p class="text-white/60 text-sm">Token: <?= htmlspecialchars(substr($walk['session_token'], 0, 16)) ?>...</p>
                            </div>
                            <a href="walk_with_me.php" class="btn btn-outline btn-sm">View Walk</a>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Test Instructions -->
        <div class="card card-glass">
            <div class="card-body p-6">
                <h2 class="text-xl font-bold text-white mb-4">How to Test</h2>
                <div class="space-y-4">
                    <div class="p-4 bg-white/5 rounded-lg">
                        <h3 class="text-white font-semibold mb-2">1. Test Panic Button</h3>
                        <ol class="text-white/70 text-sm space-y-1 list-decimal list-inside">
                            <li>Go to <a href="panic_button.php" class="text-blue-400 hover:underline">Panic Button page</a></li>
                            <li>Click the red SOS button</li>
                            <li>Fill in the form and submit</li>
                            <li>Come back here to see if alert was created</li>
                            <li>Check "Recent Notifications" to see if contacts were notified (in database)</li>
                        </ol>
                    </div>

                    <div class="p-4 bg-white/5 rounded-lg">
                        <h3 class="text-white font-semibold mb-2">2. Test Walk With Me</h3>
                        <ol class="text-white/70 text-sm space-y-1 list-decimal list-inside">
                            <li>Go to <a href="walk_with_me.php" class="text-blue-400 hover:underline">Walk With Me page</a></li>
                            <li>Click "Start Walk With Me"</li>
                            <li>Allow location permissions</li>
                            <li>Click "SOS - HELP ME" button</li>
                            <li>Come back here to see if panic alert was created</li>
                        </ol>
                    </div>

                    <div class="p-4 bg-red-500/10 rounded-lg border border-red-500/30">
                        <h3 class="text-red-400 font-semibold mb-2">⚠️ Important: Real SMS/Email Not Working</h3>
                        <p class="text-white/80 text-sm mb-2">
                            Currently, notifications are only saved in the database. To actually send SMS/Email:
                        </p>
                        <ul class="text-white/70 text-sm space-y-1 list-disc list-inside">
                            <li>Integrate with SMS service (Twilio, Nexmo, etc.)</li>
                            <li>Configure SMTP for email sending</li>
                            <li>Update <code class="bg-white/10 px-1 rounded">includes/Database.php</code> → <code class="bg-white/10 px-1 rounded">createPanicNotification()</code> method</li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>

        <div class="mt-8 text-center">
            <a href="dashboard.php" class="btn btn-outline">Back to Dashboard</a>
            <a href="panic_button.php" class="btn btn-primary ml-4">Test Panic Button</a>
        </div>
    </div>
</body>
</html>

