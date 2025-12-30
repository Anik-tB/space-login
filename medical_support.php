<?php
session_start();
$lang = $_SESSION['lang'] ?? 'en';
$lang_file = $lang === 'bn' ? 'lang_bn.php' : 'lang_en.php';
$L = include($lang_file);

require_once 'includes/Database.php';

$database = new Database();
$models = new SafeSpaceModels($database);

$userId = $_SESSION['user_id'] ?? null;

if (!$userId) {
    header('Location: login.html');
    exit;
}

// Get filters
$filters = [
    'provider_type' => $_GET['provider_type'] ?? '',
    'district' => $_GET['district'] ?? '',
    'city' => $_GET['city'] ?? '',
    'specialization' => $_GET['specialization'] ?? '',
    'language' => $_GET['language'] ?? '',
    'fee_structure' => $_GET['fee_structure'] ?? '',
    'is_24_7' => $_GET['is_24_7'] ?? '',
    'accepts_insurance' => $_GET['accepts_insurance'] ?? '',
    'is_verified' => $_GET['is_verified'] ?? '',
    'limit' => $_GET['limit'] ?? 50
];

// Search functionality
$searchTerm = $_GET['search'] ?? '';
$providers = [];

if (!empty($searchTerm)) {
    $providers = $models->searchMedicalProviders($searchTerm);
} else {
    $providers = $models->getMedicalProviders($filters);
}

// Get specializations for filter dropdown
$specializations = $models->getProviderSpecializations();

// Get unique cities and districts from active providers
$allProviders = $models->getMedicalProviders(['limit' => 1000]);
$cities = array_unique(array_filter(array_column($allProviders, 'city')));
$districts = array_unique(array_filter(array_column($allProviders, 'district')));
sort($cities);
sort($districts);

// Calculate statistics
$totalProviders = count($providers);
$verifiedProviders = count(array_filter($providers, fn($p) => $p['is_verified']));
$freeProviders = count(array_filter($providers, fn($p) => $p['fee_structure'] === 'free'));
$avgRating = $totalProviders > 0 ? array_sum(array_column($providers, 'rating')) / $totalProviders : 0;
$twentyFourSeven = count(array_filter($providers, fn($p) => $p['is_24_7']));

// Message handling
$message = '';
$error = '';
if (isset($_GET['success'])) {
    $message = 'Referral request submitted successfully!';
}
if (isset($_GET['error'])) {
    $error = 'Failed to submit referral request. Please try again.';
}
?>
<!DOCTYPE html>
<html lang="<?= $lang ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Medical & Psychological Support - SafeSpace Portal</title>

    <link rel="icon" type="image/svg+xml" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><text y='.9em' font-size='90'>🛡️</text></svg>">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&family=Poppins:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/lucide/0.263.1/lucide.min.css">
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="design-system.css">
    <link rel="stylesheet" href="dashboard-styles.css">

    <style>
        .liquid-glass {
            background: linear-gradient(135deg, rgba(255, 255, 255, 0.1), rgba(255, 255, 255, 0.05));
            backdrop-filter: blur(20px);
            border: 1px solid rgba(255, 255, 255, 0.1);
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.1);
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
    </style>
