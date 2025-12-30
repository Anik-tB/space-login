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

// Get user ID from session (you'll need to implement proper user session management)
$userId = $_SESSION['user_id'] ?? 1; // Default for demo

// Get report ID from URL
$reportId = $_GET['id'] ?? null;

if (!$reportId) {
    header('Location: my_reports.php');
    exit;
}

// Get report details
$report = $models->getIncidentReportById($reportId);

if (!$report) {
    header('Location: my_reports.php');
    exit;
}

// Check if user owns this report or is admin
if ($report['user_id'] != $userId) {
    // For now, allow viewing (you might want to restrict this)
    // header('Location: my_reports.php');
    // exit;
}

$message = '';
$error = '';

// Handle status updates
if (isset($_POST['action']) && $_POST['action'] === 'update_status' && isset($_POST['status'])) {
    $newStatus = $_POST['status'];
    $updateData = [
        'status' => $newStatus,
        'updated_date' => date('Y-m-d H:i:s')
    ];

    if ($models->updateIncidentReport($reportId, $updateData)) {
        $message = "Report status updated successfully!";
        $report['status'] = $newStatus; // Update local data
    } else {
        $error = "Failed to update report status.";
    }
}

// Get related disputes
$disputes = $models->getDisputesByReportId($reportId);

// Parse evidence files if they exist
$evidenceFiles = [];
if (!empty($report['evidence_files'])) {
    $evidenceFiles = json_decode($report['evidence_files'], true) ?: [];
}

// Get category and severity colors
function getCategoryColor($category) {
    switch($category) {
        case 'harassment': return 'bg-red-500/20 text-red-300 border-red-500/30';
        case 'assault': return 'bg-red-500/20 text-red-300 border-red-500/30';
        case 'theft': return 'bg-yellow-500/20 text-yellow-300 border-yellow-500/30';
        case 'vandalism': return 'bg-orange-500/20 text-orange-300 border-orange-500/30';
        case 'stalking': return 'bg-purple-500/20 text-purple-300 border-purple-500/30';
        case 'cyberbullying': return 'bg-blue-500/20 text-blue-300 border-blue-500/30';
        case 'discrimination': return 'bg-indigo-500/20 text-indigo-300 border-indigo-500/30';
        default: return 'bg-gray-500/20 text-gray-300 border-gray-500/30';
    }
}

function getSeverityColor($severity) {
    switch($severity) {
        case 'low': return 'bg-green-500/20 text-green-300 border-green-500/30';
        case 'medium': return 'bg-yellow-500/20 text-yellow-300 border-yellow-500/30';
        case 'high': return 'bg-orange-500/20 text-orange-300 border-orange-500/30';
        case 'critical': return 'bg-red-500/20 text-red-300 border-red-500/30';
        default: return 'bg-gray-500/20 text-gray-300 border-gray-500/30';
    }
}

