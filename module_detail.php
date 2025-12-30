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

$courseId = $_GET['id'] ?? null;

if (!$courseId) {
    header('Location: safety_education.php');
    exit;
}

$course = $models->getSafetyCourseById($courseId);

if (!$course || $course['status'] !== 'active') {
    header('Location: safety_education.php?error=Course not found');
    exit;
}

// Get user enrollment
$enrollment = $models->getEnrollmentByUserAndCourse($userId, $courseId);

// Handle enrollment
$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'enroll') {
    $enrollmentId = $models->enrollInCourse($userId, $courseId);

    if ($enrollmentId) {
        $message = 'Successfully enrolled in the course!';
        $enrollment = $models->getEnrollmentByUserAndCourse($userId, $courseId);
    } else {
        $error = 'Failed to enroll. Please try again.';
    }
}

// Handle progress update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_progress') {
    $progress = floatval($_POST['progress'] ?? 0);
    $status = $_POST['status'] ?? null;

    if ($enrollment && $progress >= 0 && $progress <= 100) {
        $newStatus = $status;
        if ($progress >= 100 && !$newStatus) {
            $newStatus = 'completed';
        } elseif ($progress > 0 && !$newStatus) {
            $newStatus = 'in_progress';
        }

        $result = $models->updateEnrollmentProgress($enrollment['id'], $progress, $newStatus);

        // Issue certificate if completed
        if ($result && $newStatus === 'completed' && !$enrollment['certificate_issued']) {
            $models->issueCertificate($userId, $courseId, $enrollment['id']);
        }

        if ($result) {
            $message = 'Progress updated successfully!';
            $enrollment = $models->getEnrollmentByUserAndCourse($userId, $courseId);
        } else {
            $error = 'Failed to update progress.';
        }
    }
}

// Handle rating submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'submit_rating') {
    $rating = intval($_POST['rating'] ?? 0);
    $feedback = trim($_POST['feedback'] ?? '');

    if ($enrollment && $rating >= 1 && $rating <= 5) {
        $result = $models->updateEnrollmentRating($enrollment['id'], $rating, $feedback);

        if ($result) {
            $message = 'Thank you for your feedback!';
            $course = $models->getSafetyCourseById($courseId); // Refresh course data
            $enrollment = $models->getEnrollmentByUserAndCourse($userId, $courseId);
        } else {
            $error = 'Failed to submit rating.';
        }
    }
}

