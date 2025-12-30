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

// Get user referrals
$referrals = $models->getUserReferrals($userId);

// Handle feedback submission
$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'submit_feedback') {
        $referralId = intval($_POST['referral_id'] ?? 0);
        $rating = intval($_POST['rating'] ?? 0);
        $feedback = trim($_POST['feedback'] ?? '');

        if ($referralId && $rating > 0) {
            $result = $models->updateReferral($referralId, [
                'rating' => $rating,
                'user_feedback' => $feedback,
                'status' => 'completed',
                'completed_at' => date('Y-m-d H:i:s')
            ]);

            if ($result) {
                $message = 'Thank you for your feedback!';
                $referrals = $models->getUserReferrals($userId);
            } else {
                $error = 'Failed to submit feedback. Please try again.';
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="<?= $lang ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Medical Referrals - SafeSpace Portal</title>

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
                    <a href="book_appointment.php" class="text-white/70 hover:text-white transition-colors duration-200">Book Appointment</a>
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
                        <i data-lucide="heart-handshake" class="w-10 h-10 text-white drop-shadow-lg"></i>
                    </div>
                    <h1 class="heading-1 mb-4 text-white">My Medical Referrals</h1>
                    <p class="body-large max-w-2xl mx-auto text-white/80 leading-relaxed">
                        Track your medical and psychological support referrals. View status, appointments, and provide feedback.
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
                            <p class="text-white/60 text-sm mb-1">Total Referrals</p>
                            <p class="heading-2 text-white"><?= count($referrals) ?></p>
                        </div>
                        <div class="w-12 h-12 bg-gradient-to-r from-blue-500 to-cyan-500 rounded-lg flex items-center justify-center">
                            <i data-lucide="file-text" class="w-6 h-6 text-white"></i>
                        </div>
                    </div>
                </div>
                <div class="card card-glass p-6">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-white/60 text-sm mb-1">Pending</p>
                            <p class="heading-2 text-white"><?= count(array_filter($referrals, fn($r) => $r['status'] === 'pending')) ?></p>
                        </div>
                        <div class="w-12 h-12 bg-gradient-to-r from-yellow-500 to-orange-500 rounded-lg flex items-center justify-center">
                            <i data-lucide="clock" class="w-6 h-6 text-white"></i>
                        </div>
                    </div>
                </div>
                <div class="card card-glass p-6">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-white/60 text-sm mb-1">Scheduled</p>
                            <p class="heading-2 text-white"><?= count(array_filter($referrals, fn($r) => $r['status'] === 'appointment_scheduled')) ?></p>
                        </div>
                        <div class="w-12 h-12 bg-gradient-to-r from-purple-500 to-pink-500 rounded-lg flex items-center justify-center">
                            <i data-lucide="calendar" class="w-6 h-6 text-white"></i>
                        </div>
                    </div>
                </div>
                <div class="card card-glass p-6">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-white/60 text-sm mb-1">Completed</p>
                            <p class="heading-2 text-white"><?= count(array_filter($referrals, fn($r) => $r['status'] === 'completed')) ?></p>
                        </div>
                        <div class="w-12 h-12 bg-gradient-to-r from-green-500 to-emerald-500 rounded-lg flex items-center justify-center">
                            <i data-lucide="check-circle" class="w-6 h-6 text-white"></i>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Referrals List -->
            <section>
                <?php if (empty($referrals)): ?>
                    <div class="card card-glass text-center p-12">
                        <i data-lucide="heart-off" class="w-16 h-16 text-white/30 mx-auto mb-4"></i>
                        <h3 class="heading-3 text-white mb-3">No Referrals Yet</h3>
                        <p class="text-white/60 mb-6">You haven't made any medical referrals yet.</p>
                        <a href="book_appointment.php" class="btn btn-primary">
                            <i data-lucide="calendar-plus" class="w-4 h-4 mr-2"></i>
                            Book Your First Appointment
                        </a>
                    </div>
                <?php else: ?>
                    <div class="space-y-4">
                        <?php foreach ($referrals as $index => $referral):
                            $statusColors = [
                                'pending' => 'border-yellow-500/50 bg-yellow-500/10',
                                'contacted' => 'border-blue-500/50 bg-blue-500/10',
                                'appointment_scheduled' => 'border-purple-500/50 bg-purple-500/10',
                                'completed' => 'border-green-500/50 bg-green-500/10',
                                'declined' => 'border-red-500/50 bg-red-500/10'
                            ];
                            $statusColor = $statusColors[$referral['status']] ?? 'border-gray-500/50 bg-gray-500/10';
                        ?>
                            <div class="card card-glass border-l-4 <?= $statusColor ?>">
                                <div class="card-body p-5">
                                    <div class="flex items-start justify-between mb-4">
                                        <div class="flex-1">
                                            <div class="flex items-center space-x-3 mb-2">
                                                <h3 class="heading-4 text-white"><?= htmlspecialchars($referral['provider_name']) ?></h3>
                                                <span class="px-2 py-1 rounded-full text-xs font-semibold <?= $statusColor ?>">
                                                    <?= ucfirst(str_replace('_', ' ', $referral['status'])) ?>
                                                </span>
                                                <span class="px-2 py-1 rounded-full text-xs font-semibold bg-purple-500/20 text-purple-300 border border-purple-500/30">
                                                    <?= ucfirst($referral['referral_type']) ?>
                                                </span>
                                                <?php if ($referral['priority'] === 'urgent'): ?>
                                                    <span class="px-2 py-1 rounded-full text-xs font-semibold bg-red-500/20 text-red-300 border border-red-500/30">
                                                        Urgent
                                                    </span>
                                                <?php endif; ?>
                                            </div>
                                            <p class="text-sm text-white/60 mb-2 flex items-center">
                                                <i data-lucide="heart-pulse" class="w-4 h-4 mr-1.5 text-red-400"></i>
                                                <?= ucfirst(str_replace('_', ' ', $referral['provider_type'] ?? 'Provider')) ?>
                                            </p>
                                            <p class="text-sm text-white/60 mb-2">
                                                Referred on <?= date('M j, Y h:i A', strtotime($referral['referred_at'])) ?>
                                            </p>
                                            <?php if ($referral['appointment_date']): ?>
                                                <p class="text-sm text-white/70 mb-2 flex items-center">
                                                    <i data-lucide="calendar" class="w-4 h-4 mr-2 text-purple-400"></i>
                                                    Appointment: <?= date('M j, Y h:i A', strtotime($referral['appointment_date'])) ?>
                                                </p>
                                            <?php endif; ?>
                                            <?php if ($referral['reason']): ?>
                                                <p class="text-white/80 mb-3 line-clamp-2"><?= htmlspecialchars(substr($referral['reason'], 0, 200)) ?><?= strlen($referral['reason']) > 200 ? '...' : '' ?></p>
                                            <?php endif; ?>
                                            <div class="grid grid-cols-1 md:grid-cols-2 gap-3 text-sm text-white/60">
                                                <div class="flex items-center">
                                                    <i data-lucide="phone" class="w-4 h-4 mr-2 text-green-400"></i>
                                                    <a href="tel:<?= htmlspecialchars($referral['phone']) ?>" class="hover:text-white transition-colors"><?= htmlspecialchars($referral['phone']) ?></a>
                                                </div>
                                                <div class="flex items-center">
                                                    <i data-lucide="map-pin" class="w-4 h-4 mr-2 text-blue-400"></i>
                                                    <?= htmlspecialchars($referral['city'] ?? 'N/A') ?>, <?= htmlspecialchars($referral['district'] ?? 'N/A') ?>
                                                </div>
                                            </div>
                                        </div>
                                    </div>

                                    <?php if ($referral['provider_notes']): ?>
                                        <div class="mb-4 p-4 bg-blue-500/10 border border-blue-500/30 rounded-lg">
                                            <p class="text-xs font-semibold text-blue-300 mb-2 flex items-center">
                                                <i data-lucide="message-square" class="w-4 h-4 mr-2"></i>
                                                Provider Notes:
                                            </p>
                                            <p class="text-sm text-blue-200 whitespace-pre-line"><?= nl2br(htmlspecialchars($referral['provider_notes'])) ?></p>
                                        </div>
                                    <?php endif; ?>

                                    <?php if ($referral['status'] === 'completed' && !$referral['rating']): ?>
                                        <div class="mt-4 p-4 bg-purple-500/10 border border-purple-500/30 rounded-lg">
                                            <h4 class="text-white font-semibold mb-3">Share Your Experience</h4>
                                            <form method="POST" class="space-y-3">
                                                <input type="hidden" name="action" value="submit_feedback">
                                                <input type="hidden" name="referral_id" value="<?= $referral['id'] ?>">
                                                <div>
                                                    <label class="text-white/80 text-sm mb-2 block">Rating</label>
                                                    <div class="flex items-center space-x-2" id="rating-stars-<?= $referral['id'] ?>">
                                                        <?php for ($i = 1; $i <= 5; $i++): ?>
                                                            <i data-lucide="star" class="w-6 h-6 text-white/30 cursor-pointer hover:text-yellow-400 rating-star" data-rating="<?= $i ?>" data-ref-id="<?= $referral['id'] ?>"></i>
                                                        <?php endfor; ?>
                                                    </div>
                                                    <input type="hidden" name="rating" id="rating-input-<?= $referral['id'] ?>" value="0" required>
                                                </div>
                                                <div>
                                                    <label class="text-white/80 text-sm mb-2 block">Feedback (Optional)</label>
                                                    <textarea name="feedback" rows="3" class="form-input w-full" placeholder="Share your experience..."></textarea>
                                                </div>
                                                <button type="submit" class="btn btn-primary btn-sm">
                                                    <i data-lucide="send" class="w-4 h-4 mr-2"></i>
                                                    Submit Feedback
                                                </button>
                                            </form>
                                        </div>
                                    <?php elseif ($referral['rating']): ?>
                                        <div class="mt-4 p-4 bg-green-500/10 border border-green-500/30 rounded-lg">
                                            <div class="flex items-center space-x-2 mb-2">
                                                <span class="text-white font-semibold">Your Rating:</span>
                                                <div class="flex items-center">
                                                    <?php for ($i = 1; $i <= 5; $i++): ?>
                                                        <i data-lucide="star" class="w-4 h-4 <?= $i <= $referral['rating'] ? 'text-yellow-400 fill-yellow-400' : 'text-white/20' ?>"></i>
                                                    <?php endfor; ?>
                                                </div>
                                            </div>
                                            <?php if ($referral['user_feedback']): ?>
                                                <p class="text-white/80 text-sm"><?= nl2br(htmlspecialchars($referral['user_feedback'])) ?></p>
                                            <?php endif; ?>
                                        </div>
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

        // Rating star interaction
        document.querySelectorAll('.rating-star').forEach(star => {
            star.addEventListener('click', function() {
                const rating = parseInt(this.dataset.rating);
                const refId = this.dataset.refId;
                const stars = document.querySelectorAll(`[data-ref-id="${refId}"]`);
                const input = document.getElementById(`rating-input-${refId}`);

                stars.forEach((s, index) => {
                    if (index < rating) {
                        s.classList.remove('text-white/30');
                        s.classList.add('text-yellow-400', 'fill-yellow-400');
                    } else {
                        s.classList.remove('text-yellow-400', 'fill-yellow-400');
                        s.classList.add('text-white/30');
                    }
                });

                input.value = rating;
            });

            star.addEventListener('mouseenter', function() {
                const rating = parseInt(this.dataset.rating);
                const refId = this.dataset.refId;
                const stars = document.querySelectorAll(`[data-ref-id="${refId}"]`);

                stars.forEach((s, index) => {
                    if (index < rating) {
                        s.classList.add('text-yellow-400');
                    }
                });
            });

            star.addEventListener('mouseleave', function() {
                const refId = this.dataset.refId;
                const input = document.getElementById(`rating-input-${refId}`);
                const currentRating = parseInt(input.value) || 0;
                const stars = document.querySelectorAll(`[data-ref-id="${refId}"]`);

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

