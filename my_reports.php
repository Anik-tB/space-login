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
$models = new SafeSpaceModels($database);

// Get user ID from session (you'll need to implement proper user session management)
$userId = $_SESSION['user_id'] ?? 1; // Default for demo

// Handle actions
$action = $_GET['action'] ?? '';
$reportId = $_GET['id'] ?? 0;

// Handle delete action
if ($action === 'delete' && $reportId) {
    try {
        // Check if report belongs to user
        $report = $models->getIncidentReportById($reportId);
        if ($report && $report['user_id'] == $userId) {
            $sql = "DELETE FROM incident_reports WHERE id = ? AND user_id = ?";
            $database->delete($sql, [$reportId, $userId]);
            $message = "Report deleted successfully.";
        } else {
            $error = "Report not found or access denied.";
        }
    } catch (Exception $e) {
        error_log('my_reports delete error: ' . $e->getMessage());
        $error = "Error deleting report. Please try again.";
    }
}

// Get filter parameters
$statusFilter = $_GET['status'] ?? '';
$categoryFilter = $_GET['category'] ?? '';
$searchQuery = $_GET['search'] ?? '';
$page = max(1, intval($_GET['page'] ?? 1));
$perPage = 10;
$offset = ($page - 1) * $perPage;

// Build filter conditions
$whereConditions = ["user_id = ?"];
$params = [$userId];

if ($statusFilter) {
    $whereConditions[] = "status = ?";
    $params[] = $statusFilter;
}

if ($categoryFilter) {
    $whereConditions[] = "category = ?";
    $params[] = $categoryFilter;
}

if ($searchQuery) {
    $whereConditions[] = "(title LIKE ? OR description LIKE ?)";
    $params[] = "%$searchQuery%";
    $params[] = "%$searchQuery%";
}

$whereClause = implode(' AND ', $whereConditions);

// Get total count for pagination
$countSql = "SELECT COUNT(*) as total FROM incident_reports WHERE $whereClause";
$totalCount = $database->fetchOne($countSql, $params)['total'];
$totalPages = ceil($totalCount / $perPage);

// Get filtered reports
$sql = "SELECT * FROM incident_reports WHERE $whereClause ORDER BY reported_date DESC LIMIT ? OFFSET ?";
$params[] = $perPage;
$params[] = $offset;
$reports = $database->fetchAll($sql, $params);

// Get statistics
$stats = $models->getDashboardStats();

