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

$providerId = $_GET['id'] ?? null;

if (!$providerId) {
    header('Location: medical_support.php');
    exit;
}

$provider = $models->getMedicalProviderById($providerId);

if (!$provider || $provider['status'] !== 'active') {
    header('Location: medical_support.php?error=Provider not found');
    exit;
}

$specs = !empty($provider['specialization']) ? explode(',', $provider['specialization']) : [];
$languages = !empty($provider['languages']) ? explode(',', $provider['languages']) : [];
?>
<!DOCTYPE html>
<html lang="<?= $lang ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($provider['provider_name']) ?> - Medical Support</title>

    <link rel="icon" type="image/svg+xml" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><text y='.9em' font-size='90'>🛡️</text></svg>">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&family=Poppins:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/lucide/0.263.1/lucide.min.css">
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="design-system.css">
    <link rel="stylesheet" href="dashboard-styles.css">
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
                    <a href="medical_support.php" class="text-white/70 hover:text-white transition-colors duration-200">Medical Support</a>
                    <a href="my_referrals.php" class="text-white/70 hover:text-white transition-colors duration-200">My Referrals</a>
                </nav>
                <div class="flex items-center space-x-4">
                    <a href="medical_support.php" class="btn btn-ghost">
                        <i data-lucide="arrow-left" class="w-4 h-4"></i>
                        Back
                    </a>
                </div>
            </div>
        </div>
    </header>

    <main class="pt-20 pb-8">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <section class="mb-8">
                <div class="card card-glass p-8">
                    <div class="flex items-start justify-between mb-6">
                        <div class="flex-1">
                            <div class="flex items-center space-x-3 mb-4">
                                <div class="w-16 h-16 bg-gradient-to-r from-red-500 to-pink-500 rounded-2xl flex items-center justify-center">
                                    <i data-lucide="heart-pulse" class="w-8 h-8 text-white"></i>
                                </div>
                                <div>
                                    <h1 class="heading-1 text-white"><?= htmlspecialchars($provider['provider_name']) ?></h1>
                                    <p class="text-white/60 capitalize"><?= str_replace('_', ' ', $provider['provider_type']) ?></p>
                                </div>
                                <?php if ($provider['is_verified']): ?>
                                    <i data-lucide="badge-check" class="w-6 h-6 text-blue-400" title="Verified Provider"></i>
                                <?php endif; ?>
                            </div>

                            <div class="flex items-center space-x-4 mb-6">
                                <div class="flex items-center">
                                    <?php for ($i = 1; $i <= 5; $i++): ?>
                                        <i data-lucide="star" class="w-5 h-5 <?= $i <= round($provider['rating']) ? 'text-yellow-400 fill-yellow-400' : 'text-white/20' ?>"></i>
                                    <?php endfor; ?>
                                </div>
                                <span class="text-white/70"><?= number_format($provider['rating'], 1) ?> (<?= $provider['review_count'] ?> reviews)</span>
                                <span class="px-3 py-1 rounded-full text-sm font-semibold bg-<?= $provider['fee_structure'] === 'free' ? 'green' : ($provider['fee_structure'] === 'subsidized' ? 'blue' : 'purple') ?>-500/20 text-<?= $provider['fee_structure'] === 'free' ? 'green' : ($provider['fee_structure'] === 'subsidized' ? 'blue' : 'purple') ?>-300 border border-<?= $provider['fee_structure'] === 'free' ? 'green' : ($provider['fee_structure'] === 'subsidized' ? 'blue' : 'purple') ?>-500/30">
                                    <?= ucfirst($provider['fee_structure']) ?>
                                </span>
                            </div>

                            <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-6">
                                <div>
                                    <h3 class="heading-3 text-white mb-3">Contact Information</h3>
                                    <div class="space-y-2 text-white/80">
                                        <p class="flex items-center">
                                            <i data-lucide="phone" class="w-5 h-5 mr-3 text-green-400"></i>
                                            <a href="tel:<?= htmlspecialchars($provider['phone']) ?>" class="hover:text-green-400">
                                                <?= htmlspecialchars($provider['phone']) ?>
                                            </a>
                                        </p>
                                        <?php if ($provider['email']): ?>
                                            <p class="flex items-center">
                                                <i data-lucide="mail" class="w-5 h-5 mr-3 text-blue-400"></i>
                                                <a href="mailto:<?= htmlspecialchars($provider['email']) ?>" class="hover:text-blue-400">
                                                    <?= htmlspecialchars($provider['email']) ?>
                                                </a>
                                            </p>
                                        <?php endif; ?>
                                        <?php if ($provider['website']): ?>
                                            <p class="flex items-center">
                                                <i data-lucide="globe" class="w-5 h-5 mr-3 text-purple-400"></i>
                                                <a href="<?= htmlspecialchars($provider['website']) ?>" target="_blank" class="hover:text-purple-400">
                                                    Visit Website
                                                </a>
                                            </p>
                                        <?php endif; ?>
                                        <p class="flex items-start">
                                            <i data-lucide="map-pin" class="w-5 h-5 mr-3 text-red-400 mt-1"></i>
                                            <span><?= nl2br(htmlspecialchars($provider['address'])) ?></span>
                                        </p>
                                        <p class="flex items-center">
                                            <i data-lucide="map" class="w-5 h-5 mr-3 text-orange-400"></i>
                                            <?= htmlspecialchars($provider['city'] ?? 'N/A') ?>, <?= htmlspecialchars($provider['district'] ?? 'N/A') ?><?= $provider['division'] ? ', ' . htmlspecialchars($provider['division']) : '' ?>
                                        </p>
                                    </div>
                                </div>

                                <div>
                                    <h3 class="heading-3 text-white mb-3">Services & Features</h3>
                                    <div class="space-y-3">
                                        <?php if ($provider['is_24_7']): ?>
                                            <div class="flex items-center p-3 bg-green-500/10 border border-green-500/30 rounded-lg">
                                                <i data-lucide="clock" class="w-5 h-5 text-green-400 mr-3"></i>
                                                <span class="text-white">24/7 Available</span>
                                            </div>
                                        <?php endif; ?>
                                        <?php if ($provider['accepts_insurance']): ?>
                                            <div class="flex items-center p-3 bg-blue-500/10 border border-blue-500/30 rounded-lg">
                                                <i data-lucide="credit-card" class="w-5 h-5 text-blue-400 mr-3"></i>
                                                <span class="text-white">Accepts Insurance</span>
                                            </div>
                                        <?php endif; ?>
                                        <?php if (!empty($languages)): ?>
                                            <div class="p-3 bg-purple-500/10 border border-purple-500/30 rounded-lg">
                                                <p class="text-white mb-2 flex items-center">
                                                    <i data-lucide="languages" class="w-5 h-5 text-purple-400 mr-2"></i>
                                                    Languages Supported:
                                                </p>
                                                <div class="flex flex-wrap gap-2">
                                                    <?php foreach ($languages as $lang): ?>
                                                        <span class="px-2 py-1 bg-purple-500/20 text-purple-300 rounded text-xs">
                                                            <?= strtoupper(trim($lang)) ?>
                                                        </span>
                                                    <?php endforeach; ?>
                                                </div>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>

                            <?php if (!empty($specs)): ?>
                                <div class="mb-6">
                                    <h3 class="heading-3 text-white mb-3">Specializations</h3>
                                    <div class="flex flex-wrap gap-2">
                                        <?php foreach ($specs as $spec): ?>
                                            <span class="px-4 py-2 bg-gradient-to-r from-purple-500/20 to-pink-500/20 text-purple-300 rounded-lg border border-purple-500/30 text-sm font-medium">
                                                <?= ucfirst(str_replace('_', ' ', trim($spec))) ?>
                                            </span>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            <?php endif; ?>

                            <!-- Collapsible Details Section -->
                            <div class="mt-4">
                                <button onclick="toggleDetails()" class="flex items-center justify-between w-full p-3 bg-white/5 hover:bg-white/8 rounded-lg transition-colors">
                                    <span class="text-sm font-semibold text-white flex items-center">
                                        <i data-lucide="info" class="w-4 h-4 mr-2 text-blue-400"></i>
                                        Additional Details
                                    </span>
                                    <i data-lucide="chevron-down" id="detailsChevron" class="w-4 h-4 text-white/60 transition-transform"></i>
                                </button>
                                <div id="detailsContent" class="hidden mt-3 space-y-3">
                                    <?php if ($provider['insurance_types']): ?>
                                        <div class="p-4 bg-blue-500/10 border border-blue-500/30 rounded-lg">
                                            <h4 class="font-semibold text-blue-300 mb-2 flex items-center text-sm">
                                                <i data-lucide="credit-card" class="w-4 h-4 mr-2"></i>
                                                Insurance Types Accepted
                                            </h4>
                                            <p class="text-sm text-blue-200"><?= htmlspecialchars($provider['insurance_types']) ?></p>
                                        </div>
                                    <?php endif; ?>
                                    <div class="grid grid-cols-2 md:grid-cols-3 gap-4 p-4 bg-white/5 rounded-lg">
                                        <div>
                                            <p class="text-xs text-white/50 mb-1">Provider Type</p>
                                            <p class="text-sm text-white font-medium capitalize"><?= str_replace('_', ' ', $provider['provider_type']) ?></p>
                                        </div>
                                        <div>
                                            <p class="text-xs text-white/50 mb-1">Fee Structure</p>
                                            <p class="text-sm text-white font-medium capitalize"><?= str_replace('_', ' ', $provider['fee_structure']) ?></p>
                                        </div>
                                        <div>
                                            <p class="text-xs text-white/50 mb-1">Verified</p>
                                            <p class="text-sm text-white font-medium"><?= $provider['is_verified'] ? 'Yes' : 'No' ?></p>
                                        </div>
                                        <?php if ($provider['division']): ?>
                                            <div>
                                                <p class="text-xs text-white/50 mb-1">Division</p>
                                                <p class="text-sm text-white font-medium"><?= htmlspecialchars($provider['division']) ?></p>
                                            </div>
                                        <?php endif; ?>
                                        <div>
                                            <p class="text-xs text-white/50 mb-1">Rating</p>
                                            <p class="text-sm text-white font-medium"><?= number_format($provider['rating'], 1) ?>/5</p>
                                        </div>
                                        <div>
                                            <p class="text-xs text-white/50 mb-1">Reviews</p>
                                            <p class="text-sm text-white font-medium"><?= $provider['review_count'] ?></p>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="flex items-center space-x-4 pt-6 border-t border-white/10">
                        <a href="book_appointment.php?provider_id=<?= $provider['id'] ?>" class="btn btn-primary flex-1">
                            <i data-lucide="calendar" class="w-4 h-4 mr-2"></i>
                            Book Appointment
                        </a>
                        <a href="medical_support.php" class="btn btn-outline">
                            <i data-lucide="arrow-left" class="w-4 h-4 mr-2"></i>
                            Back to Directory
                        </a>
                    </div>
                </div>
            </section>
        </div>
    </main>

    <script src="https://unpkg.com/lucide@latest/dist/umd/lucide.js"></script>
    <script src="dashboard-enhanced.js"></script>
    <script>
        lucide.createIcons();

        function toggleDetails() {
            const content = document.getElementById('detailsContent');
            const chevron = document.getElementById('detailsChevron');
            if (content.classList.contains('hidden')) {
                content.classList.remove('hidden');
                chevron.style.transform = 'rotate(180deg)';
            } else {
                content.classList.add('hidden');
                chevron.style.transform = 'rotate(0deg)';
            }
        }
    </script>
</body>
</html>

