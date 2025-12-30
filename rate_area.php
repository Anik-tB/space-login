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

$areaId = $_GET['area_id'] ?? null;

if (!$areaId) {
    header('Location: safety_scores.php');
    exit;
}

$area = $models->getAreaSafetyScoreById($areaId);

if (!$area) {
    header('Location: safety_scores.php?error=Area not found');
    exit;
}

$existingRating = $models->getUserAreaRating($userId, $areaId);

// Handle form submission
$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'submit_rating') {
    try {
        $ratingData = [
            'user_id' => $userId,
            'area_id' => $areaId,
            'safety_rating' => intval($_POST['safety_rating']),
            'comments' => trim($_POST['comments'] ?? ''),
            'is_verified_resident' => isset($_POST['is_verified_resident']) ? 1 : 0
        ];

        // Collect factors
        $factors = [];
        if (isset($_POST['lighting'])) $factors['lighting'] = $_POST['lighting'];
        if (isset($_POST['police_presence'])) $factors['police_presence'] = $_POST['police_presence'];
        if (isset($_POST['traffic'])) $factors['traffic'] = $_POST['traffic'];
        if (isset($_POST['public_transport'])) $factors['public_transport'] = $_POST['public_transport'];
        if (isset($_POST['street_condition'])) $factors['street_condition'] = $_POST['street_condition'];

        $ratingData['factors'] = $factors;

        if (empty($ratingData['safety_rating']) || $ratingData['safety_rating'] < 1 || $ratingData['safety_rating'] > 5) {
            throw new Exception('Please provide a valid safety rating (1-5 stars).');
        }

        $result = $models->createOrUpdateAreaRating($ratingData);

        if ($result) {
            // Recalculate area safety score after rating
            $newScore = $models->calculateAreaSafetyScore($areaId);

            $message = $existingRating ? 'Rating updated successfully!' : 'Thank you for rating this area!';
            if ($newScore !== false) {
                $message .= ' Safety score updated to ' . number_format($newScore, 1) . '/10.';
            }

            $existingRating = $models->getUserAreaRating($userId, $areaId);
            $area = $models->getAreaSafetyScoreById($areaId); // Refresh area data
        } else {
            throw new Exception('Failed to submit rating. Please try again.');
        }
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="<?= $lang ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Rate Area - <?= htmlspecialchars($area['area_name']) ?></title>

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
                    <a href="area_detail.php?id=<?= $areaId ?>" class="btn btn-ghost">
                        <i data-lucide="arrow-left" class="w-4 h-4"></i>
                        Back
                    </a>
                </div>
            </div>
        </div>
    </header>

    <main class="pt-20 pb-8">
        <div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8">
            <section class="mb-8 animate-fade-in">
                <div class="text-center">
                    <div class="w-20 h-20 bg-gradient-to-r from-green-500 via-emerald-500 to-teal-500 rounded-3xl flex items-center justify-center mx-auto mb-6 shadow-2xl ring-4 ring-green-500/20">
                        <i data-lucide="star" class="w-10 h-10 text-white drop-shadow-lg"></i>
                    </div>
                    <h1 class="heading-1 mb-4 text-white">Rate Area Safety</h1>
                    <p class="body-large max-w-2xl mx-auto text-white/80 leading-relaxed">
                        Share your experience and help improve safety information for <?= htmlspecialchars($area['area_name']) ?>
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

            <div class="card card-glass p-8">
                <div class="mb-6 p-4 bg-white/5 rounded-lg border border-white/10">
                    <h3 class="heading-3 text-white mb-2"><?= htmlspecialchars($area['area_name']) ?></h3>
                    <p class="text-white/60">
                        <?= htmlspecialchars($area['district']) ?><?= $area['upazila'] ? ', ' . htmlspecialchars($area['upazila']) : '' ?>
                        <?= $area['ward_number'] ? ' • Ward ' . htmlspecialchars($area['ward_number']) : '' ?>
                    </p>
                    <div class="mt-3">
                        <span class="text-sm text-white/70">Current Safety Score: </span>
                        <span class="text-2xl font-bold bg-gradient-to-r from-green-500 to-emerald-500 bg-clip-text text-transparent">
                            <?= number_format($area['safety_score'], 1) ?>/10
                        </span>
                    </div>
                </div>

                <form method="POST" class="space-y-6">
                    <input type="hidden" name="action" value="submit_rating">

                    <div>
                        <label class="form-label text-white mb-4 block">Overall Safety Rating <span class="text-red-400">*</span></label>
                        <div class="flex items-center justify-center space-x-2" id="rating-stars">
                            <?php
                            $currentRating = $existingRating ? $existingRating['safety_rating'] : 0;
                            for ($i = 1; $i <= 5; $i++):
                            ?>
                                <i data-lucide="star"
                                   class="w-12 h-12 cursor-pointer transition-all duration-200 rating-star <?= $i <= $currentRating ? 'text-yellow-400 fill-yellow-400' : 'text-white/30' ?>"
                                   data-rating="<?= $i ?>"></i>
                            <?php endfor; ?>
                        </div>
                        <input type="hidden" name="safety_rating" id="safety_rating_input" value="<?= $currentRating ?>" required>
                        <p class="text-center text-white/60 text-sm mt-2" id="rating-label">
                            <?= $currentRating > 0 ? ['', 'Very Unsafe', 'Unsafe', 'Moderate', 'Safe', 'Very Safe'][$currentRating] : 'Click stars to rate' ?>
                        </p>
                    </div>

                    <div>
                        <label class="form-label text-white mb-3 block">Safety Factors</label>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div>
                                <label class="text-white/80 text-sm mb-2 block">Street Lighting</label>
                                <select name="lighting" class="form-input w-full">
                                    <option value="">Not Specified</option>
                                    <option value="excellent" <?= ($existingRating && isset(json_decode($existingRating['factors'], true)['lighting']) && json_decode($existingRating['factors'], true)['lighting'] === 'excellent') ? 'selected' : '' ?>>Excellent</option>
                                    <option value="good" <?= ($existingRating && isset(json_decode($existingRating['factors'], true)['lighting']) && json_decode($existingRating['factors'], true)['lighting'] === 'good') ? 'selected' : '' ?>>Good</option>
                                    <option value="moderate" <?= ($existingRating && isset(json_decode($existingRating['factors'], true)['lighting']) && json_decode($existingRating['factors'], true)['lighting'] === 'moderate') ? 'selected' : '' ?>>Moderate</option>
                                    <option value="poor" <?= ($existingRating && isset(json_decode($existingRating['factors'], true)['lighting']) && json_decode($existingRating['factors'], true)['lighting'] === 'poor') ? 'selected' : '' ?>>Poor</option>
                                </select>
                            </div>

                            <div>
                                <label class="text-white/80 text-sm mb-2 block">Police Presence</label>
                                <select name="police_presence" class="form-input w-full">
                                    <option value="">Not Specified</option>
                                    <option value="excellent" <?= ($existingRating && isset(json_decode($existingRating['factors'], true)['police_presence']) && json_decode($existingRating['factors'], true)['police_presence'] === 'excellent') ? 'selected' : '' ?>>Excellent</option>
                                    <option value="good" <?= ($existingRating && isset(json_decode($existingRating['factors'], true)['police_presence']) && json_decode($existingRating['factors'], true)['police_presence'] === 'good') ? 'selected' : '' ?>>Good</option>
                                    <option value="moderate" <?= ($existingRating && isset(json_decode($existingRating['factors'], true)['police_presence']) && json_decode($existingRating['factors'], true)['police_presence'] === 'moderate') ? 'selected' : '' ?>>Moderate</option>
                                    <option value="poor" <?= ($existingRating && isset(json_decode($existingRating['factors'], true)['police_presence']) && json_decode($existingRating['factors'], true)['police_presence'] === 'poor') ? 'selected' : '' ?>>Poor</option>
                                </select>
                            </div>

                            <div>
                                <label class="text-white/80 text-sm mb-2 block">Traffic Safety</label>
                                <select name="traffic" class="form-input w-full">
                                    <option value="">Not Specified</option>
                                    <option value="excellent">Excellent</option>
                                    <option value="good">Good</option>
                                    <option value="moderate">Moderate</option>
                                    <option value="poor">Poor</option>
                                </select>
                            </div>

                            <div>
                                <label class="text-white/80 text-sm mb-2 block">Public Transport</label>
                                <select name="public_transport" class="form-input w-full">
                                    <option value="">Not Specified</option>
                                    <option value="excellent">Excellent</option>
                                    <option value="good">Good</option>
                                    <option value="moderate">Moderate</option>
                                    <option value="poor">Poor</option>
                                </select>
                            </div>

                            <div>
                                <label class="text-white/80 text-sm mb-2 block">Street Condition</label>
                                <select name="street_condition" class="form-input w-full">
                                    <option value="">Not Specified</option>
                                    <option value="excellent">Excellent</option>
                                    <option value="good">Good</option>
                                    <option value="moderate">Moderate</option>
                                    <option value="poor">Poor</option>
                                </select>
                            </div>
                        </div>
                    </div>

                    <div>
                        <label class="form-label text-white mb-2">Comments (Optional)</label>
                        <textarea name="comments" rows="4" class="form-input w-full" placeholder="Share your experience and observations about safety in this area..."><?= $existingRating ? htmlspecialchars($existingRating['comments']) : '' ?></textarea>
                    </div>

                    <div class="flex items-center space-x-2 p-4 bg-blue-500/10 border border-blue-500/30 rounded-lg">
                        <input type="checkbox" name="is_verified_resident" id="is_verified_resident" value="1"
                               <?= ($existingRating && $existingRating['is_verified_resident']) ? 'checked' : '' ?>
                               class="w-4 h-4 rounded">
                        <label for="is_verified_resident" class="text-white/80 text-sm">
                            I am a verified resident of this area (your rating will be given more weight)
                        </label>
                    </div>

                    <div class="flex items-center space-x-4 pt-4">
                        <button type="submit" class="btn btn-primary flex-1">
                            <i data-lucide="send" class="w-4 h-4 mr-2"></i>
                            <?= $existingRating ? 'Update Rating' : 'Submit Rating' ?>
                        </button>
                        <a href="area_detail.php?id=<?= $areaId ?>" class="btn btn-outline">
                            Cancel
                        </a>
                    </div>
                </form>
            </div>
        </div>
    </main>

    <script src="https://unpkg.com/lucide@latest/dist/umd/lucide.js"></script>
    <script src="dashboard-enhanced.js"></script>
    <script>
        lucide.createIcons();

        const ratingLabels = ['', 'Very Unsafe', 'Unsafe', 'Moderate', 'Safe', 'Very Safe'];
        const stars = document.querySelectorAll('.rating-star');
        const ratingInput = document.getElementById('safety_rating_input');
        const ratingLabel = document.getElementById('rating-label');

        stars.forEach(star => {
            star.addEventListener('click', function() {
                const rating = parseInt(this.dataset.rating);
                ratingInput.value = rating;
                ratingLabel.textContent = ratingLabels[rating];

                stars.forEach((s, index) => {
                    if (index < rating) {
                        s.classList.remove('text-white/30');
                        s.classList.add('text-yellow-400', 'fill-yellow-400');
                    } else {
                        s.classList.remove('text-yellow-400', 'fill-yellow-400');
                        s.classList.add('text-white/30');
                    }
                });
            });

            star.addEventListener('mouseenter', function() {
                const rating = parseInt(this.dataset.rating);
                stars.forEach((s, index) => {
                    if (index < rating) {
                        s.classList.add('text-yellow-400');
                    }
                });
            });

            star.addEventListener('mouseleave', function() {
                const currentRating = parseInt(ratingInput.value) || 0;
                stars.forEach((s, index) => {
                    if (index < currentRating) {
                        s.classList.add('text-yellow-400', 'fill-yellow-400');
                    } else {
                        s.classList.remove('text-yellow-400', 'fill-yellow-400');
                        s.classList.add('text-white/30');
                    }
                });
            });
        });
    </script>
</body>
</html>