// Get user's report statistics
$userStats = [
    'total' => $database->fetchOne("SELECT COUNT(*) as total FROM incident_reports WHERE user_id = ?", [$userId])['total'],
    'pending' => $database->fetchOne("SELECT COUNT(*) as total FROM incident_reports WHERE user_id = ? AND status = 'pending'", [$userId])['total'],
    'investigating' => $database->fetchOne("SELECT COUNT(*) as total FROM incident_reports WHERE user_id = ? AND status = 'investigating'", [$userId])['total'],
    'resolved' => $database->fetchOne("SELECT COUNT(*) as total FROM incident_reports WHERE user_id = ? AND status = 'resolved'", [$userId])['total'],
    'disputed' => $database->fetchOne("SELECT COUNT(*) as total FROM incident_reports WHERE user_id = ? AND status = 'disputed'", [$userId])['total']
];
?>
<!DOCTYPE html>
<html lang="<?= $lang ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Reports - SafeSpace Portal</title>

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

    <!-- Enhanced Liquid Glass Styles -->
    <style>
        /* Liquid Glass Theme Enhancements */
        .liquid-glass {
            background: linear-gradient(135deg, rgba(255, 255, 255, 0.1), rgba(255, 255, 255, 0.05));
            backdrop-filter: blur(20px);
            border: 1px solid rgba(255, 255, 255, 0.1);
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.1);
        }

        .liquid-glass-hover {
            transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
        }

        .liquid-glass-hover:hover {
            background: linear-gradient(135deg, rgba(255, 255, 255, 0.15), rgba(255, 255, 255, 0.08));
            border-color: rgba(255, 255, 255, 0.2);
            transform: translateY(-0.3px);
            box-shadow: 0 12px 40px rgba(0, 0, 0, 0.15);
        }

        /* Enhanced Statistics Cards */
        .stat-card {
            background: linear-gradient(135deg, rgba(255, 255, 255, 0.1), rgba(255, 255, 255, 0.05));
            backdrop-filter: blur(20px);
            border: 1px solid rgba(255, 255, 255, 0.1);
            border-radius: 20px;
            padding: 24px;
            transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
            position: relative;
            overflow: hidden;
        }

        .stat-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.1), transparent);
            transition: left 0.6s ease;
        }

        .stat-card:hover::before {
            left: 100%;
        }

        .stat-card:hover {
            background: linear-gradient(135deg, rgba(255, 255, 255, 0.15), rgba(255, 255, 255, 0.08));
            border-color: rgba(255, 255, 255, 0.2);
            transform: translateY(-0.5px);
            box-shadow: 0 16px 48px rgba(0, 0, 0, 0.2);
        }

        /* Enhanced Report Cards */
        .report-card {
            background: linear-gradient(135deg, rgba(255, 255, 255, 0.08), rgba(255, 255, 255, 0.03));
            backdrop-filter: blur(15px);
            border: 1px solid rgba(255, 255, 255, 0.1);
            border-radius: 16px;
            padding: 20px;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            position: relative;
            overflow: hidden;
        }

        .report-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 3px;
            background: linear-gradient(90deg, var(--accent-teal), var(--accent-purple));
            transform: scaleX(0);
            transition: transform 0.3s ease;
        }

        .report-card:hover::before {
            transform: scaleX(1);
        }

        .report-card:hover {
            background: linear-gradient(135deg, rgba(255, 255, 255, 0.12), rgba(255, 255, 255, 0.06));
            border-color: rgba(255, 255, 255, 0.2);
            transform: translateY(-0.3px);
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.15);
        }

        /* Enhanced Filter Section */
        .filter-section {
            background: linear-gradient(135deg, rgba(255, 255, 255, 0.1), rgba(255, 255, 255, 0.05));
            backdrop-filter: blur(20px);
            border: 1px solid rgba(255, 255, 255, 0.1);
            border-radius: 20px;
            padding: 24px;
            margin-bottom: 24px;
        }

        /* Enhanced Form Elements */
        .form-input-enhanced {
            background: rgba(255, 255, 255, 0.05);
            border: 2px solid rgba(255, 255, 255, 0.1);
            border-radius: 12px;
            padding: 12px 16px;
            color: white;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            backdrop-filter: blur(10px);
        }

        .form-input-enhanced:focus {
            background: rgba(255, 255, 255, 0.08);
            border-color: var(--accent-teal);
            box-shadow: 0 0 0 4px rgba(0, 212, 255, 0.1);
            transform: translateY(-1px);
        }

        .form-input-enhanced:hover {
            background: rgba(255, 255, 255, 0.08);
            border-color: rgba(255, 255, 255, 0.2);
        }

        /* Enhanced Buttons */
        .btn-liquid {
            background: linear-gradient(135deg, var(--accent-teal), var(--accent-purple));
            border: none;
            border-radius: 12px;
            padding: 12px 24px;
            color: white;
            font-weight: 600;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            position: relative;
            overflow: hidden;
        }

        .btn-liquid::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.2), transparent);
            transition: left 0.6s ease;
        }

        .btn-liquid:hover::before {
            left: 100%;
        }

        .btn-liquid:hover {
            transform: translateY(-0.3px);
            box-shadow: 0 8px 25px rgba(0, 212, 255, 0.3);
        }

        /* Status Badges */
        .status-badge {
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            backdrop-filter: blur(10px);
        }

        .status-pending {
            background: linear-gradient(135deg, rgba(245, 158, 11, 0.2), rgba(245, 158, 11, 0.1));
            border: 1px solid rgba(245, 158, 11, 0.3);
            color: #fbbf24;
        }

        .status-investigating {
            background: linear-gradient(135deg, rgba(139, 92, 246, 0.2), rgba(139, 92, 246, 0.1));
            border: 1px solid rgba(139, 92, 246, 0.3);
            color: #a78bfa;
        }

        .status-resolved {
            background: linear-gradient(135deg, rgba(34, 197, 94, 0.2), rgba(34, 197, 94, 0.1));
            border: 1px solid rgba(34, 197, 94, 0.3);
            color: #4ade80;
        }

        .status-disputed {
            background: linear-gradient(135deg, rgba(239, 68, 68, 0.2), rgba(239, 68, 68, 0.1));
            border: 1px solid rgba(239, 68, 68, 0.3);
            color: #f87171;
        }

        /* Enhanced Animations */
        .animate-slide-in {
            animation: slideIn 0.6s cubic-bezier(0.4, 0, 0.2, 1);
        }

        .animate-fade-in-up {
            animation: fadeInUp 0.8s cubic-bezier(0.4, 0, 0.2, 1);
        }

        .animate-scale-in {
            animation: scaleIn 0.5s cubic-bezier(0.4, 0, 0.2, 1);
        }

        @keyframes slideIn {
            from {
                opacity: 0;
                transform: translateX(-3px);
            }
            to {
                opacity: 1;
                transform: translateX(0);
            }
        }

        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(3px);
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

        /* Pagination Enhancement */
        .pagination-enhanced {
            background: linear-gradient(135deg, rgba(255, 255, 255, 0.1), rgba(255, 255, 255, 0.05));
            backdrop-filter: blur(15px);
            border: 1px solid rgba(255, 255, 255, 0.1);
            border-radius: 16px;
            padding: 16px;
        }

        .page-btn {
            background: rgba(255, 255, 255, 0.05);
            border: 1px solid rgba(255, 255, 255, 0.1);
            border-radius: 8px;
            padding: 8px 16px;
            color: white;
            transition: all 0.3s ease;
        }

        .page-btn:hover {
            background: rgba(255, 255, 255, 0.1);
            border-color: rgba(255, 255, 255, 0.2);
            transform: translateY(-0.2px);
        }

        .page-btn.active {
            background: linear-gradient(135deg, var(--accent-teal), var(--accent-purple));
            border-color: var(--accent-teal);
        }

        /* Responsive Enhancements */
        @media (max-width: 768px) {
            .stat-card {
                padding: 20px;
            }

            .report-card {
                padding: 16px;
            }

            .filter-section {
                padding: 20px;
            }
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
                    }
                }
            }
        }
    </script>
