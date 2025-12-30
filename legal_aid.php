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

// Get filters
$filters = [
    'city' => $_GET['city'] ?? '',
    'district' => $_GET['district'] ?? '',
    'specialization' => $_GET['specialization'] ?? '',
    'fee_structure' => $_GET['fee_structure'] ?? '',
    'language' => $_GET['language'] ?? '',
    'is_verified' => $_GET['is_verified'] ?? '',
    'limit' => $_GET['limit'] ?? 50
];

// Search functionality
$searchTerm = $_GET['search'] ?? '';
$providers = [];

if (!empty($searchTerm)) {
    $providers = $models->searchLegalAidProviders($searchTerm);
} else {
    $providers = $models->getLegalAidProviders($filters);
}

// Get specializations for filter dropdown
$specializations = $models->getLegalAidProviderSpecializations();

// Get unique cities and districts from active providers
$allProviders = $models->getLegalAidProviders(['limit' => 1000]);
$cities = array_unique(array_filter(array_column($allProviders, 'city')));
$districts = array_unique(array_filter(array_column($allProviders, 'district')));
sort($cities);
sort($districts);

// Calculate statistics
$totalProviders = count($providers);
$verifiedProviders = count(array_filter($providers, fn($p) => $p['is_verified']));
$freeProviders = count(array_filter($providers, fn($p) => $p['fee_structure'] === 'free'));
$avgRating = $totalProviders > 0 ? array_sum(array_column($providers, 'rating')) / $totalProviders : 0;

// Message handling
$message = '';
$error = '';
if (isset($_GET['success'])) {
    $message = 'Consultation request submitted successfully! You will be contacted within 48 hours.';
}
if (isset($_GET['error'])) {
    $error = 'Failed to submit consultation request. Please try again.';
}
?>
<!DOCTYPE html>
<html lang="<?= $lang ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Legal Aid & Consultation - SafeSpace Portal</title>

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

    <style>
        /* Liquid Glass Theme */
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

        .provider-card {
            background: linear-gradient(135deg, rgba(255, 255, 255, 0.08), rgba(255, 255, 255, 0.03));
            backdrop-filter: blur(15px);
            border: 1px solid rgba(255, 255, 255, 0.1);
            border-radius: 20px;
            padding: 24px;
            transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
            position: relative;
            overflow: hidden;
        }

        .provider-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.1), transparent);
            transition: left 0.6s ease;
        }

        .provider-card:hover::before {
            left: 100%;
        }

        .provider-card:hover {
            background: linear-gradient(135deg, rgba(255, 255, 255, 0.12), rgba(255, 255, 255, 0.06));
            border-color: rgba(255, 255, 255, 0.2);
            transform: translateY(-4px);
            box-shadow: 0 16px 48px rgba(0, 0, 0, 0.2);
        }

        .specialization-badge {
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 500;
            backdrop-filter: blur(10px);
            display: inline-block;
            margin: 2px;
        }

        .rating-star {
            color: #fbbf24;
        }

        .rating-star.empty {
            color: rgba(255, 255, 255, 0.2);
        }
    </style>
