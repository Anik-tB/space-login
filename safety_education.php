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
    'category' => $_GET['category'] ?? '',
    'target_audience' => $_GET['target_audience'] ?? '',
    'language' => $_GET['language'] ?? '',
    'is_premium' => $_GET['is_premium'] ?? '',
    'limit' => $_GET['limit'] ?? 50
];

// Search functionality
$searchTerm = $_GET['search'] ?? '';
$courses = [];

if (!empty($searchTerm)) {
    $courses = $models->searchSafetyCourses($searchTerm);
} else {
    $courses = $models->getSafetyCourses($filters);
}

// Get user enrollments to show enrollment status
$userEnrollments = $models->getUserEnrollments($userId);
$enrolledCourseIds = array_column($userEnrollments, 'course_id');

// Calculate statistics
$totalCourses = count($courses);
$freeCourses = count(array_filter($courses, fn($c) => !$c['is_premium']));
$premiumCourses = count(array_filter($courses, fn($c) => $c['is_premium']));
$avgRating = $totalCourses > 0 ? array_sum(array_filter(array_column($courses, 'average_rating'), fn($r) => $r > 0)) / max(1, count(array_filter(array_column($courses, 'average_rating'), fn($r) => $r > 0))) : 0;
$totalEnrollments = array_sum(array_column($courses, 'enrollment_count'));

