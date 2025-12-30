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

// Get user enrollments
$enrollments = $models->getUserEnrollments($userId);

// Get user certificates
$certificates = $models->getUserCertificates($userId);

// Calculate statistics
$totalEnrolled = count($enrollments);
$inProgress = count(array_filter($enrollments, fn($e) => $e['status'] === 'in_progress'));
$completed = count(array_filter($enrollments, fn($e) => $e['status'] === 'completed'));
$avgProgress = $totalEnrolled > 0 ? array_sum(array_column($enrollments, 'progress_percentage')) / $totalEnrolled : 0;
?>
<!DOCTYPE html>
<html lang="<?= $lang ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Training - SafeSpace Portal</title>

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
                    <div class="w-20 h-20 bg-gradient-to-r from-indigo-500 via-purple-500 to-pink-500 rounded-3xl flex items-center justify-center mx-auto mb-6 shadow-2xl ring-4 ring-indigo-500/20">
                        <i data-lucide="user-check" class="w-10 h-10 text-white drop-shadow-lg"></i>
                    </div>
                    <h1 class="heading-1 mb-4 text-white">My Training</h1>
                    <p class="body-large max-w-2xl mx-auto text-white/80 leading-relaxed">
                        Track your learning progress, view certificates, and continue your safety education journey.
                    </p>
                </div>
            </section>

            <!-- Statistics -->
            <div class="grid grid-cols-1 md:grid-cols-4 gap-4 mb-8">
                <div class="card card-glass p-6">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-white/60 text-sm mb-1">Total Enrolled</p>
                            <p class="heading-2 text-white"><?= $totalEnrolled ?></p>
                        </div>
                        <div class="w-12 h-12 bg-gradient-to-r from-blue-500 to-cyan-500 rounded-lg flex items-center justify-center">
                            <i data-lucide="book-open" class="w-6 h-6 text-white"></i>
                        </div>
                    </div>
                </div>
                <div class="card card-glass p-6">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-white/60 text-sm mb-1">In Progress</p>
                            <p class="heading-2 text-white"><?= $inProgress ?></p>
                        </div>
                        <div class="w-12 h-12 bg-gradient-to-r from-yellow-500 to-orange-500 rounded-lg flex items-center justify-center">
                            <i data-lucide="clock" class="w-6 h-6 text-white"></i>
                        </div>
                    </div>
                </div>
                <div class="card card-glass p-6">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-white/60 text-sm mb-1">Completed</p>
                            <p class="heading-2 text-white"><?= $completed ?></p>
                        </div>
                        <div class="w-12 h-12 bg-gradient-to-r from-green-500 to-emerald-500 rounded-lg flex items-center justify-center">
                            <i data-lucide="check-circle" class="w-6 h-6 text-white"></i>
                        </div>
                    </div>
                </div>
                <div class="card card-glass p-6">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-white/60 text-sm mb-1">Avg Progress</p>
                            <p class="heading-2 text-white"><?= number_format($avgProgress, 0) ?>%</p>
                        </div>
                        <div class="w-12 h-12 bg-gradient-to-r from-purple-500 to-pink-500 rounded-lg flex items-center justify-center">
                            <i data-lucide="trending-up" class="w-6 h-6 text-white"></i>
                        </div>
                    </div>
                </div>
            </div>

            <!-- My Enrollments -->
            <section class="mb-8">
                <div class="mb-6 flex items-center justify-between">
                    <div>
                        <h2 class="heading-2 text-white">My Courses (<?= count($enrollments) ?>)</h2>
                        <?php if (!empty($enrollments)): ?>
                            <p class="text-white/60 text-sm mt-1">Track your learning progress</p>
                        <?php endif; ?>
                    </div>
                    <a href="safety_education.php" class="btn btn-outline btn-sm">
                        <i data-lucide="plus" class="w-4 h-4 mr-2"></i>
                        Browse Courses
                    </a>
                </div>

                <?php if (empty($enrollments)): ?>
                    <div class="card card-glass text-center p-12">
                        <i data-lucide="book-open" class="w-16 h-16 text-white/30 mx-auto mb-4"></i>
                        <h3 class="heading-3 text-white mb-3">No Courses Enrolled</h3>
                        <p class="text-white/60 mb-6">Start your safety education journey by enrolling in a course.</p>
                        <a href="safety_education.php" class="btn btn-primary">
                            <i data-lucide="book-open" class="w-4 h-4 mr-2"></i>
                            Browse Courses
                        </a>
                    </div>
                <?php else: ?>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <?php foreach ($enrollments as $enrollment):
                            $statusColors = [
                                'enrolled' => 'border-blue-500/50 bg-blue-500/10',
                                'in_progress' => 'border-yellow-500/50 bg-yellow-500/10',
                                'completed' => 'border-green-500/50 bg-green-500/10',
                                'dropped' => 'border-red-500/50 bg-red-500/10'
                            ];
                            $statusColor = $statusColors[$enrollment['status']] ?? 'border-gray-500/50 bg-gray-500/10';
                        ?>
                            <div class="card card-glass border-l-4 <?= $statusColor ?>">
                                <div class="card-body p-5">
                                    <div class="flex items-start justify-between mb-4">
                                        <div class="flex-1">
                                            <h3 class="heading-4 text-white mb-2"><?= htmlspecialchars($enrollment['course_title']) ?></h3>
                                            <p class="text-sm text-white/60 mb-3 capitalize"><?= str_replace('_', ' ', $enrollment['category']) ?></p>
                                            <div class="flex items-center space-x-4 text-sm text-white/60 mb-3">
                                                <?php if ($enrollment['duration_minutes']): ?>
                                                    <span class="flex items-center">
                                                        <i data-lucide="clock" class="w-4 h-4 mr-1"></i>
                                                        <?= $enrollment['duration_minutes'] ?> min
                                                    </span>
                                                <?php endif; ?>
                                                <?php if ($enrollment['instructor_name']): ?>
                                                    <span class="flex items-center">
                                                        <i data-lucide="user" class="w-4 h-4 mr-1"></i>
                                                        <?= htmlspecialchars($enrollment['instructor_name']) ?>
                                                    </span>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                        <span class="px-2 py-1 rounded-full text-xs font-semibold <?= $statusColor ?>">
                                            <?= ucfirst(str_replace('_', ' ', $enrollment['status'])) ?>
                                        </span>
                                    </div>

                                    <div class="mb-4">
                                        <div class="flex items-center justify-between mb-2">
                                            <span class="text-white/70 text-sm">Progress</span>
                                            <span class="text-white font-semibold"><?= number_format($enrollment['progress_percentage'], 0) ?>%</span>
                                        </div>
                                        <div class="w-full bg-white/10 rounded-full h-3 overflow-hidden">
                                            <div class="h-full bg-gradient-to-r from-blue-500 to-cyan-500 transition-all duration-500"
                                                 style="width: <?= $enrollment['progress_percentage'] ?>%"></div>
                                        </div>
                                    </div>

                                    <div class="grid grid-cols-2 gap-3 text-sm text-white/60 mb-4">
                                        <div>
                                            <p class="text-xs text-white/50 mb-0.5">Started</p>
                                            <p><?= date('M j, Y', strtotime($enrollment['started_at'])) ?></p>
                                        </div>
                                        <?php if ($enrollment['completed_at']): ?>
                                            <div>
                                                <p class="text-xs text-white/50 mb-0.5">Completed</p>
                                                <p><?= date('M j, Y', strtotime($enrollment['completed_at'])) ?></p>
                                            </div>
                                        <?php endif; ?>
                                        <?php if ($enrollment['last_accessed_at']): ?>
                                            <div>
                                                <p class="text-xs text-white/50 mb-0.5">Last Accessed</p>
                                                <p><?= date('M j, Y', strtotime($enrollment['last_accessed_at'])) ?></p>
                                            </div>
                                        <?php endif; ?>
                                    </div>

                                    <?php if ($enrollment['status'] === 'completed' && $enrollment['certificate_issued']): ?>
                                        <div class="mb-4 p-3 bg-green-500/10 border border-green-500/30 rounded-lg">
                                            <div class="flex items-center justify-between">
                                                <div>
                                                    <p class="text-green-300 font-semibold text-sm mb-1 flex items-center">
                                                        <i data-lucide="award" class="w-4 h-4 mr-2"></i>
                                                        Certificate Issued
                                                    </p>
                                                    <p class="text-xs text-white/60">#<?= htmlspecialchars($enrollment['certificate_id']) ?></p>
                                                </div>
                                                <i data-lucide="award" class="w-6 h-6 text-green-400"></i>
                                            </div>
                                        </div>
                                    <?php endif; ?>

                                    <div class="flex items-center space-x-2 pt-4 border-t border-white/10">
                                        <a href="module_detail.php?id=<?= $enrollment['course_id'] ?>" class="btn btn-outline flex-1">
                                            <i data-lucide="eye" class="w-4 h-4 mr-2"></i>
                                            View Course
                                        </a>
                                        <?php if ($enrollment['status'] !== 'completed'): ?>
                                            <a href="module_detail.php?id=<?= $enrollment['course_id'] ?>" class="btn btn-primary flex-1">
                                                <i data-lucide="play" class="w-4 h-4 mr-2"></i>
                                                Continue
                                            </a>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </section>

            <!-- Certificates -->
            <section>
                <div class="mb-6 flex items-center justify-between">
                    <div>
                        <h2 class="heading-2 text-white">My Certificates (<?= count($certificates) ?>)</h2>
                        <?php if (!empty($certificates)): ?>
                            <p class="text-white/60 text-sm mt-1">Your earned safety education certificates</p>
                        <?php endif; ?>
                    </div>
                    <?php if (!empty($certificates)): ?>
                        <a href="verify_certificate.php" class="btn btn-outline btn-sm">
                            <i data-lucide="shield-check" class="w-4 h-4 mr-2"></i>
                            Verify Certificate
                        </a>
                    <?php endif; ?>
                </div>

                <?php if (empty($certificates)): ?>
                    <div class="card card-glass text-center p-12">
                        <i data-lucide="award" class="w-16 h-16 text-white/30 mx-auto mb-4"></i>
                        <h3 class="heading-3 text-white mb-3">No Certificates Yet</h3>
                        <p class="text-white/60 mb-6">Complete courses to earn certificates.</p>
                        <a href="safety_education.php" class="btn btn-primary">
                            <i data-lucide="book-open" class="w-4 h-4 mr-2"></i>
                            Browse Courses
                        </a>
                    </div>
                <?php else: ?>
                    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                        <?php foreach ($certificates as $certificate): ?>
                            <div class="card card-glass p-5 hover:scale-105 transition-transform duration-200">
                                <div class="text-center mb-4">
                                    <div class="w-16 h-16 bg-gradient-to-r from-yellow-500 to-amber-500 rounded-2xl flex items-center justify-center mx-auto mb-3">
                                        <i data-lucide="award" class="w-8 h-8 text-white"></i>
                                    </div>
                                    <h3 class="heading-4 text-white mb-2 line-clamp-2"><?= htmlspecialchars($certificate['course_title']) ?></h3>
                                    <p class="text-sm text-white/60 capitalize"><?= str_replace('_', ' ', $certificate['category']) ?></p>
                                    <?php if ($certificate['instructor_name']): ?>
                                        <p class="text-xs text-white/50 mt-1">by <?= htmlspecialchars($certificate['instructor_name']) ?></p>
                                    <?php endif; ?>
                                </div>

                                <div class="space-y-2 text-sm mb-4">
                                    <div class="p-3 bg-white/5 rounded-lg">
                                        <p class="text-white/50 text-xs mb-1">Certificate #</p>
                                        <p class="font-mono text-xs text-white"><?= htmlspecialchars($certificate['certificate_number']) ?></p>
                                    </div>
                                    <?php if ($certificate['verification_code']): ?>
                                        <div class="p-3 bg-white/5 rounded-lg">
                                            <p class="text-white/50 text-xs mb-1">Verification Code</p>
                                            <p class="font-mono text-xs text-white"><?= htmlspecialchars($certificate['verification_code']) ?></p>
                                        </div>
                                    <?php endif; ?>
                                    <div class="flex items-center justify-between text-xs text-white/60">
                                        <span>Issued: <?= date('M j, Y', strtotime($certificate['issued_at'])) ?></span>
                                        <?php if ($certificate['expires_at']): ?>
                                            <span class="text-orange-400">Expires: <?= date('M j, Y', strtotime($certificate['expires_at'])) ?></span>
                                        <?php endif; ?>
                                    </div>
                                </div>

                                <div class="flex items-center space-x-2 pt-4 border-t border-white/10">
                                    <a href="verify_certificate.php?code=<?= urlencode($certificate['verification_code']) ?>" class="btn btn-outline flex-1 text-center" target="_blank">
                                        <i data-lucide="shield-check" class="w-4 h-4 mr-2"></i>
                                        Verify
                                    </a>
                                    <?php if ($certificate['certificate_file_path']): ?>
                                        <a href="<?= htmlspecialchars($certificate['certificate_file_path']) ?>" class="btn btn-primary" target="_blank" title="Download Certificate">
                                            <i data-lucide="download" class="w-4 h-4"></i>
                                        </a>
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

