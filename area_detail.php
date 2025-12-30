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

$areaId = $_GET['id'] ?? null;

if (!$areaId) {
    header('Location: safety_scores.php');
    exit;
}

$area = $models->getAreaSafetyScoreById($areaId);

if (!$area) {
    header('Location: safety_scores.php?error=Area not found');
    exit;
}

// Get area ratings
$ratings = $models->getAreaRatings($areaId, ['limit' => 20]);
$userRating = $models->getUserAreaRating($userId, $areaId);

// Initialize message variables
$message = '';
$error = '';

// Build score breakdown from individual metric columns
$scoreBreakdown = array_filter([
    'incident_rate' => isset($area['incident_rate_score']) ? (float)$area['incident_rate_score'] : null,
    'resolution_rate' => isset($area['resolution_rate_score']) ? (float)$area['resolution_rate_score'] : null,
    'response_time' => isset($area['response_time_score']) ? (float)$area['response_time_score'] : null,
    'user_ratings' => isset($area['user_ratings_score']) ? (float)$area['user_ratings_score'] : null,
    'critical_incidents' => isset($area['critical_incidents_score']) ? (float)$area['critical_incidents_score'] : null,
], fn($value) => $value !== null);

// Handle manual recalculation
if (isset($_GET['recalculate']) && $_GET['recalculate'] === '1') {
    $newScore = $models->calculateAreaSafetyScore($areaId);
    if ($newScore !== false) {
        $message = 'Safety score recalculated successfully! New score: ' . number_format($newScore, 1) . '/10';
        $area = $models->getAreaSafetyScoreById($areaId); // Refresh data
        $scoreBreakdown = array_filter([
            'incident_rate' => isset($area['incident_rate_score']) ? (float)$area['incident_rate_score'] : null,
            'resolution_rate' => isset($area['resolution_rate_score']) ? (float)$area['resolution_rate_score'] : null,
            'response_time' => isset($area['response_time_score']) ? (float)$area['response_time_score'] : null,
            'user_ratings' => isset($area['user_ratings_score']) ? (float)$area['user_ratings_score'] : null,
            'critical_incidents' => isset($area['critical_incidents_score']) ? (float)$area['critical_incidents_score'] : null,
        ], fn($value) => $value !== null);
    } else {
        $error = 'Failed to recalculate safety score.';
    }
}

$score = floatval($area['safety_score']);
$scoreColor = $score >= 8 ? 'from-green-500 to-emerald-500' :
             ($score >= 6 ? 'from-yellow-500 to-orange-500' :
             'from-red-500 to-rose-500');