// Message handling
$message = '';
$error = '';
if (isset($_GET['success'])) {
    $message = 'Successfully enrolled in the course!';
}
if (isset($_GET['error'])) {
    $error = 'Failed to enroll. Please try again.';
}
?>
<!DOCTYPE html>
<html lang="<?= $lang ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Safety Education & Training - SafeSpace Portal</title>

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
                    <a href="my_training.php" class="text-white/70 hover:text-white transition-colors duration-200">My Training</a>
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
                    <div class="w-20 h-20 bg-gradient-to-r from-indigo-500 via-purple-500 to-pink-500 rounded-3xl flex items-center justify-center mx-auto mb-6 shadow-2xl ring-4 ring-indigo-500/20">
                        <i data-lucide="graduation-cap" class="w-10 h-10 text-white drop-shadow-lg"></i>
                    </div>
                    <h1 class="heading-1 mb-4 text-white">Safety Education & Training</h1>
                    <p class="body-large max-w-2xl mx-auto text-white/80 leading-relaxed">
                        Learn essential safety skills, legal rights, and self-defense techniques. Complete courses and earn certificates.
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
                                <p class="text-white/60 text-sm mb-1">Total Courses</p>
                                <p class="heading-2 text-white"><?= $totalCourses ?></p>
                            </div>
                            <div class="w-12 h-12 bg-gradient-to-r from-indigo-500 to-purple-500 rounded-lg flex items-center justify-center">
                                <i data-lucide="book-open" class="w-6 h-6 text-white"></i>
                            </div>
                        </div>
                    </div>
                    <div class="card card-glass p-5">
                        <div class="flex items-center justify-between">
                            <div>
                                <p class="text-white/60 text-sm mb-1">Free Courses</p>
                                <p class="heading-2 text-white"><?= $freeCourses ?></p>
                            </div>
                            <div class="w-12 h-12 bg-gradient-to-r from-green-500 to-emerald-500 rounded-lg flex items-center justify-center">
                                <i data-lucide="gift" class="w-6 h-6 text-white"></i>
                            </div>
                        </div>
                    </div>
                    <div class="card card-glass p-5">
                        <div class="flex items-center justify-between">
                            <div>
                                <p class="text-white/60 text-sm mb-1">Premium</p>
                                <p class="heading-2 text-white"><?= $premiumCourses ?></p>
                            </div>
                            <div class="w-12 h-12 bg-gradient-to-r from-yellow-500 to-orange-500 rounded-lg flex items-center justify-center">
                                <i data-lucide="star" class="w-6 h-6 text-white"></i>
                            </div>
                        </div>
                    </div>
                    <div class="card card-glass p-5">
                        <div class="flex items-center justify-between">
                            <div>
                                <p class="text-white/60 text-sm mb-1">Total Enrollments</p>
                                <p class="heading-2 text-white"><?= $totalEnrollments ?></p>
                            </div>
                            <div class="w-12 h-12 bg-gradient-to-r from-blue-500 to-cyan-500 rounded-lg flex items-center justify-center">
                                <i data-lucide="users" class="w-6 h-6 text-white"></i>
                            </div>
                        </div>
                    </div>
                    <div class="card card-glass p-5">
                        <div class="flex items-center justify-between">
                            <div>
                                <p class="text-white/60 text-sm mb-1">Avg Rating</p>
                                <p class="heading-2 text-white"><?= number_format($avgRating, 1) ?></p>
                            </div>
                            <div class="w-12 h-12 bg-gradient-to-r from-pink-500 to-rose-500 rounded-lg flex items-center justify-center">
                                <i data-lucide="star" class="w-6 h-6 text-white"></i>
                            </div>
                        </div>
                    </div>
                </div>
            </section>

            <!-- Quick Links -->
            <section class="mb-8 grid grid-cols-1 md:grid-cols-2 gap-4">
                <a href="my_training.php" class="card card-glass card-hover text-center p-6">
                    <div class="w-12 h-12 bg-gradient-to-r from-indigo-500 to-purple-600 rounded-xl flex items-center justify-center mx-auto mb-4">
                        <i data-lucide="user-check" class="w-6 h-6 text-white"></i>
                    </div>
                    <h3 class="heading-4 text-white mb-2">My Training</h3>
                    <p class="text-sm text-white/60">View your enrolled courses and progress</p>
                </a>
                <a href="verify_certificate.php" class="card card-glass card-hover text-center p-6">
                    <div class="w-12 h-12 bg-gradient-to-r from-yellow-500 to-amber-600 rounded-xl flex items-center justify-center mx-auto mb-4">
                        <i data-lucide="shield-check" class="w-6 h-6 text-white"></i>
                    </div>
                    <h3 class="heading-4 text-white mb-2">Verify Certificate</h3>
                    <p class="text-sm text-white/60">Verify certificate authenticity</p>
                </a>
            </section>

            <!-- Search and Filters -->
            <section class="mb-8">
                <div class="card card-glass p-6">
                    <form method="GET" class="space-y-4">
                        <div class="flex flex-col md:flex-row gap-4">
                            <div class="flex-1">
                                <input type="text" name="search" value="<?= htmlspecialchars($searchTerm) ?>"
                                       placeholder="Search courses..."
                                       class="form-input w-full">
                            </div>
                            <button type="submit" class="btn btn-primary">
                                <i data-lucide="search" class="w-4 h-4 mr-2"></i>
                                Search
                            </button>
                        </div>

                        <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
                            <div>
                                <label class="form-label text-white text-sm mb-2">Category</label>
                                <select name="category" class="form-input text-sm" onchange="this.form.submit()">
                                    <option value="">All Categories</option>
                                    <option value="self_defense" <?= $filters['category'] === 'self_defense' ? 'selected' : '' ?>>Self Defense</option>
                                    <option value="cyber_safety" <?= $filters['category'] === 'cyber_safety' ? 'selected' : '' ?>>Cyber Safety</option>
                                    <option value="legal_rights" <?= $filters['category'] === 'legal_rights' ? 'selected' : '' ?>>Legal Rights</option>
                                    <option value="emergency_response" <?= $filters['category'] === 'emergency_response' ? 'selected' : '' ?>>Emergency Response</option>
                                    <option value="prevention" <?= $filters['category'] === 'prevention' ? 'selected' : '' ?>>Prevention</option>
                                    <option value="awareness" <?= $filters['category'] === 'awareness' ? 'selected' : '' ?>>Awareness</option>
                                </select>
                            </div>

                            <div>
                                <label class="form-label text-white text-sm mb-2">Target Audience</label>
                                <select name="target_audience" class="form-input text-sm" onchange="this.form.submit()">
                                    <option value="">All Audiences</option>
                                    <option value="women" <?= $filters['target_audience'] === 'women' ? 'selected' : '' ?>>Women</option>
                                    <option value="children" <?= $filters['target_audience'] === 'children' ? 'selected' : '' ?>>Children</option>
                                    <option value="elderly" <?= $filters['target_audience'] === 'elderly' ? 'selected' : '' ?>>Elderly</option>
                                    <option value="general" <?= $filters['target_audience'] === 'general' ? 'selected' : '' ?>>General</option>
                                    <option value="professionals" <?= $filters['target_audience'] === 'professionals' ? 'selected' : '' ?>>Professionals</option>
                                </select>
                            </div>

                            <div>
                                <label class="form-label text-white text-sm mb-2">Language</label>
                                <select name="language" class="form-input text-sm" onchange="this.form.submit()">
                                    <option value="">All Languages</option>
                                    <option value="bn" <?= $filters['language'] === 'bn' ? 'selected' : '' ?>>Bengali</option>
                                    <option value="en" <?= $filters['language'] === 'en' ? 'selected' : '' ?>>English</option>
                                    <option value="both" <?= $filters['language'] === 'both' ? 'selected' : '' ?>>Both</option>
                                </select>
                            </div>

                            <div>
                                <label class="form-label text-white text-sm mb-2">Type</label>
                                <select name="is_premium" class="form-input text-sm" onchange="this.form.submit()">
                                    <option value="">All Courses</option>
                                    <option value="0" <?= $filters['is_premium'] === '0' ? 'selected' : '' ?>>Free</option>
                                    <option value="1" <?= $filters['is_premium'] === '1' ? 'selected' : '' ?>>Premium</option>
                                </select>
                            </div>
                        </div>

                        <?php if (!empty($searchTerm) || !empty(array_filter($filters))): ?>
                            <a href="safety_education.php" class="text-sm text-purple-400 hover:text-purple-300">
                                <i data-lucide="x" class="w-4 h-4 inline mr-1"></i>
                                Clear Filters
                            </a>
                        <?php endif; ?>
                    </form>
                </div>
            </section>

            <!-- Courses List -->
            <section>
                <div class="mb-6 flex items-center justify-between">
                    <div>
                        <h2 class="heading-2 text-white">Available Courses (<?= count($courses) ?>)</h2>
                        <?php if (!empty($searchTerm) || !empty(array_filter($filters))): ?>
                            <p class="text-white/60 text-sm mt-1">Filtered results</p>
                        <?php endif; ?>
                    </div>
                    <?php if (!empty($courses)): ?>
                        <div class="flex items-center space-x-2">
                            <span class="text-sm text-white/60">Sort:</span>
                            <select class="form-input text-sm" onchange="window.location.href='safety_education.php?sort=' + this.value">
                                <option value="popular">Most Popular</option>
                                <option value="rating">Highest Rated</option>
                                <option value="newest">Newest First</option>
                                <option value="free">Free First</option>
                            </select>
                        </div>
                    <?php endif; ?>
                </div>

                <?php if (empty($courses)): ?>
                    <div class="card card-glass text-center p-12">
                        <i data-lucide="book-open" class="w-16 h-16 text-white/30 mx-auto mb-4"></i>
                        <h3 class="heading-3 text-white mb-3">No Courses Found</h3>
                        <p class="text-white/60 mb-6">Try adjusting your search or filters.</p>
                    </div>
                <?php else: ?>
                    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                        <?php foreach ($courses as $course):
                            $categoryColors = [
                                'self_defense' => 'from-red-500 to-rose-500',
                                'cyber_safety' => 'from-blue-500 to-cyan-500',
                                'legal_rights' => 'from-purple-500 to-indigo-500',
                                'emergency_response' => 'from-orange-500 to-amber-500',
                                'prevention' => 'from-green-500 to-emerald-500',
                                'awareness' => 'from-pink-500 to-rose-500'
                            ];
                            $categoryColor = $categoryColors[$course['category']] ?? 'from-gray-500 to-gray-600';
                            $isEnrolled = in_array($course['id'], $enrolledCourseIds);
                            $enrollment = $isEnrolled ? array_filter($userEnrollments, fn($e) => $e['course_id'] == $course['id'])[0] ?? null : null;
                        ?>
                            <div class="card card-glass p-5 hover:scale-105 transition-transform duration-200">
                                <div class="flex items-start justify-between mb-4">
                                    <div class="flex-1">
                                        <div class="flex items-center space-x-2 mb-2">
                                            <div class="w-12 h-12 bg-gradient-to-r <?= $categoryColor ?> rounded-lg flex items-center justify-center flex-shrink-0">
                                                <i data-lucide="book" class="w-6 h-6 text-white"></i>
                                            </div>
                                            <div class="flex-1 min-w-0">
                                                <h3 class="heading-4 text-white mb-1 line-clamp-1"><?= htmlspecialchars($course['course_title']) ?></h3>
                                                <p class="text-xs text-white/60 capitalize"><?= str_replace('_', ' ', $course['category']) ?></p>
                                            </div>
                                            <?php if ($course['is_premium']): ?>
                                                <span class="px-2 py-1 rounded-full text-xs font-semibold bg-yellow-500/20 text-yellow-300 border border-yellow-500/30 flex-shrink-0">
                                                    Premium
                                                </span>
                                            <?php endif; ?>
                                        </div>

                                        <p class="text-sm text-white/70 mb-3 line-clamp-2">
                                            <?= htmlspecialchars($course['course_description'] ?? 'No description available.') ?>
                                        </p>

                                        <div class="space-y-2 text-sm text-white/60 mb-4">
                                            <?php if ($course['instructor_name']): ?>
                                                <p class="flex items-center">
                                                    <i data-lucide="user" class="w-4 h-4 mr-2 text-blue-400"></i>
                                                    <?= htmlspecialchars($course['instructor_name']) ?>
                                                </p>
                                            <?php endif; ?>
                                            <?php if ($course['duration_minutes']): ?>
                                                <p class="flex items-center">
                                                    <i data-lucide="clock" class="w-4 h-4 mr-2 text-green-400"></i>
                                                    <?= $course['duration_minutes'] ?> minutes
                                                </p>
                                            <?php endif; ?>
                                            <p class="flex items-center">
                                                <i data-lucide="users" class="w-4 h-4 mr-2 text-purple-400"></i>
                                                <?= $course['enrollment_count'] ?> enrolled
                                            </p>
                                        </div>

                                        <div class="flex items-center justify-between mb-4">
                                            <div class="flex items-center space-x-2">
                                                <?php if ($course['average_rating'] > 0): ?>
                                                    <div class="flex items-center">
                                                        <?php for ($i = 1; $i <= 5; $i++): ?>
                                                            <i data-lucide="star" class="w-4 h-4 <?= $i <= round($course['average_rating']) ? 'text-yellow-400 fill-yellow-400' : 'text-white/20' ?>"></i>
                                                        <?php endfor; ?>
                                                    </div>
                                                    <span class="text-sm text-white/60"><?= number_format($course['average_rating'], 1) ?> (<?= $course['rating_count'] ?>)</span>
                                                <?php else: ?>
                                                    <span class="text-sm text-white/50">No ratings yet</span>
                                                <?php endif; ?>
                                            </div>
                                            <div class="flex items-center space-x-2">
                                                <span class="px-2 py-1 rounded-full text-xs font-semibold bg-<?= $course['language'] === 'bn' ? 'green' : ($course['language'] === 'en' ? 'blue' : 'purple') ?>-500/20 text-<?= $course['language'] === 'bn' ? 'green' : ($course['language'] === 'en' ? 'blue' : 'purple') ?>-300 border border-<?= $course['language'] === 'bn' ? 'green' : ($course['language'] === 'en' ? 'blue' : 'purple') ?>-500/30">
                                                    <?= strtoupper($course['language']) ?>
                                                </span>
                                                <?php if ($course['completion_count'] > 0): ?>
                                                    <span class="text-xs text-white/50">
                                                        <?= $course['completion_count'] ?> completed
                                                    </span>
                                                <?php endif; ?>
                                            </div>
                                        </div>

                                        <?php if ($isEnrolled && $enrollment): ?>
                                            <div class="mb-4 p-3 bg-blue-500/10 border border-blue-500/30 rounded-lg">
                                                <div class="flex items-center justify-between mb-2">
                                                    <span class="text-sm text-white/80">Progress</span>
                                                    <span class="text-sm font-semibold text-blue-400"><?= number_format($enrollment['progress_percentage'], 0) ?>%</span>
                                                </div>
                                                <div class="w-full bg-white/10 rounded-full h-2 overflow-hidden">
                                                    <div class="h-full bg-gradient-to-r from-blue-500 to-cyan-500 transition-all duration-500"
                                                         style="width: <?= $enrollment['progress_percentage'] ?>%"></div>
                                                </div>
                                                <p class="text-xs text-white/60 mt-1 capitalize">Status: <?= str_replace('_', ' ', $enrollment['status']) ?></p>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </div>

                                <div class="flex items-center space-x-2 pt-4 border-t border-white/10">
                                    <a href="module_detail.php?id=<?= $course['id'] ?>" class="btn btn-outline flex-1">
                                        <i data-lucide="eye" class="w-4 h-4 mr-2"></i>
                                        View Details
                                    </a>
                                    <?php if ($isEnrolled): ?>
                                        <a href="module_detail.php?id=<?= $course['id'] ?>" class="btn btn-primary flex-1">
                                            <i data-lucide="play" class="w-4 h-4 mr-2"></i>
                                            Continue
                                        </a>
                                    <?php else: ?>
                                        <form method="POST" action="training_handler.php" class="flex-1">
                                            <input type="hidden" name="action" value="enroll">
                                            <input type="hidden" name="course_id" value="<?= $course['id'] ?>">
                                            <button type="submit" class="btn btn-primary w-full">
                                                <i data-lucide="book-open" class="w-4 h-4 mr-2"></i>
                                                Enroll
                                            </button>
                                        </form>
                                    <?php endif; ?>
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