</head>
<body class="bg-gradient-to-br from-slate-900 via-purple-900 to-slate-900 min-h-screen">
    <!-- Header -->
    <header class="fixed top-0 left-0 right-0 z-50 bg-white/10 backdrop-blur-xl border-b border-white/20">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="flex items-center justify-between h-16">
                <div class="flex items-center space-x-4">
                    <a href="dashboard.php" class="flex items-center space-x-2">
                        <div class="w-8 h-8 bg-gradient-to-r from-primary-500 to-secondary-500 rounded-lg flex items-center justify-center">
                            <i data-lucide="shield" class="w-5 h-5 text-white"></i>
                        </div>
                        <span class="text-xl font-bold text-white">SafeSpace</span>
                    </a>
                </div>
                <nav class="hidden md:flex items-center space-x-6">
                    <a href="dashboard.php" class="text-white/70 hover:text-white transition-colors duration-200">Dashboard</a>
                    <a href="my_reports.php" class="text-white/70 hover:text-white transition-colors duration-200">My Reports</a>
                    <a href="dispute_center.php" class="text-white/70 hover:text-white transition-colors duration-200">Disputes</a>
                    <a href="legal_aid.php" class="text-white font-medium">Legal Aid</a>
                    <a href="safety_resources.php" class="text-white/70 hover:text-white transition-colors duration-200">Resources</a>
                </nav>
                <div class="flex items-center space-x-4">
                    <a href="dashboard.php" class="btn btn-ghost">
                        <i data-lucide="arrow-left" class="w-4 h-4"></i>
                        Back
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
                    <div class="w-20 h-20 bg-gradient-to-r from-purple-500 via-indigo-500 to-blue-500 rounded-3xl flex items-center justify-center mx-auto mb-6 shadow-2xl ring-4 ring-purple-500/20">
                        <i data-lucide="scale" class="w-10 h-10 text-white drop-shadow-lg"></i>
                    </div>
                    <h1 class="heading-1 mb-4 text-white">Legal Aid & Consultation</h1>
                    <p class="body-large max-w-2xl mx-auto text-white/80 leading-relaxed">
                        Find verified legal aid providers, book consultations, and access legal resources. Connect with experienced lawyers and legal organizations.
                    </p>
                </div>
            </section>

            <!-- Messages -->
            <?php if ($message): ?>
                <div class="mb-6 card card-glass border-l-4 border-green-500">
                    <div class="card-body">
                        <div class="flex items-center">
                            <i data-lucide="check-circle" class="w-5 h-5 text-green-500 mr-3"></i>
                            <p class="text-green-300"><?= htmlspecialchars($message) ?></p>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <?php if ($error): ?>
                <div class="mb-6 card card-glass border-l-4 border-red-500">
                    <div class="card-body">
                        <div class="flex items-center">
                            <i data-lucide="alert-circle" class="w-5 h-5 text-red-500 mr-3"></i>
                            <p class="text-red-300"><?= htmlspecialchars($error) ?></p>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Statistics -->
            <section class="mb-8">
                <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
                    <div class="card card-glass p-5">
                        <div class="flex items-center justify-between">
                            <div>
                                <p class="text-white/60 text-sm mb-1">Total Providers</p>
                                <p class="heading-2 text-white"><?= $totalProviders ?></p>
                            </div>
                            <div class="w-12 h-12 bg-gradient-to-r from-purple-500 to-indigo-500 rounded-lg flex items-center justify-center">
                                <i data-lucide="scale" class="w-6 h-6 text-white"></i>
                            </div>
                        </div>
                    </div>
                    <div class="card card-glass p-5">
                        <div class="flex items-center justify-between">
                            <div>
                                <p class="text-white/60 text-sm mb-1">Verified</p>
                                <p class="heading-2 text-white"><?= $verifiedProviders ?></p>
                            </div>
                            <div class="w-12 h-12 bg-gradient-to-r from-blue-500 to-cyan-500 rounded-lg flex items-center justify-center">
                                <i data-lucide="badge-check" class="w-6 h-6 text-white"></i>
                            </div>
                        </div>
                    </div>
                    <div class="card card-glass p-5">
                        <div class="flex items-center justify-between">
                            <div>
                                <p class="text-white/60 text-sm mb-1">Free Services</p>
                                <p class="heading-2 text-white"><?= $freeProviders ?></p>
                            </div>
                            <div class="w-12 h-12 bg-gradient-to-r from-green-500 to-emerald-500 rounded-lg flex items-center justify-center">
                                <i data-lucide="gift" class="w-6 h-6 text-white"></i>
                            </div>
                        </div>
                    </div>
                    <div class="card card-glass p-5">
                        <div class="flex items-center justify-between">
                            <div>
                                <p class="text-white/60 text-sm mb-1">Avg Rating</p>
                                <p class="heading-2 text-white"><?= number_format($avgRating, 1) ?></p>
                            </div>
                            <div class="w-12 h-12 bg-gradient-to-r from-yellow-500 to-orange-500 rounded-lg flex items-center justify-center">
                                <i data-lucide="star" class="w-6 h-6 text-white"></i>
                            </div>
                        </div>
                    </div>
                </div>
            </section>

            <!-- Quick Links -->
            <section class="mb-8 grid grid-cols-1 md:grid-cols-3 gap-4">
                <a href="book_consultation.php" class="card card-glass card-hover text-center p-6">
                    <div class="w-12 h-12 bg-gradient-to-r from-blue-500 to-purple-600 rounded-xl flex items-center justify-center mx-auto mb-4">
                        <i data-lucide="calendar-plus" class="w-6 h-6 text-white"></i>
                    </div>
                    <h3 class="heading-4 text-white mb-2">Book Consultation</h3>
                    <p class="text-sm text-white/60">Schedule a legal consultation</p>
                </a>
                <a href="my_consultations.php" class="card card-glass card-hover text-center p-6">
                    <div class="w-12 h-12 bg-gradient-to-r from-green-500 to-emerald-600 rounded-xl flex items-center justify-center mx-auto mb-4">
                        <i data-lucide="list-check" class="w-6 h-6 text-white"></i>
                    </div>
                    <h3 class="heading-4 text-white mb-2">My Consultations</h3>
                    <p class="text-sm text-white/60">View your consultation history</p>
                </a>
                <a href="legal_documents.php" class="card card-glass card-hover text-center p-6">
                    <div class="w-12 h-12 bg-gradient-to-r from-orange-500 to-red-600 rounded-xl flex items-center justify-center mx-auto mb-4">
                        <i data-lucide="file-text" class="w-6 h-6 text-white"></i>
                    </div>
                    <h3 class="heading-4 text-white mb-2">Legal Documents</h3>
                    <p class="text-sm text-white/60">Access forms and guides</p>
                </a>
            </section>

            <!-- Search and Filters -->
            <section class="mb-8">
                <div class="card card-glass p-6">
                    <form method="GET" action="legal_aid.php" class="space-y-4">
                        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
                            <!-- Search -->
                            <div class="lg:col-span-4">
                                <label class="form-label text-white mb-2">Search</label>
                                <input type="text" name="search" value="<?= htmlspecialchars($searchTerm) ?>"
                                       placeholder="Search by name, specialization, or location..."
                                       class="form-input w-full">
                            </div>

                            <!-- City -->
                            <div>
                                <label class="form-label text-white mb-2">City</label>
                                <select name="city" class="form-input w-full">
                                    <option value="">All Cities</option>
                                    <?php foreach ($cities as $city): ?>
                                        <option value="<?= htmlspecialchars($city) ?>" <?= $filters['city'] === $city ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($city) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <!-- District -->
                            <div>
                                <label class="form-label text-white mb-2">District</label>
                                <select name="district" class="form-input w-full">
                                    <option value="">All Districts</option>
                                    <?php foreach ($districts as $district): ?>
                                        <option value="<?= htmlspecialchars($district) ?>" <?= $filters['district'] === $district ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($district) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <!-- Specialization -->
                            <div>
                                <label class="form-label text-white mb-2">Specialization</label>
                                <select name="specialization" class="form-input w-full">
                                    <option value="">All Specializations</option>
                                    <?php foreach ($specializations as $spec): ?>
                                        <option value="<?= htmlspecialchars($spec) ?>" <?= $filters['specialization'] === $spec ? 'selected' : '' ?>>
                                            <?= ucfirst(str_replace('_', ' ', htmlspecialchars($spec))) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <!-- Fee Structure -->
                            <div>
                                <label class="form-label text-white mb-2">Fee Structure</label>
                                <select name="fee_structure" class="form-input w-full">
                                    <option value="">All</option>
                                    <option value="free" <?= $filters['fee_structure'] === 'free' ? 'selected' : '' ?>>Free</option>
                                    <option value="low_cost" <?= $filters['fee_structure'] === 'low_cost' ? 'selected' : '' ?>>Low Cost</option>
                                    <option value="standard" <?= $filters['fee_structure'] === 'standard' ? 'selected' : '' ?>>Standard</option>
                                    <option value="pro_bono" <?= $filters['fee_structure'] === 'pro_bono' ? 'selected' : '' ?>>Pro Bono</option>
                                </select>
                            </div>

                            <!-- Language -->
                            <div>
                                <label class="form-label text-white mb-2">Language</label>
                                <select name="language" class="form-input w-full">
                                    <option value="">All Languages</option>
                                    <option value="bn" <?= $filters['language'] === 'bn' ? 'selected' : '' ?>>Bengali</option>
                                    <option value="en" <?= $filters['language'] === 'en' ? 'selected' : '' ?>>English</option>
                                </select>
                            </div>

                            <!-- Verified Only -->
                            <div>
                                <label class="form-label text-white mb-2">Verified Only</label>
                                <select name="is_verified" class="form-input w-full">
                                    <option value="">All</option>
                                    <option value="1" <?= $filters['is_verified'] === '1' ? 'selected' : '' ?>>Yes</option>
                                    <option value="0" <?= $filters['is_verified'] === '0' ? 'selected' : '' ?>>No</option>
                                </select>
                            </div>
                        </div>

                        <div class="flex items-center space-x-4">
                            <button type="submit" class="btn btn-primary">
                                <i data-lucide="search" class="w-4 h-4 mr-2"></i>
                                Search
                            </button>
                            <a href="legal_aid.php" class="btn btn-outline">
                                <i data-lucide="refresh-cw" class="w-4 h-4 mr-2"></i>
                                Reset
                            </a>
                        </div>
                    </form>
                </div>
            </section>

            <!-- Providers List -->
            <section>
                <div class="mb-6 flex items-center justify-between">
                    <div>
                        <h2 class="heading-2 text-white">Legal Aid Providers (<?= count($providers) ?>)</h2>
                        <?php if (!empty($searchTerm) || !empty(array_filter($filters))): ?>
                            <p class="text-white/60 text-sm mt-1">Filtered results</p>
                        <?php endif; ?>
                    </div>
                    <?php if (!empty($providers)): ?>
                        <div class="flex items-center space-x-2">
                            <span class="text-sm text-white/60">Sort:</span>
                            <select class="form-input text-sm" onchange="window.location.href='legal_aid.php?sort=' + this.value">
                                <option value="rating">Highest Rated</option>
                                <option value="reviews">Most Reviews</option>
                                <option value="verified">Verified First</option>
                                <option value="free">Free Services First</option>
                            </select>
                        </div>
                    <?php endif; ?>
                </div>

                <?php if (empty($providers)): ?>
                    <div class="card card-glass text-center p-12">
                        <div class="w-20 h-20 bg-gradient-to-r from-gray-500 to-gray-600 rounded-full flex items-center justify-center mx-auto mb-6">
                            <i data-lucide="search-x" class="w-10 h-10 text-white"></i>
                        </div>
                        <h3 class="heading-3 text-white mb-3">No Providers Found</h3>
                        <p class="text-white/60 mb-6">Try adjusting your search filters or search terms.</p>
                        <a href="legal_aid.php" class="btn btn-primary">View All Providers</a>
                    </div>
                <?php else: ?>
                    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                        <?php foreach ($providers as $index => $provider): ?>
                            <div class="provider-card animate-slide-in" style="animation-delay: <?= $index * 0.1 ?>s;">
                                <div class="flex items-start justify-between mb-4">
                                    <div class="flex-1">
                                        <div class="flex items-center space-x-2 mb-2">
                                            <h3 class="heading-4 text-white"><?= htmlspecialchars($provider['organization_name']) ?></h3>
                                            <?php if ($provider['is_verified']): ?>
                                                <i data-lucide="badge-check" class="w-5 h-5 text-blue-400" title="Verified"></i>
                                            <?php endif; ?>
                                        </div>
                                        <?php if ($provider['contact_person']): ?>
                                            <p class="text-sm text-white/60"><?= htmlspecialchars($provider['contact_person']) ?></p>
                                        <?php endif; ?>
                                    </div>
                                </div>

                                <!-- Rating -->
                                <div class="flex items-center space-x-2 mb-4">
                                    <div class="flex items-center">
                                        <?php for ($i = 1; $i <= 5; $i++): ?>
                                            <i data-lucide="star" class="w-4 h-4 rating-star <?= $i <= round($provider['rating']) ? '' : 'empty' ?>" fill="<?= $i <= round($provider['rating']) ? 'currentColor' : 'none' ?>"></i>
                                        <?php endfor; ?>
                                    </div>
                                    <span class="text-sm text-white/70"><?= number_format($provider['rating'], 1) ?></span>
                                    <span class="text-xs text-white/50">(<?= $provider['review_count'] ?> reviews)</span>
                                </div>

                                <!-- Specializations -->
                                <div class="mb-4">
                                    <?php
                                    $specs = explode(',', $provider['specialization']);
                                    foreach (array_slice($specs, 0, 3) as $spec):
                                        $spec = trim($spec);
                                        $colorClass = 'bg-purple-500/20 text-purple-300 border-purple-500/30';
                                    ?>
                                        <span class="specialization-badge <?= $colorClass ?> border">
                                            <?= ucfirst(str_replace('_', ' ', $spec)) ?>
                                        </span>
                                    <?php endforeach; ?>
                                    <?php if (count($specs) > 3): ?>
                                        <span class="text-xs text-white/50">+<?= count($specs) - 3 ?> more</span>
                                    <?php endif; ?>
                                </div>

                                <!-- Location -->
                                <div class="mb-4 space-y-1">
                                    <div class="flex items-center text-sm text-white/70">
                                        <i data-lucide="map-pin" class="w-4 h-4 mr-2 text-blue-400"></i>
                                        <?= htmlspecialchars($provider['city'] ?? 'N/A') ?><?= $provider['district'] ? ', ' . htmlspecialchars($provider['district']) : '' ?>
                                    </div>
                                    <div class="flex items-center text-sm text-white/70">
                                        <i data-lucide="phone" class="w-4 h-4 mr-2 text-green-400"></i>
                                        <?= htmlspecialchars($provider['phone']) ?>
                                    </div>
                                    <?php if ($provider['email']): ?>
                                        <div class="flex items-center text-sm text-white/70">
                                            <i data-lucide="mail" class="w-4 h-4 mr-2 text-purple-400"></i>
                                            <?= htmlspecialchars($provider['email']) ?>
                                        </div>
                                    <?php endif; ?>
                                </div>

                                <!-- Fee Structure -->
                                <div class="mb-4">
                                    <?php
                                    $feeColors = [
                                        'free' => 'bg-green-500/20 text-green-300 border-green-500/30',
                                        'low_cost' => 'bg-blue-500/20 text-blue-300 border-blue-500/30',
                                        'standard' => 'bg-yellow-500/20 text-yellow-300 border-yellow-500/30',
                                        'pro_bono' => 'bg-purple-500/20 text-purple-300 border-purple-500/30'
                                    ];
                                    $feeColor = $feeColors[$provider['fee_structure']] ?? 'bg-gray-500/20 text-gray-300 border-gray-500/30';
                                    ?>
                                    <span class="specialization-badge <?= $feeColor ?> border">
                                        <?= ucfirst(str_replace('_', ' ', $provider['fee_structure'])) ?>
                                    </span>
                                </div>

                                <!-- Additional Info -->
                                <div class="mb-4 space-y-1 text-xs text-white/60">
                                    <?php if ($provider['cases_handled']): ?>
                                        <p class="flex items-center">
                                            <i data-lucide="briefcase" class="w-3 h-3 mr-1.5"></i>
                                            <?= $provider['cases_handled'] ?> cases handled
                                        </p>
                                    <?php endif; ?>
                                    <?php if ($provider['availability_hours']): ?>
                                        <p class="flex items-center">
                                            <i data-lucide="clock" class="w-3 h-3 mr-1.5"></i>
                                            <?= htmlspecialchars($provider['availability_hours']) ?>
                                        </p>
                                    <?php endif; ?>
                                    <?php if ($provider['is_24_7']): ?>
                                        <p class="flex items-center text-green-400">
                                            <i data-lucide="clock" class="w-3 h-3 mr-1.5"></i>
                                            24/7 Available
                                        </p>
                                    <?php endif; ?>
                                </div>

                                <!-- Actions -->
                                <div class="flex items-center space-x-2 pt-4 border-t border-white/10">
                                    <a href="legal_provider_detail.php?id=<?= $provider['id'] ?>" class="btn btn-outline flex-1 text-center">
                                        <i data-lucide="eye" class="w-4 h-4 mr-2"></i>
                                        View Details
                                    </a>
                                    <a href="book_consultation.php?provider_id=<?= $provider['id'] ?>" class="btn btn-primary flex-1 text-center">
                                        <i data-lucide="calendar-plus" class="w-4 h-4 mr-2"></i>
                                        Book Now
                                    </a>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </section>
        </div>
    </main>

    <script src="https://unpkg.com/lucide@latest/dist/umd/lucide.js"></script>
    <script src="dashboard-enhanced.js"></script>
    <script>
        lucide.createIcons();
    </script>
</body>
</html>

