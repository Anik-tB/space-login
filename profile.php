<?php
session_start();
$lang = $_SESSION['lang'] ?? 'en';
$lang_file = $lang === 'bn' ? 'lang_bn.php' : 'lang_en.php';
$L = include($lang_file);

// Include database handler
require_once 'includes/Database.php';

// Initialize database
$database = new Database();
$models = new SafeSpaceModels($database);

// Get user ID from session
$userId = $_SESSION['user_id'] ?? null;

if (!$userId) {
    header('Location: login.html');
    exit;
}

// Get user data
$user = $database->fetchOne("SELECT * FROM users WHERE id = ?", [$userId]);

if (!$user) {
    header('Location: login.html');
    exit;
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'update_profile') {
        $displayName = trim($_POST['display_name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $bio = trim($_POST['bio'] ?? '');
        $nidNumber = trim($_POST['nid_number'] ?? '');

        $errors = [];

        // Handle profile picture upload
        $profilePicturePath = $user['profile_picture'] ?? '';
        if (isset($_FILES['profile_picture']) && $_FILES['profile_picture']['error'] === UPLOAD_ERR_OK) {
            $uploadDir = 'uploads/profile_pictures/';
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0755, true);
            }

            $file = $_FILES['profile_picture'];
            $allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
            $maxSize = 5 * 1024 * 1024; // 5MB

            if (!in_array($file['type'], $allowedTypes)) {
                $errors[] = 'Invalid file type. Please upload a JPEG, PNG, GIF, or WebP image.';
            } elseif ($file['size'] > $maxSize) {
                $errors[] = 'File size exceeds 5MB limit.';
            } else {
                $extension = pathinfo($file['name'], PATHINFO_EXTENSION);
                $fileName = 'profile_' . $userId . '_' . time() . '.' . $extension;
                $filePath = $uploadDir . $fileName;

                if (move_uploaded_file($file['tmp_name'], $filePath)) {
                    // Delete old profile picture if exists
                    if ($profilePicturePath && file_exists($profilePicturePath) && $profilePicturePath !== $filePath) {
                        @unlink($profilePicturePath);
                    }
                    $profilePicturePath = $filePath;
                } else {
                    $errors[] = 'Failed to upload profile picture.';
                }
            }
        }

        // Validation
        if (empty($displayName)) {
            $errors[] = 'Display name is required.';
        }

        if (empty($email)) {
            $errors[] = 'Email is required.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Invalid email format.';
        }

        // Check for duplicate email
        if (empty($errors)) {
            $existingUser = $database->fetchOne("SELECT id FROM users WHERE email = ? AND id != ?", [$email, $userId]);
            if ($existingUser) {
                $errors[] = 'Email already taken by another user.';
            }
        }

        if (empty($errors)) {
            try {
                // Check if phone, bio, and nid_number columns exist in the users table
                $hasPhone = $database->fetchOne("SHOW COLUMNS FROM users LIKE 'phone'");
                $hasBio = $database->fetchOne("SHOW COLUMNS FROM users LIKE 'bio'");
                $hasNidNumber = $database->fetchOne("SHOW COLUMNS FROM users LIKE 'nid_number'");

                // Build SQL query based on available columns
                $updateFields = ['display_name = ?', 'email = ?'];
                $params = [$displayName, $email];

                if ($hasPhone) {
                    $updateFields[] = 'phone = ?';
                    $params[] = $phone;
                }

                if ($hasBio) {
                    $updateFields[] = 'bio = ?';
                    $params[] = $bio;
                }

                if ($hasNidNumber) {
                    $updateFields[] = 'nid_number = ?';
                    $params[] = $nidNumber;
                }

                // Check if profile_picture column exists
                $hasProfilePicture = $database->fetchOne("SHOW COLUMNS FROM users LIKE 'profile_picture'");
                if ($hasProfilePicture && $profilePicturePath) {
                    $updateFields[] = 'profile_picture = ?';
                    $params[] = $profilePicturePath;
                }

                $params[] = $userId;
                $sql = "UPDATE users SET " . implode(', ', $updateFields) . " WHERE id = ?";

                $affectedRows = $database->update($sql, $params);

                if ($affectedRows >= 0) {
                    // Update session
                    $_SESSION['display_name'] = $displayName;
                    $_SESSION['email'] = $email;
                    if ($profilePicturePath) {
                        $_SESSION['profile_picture'] = $profilePicturePath;
                    }

                    $success = 'Profile updated successfully!';

                    // Refresh user data
                    $user = $database->fetchOne("SELECT * FROM users WHERE id = ?", [$userId]);
                } else {
                    $errors[] = 'Failed to update profile. Please try again.';
                }
            } catch (Exception $e) {
                error_log('Profile update error: ' . $e->getMessage());
                $errors[] = 'Failed to update profile. Please try again.';
            }
        }
    }
}