$categoryColors = [
    'self_defense' => 'from-red-500 to-rose-500',
    'cyber_safety' => 'from-blue-500 to-cyan-500',
    'legal_rights' => 'from-purple-500 to-indigo-500',
    'emergency_response' => 'from-orange-500 to-amber-500',
    'prevention' => 'from-green-500 to-emerald-500',
    'awareness' => 'from-pink-500 to-rose-500'
];
$categoryColor = $categoryColors[$course['category']] ?? 'from-gray-500 to-gray-600';
?>
<!DOCTYPE html>
<html lang="<?= $lang ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($course['course_title']) ?> - Safety Education</title>

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
                    <a href="safety_education.php" class="text-white/70 hover:text-white transition-colors duration-200">Safety Education</a>
                    <a href="my_training.php" class="text-white/70 hover:text-white transition-colors duration-200">My Training</a>
                </nav>
                <div class="flex items-center space-x-4">
                    <a href="safety_education.php" class="btn btn-ghost">
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

            <section class="mb-6">
                <div class="card card-glass p-6">
                    <div class="flex items-start justify-between mb-4">
                        <div class="flex-1">
                            <div class="flex items-center space-x-3 mb-3">
                                <div class="w-16 h-16 bg-gradient-to-r <?= $categoryColor ?> rounded-2xl flex items-center justify-center">
                                    <i data-lucide="book" class="w-8 h-8 text-white"></i>
                                </div>
                                <div>
                                    <h1 class="heading-1 text-white"><?= htmlspecialchars($course['course_title']) ?></h1>
                                    <p class="text-white/60 capitalize"><?= str_replace('_', ' ', $course['category']) ?></p>
                                </div>
                                <?php if ($course['is_premium']): ?>
                                    <span class="px-3 py-1 rounded-full text-sm font-semibold bg-yellow-500/20 text-yellow-300 border border-yellow-500/30">
                                        Premium
                                    </span>
                                <?php endif; ?>
                            </div>

                            <p class="text-white/80 mb-4 leading-relaxed line-clamp-3">
                                <?= htmlspecialchars($course['course_description'] ?? 'No description available.') ?>
                            </p>

                            <div class="flex flex-wrap items-center gap-3 text-sm text-white/70 mb-4">
                                <?php if ($course['instructor_name']): ?>
                                    <span class="flex items-center">
                                        <i data-lucide="user" class="w-4 h-4 mr-1.5 text-blue-400"></i>
                                        <?= htmlspecialchars($course['instructor_name']) ?>
                                    </span>
                                <?php endif; ?>
                                <?php if ($course['duration_minutes']): ?>
                                    <span class="flex items-center">
                                        <i data-lucide="clock" class="w-4 h-4 mr-1.5 text-green-400"></i>
                                        <?= $course['duration_minutes'] ?> minutes
                                    </span>
                                <?php endif; ?>
                                <span class="flex items-center">
                                    <i data-lucide="users" class="w-4 h-4 mr-1.5 text-purple-400"></i>
                                    <?= $course['enrollment_count'] ?> enrolled
                                </span>
                                <?php if ($course['average_rating'] > 0): ?>
                                    <span class="flex items-center">
                                        <i data-lucide="star" class="w-4 h-4 mr-1.5 text-yellow-400"></i>
                                        <?= number_format($course['average_rating'], 1) ?> (<?= $course['rating_count'] ?>)
                                    </span>
                                <?php endif; ?>
                                <span class="px-2 py-1 rounded-full text-xs font-semibold bg-<?= $course['language'] === 'bn' ? 'green' : ($course['language'] === 'en' ? 'blue' : 'purple') ?>-500/20 text-<?= $course['language'] === 'bn' ? 'green' : ($course['language'] === 'en' ? 'blue' : 'purple') ?>-300 border border-<?= $course['language'] === 'bn' ? 'green' : ($course['language'] === 'en' ? 'blue' : 'purple') ?>-500/30">
                                    <?= strtoupper($course['language']) ?>
                                </span>
                            </div>

                            <!-- Collapsible Details Section -->
                            <div class="mt-4">
                                <button onclick="toggleDetails()" class="flex items-center justify-between w-full p-3 bg-white/5 hover:bg-white/8 rounded-lg transition-colors">
                                    <span class="text-sm font-semibold text-white flex items-center">
                                        <i data-lucide="info" class="w-4 h-4 mr-2 text-blue-400"></i>
                                        Course Details
                                    </span>
                                    <i data-lucide="chevron-down" id="detailsChevron" class="w-4 h-4 text-white/60 transition-transform"></i>
                                </button>
                                <div id="detailsContent" class="hidden mt-3 space-y-3">
                                    <div class="p-4 bg-white/5 rounded-lg">
                                        <p class="text-white/80 text-sm leading-relaxed">
                                            <?= nl2br(htmlspecialchars($course['course_description'] ?? 'No description available.')) ?>
                                        </p>
                                    </div>
                                    <div class="grid grid-cols-2 md:grid-cols-4 gap-4 p-4 bg-white/5 rounded-lg">
                                        <?php if ($course['instructor_name']): ?>
                                            <div>
                                                <p class="text-xs text-white/50 mb-1">Instructor</p>
                                                <p class="text-sm text-white font-medium"><?= htmlspecialchars($course['instructor_name']) ?></p>
                                            </div>
                                        <?php endif; ?>
                                        <?php if ($course['duration_minutes']): ?>
                                            <div>
                                                <p class="text-xs text-white/50 mb-1">Duration</p>
                                                <p class="text-sm text-white font-medium"><?= $course['duration_minutes'] ?> min</p>
                                            </div>
                                        <?php endif; ?>
                                        <div>
                                            <p class="text-xs text-white/50 mb-1">Enrolled</p>
                                            <p class="text-sm text-white font-medium"><?= $course['enrollment_count'] ?></p>
                                        </div>
                                        <div>
                                            <p class="text-xs text-white/50 mb-1">Completed</p>
                                            <p class="text-sm text-white font-medium"><?= $course['completion_count'] ?></p>
                                        </div>
                                        <?php if ($course['target_audience']): ?>
                                            <div>
                                                <p class="text-xs text-white/50 mb-1">Target Audience</p>
                                                <p class="text-sm text-white font-medium capitalize"><?= str_replace(',', ', ', $course['target_audience']) ?></p>
                                            </div>
                                        <?php endif; ?>
                                        <div>
                                            <p class="text-xs text-white/50 mb-1">Content Type</p>
                                            <p class="text-sm text-white font-medium capitalize"><?= str_replace('_', ' ', $course['content_type']) ?></p>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <?php if ($enrollment): ?>
                                <div class="mt-4 p-5 bg-blue-500/10 border border-blue-500/30 rounded-lg">
                                    <div class="flex items-center justify-between mb-3">
                                        <h3 class="heading-3 text-white">Your Progress</h3>
                                        <span class="text-2xl font-bold text-blue-400"><?= number_format($enrollment['progress_percentage'], 0) ?>%</span>
                                    </div>
                                    <div class="w-full bg-white/10 rounded-full h-3 overflow-hidden mb-3">
                                        <div class="h-full bg-gradient-to-r from-blue-500 to-cyan-500 transition-all duration-500"
                                             style="width: <?= $enrollment['progress_percentage'] ?>%"></div>
                                    </div>
                                    <div class="flex items-center justify-between text-sm text-white/60">
                                        <span class="capitalize">Status: <?= str_replace('_', ' ', $enrollment['status']) ?></span>
                                        <span>Started: <?= date('M j, Y', strtotime($enrollment['started_at'])) ?></span>
                                    </div>
                                    <?php if ($enrollment['status'] === 'completed' && $enrollment['certificate_issued']): ?>
                                        <div class="mt-4 p-4 bg-green-500/10 border border-green-500/30 rounded-lg">
                                            <div class="flex items-center justify-between">
                                                <div>
                                                    <p class="text-green-300 font-semibold mb-1 flex items-center">
                                                        <i data-lucide="award" class="w-4 h-4 mr-2"></i>
                                                        Certificate Issued!
                                                    </p>
                                                    <p class="text-sm text-white/70">Certificate #<?= htmlspecialchars($enrollment['certificate_id']) ?></p>
                                                </div>
                                                <a href="my_training.php" class="btn btn-sm btn-primary">
                                                    <i data-lucide="award" class="w-4 h-4 mr-2"></i>
                                                    View
                                                </a>
                                            </div>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="flex items-center space-x-4 pt-4 border-t border-white/10">
                        <?php if ($enrollment): ?>
                            <?php if ($enrollment['status'] !== 'completed'): ?>
                                <form method="POST" class="flex-1">
                                    <input type="hidden" name="action" value="update_progress">
                                    <input type="hidden" name="enrollment_id" value="<?= $enrollment['id'] ?>">
                                    <input type="hidden" name="progress" id="progress_input" value="<?= $enrollment['progress_percentage'] ?>">
                                    <div class="flex items-center space-x-4">
                                        <div class="flex-1">
                                            <label class="text-white/80 text-sm mb-2 block">Update Progress</label>
                                            <input type="range" min="0" max="100" value="<?= $enrollment['progress_percentage'] ?>"
                                                   class="w-full" id="progress_slider"
                                                   onchange="document.getElementById('progress_input').value = this.value">
                                        </div>
                                        <button type="submit" class="btn btn-primary">
                                            <i data-lucide="save" class="w-4 h-4 mr-2"></i>
                                            Save Progress
                                        </button>
                                    </div>
                                </form>
                            <?php else: ?>
                                <a href="my_training.php" class="btn btn-primary flex-1">
                                    <i data-lucide="award" class="w-4 h-4 mr-2"></i>
                                    View Certificate
                                </a>
                            <?php endif; ?>
                        <?php else: ?>
                            <form method="POST" class="flex-1">
                                <input type="hidden" name="action" value="enroll">
                                <button type="submit" class="btn btn-primary w-full">
                                    <i data-lucide="book-open" class="w-4 h-4 mr-2"></i>
                                    Enroll in Course
                                </button>
                            </form>
                        <?php endif; ?>
                        <a href="safety_education.php" class="btn btn-outline">
                            <i data-lucide="arrow-left" class="w-4 h-4 mr-2"></i>
                            Back to Courses
                        </a>
                    </div>
                </div>
            </section>

            <!-- Course Content -->
            <?php if ($enrollment && $course['content_url']): ?>
                <section class="mb-8">
                    <div class="card card-glass p-6">
                        <h2 class="heading-2 text-white mb-4">Course Content</h2>
                        <div class="aspect-video bg-black/20 rounded-lg overflow-hidden">
                            <?php if ($course['content_type'] === 'video'): ?>
                                <iframe src="<?= htmlspecialchars($course['content_url']) ?>"
                                        class="w-full h-full"
                                        frameborder="0"
                                        allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture"
                                        allowfullscreen></iframe>
                            <?php else: ?>
                                <div class="w-full h-full flex items-center justify-center">
                                    <a href="<?= htmlspecialchars($course['content_url']) ?>" target="_blank" class="btn btn-primary">
                                        <i data-lucide="external-link" class="w-4 h-4 mr-2"></i>
                                        Open Course Content
                                    </a>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </section>
            <?php endif; ?>

            <!-- Rating Section -->
            <?php if ($enrollment && $enrollment['status'] === 'completed' && !$enrollment['rating']): ?>
                <section class="mb-8">
                    <div class="card card-glass p-6">
                        <h2 class="heading-2 text-white mb-4">Rate This Course</h2>
                        <form method="POST" class="space-y-4">
                            <input type="hidden" name="action" value="submit_rating">
                            <input type="hidden" name="enrollment_id" value="<?= $enrollment['id'] ?>">

                            <div>
                                <label class="text-white/80 text-sm mb-2 block">Rating</label>
                                <div class="flex items-center space-x-2" id="rating-stars">
                                    <?php for ($i = 1; $i <= 5; $i++): ?>
                                        <i data-lucide="star"
                                           class="w-8 h-8 cursor-pointer transition-all duration-200 rating-star text-white/30"
                                           data-rating="<?= $i ?>"></i>
                                    <?php endfor; ?>
                                </div>
                                <input type="hidden" name="rating" id="rating_input" value="0" required>
                            </div>

                            <div>
                                <label class="text-white/80 text-sm mb-2 block">Feedback (Optional)</label>
                                <textarea name="feedback" rows="4" class="form-input w-full" placeholder="Share your experience with this course..."></textarea>
                            </div>

                            <button type="submit" class="btn btn-primary">
                                <i data-lucide="send" class="w-4 h-4 mr-2"></i>
                                Submit Rating
                            </button>
                        </form>
                    </div>
                </section>
            <?php elseif ($enrollment && $enrollment['rating']): ?>
                <section class="mb-8">
                    <div class="card card-glass p-6">
                        <h2 class="heading-2 text-white mb-4">Your Rating</h2>
                        <div class="flex items-center space-x-2 mb-3">
                            <div class="flex items-center">
                                <?php for ($i = 1; $i <= 5; $i++): ?>
                                    <i data-lucide="star" class="w-5 h-5 <?= $i <= $enrollment['rating'] ? 'text-yellow-400 fill-yellow-400' : 'text-white/20' ?>"></i>
                                <?php endfor; ?>
                            </div>
                            <span class="text-white/70"><?= $enrollment['rating'] ?>/5</span>
                        </div>
                        <?php if ($enrollment['feedback']): ?>
                            <p class="text-white/80"><?= nl2br(htmlspecialchars($enrollment['feedback'])) ?></p>
                        <?php endif; ?>
                    </div>
                </section>
            <?php endif; ?>
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

        // Rating star interaction
        const stars = document.querySelectorAll('.rating-star');
        const ratingInput = document.getElementById('rating_input');

        if (stars.length > 0 && ratingInput) {
            stars.forEach(star => {
                star.addEventListener('click', function() {
                    const rating = parseInt(this.dataset.rating);
                    ratingInput.value = rating;

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
        }
    </script>
</body>
</html>

