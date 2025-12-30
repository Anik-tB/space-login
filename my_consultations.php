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

// Handle status updates and feedback
$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $consultationId = $_POST['consultation_id'] ?? null;

    if ($_POST['action'] === 'submit_feedback' && $consultationId) {
        $rating = intval($_POST['rating'] ?? 0);
        $feedback = trim($_POST['feedback'] ?? '');

        if ($rating > 0 && $rating <= 5) {
            $result = $models->updateConsultation($consultationId, [
                'rating' => $rating,
                'user_feedback' => $feedback,
                'completed_at' => date('Y-m-d H:i:s')
            ]);

            if ($result) {
                $message = 'Thank you for your feedback!';
            } else {
                $error = 'Failed to submit feedback. Please try again.';
            }
        } else {
            $error = 'Please provide a valid rating.';
        }
    }
}

// Get user consultations
$consultations = $models->getUserConsultations($userId);

// Group consultations by status
$consultationsByStatus = [
    'requested' => [],
    'scheduled' => [],
    'completed' => [],
    'cancelled' => [],
    'no_show' => []
];

foreach ($consultations as $consultation) {
    $status = $consultation['status'];
    if (isset($consultationsByStatus[$status])) {
        $consultationsByStatus[$status][] = $consultation;
    }
}
?>
<!DOCTYPE html>
<html lang="<?= $lang ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Consultations - SafeSpace Portal</title>

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
                    <a href="legal_aid.php" class="text-white/70 hover:text-white transition-colors duration-200">Legal Aid</a>
                    <a href="my_consultations.php" class="text-white font-medium">My Consultations</a>
                    <a href="legal_documents.php" class="text-white/70 hover:text-white transition-colors duration-200">Documents</a>
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
            <section class="mb-8 animate-fade-in">
                <div class="text-center">
                    <div class="w-20 h-20 bg-gradient-to-r from-indigo-500 via-purple-500 to-pink-500 rounded-3xl flex items-center justify-center mx-auto mb-6 shadow-2xl ring-4 ring-indigo-500/20">
                        <i data-lucide="list-check" class="w-10 h-10 text-white drop-shadow-lg"></i>
                    </div>
                    <h1 class="heading-1 mb-4 text-white">My Consultations</h1>
                    <p class="body-large max-w-2xl mx-auto text-white/80 leading-relaxed">
                        Track and manage your legal consultation requests. View status updates and provider responses.
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
            <section class="mb-8 grid grid-cols-1 md:grid-cols-4 gap-4">
                <div class="card card-glass p-6 text-center">
                    <div class="text-3xl font-bold text-white mb-2"><?= count($consultations) ?></div>
                    <div class="text-sm text-white/60">Total Consultations</div>
                </div>
                <div class="card card-glass p-6 text-center">
                    <div class="text-3xl font-bold text-yellow-400 mb-2"><?= count($consultationsByStatus['requested']) + count($consultationsByStatus['scheduled']) ?></div>
                    <div class="text-sm text-white/60">Pending</div>
                </div>
                <div class="card card-glass p-6 text-center">
                    <div class="text-3xl font-bold text-blue-400 mb-2"><?= count($consultationsByStatus['scheduled']) ?></div>
                    <div class="text-sm text-white/60">Scheduled</div>
                </div>
                <div class="card card-glass p-6 text-center">
                    <div class="text-3xl font-bold text-green-400 mb-2"><?= count($consultationsByStatus['completed']) ?></div>
                    <div class="text-sm text-white/60">Completed</div>
                </div>
            </section>

            <!-- Consultations List -->
            <?php if (empty($consultations)): ?>
                <div class="card card-glass text-center p-12">
                    <div class="w-20 h-20 bg-gradient-to-r from-gray-500 to-gray-600 rounded-full flex items-center justify-center mx-auto mb-6">
                        <i data-lucide="calendar-x" class="w-10 h-10 text-white"></i>
                    </div>
                    <h3 class="heading-3 text-white mb-3">No Consultations Yet</h3>
                    <p class="text-white/60 mb-6">You haven't booked any legal consultations yet.</p>
                    <a href="book_consultation.php" class="btn btn-primary">
                        <i data-lucide="calendar-plus" class="w-4 h-4 mr-2"></i>
                        Book Your First Consultation
                    </a>
                </div>
            <?php else: ?>
                <section class="space-y-4">
                    <?php foreach ($consultations as $index => $consultation):
                        $statusColors = [
                            'requested' => 'border-yellow-500/50 bg-yellow-500/10',
                            'scheduled' => 'border-blue-500/50 bg-blue-500/10',
                            'completed' => 'border-green-500/50 bg-green-500/10',
                            'cancelled' => 'border-red-500/50 bg-red-500/10',
                            'no_show' => 'border-gray-500/50 bg-gray-500/10'
                        ];
                        $statusColor = $statusColors[$consultation['status']] ?? 'border-gray-500/50 bg-gray-500/10';
                        $statusLabels = [
                            'requested' => 'Requested',
                            'scheduled' => 'Scheduled',
                            'completed' => 'Completed',
                            'cancelled' => 'Cancelled',
                            'no_show' => 'No Show'
                        ];
                    ?>
                        <div class="card card-glass border-l-4 <?= $statusColor ?>">
                            <div class="card-body p-5">
                                <div class="flex items-start justify-between mb-4">
                                    <div class="flex-1">
                                        <div class="flex items-center space-x-3 mb-2">
                                            <h3 class="heading-4 text-white"><?= htmlspecialchars($consultation['subject']) ?></h3>
                                            <span class="px-2 py-1 rounded-full text-xs font-semibold <?= $statusColor ?>">
                                                <?= $statusLabels[$consultation['status']] ?? ucfirst($consultation['status']) ?>
                                            </span>
                                        </div>
                                        <p class="text-sm text-white/60 mb-2 flex items-center">
                                            <i data-lucide="scale" class="w-4 h-4 mr-1.5 text-purple-400"></i>
                                            <?= htmlspecialchars($consultation['organization_name']) ?>
                                        </p>
                                        <p class="text-sm text-white/70 mb-3 line-clamp-2"><?= htmlspecialchars(substr($consultation['description'], 0, 200)) ?><?= strlen($consultation['description']) > 200 ? '...' : '' ?></p>
                                    </div>
                                </div>

                                <div class="grid grid-cols-1 md:grid-cols-2 gap-3 mb-4 text-sm">
                                    <div class="flex items-center text-white/70">
                                        <i data-lucide="calendar" class="w-4 h-4 mr-2 text-blue-400"></i>
                                        <span>Requested: <?= date('M j, Y', strtotime($consultation['created_at'])) ?></span>
                                    </div>
                                    <?php if ($consultation['preferred_date']): ?>
                                        <div class="flex items-center text-white/70">
                                            <i data-lucide="clock" class="w-4 h-4 mr-2 text-purple-400"></i>
                                            <span>Preferred: <?= date('M j, Y', strtotime($consultation['preferred_date'])) ?><?= $consultation['preferred_time'] ? ' at ' . date('h:i A', strtotime($consultation['preferred_time'])) : '' ?></span>
                                        </div>
                                    <?php endif; ?>
                                    <?php if ($consultation['scheduled_at']): ?>
                                        <div class="flex items-center text-white/70">
                                            <i data-lucide="check-circle" class="w-4 h-4 mr-2 text-green-400"></i>
                                            <span>Scheduled: <?= date('M j, Y h:i A', strtotime($consultation['scheduled_at'])) ?></span>
                                        </div>
                                    <?php endif; ?>
                                    <?php if ($consultation['phone']): ?>
                                        <div class="flex items-center text-white/70">
                                            <i data-lucide="phone" class="w-4 h-4 mr-2 text-green-400"></i>
                                            <a href="tel:<?= htmlspecialchars($consultation['phone']) ?>" class="hover:text-white transition-colors"><?= htmlspecialchars($consultation['phone']) ?></a>
                                        </div>
                                    <?php endif; ?>
                                    <?php if ($consultation['consultation_type']): ?>
                                        <div class="flex items-center text-white/70">
                                            <i data-lucide="file-text" class="w-4 h-4 mr-2 text-orange-400"></i>
                                            <span class="capitalize"><?= str_replace('_', ' ', $consultation['consultation_type']) ?></span>
                                        </div>
                                    <?php endif; ?>
                                </div>

                                <?php if ($consultation['provider_notes']): ?>
                                    <div class="mb-4 p-4 bg-blue-500/10 border border-blue-500/30 rounded-lg">
                                        <p class="text-xs font-semibold text-blue-300 mb-2 flex items-center">
                                            <i data-lucide="message-square" class="w-4 h-4 mr-2"></i>
                                            Provider Notes:
                                        </p>
                                        <p class="text-sm text-blue-200 whitespace-pre-line"><?= nl2br(htmlspecialchars($consultation['provider_notes'])) ?></p>
                                    </div>
                                <?php endif; ?>

                                <?php if ($consultation['status'] === 'completed' && !$consultation['rating']): ?>
                                    <div class="mt-4 p-4 bg-purple-500/10 border border-purple-500/30 rounded-lg">
                                        <form method="POST" class="space-y-3">
                                            <input type="hidden" name="action" value="submit_feedback">
                                            <input type="hidden" name="consultation_id" value="<?= $consultation['id'] ?>">
                                            <label class="text-sm font-semibold text-purple-300 mb-2 block">Rate this consultation:</label>
                                            <div class="flex items-center space-x-2 mb-3">
                                                <?php for ($i = 1; $i <= 5; $i++): ?>
                                                    <input type="radio" name="rating" value="<?= $i ?>" id="rating_<?= $consultation['id'] ?>_<?= $i ?>" class="hidden rating-input">
                                                    <label for="rating_<?= $consultation['id'] ?>_<?= $i ?>" class="cursor-pointer">
                                                        <i data-lucide="star" class="w-6 h-6 text-gray-400 hover:text-yellow-400 rating-star" fill="none"></i>
                                                    </label>
                                                <?php endfor; ?>
                                            </div>
                                            <textarea name="feedback" rows="2" class="form-input w-full" placeholder="Your feedback (optional)"></textarea>
                                            <button type="submit" class="btn btn-sm btn-primary">
                                                <i data-lucide="send" class="w-4 h-4 mr-2"></i>
                                                Submit Feedback
                                            </button>
                                        </form>
                                    </div>
                                <?php elseif ($consultation['rating']): ?>
                                    <div class="mt-4 flex items-center text-sm text-white/70">
                                        <span class="mr-2">Your rating:</span>
                                        <div class="flex items-center">
                                            <?php for ($i = 1; $i <= 5; $i++): ?>
                                                <i data-lucide="star" class="w-4 h-4 text-yellow-400 <?= $i <= $consultation['rating'] ? '' : 'opacity-30' ?>" fill="<?= $i <= $consultation['rating'] ? 'currentColor' : 'none' ?>"></i>
                                            <?php endfor; ?>
                                        </div>
                                        <?php if ($consultation['user_feedback']): ?>
                                            <span class="ml-4 italic">"<?= htmlspecialchars(substr($consultation['user_feedback'], 0, 50)) ?>..."</span>
                                        <?php endif; ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </section>
            <?php endif; ?>
        </div>
    </main>

    <script src="https://unpkg.com/lucide@latest/dist/umd/lucide.js"></script>
    <script src="dashboard-enhanced.js"></script>
    <script>
        lucide.createIcons();

        // Rating star hover effect
        document.querySelectorAll('.rating-input').forEach(input => {
            input.addEventListener('change', function() {
                const value = parseInt(this.value);
                const stars = this.closest('form').querySelectorAll('.rating-star');
                stars.forEach((star, index) => {
                    if (index < value) {
                        star.setAttribute('fill', 'currentColor');
                        star.classList.add('text-yellow-400');
                        star.classList.remove('text-gray-400');
                    } else {
                        star.setAttribute('fill', 'none');
                        star.classList.add('text-gray-400');
                        star.classList.remove('text-yellow-400');
                    }
                });
            });
        });
    </script>
</body>
</html>

