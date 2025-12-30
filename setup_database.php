<?php
/**
 * SafeSpace Database Setup Script
 * Run this script to set up your SafeSpace database
 */

session_start();

// Include database handler
require_once 'includes/Database.php';

$message = '';
$error = '';
$step = $_GET['step'] ?? 1;

// Database configuration
$host = 'localhost';
$user = 'root';
$pass = '';
$db = 'space_login';

try {
    // Step 1: Test connection
    if ($step == 1) {
        $conn = new mysqli($host, $user, $pass);
        if ($conn->connect_error) {
            throw new Exception('Connection failed: ' . $conn->connect_error);
        }
        $message = '✅ Database connection successful!';
        $step = 2;
    }

    // Step 2: Create database
    if ($step == 2 && isset($_POST['create_db'])) {
        $conn = new mysqli($host, $user, $pass);
        $sql = "CREATE DATABASE IF NOT EXISTS `$db` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci";
        if ($conn->query($sql) === TRUE) {
            $message = '✅ Database created successfully!';
            $step = 3;
        } else {
            throw new Exception('Error creating database: ' . $conn->error);
        }
    }

    // Step 3: Create tables
    if ($step == 3 && isset($_POST['create_tables'])) {
        $database = new Database();

        // Read and execute schema
        $schema = file_get_contents('database_schema.sql');
        $queries = explode(';', $schema);

        $successCount = 0;
        $errorCount = 0;

        foreach ($queries as $query) {
            $query = trim($query);
            if (!empty($query) && !str_starts_with($query, '--')) {
                try {
                    $database->executeRaw($query);
                    $successCount++;
                } catch (Exception $e) {
                    $errorCount++;
                    error_log('Schema error: ' . $e->getMessage());
                }
            }
        }

        if ($errorCount == 0) {
            $message = "✅ Database schema created successfully! ($successCount queries executed)";
            $step = 4;
        } else {
            $message = "⚠️ Schema created with $errorCount errors. Check error log for details.";
            $step = 4;
        }
    }

    // Step 4: Insert sample data
    if ($step == 4 && isset($_POST['insert_sample_data'])) {
        $database = new Database();
        $models = new SafeSpaceModels($database);

        // Create sample user if not exists
        $existingUser = $models->getUserByEmail('admin@safespace.com');
        if (!$existingUser) {
            $userId = $models->createUser([
                'username' => 'admin',
                'email' => 'admin@safespace.com',
                'password' => password_hash('admin123', PASSWORD_DEFAULT),
                'display_name' => 'System Administrator',
                'email_verified' => 1,
                'status' => 'active'
            ]);
        }

        $message = '✅ Sample data inserted successfully!';
        $step = 5;
    }

    // Step 5: Test system
    if ($step == 5 && isset($_POST['test_system'])) {
        $database = new Database();
        $models = new SafeSpaceModels($database);

        // Test database operations
        $stats = $models->getDashboardStats();
        $safeSpaces = $models->getSafeSpaces();
        $alerts = $models->getActiveAlerts();

        $message = '✅ System test completed successfully!';
        $step = 6;
    }

} catch (Exception $e) {
    $error = '❌ Error: ' . $e->getMessage();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SafeSpace Database Setup</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link rel="stylesheet" href="design-system.css">
</head>
<body class="bg-gradient-to-br from-slate-900 via-purple-900 to-slate-900 min-h-screen">
    <div class="container mx-auto px-4 py-8">
        <div class="max-w-4xl mx-auto">
            <!-- Header -->
            <div class="text-center mb-8">
                <h1 class="text-4xl font-bold text-white mb-4">🛡️ SafeSpace Database Setup</h1>
                <p class="text-gray-300">Complete database setup for your SafeSpace system</p>
            </div>

            <!-- Progress Bar -->
            <div class="mb-8">
                <div class="flex items-center justify-between mb-2">
                    <span class="text-sm text-gray-300">Setup Progress</span>
                    <span class="text-sm text-gray-300"><?= $step ?>/6</span>
                </div>
                <div class="w-full bg-gray-700 rounded-full h-2">
                    <div class="bg-gradient-to-r from-blue-500 to-purple-500 h-2 rounded-full transition-all duration-500"
                         style="width: <?= ($step / 6) * 100 ?>%"></div>
                </div>
            </div>

            <!-- Messages -->
            <?php if ($message): ?>
                <div class="bg-green-500/20 border border-green-500/50 rounded-lg p-4 mb-6">
                    <p class="text-green-300"><?= $message ?></p>
                </div>
            <?php endif; ?>

            <?php if ($error): ?>
                <div class="bg-red-500/20 border border-red-500/50 rounded-lg p-4 mb-6">
                    <p class="text-red-300"><?= $error ?></p>
                </div>
            <?php endif; ?>

            <!-- Setup Steps -->
            <div class="space-y-6">
                <!-- Step 1: Connection Test -->
                <?php if ($step == 1): ?>
                    <div class="card card-glass">
                        <div class="card-body">
                            <h3 class="heading-3 mb-4">Step 1: Test Database Connection</h3>
                            <p class="body-medium mb-6">Testing connection to MySQL server...</p>
                            <form method="get">
                                <input type="hidden" name="step" value="1">
                                <button type="submit" class="btn btn-primary">Test Connection</button>
                            </form>
                        </div>
                    </div>
                <?php endif; ?>

                <!-- Step 2: Create Database -->
                <?php if ($step == 2): ?>
                    <div class="card card-glass">
                        <div class="card-body">
                            <h3 class="heading-3 mb-4">Step 2: Create Database</h3>
                            <p class="body-medium mb-6">Create the SafeSpace database named 'space_login'</p>
                            <form method="post">
                                <button type="submit" name="create_db" class="btn btn-primary">Create Database</button>
                            </form>
                        </div>
                    </div>
                <?php endif; ?>

                <!-- Step 3: Create Tables -->
                <?php if ($step == 3): ?>
                    <div class="card card-glass">
                        <div class="card-body">
                            <h3 class="heading-3 mb-4">Step 3: Create Database Tables</h3>
                            <p class="body-medium mb-6">Create all necessary tables for the SafeSpace system</p>
                            <div class="bg-gray-800/50 rounded-lg p-4 mb-4">
                                <h4 class="font-semibold text-white mb-2">Tables to be created:</h4>
                                <ul class="text-gray-300 space-y-1">
                                    <li>• incident_reports - Store incident reports</li>
                                    <li>• alerts - Location-based alerts</li>
                                    <li>• safe_spaces - Verified safe locations</li>
                                    <li>• disputes - Appeal system</li>
                                    <li>• notifications - User notifications</li>
                                    <li>• safety_resources - Support resources</li>
                                    <li>• user_preferences - User settings</li>
                                    <li>• system_statistics - Analytics data</li>
                                    <li>• audit_logs - System audit trail</li>
                                </ul>
                            </div>
                            <form method="post">
                                <button type="submit" name="create_tables" class="btn btn-primary">Create Tables</button>
                            </form>
                        </div>
                    </div>
                <?php endif; ?>

                <!-- Step 4: Insert Sample Data -->
                <?php if ($step == 4): ?>
                    <div class="card card-glass">
                        <div class="card-body">
                            <h3 class="heading-3 mb-4">Step 4: Insert Sample Data</h3>
                            <p class="body-medium mb-6">Add sample data for testing and demonstration</p>
                            <div class="bg-gray-800/50 rounded-lg p-4 mb-4">
                                <h4 class="font-semibold text-white mb-2">Sample data includes:</h4>
                                <ul class="text-gray-300 space-y-1">
                                    <li>• Sample safe spaces in Dhaka</li>
                                    <li>• Safety resources and helplines</li>
                                    <li>• Sample alerts</li>
                                    <li>• Admin user account</li>
                                </ul>
                            </div>
                            <form method="post">
                                <button type="submit" name="insert_sample_data" class="btn btn-primary">Insert Sample Data</button>
                            </form>
                        </div>
                    </div>
                <?php endif; ?>

                <!-- Step 5: Test System -->
                <?php if ($step == 5): ?>
                    <div class="card card-glass">
                        <div class="card-body">
                            <h3 class="heading-3 mb-4">Step 5: Test System</h3>
                            <p class="body-medium mb-6">Verify that all database operations work correctly</p>
                            <form method="post">
                                <button type="submit" name="test_system" class="btn btn-primary">Test System</button>
                            </form>
                        </div>
                    </div>
                <?php endif; ?>

                <!-- Step 6: Complete -->
                <?php if ($step == 6): ?>
                    <div class="card card-glass">
                        <div class="card-body text-center">
                            <div class="text-6xl mb-4">🎉</div>
                            <h3 class="heading-3 mb-4">Setup Complete!</h3>
                            <p class="body-medium mb-6">Your SafeSpace database has been successfully set up and is ready to use.</p>

                            <div class="bg-green-500/20 border border-green-500/50 rounded-lg p-4 mb-6">
                                <h4 class="font-semibold text-green-300 mb-2">Next Steps:</h4>
                                <ul class="text-green-200 space-y-1 text-left">
                                    <li>• <a href="dashboard.php" class="underline">Access your dashboard</a></li>
                                    <li>• Create the missing pages (my_reports.php, dispute_center.php, etc.)</li>
                                    <li>• Configure your Firebase settings</li>
                                    <li>• Set up email notifications</li>
                                </ul>
                            </div>

                            <div class="bg-blue-500/20 border border-blue-500/50 rounded-lg p-4 mb-6">
                                <h4 class="font-semibold text-blue-300 mb-2">Default Admin Account:</h4>
                                <p class="text-blue-200">Email: admin@safespace.com</p>
                                <p class="text-blue-200">Password: admin123</p>
                            </div>

                            <a href="dashboard.php" class="btn btn-primary">Go to Dashboard</a>
                        </div>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Database Info -->
            <div class="mt-8 card card-glass">
                <div class="card-body">
                    <h3 class="heading-4 mb-4">Database Configuration</h3>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4 text-sm">
                        <div>
                            <span class="text-gray-400">Host:</span>
                            <span class="text-white ml-2"><?= $host ?></span>
                        </div>
                        <div>
                            <span class="text-gray-400">Database:</span>
                            <span class="text-white ml-2"><?= $db ?></span>
                        </div>
                        <div>
                            <span class="text-gray-400">User:</span>
                            <span class="text-white ml-2"><?= $user ?></span>
                        </div>
                        <div>
                            <span class="text-gray-400">Connection:</span>
                            <span class="text-green-400 ml-2">✅ Ready</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Auto-advance to next step if there's a message
        <?php if ($message && $step < 6): ?>
            setTimeout(() => {
                window.location.href = '?step=<?= $step + 1 ?>';
            }, 2000);
        <?php endif; ?>
    </script>
</body>
</html>