// Get user statistics (with error handling)
$stats = [
    'total_reports' => 0,
    'active_disputes' => 0,
    'resolved_reports' => 0,
    'member_since' => date('M Y', strtotime($user['created_at'] ?? 'now'))
];

try {
    // Check if incident_reports table exists
    $tableExists = $database->fetchOne("SHOW TABLES LIKE 'incident_reports'");
    if ($tableExists) {
        $totalReports = $database->fetchOne("SELECT COUNT(*) as count FROM incident_reports WHERE user_id = ?", [$userId]);
        $stats['total_reports'] = $totalReports['count'] ?? 0;

        $resolvedReports = $database->fetchOne("SELECT COUNT(*) as count FROM incident_reports WHERE user_id = ? AND status = 'resolved'", [$userId]);
        $stats['resolved_reports'] = $resolvedReports['count'] ?? 0;
    }
} catch (Exception $e) {
    error_log('Could not fetch incident reports statistics: ' . $e->getMessage());
}

try {
    // Check if disputes table exists
    $tableExists = $database->fetchOne("SHOW TABLES LIKE 'disputes'");
    if ($tableExists) {
        $activeDisputes = $database->fetchOne("SELECT COUNT(*) as count FROM disputes WHERE user_id = ? AND status IN ('pending', 'under_review')", [$userId]);
        $stats['active_disputes'] = $activeDisputes['count'] ?? 0;
    }
} catch (Exception $e) {
    error_log('Could not fetch disputes statistics: ' . $e->getMessage());
}

// Get Safety Score (New Feature)
$safetyStats = [];
try {
    // 1. Calculate fresh score
    // check if procedure exists first to avoid error on old DB
    $procExists = $database->fetchOne("SELECT COUNT(*) as count FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA = DATABASE() AND ROUTINE_NAME = 'calculate_user_safety_score'");
    if (($procExists['count'] ?? 0) > 0) {
        $database->fetchOne("CALL calculate_user_safety_score(?)", [$userId]);
        // 2. Fetch from view
        $safetyStats = $database->fetchOne("SELECT * FROM vw_user_safety_summary WHERE user_id = ?", [$userId]);
    }
} catch (Exception $e) {
    error_log('Error fetching safety stats: ' . $e->getMessage());
}

