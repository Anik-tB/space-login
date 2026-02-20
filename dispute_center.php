<?php
session_start();
$lang = $_SESSION['lang'] ?? 'en';
$lang_file = $lang === 'bn' ? 'lang_bn.php' : 'lang_en.php';
$L = include($lang_file);

// Include database handler
require_once 'includes/Database.php';

// Initialize database
$database = new Database();

// Get user ID from session (you'll need to implement proper user session management)
$userId = $_SESSION['user_id'] ?? 1; // Default for demo

// Handle form submission
if (isset($_POST['action']) && $_POST['action'] === 'file_dispute') {
    $disputeData = [
        'user_id' => $userId,
        'report_id' => $_POST['report_id'],
        'reason' => $_POST['reason'],
        'description' => $_POST['description']
    ];

    $disputeId = createDispute($database, $disputeData);

    if ($disputeId) {
        $message = 'Dispute filed successfully! We will review your appeal within 48 hours.';
        $messageType = 'success';
    } else {
        $error = 'Failed to file dispute. Please try again.';
    }
}

// Get user's disputes
$disputes = getUserDisputes($database, $userId);

// Get reports that can be disputed (reports where user is mentioned)
$disputableReports = getDisputableReports($database, $userId);

// Helper functions for database operations
function createDispute($database, $data) {
    try {
        $sql = "INSERT INTO disputes (user_id, report_id, reason, description, status)
                VALUES (?, ?, ?, ?, 'pending')";
        return $database->insert($sql, [
            $data['user_id'],
            $data['report_id'],
            $data['reason'],
            $data['description']
        ]);
    } catch (Exception $e) {
        error_log("Error creating dispute: " . $e->getMessage());
        return false;
    }
}

function getUserDisputes($database, $userId) {
    try {
        $sql = "SELECT * FROM disputes WHERE user_id = ? ORDER BY created_at DESC";
        return $database->fetchAll($sql, [$userId]);
    } catch (Exception $e) {
        error_log("Error getting user disputes: " . $e->getMessage());
        return [];
    }
}

