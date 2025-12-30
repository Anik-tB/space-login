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
// For Firebase integration, we'll use a default user ID for demo
// In production, you'd get the Firebase UID and map it to your database user
$userId = $_SESSION['user_id'] ?? 1; // Default for demo

// Fetch real data from database
$dashboardStats = $models->getDashboardStats();
$recentActivity = $models->getRecentActivity(5);
$summaryActivities = $models->getRecentActivity(100); // For the summary counts
$reportCategories = $models->getReportCategories();
$activeAlerts = $models->getActiveAlerts();
$safeSpaces = $models->getSafeSpaces();
$userNotifications = $models->getUserNotifications($userId, 3);

// Calculate additional statistics
$totalReports = $dashboardStats['total_reports'] ?? 0;
$activeAlertsCount = $dashboardStats['active_alerts'] ?? 0;
$safeSpacesCount = $dashboardStats['safe_spaces'] ?? 0;
$recentReports = $dashboardStats['recent_reports'] ?? 0;

// Calculate response time (mock calculation for now)
$avgResponseTime = 2.3; // This would be calculated from actual data

// Get user information (will be overridden by Firebase auth)
$user = $models->getUserById($userId);
$userName = $user ? ($user['display_name'] ?? explode('@', $user['email'])[0]) : 'User';
$userEmail = $user ? $user['email'] : 'user@example.com';
// Display name for navigation (prefer session display_name if updated, then database display_name, fallback to email)
$userDisplayName = $_SESSION['display_name'] ?? ($user ? (!empty($user['display_name']) ? $user['display_name'] : $user['email']) : 'User');