?>
<!DOCTYPE html>
<html lang="<?= $lang ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($area['area_name']) ?> - Safety Score</title>

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
                    <a href="safety_scores.php" class="text-white/70 hover:text-white transition-colors duration-200">Safety Scores</a>
                </nav>
                <div class="flex items-center space-x-4">
                    <a href="safety_scores.php" class="btn btn-ghost">
                        <i data-lucide="arrow-left" class="w-4 h-4"></i>
                        Back
                    </a>
                </div>
            </div>
        </div>
    </header>

    <main class="pt-20 pb-8">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
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

            <section class="mb-8">
                <div class="card card-glass p-8">
                    <div class="flex items-start justify-between mb-6">
                        <div class="flex-1">
                            <h1 class="heading-1 text-white mb-2"><?= htmlspecialchars($area['area_name']) ?></h1>
                            <p class="text-white/60 mb-4">
                                <?= htmlspecialchars($area['district']) ?><?= $area['upazila'] ? ', ' . htmlspecialchars($area['upazila']) : '' ?>
                                <?= $area['ward_number'] ? ' • Ward ' . htmlspecialchars($area['ward_number']) : '' ?>
                                <?= $area['union_name'] ? ' • ' . htmlspecialchars($area['union_name']) : '' ?>
                            </p>
                        </div>
                        <div class="text-right">
                            <div class="text-5xl font-bold bg-gradient-to-r <?= $scoreColor ?> bg-clip-text text-transparent mb-2">
                                <?= number_format($score, 1) ?>
                            </div>
                            <p class="text-white/60 text-sm">Safety Score / 10</p>
                        </div>
                    </div>

                    <!-- Score Breakdown -->
                    <?php if (!empty($scoreBreakdown)): ?>
                        <div class="mb-6">
                            <h3 class="heading-3 text-white mb-4">Score Breakdown</h3>
                            <div class="space-y-3">
                                <?php
                                $breakdownLabels = [
                                    'incident_rate' => 'Incident Rate',
                                    'resolution_rate' => 'Resolution Rate',
                                    'response_time' => 'Response Time',
                                    'user_ratings' => 'User Ratings',
                                    'critical_incidents' => 'Critical Incidents'
                                ];
                                foreach ($scoreBreakdown as $factor => $value):
                                    $factorScore = floatval($value);
                                    $factorColor = $factorScore >= 8 ? 'from-green-500 to-emerald-500' :
                                                 ($factorScore >= 6 ? 'from-yellow-500 to-orange-500' :
                                                 'from-red-500 to-rose-500');
                                ?>
                                    <div>
                                        <div class="flex items-center justify-between mb-1">
                                            <span class="text-white/80 text-sm"><?= $breakdownLabels[$factor] ?? ucfirst(str_replace('_', ' ', $factor)) ?></span>
                                            <span class="text-white font-semibold"><?= number_format($factorScore, 1) ?>/10</span>
                                        </div>
                                        <div class="w-full bg-white/10 rounded-full h-2 overflow-hidden">
                                            <div class="h-full bg-gradient-to-r <?= $factorColor ?> transition-all duration-500"
                                                 style="width: <?= ($factorScore / 10) * 100 ?>%"></div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endif; ?>

                    <!-- Statistics -->
                    <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-6">
                        <div class="p-4 bg-white/5 rounded-lg border border-white/10">
                            <p class="text-white/60 text-sm mb-1">Total Incidents</p>
                            <p class="text-2xl font-bold text-white"><?= $area['total_incidents'] ?></p>
                        </div>
                        <div class="p-4 bg-white/5 rounded-lg border border-white/10">
                            <p class="text-white/60 text-sm mb-1">Resolved</p>
                            <p class="text-2xl font-bold text-green-400"><?= $area['resolved_incidents'] ?></p>
                            <p class="text-xs text-white/50 mt-1">
                                <?= $area['total_incidents'] > 0 ? number_format(($area['resolved_incidents'] / $area['total_incidents']) * 100, 1) : 0 ?>% resolved
                            </p>
                        </div>
                        <div class="p-4 bg-white/5 rounded-lg border border-white/10">
                            <p class="text-white/60 text-sm mb-1">Critical</p>
                            <p class="text-2xl font-bold text-red-400"><?= $area['critical_incidents'] ?></p>
                        </div>
                        <div class="p-4 bg-white/5 rounded-lg border border-white/10">
                            <p class="text-white/60 text-sm mb-1">Avg Response</p>
                            <p class="text-2xl font-bold text-blue-400"><?= number_format($area['response_time_avg_hours'], 1) ?>h</p>
                        </div>
                    </div>

                    <div class="flex items-center space-x-4 pt-6 border-t border-white/10">
                        <a href="rate_area.php?area_id=<?= $area['id'] ?>" class="btn btn-primary">
                            <i data-lucide="star" class="w-4 h-4 mr-2"></i>
                            <?= $userRating ? 'Update Rating' : 'Rate This Area' ?>
                        </a>
                        <a href="area_detail.php?id=<?= $areaId ?>&recalculate=1" class="btn btn-outline" onclick="return confirm('Recalculate safety score for this area?')">
                            <i data-lucide="refresh-cw" class="w-4 h-4 mr-2"></i>
                            Recalculate Score
                        </a>
                        <a href="safety_scores.php" class="btn btn-outline">
                            <i data-lucide="arrow-left" class="w-4 h-4 mr-2"></i>
                            Back to Scores
                        </a>
                    </div>
                </div>
            </section>

            <!-- User Ratings -->
            <section>
                <div class="card card-glass p-6">
                    <div class="flex items-center justify-between mb-6">
                        <h2 class="heading-2 text-white">Community Ratings (<?= count($ratings) ?>)</h2>
                        <?php if (!empty($ratings)): ?>
                            <div class="flex items-center space-x-2">
                                <div class="flex items-center">
                                    <?php
                                    $avgRating = floatval($area['user_rating_avg'] ?? 0);
                                    for ($i = 1; $i <= 5; $i++):
                                    ?>
                                        <i data-lucide="star" class="w-5 h-5 <?= $i <= round($avgRating) ? 'text-yellow-400 fill-yellow-400' : 'text-white/20' ?>"></i>
                                    <?php endfor; ?>
                                </div>
                                <span class="text-white/70"><?= number_format($avgRating, 1) ?> average</span>
                            </div>
                        <?php endif; ?>
                    </div>

                    <?php if (empty($ratings)): ?>
                        <div class="text-center py-8">
                            <i data-lucide="star-off" class="w-12 h-12 text-white/30 mx-auto mb-3"></i>
                            <p class="text-white/60">No ratings yet. Be the first to rate this area!</p>
                        </div>
                    <?php else: ?>
                        <div class="space-y-4">
                            <?php foreach ($ratings as $rating): ?>
                                <div class="p-4 bg-white/5 rounded-lg border border-white/10">
                                    <div class="flex items-start justify-between mb-2">
                                        <div class="flex items-center space-x-3">
                                            <div class="w-10 h-10 bg-gradient-to-r from-purple-500 to-pink-500 rounded-full flex items-center justify-center">
                                                <span class="text-sm font-semibold text-white">
                                                    <?= strtoupper(substr($rating['display_name'] ?? $rating['email'] ?? 'U', 0, 1)) ?>
                                                </span>
                                            </div>
                                            <div>
                                                <p class="text-white font-medium"><?= htmlspecialchars($rating['display_name'] ?? 'Anonymous') ?></p>
                                                <p class="text-xs text-white/50">
                                                    <?= date('M j, Y', strtotime($rating['created_at'])) ?>
                                                    <?php if ($rating['is_verified_resident']): ?>
                                                        <span class="ml-2 px-2 py-0.5 bg-blue-500/20 text-blue-300 rounded text-xs">Verified Resident</span>
                                                    <?php endif; ?>
                                                </p>
                                            </div>
                                        </div>
                                        <div class="flex items-center">
                                            <?php for ($i = 1; $i <= 5; $i++): ?>
                                                <i data-lucide="star" class="w-4 h-4 <?= $i <= $rating['safety_rating'] ? 'text-yellow-400 fill-yellow-400' : 'text-white/20' ?>"></i>
                                            <?php endfor; ?>
                                        </div>
                                    </div>
                                    <?php if ($rating['comments']): ?>
                                        <p class="text-white/80 mt-2"><?= nl2br(htmlspecialchars($rating['comments'])) ?></p>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
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

