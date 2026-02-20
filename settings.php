<?php
session_start();
$lang = $_SESSION['lang'] ?? 'en';
$lang_file = $lang === 'bn' ? 'lang_bn.php' : 'lang_en.php';
$L = include($lang_file);

require_once 'includes/error_handler.php';
require_once 'includes/security.php';

// Include database handler
require_once 'includes/Database.php';

// Initialize database
$database = new Database();

// Get user ID from session
$userId = $_SESSION['user_id'] ?? null;

if (!$userId) {
    header('Location: login.html');
    exit;
}

// Get user data and preferences
$user = $database->fetchOne("SELECT * FROM users WHERE id = ?", [$userId]);
$preferences = $database->fetchOne("SELECT * FROM user_preferences WHERE user_id = ?", [$userId]);

if (!$user) {
    header('Location: login.html');
    exit;
}

$errors = [];
$success = '';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'update_notifications') {
        $emailNotifications = isset($_POST['email_notifications']) ? 1 : 0;
        $smsNotifications = isset($_POST['sms_notifications']) ? 1 : 0;
        $pushNotifications = isset($_POST['push_notifications']) ? 1 : 0;
        $alertRadius = floatval($_POST['alert_radius_km'] ?? 1.0);

        try {
            if ($preferences) {
                $sql = "UPDATE user_preferences SET
                        email_notifications = ?,
                        sms_notifications = ?,
                        push_notifications = ?,
                        alert_radius_km = ?,
                        updated_at = NOW()
                        WHERE user_id = ?";
                $database->update($sql, [$emailNotifications, $smsNotifications, $pushNotifications, $alertRadius, $userId]);
            } else {
                $sql = "INSERT INTO user_preferences (user_id, email_notifications, sms_notifications, push_notifications, alert_radius_km)
                        VALUES (?, ?, ?, ?, ?)";
                $database->insert($sql, [$userId, $emailNotifications, $smsNotifications, $pushNotifications, $alertRadius]);
            }
            $success = 'Notification settings updated successfully!';
            $preferences = $database->fetchOne("SELECT * FROM user_preferences WHERE user_id = ?", [$userId]);
        } catch (Exception $e) {
            error_log('Notification settings update error: ' . $e->getMessage());
            $errors[] = 'Failed to update notification settings.';
        }
    }

    if ($action === 'update_privacy') {
        $profileVisibility = $_POST['profile_visibility'] ?? 'private';
        $locationSharing = isset($_POST['location_sharing']) ? 1 : 0;
        $anonymousReporting = isset($_POST['anonymous_reporting']) ? 1 : 0;

        try {
            if ($preferences) {
                $sql = "UPDATE user_preferences SET
                        profile_visibility = ?,
                        location_sharing = ?,
                        anonymous_reporting = ?,
                        updated_at = NOW()
                        WHERE user_id = ?";
                $database->update($sql, [$profileVisibility, $locationSharing, $anonymousReporting, $userId]);
            } else {
                $sql = "INSERT INTO user_preferences (user_id, profile_visibility, location_sharing, anonymous_reporting)
                        VALUES (?, ?, ?, ?)";
                $database->insert($sql, [$userId, $profileVisibility, $locationSharing, $anonymousReporting]);
            }
            $success = 'Privacy settings updated successfully!';
            $preferences = $database->fetchOne("SELECT * FROM user_preferences WHERE user_id = ?", [$userId]);
        } catch (Exception $e) {
            error_log('Privacy settings update error: ' . $e->getMessage());
            $errors[] = 'Failed to update privacy settings.';
        }
    }

    if ($action === 'update_preferences') {
        $preferredLanguage = $_POST['preferred_language'] ?? 'en';
        $timezone = $_POST['timezone'] ?? 'Asia/Dhaka';
        $themePreference = $_POST['theme_preference'] ?? 'auto';

        try {
            if ($preferences) {
                $sql = "UPDATE user_preferences SET
                        preferred_language = ?,
                        timezone = ?,
                        theme_preference = ?,
                        updated_at = NOW()
                        WHERE user_id = ?";
                $database->update($sql, [$preferredLanguage, $timezone, $themePreference, $userId]);
            } else {
                $sql = "INSERT INTO user_preferences (user_id, preferred_language, timezone, theme_preference)
                        VALUES (?, ?, ?, ?)";
                $database->insert($sql, [$userId, $preferredLanguage, $timezone, $themePreference]);
            }

            // Update session language
            $_SESSION['lang'] = $preferredLanguage;

            $success = 'Preferences updated successfully!';
            $preferences = $database->fetchOne("SELECT * FROM user_preferences WHERE user_id = ?", [$userId]);
        } catch (Exception $e) {
            error_log('Preferences update error: ' . $e->getMessage());
            $errors[] = 'Failed to update preferences.';
        }
    }

    if ($action === 'change_password') {
        $currentPassword = $_POST['current_password'] ?? '';
        $newPassword = $_POST['new_password'] ?? '';
        $confirmPassword = $_POST['confirm_password'] ?? '';

        // Validation
        if (empty($currentPassword)) {
            $errors[] = 'Current password is required.';
        }
        if (empty($newPassword)) {
            $errors[] = 'New password is required.';
        } elseif (strlen($newPassword) < 8) {
            $errors[] = 'New password must be at least 8 characters long.';
        }
        if ($newPassword !== $confirmPassword) {
            $errors[] = 'New passwords do not match.';
        }

        if (empty($errors)) {
            try {
                // Verify current password
                $userData = $database->fetchOne("SELECT password FROM users WHERE id = ?", [$userId]);
                if (!password_verify($currentPassword, $userData['password'])) {
                    $errors[] = 'Current password is incorrect.';
                } else {
                    // Update password
                    $hashedPassword = password_hash($newPassword, PASSWORD_DEFAULT);
                    $database->update("UPDATE users SET password = ? WHERE id = ?", [$hashedPassword, $userId]);
                    $success = 'Password changed successfully!';
                }
            } catch (Exception $e) {
                error_log('Password change error: ' . $e->getMessage());
                $errors[] = 'Failed to change password.';
            }
        }
    }
}