function getDisputableReports($database, $userId) {
    try {
        // For now, we'll get reports that the user has filed (as a placeholder)
        // In a real system, you'd need to add an accused_user_id field to the incident_reports table
        // or create a separate table to track who is accused in each report
        $sql = "SELECT id, title, category, reported_date as created_at FROM incident_reports
                WHERE user_id = ? AND status IN ('pending', 'under_review', 'investigating')
                ORDER BY reported_date DESC";
        return $database->fetchAll($sql, [$userId]);
    } catch (Exception $e) {
        error_log("Error getting disputable reports: " . $e->getMessage());
        return [];
    }
}
?>
<!DOCTYPE html>
<html lang="<?= $lang ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dispute Center - SafeSpace Portal</title>

    <!-- Favicon -->
    <link rel="icon" type="image/svg+xml" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><text y='.9em' font-size='90'>🛡️</text></svg>">

    <!-- Modern Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&family=Poppins:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">

    <!-- Modern Icons -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/lucide/0.263.1/lucide.min.css">

    <!-- TailwindCSS -->
    <script src="https://cdn.tailwindcss.com"></script>

    <!-- Design System -->
    <link rel="stylesheet" href="design-system.css">
    <link rel="stylesheet" href="dashboard-styles.css">

    <!-- Custom Styles for Enhanced Animations -->
    <style>
        /* Liquid Glass Theme Enhancements */
        :root {
            --glass-bg: rgba(255, 255, 255, 0.08);
            --glass-border: rgba(255, 255, 255, 0.15);
            --glass-shadow: 0 8px 32px rgba(0, 0, 0, 0.3);
            --liquid-gradient: linear-gradient(135deg, rgba(255, 255, 255, 0.1), rgba(255, 255, 255, 0.05));
            --liquid-border: linear-gradient(135deg, rgba(255, 255, 255, 0.2), rgba(255, 255, 255, 0.1));
        }

        /* Enhanced Liquid Glass Effect */
        .liquid-glass {
            background: var(--liquid-gradient);
            backdrop-filter: blur(25px) saturate(180%);
            border: 1px solid var(--glass-border);
            box-shadow: var(--glass-shadow);
            position: relative;
            overflow: hidden;
        }

        .liquid-glass::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.1), transparent);
            transition: left 0.8s ease;
        }

        .liquid-glass:hover::before {
            left: 100%;
        }

        /* Floating Particles Background */
        .particles-bg {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            pointer-events: none;
            z-index: -1;
            overflow: hidden;
        }

        .particle {
            position: absolute;
            background: rgba(255, 255, 255, 0.1);
            border-radius: 50%;
            animation: float 15s ease-in-out infinite;
        }

        .particle:nth-child(1) { width: 4px; height: 4px; left: 10%; animation-delay: 0s; }
        .particle:nth-child(2) { width: 6px; height: 6px; left: 20%; animation-delay: 1s; }
        .particle:nth-child(3) { width: 3px; height: 3px; left: 30%; animation-delay: 2s; }
        .particle:nth-child(4) { width: 5px; height: 5px; left: 40%; animation-delay: 3s; }
        .particle:nth-child(5) { width: 4px; height: 4px; left: 50%; animation-delay: 4s; }
        .particle:nth-child(6) { width: 6px; height: 6px; left: 60%; animation-delay: 5s; }
        .particle:nth-child(7) { width: 3px; height: 3px; left: 70%; animation-delay: 6s; }
        .particle:nth-child(8) { width: 5px; height: 5px; left: 80%; animation-delay: 7s; }
        .particle:nth-child(9) { width: 4px; height: 4px; left: 90%; animation-delay: 8s; }

        @keyframes float {
            0%, 100% { transform: translateY(100vh) rotate(0deg); opacity: 0; }
            10% { opacity: 0.5; }
            90% { opacity: 0.5; }
            100% { transform: translateY(-10px) rotate(36deg); opacity: 0; }
        }

        /* Liquid Wave Effect */
        .liquid-wave {
            position: relative;
            overflow: hidden;
        }

        .liquid-wave::after {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: linear-gradient(45deg, transparent 30%, rgba(255, 255, 255, 0.1) 50%, transparent 70%);
            animation: wave 3s ease-in-out infinite;
        }

        @keyframes wave {
            0%, 100% { transform: translateX(-100%); }
            50% { transform: translateX(100%); }
        }

        /* Enhanced Card Glass Effect */
        .card-glass {
            background: rgba(255, 255, 255, 0.08);
            backdrop-filter: blur(20px) saturate(180%);
            border: 1px solid rgba(255, 255, 255, 0.15);
            box-shadow:
                0 8px 32px rgba(0, 0, 0, 0.3),
                inset 0 1px 0 rgba(255, 255, 255, 0.1);
            position: relative;
            overflow: hidden;
        }

        .card-glass::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 1px;
            background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.3), transparent);
        }

        /* Liquid Button Effect */
        .btn-liquid {
            position: relative;
            overflow: hidden;
            background: linear-gradient(135deg, rgba(255, 255, 255, 0.1), rgba(255, 255, 255, 0.05));
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255, 255, 255, 0.2);
            transition: all 0.3s ease;
        }

        .btn-liquid::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.2), transparent);
            transition: left 0.5s ease;
        }

        .btn-liquid:hover::before {
            left: 100%;
        }

        .btn-liquid:hover {
            transform: translateY(-0.3px);
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.3);
        }

        /* Smooth animations */
        .animate-fade-in {
            animation: fadeIn 0.8s ease-out;
        }

        .animate-slide-up {
            animation: slideUp 0.8s ease-out;
        }

        .animate-scale-in {
            animation: scaleIn 0.6s ease-out;
        }

        .animate-bounce-in {
            animation: bounceIn 0.8s ease-out;
        }

        .animate-slide-in-left {
            animation: slideInLeft 0.8s ease-out;
        }

        .animate-slide-in-right {
            animation: slideInRight 0.8s ease-out;
        }

        /* Liquid Morphing Animation */
        .liquid-morph {
            animation: liquidMorph 4s ease-in-out infinite;
        }

        @keyframes liquidMorph {
            0%, 100% { border-radius: 12px; }
            25% { border-radius: 12.3px 11.8px 12.3px 11.8px; }
            50% { border-radius: 11.8px 12.3px 11.8px 12.3px; }
            75% { border-radius: 12.3px 11.8px 11.8px 12.3px; }
        }

        /* Hover effects */
        .card-hover {
            transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
        }

        .card-hover:hover {
            transform: translateY(-1px) scale(1.005);
            box-shadow:
                0 25px 50px rgba(0, 0, 0, 0.4),
                inset 0 1px 0 rgba(255, 255, 255, 0.2);
        }

        /* Button animations */
        .btn-animate {
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }

        .btn-animate:hover {
            transform: translateY(-0.3px) scale(1.01);
        }

        .btn-animate:active {
            transform: translateY(0) scale(0.98);
        }

        /* Loading states */
        .loading {
            opacity: 0.7;
            pointer-events: none;
        }

        /* Keyframe animations */
        @keyframes fadeIn {
            from {
                opacity: 0;
                transform: translateY(2px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        @keyframes slideUp {
            from {
                opacity: 0;
                transform: translateY(4px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        @keyframes scaleIn {
            from {
                opacity: 0;
                transform: scale(0.99);
            }
            to {
                opacity: 1;
                transform: scale(1);
            }
        }

        @keyframes bounceIn {
            0% {
                opacity: 0;
                transform: scale(0.97);
            }
            50% {
                opacity: 1;
                transform: scale(1.005);
            }
            70% {
                transform: scale(0.99);
            }
            100% {
                opacity: 1;
                transform: scale(1);
            }
        }

        @keyframes slideInLeft {
            from {
                opacity: 0;
                transform: translateX(-4px);
            }
            to {
                opacity: 1;
                transform: translateX(0);
            }
        }

        @keyframes slideInRight {
            from {
                opacity: 0;
                transform: translateX(4px);
            }
            to {
                opacity: 1;
                transform: translateX(0);
            }
        }

        /* Professional enhancements */
        .glass-effect {
            background: rgba(255, 255, 255, 0.05);
            backdrop-filter: blur(20px);
            border: 1px solid rgba(255, 255, 255, 0.1);
        }

        .gradient-border {
            position: relative;
            background: linear-gradient(135deg, rgba(255, 255, 255, 0.1), rgba(255, 255, 255, 0.05));
            border-radius: 12px;
        }

        .gradient-border::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            border-radius: 12px;
            padding: 1px;
            background: linear-gradient(135deg, rgba(255, 255, 255, 0.2), rgba(255, 255, 255, 0.1));
            mask: linear-gradient(#fff 0 0) content-box, linear-gradient(#fff 0 0);
            mask-composite: exclude;
        }

        /* Enhanced Status badges with liquid effect */
        .status-badge {
            padding: 0.25rem 0.75rem;
            border-radius: 9999px;
            font-size: 0.75rem;
            font-weight: 500;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            position: relative;
            overflow: hidden;
        }

        .status-badge::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.2), transparent);
            transition: left 0.6s ease;
        }

        .status-badge:hover::before {
            left: 100%;
        }

        .status-pending {
            background: rgba(245, 158, 11, 0.2);
            color: #fbbf24;
            border: 1px solid rgba(245, 158, 11, 0.3);
            backdrop-filter: blur(10px);
        }

        .status-approved {
            background: rgba(34, 197, 94, 0.2);
            color: #4ade80;
            border: 1px solid rgba(34, 197, 94, 0.3);
            backdrop-filter: blur(10px);
        }

        .status-rejected {
            background: rgba(239, 68, 68, 0.2);
            color: #f87171;
            border: 1px solid rgba(239, 68, 68, 0.3);
            backdrop-filter: blur(10px);
        }

        .status-under-review {
            background: rgba(59, 130, 246, 0.2);
            color: #60a5fa;
            border: 1px solid rgba(59, 130, 246, 0.3);
            backdrop-filter: blur(10px);
        }

        /* Liquid Form Inputs */
        .form-input-liquid {
            background: rgba(255, 255, 255, 0.08);
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255, 255, 255, 0.2);
            border-radius: 8px;
            padding: 12px 16px;
            color: white;
            transition: all 0.3s ease;
        }

        .form-input-liquid:focus {
            outline: none;
            border-color: rgba(255, 255, 255, 0.4);
            box-shadow: 0 0 0 3px rgba(255, 255, 255, 0.1);
            background: rgba(255, 255, 255, 0.12);
        }

        .form-input-liquid::placeholder {
            color: rgba(255, 255, 255, 0.6);
        }

        /* Liquid Background Gradient */
        .liquid-bg {
            background: linear-gradient(135deg,
                rgba(15, 23, 42, 0.95) 0%,
                rgba(88, 28, 135, 0.9) 25%,
                rgba(15, 23, 42, 0.95) 50%,
                rgba(88, 28, 135, 0.9) 75%,
                rgba(15, 23, 42, 0.95) 100%);
            background-size: 400% 400%;
            animation: liquidGradient 8s ease-in-out infinite;
        }

        @keyframes liquidGradient {
            0%, 100% { background-position: 0% 50%; }
            50% { background-position: 100% 50%; }
        }
    </style>

    <script>
        tailwind.config = {
            theme: {
                extend: {
                    colors: {
                        primary: {
                            50: '#f0f9ff',
                            100: '#e0f2fe',
                            200: '#bae6fd',
                            300: '#7dd3fc',
                            400: '#38bdf8',
                            500: '#0ea5e9',
                            600: '#0284c7',
                            700: '#0369a1',
                            800: '#075985',
                            900: '#0c4a6e',
                        },
                        secondary: {
                            50: '#fdf4ff',
                            100: '#fae8ff',
                            200: '#f5d0fe',
                            300: '#f0abfc',
                            400: '#e879f9',
                            500: '#d946ef',
                            600: '#c026d3',
                            700: '#a21caf',
                            800: '#86198f',
                            900: '#701a75',
                        }
                    },
                    animation: {
                        'fade-in': 'fadeIn 0.8s ease-out',
                        'slide-up': 'slideUp 0.8s ease-out',
                        'scale-in': 'scaleIn 0.6s ease-out',
                        'bounce-in': 'bounceIn 0.8s ease-out',
                    }
                }
            }
        }
</script>
</head>
<body class="min-h-screen">
    <!-- Background animation removed -->

    <!-- Header -->
    <header class="fixed top-0 left-0 right-0 z-50 liquid-glass border-b border-white/20">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="flex items-center justify-between h-16">
                <!-- Logo -->
                <div class="flex items-center space-x-4">
                    <a href="dashboard.php" class="flex items-center space-x-2">
                        <div class="w-8 h-8 bg-gradient-to-r from-primary-500 to-secondary-500 rounded-lg flex items-center justify-center">
                            <i data-lucide="shield" class="w-5 h-5 text-white"></i>
                        </div>
                        <span class="text-xl font-bold text-white">SafeSpace</span>
                    </a>
                </div>

                <!-- Navigation -->
                <nav class="hidden md:flex items-center space-x-6">
                    <a href="dashboard.php" class="text-white/70 hover:text-white transition-colors duration-200">Dashboard</a>
                    <a href="my_reports.php" class="text-white/70 hover:text-white transition-colors duration-200">My Reports</a>
                    <a href="dispute_center.php" class="text-white font-medium">Disputes</a>
                    <a href="safety_resources.php" class="text-white/70 hover:text-white transition-colors duration-200">Resources</a>
                    <?php
                    // Add admin link if user is admin
                    if ($userId) {
                        try {
                            $adminCheck = $database->fetchOne("SELECT email FROM users WHERE id = ?", [$userId]);
                            if ($adminCheck && (strpos(strtolower($adminCheck['email'] ?? ''), 'admin') !== false || strtolower($adminCheck['email'] ?? '') === 'admin@safespace.com')) {
                                echo '<a href="admin_dashboard.php" class="text-white/70 hover:text-white transition-colors duration-200 flex items-center gap-1">
                                    <i data-lucide="shield-check" class="w-4 h-4"></i>
                                    <span>Admin</span>
                                </a>';
                            }
                        } catch (Exception $e) {}
                    }
                    ?>
                </nav>

                <!-- User Menu -->
                <div class="flex items-center space-x-4">
                    <a href="dashboard.php" class="btn btn-ghost">
                        <i data-lucide="arrow-left" class="w-4 h-4"></i>
                        Back to Dashboard
                    </a>
                </div>
            </div>
        </div>
    </header>

    <!-- Main Content -->
    <main class="pt-20 pb-8">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <!-- Header Section -->
            <section class="mb-8 animate-fade-in">
                <div class="text-center">
                    <h1 class="heading-1 mb-4">Dispute Center</h1>
                    <p class="body-large max-w-2xl mx-auto" style="color: var(--text-secondary);">
                        If you believe a report filed against you is false or incorrect, you can file a dispute here.
                        We take all appeals seriously and will review them within 48 hours.
                    </p>
                </div>
            </section>

            <!-- Messages -->
            <?php if (isset($message)): ?>
                <div class="mb-6 card card-glass border-l-4 border-green-500">
                    <div class="card-body">
                        <div class="flex items-center">
                            <i data-lucide="check-circle" class="w-5 h-5 text-green-500 mr-3"></i>
                            <p class="text-green-300"><?= $message ?></p>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <?php if (isset($error)): ?>
                <div class="mb-6 card card-glass border-l-4 border-red-500">
                    <div class="card-body">
                        <div class="flex items-center">
                            <i data-lucide="alert-circle" class="w-5 h-5 text-red-500 mr-3"></i>
                            <p class="text-red-300"><?= $error ?></p>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Statistics Cards -->
            <section class="mb-8">
                <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                    <div class="card card-glass liquid-glass liquid-morph card-hover animate-slide-up" style="animation-delay: 0.1s;">
                        <div class="card-body">
                            <div class="flex items-center justify-between">
                                <div>
                                    <p class="label-medium" style="color: var(--text-tertiary);">Total Disputes</p>
                                    <p class="heading-3" style="color: var(--text-primary);"><?= count($disputes) ?></p>
                                    <p class="text-sm text-green-400 mt-1">Active cases</p>
                                </div>
                                <div class="w-12 h-12 bg-gradient-to-r from-secondary-500 to-secondary-600 rounded-xl flex items-center justify-center shadow-lg liquid-wave">
                                    <i data-lucide="gavel" class="w-6 h-6 text-white"></i>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="card card-glass liquid-glass liquid-morph card-hover animate-slide-up" style="animation-delay: 0.2s;">
                        <div class="card-body">
                            <div class="flex items-center justify-between">
                                <div>
                                    <p class="label-medium" style="color: var(--text-tertiary);">Pending Review</p>
                                    <p class="heading-3" style="color: var(--text-primary);">
                                        <?= count(array_filter($disputes, fn($d) => $d['status'] === 'pending')) ?>
                                    </p>
                                    <p class="text-sm text-yellow-400 mt-1">Under investigation</p>
                                </div>
                                <div class="w-12 h-12 bg-gradient-to-r from-yellow-500 to-orange-500 rounded-xl flex items-center justify-center shadow-lg liquid-wave">
                                    <i data-lucide="clock" class="w-6 h-6 text-white"></i>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="card card-glass liquid-glass liquid-morph card-hover animate-slide-up" style="animation-delay: 0.3s;">
                        <div class="card-body">
                            <div class="flex items-center justify-between">
                                <div>
                                    <p class="label-medium" style="color: var(--text-tertiary);">Approved</p>
                                    <p class="heading-3" style="color: var(--text-primary);">
                                        <?= count(array_filter($disputes, fn($d) => $d['status'] === 'approved')) ?>
                                    </p>
                                    <p class="text-sm text-green-400 mt-1">Successfully resolved</p>
                                </div>
                                <div class="w-12 h-12 bg-gradient-to-r from-green-500 to-emerald-600 rounded-xl flex items-center justify-center shadow-lg liquid-wave">
                                    <i data-lucide="check-circle" class="w-6 h-6 text-white"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </section>

            <!-- File New Dispute -->
            <section class="mb-8">
                <div class="card card-glass liquid-glass liquid-morph card-hover animate-slide-up" style="animation-delay: 0.2s;">
                    <div class="card-body">
                        <div class="flex items-center mb-6">
                            <div class="w-10 h-10 bg-gradient-to-r from-blue-500 to-purple-600 rounded-xl flex items-center justify-center mr-4">
                                <i data-lucide="file-text" class="w-5 h-5 text-white"></i>
                            </div>
                            <h2 class="heading-3" style="color: var(--text-primary);">File New Dispute</h2>
                        </div>

                        <?php if (empty($disputableReports)): ?>
                            <div class="text-center py-12 animate-bounce-in">
                                <div class="w-20 h-20 bg-gradient-to-r from-green-500 to-emerald-600 rounded-full flex items-center justify-center mx-auto mb-6 shadow-lg">
                                    <i data-lucide="check" class="w-10 h-10 text-white"></i>
                                </div>
                                <h3 class="heading-4 mb-3" style="color: var(--text-primary);">No Reports to Dispute</h3>
                                <p class="body-medium max-w-md mx-auto" style="color: var(--text-secondary);">
                                    Great news! There are no reports filed against you that require disputing. Your record is clean.
                                </p>
                            </div>
                        <?php else: ?>
                            <form method="post" class="space-y-6" id="disputeForm">
                                <input type="hidden" name="action" value="file_dispute">

                                <div class="animate-slide-in-left" style="animation-delay: 0.3s;">
                                    <label class="form-label flex items-center">
                                        <i data-lucide="file-search" class="w-4 h-4 mr-2 text-blue-400"></i>
                                        Select Report to Dispute
                                    </label>
                                    <select name="report_id" class="form-input form-input-liquid" required>
                                        <option value="">Choose a report...</option>
                                        <?php foreach ($disputableReports as $report): ?>
                                            <option value="<?= $report['id'] ?>">
                                                Report #<?= $report['id'] ?> - <?= htmlspecialchars($report['title']) ?>
                                                (<?= ucfirst($report['category']) ?>)
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                                <div class="animate-slide-in-left" style="animation-delay: 0.4s;">
                                    <label class="form-label flex items-center">
                                        <i data-lucide="alert-triangle" class="w-4 h-4 mr-2 text-orange-400"></i>
                                        Reason for Dispute
                                    </label>
                                    <select name="reason" class="form-input form-input-liquid" required>
                                        <option value="">Select a reason...</option>
                                        <option value="false_accusation">False Accusation</option>
                                        <option value="wrong_person">Wrong Person Identified</option>
                                        <option value="misunderstanding">Misunderstanding</option>
                                        <option value="malicious_report">Malicious Report</option>
                                        <option value="other">Other</option>
                                    </select>
                                </div>

                                <div class="animate-slide-in-left" style="animation-delay: 0.5s;">
                                    <label class="form-label flex items-center">
                                        <i data-lucide="message-square" class="w-4 h-4 mr-2 text-purple-400"></i>
                                        Detailed Explanation
                                    </label>
                                    <textarea name="description" rows="6" class="form-input form-input-liquid"
                                              placeholder="Please provide a detailed explanation of why you believe this report is incorrect. Include any relevant evidence or context that supports your case." required></textarea>
                                </div>

                                <div class="liquid-glass liquid-morph rounded-xl p-6 animate-scale-in" style="animation-delay: 0.6s; background: linear-gradient(135deg, rgba(59, 130, 246, 0.1), rgba(147, 51, 234, 0.1));">
                                    <div class="flex items-start">
                                        <i data-lucide="info" class="w-5 h-5 text-blue-400 mr-3 mt-0.5"></i>
                                        <div>
                                            <h4 class="font-semibold text-blue-300 mb-3">Important Information</h4>
                                            <ul class="text-blue-200 space-y-2 text-sm">
                                                <li class="flex items-center">
                                                    <i data-lucide="clock" class="w-4 h-4 mr-2 text-blue-400"></i>
                                                    Disputes are reviewed within 48 hours
                                                </li>
                                                <li class="flex items-center">
                                                    <i data-lucide="file-text" class="w-4 h-4 mr-2 text-blue-400"></i>
                                                    Provide as much detail and evidence as possible
                                                </li>
                                                <li class="flex items-center">
                                                    <i data-lucide="shield-alert" class="w-4 h-4 mr-2 text-blue-400"></i>
                                                    False disputes may result in account suspension
                                                </li>
                                                <li class="flex items-center">
                                                    <i data-lucide="mail" class="w-4 h-4 mr-2 text-blue-400"></i>
                                                    You will be notified of the decision via email
                                                </li>
                                    </ul>
                                        </div>
                                    </div>
                                </div>

                                <div class="flex items-center space-x-4 animate-slide-in-right" style="animation-delay: 0.7s;">
                                    <button type="submit" class="btn btn-primary btn-liquid btn-animate">
                                        <i data-lucide="send" class="w-4 h-4 mr-2"></i>
                                        File Dispute
                                    </button>
                                    <button type="button" class="btn btn-outline btn-liquid btn-animate" onclick="resetForm()">
                                        <i data-lucide="refresh-cw" class="w-4 h-4 mr-2"></i>
                                        Reset Form
                                    </button>
                                </div>
                            </form>
                        <?php endif; ?>
                    </div>
                </div>
            </section>

            <!-- My Disputes -->
            <section>
                <div class="card card-glass liquid-glass liquid-morph card-hover animate-slide-up" style="animation-delay: 0.3s;">
                    <div class="card-body">
                        <div class="flex items-center mb-6">
                            <div class="w-10 h-10 bg-gradient-to-r from-purple-500 to-pink-600 rounded-xl flex items-center justify-center mr-4">
                                <i data-lucide="gavel" class="w-5 h-5 text-white"></i>
                            </div>
                            <h2 class="heading-3" style="color: var(--text-primary);">My Disputes</h2>
                        </div>

                        <?php if (empty($disputes)): ?>
                            <div class="text-center py-12 animate-bounce-in">
                                <div class="w-20 h-20 bg-gradient-to-r from-purple-500 to-pink-600 rounded-full flex items-center justify-center mx-auto mb-6 shadow-lg">
                                    <i data-lucide="gavel" class="w-10 h-10 text-white"></i>
                                </div>
                                <h3 class="heading-4 mb-3" style="color: var(--text-primary);">No Disputes Filed</h3>
                                <p class="body-medium max-w-md mx-auto" style="color: var(--text-secondary);">
                                    You haven't filed any disputes yet. When you do, they'll appear here with detailed status updates.
                                </p>
                            </div>
                        <?php else: ?>
                            <div class="space-y-6">
                                <?php foreach ($disputes as $index => $dispute): ?>
                                    <div class="liquid-glass liquid-morph rounded-xl p-6 hover:bg-white/5 transition-all duration-300 card-hover animate-slide-in-left"
                                         style="animation-delay: <?= 0.4 + ($index * 0.1) ?>s;">
                                        <div class="flex items-start justify-between">
                                            <div class="flex-1">
                                                <div class="flex items-center space-x-3 mb-4">
                                                    <h4 class="font-semibold text-lg" style="color: var(--text-primary);">
                                                        Dispute #<?= $dispute['id'] ?>
                                                    </h4>
                                                    <span class="status-badge status-<?= $dispute['status'] ?>">
                                                        <?= ucfirst(str_replace('_', ' ', $dispute['status'])) ?>
                                                    </span>
                                                </div>

                                                <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-4">
                                                    <div class="flex items-center">
                                                        <i data-lucide="alert-triangle" class="w-4 h-4 text-orange-400 mr-2"></i>
                                                    <div>
                                                            <p class="text-sm" style="color: var(--text-tertiary);">Reason</p>
                                                            <p class="font-medium" style="color: var(--text-primary);"><?= ucfirst(str_replace('_', ' ', $dispute['reason'])) ?></p>
                                                        </div>
                                                    </div>
                                                    <div class="flex items-center">
                                                        <i data-lucide="calendar" class="w-4 h-4 text-blue-400 mr-2"></i>
                                                    <div>
                                                            <p class="text-sm" style="color: var(--text-tertiary);">Filed</p>
                                                            <p class="font-medium" style="color: var(--text-primary);"><?= date('M j, Y', strtotime($dispute['created_at'])) ?></p>
                                                        </div>
                                                    </div>
                                                </div>

                                                <div class="mb-4">
                                                    <p class="text-sm flex items-center mb-2" style="color: var(--text-tertiary);">
                                                        <i data-lucide="message-square" class="w-4 h-4 mr-2 text-purple-400"></i>
                                                        Explanation
                                                    </p>
                                                    <p class="text-sm leading-relaxed" style="color: var(--text-secondary);"><?= htmlspecialchars($dispute['description']) ?></p>
                                                </div>

                                                <?php if ($dispute['review_notes']): ?>
                                                    <div class="mt-4 p-4 liquid-glass rounded-lg" style="background: linear-gradient(135deg, rgba(59, 130, 246, 0.1), rgba(147, 51, 234, 0.1));">
                                                        <div class="flex items-start">
                                                            <i data-lucide="file-text" class="w-4 h-4 text-blue-400 mr-2 mt-0.5"></i>
                                                            <div>
                                                                <p class="text-sm font-medium mb-1" style="color: var(--text-tertiary);">Review Notes</p>
                                                                <p class="text-sm" style="color: var(--text-secondary);"><?= htmlspecialchars($dispute['review_notes']) ?></p>
                                                            </div>
                                                        </div>
                                                    </div>
                                                <?php endif; ?>
                                            </div>

                                            <div class="flex items-center space-x-2 ml-4">
                                                <a href="view_dispute.php?id=<?= $dispute['id'] ?>"
                                                   class="btn btn-sm btn-outline btn-liquid btn-animate" title="View Details">
                                                    <i data-lucide="eye" class="w-4 h-4"></i>
                                                </a>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </section>
        </div>
    </main>

    <!-- Scripts -->
    <script src="https://unpkg.com/lucide@latest/dist/umd/lucide.js"></script>
    <script src="dashboard-enhanced.js"></script>

    <script>
        // Initialize Lucide icons
        lucide.createIcons();

        // Enhanced form functionality
        function resetForm() {
            const form = document.getElementById('disputeForm');
            if (form) {
                form.reset();

                // Add visual feedback
                const resetBtn = event.target;
                const originalText = resetBtn.innerHTML;
                resetBtn.innerHTML = '<i data-lucide="check" class="w-4 h-4 mr-2"></i>Reset Complete';
                resetBtn.classList.add('bg-green-500');

                setTimeout(() => {
                    resetBtn.innerHTML = originalText;
                    resetBtn.classList.remove('bg-green-500');
                }, 2000);
            }
        }

        // Enhanced form submission with loading state
        document.addEventListener('DOMContentLoaded', function() {
            const form = document.getElementById('disputeForm');
            if (form) {
                form.addEventListener('submit', function(e) {
                    const submitBtn = form.querySelector('button[type="submit"]');
                    const originalText = submitBtn.innerHTML;

                    // Add loading state
                    submitBtn.innerHTML = '<i data-lucide="loader-2" class="w-4 h-4 mr-2 animate-spin"></i>Filing Dispute...';
                    submitBtn.disabled = true;
                    form.classList.add('loading');
                });
            }
        });

        // Smooth scroll to sections
        function smoothScrollTo(element) {
            element.scrollIntoView({
                behavior: 'smooth',
                block: 'start'
            });
        }

        // Enhanced message handling with better animations
        setTimeout(() => {
            const messages = document.querySelectorAll('.card.border-l-4');
            messages.forEach((msg, index) => {
                setTimeout(() => {
                    msg.style.transition = 'all 0.5s ease-out';
                msg.style.opacity = '0';
                    msg.style.transform = 'translateY(-20px)';
                    setTimeout(() => msg.remove(), 500);
                }, 5000 + (index * 200));
            });
        }, 1000);

        // Add intersection observer for scroll animations
        const observerOptions = {
            threshold: 0.1,
            rootMargin: '0px 0px -50px 0px'
        };

        const observer = new IntersectionObserver((entries) => {
            entries.forEach(entry => {
                if (entry.isIntersecting) {
                    entry.target.style.opacity = '1';
                    entry.target.style.transform = 'translateY(0)';
                }
            });
        }, observerOptions);

        // Observe all animated elements
        document.addEventListener('DOMContentLoaded', function() {
            const animatedElements = document.querySelectorAll('.animate-slide-up, .animate-slide-in-left, .animate-slide-in-right');
            animatedElements.forEach(el => {
                el.style.opacity = '0';
                el.style.transform = 'translateY(20px)';
                observer.observe(el);
            });
        });

        // Enhanced hover effects for cards with liquid glass
        document.addEventListener('DOMContentLoaded', function() {
            const cards = document.querySelectorAll('.card-hover');
            cards.forEach(card => {
                card.addEventListener('mouseenter', function() {
                    this.style.transform = 'translateY(-8px) scale(1.02)';
                    this.style.backdropFilter = 'blur(30px) saturate(200%)';
                });

                card.addEventListener('mouseleave', function() {
                    this.style.transform = 'translateY(0) scale(1)';
                    this.style.backdropFilter = 'blur(20px) saturate(180%)';
                });
            });

            // Liquid glass button effects
            const liquidButtons = document.querySelectorAll('.btn-liquid');
            liquidButtons.forEach(button => {
                button.addEventListener('mouseenter', function() {
                    this.style.backdropFilter = 'blur(15px) saturate(200%)';
                });

                button.addEventListener('mouseleave', function() {
                    this.style.backdropFilter = 'blur(10px) saturate(180%)';
                });
            });

            // Liquid glass form inputs
            const liquidInputs = document.querySelectorAll('.form-input-liquid');
            liquidInputs.forEach(input => {
                input.addEventListener('focus', function() {
                    this.style.backdropFilter = 'blur(15px) saturate(200%)';
                    this.style.transform = 'scale(1.02)';
                });

                input.addEventListener('blur', function() {
                    this.style.backdropFilter = 'blur(10px) saturate(180%)';
                    this.style.transform = 'scale(1)';
                });
            });
        });

        // Status badge animations
        document.addEventListener('DOMContentLoaded', function() {
            const statusBadges = document.querySelectorAll('.status-badge');
            statusBadges.forEach(badge => {
                badge.addEventListener('mouseenter', function() {
                    this.style.transform = 'scale(1.05)';
                });

                badge.addEventListener('mouseleave', function() {
                    this.style.transform = 'scale(1)';
                });
            });
        });
    </script>
</body>
</html>
