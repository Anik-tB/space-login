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
    'district' => $_GET['district'] ?? '',
    'upazila' => $_GET['upazila'] ?? '',
    'division' => $_GET['division'] ?? '',
    'min_score' => $_GET['min_score'] ?? '',
    'max_score' => $_GET['max_score'] ?? '',
    'limit' => $_GET['limit'] ?? 100
];

// Search functionality
$searchTerm = $_GET['search'] ?? '';
$areas = [];

if (!empty($searchTerm)) {
    $areas = $models->searchAreas($searchTerm);
} else {
    $areas = $models->getAreaSafetyScores($filters);
}

// Get unique districts, upazilas, and divisions for filters
$allAreas = $models->getAreaSafetyScores([]);
$districts = array_unique(array_filter(array_column($allAreas, 'district')));
$upazilas = array_unique(array_filter(array_column($allAreas, 'upazila')));
$divisions = array_unique(array_filter(array_column($allAreas, 'division')));
sort($districts);
sort($upazilas);
sort($divisions);

// Initialize message variables
$message = '';
$error = '';

// Auto-recalculate scores for areas that need updating (if admin or on demand)
if (isset($_GET['recalculate']) && $_GET['recalculate'] === 'all') {
    $results = $models->recalculateAllAreaScores();
    $message = 'Recalculated ' . count($results) . ' area safety scores.';
    // Refresh areas
    $areas = $models->getAreaSafetyScores($filters);
    // Recalculate statistics after refresh
    $avgScore = !empty($areas) ? array_sum(array_column($areas, 'safety_score')) / count($areas) : 0;
    $highestScore = !empty($areas) ? max(array_column($areas, 'safety_score')) : 0;
    $lowestScore = !empty($areas) ? min(array_column($areas, 'safety_score')) : 0;
}