</head>
<body class="bg-gradient-to-br from-slate-900 via-purple-900 to-slate-900 min-h-screen">
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
                    <a href="my_referrals.php" class="text-white/70 hover:text-white transition-colors duration-200">My Referrals</a>
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

    <main class="pt-20 pb-8">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <section class="mb-8 animate-fade-in">
                <div class="text-center">
                    <div class="w-20 h-20 bg-gradient-to-r from-red-500 via-pink-500 to-rose-500 rounded-3xl flex items-center justify-center mx-auto mb-6 shadow-2xl ring-4 ring-red-500/20">
                        <i data-lucide="heart-pulse" class="w-10 h-10 text-white drop-shadow-lg"></i>
                    </div>
                    <h1 class="heading-1 mb-4 text-white">Medical & Psychological Support</h1>
                    <p class="body-large max-w-2xl mx-auto text-white/80 leading-relaxed">
                        Find verified medical providers, trauma centers, and mental health professionals. Get the support you need when you need it most.
                    </p>
                </div>
            </section>

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
                <div class="mb-4 card card-glass border-l-4 border-red-500">
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
                <div class="grid grid-cols-2 md:grid-cols-5 gap-4">
                    <div class="card card-glass p-5">
                        <div class="flex items-center justify-between">
                            <div>
                                <p class="text-white/60 text-sm mb-1">Total Providers</p>
                                <p class="heading-2 text-white"><?= $totalProviders ?></p>
                            </div>
                            <div class="w-12 h-12 bg-gradient-to-r from-red-500 to-pink-500 rounded-lg flex items-center justify-center">
                                <i data-lucide="heart-pulse" class="w-6 h-6 text-white"></i>
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
                                <p class="text-white/60 text-sm mb-1">24/7 Available</p>
                                <p class="heading-2 text-white"><?= $twentyFourSeven ?></p>
                            </div>
                            <div class="w-12 h-12 bg-gradient-to-r from-purple-500 to-indigo-500 rounded-lg flex items-center justify-center">
                                <i data-lucide="clock" class="w-6 h-6 text-white"></i>
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
            <section class="mb-8 grid grid-cols-1 md:grid-cols-2 gap-4">
                <a href="book_appointment.php" class="card card-glass card-hover text-center p-6">
                    <div class="w-12 h-12 bg-gradient-to-r from-red-500 to-pink-600 rounded-xl flex items-center justify-center mx-auto mb-4">
                        <i data-lucide="calendar-plus" class="w-6 h-6 text-white"></i>
                    </div>
                    <h3 class="heading-4 text-white mb-2">Book Appointment</h3>
                    <p class="text-sm text-white/60">Request a medical referral</p>
                </a>
                <a href="my_referrals.php" class="card card-glass card-hover text-center p-6">
                    <div class="w-12 h-12 bg-gradient-to-r from-green-500 to-emerald-600 rounded-xl flex items-center justify-center mx-auto mb-4">
                        <i data-lucide="list-check" class="w-6 h-6 text-white"></i>
                    </div>
                    <h3 class="heading-4 text-white mb-2">My Referrals</h3>
                    <p class="text-sm text-white/60">View your referral history</p>
                </a>
            </section>

            <!-- Search and Filters -->
            <section class="mb-8">
                <div class="card card-glass p-6">
                    <form method="GET" class="space-y-4">
                        <div class="flex flex-col md:flex-row gap-4">
                            <div class="flex-1">
                                <input type="text" name="search" value="<?= htmlspecialchars($searchTerm) ?>"
                                       placeholder="Search providers, locations, specializations..."
                                       class="form-input w-full">
                            </div>
                            <button type="submit" class="btn btn-primary">
                                <i data-lucide="search" class="w-4 h-4 mr-2"></i>
                                Search
                            </button>
                        </div>

                        <div class="grid grid-cols-2 md:grid-cols-4 lg:grid-cols-6 gap-4">
                            <div>
                                <label class="form-label text-white text-sm mb-2">Provider Type</label>
                                <select name="provider_type" class="form-input text-sm" onchange="this.form.submit()">
                                    <option value="">All Types</option>
                                    <option value="hospital" <?= $filters['provider_type'] === 'hospital' ? 'selected' : '' ?>>Hospital</option>
                                    <option value="clinic" <?= $filters['provider_type'] === 'clinic' ? 'selected' : '' ?>>Clinic</option>
                                    <option value="counselor" <?= $filters['provider_type'] === 'counselor' ? 'selected' : '' ?>>Counselor</option>
                                    <option value="psychologist" <?= $filters['provider_type'] === 'psychologist' ? 'selected' : '' ?>>Psychologist</option>
                                    <option value="psychiatrist" <?= $filters['provider_type'] === 'psychiatrist' ? 'selected' : '' ?>>Psychiatrist</option>
                                    <option value="trauma_center" <?= $filters['provider_type'] === 'trauma_center' ? 'selected' : '' ?>>Trauma Center</option>
                                    <option value="ngo" <?= $filters['provider_type'] === 'ngo' ? 'selected' : '' ?>>NGO</option>
                                </select>
                            </div>

                            <div>
                                <label class="form-label text-white text-sm mb-2">District</label>
                                <select name="district" class="form-input text-sm" onchange="this.form.submit()">
                                    <option value="">All Districts</option>
                                    <?php foreach ($districts as $district): ?>
                                        <option value="<?= htmlspecialchars($district) ?>" <?= $filters['district'] === $district ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($district) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div>
                                <label class="form-label text-white text-sm mb-2">City</label>
                                <select name="city" class="form-input text-sm" onchange="this.form.submit()">
                                    <option value="">All Cities</option>
                                    <?php foreach ($cities as $city): ?>
                                        <option value="<?= htmlspecialchars($city) ?>" <?= $filters['city'] === $city ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($city) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div>
                                <label class="form-label text-white text-sm mb-2">Fee Structure</label>
                                <select name="fee_structure" class="form-input text-sm" onchange="this.form.submit()">
                                    <option value="">All</option>
                                    <option value="free" <?= $filters['fee_structure'] === 'free' ? 'selected' : '' ?>>Free</option>
                                    <option value="subsidized" <?= $filters['fee_structure'] === 'subsidized' ? 'selected' : '' ?>>Subsidized</option>
                                    <option value="standard" <?= $filters['fee_structure'] === 'standard' ? 'selected' : '' ?>>Standard</option>
                                    <option value="premium" <?= $filters['fee_structure'] === 'premium' ? 'selected' : '' ?>>Premium</option>
                                </select>
                            </div>

                            <div>
                                <label class="form-label text-white text-sm mb-2">24/7 Available</label>
                                <select name="is_24_7" class="form-input text-sm" onchange="this.form.submit()">
                                    <option value="">All</option>
                                    <option value="1" <?= $filters['is_24_7'] === '1' ? 'selected' : '' ?>>Yes</option>
                                    <option value="0" <?= $filters['is_24_7'] === '0' ? 'selected' : '' ?>>No</option>
                                </select>
                            </div>

                            <div>
                                <label class="form-label text-white text-sm mb-2">Accepts Insurance</label>
                                <select name="accepts_insurance" class="form-input text-sm" onchange="this.form.submit()">
                                    <option value="">All</option>
                                    <option value="1" <?= $filters['accepts_insurance'] === '1' ? 'selected' : '' ?>>Yes</option>
                                    <option value="0" <?= $filters['accepts_insurance'] === '0' ? 'selected' : '' ?>>No</option>
                                </select>
                            </div>
                        </div>

                        <?php if (!empty($searchTerm) || !empty(array_filter($filters))): ?>
                            <a href="medical_support.php" class="text-sm text-purple-400 hover:text-purple-300">
                                <i data-lucide="x" class="w-4 h-4 inline mr-1"></i>
                                Clear Filters
                            </a>
                        <?php endif; ?>
                    </form>
                </div>
            </section>

            <!-- Providers List -->
            <section>
                <div class="mb-6 flex items-center justify-between">
                    <div>
                        <h2 class="heading-2 text-white">Medical Providers (<?= count($providers) ?>)</h2>
                        <?php if (!empty($searchTerm) || !empty(array_filter($filters))): ?>
                            <p class="text-white/60 text-sm mt-1">Filtered results</p>
                        <?php endif; ?>
                    </div>
                    <?php if (!empty($providers)): ?>
                        <div class="flex items-center space-x-2">
                            <span class="text-sm text-white/60">Sort:</span>
                            <select class="form-input text-sm" onchange="window.location.href='medical_support.php?sort=' + this.value">
                                <option value="rating">Highest Rated</option>
                                <option value="reviews">Most Reviews</option>
                                <option value="verified">Verified First</option>
                                <option value="free">Free Services First</option>
                                <option value="24_7">24/7 Available First</option>
                            </select>
                        </div>
                    <?php endif; ?>
                </div>

                <?php if (empty($providers)): ?>
                    <div class="card card-glass text-center p-12">
                        <i data-lucide="heart-off" class="w-16 h-16 text-white/30 mx-auto mb-4"></i>
                        <h3 class="heading-3 text-white mb-3">No Providers Found</h3>
                        <p class="text-white/60 mb-6">Try adjusting your search or filters.</p>
                    </div>
                <?php else: ?>
                    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                        <?php foreach ($providers as $provider):
                            $typeColors = [
                                'hospital' => 'from-blue-500 to-cyan-500',
                                'clinic' => 'from-green-500 to-emerald-500',
                                'counselor' => 'from-purple-500 to-pink-500',
                                'psychologist' => 'from-indigo-500 to-purple-500',
                                'psychiatrist' => 'from-violet-500 to-purple-500',
                                'trauma_center' => 'from-red-500 to-rose-500',
                                'ngo' => 'from-orange-500 to-amber-500'
                            ];
                            $typeColor = $typeColors[$provider['provider_type']] ?? 'from-gray-500 to-gray-600';
                            $specs = !empty($provider['specialization']) ? explode(',', $provider['specialization']) : [];
                        ?>
                            <div class="provider-card">
                                <div class="flex items-start justify-between mb-4">
                                    <div class="flex-1">
                                        <div class="flex items-center space-x-2 mb-2">
                                            <div class="w-12 h-12 bg-gradient-to-r <?= $typeColor ?> rounded-lg flex items-center justify-center">
                                                <i data-lucide="heart-pulse" class="w-6 h-6 text-white"></i>
                                            </div>
                                            <div class="flex-1">
                                                <h3 class="heading-4 text-white mb-1"><?= htmlspecialchars($provider['provider_name']) ?></h3>
                                                <p class="text-sm text-white/60 capitalize"><?= str_replace('_', ' ', $provider['provider_type']) ?></p>
                                            </div>
                                            <?php if ($provider['is_verified']): ?>
                                                <i data-lucide="badge-check" class="w-5 h-5 text-blue-400" title="Verified"></i>
                                            <?php endif; ?>
                                        </div>

                                        <?php if (!empty($specs)): ?>
                                            <div class="mb-3">
                                                <?php foreach (array_slice($specs, 0, 3) as $spec): ?>
                                                    <span class="specialization-badge bg-purple-500/20 text-purple-300 border border-purple-500/30">
                                                        <?= ucfirst(str_replace('_', ' ', trim($spec))) ?>
                                                    </span>
                                                <?php endforeach; ?>
                                                <?php if (count($specs) > 3): ?>
                                                    <span class="specialization-badge bg-gray-500/20 text-gray-300 border border-gray-500/30">
                                                        +<?= count($specs) - 3 ?> more
                                                    </span>
                                                <?php endif; ?>
                                            </div>
                                        <?php endif; ?>

                                        <div class="space-y-2 text-sm text-white/70 mb-4">
                                            <p class="flex items-center">
                                                <i data-lucide="map-pin" class="w-4 h-4 mr-2 text-blue-400"></i>
                                                <?= htmlspecialchars($provider['city'] ?? 'N/A') ?>, <?= htmlspecialchars($provider['district'] ?? 'N/A') ?>
                                            </p>
                                            <p class="flex items-center">
                                                <i data-lucide="phone" class="w-4 h-4 mr-2 text-green-400"></i>
                                                <?= htmlspecialchars($provider['phone']) ?>
                                            </p>
                                            <?php if ($provider['is_24_7']): ?>
                                                <p class="flex items-center text-green-400">
                                                    <i data-lucide="clock" class="w-4 h-4 mr-2"></i>
                                                    24/7 Available
                                                </p>
                                            <?php endif; ?>
                                            <?php if ($provider['accepts_insurance']): ?>
                                                <p class="flex items-center text-blue-400">
                                                    <i data-lucide="credit-card" class="w-4 h-4 mr-2"></i>
                                                    Accepts Insurance
                                                </p>
                                            <?php endif; ?>
                                        </div>

                                        <div class="flex items-center justify-between mb-4">
                                            <div class="flex items-center space-x-2">
                                                <div class="flex items-center">
                                                    <?php for ($i = 1; $i <= 5; $i++): ?>
                                                        <i data-lucide="star" class="w-4 h-4 <?= $i <= round($provider['rating']) ? 'text-yellow-400 fill-yellow-400' : 'text-white/20' ?>"></i>
                                                    <?php endfor; ?>
                                                </div>
                                                <span class="text-sm text-white/60"><?= number_format($provider['rating'], 1) ?> (<?= $provider['review_count'] ?>)</span>
                                            </div>
                                            <span class="px-3 py-1 rounded-full text-xs font-semibold bg-<?= $provider['fee_structure'] === 'free' ? 'green' : ($provider['fee_structure'] === 'subsidized' ? 'blue' : ($provider['fee_structure'] === 'premium' ? 'purple' : 'yellow')) ?>-500/20 text-<?= $provider['fee_structure'] === 'free' ? 'green' : ($provider['fee_structure'] === 'subsidized' ? 'blue' : ($provider['fee_structure'] === 'premium' ? 'purple' : 'yellow')) ?>-300 border border-<?= $provider['fee_structure'] === 'free' ? 'green' : ($provider['fee_structure'] === 'subsidized' ? 'blue' : ($provider['fee_structure'] === 'premium' ? 'purple' : 'yellow')) ?>-500/30">
                                                <?= ucfirst($provider['fee_structure']) ?>
                                            </span>
                                        </div>
                                    </div>
                                </div>

                                <div class="flex items-center space-x-2 pt-4 border-t border-white/10">
                                    <a href="provider_detail.php?id=<?= $provider['id'] ?>" class="btn btn-outline flex-1 text-center">
                                        <i data-lucide="eye" class="w-4 h-4 mr-2"></i>
                                        View Details
                                    </a>
                                    <a href="book_appointment.php?provider_id=<?= $provider['id'] ?>" class="btn btn-primary flex-1 text-center">
                                        <i data-lucide="calendar" class="w-4 h-4 mr-2"></i>
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

