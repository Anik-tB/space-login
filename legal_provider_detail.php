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
    header('Location: legal_aid.php');
    exit;
}

$provider = $models->getLegalAidProviderById($providerId);

if (!$provider || $provider['status'] !== 'active') {
    header('Location: legal_aid.php?error=Provider not found');
    exit;
}

// Parse specializations and languages
$specs = !empty($provider['specialization']) ? explode(',', $provider['specialization']) : [];
$languages = !empty($provider['language_support']) ? explode(',', $provider['language_support']) : [];

// Get provider consultations count
$providerConsultations = $models->getConsultationsByProvider($providerId);
$consultationCount = count($providerConsultations);
?>
<!DOCTYPE html>
<html lang="<?= $lang ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($provider['organization_name']) ?> - Legal Aid</title>

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
                    <a href="legal_aid.php" class="text-white/70 hover:text-white transition-colors duration-200">Legal Aid</a>
                    <a href="my_consultations.php" class="text-white/70 hover:text-white transition-colors duration-200">My Consultations</a>
                </nav>
                <div class="flex items-center space-x-4">
                    <a href="legal_aid.php" class="btn btn-ghost">
                        <i data-lucide="arrow-left" class="w-4 h-4"></i>
                        Back
                    </a>
                </div>
            </div>
        </div>
    </header>

    <main class="pt-20 pb-8">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <!-- Provider Header -->
            <section class="mb-6">
                <div class="card card-glass p-6">
                    <div class="flex items-start justify-between mb-4">
                        <div class="flex-1">
                            <div class="flex items-center space-x-3 mb-3">
                                <div class="w-16 h-16 bg-gradient-to-r from-purple-500 to-indigo-500 rounded-2xl flex items-center justify-center">
                                    <i data-lucide="scale" class="w-8 h-8 text-white"></i>
                                </div>
                                <div>
                                    <h1 class="heading-1 text-white"><?= htmlspecialchars($provider['organization_name']) ?></h1>
                                    <?php if ($provider['contact_person']): ?>
                                        <p class="text-white/60"><?= htmlspecialchars($provider['contact_person']) ?></p>
                                    <?php endif; ?>
                                </div>
                                <?php if ($provider['is_verified']): ?>
                                    <i data-lucide="badge-check" class="w-6 h-6 text-blue-400" title="Verified Provider"></i>
                                <?php endif; ?>
                            </div>

                            <div class="flex items-center space-x-4 mb-4">
                                <div class="flex items-center">
                                    <?php for ($i = 1; $i <= 5; $i++): ?>
                                        <i data-lucide="star" class="w-5 h-5 <?= $i <= round($provider['rating']) ? 'text-yellow-400 fill-yellow-400' : 'text-white/20' ?>"></i>
                                    <?php endfor; ?>
                                </div>
                                <span class="text-white/70"><?= number_format($provider['rating'], 1) ?> (<?= $provider['review_count'] ?> reviews)</span>
                                <span class="px-3 py-1 rounded-full text-sm font-semibold bg-<?= $provider['fee_structure'] === 'free' ? 'green' : ($provider['fee_structure'] === 'low_cost' ? 'blue' : ($provider['fee_structure'] === 'pro_bono' ? 'purple' : 'yellow')) ?>-500/20 text-<?= $provider['fee_structure'] === 'free' ? 'green' : ($provider['fee_structure'] === 'low_cost' ? 'blue' : ($provider['fee_structure'] === 'pro_bono' ? 'purple' : 'yellow')) ?>-300 border border-<?= $provider['fee_structure'] === 'free' ? 'green' : ($provider['fee_structure'] === 'low_cost' ? 'blue' : ($provider['fee_structure'] === 'pro_bono' ? 'purple' : 'yellow')) ?>-500/30">
                                    <?= ucfirst(str_replace('_', ' ', $provider['fee_structure'])) ?>
                                </span>
                            </div>

                            <div class="flex flex-wrap items-center gap-3 text-sm text-white/70 mb-4">
                                <span class="flex items-center"><i data-lucide="map-pin" class="w-4 h-4 mr-1.5 text-blue-400"></i> <?= htmlspecialchars($provider['city'] ?? 'N/A') ?><?= $provider['district'] ? ', ' . htmlspecialchars($provider['district']) : '' ?></span>
                                <span class="flex items-center"><i data-lucide="phone" class="w-4 h-4 mr-1.5 text-green-400"></i> <?= htmlspecialchars($provider['phone']) ?></span>
                                <?php if ($provider['email']): ?>
                                    <span class="flex items-center"><i data-lucide="mail" class="w-4 h-4 mr-1.5 text-purple-400"></i> <?= htmlspecialchars($provider['email']) ?></span>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="ml-4">
                            <a href="book_consultation.php?provider_id=<?= $provider['id'] ?>" class="btn btn-primary">
                                <i data-lucide="calendar-plus" class="w-4 h-4 mr-2"></i>
                                Book Consultation
                            </a>
                        </div>
                    </div>

                    <!-- Collapsible Details Section -->
                    <div class="mt-4">
                        <button onclick="toggleDetails()" class="flex items-center justify-between w-full p-3 bg-white/5 hover:bg-white/8 rounded-lg transition-colors">
                            <span class="text-sm font-semibold text-white flex items-center">
                                <i data-lucide="info" class="w-4 h-4 mr-2 text-blue-400"></i>
                                Provider Details
                            </span>
                            <i data-lucide="chevron-down" id="detailsChevron" class="w-4 h-4 text-white/60 transition-transform"></i>
                        </button>
                        <div id="detailsContent" class="hidden mt-3 space-y-4">
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                                <div>
                                    <h4 class="font-semibold text-white mb-3 flex items-center">
                                        <i data-lucide="map-pin" class="w-4 h-4 mr-2 text-blue-400"></i>
                                        Address
                                    </h4>
                                    <p class="text-white/80 text-sm"><?= nl2br(htmlspecialchars($provider['address'] ?? 'Address not provided')) ?></p>
                                    <?php if ($provider['division']): ?>
                                        <p class="text-white/60 text-sm mt-1"><?= htmlspecialchars($provider['division']) ?> Division</p>
                                    <?php endif; ?>
                                </div>

                                <div>
                                    <h4 class="font-semibold text-white mb-3 flex items-center">
                                        <i data-lucide="clock" class="w-4 h-4 mr-2 text-green-400"></i>
                                        Availability
                                    </h4>
                                    <?php if ($provider['is_24_7']): ?>
                                        <p class="text-green-300 font-semibold mb-2">24/7 Available</p>
                                    <?php endif; ?>
                                    <?php if ($provider['availability_hours']): ?>
                                        <p class="text-white/80 text-sm"><?= htmlspecialchars($provider['availability_hours']) ?></p>
                                    <?php else: ?>
                                        <p class="text-white/60 text-sm">Contact for availability</p>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <?php if (!empty($specs)): ?>
                                <div>
                                    <h4 class="font-semibold text-white mb-3 flex items-center">
                                        <i data-lucide="briefcase" class="w-4 h-4 mr-2 text-purple-400"></i>
                                        Specializations
                                    </h4>
                                    <div class="flex flex-wrap gap-2">
                                        <?php foreach ($specs as $spec): ?>
                                            <span class="px-3 py-1.5 bg-purple-500/20 text-purple-300 rounded-lg border border-purple-500/30 text-sm font-medium">
                                                <?= ucfirst(str_replace('_', ' ', trim($spec))) ?>
                                            </span>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            <?php endif; ?>

                            <?php if (!empty($languages)): ?>
                                <div>
                                    <h4 class="font-semibold text-white mb-3 flex items-center">
                                        <i data-lucide="languages" class="w-4 h-4 mr-2 text-indigo-400"></i>
                                        Languages Supported
                                    </h4>
                                    <div class="flex flex-wrap gap-2">
                                        <?php foreach ($languages as $lang): ?>
                                            <span class="px-3 py-1.5 bg-indigo-500/20 text-indigo-300 rounded-lg border border-indigo-500/30 text-sm">
                                                <?= strtoupper(trim($lang)) ?>
                                            </span>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            <?php endif; ?>

                            <div class="grid grid-cols-2 md:grid-cols-4 gap-4 p-4 bg-white/5 rounded-lg">
                                <?php if ($provider['cases_handled']): ?>
                                    <div>
                                        <p class="text-xs text-white/50 mb-1">Cases Handled</p>
                                        <p class="text-sm text-white font-semibold"><?= $provider['cases_handled'] ?></p>
                                    </div>
                                <?php endif; ?>
                                <?php if ($provider['success_rate']): ?>
                                    <div>
                                        <p class="text-xs text-white/50 mb-1">Success Rate</p>
                                        <p class="text-sm text-white font-semibold"><?= number_format($provider['success_rate'], 1) ?>%</p>
                                    </div>
                                <?php endif; ?>
                                <div>
                                    <p class="text-xs text-white/50 mb-1">Consultations</p>
                                    <p class="text-sm text-white font-semibold"><?= $consultationCount ?></p>
                                </div>
                                <div>
                                    <p class="text-xs text-white/50 mb-1">Rating</p>
                                    <p class="text-sm text-white font-semibold"><?= number_format($provider['rating'], 1) ?>/5</p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </section>

            <div class="grid grid-cols-1 lg:grid-cols-3 gap-4">
                <!-- Main Content -->
                <div class="lg:col-span-2 space-y-4">
                    <!-- Book Consultation CTA -->
                    <div class="card card-glass p-6 bg-gradient-to-r from-purple-500/10 to-indigo-500/10 border-purple-500/30">
                        <div class="flex items-center justify-between">
                            <div>
                                <h3 class="heading-3 text-white mb-2">Need Legal Assistance?</h3>
                                <p class="text-white/70 text-sm">Book a consultation with this provider to discuss your legal matter.</p>
                            </div>
                            <a href="book_consultation.php?provider_id=<?= $provider['id'] ?>" class="btn btn-primary">
                                <i data-lucide="calendar-plus" class="w-4 h-4 mr-2"></i>
                                Book Now
                            </a>
                        </div>
                    </div>
                </div>

                <!-- Sidebar -->
                <div class="space-y-4">
                    <!-- Quick Contact -->
                    <div class="card card-glass p-5">
                        <h3 class="heading-3 text-white mb-4">Quick Contact</h3>
                        <div class="space-y-3">
                            <a href="tel:<?= htmlspecialchars($provider['phone']) ?>" class="flex items-center justify-between p-3 bg-white/5 rounded-lg hover:bg-white/10 transition-colors">
                                <div class="flex items-center">
                                    <i data-lucide="phone" class="w-5 h-5 text-green-400 mr-3"></i>
                                    <span class="text-white text-sm">Call Now</span>
                                </div>
                                <i data-lucide="arrow-right" class="w-4 h-4 text-white/60"></i>
                            </a>
                            <?php if ($provider['email']): ?>
                                <a href="mailto:<?= htmlspecialchars($provider['email']) ?>" class="flex items-center justify-between p-3 bg-white/5 rounded-lg hover:bg-white/10 transition-colors">
                                    <div class="flex items-center">
                                        <i data-lucide="mail" class="w-5 h-5 text-blue-400 mr-3"></i>
                                        <span class="text-white text-sm">Send Email</span>
                                    </div>
                                    <i data-lucide="arrow-right" class="w-4 h-4 text-white/60"></i>
                                </a>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Key Information -->
                    <div class="card card-glass p-5">
                        <h3 class="heading-3 text-white mb-4">Key Information</h3>
                        <div class="space-y-3">
                            <div class="flex items-center justify-between p-2 rounded-lg bg-white/5">
                                <span class="text-white/70 text-sm">Fee Structure</span>
                                <span class="text-white font-semibold capitalize"><?= str_replace('_', ' ', $provider['fee_structure']) ?></span>
                            </div>
                            <div class="flex items-center justify-between p-2 rounded-lg bg-white/5">
                                <span class="text-white/70 text-sm">Verified</span>
                                <span class="text-white font-semibold"><?= $provider['is_verified'] ? 'Yes' : 'No' ?></span>
                            </div>
                            <?php if ($provider['cases_handled']): ?>
                                <div class="flex items-center justify-between p-2 rounded-lg bg-white/5">
                                    <span class="text-white/70 text-sm">Cases Handled</span>
                                    <span class="text-white font-semibold"><?= $provider['cases_handled'] ?></span>
                                </div>
                            <?php endif; ?>
                            <?php if ($provider['success_rate']): ?>
                                <div class="flex items-center justify-between p-2 rounded-lg bg-white/5">
                                    <span class="text-white/70 text-sm">Success Rate</span>
                                    <span class="text-white font-semibold"><?= number_format($provider['success_rate'], 1) ?>%</span>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
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