// Calculate statistics
$avgScore = !empty($areas) ? array_sum(array_column($areas, 'safety_score')) / count($areas) : 0;
$highestScore = !empty($areas) ? max(array_column($areas, 'safety_score')) : 0;
$lowestScore = !empty($areas) ? min(array_column($areas, 'safety_score')) : 0;
?>
<!DOCTYPE html>
<html lang="<?= $lang ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Area Safety Scores - SafeSpace Portal</title>

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
                    <a href="dashboard.php" class="text-white/70 hover:text-white transition-colors duration-200">Dashboard</a>
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
                    <div class="w-20 h-20 bg-gradient-to-r from-green-500 via-emerald-500 to-teal-500 rounded-3xl flex items-center justify-center mx-auto mb-6 shadow-2xl ring-4 ring-green-500/20">
                        <i data-lucide="shield-check" class="w-10 h-10 text-white drop-shadow-lg"></i>
                    </div>
                    <h1 class="heading-1 mb-4 text-white">Area Safety Scores</h1>
                    <p class="body-large max-w-2xl mx-auto text-white/80 leading-relaxed">
                        Compare safety scores across different areas. Scores are calculated based on incident data, response times, and community ratings.
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
            <div class="grid grid-cols-1 md:grid-cols-4 gap-4 mb-8">
                <div class="card card-glass p-6">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-white/60 text-sm mb-1">Total Areas</p>
                            <p class="heading-2 text-white"><?= count($areas) ?></p>
                        </div>
                        <div class="w-12 h-12 bg-gradient-to-r from-blue-500 to-cyan-500 rounded-lg flex items-center justify-center">
                            <i data-lucide="map" class="w-6 h-6 text-white"></i>
                        </div>
                    </div>
                </div>
                <div class="card card-glass p-6">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-white/60 text-sm mb-1">Average Score</p>
                            <p class="heading-2 text-white"><?= number_format($avgScore, 1) ?>/10</p>
                        </div>
                        <div class="w-12 h-12 bg-gradient-to-r from-green-500 to-emerald-500 rounded-lg flex items-center justify-center">
                            <i data-lucide="trending-up" class="w-6 h-6 text-white"></i>
                        </div>
                    </div>
                </div>
                <div class="card card-glass p-6">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-white/60 text-sm mb-1">Highest Score</p>
                            <p class="heading-2 text-white"><?= number_format($highestScore, 1) ?>/10</p>
                        </div>
                        <div class="w-12 h-12 bg-gradient-to-r from-yellow-500 to-orange-500 rounded-lg flex items-center justify-center">
                            <i data-lucide="award" class="w-6 h-6 text-white"></i>
                        </div>
                    </div>
                </div>
                <div class="card card-glass p-6">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-white/60 text-sm mb-1">Lowest Score</p>
                            <p class="heading-2 text-white"><?= number_format($lowestScore, 1) ?>/10</p>
                        </div>
                        <div class="w-12 h-12 bg-gradient-to-r from-red-500 to-rose-500 rounded-lg flex items-center justify-center">
                            <i data-lucide="alert-triangle" class="w-6 h-6 text-white"></i>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Search and Filters -->
            <section class="mb-8">
                <div class="card card-glass p-6">
                    <form method="GET" class="space-y-4">
                        <div class="flex flex-col md:flex-row gap-4">
                            <div class="flex-1">
                                <input type="text" name="search" value="<?= htmlspecialchars($searchTerm) ?>"
                                       placeholder="Search areas..."
                                       class="form-input w-full">
                            </div>
                            <button type="submit" class="btn btn-primary">
                                <i data-lucide="search" class="w-4 h-4 mr-2"></i>
                                Search
                            </button>
                        </div>

                        <div class="grid grid-cols-2 md:grid-cols-5 gap-4">
                            <div>
                                <label class="form-label text-white text-sm mb-2">Division</label>
                                <select name="division" class="form-input text-sm" onchange="this.form.submit()">
                                    <option value="">All Divisions</option>
                                    <?php foreach ($divisions as $div): ?>
                                        <option value="<?= htmlspecialchars($div) ?>" <?= $filters['division'] === $div ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($div) ?>
                                        </option>
                                    <?php endforeach; ?>
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
                                <label class="form-label text-white text-sm mb-2">Upazila</label>
                                <select name="upazila" class="form-input text-sm" onchange="this.form.submit()">
                                    <option value="">All Upazilas</option>
                                    <?php foreach ($upazilas as $upazila): ?>
                                        <option value="<?= htmlspecialchars($upazila) ?>" <?= $filters['upazila'] === $upazila ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($upazila) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div>
                                <label class="form-label text-white text-sm mb-2">Min Score</label>
                                <input type="number" name="min_score" value="<?= htmlspecialchars($filters['min_score']) ?>"
                                       min="0" max="10" step="0.1" class="form-input text-sm" onchange="this.form.submit()">
                            </div>

                            <div>
                                <label class="form-label text-white text-sm mb-2">Max Score</label>
                                <input type="number" name="max_score" value="<?= htmlspecialchars($filters['max_score']) ?>"
                                       min="0" max="10" step="0.1" class="form-input text-sm" onchange="this.form.submit()">
                            </div>
                        </div>

                        <?php if (!empty($searchTerm) || !empty(array_filter($filters))): ?>
                            <a href="safety_scores.php" class="text-sm text-purple-400 hover:text-purple-300">
                                <i data-lucide="x" class="w-4 h-4 inline mr-1"></i>
                                Clear Filters
                            </a>
                        <?php endif; ?>
                    </form>
                </div>
            </section>

            <!-- Areas List -->
            <section>
                <div class="mb-6 flex items-center justify-between">
                    <h2 class="heading-2 text-white">Safety Scores (<?= count($areas) ?>)</h2>
                    <div class="flex items-center space-x-2">
                        <a href="?recalculate=all" class="btn btn-outline btn-sm" onclick="return confirm('Recalculate all safety scores? This may take a moment.')">
                            <i data-lucide="refresh-cw" class="w-4 h-4 mr-2"></i>
                            Recalculate All
                        </a>
                    </div>
                </div>

                <?php if (empty($areas)): ?>
                    <div class="card card-glass text-center p-12">
                        <i data-lucide="map-pin-off" class="w-16 h-16 text-white/30 mx-auto mb-4"></i>
                        <h3 class="heading-3 text-white mb-3">No Areas Found</h3>
                        <p class="text-white/60 mb-6">Try adjusting your search or filters.</p>
                    </div>
                <?php else: ?>
                    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                        <?php foreach ($areas as $area):
                            $score = floatval($area['safety_score']);
                            $scoreColor = $score >= 8 ? 'from-green-500 to-emerald-500' :
                                         ($score >= 6 ? 'from-yellow-500 to-orange-500' :
                                         'from-red-500 to-rose-500');
                            $scoreLabel = $score >= 8 ? 'Safe' : ($score >= 6 ? 'Moderate' : 'Needs Attention');
                        ?>
                            <div class="card card-glass p-6 hover:scale-105 transition-transform duration-200">
                                <div class="flex items-start justify-between mb-4">
                                    <div class="flex-1">
                                        <h3 class="heading-4 text-white mb-2"><?= htmlspecialchars($area['area_name']) ?></h3>
                                        <p class="text-sm text-white/60 mb-3">
                                            <?= htmlspecialchars($area['district']) ?><?= $area['upazila'] ? ', ' . htmlspecialchars($area['upazila']) : '' ?>
                                            <?= $area['ward_number'] ? ' (Ward ' . htmlspecialchars($area['ward_number']) . ')' : '' ?>
                                        </p>
                                    </div>
                                </div>

                                <div class="mb-4">
                                    <div class="flex items-center justify-between mb-2">
                                        <span class="text-white/70 text-sm">Safety Score</span>
                                        <span class="px-3 py-1 rounded-full text-sm font-bold bg-gradient-to-r <?= $scoreColor ?> text-white">
                                            <?= number_format($score, 1) ?>/10
                                        </span>
                                    </div>
                                    <div class="w-full bg-white/10 rounded-full h-3 overflow-hidden">
                                        <div class="h-full bg-gradient-to-r <?= $scoreColor ?> transition-all duration-500"
                                             style="width: <?= ($score / 10) * 100 ?>%"></div>
                                    </div>
                                    <p class="text-xs text-white/50 mt-1"><?= $scoreLabel ?></p>
                                </div>

                                <div class="grid grid-cols-2 gap-3 mb-4 text-sm">
                                    <div class="p-2 bg-white/5 rounded-lg">
                                        <p class="text-white/60 text-xs">Incidents</p>
                                        <p class="text-white font-semibold"><?= $area['total_incidents'] ?></p>
                                    </div>
                                    <div class="p-2 bg-white/5 rounded-lg">
                                        <p class="text-white/60 text-xs">Resolved</p>
                                        <p class="text-white font-semibold"><?= $area['resolved_incidents'] ?></p>
                                    </div>
                                    <div class="p-2 bg-white/5 rounded-lg">
                                        <p class="text-white/60 text-xs">Critical</p>
                                        <p class="text-white font-semibold"><?= $area['critical_incidents'] ?></p>
                                    </div>
                                    <div class="p-2 bg-white/5 rounded-lg">
                                        <p class="text-white/60 text-xs">Response Time</p>
                                        <p class="text-white font-semibold"><?= number_format($area['response_time_avg_hours'], 1) ?>h</p>
                                    </div>
                                </div>

                                <?php if ($area['rating_count'] > 0): ?>
                                    <div class="flex items-center space-x-2 mb-4 text-sm">
                                        <div class="flex items-center">
                                            <?php
                                            $userRating = floatval($area['user_rating_avg'] ?? 0);
                                            for ($i = 1; $i <= 5; $i++):
                                            ?>
                                                <i data-lucide="star" class="w-3 h-3 <?= $i <= round($userRating) ? 'text-yellow-400 fill-yellow-400' : 'text-white/20' ?>"></i>
                                            <?php endfor; ?>
                                        </div>
                                        <span class="text-white/60">(<?= $area['rating_count'] ?> ratings)</span>
                                    </div>
                                <?php endif; ?>

                                <div class="flex items-center space-x-2 pt-4 border-t border-white/10">
                                    <a href="area_detail.php?id=<?= $area['id'] ?>" class="btn btn-outline flex-1">
                                        <i data-lucide="eye" class="w-4 h-4 mr-2"></i>
                                        View Details
                                    </a>
                                    <a href="rate_area.php?area_id=<?= $area['id'] ?>" class="btn btn-primary flex-1">
                                        <i data-lucide="star" class="w-4 h-4 mr-2"></i>
                                        Rate Area
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

