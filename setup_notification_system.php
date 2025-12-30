<?php
/**
 * Setup Notification System
 * This script will:
 * 1. Fix the panic_notifications table structure
 * 2. Set up notification configuration
 * 3. Test the system
 */

session_start();
require_once 'includes/Database.php';

$database = new Database();
$conn = $database->getConnection();

$messages = [];
$errors = [];

// Step 1: Fix panic_notifications table
if (isset($_POST['fix_table'])) {
    try {
        // Check if created_at column exists
        try {
            $checkColumn = $database->fetchOne(
                "SELECT COUNT(*) as count FROM INFORMATION_SCHEMA.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE()
                 AND TABLE_NAME = 'panic_notifications'
                 AND COLUMN_NAME = 'created_at'"
            );

            if (empty($checkColumn) || $checkColumn['count'] == 0) {
                // Add created_at column
                $conn->query("ALTER TABLE `panic_notifications`
                             ADD COLUMN `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP AFTER `message`");
                $messages[] = "✅ Added 'created_at' column to panic_notifications table";
            } else {
                $messages[] = "ℹ️ 'created_at' column already exists - skipping";
            }
        } catch (Exception $e) {
            // If error is about duplicate column, that's fine
            if (strpos($e->getMessage(), 'Duplicate column') !== false || strpos($e->getMessage(), '1060') !== false) {
                $messages[] = "ℹ️ 'created_at' column already exists";
            } else {
                throw $e; // Re-throw if it's a different error
            }
        }

        // Update existing records
        $conn->query("UPDATE `panic_notifications`
                     SET `created_at` = COALESCE(`sent_at`, NOW())
                     WHERE `created_at` IS NULL");
        $messages[] = "✅ Updated existing records with created_at timestamps";

    } catch (Exception $e) {
        $errors[] = "❌ Error fixing table: " . $e->getMessage();
    }
}

// Step 2: Create notification config file
if (isset($_POST['create_config'])) {
    $config = [
        'sms' => [
            'enabled' => false,
            'provider' => 'twilio', // twilio, nexmo, custom
            'api_key' => '',
            'api_secret' => '',
            'from_number' => ''
        ],
        'email' => [
            'enabled' => false,
            'smtp_host' => '',
            'smtp_port' => 587,
            'smtp_user' => '',
            'smtp_pass' => '',
            'from_email' => '',
            'from_name' => 'SafeSpace Emergency'
        ],
        'call' => [
            'enabled' => false,
            'provider' => 'twilio',
            'api_key' => '',
            'api_secret' => '',
            'from_number' => ''
        ]
    ];

    $configJson = json_encode($config, JSON_PRETTY_PRINT);
    file_put_contents('notification_config.json', $configJson);
    $messages[] = "✅ Created notification_config.json file";
}

// Check table structure
$tableStructure = $database->fetchAll("DESCRIBE panic_notifications");
$hasCreatedAt = false;
foreach ($tableStructure as $column) {
    if ($column['Field'] === 'created_at') {
        $hasCreatedAt = true;
        break;
    }
}

// Check if config file exists
$configExists = file_exists('notification_config.json');
$config = [];
if ($configExists) {
    $config = json_decode(file_get_contents('notification_config.json'), true);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Setup Notification System - SafeSpace</title>
    <link rel="stylesheet" href="https://cdn.tailwindcss.com">
    <link rel="stylesheet" href="dashboard-styles.css">
</head>
<body class="bg-gradient-to-br from-slate-900 via-red-900 to-slate-900 min-h-screen p-8">
    <div class="max-w-4xl mx-auto">
        <div class="mb-8">
            <h1 class="text-3xl font-bold text-white mb-2">⚙️ Setup Notification System</h1>
            <p class="text-white/70">Complete setup for emergency notifications</p>
        </div>

        <?php if (!empty($messages)): ?>
            <div class="card card-glass border-l-4 border-green-500 mb-6">
                <div class="card-body p-4">
                    <?php foreach ($messages as $msg): ?>
                        <p class="text-green-300"><?= htmlspecialchars($msg) ?></p>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>

        <?php if (!empty($errors)): ?>
            <div class="card card-glass border-l-4 border-red-500 mb-6">
                <div class="card-body p-4">
                    <?php foreach ($errors as $err): ?>
                        <p class="text-red-300"><?= htmlspecialchars($err) ?></p>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>

        <!-- Step 1: Fix Database Table -->
        <div class="card card-glass mb-6">
            <div class="card-body p-6">
                <div class="flex items-center justify-between mb-4">
                    <div>
                        <h2 class="text-xl font-bold text-white mb-1">Step 1: Fix Database Table</h2>
                        <p class="text-white/60 text-sm">Add missing 'created_at' column to panic_notifications table</p>
                    </div>
                    <?php if ($hasCreatedAt): ?>
                        <span class="px-3 py-1 rounded-full text-xs font-semibold bg-green-500/20 text-green-400">✅ Fixed</span>
                    <?php else: ?>
                        <span class="px-3 py-1 rounded-full text-xs font-semibold bg-yellow-500/20 text-yellow-400">⚠️ Needs Fix</span>
                    <?php endif; ?>
                </div>

                <div class="mb-4 p-4 bg-white/5 rounded-lg">
                    <p class="text-white/80 text-sm mb-2"><strong>Current Table Structure:</strong></p>
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead>
                                <tr class="border-b border-white/10">
                                    <th class="text-left text-white/70 p-2">Column</th>
                                    <th class="text-left text-white/70 p-2">Type</th>
                                    <th class="text-left text-white/70 p-2">Null</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($tableStructure as $col): ?>
                                <tr class="border-b border-white/5">
                                    <td class="p-2 text-white"><?= htmlspecialchars($col['Field']) ?></td>
                                    <td class="p-2 text-white/80 text-xs"><?= htmlspecialchars($col['Type']) ?></td>
                                    <td class="p-2 text-white/80"><?= htmlspecialchars($col['Null']) ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <?php if (!$hasCreatedAt): ?>
                    <form method="POST">
                        <button type="submit" name="fix_table" class="btn btn-primary">
                            Fix Table Structure
                        </button>
                    </form>
                <?php else: ?>
                    <p class="text-green-400 text-sm">✅ Table structure is correct!</p>
                <?php endif; ?>
            </div>
        </div>

        <!-- Step 2: Create Config File -->
        <div class="card card-glass mb-6">
            <div class="card-body p-6">
                <div class="flex items-center justify-between mb-4">
                    <div>
                        <h2 class="text-xl font-bold text-white mb-1">Step 2: Create Configuration File</h2>
                        <p class="text-white/60 text-sm">Set up notification_config.json for SMS/Email services</p>
                    </div>
                    <?php if ($configExists): ?>
                        <span class="px-3 py-1 rounded-full text-xs font-semibold bg-green-500/20 text-green-400">✅ Exists</span>
                    <?php else: ?>
                        <span class="px-3 py-1 rounded-full text-xs font-semibold bg-yellow-500/20 text-yellow-400">⚠️ Missing</span>
                    <?php endif; ?>
                </div>

                <?php if (!$configExists): ?>
                    <form method="POST">
                        <button type="submit" name="create_config" class="btn btn-primary">
                            Create Config File
                        </button>
                    </form>
                <?php else: ?>
                    <div class="p-4 bg-white/5 rounded-lg mb-4">
                        <p class="text-white/80 text-sm mb-2"><strong>Configuration Status:</strong></p>
                        <div class="space-y-2 text-sm">
                            <div class="flex items-center justify-between">
                                <span class="text-white/70">SMS Service:</span>
                                <span class="<?= $config['sms']['enabled'] ? 'text-green-400' : 'text-yellow-400' ?>">
                                    <?= $config['sms']['enabled'] ? '✅ Enabled' : '⚠️ Disabled' ?>
                                </span>
                            </div>
                            <div class="flex items-center justify-between">
                                <span class="text-white/70">Email Service:</span>
                                <span class="<?= $config['email']['enabled'] ? 'text-green-400' : 'text-yellow-400' ?>">
                                    <?= $config['email']['enabled'] ? '✅ Enabled' : '⚠️ Disabled' ?>
                                </span>
                            </div>
                            <div class="flex items-center justify-between">
                                <span class="text-white/70">Call Service:</span>
                                <span class="<?= $config['call']['enabled'] ? 'text-green-400' : 'text-yellow-400' ?>">
                                    <?= $config['call']['enabled'] ? '✅ Enabled' : '⚠️ Disabled' ?>
                                </span>
                            </div>
                        </div>
                    </div>
                    <p class="text-green-400 text-sm mb-4">✅ Config file exists at: <code class="bg-white/10 px-2 py-1 rounded">notification_config.json</code></p>
                    <p class="text-white/60 text-sm">
                        To enable real notifications, edit <code class="bg-white/10 px-1 rounded">notification_config.json</code>
                        and add your API credentials, then update <code class="bg-white/10 px-1 rounded">includes/NotificationSender.php</code>
                    </p>
                <?php endif; ?>
            </div>
        </div>

        <!-- Step 3: Test System -->
        <div class="card card-glass mb-6">
            <div class="card-body p-6">
                <h2 class="text-xl font-bold text-white mb-4">Step 3: Test the System</h2>
                <div class="space-y-4">
                    <div class="p-4 bg-white/5 rounded-lg">
                        <h3 class="text-white font-semibold mb-2">✅ What's Working:</h3>
                        <ul class="text-white/70 text-sm space-y-1 list-disc list-inside">
                            <li>Database structure (after Step 1)</li>
                            <li>Panic alert creation</li>
                            <li>Notification logging</li>
                            <li>Emergency contact retrieval</li>
                            <li>Walk With Me integration</li>
                        </ul>
                    </div>

                    <div class="p-4 bg-yellow-500/10 rounded-lg border border-yellow-500/30">
                        <h3 class="text-yellow-400 font-semibold mb-2">⚠️ What Needs Configuration:</h3>
                        <ul class="text-white/70 text-sm space-y-1 list-disc list-inside">
                            <li>SMS sending (requires Twilio/Nexmo API)</li>
                            <li>Email sending (requires SMTP configuration)</li>
                            <li>Phone calls (requires Twilio API)</li>
                        </ul>
                    </div>

                    <div class="flex space-x-4">
                        <a href="test_emergency_notifications.php" class="btn btn-outline">
                            Test Page
                        </a>
                        <a href="panic_button.php" class="btn btn-primary">
                            Test Panic Button
                        </a>
                        <a href="walk_with_me.php" class="btn btn-primary">
                            Test Walk With Me
                        </a>
                    </div>
                </div>
            </div>
        </div>

        <!-- SQL Commands -->
        <div class="card card-glass">
            <div class="card-body p-6">
                <h2 class="text-xl font-bold text-white mb-4">SQL Commands (Manual Fix)</h2>
                <p class="text-white/60 text-sm mb-4">If the automatic fix doesn't work, run this SQL manually:</p>
                <div class="p-4 bg-black/30 rounded-lg font-mono text-sm overflow-x-auto">
                    <pre class="text-green-400">-- Fix panic_notifications table
ALTER TABLE `panic_notifications`
ADD COLUMN `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP AFTER `message`;

-- Update existing records
UPDATE `panic_notifications`
SET `created_at` = COALESCE(`sent_at`, NOW())
WHERE `created_at` IS NULL;</pre>
                </div>
                <button onclick="copySQL()" class="btn btn-outline btn-sm mt-4">
                    Copy SQL
                </button>
            </div>
        </div>

        <div class="mt-8 text-center">
            <a href="dashboard.php" class="btn btn-outline">Back to Dashboard</a>
        </div>
    </div>

    <script>
        function copySQL() {
            const sql = `ALTER TABLE \`panic_notifications\`
ADD COLUMN \`created_at\` DATETIME DEFAULT CURRENT_TIMESTAMP AFTER \`message\`;

UPDATE \`panic_notifications\`
SET \`created_at\` = COALESCE(\`sent_at\`, NOW())
WHERE \`created_at\` IS NULL;`;
            navigator.clipboard.writeText(sql).then(() => {
                alert('SQL copied to clipboard!');
            });
        }
    </script>
</body>
</html>