function getStatusColor($status) {
    switch($status) {
        case 'pending': return 'bg-yellow-500/20 text-yellow-300 border-yellow-500/30';
        case 'investigating': return 'bg-blue-500/20 text-blue-300 border-blue-500/30';
        case 'resolved': return 'bg-green-500/20 text-green-300 border-green-500/30';
        case 'closed': return 'bg-gray-500/20 text-gray-300 border-gray-500/30';
        case 'disputed': return 'bg-red-500/20 text-red-300 border-red-500/30';
        default: return 'bg-gray-500/20 text-gray-300 border-gray-500/30';
    }
}
?>
<!DOCTYPE html>
<html lang="<?= $lang ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Report #<?= $reportId ?> - SafeSpace Portal</title>

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
                        'fade-in-up': 'fadeInUp 0.8s cubic-bezier(0.4, 0, 0.2, 1) forwards',
                        'fade-in-down': 'fadeInDown 0.8s cubic-bezier(0.4, 0, 0.2, 1) forwards',
                        'fade-in-left': 'fadeInLeft 0.8s cubic-bezier(0.4, 0, 0.2, 1) forwards',
                        'fade-in-right': 'fadeInRight 0.8s cubic-bezier(0.4, 0, 0.2, 1) forwards',
                        'scale-in': 'scaleIn 0.6s cubic-bezier(0.34, 1.56, 0.64, 1) forwards',
                        'slide-up-stagger': 'slideUpStagger 0.8s cubic-bezier(0.4, 0, 0.2, 1) forwards',
                        'float': 'float 3s ease-in-out infinite',
                        'pulse-gentle': 'pulseGentle 2s ease-in-out infinite',
                        'glow': 'glow 2s ease-in-out infinite alternate',
                        'shimmer': 'shimmer 2s linear infinite',
                        'bounce-subtle': 'bounceSubtle 2s ease-in-out infinite',
                    },
                    keyframes: {
                        fadeInUp: {
                            '0%': { opacity: '0', transform: 'translateY(30px)' },
                            '100%': { opacity: '1', transform: 'translateY(0)' }
                        },
                        fadeInDown: {
                            '0%': { opacity: '0', transform: 'translateY(-30px)' },
                            '100%': { opacity: '1', transform: 'translateY(0)' }
                        },
                        fadeInLeft: {
                            '0%': { opacity: '0', transform: 'translateX(-30px)' },
                            '100%': { opacity: '1', transform: 'translateX(0)' }
                        },
                        fadeInRight: {
                            '0%': { opacity: '0', transform: 'translateX(30px)' },
                            '100%': { opacity: '1', transform: 'translateX(0)' }
                        },
                        scaleIn: {
                            '0%': { opacity: '0', transform: 'scale(0.9)' },
                            '100%': { opacity: '1', transform: 'scale(1)' }
                        },
                        slideUpStagger: {
                            '0%': { opacity: '0', transform: 'translateY(40px)' },
                            '100%': { opacity: '1', transform: 'translateY(0)' }
                        },
                        float: {
                            '0%, 100%': { transform: 'translateY(0px)' },
                            '50%': { transform: 'translateY(-8px)' }
                        },
                        pulseGentle: {
                            '0%, 100%': { opacity: '1' },
                            '50%': { opacity: '0.8' }
                        },
                        glow: {
                            '0%': { boxShadow: '0 0 5px rgba(14, 165, 233, 0.3)' },
                            '100%': { boxShadow: '0 0 20px rgba(14, 165, 233, 0.6)' }
                        },
                        shimmer: {
                            '0%': { backgroundPosition: '-200% 0' },
                            '100%': { backgroundPosition: '200% 0' }
                        },
                        bounceSubtle: {
                            '0%, 100%': { transform: 'translateY(0)' },
                            '50%': { transform: 'translateY(-4px)' }
                        }
                    }
                }
            }
        }
    </script>

    <style>
        /* Enhanced Animation Styles */
        .animate-on-scroll {
            opacity: 0;
            transform: translateY(30px);
            transition: all 0.8s cubic-bezier(0.4, 0, 0.2, 1);
        }

        .animate-on-scroll.animate-in {
            opacity: 1;
            transform: translateY(0);
        }

        .stagger-animation > * {
            opacity: 0;
            transform: translateY(20px);
            transition: all 0.6s cubic-bezier(0.4, 0, 0.2, 1);
        }

        .stagger-animation.animate-in > * {
            opacity: 1;
            transform: translateY(0);
        }

        .stagger-animation.animate-in > *:nth-child(1) { transition-delay: 0.1s; }
        .stagger-animation.animate-in > *:nth-child(2) { transition-delay: 0.2s; }
        .stagger-animation.animate-in > *:nth-child(3) { transition-delay: 0.3s; }
        .stagger-animation.animate-in > *:nth-child(4) { transition-delay: 0.4s; }
        .stagger-animation.animate-in > *:nth-child(5) { transition-delay: 0.5s; }
        .stagger-animation.animate-in > *:nth-child(6) { transition-delay: 0.6s; }

        /* Card hover effects */
        .card-hover {
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            position: relative;
            overflow: hidden;
        }

        .card-hover::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.1), transparent);
            transition: left 0.5s ease;
        }

        .card-hover:hover::before {
            left: 100%;
        }

        .card-hover:hover {
            transform: translateY(-4px) scale(1.02);
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.3);
        }

        /* Button animations */
        .btn-animate {
            position: relative;
            overflow: hidden;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }

        .btn-animate::after {
            content: '';
            position: absolute;
            top: 50%;
            left: 50%;
            width: 0;
            height: 0;
            border-radius: 50%;
            background: rgba(255, 255, 255, 0.3);
            transform: translate(-50%, -50%);
            transition: width 0.6s, height 0.6s;
        }

        .btn-animate:active::after {
            width: 300px;
            height: 300px;
        }

        /* Loading skeleton */
        .skeleton {
            background: linear-gradient(90deg, rgba(255, 255, 255, 0.1) 25%, rgba(255, 255, 255, 0.2) 50%, rgba(255, 255, 255, 0.1) 75%);
            background-size: 200% 100%;
            animation: shimmer 2s linear infinite;
        }

        /* Status indicator animations */
        .status-indicator {
            position: relative;
        }

        .status-indicator::after {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            border-radius: inherit;
            background: inherit;
            animation: pulseGentle 2s ease-in-out infinite;
        }

        /* Smooth scrolling */
        html {
            scroll-behavior: smooth;
        }

        /* Custom scrollbar */
        ::-webkit-scrollbar {
            width: 8px;
        }

        ::-webkit-scrollbar-track {
            background: rgba(255, 255, 255, 0.1);
            border-radius: 4px;
        }

        ::-webkit-scrollbar-thumb {
            background: rgba(255, 255, 255, 0.3);
            border-radius: 4px;
        }

        ::-webkit-scrollbar-thumb:hover {
            background: rgba(255, 255, 255, 0.5);
        }

        /* Status badge animations */
        .status-badge {
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
            transition: left 0.5s ease;
        }

        .status-badge:hover::before {
            left: 100%;
        }

        /* Evidence file hover effects */
        .evidence-file {
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            position: relative;
        }

        .evidence-file:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.2);
        }

        .evidence-file::after {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: linear-gradient(135deg, rgba(59, 130, 246, 0.1), rgba(147, 51, 234, 0.1));
            opacity: 0;
            transition: opacity 0.3s ease;
            border-radius: inherit;
        }

        .evidence-file:hover::after {
            opacity: 1;
        }

        /* Dispute item animations */
        .dispute-item {
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }

        .dispute-item:hover {
            transform: translateX(4px);
            background: rgba(255, 255, 255, 0.08);
        }

        /* Form input focus animations */
        .form-input:focus {
            transform: scale(1.02);
            box-shadow: 0 0 0 3px rgba(14, 165, 233, 0.3);
        }

        /* Icon animations */
        .icon-animate {
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }

        .icon-animate:hover {
            transform: scale(1.1) rotate(5deg);
        }

        /* Progress indicators */
        .progress-ring {
            transform: rotate(-90deg);
        }

        .progress-ring circle {
            transition: stroke-dashoffset 0.35s;
            transform-origin: 50% 50%;
        }

        /* Floating action button */
        .fab {
            position: fixed;
            bottom: 2rem;
            right: 2rem;
            z-index: 1000;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }

        .fab:hover {
            transform: scale(1.1);
        }

        /* Reduced motion support */
        @media (prefers-reduced-motion: reduce) {
            * {
                animation-duration: 0.01ms !important;
                animation-iteration-count: 1 !important;
                transition-duration: 0.01ms !important;
            }
        }

        /* High contrast mode support */
        @media (prefers-contrast: high) {
            .card-hover {
                border: 2px solid currentColor;
            }

            .btn-animate {
                border: 2px solid currentColor;
            }
        }

        /* Print styles */
        @media print {
            .btn-animate,
            .card-hover,
            .animate-on-scroll {
                animation: none !important;
                transform: none !important;
            }

            .fab {
                display: none !important;
            }
        }
    </style>
