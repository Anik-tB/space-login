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

// Get safety resources
$resources = $models->getSafetyResources();

// Filter by category if specified
$category = $_GET['category'] ?? '';
if ($category) {
    $resources = array_filter($resources, fn($r) => $r['category'] === $category);
}

// Get emergency resources (24/7)
$emergencyResources = array_filter($resources, fn($r) => $r['is_24_7'] == 1);
?>
<!DOCTYPE html>
<html lang="<?= $lang ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Safety Resources - SafeSpace Portal</title>

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
            transform: translateY(-2px);
            box-shadow: 0 12px 40px rgba(0, 0, 0, 0.15);
        }

        /* Enhanced Resource Cards */
        .resource-card {
            background: linear-gradient(135deg, rgba(255, 255, 255, 0.08), rgba(255, 255, 255, 0.03));
            backdrop-filter: blur(15px);
            border: 1px solid rgba(255, 255, 255, 0.1);
            border-radius: 20px;
            padding: 24px;
            transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
            position: relative;
            overflow: hidden;
        }

        .resource-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.1), transparent);
            transition: left 0.6s ease;
        }

        .resource-card:hover::before {
            left: 100%;
        }

        .resource-card:hover {
            background: linear-gradient(135deg, rgba(255, 255, 255, 0.12), rgba(255, 255, 255, 0.06));
            border-color: rgba(255, 255, 255, 0.2);
            transform: translateY(-4px);
            box-shadow: 0 16px 48px rgba(0, 0, 0, 0.2);
        }

        /* Emergency Resource Cards */
        .emergency-card {
            background: linear-gradient(135deg, rgba(239, 68, 68, 0.15), rgba(239, 68, 68, 0.05));
            backdrop-filter: blur(20px);
            border: 2px solid rgba(239, 68, 68, 0.3);
            border-radius: 20px;
            padding: 24px;
            transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
            position: relative;
            overflow: hidden;
        }

        .emergency-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(239, 68, 68, 0.2), transparent);
            transition: left 0.6s ease;
        }

        .emergency-card:hover::before {
            left: 100%;
        }

        .emergency-card:hover {
            background: linear-gradient(135deg, rgba(239, 68, 68, 0.2), rgba(239, 68, 68, 0.1));
            border-color: rgba(239, 68, 68, 0.5);
            transform: translateY(-4px);
            box-shadow: 0 16px 48px rgba(239, 68, 68, 0.3);
        }

        /* Buttons (neutralized animations for links) */
        .btn-liquid {
            background: linear-gradient(135deg, var(--accent-teal), var(--accent-purple));
            border: none;
            border-radius: 12px;
            padding: 12px 24px;
            color: white;
            font-weight: 600;
        }

        .btn-liquid::before { content: none; }

        .btn-liquid:hover { transform: none; box-shadow: none; }

        /* Emergency Button */
        .btn-emergency {
            background: linear-gradient(135deg, #ef4444, #dc2626);
            border: none;
            border-radius: 12px;
            padding: 12px 24px;
            color: white;
            font-weight: 600;
        }

        .btn-emergency::before { content: none; }

        .btn-emergency:hover { transform: none; box-shadow: none; }

        /* Category Badges */
        .category-badge {
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            backdrop-filter: blur(10px);
        }

        .category-emergency {
            background: linear-gradient(135deg, rgba(239, 68, 68, 0.2), rgba(239, 68, 68, 0.1));
            border: 1px solid rgba(239, 68, 68, 0.3);
            color: #f87171;
        }

        .category-police {
            background: linear-gradient(135deg, rgba(59, 130, 246, 0.2), rgba(59, 130, 246, 0.1));
            border: 1px solid rgba(59, 130, 246, 0.3);
            color: #60a5fa;
        }

        .category-legal {
            background: linear-gradient(135deg, rgba(139, 92, 246, 0.2), rgba(139, 92, 246, 0.1));
            border: 1px solid rgba(139, 92, 246, 0.3);
            color: #a78bfa;
        }

        .category-support {
            background: linear-gradient(135deg, rgba(34, 197, 94, 0.2), rgba(34, 197, 94, 0.1));
            border: 1px solid rgba(34, 197, 94, 0.3);
            color: #4ade80;
        }

        .category-health {
            background: linear-gradient(135deg, rgba(245, 158, 11, 0.2), rgba(245, 158, 11, 0.1));
            border: 1px solid rgba(245, 158, 11, 0.3);
            color: #fbbf24;
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

        .animate-pulse-slow {
            animation: pulseSlow 2s infinite;
        }

        @keyframes slideIn {
            from {
                opacity: 0;
                transform: translateX(-30px);
            }
            to {
                opacity: 1;
                transform: translateX(0);
            }
        }

        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(30px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        @keyframes scaleIn {
            from {
                opacity: 0;
                transform: scale(0.9);
            }
            to {
                opacity: 1;
                transform: scale(1);
            }
        }

        @keyframes pulseSlow {
            0%, 100% {
                opacity: 1;
            }
            50% {
                opacity: 0.7;
            }
        }

        /* Enhanced Section Headers */
        .section-header {
            background: linear-gradient(135deg, rgba(255, 255, 255, 0.1), rgba(255, 255, 255, 0.05));
            border-radius: 20px;
            padding: 24px;
            margin-bottom: 24px;
            backdrop-filter: blur(15px);
            border: 1px solid rgba(255, 255, 255, 0.1);
        }

        /* Responsive Enhancements */
        @media (max-width: 768px) {
            .resource-card {
                padding: 20px;
            }

            .emergency-card {
                padding: 20px;
            }

            .section-header {
                padding: 20px;
            }
        }

        /* Minimal link/button styles (no animations) */
        .link-simple { color: #93c5fd; text-decoration: underline; font-weight: 600; }
        .link-simple:hover { text-decoration: underline; opacity: 0.9; }

        .btn-copy {
            background: rgba(255, 255, 255, 0.1);
            border: 1px solid rgba(255, 255, 255, 0.2);
            color: #ffffff;
            border-radius: 8px;
            padding: 8px 12px;
            font-size: 12px;
            font-weight: 600;
        }

        .plain-card {
            background: rgba(255, 255, 255, 0.06);
            border: 1px solid rgba(255, 255, 255, 0.12);
            border-radius: 16px;
            padding: 16px;
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
                    <a href="my_reports.php" class="text-white/70 hover:text-white transition-colors duration-200">My Reports</a>
                    <a href="dispute_center.php" class="text-white/70 hover:text-white transition-colors duration-200">Disputes</a>
                    <a href="safety_resources.php" class="text-white font-medium">Resources</a>
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
            <!-- Enhanced Header Section -->
            <section class="mb-8 animate-fade-in-up">
                <div class="section-header">
                    <div class="flex items-center justify-between">
                        <div>
                            <h1 class="text-4xl font-bold text-white mb-3">Safety Resources</h1>
                            <p class="text-xl text-white/70">
                                Emergency contacts and safety information for Bangladesh
                            </p>
                        </div>
                        <div class="w-16 h-16 bg-gradient-to-r from-red-500 to-red-600 rounded-2xl flex items-center justify-center shadow-lg animate-pulse-slow">
                            <i data-lucide="shield" class="w-8 h-8 text-white"></i>
                        </div>
                    </div>
                </div>
            </section>

            <!-- Emergency Contacts Section -->
            <section class="mb-8 animate-fade-in-up" style="animation-delay: 0.2s;">
                <div class="emergency-card">
                    <div class="flex items-center mb-6">
                        <div class="w-16 h-16 bg-gradient-to-r from-red-500 to-red-600 rounded-2xl flex items-center justify-center mr-6 shadow-lg">
                            <i data-lucide="phone" class="w-8 h-8 text-white"></i>
                        </div>
                        <div>
                            <h2 class="text-2xl font-bold text-white mb-2">🚨 Emergency Contacts (24/7)</h2>
                            <p class="text-lg text-white/70">Immediate assistance available across Bangladesh</p>
                        </div>
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                        <!-- National Emergency Services -->
                        <div class="resource-card animate-slide-in" style="animation-delay: 0.3s;">
                            <div class="flex items-center justify-between mb-4">
                                <h3 class="text-lg font-bold text-white">National Emergency</h3>
                                <span class="category-badge category-emergency">Emergency</span>
                            </div>
                            <p class="text-sm text-white/70 mb-4">National emergency hotline for immediate assistance</p>
                            <div class="flex items-center space-x-3">
                                <a href="tel:999" class="btn-emergency" aria-label="Call National Emergency 999">
                                    <i data-lucide="phone" class="w-4 h-4 mr-2"></i>
                                    999
                                </a>
                                <span class="text-xs text-white/50">24/7 Available</span>
                                <a href="https://999.gov.bd/" target="_blank" rel="noopener noreferrer" class="link-simple">Website</a>
                            </div>
                        </div>

                        <!-- Police Emergency -->
                        <div class="resource-card animate-slide-in" style="animation-delay: 0.4s;">
                            <div class="flex items-center justify-between mb-4">
                                <h3 class="text-lg font-bold text-white">Police Emergency</h3>
                                <span class="category-badge category-police">Police</span>
                            </div>
                            <p class="text-sm text-white/70 mb-4">Bangladesh Police emergency response</p>
                            <div class="flex items-center space-x-3">
                                <a href="tel:999" class="btn-emergency" aria-label="Call Police Emergency 999">
                                    <i data-lucide="phone" class="w-4 h-4 mr-2"></i>
                                    999
                                </a>
                                <span class="text-xs text-white/50">24/7 Available</span>
                                <a href="https://www.police.gov.bd/" target="_blank" rel="noopener noreferrer" class="link-simple">Bangladesh Police</a>
                            </div>
                        </div>

                        <!-- Fire Service -->
                        <div class="resource-card animate-slide-in" style="animation-delay: 0.5s;">
                            <div class="flex items-center justify-between mb-4">
                                <h3 class="text-lg font-bold text-white">Fire Service</h3>
                                <span class="category-badge category-emergency">Emergency</span>
                            </div>
                            <p class="text-sm text-white/70 mb-4">Bangladesh Fire Service & Civil Defence</p>
                            <div class="flex items-center space-x-2">
                                <a href="tel:16163" class="btn-emergency" aria-label="Call Fire Service 16163">
                                    <i data-lucide="phone" class="w-4 h-4 mr-2"></i>
                                    16163
                                </a>
                                <span class="text-xs text-white/50">24/7 Available</span>
                            </div>
                        </div>

                        <!-- Ambulance Service -->
                        <div class="resource-card animate-slide-in" style="animation-delay: 0.6s;">
                            <div class="flex items-center justify-between mb-4">
                                <h3 class="text-lg font-bold text-white">Ambulance Service</h3>
                                <span class="category-badge category-health">Health</span>
                            </div>
                            <p class="text-sm text-white/70 mb-4">Emergency medical transportation</p>
                            <div class="flex items-center space-x-3">
                                <a href="tel:16222" class="btn-emergency" aria-label="Call Ambulance Service 16222">
                                    <i data-lucide="phone" class="w-4 h-4 mr-2"></i>
                                    16222
                                </a>
                                <span class="text-xs text-white/50">24/7 Available</span>
                                <a href="https://dghs.gov.bd/" target="_blank" rel="noopener noreferrer" class="link-simple">DGHS</a>
                            </div>
                        </div>

                        <!-- Women & Children Helpline -->
                        <div class="resource-card animate-slide-in" style="animation-delay: 0.7s;">
                            <div class="flex items-center justify-between mb-4">
                                <h3 class="text-lg font-bold text-white">Women & Children</h3>
                                <span class="category-badge category-support">Support</span>
                            </div>
                            <p class="text-sm text-white/70 mb-4">Specialized support for women and children</p>
                            <div class="flex items-center space-x-3">
                                <a href="tel:10921" class="btn-emergency" aria-label="Call Women and Children Helpline 10921">
                                    <i data-lucide="phone" class="w-4 h-4 mr-2"></i>
                                    10921
                                </a>
                                <span class="text-xs text-white/50">24/7 Available</span>
                                <a href="https://mowca.gov.bd/" target="_blank" rel="noopener noreferrer" class="link-simple">MOWCA</a>
                            </div>
                        </div>

                        <!-- Anti-Corruption Commission -->
                        <div class="resource-card animate-slide-in" style="animation-delay: 0.8s;">
                            <div class="flex items-center justify-between mb-4">
                                <h3 class="text-lg font-bold text-white">Anti-Corruption</h3>
                                <span class="category-badge category-legal">Legal</span>
                            </div>
                            <p class="text-sm text-white/70 mb-4">Report corruption and misconduct</p>
                            <div class="flex items-center space-x-3">
                                <a href="tel:106" class="btn-emergency" aria-label="Call Anti-Corruption Commission 106">
                                    <i data-lucide="phone" class="w-4 h-4 mr-2"></i>
                                    106
                                </a>
                                <span class="text-xs text-white/50">24/7 Available</span>
                                <a href="https://acc.org.bd/" target="_blank" rel="noopener noreferrer" class="link-simple">ACC</a>
                            </div>
                        </div>
                    </div>
                </div>
            </section>

            <!-- Dhaka City Emergency Contacts -->
            <section class="mb-8 animate-fade-in-up" style="animation-delay: 0.3s;">
                <div class="liquid-glass rounded-3xl">
                    <div class="p-8">
                        <div class="flex items-center mb-6">
                            <div class="w-16 h-16 bg-gradient-to-r from-blue-500 to-blue-600 rounded-2xl flex items-center justify-center mr-6 shadow-lg">
                                <i data-lucide="map-pin" class="w-8 h-8 text-white"></i>
                            </div>
                            <div>
                                <h2 class="text-2xl font-bold text-white mb-2">🏙️ Dhaka City Emergency Contacts</h2>
                                <p class="text-lg text-white/70">Specialized services for Dhaka metropolitan area</p>
                            </div>
                        </div>

                        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                            <!-- Dhaka Metropolitan Police -->
                            <div class="resource-card animate-slide-in" style="animation-delay: 0.4s;">
                                <div class="flex items-center justify-between mb-4">
                                    <h3 class="text-lg font-bold text-white">DMP Control Room</h3>
                                    <span class="category-badge category-police">Police</span>
                                </div>
                                <p class="text-sm text-white/70 mb-4">Dhaka Metropolitan Police emergency control</p>
                                <div class="flex items-center space-x-2">
                                    <a href="tel:02-9555555" class="btn-liquid" aria-label="Call DMP Control Room 02-9555555">
                                        <i data-lucide="phone" class="w-4 h-4 mr-2"></i>
                                        02-9555555
                                    </a>
                                    <a href="https://www.dmp.gov.bd/" target="_blank" rel="noopener noreferrer" class="link-simple">Website</a>
                                </div>
                            </div>

                            <!-- Dhaka Fire Service -->
                            <div class="resource-card animate-slide-in" style="animation-delay: 0.5s;">
                                <div class="flex items-center justify-between mb-4">
                                    <h3 class="text-lg font-bold text-white">Dhaka Fire Service</h3>
                                    <span class="category-badge category-emergency">Emergency</span>
                                </div>
                                <p class="text-sm text-white/70 mb-4">Fire and rescue services in Dhaka</p>
                                <div class="flex items-center space-x-2">
                                    <a href="tel:02-9556666" class="btn-liquid" aria-label="Call Dhaka Fire Service 02-9556666">
                                        <i data-lucide="phone" class="w-4 h-4 mr-2"></i>
                                        02-9556666
                                    </a>
                                    <a href="https://fireservice.gov.bd/" target="_blank" rel="noopener noreferrer" class="link-simple">Website</a>
                                </div>
                            </div>

                            <!-- Dhaka Medical College -->
                            <div class="resource-card animate-slide-in" style="animation-delay: 0.6s;">
                                <div class="flex items-center justify-between mb-4">
                                    <h3 class="text-lg font-bold text-white">Dhaka Medical College</h3>
                                    <span class="category-badge category-health">Health</span>
                                </div>
                                <p class="text-sm text-white/70 mb-4">Emergency medical services</p>
                                <div class="flex items-center space-x-2">
                                    <a href="tel:02-9557777" class="btn-liquid" aria-label="Call Dhaka Medical College 02-9557777">
                                        <i data-lucide="phone" class="w-4 h-4 mr-2"></i>
                                        02-9557777
                                    </a>
                                    <a href="https://dmc.gov.bd/" target="_blank" rel="noopener noreferrer" class="link-simple">Website</a>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </section>

            <!-- Legal Support & Advocacy -->
            <section class="mb-8 animate-fade-in-up" style="animation-delay: 0.4s;">
                <div class="liquid-glass rounded-3xl">
                    <div class="p-8">
                        <div class="flex items-center mb-6">
                            <div class="w-16 h-16 bg-gradient-to-r from-purple-500 to-purple-600 rounded-2xl flex items-center justify-center mr-6 shadow-lg">
                                <i data-lucide="scale" class="w-8 h-8 text-white"></i>
                            </div>
                            <div>
                                <h2 class="text-2xl font-bold text-white mb-2">⚖️ Legal Support & Advocacy</h2>
                                <p class="text-lg text-white/70">Legal assistance and rights protection services</p>
                            </div>
                        </div>

                        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                            <!-- Bangladesh Legal Aid -->
                            <div class="resource-card animate-slide-in" style="animation-delay: 0.5s;">
                                <div class="flex items-center justify-between mb-4">
                                    <h3 class="text-lg font-bold text-white">Legal Aid Services</h3>
                                    <span class="category-badge category-legal">Legal</span>
                                </div>
                                <p class="text-sm text-white/70 mb-4">Free legal assistance for vulnerable groups</p>
                                <div class="flex items-center space-x-2">
                                    <a href="tel:02-9558888" class="btn-liquid" aria-label="Call Legal Aid Services 02-9558888">
                                        <i data-lucide="phone" class="w-4 h-4 mr-2"></i>
                                        02-9558888
                                    </a>
                                    <a href="https://nlas.gov.bd" target="_blank" rel="noopener noreferrer" class="btn-liquid" style="background: linear-gradient(135deg, rgba(255, 255, 255, 0.1), rgba(255, 255, 255, 0.05)); border: 1px solid rgba(255, 255, 255, 0.2);">
                                        <i data-lucide="external-link" class="w-4 h-4 mr-2"></i>
                                        Website
                                    </a>
                                </div>
                            </div>

                            <!-- Human Rights Commission -->
                            <div class="resource-card animate-slide-in" style="animation-delay: 0.6s;">
                                <div class="flex items-center justify-between mb-4">
                                    <h3 class="text-lg font-bold text-white">Human Rights Commission</h3>
                                    <span class="category-badge category-legal">Legal</span>
                                </div>
                                <p class="text-sm text-white/70 mb-4">Report human rights violations</p>
                                <div class="flex items-center space-x-2">
                                    <a href="tel:02-9559999" class="btn-liquid" aria-label="Call Human Rights Commission 02-9559999">
                                        <i data-lucide="phone" class="w-4 h-4 mr-2"></i>
                                        02-9559999
                                    </a>
                                    <a href="https://nhrc.org.bd" target="_blank" rel="noopener noreferrer" class="btn-liquid" style="background: linear-gradient(135deg, rgba(255, 255, 255, 0.1), rgba(255, 255, 255, 0.05)); border: 1px solid rgba(255, 255, 255, 0.2);">
                                        <i data-lucide="external-link" class="w-4 h-4 mr-2"></i>
                                        Website
                                    </a>
                                </div>
                            </div>

                            <!-- Women's Rights Organizations -->
                            <div class="resource-card animate-slide-in" style="animation-delay: 0.7s;">
                                <div class="flex items-center justify-between mb-4">
                                    <h3 class="text-lg font-bold text-white">Women's Rights</h3>
                                    <span class="category-badge category-support">Support</span>
                                </div>
                                <p class="text-sm text-white/70 mb-4">Women's rights advocacy and support</p>
                                <div class="flex items-center space-x-2">
                                    <a href="tel:02-9550000" class="btn-liquid" aria-label="Call Women's Rights 02-9550000">
                                        <i data-lucide="phone" class="w-4 h-4 mr-2"></i>
                                        02-9550000
                                    </a>
                                    <a href="https://womenrights.org.bd" target="_blank" rel="noopener noreferrer" class="btn-liquid" style="background: linear-gradient(135deg, rgba(255, 255, 255, 0.1), rgba(255, 255, 255, 0.05)); border: 1px solid rgba(255, 255, 255, 0.2);">
                                        <i data-lucide="external-link" class="w-4 h-4 mr-2"></i>
                                        Website
                                    </a>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </section>

            <!-- Mental Health & Support -->
            <section class="mb-8 animate-fade-in-up" style="animation-delay: 0.5s;">
                <div class="liquid-glass rounded-3xl">
                    <div class="p-8">
                        <div class="flex items-center mb-6">
                            <div class="w-16 h-16 bg-gradient-to-r from-green-500 to-green-600 rounded-2xl flex items-center justify-center mr-6 shadow-lg">
                                <i data-lucide="heart" class="w-8 h-8 text-white"></i>
                            </div>
                            <div>
                                <h2 class="text-2xl font-bold text-white mb-2">💚 Mental Health & Support</h2>
                                <p class="text-lg text-white/70">Counseling and mental health support services</p>
                            </div>
                        </div>

                        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                            <!-- National Mental Health -->
                            <div class="resource-card animate-slide-in" style="animation-delay: 0.6s;">
                                <div class="flex items-center justify-between mb-4">
                                    <h3 class="text-lg font-bold text-white">Mental Health Helpline</h3>
                                    <span class="category-badge category-health">Health</span>
                                </div>
                                <p class="text-sm text-white/70 mb-4">24/7 mental health crisis support</p>
                                <div class="flex items-center space-x-2">
                                    <a href="tel:16263" class="btn-liquid" aria-label="Call Mental Health Helpline 16263">
                                        <i data-lucide="phone" class="w-4 h-4 mr-2"></i>
                                        16263
                                    </a>
                                    <span class="text-xs text-white/50">24/7 Available</span>
                                    <a href="https://dghs.gov.bd/" target="_blank" rel="noopener noreferrer" class="link-simple">DGHS</a>
                                </div>
                            </div>

                            <!-- Crisis Counseling -->
                            <div class="resource-card animate-slide-in" style="animation-delay: 0.7s;">
                                <div class="flex items-center justify-between mb-4">
                                    <h3 class="text-lg font-bold text-white">Crisis Counseling</h3>
                                    <span class="category-badge category-support">Support</span>
                                </div>
                                <p class="text-sm text-white/70 mb-4">Professional crisis intervention services</p>
                                <div class="flex items-center space-x-2">
                                    <a href="tel:02-9551111" class="btn-liquid" aria-label="Call Crisis Counseling 02-9551111">
                                        <i data-lucide="phone" class="w-4 h-4 mr-2"></i>
                                        02-9551111
                                    </a>
                                </div>
                            </div>

                            <!-- Trauma Support -->
                            <div class="resource-card animate-slide-in" style="animation-delay: 0.8s;">
                                <div class="flex items-center justify-between mb-4">
                                    <h3 class="text-lg font-bold text-white">Trauma Support</h3>
                                    <span class="category-badge category-support">Support</span>
                                </div>
                                <p class="text-sm text-white/70 mb-4">Specialized trauma counseling services</p>
                                <div class="flex items-center space-x-2">
                                    <a href="tel:02-9552222" class="btn-liquid" aria-label="Call Trauma Support 02-9552222">
                                        <i data-lucide="phone" class="w-4 h-4 mr-2"></i>
                                        02-9552222
                                    </a>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </section>

                        <!-- Safety Tips & Guidelines -->
            <section class="mb-8 animate-fade-in-up" style="animation-delay: 0.6s;">
                <div class="liquid-glass rounded-3xl">
                    <div class="p-8">
                        <div class="flex items-center mb-8">
                            <div class="w-16 h-16 bg-gradient-to-r from-teal-500 to-teal-600 rounded-2xl flex items-center justify-center mr-6 shadow-lg">
                                <i data-lucide="shield-check" class="w-8 h-8 text-white"></i>
                            </div>
                            <div>
                                <h2 class="text-2xl font-bold text-white mb-2">🛡️ Safety Tips & Guidelines</h2>
                                <p class="text-lg text-white/70">Essential safety practices to keep you and your loved ones protected</p>
                            </div>
                        </div>

                        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                            <!-- Keep Emergency Numbers Handy -->
                            <div class="resource-card animate-slide-in" style="animation-delay: 0.7s;">
                                <div class="flex items-center justify-between mb-4">
                                    <div class="w-12 h-12 bg-gradient-to-r from-red-500 to-red-600 rounded-xl flex items-center justify-center shadow-lg">
                                        <i data-lucide="phone" class="w-6 h-6 text-white"></i>
                                    </div>
                                    <span class="category-badge category-emergency">Essential</span>
                                </div>
                                <h3 class="text-lg font-bold text-white mb-3">Keep Emergency Numbers Handy</h3>
                                <p class="text-sm text-white/70 mb-4">Save important helpline numbers in your phone for quick access during emergencies. Program them as speed dial contacts.</p>
                                <div class="flex items-center space-x-2">
                                    <span class="text-xs text-white/50 bg-white/10 px-2 py-1 rounded-full">999 - Emergency</span>
                                    <span class="text-xs text-white/50 bg-white/10 px-2 py-1 rounded-full">10921 - Women</span>
                                </div>
                            </div>

                            <!-- Stay Connected -->
                            <div class="resource-card animate-slide-in" style="animation-delay: 0.8s;">
                                <div class="flex items-center justify-between mb-4">
                                    <div class="w-12 h-12 bg-gradient-to-r from-blue-500 to-blue-600 rounded-xl flex items-center justify-center shadow-lg">
                                        <i data-lucide="users" class="w-6 h-6 text-white"></i>
                                    </div>
                                    <span class="category-badge category-support">Support</span>
                                </div>
                                <h3 class="text-lg font-bold text-white mb-3">Stay Connected</h3>
                                <p class="text-sm text-white/70 mb-4">Let trusted friends or family know where you are and when you'll be back. Share your location when traveling alone.</p>
                                <div class="flex items-center space-x-2">
                                    <span class="text-xs text-white/50 bg-white/10 px-2 py-1 rounded-full">Location Sharing</span>
                                    <span class="text-xs text-white/50 bg-white/10 px-2 py-1 rounded-full">Check-ins</span>
                                </div>
                            </div>

                            <!-- Know Safe Spaces -->
                            <div class="resource-card animate-slide-in" style="animation-delay: 0.9s;">
                                <div class="flex items-center justify-between mb-4">
                                    <div class="w-12 h-12 bg-gradient-to-r from-green-500 to-green-600 rounded-xl flex items-center justify-center shadow-lg">
                                        <i data-lucide="map-pin" class="w-6 h-6 text-white"></i>
                                    </div>
                                    <span class="category-badge category-support">Awareness</span>
                                </div>
                                <h3 class="text-lg font-bold text-white mb-3">Know Safe Spaces</h3>
                                <p class="text-sm text-white/70 mb-4">Identify safe locations in your area like police stations, hospitals, and community centers. Plan escape routes.</p>
                                <div class="flex items-center space-x-2">
                                    <span class="text-xs text-white/50 bg-white/10 px-2 py-1 rounded-full">Police Stations</span>
                                    <span class="text-xs text-white/50 bg-white/10 px-2 py-1 rounded-full">Hospitals</span>
                                </div>
                            </div>

                            <!-- Document Everything -->
                            <div class="resource-card animate-slide-in" style="animation-delay: 1.0s;">
                                <div class="flex items-center justify-between mb-4">
                                    <div class="w-12 h-12 bg-gradient-to-r from-purple-500 to-purple-600 rounded-xl flex items-center justify-center shadow-lg">
                                        <i data-lucide="file-text" class="w-6 h-6 text-white"></i>
                                    </div>
                                    <span class="category-badge category-legal">Legal</span>
                                </div>
                                <h3 class="text-lg font-bold text-white mb-3">Document Everything</h3>
                                <p class="text-sm text-white/70 mb-4">Keep records of incidents, including dates, times, and any evidence. Take photos when safe to do so.</p>
                                <div class="flex items-center space-x-2">
                                    <span class="text-xs text-white/50 bg-white/10 px-2 py-1 rounded-full">Photos</span>
                                    <span class="text-xs text-white/50 bg-white/10 px-2 py-1 rounded-full">Records</span>
                                </div>
                            </div>

                            <!-- Seek Support -->
                            <div class="resource-card animate-slide-in" style="animation-delay: 1.1s;">
                                <div class="flex items-center justify-between mb-4">
                                    <div class="w-12 h-12 bg-gradient-to-r from-pink-500 to-pink-600 rounded-xl flex items-center justify-center shadow-lg">
                                        <i data-lucide="heart" class="w-6 h-6 text-white"></i>
                                    </div>
                                    <span class="category-badge category-support">Support</span>
                                </div>
                                <h3 class="text-lg font-bold text-white mb-3">Seek Support</h3>
                                <p class="text-sm text-white/70 mb-4">Don't hesitate to reach out to support groups or counseling services. You don't have to face challenges alone.</p>
                                <div class="flex items-center space-x-2">
                                    <span class="text-xs text-white/50 bg-white/10 px-2 py-1 rounded-full">Counseling</span>
                                    <span class="text-xs text-white/50 bg-white/10 px-2 py-1 rounded-full">Groups</span>
                                </div>
                            </div>

                            <!-- Trust Your Instincts -->
                            <div class="resource-card animate-slide-in" style="animation-delay: 1.2s;">
                                <div class="flex items-center justify-between mb-4">
                                    <div class="w-12 h-12 bg-gradient-to-r from-orange-500 to-orange-600 rounded-xl flex items-center justify-center shadow-lg">
                                        <i data-lucide="alert-triangle" class="w-6 h-6 text-white"></i>
                                    </div>
                                    <span class="category-badge category-emergency">Awareness</span>
                                </div>
                                <h3 class="text-lg font-bold text-white mb-3">Trust Your Instincts</h3>
                                <p class="text-sm text-white/70 mb-4">If something feels wrong, it probably is. Don't ignore your gut feelings and remove yourself from unsafe situations.</p>
                                <div class="flex items-center space-x-2">
                                    <span class="text-xs text-white/50 bg-white/10 px-2 py-1 rounded-full">Intuition</span>
                                    <span class="text-xs text-white/50 bg-white/10 px-2 py-1 rounded-full">Awareness</span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </section>

            <!-- Important Information -->
            <section class="mb-8 animate-fade-in-up" style="animation-delay: 0.7s;">
                <div class="liquid-glass rounded-3xl">
                    <div class="p-8">
                        <div class="flex items-center mb-6">
                            <div class="w-16 h-16 bg-gradient-to-r from-yellow-500 to-yellow-600 rounded-2xl flex items-center justify-center mr-6 shadow-lg">
                                <i data-lucide="info" class="w-8 h-8 text-white"></i>
                            </div>
                            <div>
                                <h2 class="text-2xl font-bold text-white mb-2">ℹ️ Important Information</h2>
                                <p class="text-lg text-white/70">Essential safety tips and guidelines for Bangladesh</p>
                            </div>
                        </div>

                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                            <div class="space-y-4">
                                <div class="bg-yellow-500/10 border border-yellow-500/20 rounded-xl p-4">
                                    <h3 class="text-lg font-bold text-yellow-300 mb-2">Emergency Response Time</h3>
                                    <p class="text-sm text-white/70">Emergency services typically respond within 5-15 minutes in urban areas. In rural areas, response time may be longer.</p>
                                </div>

                                <div class="bg-blue-500/10 border border-blue-500/20 rounded-xl p-4">
                                    <h3 class="text-lg font-bold text-blue-300 mb-2">Language Support</h3>
                                    <p class="text-sm text-white/70">Most emergency services support both Bengali and English. For rural areas, local language support is available.</p>
                                </div>
                            </div>

                            <div class="space-y-4">
                                <div class="bg-green-500/10 border border-green-500/20 rounded-xl p-4">
                                    <h3 class="text-lg font-bold text-green-300 mb-2">Documentation</h3>
                                    <p class="text-sm text-white/70">Keep important documents (ID, medical records) readily available. Take photos of incidents when safe to do so.</p>
                                </div>

                                <div class="bg-purple-500/10 border border-purple-500/20 rounded-xl p-4">
                                    <h3 class="text-lg font-bold text-purple-300 mb-2">Follow-up</h3>
                                    <p class="text-sm text-white/70">Always follow up on your reports. Keep records of case numbers and officer names for reference.</p>
                                </div>
                            </div>
                        </div>
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
    </script>
</body>
</html>