// Get recent activity (with error handling)
$recentActivity = [];
try {
    // Check if incident_reports table exists first
    $tableExists = $database->fetchOne("SHOW TABLES LIKE 'incident_reports'");
    if ($tableExists) {
        $recentActivity = $database->fetchAll("
            SELECT 'report' as type, title, reported_date as created_at, status
            FROM incident_reports
            WHERE user_id = ?
            ORDER BY reported_date DESC
            LIMIT 3
        ", [$userId]);
    }
} catch (Exception $e) {
    // Silently handle the error - recent activity is optional
    error_log('Could not fetch recent activity: ' . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="<?= $lang ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Profile - SafeSpace Portal</title>
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
                    <a href="dashboard.php" class="flex items-center space-x-2 px-4 h-[52px] rounded-full bg-white/5 hover:bg-white/10 border border-white/10 hover:border-cyan-500/50 transition-all duration-300 group">
                        <i data-lucide="arrow-left" class="w-4 h-4 text-white/70 group-hover:text-cyan-400 transition-colors duration-300"></i>
                        <span class="text-sm font-medium text-white/90 group-hover:text-white transition-colors duration-300 hidden sm:inline">Back</span>
                    </a>
                </div>
            </div>
        </div>
    </header>

    <main class="dashboard-content">
        <div class="max-w-7xl mx-auto">
            <!-- Compact Profile Header -->
            <section class="mb-6 animate-slide-up relative">
                <div class="absolute inset-0 bg-gradient-to-b from-cyan-500/5 to-transparent rounded-3xl -z-10 blur-3xl"></div>
                <div class="flex flex-col md:flex-row items-center gap-6 p-4">
                    <div class="relative group">
                        <div class="w-24 h-24 bg-gradient-to-r from-cyan-400 via-blue-500 to-purple-600 rounded-2xl flex items-center justify-center shadow-xl ring-2 ring-white/10 overflow-hidden">
                            <?php
                            $profilePicture = $user['profile_picture'] ?? '';
                            if ($profilePicture && file_exists($profilePicture)):
                            ?>
                                <img src="<?= htmlspecialchars($profilePicture) ?>" alt="Profile Picture" class="w-full h-full object-cover" id="profile-avatar-img">
                            <?php else: ?>
                                <i data-lucide="user" class="w-12 h-12 text-white" id="profile-avatar-icon"></i>
                            <?php endif; ?>
                        </div>
                        <label for="profile-picture-input" class="absolute -bottom-2 -right-2 w-8 h-8 bg-cyan-500 hover:bg-cyan-400 rounded-lg flex items-center justify-center shadow-lg cursor-pointer transition-all hover:scale-110 hover:rotate-3 border-2 border-slate-900">
                            <i data-lucide="camera" class="w-4 h-4 text-white"></i>
                            <input type="file" id="profile-picture-input" name="profile_picture" accept="image/*" class="hidden" onchange="previewProfilePicture(this)">
                        </label>
                    </div>

                    <div class="text-center md:text-left flex-1">
                        <h1 class="text-3xl font-bold mb-1 text-white tracking-tight">
                            <?= htmlspecialchars($user['display_name'] ?? 'User') ?>
                        </h1>
                        <p class="text-sm text-slate-400 mb-3 max-w-2xl">
                            <?= htmlspecialchars($user['bio'] ?? 'Manage your personal information and view your activity statistics') ?>
                        </p>
                        <div class="flex flex-wrap justify-center md:justify-start gap-2">
                            <span class="px-2.5 py-1 rounded-md bg-white/5 border border-white/10 text-xs text-slate-300 flex items-center">
                                <i data-lucide="mail" class="w-3 h-3 mr-1.5 text-cyan-400"></i>
                                <?= htmlspecialchars($user['email']) ?>
                            </span>
                            <?php if (!empty($user['phone'])): ?>
                                <span class="px-2.5 py-1 rounded-md bg-white/5 border border-white/10 text-xs text-slate-300 flex items-center">
                                    <i data-lucide="phone" class="w-3 h-3 mr-1.5 text-purple-400"></i>
                                    <?= htmlspecialchars($user['phone']) ?>
                                </span>
                            <?php endif; ?>
                            <span class="px-2.5 py-1 rounded-md bg-white/5 border border-white/10 text-xs text-slate-300 flex items-center">
                                <i data-lucide="calendar" class="w-3 h-3 mr-1.5 text-blue-400"></i>
                                Since <?= $stats['member_since'] ?>
                            </span>
                        </div>
                    </div>
                </div>
            </section>

            <!-- Compact Stats Row -->
            <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-6 animate-slide-up" style="animation-delay: 0.1s;">
                <div class="card p-4 bg-white/5 border border-white/10 hover:bg-white/10 transition-colors group">
                    <div class="flex justify-between items-start mb-1">
                        <div class="p-1.5 bg-cyan-500/20 rounded-md text-cyan-400 group-hover:text-cyan-300 transition-colors">
                            <i data-lucide="file-text" class="w-4 h-4"></i>
                        </div>
                        <span class="text-[10px] font-medium text-slate-400 bg-white/5 px-1.5 py-0.5 rounded uppercase tracking-wider">Total</span>
                    </div>
                    <div class="text-xl font-bold text-white mb-0.5"><?= number_format($stats['total_reports']) ?></div>
                    <div class="text-[11px] text-slate-400">Reports Submitted</div>
                </div>

                <div class="card p-4 bg-white/5 border border-white/10 hover:bg-white/10 transition-colors group">
                    <div class="flex justify-between items-start mb-1">
                        <div class="p-1.5 bg-green-500/20 rounded-md text-green-400 group-hover:text-green-300 transition-colors">
                            <i data-lucide="check-circle" class="w-4 h-4"></i>
                        </div>
                        <span class="text-[10px] font-medium text-slate-400 bg-white/5 px-1.5 py-0.5 rounded uppercase tracking-wider">Success</span>
                    </div>
                    <div class="text-xl font-bold text-white mb-0.5"><?= number_format($stats['resolved_reports']) ?></div>
                    <div class="text-[11px] text-slate-400">Cases Resolved</div>
                </div>

                <div class="card p-4 bg-white/5 border border-white/10 hover:bg-white/10 transition-colors group">
                    <div class="flex justify-between items-start mb-1">
                        <div class="p-1.5 bg-orange-500/20 rounded-md text-orange-400 group-hover:text-orange-300 transition-colors">
                            <i data-lucide="gavel" class="w-4 h-4"></i>
                        </div>
                        <span class="text-[10px] font-medium text-slate-400 bg-white/5 px-1.5 py-0.5 rounded uppercase tracking-wider">Active</span>
                    </div>
                    <div class="text-xl font-bold text-white mb-0.5"><?= number_format($stats['active_disputes']) ?></div>
                    <div class="text-[11px] text-slate-400">Ongoing Disputes</div>
                </div>

                <div class="card p-4 bg-white/5 border border-white/10 hover:bg-white/10 transition-colors group">
                    <div class="flex justify-between items-start mb-1">
                        <div class="p-1.5 bg-purple-500/20 rounded-md text-purple-400 group-hover:text-purple-300 transition-colors">
                            <i data-lucide="shield-check" class="w-4 h-4"></i>
                        </div>
                        <span class="text-[10px] font-medium text-slate-400 bg-white/5 px-1.5 py-0.5 rounded uppercase tracking-wider">Score</span>
                    </div>
                    <div class="text-xl font-bold text-white mb-0.5"><?= number_format($safetyStats['safety_score'] ?? 5.0, 1) ?></div>
                    <div class="text-[11px] text-slate-400">
                        <?php if (isset($safetyStats['community_rank'])): ?>
                            Rank #<?= $safetyStats['community_rank'] ?> (Top <?= number_format(100 - ($safetyStats['percentile'] ?? 0), 0) ?>%)
                        <?php else: ?>
                            Community Member
                        <?php endif; ?>
                    </div>
                </div>
            </div>

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

            <!-- Main Grid -->
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                <!-- Left Column: Edit Profile -->
                <div class="space-y-6 animate-slide-up" style="animation-delay: 0.2s;">
                    <div class="card h-full">
                        <div class="flex items-center justify-between p-5 border-b border-white/10">
                            <div class="flex items-center space-x-3">
                                <div class="w-8 h-8 bg-gradient-to-r from-blue-500 to-cyan-600 rounded-lg flex items-center justify-center shadow-lg">
                                    <i data-lucide="user-cog" class="w-4 h-4 text-white"></i>
                                </div>
                                <div>
                                    <h2 class="text-base font-bold text-white">Edit Profile</h2>
                                    <p class="text-xs text-slate-400">Update your personal details</p>
                                </div>
                            </div>
                        </div>

                        <div class="p-5">
                            <form method="post" class="space-y-5" id="profileForm" enctype="multipart/form-data">
                                <input type="hidden" name="action" value="update_profile">

                                <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
                                    <div class="space-y-1.5">
                                        <label class="form-label text-xs">
                                            <i data-lucide="user" class="w-3 h-3 mr-1.5 text-cyan-400 inline"></i>
                                            Display Name
                                        </label>
                                        <input type="text" name="display_name" class="form-input text-sm py-2" value="<?= htmlspecialchars($user['display_name'] ?? '') ?>" placeholder="Enter your display name" required>
                                    </div>

                                    <div class="space-y-1.5">
                                        <label class="form-label text-xs">
                                            <i data-lucide="mail" class="w-3 h-3 mr-1.5 text-blue-400 inline"></i>
                                            Email Address
                                        </label>
                                        <input type="email" name="email" class="form-input text-sm py-2" value="<?= htmlspecialchars($user['email'] ?? '') ?>" placeholder="your.email@example.com" required>
                                    </div>
                                </div>

                                <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
                                    <div class="space-y-1.5">
                                        <label class="form-label text-xs">
                                            <i data-lucide="phone" class="w-3 h-3 mr-1.5 text-purple-400 inline"></i>
                                            Phone Number
                                        </label>
                                        <input type="tel" name="phone" class="form-input text-sm py-2" value="<?= htmlspecialchars($user['phone'] ?? '') ?>" placeholder="+880 1XXX XXX XXX">
                                    </div>

                                    <div class="space-y-1.5">
                                        <label class="form-label text-xs">
                                            <i data-lucide="id-card" class="w-3 h-3 mr-1.5 text-indigo-400 inline"></i>
                                            National ID (NID)
                                        </label>
                                        <input type="text" name="nid_number" class="form-input text-sm py-2" value="<?= htmlspecialchars($user['nid_number'] ?? '') ?>" placeholder="Enter your National ID number" pattern="[0-9]{10,17}" title="NID should be 10-17 digits">
                                    </div>
                                </div>

                                <div class="space-y-1.5">
                                    <label class="form-label text-xs">
                                        <i data-lucide="message-square" class="w-3 h-3 mr-1.5 text-teal-400 inline"></i>
                                        Bio
                                    </label>
                                    <textarea name="bio" rows="3" class="form-textarea resize-none text-sm" placeholder="Tell us a bit about yourself..."><?= htmlspecialchars($user['bio'] ?? '') ?></textarea>
                                </div>

                                <div class="pt-4 border-t border-white/10 flex items-center justify-end space-x-3">
                                    <a href="settings.php" class="btn btn-outline btn-sm">
                                        <i data-lucide="settings" class="w-3 h-3"></i>
                                        <span>Settings</span>
                                    </a>
                                    <button type="submit" class="btn btn-primary btn-sm" id="updateBtn">
                                        <i data-lucide="save" class="w-3 h-3"></i>
                                        <span>Save Changes</span>
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>

                <!-- Right Column: Unified Sidebar Card -->
                <div class="space-y-6 animate-slide-up" style="animation-delay: 0.3s;">
                    <div class="card h-full flex flex-col">
                        <!-- Quick Actions Header -->
                        <div class="p-3 border-b border-white/10 bg-white/5">
                            <div class="flex items-center justify-between gap-2">
                                <a href="change_password.php" class="flex-1 flex items-center justify-center py-1.5 px-2 rounded-md hover:bg-white/10 transition-colors group">
                                    <i data-lucide="lock" class="w-3.5 h-3.5 text-cyan-400 mr-1.5"></i>
                                    <span class="text-[11px] font-medium text-slate-300 group-hover:text-white">Password</span>
                                </a>
                                <div class="w-px h-4 bg-white/10"></div>
                                <a href="settings.php" class="flex-1 flex items-center justify-center py-1.5 px-2 rounded-md hover:bg-white/10 transition-colors group">
                                    <i data-lucide="settings" class="w-3.5 h-3.5 text-blue-400 mr-1.5"></i>
                                    <span class="text-[11px] font-medium text-slate-300 group-hover:text-white">Settings</span>
                                </a>
                                <div class="w-px h-4 bg-white/10"></div>
                                <a href="deactivate_account.php" class="flex-1 flex items-center justify-center py-1.5 px-2 rounded-md hover:bg-white/10 transition-colors group">
                                    <i data-lucide="alert-triangle" class="w-3.5 h-3.5 text-red-400 mr-1.5"></i>
                                    <span class="text-[11px] font-medium text-slate-300 group-hover:text-white">Deactivate</span>
                                </a>
                            </div>
                        </div>

                        <!-- Content Body -->
                        <div class="p-4 flex-1 grid grid-cols-1 gap-6">
                            <!-- Contacts -->
                            <?php
                            // Get emergency contacts
                            $emergencyContacts = $models->getUserEmergencyContacts($userId);
                            ?>
                            <div class="flex flex-col">
                                <div class="flex items-center justify-between mb-3">
                                    <h3 class="font-bold text-white flex items-center text-sm">
                                        <i data-lucide="users" class="w-4 h-4 text-pink-400 mr-2"></i>
                                        Contacts
                                    </h3>
                                    <a href="emergency_contacts.php" class="text-[10px] font-bold uppercase tracking-wider text-cyan-400 hover:text-cyan-300 bg-cyan-500/10 px-2 py-1 rounded hover:bg-cyan-500/20 transition-colors">View All</a>
                                </div>

                                <?php if (empty($emergencyContacts)): ?>
                                    <div class="flex-1 flex flex-col items-center justify-center py-4 bg-white/5 rounded-lg border border-dashed border-white/10">
                                        <p class="text-xs text-slate-400 mb-2">No contacts added</p>
                                        <a href="emergency_contacts.php" class="btn btn-xs btn-primary inline-flex">
                                            <i data-lucide="plus" class="w-3 h-3 mr-1"></i> Add
                                        </a>
                                    </div>
                                <?php else: ?>
                                    <div class="space-y-2">
                                        <?php foreach (array_slice($emergencyContacts, 0, 3) as $contact): ?>
                                            <div class="flex items-center justify-between p-2 rounded-lg bg-white/5 border border-white/5">
                                                <div class="flex items-center space-x-2 overflow-hidden">
                                                    <div class="w-6 h-6 bg-pink-500/20 rounded-md flex items-center justify-center flex-shrink-0">
                                                        <i data-lucide="user" class="w-3 h-3 text-pink-400"></i>
                                                    </div>
                                                    <div class="min-w-0">
                                                        <p class="text-xs font-medium text-white truncate"><?= htmlspecialchars($contact['contact_name']) ?></p>
                                                        <p class="text-[10px] text-slate-400 truncate"><?= htmlspecialchars($contact['phone_number']) ?></p>
                                                    </div>
                                                </div>
                                                <div class="w-1.5 h-1.5 rounded-full <?= ($contact['is_active'] ?? 1) ? 'bg-green-500 shadow-[0_0_8px_rgba(34,197,94,0.5)]' : 'bg-slate-600' ?>"></div>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                            </div>

                            <!-- Recent Activity -->
                            <?php if (!empty($recentActivity)): ?>
                            <div class="flex flex-col pt-4 border-t border-white/10">
                                <div class="flex items-center justify-between mb-3">
                                    <h3 class="font-bold text-white flex items-center text-sm">
                                        <i data-lucide="activity" class="w-4 h-4 text-green-400 mr-2"></i>
                                        Activity
                                    </h3>
                                    <a href="my_reports.php" class="text-[10px] font-bold uppercase tracking-wider text-cyan-400 hover:text-cyan-300 bg-cyan-500/10 px-2 py-1 rounded hover:bg-cyan-500/20 transition-colors">View All</a>
                                </div>
                                <div class="space-y-3 relative">
                                    <div class="absolute left-2 top-2 bottom-2 w-0.5 bg-white/10"></div>
                                    <?php foreach ($recentActivity as $activity): ?>
                                        <div class="relative pl-5">
                                            <div class="absolute left-0.5 top-1.5 w-3 h-3 rounded-full border-2 border-slate-900 bg-slate-700 z-10"></div>
                                            <p class="text-xs font-medium text-white truncate"><?= htmlspecialchars($activity['title']) ?></p>
                                            <p class="text-[10px] text-slate-400 mt-0.5">
                                                <?= date('M j', strtotime($activity['created_at'])) ?> •
                                                <span class="<?= $activity['status'] === 'resolved' ? 'text-green-400' : 'text-orange-400' ?>">
                                                    <?= ucfirst($activity['status']) ?>
                                                </span>
                                            </p>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </main>

    <!-- Scripts -->
    <script src="https://unpkg.com/lucide@latest/dist/umd/lucide.js"></script>
    <script src="dashboard-enhanced.js"></script>

    <script>
        // Initialize Lucide icons
        lucide.createIcons();

        // Profile picture preview
        function previewProfilePicture(input) {
            const img = document.getElementById('profile-avatar-img');
            const icon = document.getElementById('profile-avatar-icon');

            if (input.files && input.files[0]) {
                const reader = new FileReader();
                reader.onload = function(e) {
                    if (img) {
                        img.src = e.target.result;
                        img.style.display = 'block';
                    }
                    if (icon) {
                        icon.style.display = 'none';
                    }
                };
                reader.readAsDataURL(input.files[0]);
            }
        }

        // Enhanced form handling
        const profileForm = document.getElementById('profileForm');
        const updateBtn = document.getElementById('updateBtn');

        if (profileForm) {
            profileForm.addEventListener('submit', function(e) {
                updateBtn.innerHTML = '<i data-lucide="loader-2" class="w-3 h-3 mr-2 animate-spin"></i>Saving...';
                updateBtn.disabled = true;
                updateBtn.classList.add('opacity-75', 'cursor-not-allowed');
                lucide.createIcons();
            });
        }

        // Auto-hide messages
        setTimeout(() => {
            const messages = document.querySelectorAll('.card.border-l-4');
            messages.forEach((msg, index) => {
                setTimeout(() => {
                    msg.style.transition = 'all 0.8s cubic-bezier(0.4, 0, 0.2, 1)';
                    msg.style.opacity = '0';
                    msg.style.transform = 'translateY(-30px) scale(0.95)';
                    setTimeout(() => msg.remove(), 800);
                }, 6000 + (index * 300));
            });
        }, 1000);
    </script>
</body>
</html>