</head>
<body class="bg-gradient-to-br from-slate-900 via-purple-900 to-slate-900 min-h-screen">
    <!-- Header -->


    <!-- Main Content -->
  <main class="max-w-7xl mx-auto">
        <div class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8">
            <!-- Messages -->
            <?php if ($message): ?>
                <div class="mb-8 card card-glass border-l-4 border-green-500">
                    <div class="card-body">
                        <div class="flex items-center">
                            <i data-lucide="check-circle" class="w-5 h-5 text-green-500 mr-3"></i>
                            <p class="text-green-300"><?= $message ?></p>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <?php if ($error): ?>
                <div class="mb-8 card card-glass border-l-4 border-red-500">
                    <div class="card-body">
                        <div class="flex items-center">
                            <i data-lucide="alert-circle" class="w-5 h-5 text-red-500 mr-3"></i>
                            <p class="text-red-300"><?= $error ?></p>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Header Section -->
           <section class="mb-8 animate-on-scroll">
                <div class="flex items-center justify-between">
                    <div>
                        <div class="flex items-center space-x-4 mb-4">
                            <div class="w-16 h-16 bg-gradient-to-r from-primary-500 to-secondary-500 rounded-2xl flex items-center justify-center animate-scale-in float">
                                <i data-lucide="file-text" class="w-8 h-8 text-white"></i>
                            </div>
                            <div class="animate-fade-in-left">
                                <h1 class="heading-1 mb-2">Report #<?= $reportId ?></h1>
                                <p class="body-large" style="color: var(--text-secondary);">
                                    Incident Report Details
                                </p>
                            </div>
                        </div>
                    </div>
                   <div class="flex items-center space-x-4 animate-fade-in-right">
                        <a href="recent_reports_details.php" class="btn btn-primary btn-lg btn-animate">
                            <i data-lucide="arrow-left" class="w-5 h-5 mr-2"></i>
                            Back to Reports
                        </a>

                        <a href="dashboard.php" class="btn btn-primary btn-lg btn-animate">
                            <i data-lucide="arrow left" class="w-5 h-5 mr-2"></i>
                            Back to Dashboard
                        </a>
                    </div>

                    </div>
            </section>

            <!-- Report Details -->
            <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">
                <!-- Main Content -->
                <div class="lg:col-span-2 space-y-8">
                    <!-- Basic Information -->
                    <section class="animate-on-scroll">
                        <div class="card card-glass card-hover">
                            <div class="card-body">
                                <div class="flex items-center space-x-4 mb-6">
                                    <div class="w-10 h-10 bg-gradient-to-r from-primary-500 to-secondary-500 rounded-xl flex items-center justify-center animate-scale-in">
                                        <i data-lucide="info" class="w-5 h-5 text-white"></i>
                                    </div>
                                    <h2 class="heading-2" style="color: var(--text-primary);">Report Information</h2>
                                </div>

                                <div class="space-y-6">
                                    <!-- Title and Status -->
                                    <div class="flex items-start justify-between">
                                        <div class="flex-1">
                                            <h3 class="heading-3 mb-2" style="color: var(--text-primary);">
                                                <?= htmlspecialchars($report['title']) ?>
                                            </h3>
                                            <p class="body-large" style="color: var(--text-secondary);">
                                                <?= htmlspecialchars($report['description']) ?>
                                            </p>
                                        </div>
                                       
                                    </div>

                                    <!-- Categories and Severity -->
                                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                                        <div>
                                            <label class="form-label font-semibold">Category</label>
                                            <div class="mt-2">
                                                <span class="inline-flex items-center px-3 py-1 rounded-full text-sm font-medium border status-badge <?= getCategoryColor($report['category']) ?>">
                                                    <?= ucfirst($report['category']) ?>
                                                </span>
                                            </div>
                                        </div>
                                        <div>
                                            <label class="form-label font-semibold">Severity Level</label>
                                            <div class="mt-2">
                                                <span class="inline-flex items-center px-3 py-1 rounded-full text-sm font-medium border status-badge <?= getSeverityColor($report['severity']) ?>">
                                                    <?= ucfirst($report['severity']) ?>
                                                </span>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Date and Witnesses -->
                                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                                        <div>
                                            <label class="form-label font-semibold">Incident Date</label>
                                            <p class="mt-2" style="color: var(--text-secondary);">
                                                <?= $report['incident_date'] ? date('F j, Y \a\t g:i A', strtotime($report['incident_date'])) : 'Not specified' ?>
                                            </p>
                                        </div>
                                        <div>
                                            <label class="form-label font-semibold">Witnesses</label>
                                            <p class="mt-2" style="color: var(--text-secondary);">
                                                <?= $report['witness_count'] ? $report['witness_count'] . ' witness(es)' : 'No witnesses reported' ?>
                                            </p>
                                        </div>
                                    </div>

                                    <!-- Privacy Settings -->
                                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                                        <div>
                                            <label class="form-label font-semibold">Privacy Settings</label>
                                            <div class="mt-2 space-y-2">
                                                <div class="flex items-center space-x-2">
                                                    <i data-lucide="<?= $report['is_anonymous'] ? 'check' : 'x' ?>"
                                                       class="w-4 h-4 <?= $report['is_anonymous'] ? 'text-green-400' : 'text-red-400' ?>"></i>
                                                    <span style="color: var(--text-secondary);">
                                                        <?= $report['is_anonymous'] ? 'Anonymous Report' : 'Named Report' ?>
                                                    </span>
                                                </div>
                                                <div class="flex items-center space-x-2">
                                                    <i data-lucide="<?= $report['is_public'] ? 'check' : 'x' ?>"
                                                       class="w-4 h-4 <?= $report['is_public'] ? 'text-green-400' : 'text-red-400' ?>"></i>
                                                    <span style="color: var(--text-secondary);">
                                                        <?= $report['is_public'] ? 'Public Report' : 'Private Report' ?>
                                                    </span>
                                                </div>
                                            </div>
                                        </div>
                                        <div>
                                            <label class="form-label font-semibold">Report Timeline</label>
                                            <div class="mt-2 space-y-2">
                                                <div class="flex items-center space-x-2">
                                                    <i data-lucide="calendar" class="w-4 h-4 text-blue-400"></i>
                                                    <span style="color: var(--text-secondary);">
                                                        Reported: <?= date('M j, Y', strtotime($report['reported_date'])) ?>
                                                    </span>
                                                </div>
                                                <?php if ($report['updated_date']): ?>
                                                    <div class="flex items-center space-x-2">
                                                        <i data-lucide="edit" class="w-4 h-4 text-green-400"></i>
                                                        <span style="color: var(--text-secondary);">
                                                            Updated: <?= date('M j, Y', strtotime($report['updated_date'])) ?>
                                                        </span>
                                                    </div>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </section>

                    <!-- Location Information -->
                    <?php if ($report['location_name'] || $report['address']): ?>
                    <section class="animate-on-scroll">
                        <div class="card card-glass card-hover">
                            <div class="card-body">
                                <div class="flex items-center space-x-4 mb-6">
                                    <div class="w-10 h-10 bg-gradient-to-r from-accent-500 to-accent-600 rounded-xl flex items-center justify-center animate-scale-in">
                                        <i data-lucide="map-pin" class="w-5 h-5 text-white"></i>
                                    </div>
                                    <h2 class="heading-2" style="color: var(--text-primary);">Location Details</h2>
                                </div>

                                <div class="space-y-4">
                                    <?php if ($report['location_name']): ?>
                                        <div>
                                            <label class="form-label font-semibold">Location Name</label>
                                            <p class="mt-2" style="color: var(--text-secondary);">
                                                <?= htmlspecialchars($report['location_name']) ?>
                                            </p>
                                        </div>
                                    <?php endif; ?>

                                    <?php if ($report['address']): ?>
                                        <div>
                                            <label class="form-label font-semibold">Address</label>
                                            <p class="mt-2" style="color: var(--text-secondary);">
                                                <?= htmlspecialchars($report['address']) ?>
                                            </p>
                                        </div>
                                    <?php endif; ?>

                                    <?php if ($report['latitude'] && $report['longitude']): ?>
                                        <div>
                                            <label class="form-label font-semibold">Coordinates</label>
                                            <p class="mt-2" style="color: var(--text-secondary);">
                                                <?= $report['latitude'] ?>, <?= $report['longitude'] ?>
                                            </p>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </section>
                    <?php endif; ?>

                    <!-- Evidence Files -->
                    <?php if (!empty($evidenceFiles)): ?>
                    <section class="animate-on-scroll">
                        <div class="card card-glass card-hover">
                            <div class="card-body">
                                <div class="flex items-center space-x-4 mb-6">
                                    <div class="w-10 h-10 bg-gradient-to-r from-warning-500 to-warning-600 rounded-xl flex items-center justify-center animate-scale-in">
                                        <i data-lucide="paperclip" class="w-5 h-5 text-white"></i>
                                    </div>
                                    <h2 class="heading-2" style="color: var(--text-primary);">Evidence Files</h2>
                                </div>

                                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                    <?php foreach ($evidenceFiles as $file): ?>
                                        <div class="bg-white/5 rounded-lg p-4 border border-white/10 evidence-file">
                                            <div class="flex items-center space-x-3">
                                                <i data-lucide="file" class="w-8 h-8 text-blue-400 icon-animate"></i>
                                                <div class="flex-1">
                                                    <p class="font-medium" style="color: var(--text-primary);">
                                                        <?= basename($file) ?>
                                                    </p>
                                                    <p class="text-sm" style="color: var(--text-tertiary);">
                                                        Evidence file
                                                    </p>
                                                </div>
                                                <a href="<?= $file ?>" target="_blank" class="btn btn-sm btn-outline btn-animate">
                                                    <i data-lucide="external-link" class="w-4 h-4"></i>
                                                </a>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>
                    </section>
                    <?php endif; ?>

                    <!-- Related Disputes -->
                    <?php if (!empty($disputes)): ?>
                    <section class="animate-on-scroll">
                        <div class="card card-glass card-hover">
                            <div class="card-body">
                                <div class="flex items-center space-x-4 mb-6">
                                    <div class="w-10 h-10 bg-gradient-to-r from-red-500 to-red-600 rounded-xl flex items-center justify-center animate-scale-in">
                                        <i data-lucide="alert-triangle" class="w-5 h-5 text-white"></i>
                                    </div>
                                    <h2 class="heading-2" style="color: var(--text-primary);">Related Disputes</h2>
                                </div>

                                <div class="space-y-4">
                                    <?php foreach ($disputes as $dispute): ?>
                                        <div class="bg-white/5 rounded-lg p-4 border border-white/10 dispute-item">
                                            <div class="flex items-center justify-between mb-2">
                                                <h4 class="font-semibold" style="color: var(--text-primary);">
                                                    Dispute #<?= $dispute['id'] ?>
                                                </h4>
                                                <span class="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium status-badge bg-red-500/20 text-red-300 border border-red-500/30">
                                                    <?= ucfirst($dispute['status']) ?>
                                                </span>
                                            </div>
                                            <p class="text-sm" style="color: var(--text-secondary);">
                                                <?= htmlspecialchars($dispute['reason']) ?>
                                            </p>
                                            <div class="flex items-center space-x-2 mt-3">
                                                <span class="text-xs" style="color: var(--text-tertiary);">
                                                    Filed: <?= date('M j, Y', strtotime($dispute['created_date'])) ?>
                                                </span>
                                                <a href="dispute_center.php?id=<?= $dispute['id'] ?>" class="btn btn-sm btn-outline btn-animate">
                                                    <i data-lucide="eye" class="w-4 h-4 mr-1"></i>
                                                    View
                                                </a>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>
                    </section>
                    <?php endif; ?>
                </div>

                <!-- Sidebar -->
                <div class="space-y-8">


                    <!-- Report Statistics -->
                    <section class="animate-on-scroll">
                        <div class="card card-glass card-hover">
                            <div class="card-body">
                                <div class="flex items-center space-x-4 mb-6">
                                    <div class="w-10 h-10 bg-gradient-to-r from-primary-500 to-primary-600 rounded-xl flex items-center justify-center animate-scale-in">
                                        <i data-lucide="bar-chart" class="w-5 h-5 text-white"></i>
                                    </div>
                                    <h2 class="heading-3" style="color: var(--text-primary);">Report Info</h2>
                                </div>

                                <div class="space-y-4">
                                    <div class="flex items-center justify-between">
                                        <span style="color: var(--text-secondary);">Report ID</span>
                                        <span class="font-mono font-medium" style="color: var(--text-primary);">#<?= $reportId ?></span>
                                    </div>
                                    <div class="flex items-center justify-between">
                                        <span style="color: var(--text-secondary);">Category</span>
                                        <span class="font-medium" style="color: var(--text-primary);"><?= ucfirst($report['category']) ?></span>
                                    </div>
                                    <div class="flex items-center justify-between">
                                        <span style="color: var(--text-secondary);">Severity</span>
                                        <span class="font-medium" style="color: var(--text-primary);"><?= ucfirst($report['severity']) ?></span>
                                    </div>
                                    <div class="flex items-center justify-between">
                                        <span style="color: var(--text-secondary);">Witnesses</span>
                                        <span class="font-medium" style="color: var(--text-primary);"><?= $report['witness_count'] ?: 0 ?></span>
                                    </div>
                                    <div class="flex items-center justify-between">
                                        <span style="color: var(--text-secondary);">Evidence Files</span>
                                        <span class="font-medium" style="color: var(--text-primary);"><?= count($evidenceFiles) ?></span>
                                    </div>

                                </div>
                            </div>
                        </div>
                    </section>
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

        // Enhanced Animation System
        class AnimationController {
            constructor() {
                this.observers = new Map();
                this.init();
            }

            init() {
                this.setupIntersectionObserver();
                this.setupStaggerAnimations();
                this.setupScrollAnimations();
                this.setupButtonEffects();
                this.setupCardEffects();
            }

            setupIntersectionObserver() {
                const options = {
                    threshold: 0.1,
                    rootMargin: '0px 0px -50px 0px'
                };

                this.observers.set('scroll', new IntersectionObserver((entries) => {
                    entries.forEach(entry => {
                        if (entry.isIntersecting) {
                            entry.target.classList.add('animate-in');

                            // Add entrance animation class based on data attribute
                            const animationType = entry.target.dataset.animation || 'fade-in-up';
                            entry.target.classList.add(animationType);

                            // Trigger stagger animation for child elements
                            const staggerContainer = entry.target.querySelector('.stagger-animation');
                            if (staggerContainer) {
                                setTimeout(() => {
                                    staggerContainer.classList.add('animate-in');
                                }, 200);
                            }
                        }
                    });
                }, options));

                // Observe all elements with animate-on-scroll class
                document.querySelectorAll('.animate-on-scroll').forEach(el => {
                    this.observers.get('scroll').observe(el);
                });
            }

            setupStaggerAnimations() {
                // Auto-trigger stagger animations for elements already in view
                document.querySelectorAll('.stagger-animation').forEach(container => {
                    if (this.isElementInViewport(container)) {
                        setTimeout(() => {
                            container.classList.add('animate-in');
                        }, 300);
                    }
                });
            }

            setupScrollAnimations() {
                // Parallax effect for header elements
                window.addEventListener('scroll', () => {
                    const scrolled = window.pageYOffset;
                    const parallaxElements = document.querySelectorAll('[data-parallax]');

                    parallaxElements.forEach(el => {
                        const speed = el.dataset.parallax || 0.5;
                        const yPos = -(scrolled * speed);
                        el.style.transform = `translateY(${yPos}px)`;
                    });
                });
            }

            setupButtonEffects() {
                // Enhanced button hover effects
                document.querySelectorAll('.btn-animate').forEach(btn => {
                    btn.addEventListener('mouseenter', (e) => {
                        this.createRippleEffect(e, btn);
                    });

                    btn.addEventListener('click', (e) => {
                        this.createClickEffect(e, btn);
                    });
                });
            }

            setupCardEffects() {
                // Enhanced card hover effects
                document.querySelectorAll('.card-hover').forEach(card => {
                    card.addEventListener('mouseenter', () => {
                        card.style.transform = 'translateY(-8px) scale(1.02)';
                        card.style.boxShadow = '0 25px 50px rgba(0, 0, 0, 0.4)';
                    });

                    card.addEventListener('mouseleave', () => {
                        card.style.transform = 'translateY(0) scale(1)';
                        card.style.boxShadow = '';
                    });
                });
            }

            createRippleEffect(event, element) {
                const ripple = document.createElement('span');
                const rect = element.getBoundingClientRect();
                const size = Math.max(rect.width, rect.height);
                const x = event.clientX - rect.left - size / 2;
                const y = event.clientY - rect.top - size / 2;

                ripple.style.cssText = `
                    position: absolute;
                    width: ${size}px;
                    height: ${size}px;
                    left: ${x}px;
                    top: ${y}px;
                    background: rgba(255, 255, 255, 0.3);
                    border-radius: 50%;
                    transform: scale(0);
                    animation: ripple 0.6s linear;
                    pointer-events: none;
                `;

                element.appendChild(ripple);

                setTimeout(() => {
                    ripple.remove();
                }, 600);
            }

            createClickEffect(event, element) {
                element.style.transform = 'scale(0.95)';
                setTimeout(() => {
                    element.style.transform = '';
                }, 150);
            }

            isElementInViewport(el) {
                const rect = el.getBoundingClientRect();
                return (
                    rect.top >= 0 &&
                    rect.left >= 0 &&
                    rect.bottom <= (window.innerHeight || document.documentElement.clientHeight) &&
                    rect.right <= (window.innerWidth || document.documentElement.clientWidth)
                );
            }

            // Utility method to trigger animations programmatically
            triggerAnimation(element, animationClass) {
                element.classList.add(animationClass);
                setTimeout(() => {
                    element.classList.remove(animationClass);
                }, 1000);
            }
        }

        // Toast notification system
        class ToastManager {
            constructor() {
                this.container = this.createContainer();
            }

            createContainer() {
                const container = document.createElement('div');
                container.id = 'toast-container';
                container.style.cssText = `
                    position: fixed;
                    top: 20px;
                    right: 20px;
                    z-index: 9999;
                    display: flex;
                    flex-direction: column;
                    gap: 10px;
                `;
                document.body.appendChild(container);
                return container;
            }

            show(message, type = 'info', duration = 3000) {
                const toast = document.createElement('div');
                const colors = {
                    success: 'bg-green-500',
                    error: 'bg-red-500',
                    warning: 'bg-yellow-500',
                    info: 'bg-blue-500'
                };

                toast.className = `${colors[type]} text-white px-6 py-3 rounded-lg shadow-lg transform translate-x-full transition-transform duration-300`;
                toast.innerHTML = `
                    <div class="flex items-center space-x-2">
                        <i data-lucide="${this.getIcon(type)}" class="w-4 h-4"></i>
                        <span>${message}</span>
                    </div>
                `;

                this.container.appendChild(toast);
                lucide.createIcons();

                // Animate in
                setTimeout(() => {
                    toast.classList.remove('translate-x-full');
                }, 100);

                // Auto remove
                setTimeout(() => {
                    toast.classList.add('translate-x-full');
                    setTimeout(() => {
                        if (toast.parentNode) {
                            toast.parentNode.removeChild(toast);
                        }
                    }, 300);
                }, duration);
            }

            getIcon(type) {
                const icons = {
                    success: 'check-circle',
                    error: 'x-circle',
                    warning: 'alert-triangle',
                    info: 'info'
                };
                return icons[type] || 'info';
            }
        }

        // Loading state manager
        class LoadingManager {
            constructor() {
                this.loadingStates = new Map();
            }

            showLoading(element, text = 'Loading...') {
                const originalContent = element.innerHTML;
                const loadingId = Math.random().toString(36);

                element.innerHTML = `
                    <div class="flex items-center justify-center space-x-2">
                        <div class="spinner"></div>
                        <span>${text}</span>
                    </div>
                `;
                element.disabled = true;

                this.loadingStates.set(loadingId, { element, originalContent });
                return loadingId;
            }

            hideLoading(loadingId) {
                const state = this.loadingStates.get(loadingId);
                if (state) {
                    state.element.innerHTML = state.originalContent;
                    state.element.disabled = false;
                    this.loadingStates.delete(loadingId);
                }
            }
        }

        // Initialize all managers
        const animationController = new AnimationController();
        const toastManager = new ToastManager();
        const loadingManager = new LoadingManager();

        // Share report function with enhanced UX
        function shareReport() {
            const loadingId = loadingManager.showLoading(
                document.querySelector('button[onclick="shareReport()"]'),
                'Sharing...'
            );

            setTimeout(() => {
            if (navigator.share) {
                navigator.share({
                    title: 'Incident Report #<?= $reportId ?>',
                    text: 'View this incident report on SafeSpace Portal',
                    url: window.location.href
                    }).then(() => {
                        toastManager.show('Report shared successfully!', 'success');
                    }).catch(() => {
                        toastManager.show('Failed to share report', 'error');
                });
            } else {
                // Fallback: copy to clipboard
                navigator.clipboard.writeText(window.location.href).then(() => {
                        toastManager.show('Report URL copied to clipboard!', 'success');
                    }).catch(() => {
                        toastManager.show('Failed to copy URL', 'error');
                    });
                }
                loadingManager.hideLoading(loadingId);
            }, 500);
        }

        // Enhanced form submission with loading states
        document.addEventListener('DOMContentLoaded', function() {
            const statusForm = document.querySelector('form[method="POST"]');
            if (statusForm) {
                statusForm.addEventListener('submit', function(e) {
                    const submitBtn = this.querySelector('button[type="submit"]');
                    const loadingId = loadingManager.showLoading(submitBtn, 'Updating...');

                    // The form will submit normally, but we show loading state
                    setTimeout(() => {
                        loadingManager.hideLoading(loadingId);
                    }, 2000);
                });
            }

            // Add smooth scroll behavior for anchor links
            document.querySelectorAll('a[href^="#"]').forEach(anchor => {
                anchor.addEventListener('click', function (e) {
                    e.preventDefault();
                    const target = document.querySelector(this.getAttribute('href'));
                    if (target) {
                        target.scrollIntoView({
                            behavior: 'smooth',
                            block: 'start'
                        });
                    }
                });
            });

            // Add keyboard shortcuts
            document.addEventListener('keydown', function(e) {
                if (e.ctrlKey || e.metaKey) {
                    switch(e.key) {
                        case 'p':
                            e.preventDefault();
                            window.print();
                            break;
                        case 's':
                            e.preventDefault();
                            document.querySelector('button[type="submit"]')?.click();
                            break;
                    }
                }
            });

            // Add focus management for accessibility
            document.querySelectorAll('.btn-animate, .card-hover').forEach(element => {
                element.addEventListener('focus', function() {
                    this.style.outline = '2px solid #0ea5e9';
                    this.style.outlineOffset = '2px';
                });

                element.addEventListener('blur', function() {
                    this.style.outline = '';
                    this.style.outlineOffset = '';
                });
            });
        });

        // Performance optimization: Debounce scroll events
        function debounce(func, wait) {
            let timeout;
            return function executedFunction(...args) {
                const later = () => {
                    clearTimeout(timeout);
                    func(...args);
                };
                clearTimeout(timeout);
                timeout = setTimeout(later, wait);
            };
        }

        // Optimized scroll handler
        const optimizedScrollHandler = debounce(() => {
            // Any scroll-based animations can go here
        }, 16); // ~60fps

        window.addEventListener('scroll', optimizedScrollHandler);

        // Make utilities globally available
        window.safeSpaceAnimations = {
            controller: animationController,
            toast: toastManager,
            loading: loadingManager,
            triggerAnimation: (element, animation) => animationController.triggerAnimation(element, animation)
        };
    </script>
</body>
</html>