</head>
<body class="bg-gradient-to-br from-slate-900 via-purple-900 to-slate-900 min-h-screen">
    <!-- Header -->
    <header class="fixed top-0 left-0 right-0 z-50 bg-white/10 backdrop-blur-xl border-b border-white/20">
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
                    <a href="my_reports.php" class="text-white font-medium">My Reports</a>
                    <a href="dispute_center.php" class="text-white/70 hover:text-white transition-colors duration-200">Disputes</a>
                    <a href="safety_resources.php" class="text-white/70 hover:text-white transition-colors duration-200">Resources</a>
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
            <!-- Messages -->
            <?php if (isset($message)): ?>
                <div class="mb-8 card card-glass border-l-4 border-green-500">
                    <div class="card-body">
                        <div class="flex items-center">
                            <i data-lucide="check-circle" class="w-5 h-5 text-green-500 mr-3"></i>
                            <p class="text-green-300"><?= $message ?></p>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <?php if (isset($error)): ?>
                <div class="mb-8 card card-glass border-l-4 border-red-500">
                    <div class="card-body">
                        <div class="flex items-center">
                            <i data-lucide="alert-circle" class="w-5 h-5 text-red-500 mr-3"></i>
                            <p class="text-red-300"><?= $error ?></p>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Enhanced Header Section -->
            <section class="mb-8 animate-fade-in-up">
                <div class="flex items-center justify-between">
                    <div>
                        <h1 class="text-3xl font-bold text-white mb-3">My Incident Reports</h1>
                        <p class="text-lg text-white/70">
                            Manage and track your safety incident reports
                        </p>
                    </div>
                    <a href="report_incident.php" class="btn-liquid px-8 py-4 text-lg">
                        <i data-lucide="plus" class="w-5 h-5 mr-2"></i>
                        Report New Incident
                    </a>
                </div>
            </section>

            <!-- Enhanced Statistics Cards -->
            <section class="mb-8 animate-fade-in-up">
                <div class="grid grid-cols-1 md:grid-cols-5 gap-6">
                    <div class="stat-card animate-slide-in" style="animation-delay: 0.1s;">
                        <div class="flex items-center justify-between">
                            <div>
                                <p class="text-sm font-medium text-white/60 mb-1">Total Reports</p>
                                <p class="text-2xl font-bold text-white"><?= $userStats['total'] ?></p>
                            </div>
                            <div class="w-14 h-14 bg-gradient-to-r from-primary-500 to-primary-600 rounded-2xl flex items-center justify-center shadow-lg">
                                <i data-lucide="file-text" class="w-7 h-7 text-white"></i>
                            </div>
                        </div>
                    </div>

                    <div class="stat-card animate-slide-in" style="animation-delay: 0.2s;">
                        <div class="flex items-center justify-between">
                            <div>
                                <p class="text-sm font-medium text-white/60 mb-1">Pending</p>
                                <p class="text-2xl font-bold text-white"><?= $userStats['pending'] ?></p>
                            </div>
                            <div class="w-14 h-14 bg-gradient-to-r from-warning-500 to-warning-600 rounded-2xl flex items-center justify-center shadow-lg">
                                <i data-lucide="clock" class="w-7 h-7 text-white"></i>
                            </div>
                        </div>
                    </div>

                    <div class="stat-card animate-slide-in" style="animation-delay: 0.3s;">
                        <div class="flex items-center justify-between">
                            <div>
                                <p class="text-sm font-medium text-white/60 mb-1">Investigating</p>
                                <p class="text-2xl font-bold text-white"><?= $userStats['investigating'] ?></p>
                            </div>
                            <div class="w-14 h-14 bg-gradient-to-r from-secondary-500 to-secondary-600 rounded-2xl flex items-center justify-center shadow-lg">
                                <i data-lucide="search" class="w-7 h-7 text-white"></i>
                            </div>
                        </div>
                    </div>

                    <div class="stat-card animate-slide-in" style="animation-delay: 0.4s;">
                        <div class="flex items-center justify-between">
                            <div>
                                <p class="text-sm font-medium text-white/60 mb-1">Resolved</p>
                                <p class="text-2xl font-bold text-white"><?= $userStats['resolved'] ?></p>
                            </div>
                            <div class="w-14 h-14 bg-gradient-to-r from-accent-500 to-accent-600 rounded-2xl flex items-center justify-center shadow-lg">
                                <i data-lucide="check-circle" class="w-7 h-7 text-white"></i>
                            </div>
                        </div>
                    </div>

                    <div class="stat-card animate-slide-in" style="animation-delay: 0.5s;">
                        <div class="flex items-center justify-between">
                            <div>
                                <p class="text-sm font-medium text-white/60 mb-1">Disputed</p>
                                <p class="text-2xl font-bold text-white"><?= $userStats['disputed'] ?></p>
                            </div>
                            <div class="w-14 h-14 bg-gradient-to-r from-red-500 to-red-600 rounded-2xl flex items-center justify-center shadow-lg">
                                <i data-lucide="alert-triangle" class="w-7 h-7 text-white"></i>
                            </div>
                        </div>
                    </div>
                </div>
            </section>

            <!-- Enhanced Filters and Search -->
            <section class="mb-8 animate-fade-in-up" style="animation-delay: 0.6s;">
                <div class="filter-section">
                    <div class="flex items-center space-x-4 mb-6">
                        <div class="w-12 h-12 bg-gradient-to-r from-accent-500 to-accent-600 rounded-2xl flex items-center justify-center shadow-lg">
                            <i data-lucide="filter" class="w-6 h-6 text-white"></i>
                        </div>
                        <div>
                            <h3 class="text-xl font-bold text-white">Filter & Search</h3>
                            <p class="text-sm text-white/60 mt-1">Find specific reports quickly</p>
                        </div>
                    </div>

                    <form method="GET" class="space-y-6">
                        <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
                            <!-- Search -->
                            <div class="md:col-span-2">
                                <label class="text-sm font-semibold text-white mb-2 block">Search Reports</label>
                                <div class="relative">
                                    <input type="text" name="search" value="<?= htmlspecialchars($searchQuery) ?>"
                                           class="form-input-enhanced w-full pl-10" placeholder="Search by title or description...">
                                    <i data-lucide="search" class="w-5 h-5 absolute left-3 top-1/2 transform -translate-y-1/2 text-white/50"></i>
                                </div>
                            </div>

                            <!-- Status Filter -->
                            <div>
                                <label class="text-sm font-semibold text-white mb-2 block">Status</label>
                                <select name="status" class="form-input-enhanced w-full">
                                    <option value="">All Status</option>
                                    <option value="pending" <?= $statusFilter === 'pending' ? 'selected' : '' ?>>Pending</option>
                                    <option value="investigating" <?= $statusFilter === 'investigating' ? 'selected' : '' ?>>Investigating</option>
                                    <option value="resolved" <?= $statusFilter === 'resolved' ? 'selected' : '' ?>>Resolved</option>
                                    <option value="closed" <?= $statusFilter === 'closed' ? 'selected' : '' ?>>Closed</option>
                                    <option value="disputed" <?= $statusFilter === 'disputed' ? 'selected' : '' ?>>Disputed</option>
                                </select>
                            </div>

                            <!-- Category Filter -->
                            <div>
                                <label class="text-sm font-semibold text-white mb-2 block">Category</label>
                                <select name="category" class="form-input-enhanced w-full">
                                    <option value="">All Categories</option>
                                    <option value="harassment" <?= $categoryFilter === 'harassment' ? 'selected' : '' ?>>Harassment</option>
                                    <option value="assault" <?= $categoryFilter === 'assault' ? 'selected' : '' ?>>Assault</option>
                                    <option value="theft" <?= $categoryFilter === 'theft' ? 'selected' : '' ?>>Theft</option>
                                    <option value="vandalism" <?= $categoryFilter === 'vandalism' ? 'selected' : '' ?>>Vandalism</option>
                                    <option value="stalking" <?= $categoryFilter === 'stalking' ? 'selected' : '' ?>>Stalking</option>
                                    <option value="cyberbullying" <?= $categoryFilter === 'cyberbullying' ? 'selected' : '' ?>>Cyberbullying</option>
                                    <option value="discrimination" <?= $categoryFilter === 'discrimination' ? 'selected' : '' ?>>Discrimination</option>
                                    <option value="other" <?= $categoryFilter === 'other' ? 'selected' : '' ?>>Other</option>
                                </select>
                            </div>
                        </div>

                        <div class="flex items-center justify-between">
                            <div class="flex items-center space-x-4">
                                <button type="submit" class="btn-liquid">
                                    <i data-lucide="filter" class="w-4 h-4 mr-2"></i>
                                    Apply Filters
                                </button>
                                <a href="my_reports.php" class="btn-liquid" style="background: linear-gradient(135deg, rgba(255, 255, 255, 0.1), rgba(255, 255, 255, 0.05)); border: 1px solid rgba(255, 255, 255, 0.2);">
                                    <i data-lucide="refresh-cw" class="w-4 h-4 mr-2"></i>
                                    Clear Filters
                                </a>
                            </div>
                            <div class="text-sm text-white/60">
                                Showing <?= count($reports) ?> of <?= $totalCount ?> reports
                            </div>
                        </div>
                    </form>
                </div>
            </section>

            <!-- Enhanced Reports List -->
            <section class="animate-fade-in-up" style="animation-delay: 0.7s;">
                <div class="liquid-glass rounded-3xl">
                    <div class="p-8">
                        <?php if (empty($reports)): ?>
                            <!-- Enhanced Empty State -->
                            <div class="text-center py-16">
                                <div class="w-24 h-24 bg-gradient-to-r from-primary-500 to-secondary-500 rounded-full flex items-center justify-center mx-auto mb-8 shadow-lg">
                                    <i data-lucide="file-text" class="w-12 h-12 text-white"></i>
                                </div>
                                <h3 class="text-2xl font-bold text-white mb-4">No Reports Found</h3>
                                <p class="text-lg text-white/70 mb-8 max-w-md mx-auto">
                                    <?php if ($searchQuery || $statusFilter || $categoryFilter): ?>
                                        No reports match your current filters. Try adjusting your search criteria.
                                    <?php else: ?>
                                        You haven't filed any incident reports yet. When you do, they'll appear here.
                                    <?php endif; ?>
                                </p>
                                <div class="flex items-center justify-center space-x-4">
                                    <a href="report_incident.php" class="btn-liquid px-8 py-4 text-lg">
                                        <i data-lucide="plus" class="w-5 h-5 mr-2"></i>
                                        Report New Incident
                                    </a>
                                    <?php if ($searchQuery || $statusFilter || $categoryFilter): ?>
                                        <a href="my_reports.php" class="btn-liquid px-6 py-3" style="background: linear-gradient(135deg, rgba(255, 255, 255, 0.1), rgba(255, 255, 255, 0.05)); border: 1px solid rgba(255, 255, 255, 0.2);">
                                            <i data-lucide="refresh-cw" class="w-4 h-4 mr-2"></i>
                                            Clear Filters
                                        </a>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php else: ?>
                            <!-- Enhanced Reports Grid -->
                            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                                <?php foreach ($reports as $index => $report): ?>
                                    <div class="report-card animate-slide-in" style="animation-delay: <?= $index * 0.1 ?>s;">
                                        <div class="flex items-start justify-between mb-4">
                                            <div class="flex-1">
                                                <h3 class="text-lg font-bold text-white mb-2">
                                                    <?= htmlspecialchars($report['title']) ?>
                                                </h3>
                                                <p class="text-sm text-white/70 mb-3">
                                                    <?= htmlspecialchars(substr($report['description'], 0, 120)) ?>...
                                                </p>
                                            </div>
                                            <div class="flex items-center space-x-2 ml-4">
                                                <span class="status-badge status-<?= $report['status'] ?>">
                                                    <?= ucfirst(str_replace('_', ' ', $report['status'])) ?>
                                                </span>
                                            </div>
                                        </div>

                                        <div class="flex items-center justify-between mb-4">
                                            <div class="flex items-center space-x-4">
                                                <span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-medium bg-white/10 text-white/80 border border-white/20 backdrop-blur-sm">
                                                    <?= ucfirst($report['category']) ?>
                                                </span>
                                                <span class="text-xs text-white/50 flex items-center">
                                                    <i data-lucide="calendar" class="w-3 h-3 mr-1"></i>
                                                    <?= date('M j, Y', strtotime($report['reported_date'])) ?>
                                                </span>
                                            </div>
                                            <div class="text-xs text-white/50">
                                                ID: #<?= $report['id'] ?>
                                            </div>
                                        </div>

                                        <div class="flex items-center justify-between">
                                            <div class="flex items-center space-x-2">
                                                <a href="view_report.php?id=<?= $report['id'] ?>"
                                                   class="btn-liquid px-4 py-2 text-sm">
                                                    <i data-lucide="eye" class="w-4 h-4 mr-1"></i>
                                                    View Details
                                                </a>
                                                <?php if ($report['status'] === 'pending'): ?>
                                                    <a href="edit_report.php?id=<?= $report['id'] ?>"
                                                       class="btn-liquid px-4 py-2 text-sm" style="background: linear-gradient(135deg, rgba(255, 255, 255, 0.1), rgba(255, 255, 255, 0.05)); border: 1px solid rgba(255, 255, 255, 0.2);">
                                                        <i data-lucide="edit" class="w-4 h-4 mr-1"></i>
                                                        Edit
                                                    </a>
                                                <?php endif; ?>
                                            </div>
                                            <div class="flex items-center space-x-2">
                                                <?php if ($report['status'] !== 'resolved' && $report['status'] !== 'closed'): ?>
                                                    <button onclick="deleteReport(<?= $report['id'] ?>)"
                                                            class="btn-liquid px-3 py-2 text-sm" style="background: linear-gradient(135deg, rgba(239, 68, 68, 0.2), rgba(239, 68, 68, 0.1)); border: 1px solid rgba(239, 68, 68, 0.3); color: #f87171;">
                                                        <i data-lucide="trash-2" class="w-4 h-4"></i>
                                                    </button>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>

                            <!-- Enhanced Pagination -->
                            <?php if ($totalPages > 1): ?>
                                <div class="pagination-enhanced mt-8">
                                    <div class="flex items-center justify-between">
                                        <div class="text-sm text-white/60">
                                            Page <?= $page ?> of <?= $totalPages ?>
                                        </div>
                                        <div class="flex items-center space-x-2">
                                            <?php if ($page > 1): ?>
                                                <a href="?<?= http_build_query(array_merge($_GET, ['page' => $page - 1])) ?>"
                                                   class="page-btn">
                                                    <i data-lucide="chevron-left" class="w-4 h-4 mr-1"></i>
                                                    Previous
                                                </a>
                                            <?php endif; ?>

                                            <?php
                                            $startPage = max(1, $page - 2);
                                            $endPage = min($totalPages, $page + 2);

                                            for ($i = $startPage; $i <= $endPage; $i++):
                                            ?>
                                                <a href="?<?= http_build_query(array_merge($_GET, ['page' => $i])) ?>"
                                                   class="page-btn <?= $i === $page ? 'active' : '' ?>">
                                                    <?= $i ?>
                                                </a>
                                            <?php endfor; ?>

                                            <?php if ($page < $totalPages): ?>
                                                <a href="?<?= http_build_query(array_merge($_GET, ['page' => $page + 1])) ?>"
                                                   class="page-btn">
                                                    Next
                                                    <i data-lucide="chevron-right" class="w-4 h-4 ml-1"></i>
                                                </a>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                            <?php endif; ?>
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

        // Delete report confirmation
        function deleteReport(reportId) {
            if (confirm('Are you sure you want to delete this report? This action cannot be undone.')) {
                window.location.href = `my_reports.php?action=delete&id=${reportId}`;
            }
        }

        // Auto-submit form on filter change
        document.addEventListener('DOMContentLoaded', function() {
            const statusFilter = document.querySelector('select[name="status"]');
            const categoryFilter = document.querySelector('select[name="category"]');

            function autoSubmit() {
                // Add a small delay to prevent too many requests
                clearTimeout(window.filterTimeout);
                window.filterTimeout = setTimeout(() => {
                    document.querySelector('form').submit();
                }, 500);
            }

            statusFilter.addEventListener('change', autoSubmit);
            categoryFilter.addEventListener('change', autoSubmit);
        });
    </script>

    <!-- Toast notification bridge (PHP → JS) -->
    <script src="js/toast.js"></script>
    <?php if (!empty($message)): ?>
    <div data-toast="<?= htmlspecialchars($message, ENT_QUOTES) ?>" data-toast-type="success" hidden></div>
    <?php endif; ?>
    <?php if (!empty($error)): ?>
    <div data-toast="<?= htmlspecialchars($error, ENT_QUOTES) ?>" data-toast-type="error" hidden></div>
    <?php endif; ?>
</body>
</html>