// Set default preferences if none exist
if (!$preferences) {
    $preferences = [
        'email_notifications' => 1,
        'sms_notifications' => 0,
        'push_notifications' => 1,
        'alert_radius_km' => 1.0,
        'profile_visibility' => 'private',
        'location_sharing' => 0,
        'anonymous_reporting' => 1,
        'preferred_language' => 'en',
        'timezone' => 'Asia/Dhaka',
        'theme_preference' => 'auto'
    ];
}
?>
<!DOCTYPE html>
<html lang="<?= $lang ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Settings - SafeSpace Portal</title>
    <link rel="icon" type="image/svg+xml" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><text y='.9em' font-size='90'>🛡️</text></svg>">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&family=Poppins:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/lucide/0.263.1/lucide.min.css">
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="js/suppress-tailwind-warning.js"></script>
    <link rel="stylesheet" href="design-system.css">
    <link rel="stylesheet" href="dashboard-styles.css">
</head>
<body class="min-h-screen font-sans bg-slate-900 text-slate-50">
    <!-- Header -->
    <header class="header-bar">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="flex items-center justify-between h-[64px] gap-4">
                <a href="dashboard.php" class="flex items-center space-x-3 flex-shrink-0 group/logo transition-all duration-300 hover:scale-105">
                    <div class="w-10 h-10 bg-gradient-to-r from-cyan-400 via-blue-500 to-purple-600 rounded-2xl flex items-center justify-center shadow-lg ring-2 ring-cyan-500/20 group-hover/logo:ring-cyan-500/40 transition-all duration-300">
                        <i data-lucide="shield" class="w-6 h-6 text-white drop-shadow-lg"></i>
                    </div>
                    <div class="flex items-baseline">
                        <span class="text-xl font-display font-bold bg-gradient-to-r from-cyan-400 to-blue-500 bg-clip-text text-transparent">Safe</span>
                        <span class="text-xl font-display font-bold bg-gradient-to-r from-purple-500 to-pink-500 bg-clip-text text-transparent">Space</span>
                    </div>
                </a>

                <nav class="hidden md:flex items-center justify-center bg-white/5 backdrop-blur-xl rounded-full border border-white/10 shadow-lg relative flex-1 max-w-2xl mx-auto" style="padding: 4px; height: 52px; gap: 4px;">
                    <a href="dashboard.php" class="relative flex flex-col items-center justify-center transition-all duration-300 group px-4 py-2 rounded-2xl min-w-[70px] hover:bg-white/10" style="gap: 4px;">
                        <i data-lucide="layout-dashboard" class="w-4 h-4 text-white/70 group-hover:text-white transition-colors duration-300"></i>
                        <span class="text-xs font-medium text-white/60 group-hover:text-white/80 transition-colors duration-300 whitespace-nowrap">Dashboard</span>
                    </a>
                    <a href="my_reports.php" class="relative flex flex-col items-center justify-center transition-all duration-300 group px-4 py-2 rounded-2xl min-w-[70px] hover:bg-white/10" style="gap: 4px;">
                        <i data-lucide="file-text" class="w-4 h-4 text-white/70 group-hover:text-white transition-colors duration-300"></i>
                        <span class="text-xs font-medium text-white/60 group-hover:text-white/80 transition-colors duration-300 whitespace-nowrap">Reports</span>
                    </a>
                    <a href="dispute_center.php" class="relative flex flex-col items-center justify-center transition-all duration-300 group px-4 py-2 rounded-2xl min-w-[70px] hover:bg-white/10" style="gap: 4px;">
                        <i data-lucide="gavel" class="w-4 h-4 text-white/70 group-hover:text-white transition-colors duration-300"></i>
                        <span class="text-xs font-medium text-white/60 group-hover:text-white/80 transition-colors duration-300 whitespace-nowrap">Disputes</span>
                    </a>
                    <a href="safety_resources.php" class="relative flex flex-col items-center justify-center transition-all duration-300 group px-4 py-2 rounded-2xl min-w-[70px] hover:bg-white/10" style="gap: 4px;">
                        <i data-lucide="bookmark" class="w-4 h-4 text-white/70 group-hover:text-white transition-colors duration-300"></i>
                        <span class="text-xs font-medium text-white/60 group-hover:text-white/80 transition-colors duration-300 whitespace-nowrap">Resources</span>
                    </a>
                </nav>

                <div class="flex items-center space-x-2">
                    <a href="profile.php" class="flex items-center space-x-2 px-4 h-[52px] rounded-full bg-white/5 hover:bg-white/10 border border-white/10 hover:border-cyan-500/50 transition-all duration-300 group">
                        <i data-lucide="arrow-left" class="w-4 h-4 text-white/70 group-hover:text-cyan-400 transition-colors duration-300"></i>
                        <span class="text-sm font-medium text-white/90 group-hover:text-white transition-colors duration-300 hidden sm:inline">Back</span>
                    </a>
                </div>
            </div>
        </div>
    </header>

    <main class="dashboard-content">
        <div class="max-w-7xl mx-auto">
            <!-- Header Section -->
            <section class="mb-6 animate-slide-up relative">
                <div class="absolute inset-0 bg-gradient-to-b from-cyan-500/5 to-transparent rounded-3xl -z-10 blur-3xl"></div>
                <div class="flex items-center gap-4 p-4">
                    <div class="w-16 h-16 bg-gradient-to-r from-cyan-400 via-blue-500 to-purple-600 rounded-2xl flex items-center justify-center shadow-lg ring-2 ring-white/10">
                        <i data-lucide="settings" class="w-8 h-8 text-white"></i>
                    </div>
                    <div>
                        <h1 class="text-2xl font-bold text-white tracking-tight">Account Settings</h1>
                        <p class="text-sm text-slate-400">Customize your SafeSpace experience</p>
                    </div>
                </div>
            </section>

            <!-- Messages -->
            <?php if (!empty($errors)): ?>
                <div class="mb-6 card border-l-4 border-red-500 animate-slide-up bg-red-500/10">
                    <div class="flex items-center p-4">
                        <i data-lucide="alert-circle" class="w-5 h-5 text-red-500 mr-3"></i>
                        <div>
                            <?php foreach ($errors as $error): ?>
                                <p class="text-red-300"><?= htmlspecialchars($error) ?></p>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <?php if (!empty($success)): ?>
                <div class="mb-6 card border-l-4 border-green-500 animate-slide-up bg-green-500/10">
                    <div class="flex items-center p-4">
                        <i data-lucide="check-circle" class="w-5 h-5 text-green-500 mr-3"></i>
                        <p class="text-green-300"><?= htmlspecialchars($success) ?></p>
                    </div>
                </div>
            <?php endif; ?>

            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                <!-- Notification Settings -->
                <div class="card animate-slide-up overflow-hidden">
                    <div class="flex items-center justify-between p-5 border-b border-white/10">
                        <div class="flex items-center space-x-3">
                            <div class="w-8 h-8 bg-gradient-to-r from-blue-500 to-cyan-600 rounded-lg flex items-center justify-center shadow-lg">
                                <i data-lucide="bell" class="w-4 h-4 text-white"></i>
                            </div>
                            <div>
                                <h2 class="text-base font-bold text-white">Notifications</h2>
                                <p class="text-xs text-slate-400">Manage alerts</p>
                            </div>
                        </div>
                    </div>

                    <div class="p-5">
                        <form method="post" class="space-y-5">
                            <input type="hidden" name="action" value="update_notifications">

                            <div class="space-y-3">
                                <div class="flex items-center justify-between p-3 bg-white/5 rounded-lg border border-white/5">
                                    <div class="flex items-center space-x-3">
                                        <i data-lucide="mail" class="w-4 h-4 text-blue-400"></i>
                                        <div>
                                            <p class="font-medium text-sm text-white">Email</p>
                                            <p class="text-[10px] text-slate-400">Get updates via email</p>
                                        </div>
                                    </div>
                                    <label class="relative inline-flex items-center cursor-pointer">
                                        <input type="checkbox" name="email_notifications" class="sr-only peer" <?= ($preferences['email_notifications'] ?? 1) ? 'checked' : '' ?>>
                                        <div class="w-9 h-5 bg-slate-700 peer-focus:outline-none rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:rounded-full after:h-4 after:w-4 after:transition-all peer-checked:bg-cyan-500"></div>
                                    </label>
                                </div>

                                <div class="flex items-center justify-between p-3 bg-white/5 rounded-lg border border-white/5">
                                    <div class="flex items-center space-x-3">
                                        <i data-lucide="message-circle" class="w-4 h-4 text-green-400"></i>
                                        <div>
                                            <p class="font-medium text-sm text-white">SMS</p>
                                            <p class="text-[10px] text-slate-400">Get updates via SMS</p>
                                        </div>
                                    </div>
                                    <label class="relative inline-flex items-center cursor-pointer">
                                        <input type="checkbox" name="sms_notifications" class="sr-only peer" <?= ($preferences['sms_notifications'] ?? 0) ? 'checked' : '' ?>>
                                        <div class="w-9 h-5 bg-slate-700 peer-focus:outline-none rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:rounded-full after:h-4 after:w-4 after:transition-all peer-checked:bg-green-500"></div>
                                    </label>
                                </div>

                                <div class="flex items-center justify-between p-3 bg-white/5 rounded-lg border border-white/5">
                                    <div class="flex items-center space-x-3">
                                        <i data-lucide="smartphone" class="w-4 h-4 text-purple-400"></i>
                                        <div>
                                            <p class="font-medium text-sm text-white">Push</p>
                                            <p class="text-[10px] text-slate-400">Browser notifications</p>
                                        </div>
                                    </div>
                                    <label class="relative inline-flex items-center cursor-pointer">
                                        <input type="checkbox" name="push_notifications" class="sr-only peer" <?= ($preferences['push_notifications'] ?? 1) ? 'checked' : '' ?>>
                                        <div class="w-9 h-5 bg-slate-700 peer-focus:outline-none rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:rounded-full after:h-4 after:w-4 after:transition-all peer-checked:bg-purple-500"></div>
                                    </label>
                                </div>
                            </div>

                            <div class="space-y-2">
                                <label class="flex items-center text-xs font-semibold text-slate-300">
                                    <i data-lucide="map-pin" class="w-3 h-3 mr-1.5 text-orange-400"></i>
                                    Alert Radius (km)
                                </label>
                                <input type="range" name="alert_radius_km" class="w-full h-1.5 bg-slate-700 rounded-lg appearance-none cursor-pointer accent-cyan-500"
                                       min="0.5" max="10" step="0.5" value="<?= $preferences['alert_radius_km'] ?? 1.0 ?>" id="alert-radius">
                                <div class="flex justify-between text-[10px] text-slate-400">
                                    <span>0.5 km</span>
                                    <span id="radius-value" class="text-cyan-400 font-bold"><?= $preferences['alert_radius_km'] ?? 1.0 ?> km</span>
                                    <span>10 km</span>
                                </div>
                            </div>

                            <button type="submit" class="btn btn-primary btn-sm w-full flex items-center justify-center">
                                <i data-lucide="save" class="w-3 h-3 mr-2"></i>
                                <span>Save Changes</span>
                            </button>
                        </form>
                    </div>
                </div>

                <!-- Privacy Settings -->
                <div class="card animate-slide-up overflow-hidden" style="animation-delay: 0.1s;">
                    <div class="flex items-center justify-between p-5 border-b border-white/10">
                        <div class="flex items-center space-x-3">
                            <div class="w-8 h-8 bg-gradient-to-r from-green-500 to-emerald-600 rounded-lg flex items-center justify-center shadow-lg">
                                <i data-lucide="shield" class="w-4 h-4 text-white"></i>
                            </div>
                            <div>
                                <h2 class="text-base font-bold text-white">Privacy</h2>
                                <p class="text-xs text-slate-400">Control visibility</p>
                            </div>
                        </div>
                    </div>

                    <div class="p-5">
                        <form method="post" class="space-y-5">
                            <input type="hidden" name="action" value="update_privacy">

                            <div class="space-y-1.5">
                                <label class="form-label text-xs">
                                    <i data-lucide="eye" class="w-3 h-3 mr-1.5 text-blue-400 inline"></i>
                                    Profile Visibility
                                </label>
                                <select name="profile_visibility" class="form-select text-sm py-2">
                                    <option value="private" <?= ($preferences['profile_visibility'] ?? 'private') === 'private' ? 'selected' : '' ?>>Private</option>
                                    <option value="friends_only" <?= ($preferences['profile_visibility'] ?? 'private') === 'friends_only' ? 'selected' : '' ?>>Friends Only</option>
                                    <option value="public" <?= ($preferences['profile_visibility'] ?? 'private') === 'public' ? 'selected' : '' ?>>Public</option>
                                </select>
                            </div>

                            <div class="space-y-3">
                                <div class="flex items-center justify-between p-3 bg-white/5 rounded-lg border border-white/5">
                                    <div class="flex items-center space-x-3">
                                        <i data-lucide="map-pin" class="w-4 h-4 text-green-400"></i>
                                        <div>
                                            <p class="font-medium text-sm text-white">Location Sharing</p>
                                            <p class="text-[10px] text-slate-400">Allow location features</p>
                                        </div>
                                    </div>
                                    <label class="relative inline-flex items-center cursor-pointer">
                                        <input type="checkbox" name="location_sharing" class="sr-only peer" <?= ($preferences['location_sharing'] ?? 0) ? 'checked' : '' ?>>
                                        <div class="w-9 h-5 bg-slate-700 peer-focus:outline-none rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:rounded-full after:h-4 after:w-4 after:transition-all peer-checked:bg-green-500"></div>
                                    </label>
                                </div>

                                <div class="flex items-center justify-between p-3 bg-white/5 rounded-lg border border-white/5">
                                    <div class="flex items-center space-x-3">
                                        <i data-lucide="user-x" class="w-4 h-4 text-purple-400"></i>
                                        <div>
                                            <p class="font-medium text-sm text-white">Anonymous Reporting</p>
                                            <p class="text-[10px] text-slate-400">Submit anonymously</p>
                                        </div>
                                    </div>
                                    <label class="relative inline-flex items-center cursor-pointer">
                                        <input type="checkbox" name="anonymous_reporting" class="sr-only peer" <?= ($preferences['anonymous_reporting'] ?? 1) ? 'checked' : '' ?>>
                                        <div class="w-9 h-5 bg-slate-700 peer-focus:outline-none rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:rounded-full after:h-4 after:w-4 after:transition-all peer-checked:bg-purple-500"></div>
                                    </label>
                                </div>
                            </div>

                            <button type="submit" class="btn btn-primary btn-sm w-full flex items-center justify-center">
                                <i data-lucide="save" class="w-3 h-3 mr-2"></i>
                                <span>Save Changes</span>
                            </button>
                        </form>
                    </div>
                </div>

                <!-- Preferences -->
                <div class="card animate-slide-up overflow-hidden" style="animation-delay: 0.2s;">
                    <div class="flex items-center justify-between p-5 border-b border-white/10">
                        <div class="flex items-center space-x-3">
                            <div class="w-8 h-8 bg-gradient-to-r from-purple-500 to-pink-600 rounded-lg flex items-center justify-center shadow-lg">
                                <i data-lucide="sliders" class="w-4 h-4 text-white"></i>
                            </div>
                            <div>
                                <h2 class="text-base font-bold text-white">Preferences</h2>
                                <p class="text-xs text-slate-400">System settings</p>
                            </div>
                        </div>
                    </div>

                    <div class="p-5">
                        <form method="post" class="space-y-5">
                            <input type="hidden" name="action" value="update_preferences">

                            <div class="space-y-1.5">
                                <label class="form-label text-xs">
                                    <i data-lucide="globe" class="w-3 h-3 mr-1.5 text-blue-400 inline"></i>
                                    Language
                                </label>
                                <select name="preferred_language" class="form-select text-sm py-2">
                                    <option value="en" <?= ($preferences['preferred_language'] ?? 'en') === 'en' ? 'selected' : '' ?>>English</option>
                                    <option value="bn" <?= ($preferences['preferred_language'] ?? 'en') === 'bn' ? 'selected' : '' ?>>বাংলা (Bengali)</option>
                                </select>
                            </div>

                            <div class="space-y-1.5">
                                <label class="form-label text-xs">
                                    <i data-lucide="clock" class="w-3 h-3 mr-1.5 text-green-400 inline"></i>
                                    Timezone
                                </label>
                                <select name="timezone" class="form-select text-sm py-2">
                                    <option value="Asia/Dhaka" <?= ($preferences['timezone'] ?? 'Asia/Dhaka') === 'Asia/Dhaka' ? 'selected' : '' ?>>Asia/Dhaka (UTC+6)</option>
                                    <option value="UTC" <?= ($preferences['timezone'] ?? 'Asia/Dhaka') === 'UTC' ? 'selected' : '' ?>>UTC (UTC+0)</option>
                                    <option value="America/New_York" <?= ($preferences['timezone'] ?? 'Asia/Dhaka') === 'America/New_York' ? 'selected' : '' ?>>America/New_York (UTC-5)</option>
                                    <option value="Europe/London" <?= ($preferences['timezone'] ?? 'Asia/Dhaka') === 'Europe/London' ? 'selected' : '' ?>>Europe/London (UTC+0)</option>
                                    <option value="Asia/Kolkata" <?= ($preferences['timezone'] ?? 'Asia/Dhaka') === 'Asia/Kolkata' ? 'selected' : '' ?>>Asia/Kolkata (UTC+5:30)</option>
                                    <option value="Asia/Dubai" <?= ($preferences['timezone'] ?? 'Asia/Dhaka') === 'Asia/Dubai' ? 'selected' : '' ?>>Asia/Dubai (UTC+4)</option>
                                </select>
                            </div>

                            <div class="space-y-1.5">
                                <label class="form-label text-xs">
                                    <i data-lucide="palette" class="w-3 h-3 mr-1.5 text-purple-400 inline"></i>
                                    Theme
                                </label>
                                <select name="theme_preference" class="form-select text-sm py-2">
                                    <option value="auto" <?= ($preferences['theme_preference'] ?? 'auto') === 'auto' ? 'selected' : '' ?>>Auto (System)</option>
                                    <option value="light" <?= ($preferences['theme_preference'] ?? 'auto') === 'light' ? 'selected' : '' ?>>Light</option>
                                    <option value="dark" <?= ($preferences['theme_preference'] ?? 'auto') === 'dark' ? 'selected' : '' ?>>Dark</option>
                                </select>
                            </div>

                            <button type="submit" class="btn btn-primary btn-sm w-full flex items-center justify-center">
                                <i data-lucide="save" class="w-3 h-3 mr-2"></i>
                                <span>Save Changes</span>
                            </button>
                        </form>
                    </div>
                </div>

                <!-- Security Settings -->
                <div class="card animate-slide-up overflow-hidden" style="animation-delay: 0.3s;">
                    <div class="flex items-center justify-between p-5 border-b border-white/10">
                        <div class="flex items-center space-x-3">
                            <div class="w-8 h-8 bg-gradient-to-r from-red-500 to-orange-600 rounded-lg flex items-center justify-center shadow-lg">
                                <i data-lucide="lock" class="w-4 h-4 text-white"></i>
                            </div>
                            <div>
                                <h2 class="text-base font-bold text-white">Security</h2>
                                <p class="text-xs text-slate-400">Password & Access</p>
                            </div>
                        </div>
                    </div>

                    <div class="p-5">
                        <form method="post" class="space-y-5" id="passwordForm">
                            <input type="hidden" name="action" value="change_password">

                            <div class="space-y-1.5">
                                <label class="form-label text-xs">
                                    <i data-lucide="key" class="w-3 h-3 mr-1.5 text-blue-400 inline"></i>
                                    Current Password
                                </label>
                                <input type="password" name="current_password" class="form-input text-sm py-2" placeholder="Enter current password" required>
                            </div>

                            <div class="space-y-1.5">
                                <label class="form-label text-xs">
                                    <i data-lucide="lock" class="w-3 h-3 mr-1.5 text-green-400 inline"></i>
                                    New Password
                                </label>
                                <input type="password" name="new_password" class="form-input text-sm py-2" placeholder="New password (min. 8 chars)" minlength="8" required>
                            </div>

                            <div class="space-y-1.5">
                                <label class="form-label text-xs">
                                    <i data-lucide="lock-check" class="w-3 h-3 mr-1.5 text-purple-400 inline"></i>
                                    Confirm Password
                                </label>
                                <input type="password" name="confirm_password" class="form-input text-sm py-2" placeholder="Confirm new password" minlength="8" required>
                            </div>

                            <button type="submit" class="btn btn-primary btn-sm w-full flex items-center justify-center">
                                <i data-lucide="save" class="w-3 h-3 mr-2"></i>
                                <span>Change Password</span>
                            </button>
                        </form>

                        <div class="mt-5 pt-5 border-t border-white/10">
                            <a href="deactivate_account.php" class="flex items-center justify-between p-3 rounded-lg bg-red-500/10 hover:bg-red-500/20 border border-red-500/20 hover:border-red-500/40 transition-all group">
                                <div class="flex items-center space-x-3">
                                    <i data-lucide="alert-triangle" class="w-4 h-4 text-red-400"></i>
                                    <div>
                                        <p class="font-medium text-sm text-red-200 group-hover:text-red-100">Deactivate Account</p>
                                        <p class="text-[10px] text-red-400/70">Temporarily disable access</p>
                                    </div>
                                </div>
                                <i data-lucide="chevron-right" class="w-4 h-4 text-red-400/50 group-hover:text-red-400 transition-colors"></i>
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </main>

    <!-- Toast notification bridge (PHP → JS) -->
    <script src="js/toast.js"></script>
    <?php if (!empty($success)): ?>
    <div data-toast="<?= htmlspecialchars($success, ENT_QUOTES) ?>" data-toast-type="success" hidden></div>
    <?php endif; ?>
    <?php foreach ($errors as $err): ?>
    <div data-toast="<?= htmlspecialchars($err, ENT_QUOTES) ?>" data-toast-type="error" hidden></div>
    <?php endforeach; ?>
</body>
</html>