// Sync session with database if database has display_name but session doesn't
if ($user && !isset($_SESSION['display_name']) && !empty($user['display_name'])) {
    $_SESSION['display_name'] = $user['display_name'];
    $userDisplayName = $user['display_name'];
}
?>
<!DOCTYPE html>
<html lang="<?= $lang ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $L['welcome'] ?> - SafeSpace Portal</title>

    <!-- Favicon -->
    <link rel="icon" type="image/svg+xml" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><text y='.9em' font-size='90'>🛡️</text></svg>">

    <!-- Modern Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&family=Poppins:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">

    <!-- Modern Icons -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/lucide/0.263.1/lucide.min.css">

    <!-- Chart.js for interactive charts -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

    <!-- ApexCharts for advanced visualizations -->
    <script src="https://cdn.jsdelivr.net/npm/apexcharts"></script>

    <!-- TailwindCSS for rapid styling -->
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="js/suppress-tailwind-warning.js"></script>

    <!-- Design System -->
    <link rel="stylesheet" href="design-system.css">

    <!-- Enhanced Dashboard Styles -->
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
                        },
                        accent: {
                            50: '#f0fdf4',
                            100: '#dcfce7',
                            200: '#bbf7d0',
                            300: '#86efac',
                            400: '#4ade80',
                            500: '#22c55e',
                            600: '#16a34a',
                            700: '#15803d',
                            800: '#166534',
                            900: '#14532d',
                        },
                        neutral: {
                            50: '#fafafa',
                            100: '#f5f5f5',
                            200: '#e5e5e5',
                            300: '#d4d4d4',
                            400: '#a3a3a3',
                            500: '#737373',
                            600: '#525252',
                            700: '#404040',
                            800: '#262626',
                            900: '#171717',
                        },
                        error: {
                            50: '#fef2f2',
                            100: '#fee2e2',
                            200: '#fecaca',
                            300: '#fca5a5',
                            400: '#f87171',
                            500: '#ef4444',
                            600: '#dc2626',
                            700: '#b91c1c',
                            800: '#991b1b',
                            900: '#7f1d1d',
                        }
                    },
                    fontFamily: {
                        'sans': ['Inter', 'system-ui', 'sans-serif'],
                        'display': ['Poppins', 'system-ui', 'sans-serif'],
                    },
                    animation: {
                        'fade-in': 'fadeIn 0.3s ease-out',
                        'slide-up': 'slideUp 0.25s ease-out',
                        'scale-in': 'scaleIn 0.15s ease-out',
                        'bounce-gentle': 'bounceGentle 2s infinite',
                    },
                    keyframes: {
                        fadeIn: {
                            '0%': { opacity: '0' },
                            '100%': { opacity: '1' },
                        },
                        slideUp: {
                            '0%': { transform: 'translateY(15px)', opacity: '0' },
                            '100%': { transform: 'translateY(0)', opacity: '1' },
                        },
                        scaleIn: {
                            '0%': { transform: 'scale(0.98)', opacity: '0' },
                            '100%': { transform: 'scale(1)', opacity: '1' },
                        },
                        bounceGentle: {
                            '0%, 100%': { transform: 'translateY(0)' },
                            '50%': { transform: 'translateY(-3px)' },
                        }
                    }
                }
            }
        }
    </script>

    <style>
        /* Custom CSS for enhanced Material Design 3 */
        :root {
            --md-elevation-1: 0 1px 3px 0 rgb(0 0 0 / 0.1), 0 1px 2px -1px rgb(0 0 0 / 0.1);
            --md-elevation-2: 0 4px 6px -1px rgb(0 0 0 / 0.1), 0 2px 4px -2px rgb(0 0 0 / 0.1);
            --md-elevation-3: 0 10px 15px -3px rgb(0 0 0 / 0.1), 0 4px 6px -4px rgb(0 0 0 / 0.1);
            --md-elevation-4: 0 20px 25px -5px rgb(0 0 0 / 0.1), 0 8px 10px -6px rgb(0 0 0 / 0.1);
        }

        /* Glassmorphism effect */
        .glass {
            background: rgba(255, 255, 255, 0.1);
            backdrop-filter: blur(20px);
            border: 1px solid rgba(255, 255, 255, 0.2);
        }

        /* Smooth scrolling */
        html {
            scroll-behavior: smooth;
        }

        /* Performance optimizations */
        * {
            box-sizing: border-box;
        }

        /* Optimize animations for performance */
        .animate-fade-in,
        .animate-slide-up,
        .animate-scale-in {
            will-change: transform, opacity;
        }

        /* Reduce motion for users who prefer it */
        @media (prefers-reduced-motion: reduce) {
            *,
            *::before,
            *::after {
                animation-duration: 0.01ms !important;
                animation-iteration-count: 1 !important;
                transition-duration: 0.01ms !important;
                scroll-behavior: auto !important;
            }
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

        /* Loading skeleton */
        .skeleton {
            background: linear-gradient(90deg, #f0f0f0 25%, #e0e0e0 50%, #f0f0f0 75%);
            background-size: 200% 100%;
            animation: loading 1.5s infinite;
        }

        @keyframes loading {
            0% { background-position: 200% 0; }
            100% { background-position: -200% 0; }
        }

        /* Card hover effects */
        .card-hover {
            transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
            will-change: transform, box-shadow;
        }

        .card-hover:hover {
            transform: translateY(-2px);
            box-shadow: var(--md-elevation-4);
        }

        /* Button ripple effect */
        .btn-ripple {
            position: relative;
            overflow: hidden;
        }

        .btn-ripple::after {
            content: '';
            position: absolute;
            top: 50%;
            left: 50%;
            width: 0;
            height: 0;
            border-radius: 50%;
            background: rgba(255, 255, 255, 0.3);
            transform: translate(-50%, -50%);
            transition: width 0.4s, height 0.4s;
        }

        .btn-ripple:active::after {
            width: 300px;
            height: 300px;
        }

        /* Status indicators */
        .status-online {
            background: linear-gradient(135deg, #22c55e, #16a34a);
        }

        .status-warning {
            background: linear-gradient(135deg, #f59e0b, #d97706);
        }

        .status-error {
            background: linear-gradient(135deg, #ef4444, #dc2626);
        }

        /* Gradient text */
        .gradient-text {
            background: linear-gradient(135deg, #0ea5e9, #d946ef);
            -webkit-background-clip: text;
            background-clip: text;
            color: transparent;
        }

        /* Floating animation */
        .float {
            animation: float 3s ease-in-out infinite;
        }

        @keyframes float {
            0%, 100% { transform: translateY(0px); }
            50% { transform: translateY(-10px); }
        }

        /* Toast notifications */
        .toast {
            position: fixed;
            top: 20px;
            right: 20px;
            z-index: 1080;
            background: var(--bg-elevated);
            border: 1px solid var(--border-primary);
            border-radius: var(--radius-lg);
            padding: var(--space-4);
            box-shadow: var(--shadow-lg);
            transform: translateX(100%);
            transition: transform 0.2s ease;
            max-width: 400px;
            will-change: transform;
        }

        .toast-show {
            transform: translateX(0);
        }

        .toast-content {
            display: flex;
            align-items: center;
            gap: var(--space-3);
        }

        .toast-close {
        position: absolute;
            top: var(--space-2);
            right: var(--space-2);
            background: none;
            border: none;
            color: var(--text-tertiary);
            cursor: pointer;
            padding: var(--space-1);
            border-radius: var(--radius-base);
        }

        .toast-close:hover {
            background: var(--bg-secondary);
            color: var(--text-primary);
        }

        /* Tooltip system */
        .tooltip {
            position: absolute;
            background: var(--bg-elevated);
            color: var(--text-primary);
            padding: var(--space-2) var(--space-3);
            border-radius: var(--radius-lg);
            font-size: var(--text-xs);
            box-shadow: var(--shadow-lg);
            border: 1px solid var(--border-primary);
            z-index: 1070;
            opacity: 0;
            transform: translateY(-8px);
            transition: all 0.15s ease;
            pointer-events: none;
            white-space: nowrap;
            will-change: transform, opacity;
        }

        .tooltip-show {
            opacity: 1;
            transform: translateY(0);
        }

        /* Chart Styles */
        .chart-container {
            position: relative;
            height: 300px;
            width: 100%;
        }

        .chart-container canvas {
            border-radius: 12px;
        }

        /* Timeframe Button Styles */
        .timeframe-btn {
            color: rgba(255, 255, 255, 0.7);
            transition: all 0.2s ease;
        }

        .timeframe-btn:hover {
            color: white;
            background: rgba(255, 255, 255, 0.1);
        }

        .timeframe-btn.active {
            color: white;
            background: #3b82f6;
            box-shadow: 0 0 0 1px rgba(59, 130, 246, 0.3);
        }

        /* Chart Loading Animation */
        .chart-loading {
            position: absolute;
            inset: 0;
            display: flex;
            align-items: center;
            justify-content: center;
            background: rgba(255, 255, 255, 0.05);
            backdrop-filter: blur(4px);
            border-radius: 12px;
            z-index: 10;
        }

        /* Real-time Data Indicator */
        .realtime-indicator {
            position: absolute;
            top: 10px;
            right: 10px;
            display: flex;
            align-items: center;
            gap: 6px;
            padding: 4px 8px;
            background: rgba(34, 197, 94, 0.2);
            border: 1px solid rgba(34, 197, 94, 0.3);
            border-radius: 12px;
            font-size: 11px;
            color: #22c55e;
            z-index: 5;
        }

        .realtime-dot {
            width: 6px;
            height: 6px;
            background: #22c55e;
            border-radius: 50%;
            animation: pulse 2s infinite;
        }

        @keyframes pulse {
            0%, 100% { opacity: 1; transform: scale(1); }
            50% { opacity: 0.5; transform: scale(1.2); }
        }

        /* Quick actions modal */
        .quick-actions-modal {
            position: fixed;
        top: 0;
        left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0, 0, 0, 0.5);
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 1050;
            opacity: 0;
            visibility: hidden;
            transition: all 0.3s ease;
        }

        .quick-actions-show {
            opacity: 1;
            visibility: visible;
        }

        .quick-actions-content {
            background: var(--bg-elevated);
            border-radius: var(--radius-xl);
            padding: var(--space-8);
            box-shadow: var(--shadow-xl);
            border: 1px solid var(--border-primary);
            max-width: 500px;
            width: 90%;
        }

        .quick-actions-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: var(--space-4);
            margin-top: var(--space-6);
        }

        .quick-actions-grid button {
        display: flex;
            align-items: center;
            gap: var(--space-3);
            padding: var(--space-4);
            background: var(--bg-secondary);
            border: 1px solid var(--border-primary);
            border-radius: var(--radius-lg);
            color: var(--text-primary);
            cursor: pointer;
            transition: all 0.2s ease;
        }

        .quick-actions-grid button:hover {
            background: var(--bg-tertiary);
            transform: translateY(-2px);
            box-shadow: var(--shadow-md);
        }

        /* Skip link for accessibility */
        .skip-link {
        position: absolute;
            top: -40px;
            left: 6px;
            background: var(--primary-500);
            color: white;
            padding: 8px;
            text-decoration: none;
            border-radius: var(--radius-base);
            z-index: 1001;
        }

        .skip-link:focus {
            top: 6px;
        }

        /* Ripple animation */
        @keyframes ripple {
            to {
                transform: scale(4);
                opacity: 0;
            }
        }

        /* Responsive grid adjustments */
        @media (max-width: 768px) {
            .grid-cols-1 {
                grid-template-columns: repeat(1, minmax(0, 1fr));
            }
        }

        @media (min-width: 769px) and (max-width: 1024px) {
            .grid-cols-2 {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }
        }

        /* Dark mode support */
        @media (prefers-color-scheme: dark) {
            .auto-dark {
                background-color: #0f172a;
                color: #f8fafc;
            }
        }

        /* Accessibility improvements */
        .focus-visible:focus-visible {
            outline: 2px solid #0ea5e9;
            outline-offset: 2px;
        }

        /* High contrast mode support */
        @media (prefers-contrast: high) {
            .card {
                border: 2px solid currentColor;
            }
        }

        /* Additional button variants */
        .btn-warning {
            background: linear-gradient(135deg, #f59e0b, #d97706);
            color: white;
            box-shadow: 0 4px 14px 0 rgb(245 158 11 / 0.25);
        }

        .btn-warning:hover:not(:disabled) {
            background: linear-gradient(135deg, #d97706, #b45309);
            transform: translateY(-1px);
            box-shadow: var(--shadow-lg), 0 4px 14px 0 rgb(245 158 11 / 0.25);
        }

        /* Reduced motion support */
        @media (prefers-reduced-motion: reduce) {
            * {
                animation-duration: 0.01ms !important;
                animation-iteration-count: 1 !important;
                transition-duration: 0.01ms !important;
            }
        }

        /* Small button variant */
        .btn-sm {
            padding: var(--space-2) var(--space-3);
            font-size: var(--text-sm);
            border-radius: var(--radius-base);
        }

        /* Spinner animation - Optimized */
        .spinner {
            width: 24px;
            height: 24px;
            border: 2px solid var(--border-primary);
            border-top: 2px solid var(--primary-500);
            border-radius: 50%;
            animation: spin 0.8s linear infinite;
            will-change: transform;
        }

        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }

        /* Loaded state optimizations */
        .loaded .animate-fade-in {
            animation-duration: 0.2s;
        }

        .loaded .animate-slide-up {
            animation-duration: 0.2s;
        }

        /* Optimize chart loading */
        .chart-loading {
            transition: opacity 0.2s ease;
        }

        /* Professional Quick Actions Styles */
        .priority-action {
            border: 1px solid rgba(255, 255, 255, 0.1);
            background: linear-gradient(135deg, rgba(255, 255, 255, 0.05) 0%, rgba(255, 255, 255, 0.02) 100%);
            position: relative;
            overflow: hidden;
        }

        .priority-action::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 2px;
            background: linear-gradient(90deg, #ef4444, #f59e0b);
            opacity: 0.8;
        }

        .priority-action:hover {
            transform: translateY(-3px);
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.3);
            border-color: rgba(255, 255, 255, 0.2);
        }

        .priority-action .card-icon {
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.3);
        }

        /* Quick Actions Grid Enhancements */
        .quick-actions-grid .card {
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            border: 1px solid rgba(255, 255, 255, 0.05);
        }

        .quick-actions-grid .card:hover {
            border-color: rgba(255, 255, 255, 0.15);
            background: linear-gradient(135deg, rgba(255, 255, 255, 0.08) 0%, rgba(255, 255, 255, 0.03) 100%);
        }

        /* Status indicators */
        .status-online {
            background: #10b981;
            box-shadow: 0 0 0 2px rgba(16, 185, 129, 0.3);
        }

        .status-warning {
            background: #f59e0b;
            box-shadow: 0 0 0 2px rgba(245, 158, 11, 0.3);
        }

        /* Badge styles */
        .badge-priority {
            background: rgba(239, 68, 68, 0.2);
            color: #f87171;
            border: 1px solid rgba(239, 68, 68, 0.3);
        }

        .badge-live {
            background: rgba(245, 158, 11, 0.2);
            color: #fbbf24;
            border: 1px solid rgba(245, 158, 11, 0.3);
        }

        .badge-anonymous {
            background: rgba(16, 185, 129, 0.2);
            color: #34d399;
            border: 1px solid rgba(16, 185, 129, 0.3);
        }

        /* Keyboard shortcut hints */
        .keyboard-shortcut {
            font-family: 'Monaco', 'Menlo', 'Ubuntu Mono', monospace;
            background: rgba(255, 255, 255, 0.1);
            border: 1px solid rgba(255, 255, 255, 0.2);
            border-radius: 4px;
            padding: 2px 6px;
            font-size: 10px;
            color: var(--text-tertiary);
        }

                @keyframes pulse {
            0%, 100% {
                transform: scale(1);
                opacity: 1;
            }
            50% {
                transform: scale(1.1);
                opacity: 0.8;
            }
        }

        /* Professional Table Styles */
        .category-table {
            border-collapse: separate;
            border-spacing: 0;
        }

        .category-table th {
            position: sticky;
            top: 0;
            background: rgba(255, 255, 255, 0.05);
            backdrop-filter: blur(10px);
            z-index: 10;
        }

        .category-table tr:hover {
            background: rgba(255, 255, 255, 0.05);
            transform: translateY(-1px);
            transition: all 0.2s ease;
        }

        .category-table td, .category-table th {
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
        }

        /* Status Badge Styles */
        .status-badge {
            display: inline-flex;
            align-items: center;
            padding: 0.25rem 0.75rem;
            border-radius: 9999px;
            font-size: 0.75rem;
            font-weight: 500;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }

        .status-high {
            background: rgba(239, 68, 68, 0.2);
            color: #f87171;
            border: 1px solid rgba(239, 68, 68, 0.3);
        }

        .status-medium {
            background: rgba(245, 158, 11, 0.2);
            color: #fbbf24;
            border: 1px solid rgba(245, 158, 11, 0.3);
        }

        .status-low {
            background: rgba(59, 130, 246, 0.2);
            color: #60a5fa;
            border: 1px solid rgba(59, 130, 246, 0.3);
        }

        .status-minimal {
            background: rgba(107, 114, 128, 0.2);
            color: #9ca3af;
            border: 1px solid rgba(107, 114, 128, 0.3);
        }

        /* Responsive Table */
        @media (max-width: 768px) {
            .category-table {
                font-size: 0.75rem;
            }

            .category-table th,
            .category-table td {
                padding: 0.5rem 0.25rem;
            }

            .category-table .mobile-hidden {
                display: none;
            }
        }

        /* Modern Navigation Panel Styles */
        #main-nav {
            position: relative;
            overflow: visible;
            padding: 4px;
            height: 52px;
            display: flex;
            align-items: center;
            gap: 4px;
        }

        .nav-item {
            position: relative;
            min-width: 70px;
            padding: 8px 12px;
            cursor: pointer;
            z-index: 2;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            gap: 4px;
        }

        .nav-icon-wrapper {
            position: relative;
            width: 18px;
            height: 18px;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.4s cubic-bezier(0.34, 1.56, 0.64, 1);
            z-index: 3;
        }


        .nav-item.active {
            z-index: 10;
            /*background: rgba(255, 255, 255, 0.2);
            border-radius: 16px;*/
        }

        .nav-item:not(.active) .nav-icon-wrapper:hover {
            transform: translateY(-1px);
        }

        .nav-item.active .nav-icon-wrapper {
            transform: scale(1.05);
            z-index: 4;
        }

        .nav-item.active .nav-label {
            color: white !important;
            font-weight: 600;
        }

        .nav-item.active .nav-icon-wrapper i {
            color: white !important;
        }

        .nav-item:not(.active) .nav-icon-wrapper i {
            color: rgba(255, 255, 255, 0.7);
        }

        .nav-item:not(.active) .nav-label {
            color: rgba(255, 255, 255, 0.6);
        }

        .nav-item:not(.active):hover {
            background: rgba(255, 255, 255, 0.08);
            border-radius: 16px;
        }

        /* Create the embedded curve effect */
        #main-nav {
            background: rgba(255, 255, 255, 0.1);
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
        }
    </style>
</head>
<body class="min-h-screen font-sans" style="background: var(--bg-primary); color: var(--text-primary);">
    <!-- Background Pattern -->
    <div class="fixed inset-0 bg-pattern opacity-50"></div>

    <!-- Header Navigation -->
    <header class="fixed top-0 left-0 right-0 z-50 glass" style="border-bottom: 1px solid var(--border-primary);">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="flex items-center justify-between h-[64px] gap-4">
                <!-- Logo -->
                <a href="dashboard.php" class="flex items-center space-x-3 flex-shrink-0 group/logo transition-all duration-300 hover:scale-105">
                    <div class="w-10 h-10 bg-gradient-to-r from-cyan-400 via-blue-500 to-purple-600 rounded-2xl flex items-center justify-center shadow-lg ring-2 ring-cyan-500/20 group-hover/logo:ring-cyan-500/40 transition-all duration-300">
                        <i data-lucide="shield" class="w-6 h-6 text-white drop-shadow-lg"></i>
                    </div>
                    <div class="flex items-baseline">
                        <span class="text-xl font-display font-bold bg-gradient-to-r from-cyan-400 to-blue-500 bg-clip-text text-transparent">Safe</span>
                        <span class="text-xl font-display font-bold bg-gradient-to-r from-purple-500 to-pink-500 bg-clip-text text-transparent">Space</span>
</div>
                </a>

                <!-- Navigation -->
                <nav class="hidden md:flex items-center justify-center bg-white/10 backdrop-blur-xl rounded-full border border-white/20 shadow-lg relative flex-1 max-w-2xl mx-auto" id="main-nav">
                    <a href="#dashboard" class="nav-item relative flex flex-col items-center justify-center transition-all duration-300 group active" data-nav="dashboard">
                        <div class="nav-icon-wrapper relative z-10 flex items-center justify-center transition-all duration-300">
                            <i data-lucide="layout-dashboard" class="w-4 h-4 text-white/70 group-hover:text-white transition-colors duration-300"></i>
                        </div>
                        <span class="nav-label text-xs font-medium text-white/60 group-hover:text-white/80 transition-colors duration-300 whitespace-nowrap">Dashboard</span>
                    </a>
                   <a href="report_incident.php" class="nav-item relative flex flex-col items-center justify-center transition-all duration-300 group" data-nav="reports">
                        <div class="nav-icon-wrapper relative z-10 flex items-center justify-center transition-all duration-300">
                            <i data-lucide="file-text" class="w-4 h-4 text-white/70 group-hover:text-white transition-colors duration-300"></i>
                        </div>
                        <span class="nav-label text-xs font-medium text-white/60 group-hover:text-white/80 transition-colors duration-300 whitespace-nowrap">Reports</span>
                    </a>
                    <a href="#alerts" class="nav-item relative flex flex-col items-center justify-center transition-all duration-300 group" data-nav="alerts">
                        <div class="nav-icon-wrapper relative z-10 flex items-center justify-center transition-all duration-300">
                            <i data-lucide="bell" class="w-4 h-4 text-white/70 group-hover:text-white transition-colors duration-300"></i>
                        </div>
                        <span class="nav-label text-xs font-medium text-white/60 group-hover:text-white/80 transition-colors duration-300 whitespace-nowrap">Alerts</span>
                    </a>
                    <a href="safety_resources.php" class="nav-item relative flex flex-col items-center justify-center transition-all duration-300 group" data-nav="resources">
                        <div class="nav-icon-wrapper relative z-10 flex items-center justify-center transition-all duration-300">
                            <i data-lucide="bookmark" class="w-4 h-4 text-white/70 group-hover:text-white transition-colors duration-300"></i>
                        </div>
                        <span class="nav-label text-xs font-medium text-white/60 group-hover:text-white/80 transition-colors duration-300 whitespace-nowrap">Resources</span>
                    </a>
                    <?php
                    // Add admin link if user is admin
                    $userId = $_SESSION['user_id'] ?? null;
                    if ($userId) {
                        try {
                            $adminCheck = $database->fetchOne("SELECT email FROM users WHERE id = ?", [$userId]);
                            if ($adminCheck && (strpos(strtolower($adminCheck['email'] ?? ''), 'admin') !== false || strtolower($adminCheck['email'] ?? '') === 'admin@safespace.com')) {
                                echo '<a href="admin_dashboard.php" class="nav-item relative flex flex-col items-center justify-center transition-all duration-300 group" data-nav="admin">
                                    <div class="nav-icon-wrapper relative z-10 flex items-center justify-center transition-all duration-300">
                                        <i data-lucide="shield-check" class="w-4 h-4 text-white/70 group-hover:text-white transition-colors duration-300"></i>
                                    </div>
                                    <span class="nav-label text-xs font-medium text-white/60 group-hover:text-white/80 transition-colors duration-300 whitespace-nowrap">Admin</span>
                                </a>';
                            }
                        } catch (Exception $e) {}
                    }
                    ?>
                </nav>

                <div class="flex items-center space-x-2">

    <button id="theme-toggle"
            style="width: 52px; height: 52px; min-width: 52px; min-height: 52px;"
            class="flex flex-shrink-0 items-center justify-center rounded-full bg-white/5 hover:bg-white/10 border border-white/10 hover:border-primary-500/50 transition-all duration-300 group p-0"
            data-tooltip="Toggle theme">
        <i data-lucide="moon" class="w-5 h-5 text-white/70 group-hover:text-primary-400 transition-colors duration-300" id="theme-icon"></i>
    </button>

    <button id="dnd-toggle"
            style="width: 52px; height: 52px; min-width: 52px; min-height: 52px;"
            class="flex flex-shrink-0 items-center justify-center rounded-full bg-white/5 hover:bg-white/10 border border-white/10 hover:border-primary-500/50 transition-all duration-300 group p-0"
            data-tooltip="Do Not Disturb">
        <i data-lucide="bell" class="w-5 h-5 text-white/70 group-hover:text-primary-400 transition-colors duration-300" id="dnd-icon"></i>
    </button>

    <div class="relative">
        <form method="get" action="set_lang.php" id="lang-form" class="hidden">
            <input type="hidden" name="lang" id="lang-input" value="<?= $lang ?>">
        </form>
        <button type="button" id="lang-toggle" class="relative h-[52px] w-auto flex items-center space-x-2 rounded-full bg-white/5 hover:bg-white/10 border border-white/10 hover:border-primary-500/50 transition-all duration-300 group px-3.5 overflow-hidden">
            <span class="absolute inset-0 bg-gradient-to-r from-primary-500/0 to-secondary-500/0 group-hover:from-primary-500/10 group-hover:to-secondary-500/10 transition-all duration-300"></span>
            <span class="text-sm leading-none relative z-10 transition-all duration-300" id="lang-flag" style="display: inline-block; transform-origin: center;"><?= $lang === 'en' ? '🇬🇧' : '🇧🇩' ?></span>
            <span class="text-xs font-medium text-white/70 group-hover:text-white transition-all duration-300 relative z-10" id="lang-text"><?= $lang === 'en' ? 'English' : 'বাংলা' ?></span>
            <i data-lucide="chevron-down" class="w-3.5 h-3.5 text-white/50 group-hover:text-primary-400 transition-all duration-300 relative z-10 ml-0.5" id="lang-chevron" style="transform-origin: center;"></i>
        </button>
        <div id="lang-menu" class="absolute right-0 mt-2 w-48 bg-white/10 backdrop-blur-xl border border-white/20 rounded-xl shadow-2xl opacity-0 invisible transition-all duration-300 transform scale-95 translate-y-2 origin-top-right">
            <div class="py-2">
                <button type="button" data-lang="en" class="lang-option flex items-center space-x-3 px-4 py-2.5 text-sm hover:bg-white/10 transition-all duration-200 w-full text-left group/item <?= $lang === 'en' ? 'bg-primary-500/10 border-l-2 border-primary-500' : '' ?>">
                    <span class="text-xl">🇬🇧</span>
                    <span class="flex-1 text-white/90 group-hover/item:text-white font-medium">English</span>
                    <?php if ($lang === 'en'): ?>
                        <i data-lucide="check" class="w-4 h-4 text-primary-400"></i>
                    <?php endif; ?>
                </button>
                <button type="button" data-lang="bn" class="lang-option flex items-center space-x-3 px-4 py-2.5 text-sm hover:bg-white/10 transition-all duration-200 w-full text-left group/item <?= $lang === 'bn' ? 'bg-primary-500/10 border-l-2 border-primary-500' : '' ?>">
                    <span class="text-xl">🇧🇩</span>
                    <span class="flex-1 text-white/90 group-hover/item:text-white font-medium">বাংলা</span>
                    <?php if ($lang === 'bn'): ?>
                        <i data-lucide="check" class="w-4 h-4 text-primary-400"></i>
                    <?php endif; ?>
                </button>
            </div>
        </div>
    </div>

    <div class="relative">
        <button id="user-menu-btn" class="flex items-center space-x-2.5 px-3.5 h-[52px] rounded-full bg-white/5 hover:bg-white/10 border border-white/10 hover:border-primary-500/50 transition-all duration-300 group" data-tooltip="User menu">
            <div class="w-6 h-6 bg-gradient-to-r from-primary-500 to-secondary-500 rounded-full flex items-center justify-center shadow-lg ring-2 ring-primary-500/20 group-hover:ring-primary-500/50 transition-all duration-300 flex-shrink-0">
                <i data-lucide="user" class="w-3.5 h-3.5 text-white"></i>
            </div>
            <span id="userEmail" class="text-xs font-medium text-white/90 group-hover:text-white transition-colors duration-300 whitespace-nowrap max-w-[100px] truncate"><?= htmlspecialchars($userDisplayName) ?></span>
            <i data-lucide="chevron-down" class="w-3.5 h-3.5 text-white/50 group-hover:text-primary-400 transition-all duration-300 group-hover:rotate-180 flex-shrink-0"></i>
        </button>
        <div id="user-menu" class="absolute right-0 mt-2 w-56 bg-white/10 backdrop-blur-xl border border-white/20 rounded-xl shadow-2xl opacity-0 invisible transition-all duration-300 transform scale-95 translate-y-2">
            <div class="py-2">
                <div class="px-4 py-3 border-b border-white/10 mb-1">
                    <p class="text-xs text-white/50 uppercase tracking-wider mb-1">Account</p>
                    <p class="text-sm font-medium text-white"><?= htmlspecialchars($userDisplayName) ?></p>
                    <p class="text-xs text-white/50"><?= htmlspecialchars($userEmail) ?></p>
                </div>
                <a href="profile.php" class="flex items-center px-4 py-2.5 text-sm hover:bg-white/10 transition-all duration-200 group/item">
                    <i data-lucide="user" class="w-4 h-4 mr-3 text-white/70 group-hover/item:text-primary-400 transition-colors"></i>
                    <span class="text-white/90 group-hover/item:text-white">Profile</span>
                </a>
                <a href="settings.php" class="flex items-center px-4 py-2.5 text-sm hover:bg-white/10 transition-all duration-200 group/item">
                    <i data-lucide="settings" class="w-4 h-4 mr-3 text-white/70 group-hover/item:text-primary-400 transition-colors"></i>
                    <span class="text-white/90 group-hover/item:text-white">Settings</span>
                </a>
                <button id="toggle-notifications" class="flex items-center px-4 py-2.5 text-sm hover:bg-white/10 transition-all duration-200 w-full text-left group/item">
                    <i data-lucide="bell" class="w-4 h-4 mr-3 text-white/70 group-hover/item:text-primary-400 transition-colors"></i>
                    <span id="notification-status" class="text-white/90 group-hover/item:text-white">Enable Notifications</span>
                </button>
                <button id="notification-settings" class="flex items-center px-4 py-2.5 text-sm hover:bg-white/10 transition-all duration-200 w-full text-left group/item">
                    <i data-lucide="settings" class="w-4 h-4 mr-3 text-white/70 group-hover/item:text-primary-400 transition-colors"></i>
                    <span class="text-white/90 group-hover/item:text-white">Notification Settings</span>
                </button>
                <hr class="border-white/10 my-2">
                <a href="logout.php" class="flex items-center px-4 py-2.5 text-sm text-red-400 hover:bg-red-500/10 transition-all duration-200 group/item">
                    <i data-lucide="log-out" class="w-4 h-4 mr-3 group-hover/item:rotate-12 transition-transform"></i>
                    <span class="group-hover/item:text-red-300">Logout</span>
                </a>
            </div>
        </div>
    </div>

</div>
            </div>
        </div>
    </header>

    <!-- Main Content -->
    <main class="pt-20 pb-8">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <!-- Welcome Section -->
            <section class="mb-8 animate-fade-in" style="animation-duration: 0.4s;">
                <div class="card card-glass p-8 text-center">
                    <h1 class="heading-1 mb-4 gradient-text">
                        Welcome back, <span id="userName" style="color: var(--text-primary);"><?= htmlspecialchars($userName) ?></span>! 👋
                    </h1>
                    <p class="body-large max-w-2xl mx-auto" style="color: var(--text-secondary);">
                        You're in a safe space. What would you like to do today?
                    </p>
  </div>
            </section>

            <!-- Enhanced Quick Stats with Advanced Analytics -->
            <section class="mb-8 animate-slide-up" style="animation-duration: 0.3s;">
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6">
                    <!-- Total Reports with Advanced Metrics -->
                    <div class="card card-glass card-hover stats-card enhanced-stats" data-stat="reports">
                        <div class="flex items-center justify-between">
                            <div class="flex-1">
                                <p class="label-medium" style="color: var(--text-tertiary);">Total Reports</p>
                                <p class="heading-3 counter" data-target="<?= $totalReports ?>" data-duration="2000" style="color: var(--text-primary);"><?= $totalReports ?></p>
                                <div class="mt-2 flex items-center space-x-2">
                                    <div class="w-16 h-1 bg-gray-200 rounded-full overflow-hidden">
                                        <div class="h-full bg-gradient-to-r from-green-400 to-blue-500 rounded-full" style="width: 75%"></div>
              </div>
                                    <span class="text-xs" style="color: var(--text-tertiary);">75% resolved</span>
                                </div>
                            </div>
                            <div class="w-12 h-12 bg-gradient-to-r from-primary-500 to-primary-600 rounded-lg flex items-center justify-center card-icon relative">
                                <i data-lucide="file-text" class="w-6 h-6 text-white"></i>
                                <div class="absolute -top-1 -right-1 w-4 h-4 bg-green-500 rounded-full flex items-center justify-center">
                                    <i data-lucide="check" class="w-2 h-2 text-white"></i>
            </div>
          </div>
                        </div>
                        <div class="mt-4 flex items-center justify-between text-sm">
                            <span class="text-accent-500 flex items-center">
                                <i data-lucide="trending-up" class="w-4 h-4 mr-1"></i>
                                +12%
                            </span>
                            <span class="text-xs" style="color: var(--text-tertiary);">from last month</span>
              </div>
            </div>

                    <!-- Active Alerts with Priority Levels -->
                    <div class="card card-glass card-hover stats-card enhanced-stats" data-stat="alerts">
                        <div class="flex items-center justify-between">
                            <div class="flex-1">
                                <p class="label-medium" style="color: var(--text-tertiary);">Active Alerts</p>
                                <p class="heading-3 counter" data-target="<?= $activeAlertsCount ?>" data-duration="2000" style="color: var(--text-primary);"><?= $activeAlertsCount ?></p>
                                <div class="mt-2 flex items-center space-x-1">
                                    <div class="w-2 h-2 bg-red-500 rounded-full"></div>
                                    <div class="w-2 h-2 bg-yellow-500 rounded-full"></div>
                                    <div class="w-2 h-2 bg-green-500 rounded-full"></div>
                                    <span class="text-xs ml-2" style="color: var(--text-tertiary);">Priority levels</span>
          </div>
                            </div>
                            <div class="w-12 h-12 bg-gradient-to-r from-warning-500 to-warning-600 rounded-lg flex items-center justify-center card-icon relative">
                                <i data-lucide="bell" class="w-6 h-6 text-white"></i>
                                <div class="absolute -top-1 -right-1 w-4 h-4 bg-red-500 rounded-full flex items-center justify-center animate-pulse">
                                    <span class="text-xs text-white font-bold">3</span>
                            </div>
                        </div>
                        </div>
                        <div class="mt-4 flex items-center justify-between text-sm">
                            <span class="text-warning-500 flex items-center">
                                <i data-lucide="alert-triangle" class="w-4 h-4 mr-1"></i>
                                3 new
                            </span>
                            <span class="text-xs" style="color: var(--text-tertiary);">in your area</span>
        </div>
      </div>

                    <!-- Safe Spaces with Location Services -->
                    <div class="card card-glass card-hover stats-card enhanced-stats" data-stat="safe-spaces">
                        <div class="flex items-center justify-between">
                            <div class="flex-1">
                                <p class="label-medium" style="color: var(--text-tertiary);">Safe Spaces</p>
                                <p class="heading-3 counter" data-target="<?= $safeSpacesCount ?>" data-duration="2000" style="color: var(--text-primary);"><?= $safeSpacesCount ?></p>
                                <div class="mt-2 flex items-center space-x-2">
                                    <div class="flex items-center space-x-1">
                                        <i data-lucide="wifi" class="w-3 h-3 text-green-500"></i>
                                        <span class="text-xs" style="color: var(--text-tertiary);">Connected</span>
              </div>
                                </div>
                            </div>
                            <div class="w-12 h-12 bg-gradient-to-r from-accent-500 to-accent-600 rounded-lg flex items-center justify-center card-icon relative">
                                <i data-lucide="map-pin" class="w-6 h-6 text-white"></i>
                                <div class="absolute -bottom-1 -right-1 w-5 h-5 bg-blue-500 rounded-full flex items-center justify-center">
                                    <i data-lucide="navigation" class="w-2 h-2 text-white"></i>
            </div>
          </div>
                        </div>
                        <div class="mt-4 flex items-center justify-between text-sm">
                            <span class="text-accent-500 flex items-center">
                                <i data-lucide="navigation" class="w-4 h-4 mr-1"></i>
                                2 nearby
                            </span>
                            <span class="text-xs" style="color: var(--text-tertiary);">within 1km</span>
              </div>
            </div>

                    <!-- Response Time with Performance Metrics -->
                    <div class="card card-glass card-hover stats-card enhanced-stats" data-stat="response-time">
                        <div class="flex items-center justify-between">
                            <div class="flex-1">
                                <p class="label-medium" style="color: var(--text-tertiary);">Avg Response</p>
                                <p class="heading-3" style="color: var(--text-primary);"><?= $avgResponseTime ?>m</p>
                                <div class="mt-2 flex items-center space-x-2">
                                    <div class="w-16 h-1 bg-gray-200 rounded-full overflow-hidden">
                                        <div class="h-full bg-gradient-to-r from-green-500 to-yellow-500 rounded-full" style="width: 85%"></div>
          </div>
                                    <span class="text-xs" style="color: var(--text-tertiary);">85% efficiency</span>
                                </div>
                            </div>
                            <div class="w-12 h-12 bg-gradient-to-r from-secondary-500 to-secondary-600 rounded-lg flex items-center justify-center card-icon relative">
                                <i data-lucide="clock" class="w-6 h-6 text-white"></i>
                                <div class="absolute -top-1 -right-1 w-4 h-4 bg-green-500 rounded-full flex items-center justify-center">
                                    <i data-lucide="zap" class="w-2 h-2 text-white"></i>
        </div>
      </div>
                        </div>
                        <div class="mt-4 flex items-center justify-between text-sm">
                            <span class="text-secondary-500 flex items-center">
                                <i data-lucide="zap" class="w-4 h-4 mr-1"></i>
                                -15%
                            </span>
                            <span class="text-xs" style="color: var(--text-tertiary);">faster than avg</span>
                        </div>
                    </div>
                </div>
            </section>



            <!-- Trend Chart Section -->
            <section class="mb-8 animate-slide-up" style="animation-duration: 0.3s;">
                <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                    <!-- Incident Trends Chart -->
                    <div class="lg:col-span-2">
                        <div class="card card-glass card-hover">
                            <div class="card-body">
                                <div class="flex items-center justify-between mb-6">
                                    <div class="flex items-center">
                                        <div class="w-12 h-12 bg-gradient-to-r from-blue-500 to-purple-600 rounded-xl flex items-center justify-center mr-4">
                                            <i data-lucide="trending-up" class="w-6 h-6 text-white"></i>
                                        </div>
                                        <div>
                                            <h3 class="heading-3" style="color: var(--text-primary);">Incident Trends</h3>
                                            <p class="body-small" style="color: var(--text-tertiary);">Real-time incident analysis & forecasting</p>
                                        </div>
                                    </div>
                                    <div class="flex items-center space-x-2">
                                        <div class="flex items-center space-x-1 bg-white/5 rounded-lg p-1">
                                            <button class="timeframe-btn px-3 py-1 rounded text-xs font-medium transition-all duration-200 hover:bg-white/10" data-period="7D">7D</button>
                                            <button class="timeframe-btn active px-3 py-1 rounded text-xs font-medium transition-all duration-200 bg-blue-500 text-white" data-period="30D">30D</button>
                                            <button class="timeframe-btn px-3 py-1 rounded text-xs font-medium transition-all duration-200 hover:bg-white/10" data-period="90D">90D</button>
                                        </div>
                                        <button id="view-trends-details" class="text-primary-400 hover:text-primary-300 text-sm font-medium transition-colors duration-200 flex items-center space-x-1">
                                            <span>Details</span>
                                            <i data-lucide="chevron-right" class="w-4 h-4"></i>
                                        </button>
                                    </div>
                                </div>

                                <!-- Chart Container -->
                                <div class="chart-container mb-6 relative">
                                    <div class="realtime-indicator">
                                        <div class="realtime-dot"></div>
                                        <span>LIVE</span>
                                    </div>
                                    <canvas id="incidentTrendsChart" width="400" height="200"></canvas>
                                    <div id="chartLoading" class="chart-loading">
                                        <div class="spinner"></div>
                                    </div>
                                </div>

                                <!-- Enhanced Statistics Row -->
                                <div class="grid grid-cols-1 lg:grid-cols-3 gap-4 mb-6">

                                    <div class="text-center p-4 bg-white/5 rounded-lg">
                                        <div class="text-2xl font-bold text-green-400 mb-1" id="trendPercentage">+12%</div>
                                        <div class="text-xs" style="color: var(--text-secondary);">Growth Rate</div>
                                        <div class="text-xs text-blue-400 mt-1">Accelerating</div>
                                    </div>
                                    <div class="text-center p-4 bg-white/5 rounded-lg">
                                        <div class="text-2xl font-bold text-white mb-1" id="activeAlerts"><?= $activeAlertsCount ?></div>
                                        <div class="text-xs" style="color: var(--text-secondary);">Active Alerts</div>
                                        <div class="text-xs text-yellow-400 mt-1" id="alertsTrend">3 new today</div>
                                    </div>
                                    <div class="text-center p-4 bg-white/5 rounded-lg">
                                        <div class="text-2xl font-bold text-purple-400 mb-1" id="avgResponse">2.3m</div>
                                        <div class="text-xs" style="color: var(--text-secondary);">Avg Response</div>
                                        <div class="text-xs text-green-400 mt-1">-15% faster</div>
                                    </div>
                                </div>

                                <!-- Quick Trends Table -->
                                <div class="overflow-hidden">
                                    <table class="w-full text-sm category-table">
                                        <thead>
                                            <tr class="border-b border-white/10">
                                                <th class="text-left py-2 px-3 font-medium" style="color: var(--text-secondary);">Metric</th>
                                                <th class="text-center py-2 px-3 font-medium" style="color: var(--text-secondary);">Current</th>
                                                <th class="text-center py-2 px-3 font-medium" style="color: var(--text-secondary);">Previous</th>
                                                <th class="text-center py-2 px-3 font-medium" style="color: var(--text-secondary);">Change</th>
                                            </tr>
                                        </thead>
                                     <tbody id="quick-trends-body" class="divide-y divide-white/5">

    <tr class="hover:bg-white/5 transition-colors duration-200">
        <td class="py-2 px-3">
            <div class="flex items-center space-x-2">
                <div class="w-6 h-6 bg-red-500/20 rounded flex items-center justify-center">
                    <i data-lucide="alert-triangle" class="w-3 h-3 text-red-500"></i>
                </div>
                <span style="color: var(--text-primary);">Critical Incidents</span>
            </div>
        </td>
        <td class="py-2 px-3 text-center">
            <span id="quick-critical-current" class="font-semibold" style="color: var(--text-primary);">0</span>
        </td>
        <td class="py-2 px-3 text-center">
            <span id="quick-critical-previous" style="color: var(--text-tertiary);">0</span>
        </td>
        <td class="py-2 px-3 text-center">
            <div id="quick-critical-change" class="flex items-center justify-center space-x-1">
                <i data-lucide="minus" class="w-3 h-3 text-gray-500"></i>
                <span class="text-xs font-medium text-gray-500">0%</span>
            </div>
        </td>
    </tr>
    <tr class="hover:bg-white/5 transition-colors duration-200">
        <td class="py-2 px-3">
            <div class="flex items-center space-x-2">
                <div class="w-6 h-6 bg-green-500/20 rounded flex items-center justify-center">
                    <i data-lucide="clock" class="w-3 h-3 text-green-500"></i>
                </div>
                <span style="color: var(--text-primary);">Resolution Rate</span>
            </div>
        </td>
        <td id="quick-resolution-current" class="py-2 px-3 text-center" colspan="3">
            <span class="font-semibold" style="color: var(--text-primary);">0%</span>
        </td>
    </tr>
</tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Category Distribution -->
                    <div class="lg:col-span-1">
                        <div class="card card-glass">
                            <div class="card-body">
                                <div class="flex items-center justify-between mb-6">
                                    <h3 class="heading-3" style="color: var(--text-primary);">Report Categories</h3>
                                    <button id="view-all-categories" class="text-primary-400 hover:text-primary-300 text-sm font-medium transition-colors duration-200 flex items-center space-x-1">
                                        <span>View All</span>
                                        <i data-lucide="chevron-right" class="w-4 h-4"></i>
                                    </button>
                                </div>

                                <!-- Professional Table View -->
                                <div class="overflow-hidden">
                                    <table class="w-full text-sm category-table">
                                        <thead>
                                            <tr class="border-b border-white/10">
                                                <th class="text-left py-3 px-2 font-medium" style="color: var(--text-secondary);">Category</th>
                                                <th class="text-center py-3 px-2 font-medium" style="color: var(--text-secondary);">Count</th>
                                                <th class="text-center py-3 px-2 font-medium" style="color: var(--text-secondary);">%</th>
                                                <th class="text-center py-3 px-2 font-medium" style="color: var(--text-secondary);">Trend</th>
                                            </tr>
                                        </thead>
                                        <tbody class="divide-y divide-white/5">
                                            <?php
                                            $totalCategoryReports = array_sum(array_column($reportCategories, 'count'));
                                            $categoryColors = [
                                                'harassment' => ['color' => 'error', 'icon' => 'user-x'],
                                                'assault' => ['color' => 'primary', 'icon' => 'alert-triangle'],
                                                'theft' => ['color' => 'warning', 'icon' => 'package'],
                                                'vandalism' => ['color' => 'orange', 'icon' => 'hammer'],
                                                'stalking' => ['color' => 'purple', 'icon' => 'eye'],
                                                'cyberbullying' => ['color' => 'blue', 'icon' => 'monitor'],
                                                'discrimination' => ['color' => 'indigo', 'icon' => 'users'],
                                                'other' => ['color' => 'secondary', 'icon' => 'file-text']
                                            ];

                                            $displayLimit = 5; // Show only first 5 categories
                                            $displayedCategories = array_slice($reportCategories, 0, $displayLimit);

                                            foreach ($displayedCategories as $index => $category):
                                                $percentage = $totalCategoryReports > 0 ? round(($category['count'] / $totalCategoryReports) * 100) : 0;
                                                $color = $categoryColors[$category['category']] ?? ['color' => 'secondary', 'icon' => 'file-text'];
                                                $trend = rand(-15, 25); // Simulated trend data
                                            ?>
                                            <tr class="hover:bg-white/5 transition-colors duration-200">
                                                <td class="py-3 px-2">
                                                    <div class="flex items-center space-x-3">
                                                        <div class="w-8 h-8 bg-<?= $color['color'] ?>-500/20 rounded-lg flex items-center justify-center">
                                                            <i data-lucide="<?= $color['icon'] ?>" class="w-4 h-4 text-<?= $color['color'] ?>-500"></i>
                                                        </div>
                                                        <div>
                                                            <div class="font-medium" style="color: var(--text-primary);"><?= ucfirst($category['category']) ?></div>
                                                            <div class="text-xs" style="color: var(--text-tertiary);"><?= $category['count'] ?> reports</div>
                                                        </div>
                                                    </div>
                                                </td>
                                                <td class="py-3 px-2 text-center">
                                                    <span class="font-semibold" style="color: var(--text-primary);"><?= number_format($category['count']) ?></span>
                                                </td>
                                                <td class="py-3 px-2 text-center">
                                                    <div class="flex items-center justify-center space-x-2">
                                                        <div class="w-12 h-1.5 rounded-full" style="background: var(--bg-tertiary);">
                                                            <div class="bg-<?= $color['color'] ?>-500 h-1.5 rounded-full" style="width: <?= $percentage ?>%"></div>
                                                        </div>
                                                        <span class="text-xs font-medium" style="color: var(--text-tertiary);"><?= $percentage ?>%</span>
                                                    </div>
                                                </td>
                                                <td class="py-3 px-2 text-center">
                                                    <div class="flex items-center justify-center space-x-1">
                                                        <i data-lucide="<?= $trend > 0 ? 'trending-up' : ($trend < 0 ? 'trending-down' : 'minus') ?>" class="w-3 h-3 <?= $trend > 0 ? 'text-green-500' : ($trend < 0 ? 'text-red-500' : 'text-gray-500') ?>"></i>
                                                        <span class="text-xs font-medium <?= $trend > 0 ? 'text-green-500' : ($trend < 0 ? 'text-red-500' : 'text-gray-500') ?>">
                                                            <?= $trend > 0 ? '+' . $trend : $trend ?>%
                                                        </span>
                                                    </div>
                                                </td>
                                            </tr>
                                            <?php endforeach; ?>

                                            <?php if (count($reportCategories) > $displayLimit): ?>
                                            <tr class="border-t border-white/10">
                                                <td colspan="4" class="py-3 px-2 text-center">
                                                    <button id="show-more-categories" class="text-primary-400 hover:text-primary-300 text-sm font-medium transition-colors duration-200">
                                                        Show <?= count($reportCategories) - $displayLimit ?> more categories
                                                    </button>
                                                </td>
                                            </tr>
                                            <?php endif; ?>
                                        </tbody>
                                    </table>

                                    <?php if (empty($reportCategories)): ?>
                                    <div class="text-center py-8">
                                        <div class="w-12 h-12 bg-gradient-to-r from-gray-500 to-gray-600 rounded-lg flex items-center justify-center mx-auto mb-3">
                                            <i data-lucide="bar-chart-3" class="w-6 h-6 text-white"></i>
                                        </div>
                                        <p class="text-sm" style="color: var(--text-tertiary);">No report categories yet</p>
                                        <p class="text-xs mt-1" style="color: var(--text-tertiary);">Categories will appear when reports are submitted</p>
                                    </div>
                                    <?php endif; ?>
                                </div>

                                <!-- Summary Stats -->
                                <div class="mt-6 pt-4 border-t border-white/10">
                                    <div class="grid grid-cols-2 gap-4">
                                        <div class="text-center">
                                            <div class="text-lg font-bold" style="color: var(--text-primary);"><?= count($reportCategories) ?></div>
                                            <div class="text-xs" style="color: var(--text-tertiary);">Categories</div>
                                        </div>
                                        <div class="text-center">
                                            <div class="text-lg font-bold" style="color: var(--text-primary);"><?= number_format($totalCategoryReports) ?></div>
                                            <div class="text-xs" style="color: var(--text-tertiary);">Total Reports</div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </section>

            <!-- Professional Quick Actions Section -->
            <section class="mb-8 animate-slide-up" style="animation-duration: 0.3s;">
                <div class="flex items-center justify-between mb-6">
                    <div class="flex items-center space-x-4">
                        <div class="w-12 h-12 bg-gradient-to-r from-blue-500 to-purple-600 rounded-xl flex items-center justify-center">
                            <i data-lucide="zap" class="w-6 h-6 text-white"></i>
                        </div>
                        <div>
                            <h2 class="heading-2" style="color: var(--text-primary);">Quick Actions</h2>
                            <p class="body-small" style="color: var(--text-tertiary);">Essential tools for safety and support</p>
                        </div>
                    </div>
                    <div class="flex items-center space-x-2">
                        <button id="quick-actions-settings" class="text-primary-400 hover:text-primary-300 text-sm font-medium transition-colors duration-200 flex items-center space-x-1">
                            <i data-lucide="settings" class="w-4 h-4"></i>
                            <span>Customize</span>
                        </button>
                        <button id="quick-actions-help" class="text-primary-400 hover:text-primary-300 text-sm font-medium transition-colors duration-200 flex items-center space-x-1">
                            <i data-lucide="help-circle" class="w-4 h-4"></i>
                            <span>Help</span>
                        </button>
                    </div>
                </div>

                <!-- Priority Actions Row -->
                <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6">
                    <!-- Report Incident - Priority -->
                    <div class="card card-glass card-hover group cursor-pointer animate-on-scroll priority-action" onclick="window.location.href='report_incident.php'" data-tooltip="Report safety concerns anonymously">
                        <div class="card-body p-6">
                            <div class="flex items-start justify-between mb-4">
                                <div class="flex items-center space-x-4">
                                    <div class="relative">
                                        <div class="w-16 h-16 bg-gradient-to-r from-error-500 to-error-600 rounded-xl flex items-center justify-center group-hover:scale-110 transition-transform duration-200 card-icon shadow-lg">
                                            <i data-lucide="alert-triangle" class="w-8 h-8 text-white"></i>
                                        </div>
                                        <div class="absolute -top-2 -right-2 w-6 h-6 bg-red-500 rounded-full flex items-center justify-center">
                                            <span class="text-xs font-bold text-white">!</span>
                                        </div>
                                    </div>
                                    <div>
                                        <h3 class="heading-3 mb-1" style="color: var(--text-primary);">Report Incident</h3>
                                        <p class="body-medium mb-2" style="color: var(--text-secondary);">Report harassment or safety concerns anonymously and instantly.</p>
                                        <div class="flex items-center space-x-3">
                                            <span class="text-xs px-2 py-1 bg-red-500/20 text-red-400 rounded-full font-medium">Priority</span>
                                            <span class="text-xs px-2 py-1 bg-green-500/20 text-green-400 rounded-full font-medium">Anonymous</span>
                                        </div>
                                    </div>
                                </div>
                                <div class="flex flex-col items-end space-y-2">
                                    <div class="status-online w-3 h-3 rounded-full"></div>
                                    <div class="text-right">
                                        <div class="text-sm font-medium" style="color: var(--text-primary);">24/7 Available</div>
                                        <div class="text-xs" style="color: var(--text-tertiary);">Emergency Response</div>
                                    </div>
                                </div>
                            </div>
                            <div class="flex items-center justify-between pt-4 border-t border-white/10">
                                <div class="flex items-center text-error-400 text-sm font-medium">
                                    <span>Report Now</span>
                                    <i data-lucide="arrow-right" class="w-4 h-4 ml-2 group-hover:translate-x-1 transition-transform duration-200"></i>
                                </div>
                                <div class="text-xs" style="color: var(--text-tertiary);">Ctrl + R</div>
                            </div>
                        </div>
                    </div>

                    <!-- Community Alerts - Priority -->
                    <div class="card card-glass card-hover group cursor-pointer animate-on-scroll priority-action" onclick="window.location.href='community_alerts.php'" data-tooltip="View real-time safety alerts">
                        <div class="card-body p-6">
                            <div class="flex items-start justify-between mb-4">
                                <div class="flex items-center space-x-4">
                                    <div class="relative">
                                        <div class="w-16 h-16 bg-gradient-to-r from-warning-500 to-warning-600 rounded-xl flex items-center justify-center group-hover:scale-110 transition-transform duration-200 card-icon shadow-lg">
                                            <i data-lucide="bell" class="w-8 h-8 text-white"></i>
                                        </div>
                                        <div class="absolute -top-2 -right-2 w-6 h-6 bg-yellow-500 rounded-full flex items-center justify-center animate-pulse">
                                            <i data-lucide="zap" class="w-3 h-3 text-white"></i>
                                        </div>
                                    </div>
                                    <div>
                                        <h3 class="heading-3 mb-1" style="color: var(--text-primary);">Community Alerts</h3>
                                        <p class="body-medium mb-2" style="color: var(--text-secondary);">Stay informed about safety concerns in your area in real-time.</p>
                                        <div class="flex items-center space-x-3">
                                            <span class="text-xs px-2 py-1 bg-yellow-500/20 text-yellow-400 rounded-full font-medium">Live</span>
                                            <span class="text-xs px-2 py-1 bg-blue-500/20 text-blue-400 rounded-full font-medium">Real-time</span>
                                        </div>
                                    </div>
                                </div>
                                <div class="flex flex-col items-end space-y-2">
                                    <div class="status-warning w-3 h-3 rounded-full animate-pulse"></div>
                                    <div class="text-right">
                                        <div class="text-sm font-medium" style="color: var(--text-primary);">Active Alerts</div>
                                        <div class="text-xs" style="color: var(--text-tertiary);"><?= rand(1, 5) ?> in your area</div>
                                    </div>
                                </div>
                            </div>
                            <div class="flex items-center justify-between pt-4 border-t border-white/10">
                                <div class="flex items-center text-warning-400 text-sm font-medium">
                                    <span>View Alerts</span>
                                    <i data-lucide="arrow-right" class="w-4 h-4 ml-2 group-hover:translate-x-1 transition-transform duration-200"></i>
                                </div>
                                <div class="text-xs" style="color: var(--text-tertiary);">Ctrl + A</div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Standard Actions Grid -->
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
                    <!-- Community Groups -->
                    <div class="card card-glass card-hover group cursor-pointer animate-on-scroll" onclick="window.location.href='community_groups.php'" data-tooltip="Join neighborhood safety groups">
                        <div class="card-body p-5">
                            <div class="flex items-center justify-between mb-4">
                                <div class="w-12 h-12 bg-gradient-to-r from-green-500 to-emerald-600 rounded-lg flex items-center justify-center group-hover:scale-110 transition-transform duration-200 card-icon">
                                    <i data-lucide="users" class="w-6 h-6 text-white"></i>
                                </div>
                                <div class="status-online w-3 h-3 rounded-full"></div>
                            </div>
                            <h3 class="heading-4 mb-2" style="color: var(--text-primary);">Community Groups</h3>
                            <p class="body-small mb-4" style="color: var(--text-secondary);">Join neighborhood safety groups, share alerts, and work together for community safety.</p>
                            <div class="flex items-center justify-between">
                                <div class="flex items-center text-green-400 text-sm font-medium">
                                    <span>Join Groups</span>
                                    <i data-lucide="arrow-right" class="w-4 h-4 ml-2 group-hover:translate-x-1 transition-transform duration-200"></i>
                                </div>
                                <div class="text-xs px-2 py-1 bg-green-500/20 text-green-400 rounded-full">Active</div>
                            </div>
                        </div>
                    </div>

                    <!-- Legal Aid -->
                    <div class="card card-glass card-hover group cursor-pointer animate-on-scroll" onclick="window.location.href='legal_aid.php'" data-tooltip="Find legal aid providers and book consultations">
                        <div class="card-body p-5">
                            <div class="flex items-center justify-between mb-4">
                                <div class="w-12 h-12 bg-gradient-to-r from-purple-500 to-indigo-600 rounded-lg flex items-center justify-center group-hover:scale-110 transition-transform duration-200 card-icon">
                                    <i data-lucide="scale" class="w-6 h-6 text-white"></i>
                                </div>
                                <div class="status-online w-3 h-3 rounded-full"></div>
                            </div>
                            <h3 class="heading-4 mb-2" style="color: var(--text-primary);">Legal Aid</h3>
                            <p class="body-small mb-4" style="color: var(--text-secondary);">Find verified legal aid providers, book consultations, and access legal documents.</p>
                            <div class="flex items-center justify-between">
                                <div class="flex items-center text-purple-400 text-sm font-medium">
                                    <span>Get Help</span>
                                    <i data-lucide="arrow-right" class="w-4 h-4 ml-2 group-hover:translate-x-1 transition-transform duration-200"></i>
                                </div>
                                <div class="text-xs px-2 py-1 bg-purple-500/20 text-purple-400 rounded-full">Available</div>
                            </div>
                        </div>
                    </div>

                    <!-- Medical Support -->
                    <div class="card card-glass card-hover group cursor-pointer animate-on-scroll" onclick="window.location.href='medical_support.php'" data-tooltip="Find medical providers and psychological support">
                        <div class="card-body p-5">
                            <div class="flex items-center justify-between mb-4">
                                <div class="w-12 h-12 bg-gradient-to-r from-red-500 to-pink-600 rounded-lg flex items-center justify-center group-hover:scale-110 transition-transform duration-200 card-icon">
                                    <i data-lucide="heart-pulse" class="w-6 h-6 text-white"></i>
                                </div>
                                <div class="status-online w-3 h-3 rounded-full"></div>
                            </div>
                            <h3 class="heading-4 mb-2" style="color: var(--text-primary);">Medical Support</h3>
                            <p class="body-small mb-4" style="color: var(--text-secondary);">Find verified medical providers, trauma centers, and mental health professionals.</p>
                            <div class="flex items-center justify-between">
                                <div class="flex items-center text-red-400 text-sm font-medium">
                                    <span>Get Help</span>
                                    <i data-lucide="arrow-right" class="w-4 h-4 ml-2 group-hover:translate-x-1 transition-transform duration-200"></i>
                                </div>
                                <div class="text-xs px-2 py-1 bg-red-500/20 text-red-400 rounded-full">Available</div>
                            </div>
                        </div>
                    </div>

                    <!-- Safety Scores -->
                    <div class="card card-glass card-hover group cursor-pointer animate-on-scroll" onclick="window.location.href='safety_scores.php'" data-tooltip="View area safety scores and ratings">
                        <div class="card-body p-5">
                            <div class="flex items-center justify-between mb-4">
                                <div class="w-12 h-12 bg-gradient-to-r from-green-500 to-emerald-600 rounded-lg flex items-center justify-center group-hover:scale-110 transition-transform duration-200 card-icon">
                                    <i data-lucide="shield-check" class="w-6 h-6 text-white"></i>
                                </div>
                                <div class="status-online w-3 h-3 rounded-full"></div>
                            </div>
                            <h3 class="heading-4 mb-2" style="color: var(--text-primary);">Safety Scores</h3>
                            <p class="body-small mb-4" style="color: var(--text-secondary);">Compare safety scores across areas and rate your neighborhood.</p>
                            <div class="flex items-center justify-between">
                                <div class="flex items-center text-green-400 text-sm font-medium">
                                    <span>View Scores</span>
                                    <i data-lucide="arrow-right" class="w-4 h-4 ml-2 group-hover:translate-x-1 transition-transform duration-200"></i>
                                </div>
                                <div class="text-xs px-2 py-1 bg-green-500/20 text-green-400 rounded-full">Active</div>
                            </div>
                        </div>
                    </div>

                    <!-- Safety Education -->
                    <div class="card card-glass card-hover group cursor-pointer animate-on-scroll" onclick="window.location.href='safety_education.php'" data-tooltip="Access safety education courses and training modules">
                        <div class="card-body p-5">
                            <div class="flex items-center justify-between mb-4">
                                <div class="w-12 h-12 bg-gradient-to-r from-indigo-500 to-purple-600 rounded-lg flex items-center justify-center group-hover:scale-110 transition-transform duration-200 card-icon">
                                    <i data-lucide="graduation-cap" class="w-6 h-6 text-white"></i>
                                </div>
                                <div class="status-online w-3 h-3 rounded-full"></div>
                            </div>
                            <h3 class="heading-4 mb-2" style="color: var(--text-primary);">Safety Education</h3>
                            <p class="body-small mb-4" style="color: var(--text-secondary);">Learn safety skills, legal rights, and earn certificates through interactive courses.</p>
                            <div class="flex items-center justify-between">
                                <div class="flex items-center text-indigo-400 text-sm font-medium">
                                    <span>Start Learning</span>
                                    <i data-lucide="arrow-right" class="w-4 h-4 ml-2 group-hover:translate-x-1 transition-transform duration-200"></i>
                                </div>
                                <div class="text-xs px-2 py-1 bg-indigo-500/20 text-indigo-400 rounded-full">Available</div>
                            </div>
                        </div>
                    </div>

                    <!-- Panic Button -->
                    <div class="card card-glass card-hover group cursor-pointer animate-on-scroll" onclick="window.location.href='panic_button.php'" data-tooltip="Emergency panic button and SOS system">
                        <div class="card-body p-5">
                            <div class="flex items-center justify-between mb-4">
                                <div class="w-12 h-12 bg-gradient-to-r from-red-500 to-rose-600 rounded-lg flex items-center justify-center group-hover:scale-110 transition-transform duration-200 card-icon">
                                    <i data-lucide="alert-triangle" class="w-6 h-6 text-white"></i>
                                </div>
                                <div class="status-online w-3 h-3 rounded-full"></div>
                            </div>
                            <h3 class="heading-4 mb-2" style="color: var(--text-primary);">Panic Button</h3>
                            <p class="body-small mb-4" style="color: var(--text-secondary);">One-touch emergency alert to notify contacts and emergency services instantly.</p>
                            <div class="flex items-center justify-between">
                                <div class="flex items-center text-red-400 text-sm font-medium">
                                    <span>Emergency SOS</span>
                                    <i data-lucide="arrow-right" class="w-4 h-4 ml-2 group-hover:translate-x-1 transition-transform duration-200"></i>
                                </div>
                                <div class="text-xs px-2 py-1 bg-red-500/20 text-red-400 rounded-full">Active</div>
                            </div>
                        </div>
                    </div>

                    <!-- Walk With Me -->
                    <div class="card card-glass card-hover group cursor-pointer animate-on-scroll" onclick="window.location.href='walk_with_me.php'" data-tooltip="Share your live location with trusted contacts while walking">
                        <div class="card-body p-5">
                            <div class="flex items-center justify-between mb-4">
                                <div class="w-12 h-12 bg-gradient-to-r from-blue-500 to-cyan-600 rounded-lg flex items-center justify-center group-hover:scale-110 transition-transform duration-200 card-icon">
                                    <i data-lucide="navigation" class="w-6 h-6 text-white"></i>
                                </div>
                                <div class="status-online w-3 h-3 rounded-full"></div>
                            </div>
                            <h3 class="heading-4 mb-2" style="color: var(--text-primary);">Walk With Me</h3>
                            <p class="body-small mb-4" style="color: var(--text-secondary);">Share your live location with trusted contacts for safe walking. Includes SOS button.</p>
                            <div class="flex items-center justify-between">
                                <div class="flex items-center text-blue-400 text-sm font-medium">
                                    <span>Start Walk</span>
                                    <i data-lucide="arrow-right" class="w-4 h-4 ml-2 group-hover:translate-x-1 transition-transform duration-200"></i>
                                </div>
                                <div class="text-xs px-2 py-1 bg-blue-500/20 text-blue-400 rounded-full">Live</div>
                            </div>
                        </div>
                    </div>

                    <!-- Safe Spaces -->
                    <div class="card card-glass card-hover group cursor-pointer animate-on-scroll" onclick="window.location.href='safe_space_nearby.php'" data-tooltip="Find nearby safe locations">
                        <div class="card-body p-5">
                            <div class="flex items-center justify-between mb-4">
                                <div class="w-12 h-12 bg-gradient-to-r from-accent-500 to-accent-600 rounded-lg flex items-center justify-center group-hover:scale-110 transition-transform duration-200 card-icon">
                                    <i data-lucide="map-pin" class="w-6 h-6 text-white"></i>
                                </div>
                                <div class="status-online w-3 h-3 rounded-full"></div>
                            </div>
                            <h3 class="heading-4 mb-2" style="color: var(--text-primary);">Safe Spaces</h3>
                            <p class="body-small mb-4" style="color: var(--text-secondary);">Find verified safe spaces and partner locations near you.</p>
                            <div class="flex items-center justify-between">
                                <div class="flex items-center text-accent-400 text-sm font-medium">
                                    <span>Find Nearby</span>
                                    <i data-lucide="arrow-right" class="w-4 h-4 ml-2 group-hover:translate-x-1 transition-transform duration-200"></i>
                                </div>
                                <div class="text-xs px-2 py-1 bg-accent-500/20 text-accent-400 rounded-full"><?= rand(3, 8) ?> nearby</div>
                            </div>
                        </div>
                    </div>

                    <!-- My Reports -->
                    <div class="card card-glass card-hover group cursor-pointer animate-on-scroll" onclick="window.location.href='my_reports.php'" data-tooltip="Manage your incident reports">
                        <div class="card-body p-5">
                            <div class="flex items-center justify-between mb-4">
                                <div class="w-12 h-12 bg-gradient-to-r from-primary-500 to-primary-600 rounded-lg flex items-center justify-center group-hover:scale-110 transition-transform duration-200 card-icon">
                                    <i data-lucide="folder-open" class="w-6 h-6 text-white"></i>
                                </div>
                                <div class="status-online w-3 h-3 rounded-full"></div>
                            </div>
                            <h3 class="heading-4 mb-2" style="color: var(--text-primary);">My Reports</h3>
                            <p class="body-small mb-4" style="color: var(--text-secondary);">View, edit, or manage your past incident reports.</p>
                            <div class="flex items-center justify-between">
                                <div class="flex items-center text-primary-400 text-sm font-medium">
                                    <span>View History</span>
                                    <i data-lucide="arrow-right" class="w-4 h-4 ml-2 group-hover:translate-x-1 transition-transform duration-200"></i>
                                </div>
                                <div class="text-xs px-2 py-1 bg-primary-500/20 text-primary-400 rounded-full"><?= rand(0, 3) ?> reports</div>
                            </div>
                        </div>
                    </div>

                    <!-- Dispute Center -->
                    <div class="card card-glass card-hover group cursor-pointer animate-on-scroll" onclick="window.location.href='dispute_center.php'" data-tooltip="Appeal false reports">
                        <div class="card-body p-5">
                            <div class="flex items-center justify-between mb-4">
                                <div class="w-12 h-12 bg-gradient-to-r from-secondary-500 to-secondary-600 rounded-lg flex items-center justify-center group-hover:scale-110 transition-transform duration-200 card-icon">
                                    <i data-lucide="gavel" class="w-6 h-6 text-white"></i>
                                </div>
                                <div class="status-online w-3 h-3 rounded-full"></div>
                            </div>
                            <h3 class="heading-4 mb-2" style="color: var(--text-primary);">Dispute Center</h3>
                            <p class="body-small mb-4" style="color: var(--text-secondary);">Appeal false reports or clear your name from the system.</p>
                            <div class="flex items-center justify-between">
                                <div class="flex items-center text-secondary-400 text-sm font-medium">
                                    <span>File Appeal</span>
                                    <i data-lucide="arrow-right" class="w-4 h-4 ml-2 group-hover:translate-x-1 transition-transform duration-200"></i>
                                </div>
                                <div class="text-xs px-2 py-1 bg-secondary-500/20 text-secondary-400 rounded-full">Fair Process</div>
                            </div>
                        </div>
                    </div>

                    <!-- Safety Resources -->
                    <div class="card card-glass card-hover group cursor-pointer animate-on-scroll" onclick="window.location.href='safety_resources.php'" data-tooltip="Access safety guides and support">
                        <div class="card-body p-5">
                            <div class="flex items-center justify-between mb-4">
                                <div class="w-12 h-12 bg-gradient-to-r from-purple-500 to-purple-600 rounded-lg flex items-center justify-center group-hover:scale-110 transition-transform duration-200 card-icon">
                                    <i data-lucide="book-open" class="w-6 h-6 text-white"></i>
                                </div>
                                <div class="status-online w-3 h-3 rounded-full"></div>
                            </div>
                            <h3 class="heading-4 mb-2" style="color: var(--text-primary);">Safety Resources</h3>
                            <p class="body-small mb-4" style="color: var(--text-secondary);">Access helplines, guides, and support resources.</p>
                            <div class="flex items-center justify-between">
                                <div class="flex items-center text-purple-400 text-sm font-medium">
                                    <span>Browse Resources</span>
                                    <i data-lucide="arrow-right" class="w-4 h-4 ml-2 group-hover:translate-x-1 transition-transform duration-200"></i>
                                </div>
                                <div class="text-xs px-2 py-1 bg-purple-500/20 text-purple-400 rounded-full">24/7 Support</div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Quick Actions Summary -->
                <div class="mt-6 p-4 bg-white/5 rounded-lg border border-white/10">
                    <div class="flex items-center justify-between">
                        <div class="flex items-center space-x-4">
                            <div class="w-8 h-8 bg-blue-500/20 rounded-lg flex items-center justify-center">
                                <i data-lucide="info" class="w-4 h-4 text-blue-500"></i>
                            </div>
                            <div>
                                <h4 class="text-sm font-medium" style="color: var(--text-primary);">Quick Actions Overview</h4>
                                <p class="text-xs" style="color: var(--text-tertiary);">All actions are secure, anonymous, and available 24/7</p>
                            </div>
                        </div>
                        <div class="flex items-center space-x-2">
                            <span class="text-xs px-2 py-1 bg-green-500/20 text-green-400 rounded-full">System Online</span>
                            <span class="text-xs px-2 py-1 bg-blue-500/20 text-blue-400 rounded-full"><?= date('H:i') ?> UTC</span>
                        </div>
                    </div>
                </div>
            </section>

                        <!-- Recent Activity -->
            <section class="mb-8 animate-slide-up" style="animation-duration: 0.3s;">
                <div class="flex items-center justify-between mb-6">
                    <div class="flex items-center space-x-4">
                        <h2 class="heading-2" style="color: var(--text-primary);">Recent Activity</h2>
                        <div class="flex items-center space-x-2">
                            <span class="text-xs px-2 py-1 bg-green-500/20 text-green-400 rounded-full font-medium">Live</span>
                            <div class="w-2 h-2 bg-green-500 rounded-full animate-pulse"></div>
                        </div>
                    </div>
                    <div class="flex items-center space-x-3">
                        <div class="flex items-center space-x-1 bg-white/5 rounded-lg p-1">
                            <button class="activity-filter-btn active px-3 py-1 rounded text-xs font-medium transition-all duration-200 bg-blue-500 text-white" data-filter="all">All</button>
                            <button class="activity-filter-btn px-3 py-1 rounded text-xs font-medium transition-all duration-200 hover:bg-white/10" data-filter="reports">Reports</button>
                            <button class="activity-filter-btn px-3 py-1 rounded text-xs font-medium transition-all duration-200 hover:bg-white/10" data-filter="alerts">Alerts</button>
                            <button class="activity-filter-btn px-3 py-1 rounded text-xs font-medium transition-all duration-200 hover:bg-white/10" data-filter="users">Users</button>
                        </div>
                        <button id="view-activity-details" class="text-primary-400 hover:text-primary-300 text-sm font-medium transition-colors duration-200 flex items-center space-x-1">
                            <span>Details</span>
                            <i data-lucide="chevron-right" class="w-4 h-4"></i>
                        </button>
                    </div>
                </div>

                <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                    <!-- Activity Timeline -->
                    <div class="lg:col-span-2">
                     <div class="card card-glass">
                      <div class="card-body">

        <div class="flex justify-end mb-4" id="report-details-btn-container" style="display: none;">
            <button id="view-report-details-btn" class="px-4 py-2 text-sm font-medium transition-colors duration-200 bg-white/10 hover:bg-white/20 rounded-lg focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary-500">
                View Details
            </button>
        </div>
        <div class="space-y-4 activity-timeline" id="activity-timeline">
            <?php if (!empty($recentActivity)): ?>
                                        <?php
                                        $displayLimit = 5; // Show only first 5 activities
                                        $displayedActivities = array_slice($recentActivity, 0, $displayLimit);
                                        foreach ($displayedActivities as $index => $activity):
                                            $activityType = $activity['type'];
                                            $activityColors = [
                                                'report' => ['bg' => 'accent', 'icon' => 'file-text', 'status' => 'New Report'],
                                                'alert' => ['bg' => 'warning', 'icon' => 'bell', 'status' => 'Alert Created'],
                                                'user' => ['bg' => 'primary', 'icon' => 'user-plus', 'status' => 'User Registered'],
                                                'dispute' => ['bg' => 'secondary', 'icon' => 'gavel', 'status' => 'Dispute Filed']
                                            ];
                                            $color = $activityColors[$activityType] ?? ['bg' => 'secondary', 'icon' => 'activity', 'status' => 'Activity'];
                                        ?>
                                        <div class="activity-item flex items-center space-x-4 p-4 rounded-lg hover:bg-white/5 transition-all duration-200 border-l-4 border-<?= $color['bg'] ?>-500/30" data-type="<?= $activityType ?>">
                                            <div class="relative">
                                                <div class="w-12 h-12 bg-gradient-to-r from-<?= $color['bg'] ?>-500 to-<?= $color['bg'] ?>-600 rounded-lg flex items-center justify-center">
                                                    <i data-lucide="<?= $color['icon'] ?>" class="w-6 h-6 text-white"></i>
                                                </div>
                                                <?php if ($index === 0): ?>
                                                <div class="absolute -top-1 -right-1 w-4 h-4 bg-green-500 rounded-full flex items-center justify-center">
                                                    <i data-lucide="zap" class="w-2 h-2 text-white"></i>
                                                </div>
                                                <?php endif; ?>
                                            </div>
                                            <div class="flex-1">
                                                <div class="flex items-center space-x-2 mb-1">
                                                    <p class="font-medium" style="color: var(--text-primary);">
                                                        <?= $color['status'] ?>
                                                    </p>
                                                    <span class="text-xs px-2 py-0.5 bg-<?= $color['bg'] ?>-500/20 text-<?= $color['bg'] ?>-400 rounded-full">
                                                        <?= ucfirst($activityType) ?>
                                                    </span>
                                                </div>
                                                <p class="text-sm" style="color: var(--text-secondary);">
                                                    <?= htmlspecialchars($activity['title']) ?>
                                                </p>
                                                <div class="flex items-center space-x-2 mt-1">
                                                    <span class="text-xs" style="color: var(--text-tertiary);">
                                                        <?= date('M j, g:i A', strtotime($activity['date'])) ?>
                                                    </span>
                                                    <span class="text-xs text-green-400">• Live</span>
                                                </div>
                                            </div>
                                            <div class="flex flex-col items-end space-y-2">
                                                <button class="activity-details-btn p-1 hover:bg-white/10 rounded transition-colors" data-activity-id="<?= $activity['id'] ?>">
                                                    <i data-lucide="more-horizontal" class="w-4 h-4" style="color: var(--text-tertiary);"></i>
                                                </button>
                                                <div class="w-2 h-2 bg-<?= $color['bg'] ?>-500 rounded-full"></div>
                                            </div>
                                        </div>
                                        <?php endforeach; ?>

                                        <?php if (count($recentActivity) > $displayLimit): ?>
                                        <div class="text-center pt-4">
                                            <button id="load-more-activities" class="text-primary-400 hover:text-primary-300 text-sm font-medium transition-colors duration-200">
                                                Load <?= count($recentActivity) - $displayLimit ?> more activities
                                            </button>
                                        </div>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <div class="text-center py-8">
                                            <div class="w-16 h-16 bg-gradient-to-r from-gray-500 to-gray-600 rounded-lg flex items-center justify-center mx-auto mb-4">
                                                <i data-lucide="activity" class="w-8 h-8 text-white"></i>
                                            </div>
                                            <p class="text-sm" style="color: var(--text-tertiary);">No recent activity</p>
                                            <p class="text-xs mt-1" style="color: var(--text-tertiary);">Activity will appear here as it happens</p>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Activity Summary -->
                    <div class="lg:col-span-1">
                        <div class="card card-glass">
                            <div class="card-body">
                                <h3 class="heading-4 mb-6" style="color: var(--text-primary);">Activity Summary</h3>

                                <!-- Activity Stats -->
                                <div class="space-y-4 mb-6">
                                    <div class="flex items-center justify-between p-3 bg-white/5 rounded-lg">
                                        <div class="flex items-center space-x-3">
                                            <div class="w-8 h-8 bg-blue-500/20 rounded-lg flex items-center justify-center">
                                                <i data-lucide="file-text" class="w-4 h-4 text-blue-500"></i>
                                            </div>
                                            <span style="color: var(--text-primary);">Reports</span>
                                        </div>
                                        <span class="font-semibold" style="color: var(--text-primary);">
                                        <?= $dashboardStats['total_reports'] ?? 0 ?>
                                        </span>
                                    </div>
                                    <div class="flex items-center justify-between p-3 bg-white/5 rounded-lg">
                                        <div class="flex items-center space-x-3">
                                            <div class="w-8 h-8 bg-yellow-500/20 rounded-lg flex items-center justify-center">
                                                <i data-lucide="bell" class="w-4 h-4 text-yellow-500"></i>
                                            </div>
                                            <span style="color: var(--text-primary);">Alerts</span>
                                        </div>
                                        <span class="font-semibold" style="color: var(--text-primary);">
                                            <?= $dashboardStats['active_alerts'] ?? 0 ?>
                                        </span>
                                    </div>
                                    <div class="flex items-center justify-between p-3 bg-white/5 rounded-lg">
                                        <div class="flex items-center space-x-3">
                                            <div class="w-8 h-8 bg-green-500/20 rounded-lg flex items-center justify-center">
                                                <i data-lucide="user-plus" class="w-4 h-4 text-green-500"></i>
                                            </div>
                                            <span style="color: var(--text-primary);">New Users</span>
                                        </div>
                                        <span class="font-semibold" style="color: var(--text-primary);">
                                           <?= $dashboardStats['total_users'] ?? 0 ?>
                                        </span>
                                    </div>
                                </div>

                                <!-- Activity Timeline -->
                                <div class="space-y-3">
                                    <h4 class="text-sm font-medium" style="color: var(--text-secondary);">Today's Timeline</h4>
                                    <div class="space-y-2">
                                        <div class="flex items-center space-x-2 text-xs">
                                            <div class="w-2 h-2 bg-green-500 rounded-full"></div>
                                            <span style="color: var(--text-tertiary);">9:00 AM</span>
                                            <span style="color: var(--text-primary);">System startup</span>
                                        </div>
                                        <div class="flex items-center space-x-2 text-xs">
                                            <div class="w-2 h-2 bg-blue-500 rounded-full"></div>
                                            <span style="color: var(--text-tertiary);">10:30 AM</span>
                                            <span style="color: var(--text-primary);">First report received</span>
                                        </div>
                                        <div class="flex items-center space-x-2 text-xs">
                                            <div class="w-2 h-2 bg-yellow-500 rounded-full"></div>
                                            <span style="color: var(--text-tertiary);">2:15 PM</span>
                                            <span style="color: var(--text-primary);">Alert triggered</span>
                                        </div>
                                        <div class="flex items-center space-x-2 text-xs">
                                            <div class="w-2 h-2 bg-purple-500 rounded-full"></div>
                                            <span style="color: var(--text-tertiary);">4:45 PM</span>
                                            <span style="color: var(--text-primary);">Peak activity</span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </section>

            <!-- Emergency Actions -->
            <section class="animate-slide-up" style="animation-duration: 0.3s;">
                <div class="card card-glass border-l-4 border-error-500">
                    <div class="card-body">
                        <div class="flex items-center justify-between">
                            <div>
                                <h3 class="heading-4 mb-2" style="color: var(--text-primary);">Emergency Actions</h3>
                                <p class="body-medium" style="color: var(--text-secondary);">Quick access to emergency services and immediate help</p>
                            </div>
                            <div class="flex space-x-3">
                                <button class="btn btn-danger btn-ripple" onclick="window.safeSpaceDashboard.showToast('Emergency services contacted', 'info')">
                                    <i data-lucide="phone" class="w-4 h-4"></i>
                                    Emergency Call
  </button>
                                <button class="btn btn-warning btn-ripple" onclick="window.safeSpaceDashboard.showToast('Panic alert sent to nearby responders', 'warning')">
                                    <i data-lucide="alert-triangle" class="w-4 h-4"></i>
                                    Panic Alert
  </button>
</div>
                        </div>
                    </div>
                </div>
            </section>
        </div>
    </main>

    <!-- Floating Action Button -->
    <div class="fixed bottom-6 right-6 z-40">
        <button class="btn btn-icon btn-primary btn-ripple float" onclick="window.safeSpaceDashboard.showQuickActions()" data-tooltip="Quick Actions (Ctrl+K)">
            <i data-lucide="plus" class="w-6 h-6"></i>
        </button>
    </div>

    <!-- Scripts -->
    <script src="https://unpkg.com/lucide@latest/dist/umd/lucide.js"></script>
    <script src="https://www.gstatic.com/firebasejs/10.12.2/firebase-app-compat.js"></script>
    <script src="https://www.gstatic.com/firebasejs/10.12.2/firebase-auth-compat.js"></script>
    <script src="dashboard-enhanced.js"></script>

<script>
        // Initialize Lucide icons
        lucide.createIcons();

        // Performance optimization: Hide loading spinner after content loads
        window.addEventListener('load', () => {
            // Remove any loading spinners
            const spinners = document.querySelectorAll('.spinner');
            spinners.forEach(spinner => {
                spinner.style.opacity = '0';
                setTimeout(() => spinner.remove(), 200);
            });

            // Add loaded class to body for final animations
            document.body.classList.add('loaded');
        });

        // Do Not Disturb functionality
        const dndToggle = document.getElementById('dnd-toggle');
        const dndIcon = document.getElementById('dnd-icon');

        // Check current DND status
        const dndEnabled = localStorage.getItem('safespace-dnd-enabled');
        if (dndEnabled) {
            dndIcon.setAttribute('data-lucide', 'moon');
            dndToggle.setAttribute('data-tooltip', 'Do Not Disturb (Active)');
            dndToggle.classList.add('bg-primary-500/20', 'border-primary-500/50');
        } else {
            dndIcon.setAttribute('data-lucide', 'bell');
            dndToggle.setAttribute('data-tooltip', 'Do Not Disturb');
        }
        lucide.createIcons();

        dndToggle.addEventListener('click', () => {
            const currentlyEnabled = localStorage.getItem('safespace-dnd-enabled');

            if (currentlyEnabled) {
                // Disable DND
                localStorage.removeItem('safespace-dnd-enabled');
                dndIcon.setAttribute('data-lucide', 'bell');
                dndToggle.setAttribute('data-tooltip', 'Do Not Disturb');
                dndToggle.classList.remove('bg-primary-500/20', 'border-primary-500/50');
                showToast('Do Not Disturb disabled', 'success');
            } else {
                // Enable DND
                localStorage.setItem('safespace-dnd-enabled', 'true');
                dndIcon.setAttribute('data-lucide', 'moon');
                dndToggle.setAttribute('data-tooltip', 'Do Not Disturb (Active)');
                dndToggle.classList.add('bg-primary-500/20', 'border-primary-500/50');
                showToast('Do Not Disturb enabled', 'info');
            }

            lucide.createIcons();
        });

        // Firebase configuration
const firebaseConfig = {
  apiKey: "AIzaSyAjkHWsT9fxdbeQa-Udfu8KZxUjRA5EC4k",
  authDomain: "space-21c7e.firebaseapp.com",
  projectId: "space-21c7e",
  storageBucket: "space-21c7e.firebasestorage.app",
  messagingSenderId: "980510379589",
  appId: "1:980510379589:web:a65a6ffd4a97b62282dd2c",
  measurementId: "G-EPV8W821GK"
};

if (!firebase.apps.length) {
  firebase.initializeApp(firebaseConfig);
}

        // Store PHP display name for use in JavaScript
        const serverDisplayName = <?= json_encode($userDisplayName) ?>;
        const serverEmail = <?= json_encode($userEmail) ?>;

        // User authentication
firebase.auth().onAuthStateChanged(function(user) {
  if (user) {
    const email = user.email;

    // PRIORITIZE SERVER DISPLAY NAME (from database) over Firebase
    // Use serverDisplayName if available, otherwise fall back to Firebase or email prefix
    let displayName = serverDisplayName && serverDisplayName.trim() !== ""
      ? serverDisplayName
      : (user.displayName && user.displayName.trim() !== ""
          ? user.displayName
          : email.split('@')[0]);

    // Update UI with the correct name (prioritizing database value)
    document.getElementById('userEmail').textContent = displayName;
    document.getElementById('userName').textContent = displayName;

  } else {
    window.location.href = 'index.html';
  }
});

        // User menu toggle
        const userMenuBtn = document.getElementById('user-menu-btn');
        const userMenu = document.getElementById('user-menu');

        userMenuBtn.addEventListener('click', (e) => {
            e.stopPropagation();
            const isHidden = userMenu.classList.contains('opacity-0');
            if (isHidden) {
                userMenu.classList.remove('opacity-0', 'invisible', 'scale-95', 'translate-y-2');
                userMenu.classList.add('opacity-100', 'visible', 'scale-100', 'translate-y-0');
            } else {
                userMenu.classList.add('opacity-0', 'invisible', 'scale-95', 'translate-y-2');
                userMenu.classList.remove('opacity-100', 'visible', 'scale-100', 'translate-y-0');
            }
        });

        // Close menu when clicking outside
        document.addEventListener('click', (e) => {
            if (!userMenuBtn.contains(e.target) && !userMenu.contains(e.target)) {
                userMenu.classList.add('opacity-0', 'invisible', 'scale-95', 'translate-y-2');
                userMenu.classList.remove('opacity-100', 'visible', 'scale-100', 'translate-y-0');
            }
        });


        // Language selector functionality
        const langToggle = document.getElementById('lang-toggle');
        const langMenu = document.getElementById('lang-menu');
        const langForm = document.getElementById('lang-form');
        const langInput = document.getElementById('lang-input');
        const langOptions = document.querySelectorAll('.lang-option');
        const langFlag = document.getElementById('lang-flag');
        const langText = document.getElementById('lang-text');
        const langChevron = document.getElementById('lang-chevron');

        // Toggle language menu
        langToggle.addEventListener('click', (e) => {
            e.stopPropagation();
            const isHidden = langMenu.classList.contains('opacity-0');

            if (isHidden) {
                // Open menu with animation
                langMenu.classList.remove('opacity-0', 'invisible', 'scale-95', 'translate-y-2');
                langMenu.classList.add('opacity-100', 'visible', 'scale-100', 'translate-y-0');
                langChevron.style.transform = 'rotate(180deg)';

                // Animate options
                langOptions.forEach((option, index) => {
                    option.style.opacity = '0';
                    option.style.transform = 'translateX(-10px)';
                    setTimeout(() => {
                        option.style.transition = 'all 0.3s ease';
                        option.style.opacity = '1';
                        option.style.transform = 'translateX(0)';
                    }, index * 50);
                });
            } else {
                // Close menu with animation
                langMenu.classList.add('opacity-0', 'invisible', 'scale-95', 'translate-y-2');
                langMenu.classList.remove('opacity-100', 'visible', 'scale-100', 'translate-y-0');
                langChevron.style.transform = 'rotate(0deg)';
            }

            lucide.createIcons();
        });

        // Close menu when clicking outside
        document.addEventListener('click', (e) => {
            if (!langToggle.contains(e.target) && !langMenu.contains(e.target)) {
                langMenu.classList.add('opacity-0', 'invisible', 'scale-95', 'translate-y-2');
                langMenu.classList.remove('opacity-100', 'visible', 'scale-100', 'translate-y-0');
                langChevron.style.transform = 'rotate(0deg)';
            }
        });

        // Handle language selection
        langOptions.forEach(option => {
            option.addEventListener('click', function() {
                const selectedLang = this.getAttribute('data-lang');
                const currentLang = langInput.value;

                if (selectedLang === currentLang) {
                    // Same language selected, just close menu
                    langMenu.classList.add('opacity-0', 'invisible', 'scale-95', 'translate-y-2');
                    langMenu.classList.remove('opacity-100', 'visible', 'scale-100', 'translate-y-0');
                    langChevron.style.transform = 'rotate(0deg)';
                    return;
                }

                // Animate selection
                this.style.transform = 'scale(0.95)';
                setTimeout(() => {
                    this.style.transform = 'scale(1)';
                }, 150);

                // Update button text and flag with animation
                langFlag.style.transform = 'scale(0) rotate(180deg)';
                langText.style.opacity = '0';

                setTimeout(() => {
                    // Update values
                    langInput.value = selectedLang;

                    // Update button display
                    if (selectedLang === 'en') {
                        langFlag.textContent = '🇬🇧';
                        langText.textContent = 'English';
                    } else {
                        langFlag.textContent = '🇧🇩';
                        langText.textContent = 'বাংলা';
                    }

                    // Animate back in
                    langFlag.style.transform = 'scale(1) rotate(0deg)';
                    langText.style.opacity = '1';

                    // Update active state in menu
                    langOptions.forEach(opt => {
                        opt.classList.remove('bg-primary-500/10', 'border-l-2', 'border-primary-500');
                        const checkIcon = opt.querySelector('i[data-lucide="check"]');
                        if (checkIcon) {
                            checkIcon.remove();
                        }
                    });

                    // Add active state to selected option
                    this.classList.add('bg-primary-500/10', 'border-l-2', 'border-primary-500');
                    const checkIcon = document.createElement('i');
                    checkIcon.setAttribute('data-lucide', 'check');
                    checkIcon.className = 'w-4 h-4 text-primary-400';
                    this.appendChild(checkIcon);
                    lucide.createIcons();

                    // Close menu
                    langMenu.classList.add('opacity-0', 'invisible', 'scale-95', 'translate-y-2');
                    langMenu.classList.remove('opacity-100', 'visible', 'scale-100', 'translate-y-0');
                    langChevron.style.transform = 'rotate(0deg)';

                    // Submit form
                    setTimeout(() => {
                        langForm.submit();
                    }, 200);
                }, 200);
            });
        });

        // Initialize chevron transition
        langChevron.style.transition = 'transform 0.3s ease';

        // Theme toggle functionality
        const themeToggle = document.getElementById('theme-toggle');
        const themeIcon = document.getElementById('theme-icon');

        // Check for saved theme preference or default to dark
        const currentTheme = localStorage.getItem('safespace-theme') || 'dark';
        document.documentElement.setAttribute('data-theme', currentTheme);

        // Update icon based on theme
        if (currentTheme === 'light') {
            themeIcon.setAttribute('data-lucide', 'sun');
        } else {
            themeIcon.setAttribute('data-lucide', 'moon');
        }
        lucide.createIcons();

        themeToggle.addEventListener('click', () => {
            const currentTheme = document.documentElement.getAttribute('data-theme');
            const newTheme = currentTheme === 'dark' ? 'light' : 'dark';

            document.documentElement.setAttribute('data-theme', newTheme);
            localStorage.setItem('safespace-theme', newTheme);

            // Update icon
            themeIcon.setAttribute('data-lucide', newTheme === 'light' ? 'moon' : 'sun');
            lucide.createIcons();

            showToast(`Switched to ${newTheme} theme`, 'success');
        });

        // Navigation Panel
        const navItems = document.querySelectorAll('.nav-item');

        // Set initial active state (Dashboard by default)
        let currentActive = 'dashboard';

        // Initialize after DOM is ready
        function initNavigation() {
            updateActiveNav('dashboard');
            lucide.createIcons();
        }

        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', initNavigation);
        } else {
            initNavigation();
        }

        function updateActiveNav(navName) {
            navItems.forEach(item => {
                const navData = item.getAttribute('data-nav');

                if (navData === navName) {
                    // Active state
                    item.classList.add('active');
                    currentActive = navName;
                } else {
                    // Inactive state
                    item.classList.remove('active');
                }
            });
        }

       // Handle navigation clicks (Around line 727)
        navItems.forEach(item => {
            item.addEventListener('click', function(e) {
                const navName = this.getAttribute('data-nav');

                // Only prevent default and scroll for Dashboard and Alerts
                if (navName === 'dashboard' || navName === 'alerts') {
                    e.preventDefault();
                    updateActiveNav(navName);

                    // Smooth scroll to section
                    const target = document.querySelector(this.getAttribute('href'));
                    if (target) {
                        target.scrollIntoView({
                            behavior: 'smooth',
                            block: 'start'
                        });
                    }
                } else {
                    // For Reports and Resources, allow default behavior (page change)
                    updateActiveNav(navName); // Keep the active style update
                }

                lucide.createIcons();
            });
        });

        // Update active nav on scroll (optional - detects which section is in view)
        const navSections = document.querySelectorAll('[id^="dashboard"], [id^="reports"], [id^="alerts"], [id^="resources"]');
        const navObserverOptions = {
            rootMargin: '-20% 0px -70% 0px',
            threshold: 0
        };

        const sectionObserver = new IntersectionObserver((entries) => {
            entries.forEach(entry => {
                if (entry.isIntersecting) {
                    const sectionId = entry.target.id;
                    if (sectionId) {
                        let navName = '';
                        if (sectionId.includes('dashboard')) navName = 'dashboard';
                        else if (sectionId.includes('reports')) navName = 'reports';
                        else if (sectionId.includes('alerts')) navName = 'alerts';
                        else if (sectionId.includes('resources')) navName = 'resources';

                        if (navName && navName !== currentActive) {
                            updateActiveNav(navName);
                        }
                    }
                }
            });
        }, navObserverOptions);

        navSections.forEach(section => {
            sectionObserver.observe(section);
        });

        // Smooth scrolling for other navigation links
        document.querySelectorAll('a[href^="#"]:not(.nav-item)').forEach(anchor => {
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

        // Add loading states
        function showLoading(element) {
            element.classList.add('skeleton');
            element.style.pointerEvents = 'none';
        }

        function hideLoading(element) {
            element.classList.remove('skeleton');
            element.style.pointerEvents = 'auto';
        }

        // Intersection Observer for animations - Optimized
        const observerOptions = {
            threshold: 0.05,
            rootMargin: '0px 0px -30px 0px'
        };

        const observer = new IntersectionObserver((entries) => {
            entries.forEach(entry => {
                if (entry.isIntersecting) {
                    entry.target.style.opacity = '1';
                    entry.target.style.transform = 'translateY(0)';
                    observer.unobserve(entry.target); // Stop observing once animated
                }
            });
        }, observerOptions);

        // Observe all cards for animation - Optimized
        document.querySelectorAll('.card-hover').forEach((card, index) => {
            card.style.opacity = '0';
            card.style.transform = 'translateY(15px)';
            card.style.transition = `opacity 0.3s ease ${index * 0.05}s, transform 0.3s ease ${index * 0.05}s`;
            observer.observe(card);
        });

        // Incident Trends Chart
        let incidentChart = null;
        let currentPeriod = '30D';
        let realtimeUpdateInterval = null;
// Initialize Chart
        function initIncidentChart() {
            const ctx = document.getElementById('incidentTrendsChart');
            if (!ctx) return;

            const gradient = ctx.getContext('2d').createLinearGradient(0, 0, 0, 300);
            gradient.addColorStop(0, 'rgba(59, 130, 246, 0.8)');
            gradient.addColorStop(1, 'rgba(59, 130, 246, 0.1)');

            incidentChart = new Chart(ctx, {
                type: 'line',
                data: {
                    labels: [],
                    datasets: [{
                        label: 'Incidents',
                        data: [],
                        borderColor: '#3b82f6',
                        backgroundColor: gradient,
                        borderWidth: 3,
                        fill: true,
                        tension: 0.4,
                        pointBackgroundColor: '#3b82f6',
                        pointBorderColor: '#ffffff',
                        pointBorderWidth: 2,
                        pointRadius: 6,
                        pointHoverRadius: 8,
                        pointHoverBackgroundColor: '#3b82f6',
                        pointHoverBorderColor: '#ffffff',
                        pointHoverBorderWidth: 3
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            display: false
                        },
                        tooltip: {
                            backgroundColor: 'rgba(0, 0, 0, 0.8)',
                            titleColor: '#ffffff',
                            bodyColor: '#ffffff',
                            borderColor: 'rgba(255, 255, 255, 0.1)',
                            borderWidth: 1,
                            cornerRadius: 8,
                            displayColors: false,
                            callbacks: {
                                title: function(context) {
                                    return context[0].label;
                                },
                                label: function(context) {
                                    return `${context.parsed.y} incidents`;
                                }
                            }
                        }
                    },
                    scales: {
                        x: {
                            grid: {
                                color: 'rgba(255, 255, 255, 0.1)',
                                drawBorder: false
                            },
                            ticks: {
                                color: 'rgba(255, 255, 255, 0.7)',
                                font: {
                                    size: 12
                                }
                            }
                        },
                        y: {
                            grid: {
                                color: 'rgba(255, 255, 255, 0.1)',
                                drawBorder: false
                            },
                            ticks: {
                                color: 'rgba(255, 255, 255, 0.7)',
                                font: {
                                    size: 12
                                },
                                // The rounding callback that caused the issue has been removed.
                            },
                            beginAtZero: true
                        }
                    },
                    interaction: {
                        intersect: false,
                        mode: 'index'
                    },
                    elements: {
                        point: {
                            hoverRadius: 8
                        }
                    }
                }
            });
        }

        // Generate mock data for different periods
        function generateChartData(period) {
            const now = new Date();
            const labels = [];
            const data = [];

            let days;
            switch(period) {
                case '7D':
                    days = 7;
                    break;
                case '30D':
                    days = 30;
                    break;
                case '90D':
                    days = 90;
                    break;
                default:
                    days = 30;
            }

            for (let i = days - 1; i >= 0; i--) {
                const date = new Date(now);
                date.setDate(date.getDate() - i);

                if (period === '7D') {
                    labels.push(date.toLocaleDateString('en-US', { weekday: 'short' }));
                } else if (period === '30D') {
                    labels.push(date.getDate().toString());
                } else {
                    labels.push(date.toLocaleDateString('en-US', { month: 'short', day: 'numeric' }));
                }

                                 // Generate realistic data with some randomness and patterns
                 const dayOfWeek = date.getDay(); // 0=Sunday, 6=Saturday
                 const isWeekend = (dayOfWeek === 0 || dayOfWeek === 6);
                 const baseValue = isWeekend ? 2 : 1; // More incidents on weekends
                 const randomValue = Math.random() * 3 + baseValue;
                 data.push(Math.round(randomValue));
            }

            return { labels, data };
        }

        // Update chart data
        function updateChartData(period) {
            if (!incidentChart) return;

            showChartLoading(true);

            // Fetch real data via WebSocket
            if (window.safeSpaceDashboard && typeof window.safeSpaceDashboard.requestChartDataViaWS === 'function') {
                window.safeSpaceDashboard.requestChartDataViaWS(period)
                  .then(wsData => {
                        incidentChart.data.labels = wsData.labels;
                        incidentChart.data.datasets[0].data = wsData.values;
                        incidentChart.update('active');

                        const stats = wsData.statistics || {};

                        // Update Total Reports (now the period total)
                        const totalReportsEl = document.getElementById('totalReports');
                        if (totalReportsEl && typeof stats.totalReports !== 'undefined') totalReportsEl.textContent = stats.totalReports;

                        // Update Growth Rate
                        const trendEl = document.getElementById('trendPercentage');
                        if (trendEl && typeof stats.trendPercentage !== 'undefined') {
                            trendEl.textContent = `${stats.trendPercentage > 0 ? '+' : ''}${stats.trendPercentage}%`;
                            trendEl.className = `text-2xl font-bold mb-1 ${stats.trendPercentage >= 0 ? 'text-green-400' : 'text-red-400'}`;
                        }

                        // Update Active Alerts
                        const activeAlertsEl = document.getElementById('activeAlerts');
                        if (activeAlertsEl && typeof stats.activeAlerts !== 'undefined') activeAlertsEl.textContent = stats.activeAlerts;

                        // --- NEW: Update "New Today" and "Avg Response" ---
                        const alertsTrendEl = document.getElementById('alertsTrend');
                        if (alertsTrendEl && typeof stats.alertsNewToday !== 'undefined') {
                            alertsTrendEl.textContent = `${stats.alertsNewToday} new today`;
                        }

                        const avgResponseEl = document.getElementById('avgResponse');
                        if (avgResponseEl && typeof stats.avgResponse !== 'undefined') {
                            avgResponseEl.textContent = stats.avgResponse;
                        }

                        showChartLoading(false);
                  })
                  .catch(() => {
                        const { labels, data: mockData } = generateChartData(period);
                        incidentChart.data.labels = labels;
                        incidentChart.data.datasets[0].data = mockData;
                        incidentChart.update('active');

                        const totalReports = mockData.reduce((sum, val) => sum + val, 0);
                        const previousPeriod = mockData.slice(0, Math.floor(mockData.length / 2));
                        const currentPeriod = mockData.slice(Math.floor(mockData.length / 2));
                        const previousAvg = previousPeriod.reduce((sum, val) => sum + val, 0) / previousPeriod.length;
                        const currentAvg = currentPeriod.reduce((sum, val) => sum + val, 0) / currentPeriod.length;
                        const percentageChange = previousAvg > 0 ? ((currentAvg - previousAvg) / previousAvg * 100) : 0;


                        showChartLoading(false);
                  });
          }
        }

        // Show/hide chart loading - Optimized
        function showChartLoading(show) {
            const loading = document.getElementById('chartLoading');
            if (loading) {
                if (show) {
                    loading.style.display = 'flex';
                    loading.style.opacity = '1';
                } else {
                    loading.style.opacity = '0';
                    setTimeout(() => {
                        loading.style.display = 'none';
                    }, 200);
                }
            }
        }

        // Timeframe button click handlers
        document.querySelectorAll('.timeframe-btn').forEach(btn => {
            btn.addEventListener('click', function() {
                const period = this.dataset.period;

                // Update active button
                document.querySelectorAll('.timeframe-btn').forEach(b => b.classList.remove('active', 'bg-blue-500', 'text-white'));
                this.classList.add('active', 'bg-blue-500', 'text-white');

                // Update chart
                currentPeriod = period;
                updateChartData(period);
            });
        });

        // Real-time updates - Optimized
        function startRealtimeUpdates() {
            if (realtimeUpdateInterval) {
                clearInterval(realtimeUpdateInterval);
            }

            realtimeUpdateInterval = setInterval(() => {
                if (incidentChart && document.visibilityState === 'visible' && window.safeSpaceDashboard && typeof window.safeSpaceDashboard.requestChartDataViaWS === 'function') {
                    window.safeSpaceDashboard.requestChartDataViaWS(currentPeriod)
                        .then(wsData => {
                            // Batch DOM updates
                            requestAnimationFrame(() => {
                                incidentChart.data.labels = wsData.labels;
                                incidentChart.data.datasets[0].data = wsData.values;
                                incidentChart.update('none');

                                // Update statistics
                                const stats = wsData.statistics || {};
                                const totalReportsEl = document.getElementById('totalReports');
                                const activeAlertsEl = document.getElementById('activeAlerts');
                                if (totalReportsEl && typeof stats.totalReports !== 'undefined') totalReportsEl.textContent = stats.totalReports;
                                if (activeAlertsEl && typeof stats.activeAlerts !== 'undefined') activeAlertsEl.textContent = stats.activeAlerts;

                                // Update trend percentage with animation
                                const trendElement = document.getElementById('trendPercentage');
                                if (trendElement && typeof stats.trendPercentage !== 'undefined') {
                                    const currentValue = parseFloat(trendElement.textContent.replace(/[+%]/g, ''));
                                    const newValue = stats.trendPercentage;
                                    if (currentValue !== newValue) {
                                        trendElement.textContent = `${newValue > 0 ? '+' : ''}${newValue}%`;
                                        trendElement.className = `text-2xl font-bold mb-1 ${newValue >= 0 ? 'text-green-400' : 'text-red-400'}`;
                                    }
                                }
                            });
                        })
                        .catch(() => {});
                }
            }, 45000); // Update every 45 seconds
        }

        // Load detailed metrics via WebSocket
      // Load detailed metrics via WebSocket
        function loadDetailedMetrics() {
            if (window.safeSpaceDashboard && typeof window.safeSpaceDashboard.requestDetailedMetricsViaWS === 'function') {
                window.safeSpaceDashboard.requestDetailedMetricsViaWS()
                    .then(metrics => {
                        // Store metrics globally for the modal
                        window.detailedMetricsData = metrics;

                        // --- NEW: Update the main dashboard's quick trends table ---
                        updateQuickTrends(metrics);

                        // Update the trends table in the modal if it's open
                        const trendsTableBody = document.querySelector('.trends-table-body');
                        if (trendsTableBody) {
                            trendsTableBody.innerHTML = generateTrendsTableRows();
                            if (typeof lucide !== 'undefined') {
                                lucide.createIcons();
                            }
                        }
                    })
                    .catch(() => {
                        // On error, set all to 0
                        const zeroMetrics = {
                            dailyReports: { current: 0, previous: 0 },
                            criticalIncidents: { current: 0, previous: 0 },
                            responseTime: { current: '0h', previous: '0h' },
                            resolutionRate: 0
                        };
                        window.detailedMetricsData = zeroMetrics;
                        updateQuickTrends(zeroMetrics); // Also update with zeros on error
                    });
            }
        }

        // --- NEW: Add this new helper function ---
        function updateQuickTrends(metrics) {
            // Daily Reports
            const qrCurrent = document.getElementById('quick-reports-current');
            const qrPrevious = document.getElementById('quick-reports-previous');
            if (qrCurrent) qrCurrent.textContent = metrics.dailyReports.current;
            if (qrPrevious) qrPrevious.textContent = metrics.dailyReports.previous;

            // Critical Incidents
            const qcCurrent = document.getElementById('quick-critical-current');
            const qcPrevious = document.getElementById('quick-critical-previous');
            if (qcCurrent) qcCurrent.textContent = metrics.criticalIncidents.current;
            if (qcPrevious) qcPrevious.textContent = metrics.criticalIncidents.previous;

            // Resolution Rate
            const qResolution = document.getElementById('quick-resolution-current');
            if (qResolution) qResolution.innerHTML = `<span class"font-semibold" style="color: var(--text-primary);">${metrics.resolutionRate}%</span>`;

            // Calculate and display change for Daily Reports
            const reportsChangeDiv = document.getElementById('quick-reports-change');
            if (reportsChangeDiv) {
                const rCurrent = metrics.dailyReports.current;
                const rPrev = metrics.dailyReports.previous;
                const rChange = rPrev > 0 ? Math.round(((rCurrent - rPrev) / rPrev) * 100) : (rCurrent > 0 ? 100 : 0);

                let rIcon = rChange === 0 ? 'minus' : (rChange > 0 ? 'trending-up' : 'trending-down');
                let rColor = rChange === 0 ? 'gray' : (rChange > 0 ? 'green' : 'red');
                reportsChangeDiv.innerHTML = `<i data-lucide="${rIcon}" class="w-3 h-3 text-${rColor}-500"></i><span class="text-xs font-medium text-${rColor}-500">${rChange > 0 ? '+' : ''}${rChange}%</span>`;
            }

            // Calculate and display change for Critical Incidents
            const criticalChangeDiv = document.getElementById('quick-critical-change');
            if (criticalChangeDiv) {
                const cCurrent = metrics.criticalIncidents.current;
                const cPrev = metrics.criticalIncidents.previous;
                const cChange = cPrev > 0 ? Math.round(((cCurrent - cPrev) / cPrev) * 100) : (cCurrent > 0 ? 100 : 0);

                // For critical incidents, an increase is bad (red)
                let cIcon = cChange === 0 ? 'minus' : (cChange > 0 ? 'trending-up' : 'trending-down');
                let cColor = cChange === 0 ? 'gray' : (cChange > 0 ? 'red' : 'green'); // Increase is red
                criticalChangeDiv.innerHTML = `<i data-lucide="${cIcon}" class="w-3 h-3 text-${cColor}-500"></i><span class="text-xs font-medium text-${cColor}-500">${cChange > 0 ? '+' : ''}${cChange}%</span>`;
            }

            if (typeof lucide !== 'undefined') {
                lucide.createIcons();
            }
        }

      // Initialize chart when WebSocket is ready
        window.addEventListener('wsReady', function() {
            // Defer chart initialization for better performance
            requestAnimationFrame(() => {
                initIncidentChart();
                updateChartData('30D');
                startRealtimeUpdates();

                // Load detailed metrics
                setTimeout(() => {
                    loadDetailedMetrics();
                    // Refresh every 60 seconds
                    setInterval(loadDetailedMetrics, 60000);
                }, 2000);
            });
        });

        // Fallback for DOMContentLoaded (in case wsReady fires first)
        document.addEventListener('DOMContentLoaded', function() {
            // This just ensures that if wsReady has already fired,
            // we're still good to go. But wsReady is the main trigger.
        });

        // Toast notification system
        function showToast(message, type = 'info') {
            const toast = document.createElement('div');
            toast.className = `fixed top-20 right-4 z-50 px-6 py-3 rounded-lg text-white font-medium transition-all duration-300 transform translate-x-full`;

            switch(type) {
                case 'success':
                    toast.classList.add('bg-accent-500');
                    break;
                case 'error':
                    toast.classList.add('bg-error-500');
                    break;
                case 'warning':
                    toast.classList.add('bg-warning-500');
                    break;
                default:
                    toast.classList.add('bg-primary-500');
            }

            toast.textContent = message;
            document.body.appendChild(toast);

            // Animate in
            setTimeout(() => {
                toast.classList.remove('translate-x-full');
            }, 100);

            // Remove after 3 seconds
            setTimeout(() => {
                toast.classList.add('translate-x-full');
                setTimeout(() => {
                    document.body.removeChild(toast);
                }, 300);
            }, 3000);
        }

        // Make toast function globally available
        window.safeSpaceDashboard = {
            showToast: showToast
        };

        // Notification toggle functionality
        const toggleNotificationsBtn = document.getElementById('toggle-notifications');
        const notificationStatus = document.getElementById('notification-status');

        // Check current notification status
        const notificationsDisabled = localStorage.getItem('safespace-notifications-disabled');
        if (notificationsDisabled) {
            notificationStatus.textContent = 'Enable Notifications';
        } else {
            notificationStatus.textContent = 'Disable Notifications';
        }

        toggleNotificationsBtn.addEventListener('click', () => {
            const currentlyDisabled = localStorage.getItem('safespace-notifications-disabled');

            if (currentlyDisabled) {
                // Enable notifications
                localStorage.removeItem('safespace-notifications-disabled');
                notificationStatus.textContent = 'Disable Notifications';
                showToast('Activity notifications enabled', 'success');
            } else {
                // Disable notifications
                localStorage.setItem('safespace-notifications-disabled', 'true');
                notificationStatus.textContent = 'Enable Notifications';
                showToast('Activity notifications disabled', 'info');
            }

                    // Close user menu
        userMenu.classList.add('opacity-0', 'invisible', 'scale-95');
    });

    // Notification settings modal
    const notificationSettingsBtn = document.getElementById('notification-settings');
    notificationSettingsBtn.addEventListener('click', () => {
        showNotificationSettingsModal();
        userMenu.classList.add('opacity-0', 'invisible', 'scale-95');
    });

    function showNotificationSettingsModal() {
        const modal = document.createElement('div');
        modal.className = 'fixed inset-0 bg-black/50 backdrop-blur-sm z-50 flex items-center justify-center p-4';
        modal.innerHTML = `
            <div class="bg-white/10 backdrop-blur-md rounded-xl border border-white/20 max-w-md w-full">
                <div class="flex items-center justify-between p-6 border-b border-white/10">
                    <h3 class="text-lg font-semibold" style="color: var(--text-primary);">Notification Settings</h3>
                    <button class="close-modal-btn p-2 hover:bg-white/10 rounded-lg transition-colors">
                        <i data-lucide="x" class="w-5 h-5" style="color: var(--text-primary);"></i>
                    </button>
                </div>
                <div class="p-6 space-y-4">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="font-medium" style="color: var(--text-primary);">Activity Notifications</p>
                            <p class="text-sm" style="color: var(--text-secondary);">Show popup notifications for new activities</p>
                        </div>
                        <label class="relative inline-flex items-center cursor-pointer">
                            <input type="checkbox" id="activity-notifications" class="sr-only peer" ${!localStorage.getItem('safespace-notifications-disabled') ? 'checked' : ''}>
                            <div class="w-11 h-6 bg-gray-200 peer-focus:outline-none rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-blue-600"></div>
                        </label>
                    </div>
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="font-medium" style="color: var(--text-primary);">Important Alerts</p>
                            <p class="text-sm" style="color: var(--text-secondary);">Always show critical alerts and reports</p>
                        </div>
                        <label class="relative inline-flex items-center cursor-pointer">
                            <input type="checkbox" id="important-alerts" class="sr-only peer" checked disabled>
                            <div class="w-11 h-6 bg-blue-600 rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:rounded-full after:h-5 after:w-5 after:transition-all"></div>
                        </label>
                    </div>
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="font-medium" style="color: var(--text-primary);">Activity Counter</p>
                            <p class="text-sm" style="color: var(--text-secondary);">Show badge for new activities</p>
                        </div>
                        <label class="relative inline-flex items-center cursor-pointer">
                            <input type="checkbox" id="activity-counter" class="sr-only peer" ${!localStorage.getItem('safespace-counter-disabled') ? 'checked' : ''}>
                            <div class="w-11 h-6 bg-gray-200 peer-focus:outline-none rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-blue-600"></div>
                        </label>
                    </div>
                </div>
                <div class="flex justify-end space-x-3 p-6 border-t border-white/10">
                    <button class="px-4 py-2 text-sm rounded-lg transition-colors" style="color: var(--text-secondary); background: var(--bg-secondary);" onclick="closeNotificationModal()">Cancel</button>
                    <button class="px-4 py-2 text-sm rounded-lg transition-colors bg-blue-600 text-white" onclick="saveNotificationSettings()">Save Settings</button>
                </div>
            </div>
        `;

        document.body.appendChild(modal);
        lucide.createIcons();

        // Close modal functionality
        const closeBtn = modal.querySelector('.close-modal-btn');
        const closeModal = () => {
            modal.remove();
        };

        closeBtn.addEventListener('click', closeModal);
        modal.addEventListener('click', (e) => {
            if (e.target === modal) closeModal();
        });

        // Make functions globally available
        window.closeNotificationModal = closeModal;
        window.saveNotificationSettings = () => {
            const activityNotifications = document.getElementById('activity-notifications').checked;
            const activityCounter = document.getElementById('activity-counter').checked;

            if (activityNotifications) {
                localStorage.removeItem('safespace-notifications-disabled');
            } else {
                localStorage.setItem('safespace-notifications-disabled', 'true');
            }

            if (activityCounter) {
                localStorage.removeItem('safespace-counter-disabled');
            } else {
                localStorage.setItem('safespace-counter-disabled', 'true');
            }

            // Update notification status in user menu
            const notificationStatus = document.getElementById('notification-status');
            if (notificationStatus) {
                notificationStatus.textContent = activityNotifications ? 'Disable Notifications' : 'Enable Notifications';
            }

            showToast('Notification settings saved', 'success');
            closeModal();
        };
    }

    // Report Categories functionality
    const viewAllCategoriesBtn = document.getElementById('view-all-categories');
    const showMoreCategoriesBtn = document.getElementById('show-more-categories');

    if (viewAllCategoriesBtn) {
        viewAllCategoriesBtn.addEventListener('click', () => {
            showAllCategoriesModal();
        });
    }

    if (showMoreCategoriesBtn) {
        showMoreCategoriesBtn.addEventListener('click', () => {
            showAllCategoriesModal();
        });
    }

    function showAllCategoriesModal() {
        const modal = document.createElement('div');
        modal.className = 'fixed inset-0 bg-black/50 backdrop-blur-sm z-50 flex items-center justify-center p-4';
        modal.innerHTML = `
            <div class="bg-white/10 backdrop-blur-md rounded-xl border border-white/20 max-w-4xl w-full max-h-[80vh] overflow-hidden">
                <div class="flex items-center justify-between p-6 border-b border-white/10">
                    <div>
                        <h3 class="text-xl font-semibold" style="color: var(--text-primary);">All Report Categories</h3>
                        <p class="text-sm mt-1" style="color: var(--text-secondary);">Complete breakdown of incident reports by category</p>
                    </div>
                    <button class="close-modal-btn p-2 hover:bg-white/10 rounded-lg transition-colors">
                        <i data-lucide="x" class="w-5 h-5" style="color: var(--text-primary);"></i>
                    </button>
                </div>
                <div class="p-6 overflow-y-auto max-h-[60vh]">
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead>
                                <tr class="border-b border-white/10">
                                    <th class="text-left py-3 px-4 font-medium" style="color: var(--text-secondary);">Category</th>
                                    <th class="text-center py-3 px-4 font-medium" style="color: var(--text-secondary);">Reports</th>
                                    <th class="text-center py-3 px-4 font-medium" style="color: var(--text-secondary);">Percentage</th>
                                    <th class="text-center py-3 px-4 font-medium" style="color: var(--text-secondary);">Trend</th>
                                    <th class="text-center py-3 px-4 font-medium" style="color: var(--text-secondary);">Status</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-white/5">
                                ${generateAllCategoriesRows()}
                            </tbody>
                        </table>
                    </div>

                    <!-- Summary Statistics -->
                    <div class="mt-8 grid grid-cols-1 md:grid-cols-4 gap-4 pt-6 border-t border-white/10">
                        <div class="text-center p-4 bg-white/5 rounded-lg">
                            <div class="text-2xl font-bold text-blue-400">${<?= count($reportCategories) ?>}</div>
                            <div class="text-xs" style="color: var(--text-tertiary);">Total Categories</div>
                        </div>
                        <div class="text-center p-4 bg-white/5 rounded-lg">
                            <div class="text-2xl font-bold text-green-400">${<?= number_format($totalCategoryReports) ?>}</div>
                            <div class="text-xs" style="color: var(--text-tertiary);">Total Reports</div>
                        </div>
                        <div class="text-center p-4 bg-white/5 rounded-lg">
                            <div class="text-2xl font-bold text-yellow-400">${getTopCategory()}</div>
                            <div class="text-xs" style="color: var(--text-tertiary);">Most Reported</div>
                        </div>
                        <div class="text-center p-4 bg-white/5 rounded-lg">
                            <div class="text-2xl font-bold text-purple-400">${getAverageReports()}</div>
                            <div class="text-xs" style="color: var(--text-tertiary);">Avg per Category</div>
                        </div>
                    </div>
                </div>
            </div>
        `;

        document.body.appendChild(modal);
        lucide.createIcons();

        // Close modal functionality
        const closeBtn = modal.querySelector('.close-modal-btn');
        const closeModal = () => {
            modal.remove();
        };

        closeBtn.addEventListener('click', closeModal);
        modal.addEventListener('click', (e) => {
            if (e.target === modal) closeModal();
        });
    }

    function generateAllCategoriesRows() {
        const categories = <?= json_encode($reportCategories) ?>;
        const totalReports = <?= $totalCategoryReports ?>;
        const categoryColors = {
            'harassment': {color: 'error', icon: 'user-x'},
            'assault': {color: 'primary', icon: 'alert-triangle'},
            'theft': {color: 'warning', icon: 'package'},
            'vandalism': {color: 'orange', icon: 'hammer'},
            'stalking': {color: 'purple', icon: 'eye'},
            'cyberbullying': {color: 'blue', icon: 'monitor'},
            'discrimination': {color: 'indigo', icon: 'users'},
            'other': {color: 'secondary', icon: 'file-text'}
        };

        return categories.map(category => {
            const percentage = totalReports > 0 ? Math.round((category.count / totalReports) * 100) : 0;
            const color = categoryColors[category.category] || {color: 'secondary', icon: 'file-text'};
            const trend = Math.floor(Math.random() * 41) - 20; // -20 to +20
            const status = getCategoryStatus(category.count, percentage);

            return `
                <tr class="hover:bg-white/5 transition-colors duration-200">
                    <td class="py-4 px-4">
                        <div class="flex items-center space-x-3">
                            <div class="w-10 h-10 bg-${color.color}-500/20 rounded-lg flex items-center justify-center">
                                <i data-lucide="${color.icon}" class="w-5 h-5 text-${color.color}-500"></i>
                            </div>
                            <div>
                                <div class="font-medium" style="color: var(--text-primary);">${category.category.charAt(0).toUpperCase() + category.category.slice(1)}</div>
                                <div class="text-xs" style="color: var(--text-tertiary);">${category.count} reports</div>
                            </div>
                        </div>
                    </td>
                    <td class="py-4 px-4 text-center">
                        <span class="font-semibold" style="color: var(--text-primary);">${category.count.toLocaleString()}</span>
                    </td>
                    <td class="py-4 px-4 text-center">
                        <div class="flex items-center justify-center space-x-2">
                            <div class="w-16 h-2 rounded-full" style="background: var(--bg-tertiary);">
                                <div class="bg-${color.color}-500 h-2 rounded-full" style="width: ${percentage}%"></div>
                            </div>
                            <span class="text-sm font-medium" style="color: var(--text-tertiary);">${percentage}%</span>
                        </div>
                    </td>
                    <td class="py-4 px-4 text-center">
                        <div class="flex items-center justify-center space-x-1">
                            <i data-lucide="${trend > 0 ? 'trending-up' : (trend < 0 ? 'trending-down' : 'minus')}" class="w-4 h-4 ${trend > 0 ? 'text-green-500' : (trend < 0 ? 'text-red-500' : 'text-gray-500')}"></i>
                            <span class="text-sm font-medium ${trend > 0 ? 'text-green-500' : (trend < 0 ? 'text-red-500' : 'text-gray-500')}">
                                ${trend > 0 ? '+' + trend : trend}%
                            </span>
                        </div>
                    </td>
                    <td class="py-4 px-4 text-center">
                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium ${status.class}">
                            ${status.text}
                        </span>
                    </td>
                </tr>
            `;
        }).join('');
    }

    function getCategoryStatus(count, percentage) {
        if (percentage > 30) return {text: 'High Priority', class: 'bg-red-500/20 text-red-400'};
        if (percentage > 15) return {text: 'Medium Priority', class: 'bg-yellow-500/20 text-yellow-400'};
        if (percentage > 5) return {text: 'Low Priority', class: 'bg-blue-500/20 text-blue-400'};
        return {text: 'Minimal', class: 'bg-gray-500/20 text-gray-400'};
    }

    function getTopCategory() {
        const categories = <?= json_encode($reportCategories) ?>;
        if (categories.length === 0) return 'N/A';
        const topCategory = categories.reduce((prev, current) =>
            (prev.count > current.count) ? prev : current
        );
        return topCategory.category.charAt(0).toUpperCase() + topCategory.category.slice(1);
    }

    function getAverageReports() {
        const categories = <?= json_encode($reportCategories) ?>;
        if (categories.length === 0) return '0';
        const total = categories.reduce((sum, cat) => sum + cat.count, 0);
        return Math.round(total / categories.length).toLocaleString();
    }

    // Incident Trends Details functionality
    const viewTrendsDetailsBtn = document.getElementById('view-trends-details');

    if (viewTrendsDetailsBtn) {
        viewTrendsDetailsBtn.addEventListener('click', () => {
            showTrendsDetailsModal();
        });
    }

    function showTrendsDetailsModal() {
        const modal = document.createElement('div');
        modal.className = 'fixed inset-0 bg-black/50 backdrop-blur-sm z-50 flex items-center justify-center p-4';
        modal.innerHTML = `
            <div class="bg-white/10 backdrop-blur-md rounded-xl border border-white/20 max-w-6xl w-full max-h-[90vh] overflow-hidden">
                <div class="flex items-center justify-between p-6 border-b border-white/10">
                    <div>
                        <h3 class="text-xl font-semibold" style="color: var(--text-primary);">Incident Trends Analysis</h3>
                        <p class="text-sm mt-1" style="color: var(--text-secondary);">Comprehensive incident analytics and forecasting</p>
                    </div>
                    <button class="close-modal-btn p-2 hover:bg-white/10 rounded-lg transition-colors">
                        <i data-lucide="x" class="w-5 h-5" style="color: var(--text-primary);"></i>
                    </button>
                </div>
                <div class="p-6 overflow-y-auto max-h-[70vh]">
                    <!-- Key Metrics Grid -->
                    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4 mb-8">
                        <div class="p-4 bg-white/5 rounded-lg border border-white/10">
                            <div class="flex items-center justify-between mb-2">
                                <div class="w-8 h-8 bg-blue-500/20 rounded-lg flex items-center justify-center">
                                    <i data-lucide="trending-up" class="w-4 h-4 text-blue-500"></i>
                                </div>
                                <span class="text-xs text-green-400">+15%</span>
                            </div>
                            <div class="text-2xl font-bold" style="color: var(--text-primary);"><?= $totalReports ?></div>
                            <div class="text-sm" style="color: var(--text-secondary);">Total Incidents</div>
                        </div>
                        <div class="p-4 bg-white/5 rounded-lg border border-white/10">
                            <div class="flex items-center justify-between mb-2">
                                <div class="w-8 h-8 bg-red-500/20 rounded-lg flex items-center justify-center">
                                    <i data-lucide="alert-triangle" class="w-4 h-4 text-red-500"></i>
                                </div>
                                <span class="text-xs text-red-400">+8%</span>
                            </div>
                            <div class="text-2xl font-bold" style="color: var(--text-primary);"><?= $activeAlertsCount ?></div>
                            <div class="text-sm" style="color: var(--text-secondary);">Active Alerts</div>
                        </div>
                        <div class="p-4 bg-white/5 rounded-lg border border-white/10">
                            <div class="flex items-center justify-between mb-2">
                                <div class="w-8 h-8 bg-green-500/20 rounded-lg flex items-center justify-center">
                                    <i data-lucide="clock" class="w-4 h-4 text-green-500"></i>
                                </div>
                                <span class="text-xs text-green-400">-12%</span>
                            </div>
                            <div class="text-2xl font-bold" style="color: var(--text-primary);">2.3m</div>
                            <div class="text-sm" style="color: var(--text-secondary);">Avg Response</div>
                        </div>
                        <div class="p-4 bg-white/5 rounded-lg border border-white/10">
                            <div class="flex items-center justify-between mb-2">
                                <div class="w-8 h-8 bg-purple-500/20 rounded-lg flex items-center justify-center">
                                    <i data-lucide="target" class="w-4 h-4 text-purple-500"></i>
                                </div>
                                <span class="text-xs text-purple-400">85%</span>
                            </div>
                            <div class="text-2xl font-bold" style="color: var(--text-primary);">92%</div>
                            <div class="text-sm" style="color: var(--text-secondary);">Resolution Rate</div>
                        </div>
                    </div>

                    <!-- Detailed Trends Table -->
                    <div class="overflow-hidden mb-8">
                        <h4 class="text-lg font-semibold mb-4" style="color: var(--text-primary);">Detailed Metrics</h4>
                        <table class="w-full text-sm category-table">
                            <thead>
                                <tr class="border-b border-white/10">
                                    <th class="text-left py-3 px-4 font-medium" style="color: var(--text-secondary);">Metric</th>
                                    <th class="text-center py-3 px-4 font-medium" style="color: var(--text-secondary);">Current Period</th>
                                    <th class="text-center py-3 px-4 font-medium" style="color: var(--text-secondary);">Previous Period</th>
                                    <th class="text-center py-3 px-4 font-medium" style="color: var(--text-secondary);">Change</th>
                                    <th class="text-center py-3 px-4 font-medium" style="color: var(--text-secondary);">Trend</th>
                                    <th class="text-center py-3 px-4 font-medium" style="color: var(--text-secondary);">Status</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-white/5 trends-table-body">
                                ${generateTrendsTableRows()}
                            </tbody>
                        </table>
                    </div>

                    <!-- Forecasting Section -->
                    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                        <div class="p-4 bg-white/5 rounded-lg border border-white/10">
                            <h4 class="text-lg font-semibold mb-4" style="color: var(--text-primary);">Forecast (Next 30 Days)</h4>
                            <div class="space-y-3">
                                <div class="flex items-center justify-between">
                                    <span style="color: var(--text-secondary);">Predicted Incidents</span>
                                    <span class="font-semibold" style="color: var(--text-primary);"><?= $totalReports + rand(50, 150) ?></span>
                                </div>
                                <div class="flex items-center justify-between">
                                    <span style="color: var(--text-secondary);">Expected Growth</span>
                                    <span class="font-semibold text-green-400">+<?= rand(10, 25) ?>%</span>
                                </div>
                                <div class="flex items-center justify-between">
                                    <span style="color: var(--text-secondary);">Peak Days</span>
                                    <span class="font-semibold" style="color: var(--text-primary);">Weekends</span>
                                </div>
                                <div class="flex items-center justify-between">
                                    <span style="color: var(--text-secondary);">Risk Level</span>
                                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-yellow-500/20 text-yellow-400">
                                        Medium
                                    </span>
                                </div>
                            </div>
                        </div>
                        <div class="p-4 bg-white/5 rounded-lg border border-white/10">
                            <h4 class="text-lg font-semibold mb-4" style="color: var(--text-primary);">Performance Insights</h4>
                            <div class="space-y-3">
                                <div class="flex items-center justify-between">
                                    <span style="color: var(--text-secondary);">Response Efficiency</span>
                                    <div class="flex items-center space-x-2">
                                        <div class="w-16 h-2 bg-gray-200 rounded-full overflow-hidden">
                                            <div class="h-full bg-green-500 rounded-full" style="width: 85%"></div>
                                        </div>
                                        <span class="text-sm font-medium text-green-400">85%</span>
                                    </div>
                                </div>
                                <div class="flex items-center justify-between">
                                    <span style="color: var(--text-secondary);">Alert Accuracy</span>
                                    <div class="flex items-center space-x-2">
                                        <div class="w-16 h-2 bg-gray-200 rounded-full overflow-hidden">
                                            <div class="h-full bg-blue-500 rounded-full" style="width: 92%"></div>
                                        </div>
                                        <span class="text-sm font-medium text-blue-400">92%</span>
                                    </div>
                                </div>
                                <div class="flex items-center justify-between">
                                    <span style="color: var(--text-secondary);">User Satisfaction</span>
                                    <div class="flex items-center space-x-2">
                                        <div class="w-16 h-2 bg-gray-200 rounded-full overflow-hidden">
                                            <div class="h-full bg-purple-500 rounded-full" style="width: 78%"></div>
                                        </div>
                                        <span class="text-sm font-medium text-purple-400">78%</span>
                                    </div>
                                </div>
                                <div class="flex items-center justify-between">
                                    <span style="color: var(--text-secondary);">System Uptime</span>
                                    <div class="flex items-center space-x-2">
                                        <div class="w-16 h-2 bg-gray-200 rounded-full overflow-hidden">
                                            <div class="h-full bg-green-500 rounded-full" style="width: 99.9%"></div>
                                        </div>
                                        <span class="text-sm font-medium text-green-400">99.9%</span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        `;

        document.body.appendChild(modal);
        lucide.createIcons();

        // Close modal functionality
        const closeBtn = modal.querySelector('.close-modal-btn');
        const closeModal = () => {
            modal.remove();
        };

        closeBtn.addEventListener('click', closeModal);
        modal.addEventListener('click', (e) => {
            if (e.target === modal) closeModal();
        });
    }

    function generateTrendsTableRows() {
        // Get REAL data from WebSocket - NO MOCK DATA
        const metricsData = window.detailedMetricsData || {
            dailyReports: { current: 0, previous: 0 },
            criticalIncidents: { current: 0, previous: 0 },
            responseTime: { current: '0h', previous: '0h' },
            resolutionRate: 0
        };

        const metrics = [

            {
                name: 'Critical Incidents',
                icon: 'alert-triangle',
                color: 'red',
                current: metricsData.criticalIncidents.current || 0,
                previous: metricsData.criticalIncidents.previous || 0,
                trend: (metricsData.criticalIncidents.current || 0) >= (metricsData.criticalIncidents.previous || 0) ? 'up' : 'down'
            },
            {
                name: 'Response Time',
                icon: 'clock',
                color: 'green',
                current: metricsData.responseTime.current || '0h',
                previous: metricsData.responseTime.previous || '0h',
                trend: 'up'
            },
            {
                name: 'Resolution Rate',
                icon: 'check-circle',
                color: 'purple',
                current: (metricsData.resolutionRate || 0) + '%',
                previous: '0%',
                trend: 'up'
            },
            {
                name: 'False Alerts',
                icon: 'x-circle',
                color: 'yellow',
                current: 0,
                previous: 0,
                trend: 'down'
            },
            {
                name: 'User Engagement',
                icon: 'users',
                color: 'indigo',
                current: '0%',
                previous: '0%',
                trend: 'up'
            }
        ];

        return metrics.map(metric => {
            // Calculate REAL percentage change from actual data
            let change = '0%';
            let changeColor = 'text-gray-500';

            if (metric.previous !== undefined && metric.previous !== null) {
                const prev = typeof metric.previous === 'string' ? parseFloat(metric.previous) : metric.previous;
                const curr = typeof metric.current === 'string' ? parseFloat(metric.current.replace(/[^0-9.]/g, '')) : metric.current;

                if (prev > 0 && curr !== undefined && curr !== null) {
                    const percentChange = ((curr - prev) / prev) * 100;
                    change = percentChange >= 0 ? `+${Math.round(percentChange)}%` : `${Math.round(percentChange)}%`;
                    changeColor = (metric.name.includes('Time') || metric.name.includes('False')) ?
                        (percentChange <= 0 ? 'text-green-500' : 'text-red-500') :
                        (percentChange >= 0 ? 'text-green-500' : 'text-red-500');
                } else if (prev === 0 && curr > 0) {
                    change = '+100%';
                    changeColor = 'text-green-500';
                }
            }

            const status = getMetricStatus(metric.name, change);

            return `
                <tr class="hover:bg-white/5 transition-colors duration-200">
                    <td class="py-3 px-4">
                        <div class="flex items-center space-x-3">
                            <div class="w-8 h-8 bg-${metric.color}-500/20 rounded-lg flex items-center justify-center">
                                <i data-lucide="${metric.icon}" class="w-4 h-4 text-${metric.color}-500"></i>
                            </div>
                            <span style="color: var(--text-primary);">${metric.name}</span>
                        </div>
                    </td>
                    <td class="py-3 px-4 text-center">
                        <span class="font-semibold" style="color: var(--text-primary);">${metric.current}</span>
                    </td>
                    <td class="py-3 px-4 text-center">
                        <span style="color: var(--text-tertiary);">${metric.previous}</span>
                    </td>
                    <td class="py-3 px-4 text-center">
                        <span class="font-medium ${changeColor}">${change}</span>
                    </td>
                    <td class="py-3 px-4 text-center">
                        <div class="flex items-center justify-center">
                            <i data-lucide="${metric.trend === 'up' ? 'trending-up' : 'trending-down'}" class="w-4 h-4 ${changeColor}"></i>
                        </div>
                    </td>
                    <td class="py-3 px-4 text-center">
                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium ${status.class}">
                            ${status.text}
                        </span>
                    </td>
                </tr>
            `;
        }).join('');
    }

    function getMetricStatus(metricName, change) {
        const changeValue = parseInt(change.replace(/[+%-]/g, ''));

        if (metricName.includes('Time') || metricName.includes('False')) {
            // For these metrics, negative change is good
            if (changeValue > 15) return {text: 'Excellent', class: 'bg-green-500/20 text-green-400'};
            if (changeValue > 5) return {text: 'Good', class: 'bg-blue-500/20 text-blue-400'};
            if (changeValue > 0) return {text: 'Fair', class: 'bg-yellow-500/20 text-yellow-400'};
            return {text: 'Needs Attention', class: 'bg-red-500/20 text-red-400'};
        } else {
            // For other metrics, positive change is good
            if (changeValue > 15) return {text: 'Excellent', class: 'bg-green-500/20 text-green-400'};
            if (changeValue > 5) return {text: 'Good', class: 'bg-blue-500/20 text-blue-400'};
            if (changeValue > 0) return {text: 'Fair', class: 'bg-yellow-500/20 text-yellow-400'};
            return {text: 'Needs Attention', class: 'bg-red-500/20 text-red-400'};
        }
    }

    // Recent Activity functionality
    const activityFilterBtns = document.querySelectorAll('.activity-filter-btn');
    const activityItems = document.querySelectorAll('.activity-item');
    const viewActivityDetailsBtn = document.getElementById('view-activity-details');
    const loadMoreActivitiesBtn = document.getElementById('load-more-activities');

            // Quick Actions functionality
        const quickActionsSettingsBtn = document.getElementById('quick-actions-settings');
        const quickActionsHelpBtn = document.getElementById('quick-actions-help');

        if (quickActionsSettingsBtn) {
            quickActionsSettingsBtn.addEventListener('click', () => {
                showQuickActionsSettingsModal();
            });
        }

        if (quickActionsHelpBtn) {
            quickActionsHelpBtn.addEventListener('click', () => {
                showQuickActionsHelpModal();
            });
        }

        // Keyboard shortcuts for Quick Actions
        document.addEventListener('keydown', (e) => {
            if (e.ctrlKey || e.metaKey) {
                switch (e.key.toLowerCase()) {
                    case 'r':
                        e.preventDefault();
                        window.location.href = 'report_incident.php';
                        break;
                    case 'a':
                        e.preventDefault();
                        window.location.href = 'community_alerts.php';
                        break;
                    case 'k':
                        e.preventDefault();
                        if (window.safeSpaceDashboard) {
                            window.safeSpaceDashboard.showQuickActions();
                        }
                        break;
                }
            }
        });

// Activity filtering
activityFilterBtns.forEach(btn => {
    btn.addEventListener('click', () => {
        const filter = btn.dataset.filter;

        // Update active button
        activityFilterBtns.forEach(b => {
            b.classList.remove('active', 'bg-blue-500', 'text-white');
            b.classList.add('hover:bg-white/10');
        });
        btn.classList.add('active', 'bg-blue-500', 'text-white');
        btn.classList.remove('hover:bg-white/10');

        // ⭐️ --- NEW LOGIC TO SHOW/HIDE THE BUTTON --- ⭐️
        const reportDetailsBtnContainer = document.getElementById('report-details-btn-container');
        if (filter === 'reports') {
            reportDetailsBtnContainer.style.display = 'flex';
        } else {
            reportDetailsBtnContainer.style.display = 'none';
        }
        // ⭐️ --- END NEW LOGIC --- ⭐️

        // Call the new function from your dashboard-enhanced.js file
        if (window.safeSpaceDashboard) {
            window.safeSpaceDashboard.loadAndDisplayActivity(filter);
        } else {
            console.error("Dashboard instance not found.");
        }
    });
});

// --- FIX: Automatically load the 'all' activity filter on page load ---
document.addEventListener('DOMContentLoaded', () => {
    // We wait for the safeSpaceDashboard class in dashboard-enhanced.js to initialize
    // This uses a simple poll, which is safer than relying on DOMContentLoaded alone
    const checkDashboardInit = setInterval(() => {
        if (window.safeSpaceDashboard && typeof window.safeSpaceDashboard.loadAndDisplayActivity === 'function') {
            clearInterval(checkDashboardInit);

            // 1. Load the 'all' data
            window.safeSpaceDashboard.loadAndDisplayActivity('all');

            // 2. Set the 'All' button as active to match the loaded data
            document.querySelectorAll('.activity-filter-btn').forEach(b => {
                b.classList.remove('active', 'bg-blue-500', 'text-white');
                b.classList.add('hover:bg-white/10');
            });
            const allButton = document.querySelector('.activity-filter-btn[data-filter="all"]');
            if (allButton) {
                allButton.classList.add('active', 'bg-blue-500', 'text-white');
                allButton.classList.remove('hover:bg-white/10');
            }

            // 3. Hide the unnecessary report details button if the default 'All' filter is used
            const reportDetailsBtnContainer = document.getElementById('report-details-btn-container');
            if (reportDetailsBtnContainer) {
                reportDetailsBtnContainer.style.display = 'none';
            }
        }
    }, 100);

    // Stop checking after a maximum time to prevent issues
    setTimeout(() => {
        clearInterval(checkDashboardInit);
    }, 5000);
});
// --- END FIX ---

    // Activity details modal
    if (viewActivityDetailsBtn) {
        viewActivityDetailsBtn.addEventListener('click', () => {
            showActivityDetailsModal();
        });
    }

    // Load more activities
    if (loadMoreActivitiesBtn) {
        loadMoreActivitiesBtn.addEventListener('click', () => {
            loadMoreActivities();
        });
    }

    // Activity detail buttons
    document.addEventListener('click', (e) => {
        if (e.target.closest('.activity-details-btn')) {
            const activityId = e.target.closest('.activity-details-btn').dataset.activityId;
            showActivityDetailModal(activityId);
        }
    });

    function showActivityDetailsModal() {
        const modal = document.createElement('div');
        modal.className = 'fixed inset-0 bg-black/50 backdrop-blur-sm z-50 flex items-center justify-center p-4';
        modal.innerHTML = `
            <div class="bg-white/10 backdrop-blur-md rounded-xl border border-white/20 max-w-6xl w-full max-h-[90vh] overflow-hidden">
                <div class="flex items-center justify-between p-6 border-b border-white/10">
                    <div>
                        <h3 class="text-xl font-semibold" style="color: var(--text-primary);">Activity Analytics</h3>
                        <p class="text-sm mt-1" style="color: var(--text-secondary);">Comprehensive activity analysis and insights</p>
                    </div>
                    <button class="close-modal-btn p-2 hover:bg-white/10 rounded-lg transition-colors">
                        <i data-lucide="x" class="w-5 h-5" style="color: var(--text-primary);"></i>
                    </button>
                </div>
                <div class="p-6 overflow-y-auto max-h-[70vh]">
                    <!-- Activity Overview -->
                    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4 mb-8">
                        <div class="p-4 bg-white/5 rounded-lg border border-white/10">
                            <div class="flex items-center justify-between mb-2">
                                <div class="w-8 h-8 bg-blue-500/20 rounded-lg flex items-center justify-center">
                                    <i data-lucide="activity" class="w-4 h-4 text-blue-500"></i>
                                </div>
                                <span class="text-xs text-green-400">+25%</span>
                            </div>
                            <div class="text-2xl font-bold" style="color: var(--text-primary);"><?= count($recentActivity) ?></div>
                            <div class="text-sm" style="color: var(--text-secondary);">Total Activities</div>
                        </div>
                        <div class="p-4 bg-white/5 rounded-lg border border-white/10">
                            <div class="flex items-center justify-between mb-2">
                                <div class="w-8 h-8 bg-green-500/20 rounded-lg flex items-center justify-center">
                                    <i data-lucide="users" class="w-4 h-4 text-green-500"></i>
                                </div>
                                <span class="text-xs text-green-400">+12%</span>
                            </div>
                            <div class="text-2xl font-bold" style="color: var(--text-primary);"><?= count(array_filter($recentActivity, fn($a) => $a['type'] === 'user')) ?></div>
                            <div class="text-sm" style="color: var(--text-secondary);">New Users</div>
                        </div>
                        <div class="p-4 bg-white/5 rounded-lg border border-white/10">
                            <div class="flex items-center justify-between mb-2">
                                <div class="w-8 h-8 bg-yellow-500/20 rounded-lg flex items-center justify-center">
                                    <i data-lucide="clock" class="w-4 h-4 text-yellow-500"></i>
                                </div>
                                <span class="text-xs text-blue-400">2.3m</span>
                            </div>
                            <div class="text-2xl font-bold" style="color: var(--text-primary);"><?= count(array_filter($recentActivity, fn($a) => $a['type'] === 'report')) ?></div>
                            <div class="text-sm" style="color: var(--text-secondary);">Reports Today</div>
                        </div>
                        <div class="p-4 bg-white/5 rounded-lg border border-white/10">
                            <div class="flex items-center justify-between mb-2">
                                <div class="w-8 h-8 bg-purple-500/20 rounded-lg flex items-center justify-center">
                                    <i data-lucide="trending-up" class="w-4 h-4 text-purple-500"></i>
                                </div>
                                <span class="text-xs text-purple-400">Peak</span>
                            </div>
                            <div class="text-2xl font-bold" style="color: var(--text-primary);">4:45 PM</div>
                            <div class="text-sm" style="color: var(--text-secondary);">Peak Activity Time</div>
                        </div>
                    </div>

                    <!-- Activity Breakdown -->
                    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-8">
                        <div class="p-4 bg-white/5 rounded-lg border border-white/10">
                            <h4 class="text-lg font-semibold mb-4" style="color: var(--text-primary);">Activity by Type</h4>
                            <div class="space-y-3">
                                ${generateActivityTypeBreakdown()}
                            </div>
                        </div>
                        <div class="p-4 bg-white/5 rounded-lg border border-white/10">
                            <h4 class="text-lg font-semibold mb-4" style="color: var(--text-primary);">Hourly Distribution</h4>
                            <div class="space-y-3">
                                ${generateHourlyDistribution()}
                            </div>
                        </div>
                    </div>

                    <!-- All Activities Table -->
                    <div class="overflow-hidden">
                        <h4 class="text-lg font-semibold mb-4" style="color: var(--text-primary);">All Recent Activities</h4>
                        <table class="w-full text-sm category-table">
                            <thead>
                                <tr class="border-b border-white/10">
                                    <th class="text-left py-3 px-4 font-medium" style="color: var(--text-secondary);">Activity</th>
                                    <th class="text-center py-3 px-4 font-medium" style="color: var(--text-secondary);">Type</th>
                                    <th class="text-center py-3 px-4 font-medium" style="color: var(--text-secondary);">Time</th>
                                    <th class="text-center py-3 px-4 font-medium" style="color: var(--text-secondary);">Status</th>
                                    <th class="text-center py-3 px-4 font-medium" style="color: var(--text-secondary);">Actions</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-white/5">
                                ${generateAllActivitiesTable()}
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        `;

        document.body.appendChild(modal);
        lucide.createIcons();

        // Close modal functionality
        const closeBtn = modal.querySelector('.close-modal-btn');
        const closeModal = () => {
            modal.remove();
        };

        closeBtn.addEventListener('click', closeModal);
        modal.addEventListener('click', (e) => {
            if (e.target === modal) closeModal();
        });
    }

    function generateActivityTypeBreakdown() {
        const activities = <?= json_encode($recentActivity) ?>;
        const typeCounts = {};

        activities.forEach(activity => {
            typeCounts[activity.type] = (typeCounts[activity.type] || 0) + 1;
        });

        const total = activities.length;
        const types = [
            {type: 'report', name: 'Reports', color: 'blue', icon: 'file-text'},
            {type: 'alert', name: 'Alerts', color: 'yellow', icon: 'bell'},
            {type: 'user', name: 'Users', color: 'green', icon: 'user-plus'},
            {type: 'dispute', name: 'Disputes', color: 'purple', icon: 'gavel'}
        ];

        return types.map(type => {
            const count = typeCounts[type.type] || 0;
            const percentage = total > 0 ? Math.round((count / total) * 100) : 0;

            return `
                <div class="flex items-center justify-between">
                    <div class="flex items-center space-x-3">
                        <div class="w-6 h-6 bg-${type.color}-500/20 rounded flex items-center justify-center">
                            <i data-lucide="${type.icon}" class="w-3 h-3 text-${type.color}-500"></i>
                        </div>
                        <span style="color: var(--text-primary);">${type.name}</span>
                    </div>
                    <div class="flex items-center space-x-2">
                        <div class="w-16 h-2 bg-gray-200 rounded-full overflow-hidden">
                            <div class="h-full bg-${type.color}-500 rounded-full" style="width: ${percentage}%"></div>
                        </div>
                        <span class="text-sm font-medium" style="color: var(--text-tertiary);">${count}</span>
                    </div>
                </div>
            `;
        }).join('');
    }

    function generateHourlyDistribution() {
        const hours = [
            {hour: '9 AM', count: 5, color: 'green'},
            {hour: '10 AM', count: 12, color: 'blue'},
            {hour: '11 AM', count: 8, color: 'yellow'},
            {hour: '12 PM', count: 15, color: 'purple'},
            {hour: '1 PM', count: 10, color: 'blue'},
            {hour: '2 PM', count: 18, color: 'red'},
            {hour: '3 PM', count: 14, color: 'yellow'},
            {hour: '4 PM', count: 22, color: 'purple'},
            {hour: '5 PM', count: 16, color: 'blue'}
        ];

        return hours.map(hour => {
            const maxCount = Math.max(...hours.map(h => h.count));
            const percentage = Math.round((hour.count / maxCount) * 100);

            return `
                <div class="flex items-center justify-between">
                    <span style="color: var(--text-primary);">${hour.hour}</span>
                    <div class="flex items-center space-x-2">
                        <div class="w-20 h-2 bg-gray-200 rounded-full overflow-hidden">
                            <div class="h-full bg-${hour.color}-500 rounded-full" style="width: ${percentage}%"></div>
                        </div>
                        <span class="text-sm font-medium" style="color: var(--text-tertiary);">${hour.count}</span>
                    </div>
                </div>
            `;
        }).join('');
    }

    function generateAllActivitiesTable() {
        const activities = <?= json_encode($recentActivity) ?>;
        const activityColors = {
            'report': {color: 'blue', icon: 'file-text', status: 'New Report'},
            'alert': {color: 'yellow', icon: 'bell', status: 'Alert Created'},
            'user': {color: 'green', icon: 'user-plus', status: 'User Registered'},
            'dispute': {color: 'purple', icon: 'gavel', status: 'Dispute Filed'}
        };

        return activities.map(activity => {
            const color = activityColors[activity.type] || {color: 'gray', icon: 'activity', status: 'Activity'};
            const time = new Date(activity.date).toLocaleTimeString('en-US', {hour: '2-digit', minute: '2-digit'});

            return `
                <tr class="hover:bg-white/5 transition-colors duration-200">
                    <td class="py-3 px-4">
                        <div class="flex items-center space-x-3">
                            <div class="w-8 h-8 bg-${color.color}-500/20 rounded-lg flex items-center justify-center">
                                <i data-lucide="${color.icon}" class="w-4 h-4 text-${color.color}-500"></i>
                            </div>
                            <div>
                                <div class="font-medium" style="color: var(--text-primary);">${activity.title}</div>
                                <div class="text-xs" style="color: var(--text-tertiary);">ID: ${activity.id}</div>
                            </div>
                        </div>
                    </td>
                    <td class="py-3 px-4 text-center">
                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-${color.color}-500/20 text-${color.color}-400">
                            ${activity.type.charAt(0).toUpperCase() + activity.type.slice(1)}
                        </span>
                    </td>
                    <td class="py-3 px-4 text-center">
                        <span style="color: var(--text-primary);">${time}</span>
                    </td>
                    <td class="py-3 px-4 text-center">
                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-green-500/20 text-green-400">
                            Completed
                        </span>
                    </td>
                    <td class="py-3 px-4 text-center">
                        <button class="p-1 hover:bg-white/10 rounded transition-colors">
                            <i data-lucide="eye" class="w-4 h-4" style="color: var(--text-tertiary);"></i>
                        </button>
                    </td>
                </tr>
            `;
        }).join('');
    }

    function loadMoreActivities() {
        // Simulate loading more activities
        const loadMoreBtn = document.getElementById('load-more-activities');
        if (loadMoreBtn) {
            loadMoreBtn.innerHTML = '<div class="spinner w-4 h-4 border-2 border-primary-500 border-t-transparent rounded-full animate-spin mx-auto"></div>';

            setTimeout(() => {
                // In a real implementation, this would fetch more data from the server
                loadMoreBtn.textContent = 'All activities loaded';
                loadMoreBtn.disabled = true;
                loadMoreBtn.classList.add('opacity-50');
            }, 2000);
        }
    }

    function showQuickActionsSettingsModal() {
        const modal = document.createElement('div');
        modal.className = 'fixed inset-0 bg-black/50 backdrop-blur-sm flex items-center justify-center z-50';
        modal.innerHTML = `
            <div class="bg-white/10 backdrop-blur-xl border border-white/20 rounded-2xl p-6 max-w-lg w-full mx-4 transform transition-all duration-300 scale-95 opacity-0">
                <div class="flex items-center justify-between mb-6">
                    <div class="flex items-center space-x-3">
                        <div class="w-10 h-10 bg-blue-500/20 rounded-lg flex items-center justify-center">
                            <i data-lucide="settings" class="w-5 h-5 text-blue-500"></i>
                        </div>
                        <h3 class="text-xl font-semibold" style="color: var(--text-primary);">Quick Actions Settings</h3>
                    </div>
                    <button class="close-modal text-white/60 hover:text-white transition-colors">
                        <i data-lucide="x" class="w-5 h-5"></i>
                    </button>
                </div>

                <div class="space-y-6">
                    <div>
                        <h4 class="text-sm font-medium mb-3" style="color: var(--text-primary);">Keyboard Shortcuts</h4>
                        <div class="space-y-2">
                            <div class="flex items-center justify-between p-3 bg-white/5 rounded-lg">
                                <span class="text-sm" style="color: var(--text-secondary);">Report Incident</span>
                                <kbd class="keyboard-shortcut">Ctrl + R</kbd>
                            </div>
                            <div class="flex items-center justify-between p-3 bg-white/5 rounded-lg">
                                <span class="text-sm" style="color: var(--text-secondary);">Community Alerts</span>
                                <kbd class="keyboard-shortcut">Ctrl + A</kbd>
                            </div>
                            <div class="flex items-center justify-between p-3 bg-white/5 rounded-lg">
                                <span class="text-sm" style="color: var(--text-secondary);">Quick Actions Menu</span>
                                <kbd class="keyboard-shortcut">Ctrl + K</kbd>
                            </div>
                        </div>
                    </div>

                    <div>
                        <h4 class="text-sm font-medium mb-3" style="color: var(--text-primary);">Display Options</h4>
                        <div class="space-y-3">
                            <label class="flex items-center space-x-3 cursor-pointer">
                                <input type="checkbox" class="form-checkbox" checked>
                                <span class="text-sm" style="color: var(--text-secondary);">Show priority actions first</span>
                            </label>
                            <label class="flex items-center space-x-3 cursor-pointer">
                                <input type="checkbox" class="form-checkbox" checked>
                                <span class="text-sm" style="color: var(--text-secondary);">Show keyboard shortcuts</span>
                            </label>
                            <label class="flex items-center space-x-3 cursor-pointer">
                                <input type="checkbox" class="form-checkbox">
                                <span class="text-sm" style="color: var(--text-secondary);">Compact layout</span>
                            </label>
                        </div>
                    </div>
                </div>

                <div class="flex items-center justify-end space-x-3 mt-6 pt-4 border-t border-white/10">
                    <button class="px-4 py-2 text-sm font-medium transition-colors duration-200 close-modal" style="color: var(--text-secondary);">Cancel</button>
                    <button class="px-4 py-2 bg-blue-500 text-white text-sm font-medium rounded-lg hover:bg-blue-600 transition-colors duration-200">Save Settings</button>
                </div>
            </div>
        `;

        document.body.appendChild(modal);
        lucide.createIcons();

        setTimeout(() => {
            modal.querySelector('.bg-white\\/10').classList.remove('scale-95', 'opacity-0');
        }, 10);

        modal.addEventListener('click', (e) => {
            if (e.target === modal || e.target.closest('.close-modal')) {
                modal.remove();
            }
        });
    }

    function showQuickActionsHelpModal() {
        const modal = document.createElement('div');
        modal.className = 'fixed inset-0 bg-black/50 backdrop-blur-sm flex items-center justify-center z-50';
        modal.innerHTML = `
            <div class="bg-white/10 backdrop-blur-xl border border-white/20 rounded-2xl p-6 max-w-2xl w-full mx-4 transform transition-all duration-300 scale-95 opacity-0">
                <div class="flex items-center justify-between mb-6">
                    <div class="flex items-center space-x-3">
                        <div class="w-10 h-10 bg-green-500/20 rounded-lg flex items-center justify-center">
                            <i data-lucide="help-circle" class="w-5 h-5 text-green-500"></i>
                        </div>
                        <h3 class="text-xl font-semibold" style="color: var(--text-primary);">Quick Actions Help</h3>
                    </div>
                    <button class="close-modal text-white/60 hover:text-white transition-colors">
                        <i data-lucide="x" class="w-5 h-5"></i>
                    </button>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <div>
                        <h4 class="text-sm font-medium mb-3" style="color: var(--text-primary);">Priority Actions</h4>
                        <div class="space-y-3">
                            <div class="p-3 bg-white/5 rounded-lg">
                                <div class="flex items-center space-x-2 mb-1">
                                    <i data-lucide="alert-triangle" class="w-4 h-4 text-red-500"></i>
                                    <span class="text-sm font-medium" style="color: var(--text-primary);">Report Incident</span>
                                </div>
                                <p class="text-xs" style="color: var(--text-secondary);">For immediate safety concerns and harassment reports</p>
                            </div>
                            <div class="p-3 bg-white/5 rounded-lg">
                                <div class="flex items-center space-x-2 mb-1">
                                    <i data-lucide="bell" class="w-4 h-4 text-yellow-500"></i>
                                    <span class="text-sm font-medium" style="color: var(--text-primary);">Community Alerts</span>
                                </div>
                                <p class="text-xs" style="color: var(--text-secondary);">Real-time safety alerts in your area</p>
                            </div>
                        </div>
                    </div>

                    <div>
                        <h4 class="text-sm font-medium mb-3" style="color: var(--text-primary);">Standard Actions</h4>
                        <div class="space-y-3">
                            <div class="p-3 bg-white/5 rounded-lg">
                                <div class="flex items-center space-x-2 mb-1">
                                    <i data-lucide="map-pin" class="w-4 h-4 text-blue-500"></i>
                                    <span class="text-sm font-medium" style="color: var(--text-primary);">Safe Spaces</span>
                                </div>
                                <p class="text-xs" style="color: var(--text-secondary);">Find verified safe locations nearby</p>
                            </div>
                            <div class="p-3 bg-white/5 rounded-lg">
                                <div class="flex items-center space-x-2 mb-1">
                                    <i data-lucide="folder-open" class="w-4 h-4 text-purple-500"></i>
                                    <span class="text-sm font-medium" style="color: var(--text-primary);">My Reports</span>
                                </div>
                                <p class="text-xs" style="color: var(--text-secondary);">Manage your incident reports</p>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="mt-6 p-4 bg-blue-500/10 border border-blue-500/20 rounded-lg">
                    <div class="flex items-center space-x-2 mb-2">
                        <i data-lucide="info" class="w-4 h-4 text-blue-500"></i>
                        <span class="text-sm font-medium" style="color: var(--text-primary);">Need Immediate Help?</span>
                    </div>
                    <p class="text-xs" style="color: var(--text-secondary);">All reports are anonymous and secure. For emergencies, contact local authorities immediately.</p>
                </div>

                <div class="flex items-center justify-end mt-6">
                    <button class="px-4 py-2 bg-blue-500 text-white text-sm font-medium rounded-lg hover:bg-blue-600 transition-colors duration-200 close-modal">Got it</button>
                </div>
            </div>
        `;

        document.body.appendChild(modal);
        lucide.createIcons();

        setTimeout(() => {
            modal.querySelector('.bg-white\\/10').classList.remove('scale-95', 'opacity-0');
        }, 10);

        modal.addEventListener('click', (e) => {
            if (e.target === modal || e.target.closest('.close-modal')) {
                modal.remove();
            }
        });
    }

    // ⭐️ --- NEW: Add click listener for the new 'View Details' button --- ⭐️
const viewReportDetailsBtn = document.getElementById('view-report-details-btn');
if (viewReportDetailsBtn) {
    viewReportDetailsBtn.addEventListener('click', () => {
        // Redirect to our new recent reports page
  window.location.href = 'recent_reports_details.php';
    });
}

    function showActivityDetailModal(activityId) {
        // Show individual activity detail modal
        showToast(`Viewing details for activity #${activityId}`, 'info');
    }
    </script>

    <!-- Professional Footer -->
    <footer class="mt-16 backdrop-blur-sm" style="border-top: 1px solid var(--border-primary); background: var(--bg-secondary);">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
            <div class="grid grid-cols-1 md:grid-cols-4 gap-8">
                <!-- Brand Section -->
                <div class="md:col-span-2">
                    <div class="flex items-center space-x-3 mb-4">
                        <div class="w-8 h-8 bg-gradient-to-r from-primary-500 to-secondary-500 rounded-lg flex items-center justify-center">
                            <i data-lucide="shield" class="w-5 h-5 text-white"></i>
                        </div>
                        <span class="text-xl font-display font-bold gradient-text">SafeSpace</span>
                    </div>
                    <p class="mb-4 max-w-md" style="color: var(--text-secondary);">
                        Empowering communities with real-time safety information and anonymous reporting tools.
                        Together, we create safer spaces for everyone.
                    </p>
                    <div class="flex space-x-4">
                        <a href="#" class="transition-colors duration-200" style="color: var(--text-tertiary);">
                            <i data-lucide="twitter" class="w-5 h-5"></i>
                        </a>
                        <a href="#" class="transition-colors duration-200" style="color: var(--text-tertiary);">
                            <i data-lucide="facebook" class="w-5 h-5"></i>
                        </a>
                        <a href="#" class="transition-colors duration-200" style="color: var(--text-tertiary);">
                            <i data-lucide="instagram" class="w-5 h-5"></i>
                        </a>
                        <a href="#" class="transition-colors duration-200" style="color: var(--text-tertiary);">
                            <i data-lucide="linkedin" class="w-5 h-5"></i>
                        </a>
                    </div>
                </div>

                <!-- Quick Links -->
                <div>
                    <h4 class="font-semibold mb-4" style="color: var(--text-primary);">Quick Links</h4>
                    <ul class="space-y-2">
                        <li><a href="#" class="transition-colors duration-200" style="color: var(--text-tertiary);">About Us</a></li>
                        <li><a href="#" class="transition-colors duration-200" style="color: var(--text-tertiary);">Safety Guidelines</a></li>
                        <li><a href="#" class="transition-colors duration-200" style="color: var(--text-tertiary);">Emergency Contacts</a></li>
                        <li><a href="#" class="transition-colors duration-200" style="color: var(--text-tertiary);">Privacy Policy</a></li>
                        <li><a href="#" class="transition-colors duration-200" style="color: var(--text-tertiary);">Terms of Service</a></li>
                    </ul>
                </div>

                <!-- Support -->
                <div>
                    <h4 class="font-semibold mb-4" style="color: var(--text-primary);">Support</h4>
                    <ul class="space-y-2">
                        <li><a href="#" class="transition-colors duration-200" style="color: var(--text-tertiary);">Help Center</a></li>
                        <li><a href="#" class="transition-colors duration-200" style="color: var(--text-tertiary);">Contact Support</a></li>
                        <li><a href="#" class="transition-colors duration-200" style="color: var(--text-tertiary);">Report Bug</a></li>
                        <li><a href="#" class="transition-colors duration-200" style="color: var(--text-tertiary);">Feature Request</a></li>
                        <li><a href="#" class="transition-colors duration-200" style="color: var(--text-tertiary);">Community Forum</a></li>
                    </ul>
                </div>
            </div>

            <!-- Bottom Section -->
            <div class="mt-8 pt-8 flex flex-col md:flex-row justify-between items-center" style="border-top: 1px solid var(--border-primary);">
                <div class="text-sm" style="color: var(--text-tertiary);">
                    © 2024 SafeSpace. All rights reserved. Made with ❤️ for safer communities.
                </div>
                <div class="flex items-center space-x-6 mt-4 md:mt-0">
                    <span class="text-sm" style="color: var(--text-tertiary);">Status: <span class="text-accent-500">All Systems Operational</span></span>
                    <span class="text-sm" style="color: var(--text-tertiary);">Version: 2.1.0</span>
                </div>
            </div>
        </div>
    </footer>
</body>
</html>