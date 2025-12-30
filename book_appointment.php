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

// Get provider ID if specified
$providerId = $_GET['provider_id'] ?? null;
$provider = null;
if ($providerId) {
    $provider = $models->getMedicalProviderById($providerId);
}

// Get all providers for dropdown
$allProviders = $models->getMedicalProviders(['limit' => 100]);

// Get user reports for linking
$userReports = $models->getUserReports($userId);

// Handle form submission
$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'book_referral') {
    try {
        $referralData = [
            'user_id' => $userId,
            'report_id' => !empty($_POST['report_id']) ? intval($_POST['report_id']) : null,
            'provider_id' => intval($_POST['provider_id']),
            'referral_type' => $_POST['referral_type'],
            'priority' => $_POST['priority'] ?? 'medium',
            'reason' => trim($_POST['reason'] ?? ''),
            'is_anonymous' => isset($_POST['is_anonymous']) ? 1 : 0,
            'appointment_date' => !empty($_POST['appointment_date']) && !empty($_POST['appointment_time'])
                ? $_POST['appointment_date'] . ' ' . $_POST['appointment_time'] . ':00'
                : null
        ];

        if (empty($referralData['provider_id']) || empty($referralData['referral_type'])) {
            throw new Exception('Please select a provider and referral type.');
        }

        $referralId = $models->createSupportReferral($referralData);

        if ($referralId) {
            // Create notification
            $models->createNotification([
                'user_id' => $userId,
                'title' => 'Referral Request Submitted',
                'message' => 'Your medical support referral #' . $referralId . ' has been submitted. The provider will contact you soon.',
                'type' => 'report_update',
                'action_url' => 'my_referrals.php'
            ]);

            header('Location: medical_support.php?success=1');
            exit;
        } else {
            throw new Exception('Failed to submit referral request. Please try again.');
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
    <title>Book Medical Appointment - SafeSpace Portal</title>

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
        <div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8">
            <section class="mb-8 animate-fade-in">
                <div class="text-center">
                    <div class="w-20 h-20 bg-gradient-to-r from-red-500 via-pink-500 to-rose-500 rounded-3xl flex items-center justify-center mx-auto mb-6 shadow-2xl ring-4 ring-red-500/20">
                        <i data-lucide="calendar-plus" class="w-10 h-10 text-white drop-shadow-lg"></i>
                    </div>
                    <h1 class="heading-1 mb-4 text-white">Book Medical Appointment</h1>
                    <p class="body-large max-w-2xl mx-auto text-white/80 leading-relaxed">
                        Request a referral to a medical provider or mental health professional. We'll help connect you with the right support.
                    </p>
                </div>
            </section>

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

            <div class="card card-glass p-6">
                <?php if ($provider): ?>
                    <div class="mb-6 p-4 bg-gradient-to-r from-red-500/10 to-pink-500/10 border border-red-500/30 rounded-lg">
                        <h3 class="heading-4 text-white mb-3">Selected Provider</h3>
                        <div class="flex items-start justify-between">
                            <div class="flex-1">
                                <h4 class="text-lg font-semibold text-white mb-1"><?= htmlspecialchars($provider['provider_name']) ?></h4>
                                <p class="text-sm text-white/70 mb-2 capitalize"><?= str_replace('_', ' ', $provider['provider_type']) ?></p>
                                <div class="flex items-center space-x-4 text-sm text-white/60">
                                    <span class="flex items-center"><i data-lucide="map-pin" class="w-4 h-4 mr-1"></i> <?= htmlspecialchars($provider['city'] ?? 'N/A') ?></span>
                                    <span class="flex items-center"><i data-lucide="phone" class="w-4 h-4 mr-1"></i> <?= htmlspecialchars($provider['phone']) ?></span>
                                </div>
                            </div>
                            <div class="text-right">
                                <div class="flex items-center mb-2">
                                    <?php for ($i = 1; $i <= 5; $i++): ?>
                                        <i data-lucide="star" class="w-4 h-4 text-yellow-400 <?= $i <= round($provider['rating']) ? '' : 'opacity-30' ?>" fill="<?= $i <= round($provider['rating']) ? 'currentColor' : 'none' ?>"></i>
                                    <?php endfor; ?>
                                </div>
                                <span class="text-xs text-white/60"><?= number_format($provider['rating'], 1) ?> rating</span>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>

                <form method="POST" class="space-y-5" id="referralForm">
                    <input type="hidden" name="action" value="book_referral">

                    <div>
                        <label class="form-label text-white mb-2 flex items-center">
                            <i data-lucide="heart-pulse" class="w-4 h-4 mr-2 text-red-400"></i>
                            Select Provider <span class="text-red-400">*</span>
                        </label>
                        <select name="provider_id" class="form-input w-full" required>
                            <option value="">-- Select a Provider --</option>
                            <?php foreach ($allProviders as $p): ?>
                                <option value="<?= $p['id'] ?>" <?= ($provider && $provider['id'] == $p['id']) ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($p['provider_name']) ?> - <?= str_replace('_', ' ', $p['provider_type']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <label class="form-label text-white mb-2 flex items-center">
                                <i data-lucide="file-text" class="w-4 h-4 mr-2 text-blue-400"></i>
                                Referral Type <span class="text-red-400">*</span>
                            </label>
                            <select name="referral_type" class="form-input w-full" required>
                                <option value="medical">Medical</option>
                                <option value="counseling">Counseling</option>
                                <option value="emergency">Emergency</option>
                                <option value="follow_up">Follow-up</option>
                            </select>
                        </div>

                        <div>
                            <label class="form-label text-white mb-2 flex items-center">
                                <i data-lucide="alert-circle" class="w-4 h-4 mr-2 text-orange-400"></i>
                                Priority
                            </label>
                            <select name="priority" class="form-input w-full">
                                <option value="low">Low</option>
                                <option value="medium" selected>Medium</option>
                                <option value="high">High</option>
                                <option value="urgent">Urgent</option>
                            </select>
                        </div>
                    </div>

                    <?php if (!empty($userReports)): ?>
                        <div>
                            <label class="form-label text-white mb-2 flex items-center">
                                <i data-lucide="alert-triangle" class="w-4 h-4 mr-2 text-orange-400"></i>
                                Link to Incident Report (Optional)
                            </label>
                            <select name="report_id" class="form-input w-full">
                                <option value="">-- No Report --</option>
                                <?php foreach ($userReports as $report): ?>
                                    <option value="<?= $report['id'] ?>">
                                        Report #<?= $report['id'] ?> - <?= htmlspecialchars($report['incident_type']) ?> (<?= date('M j, Y', strtotime($report['incident_date'])) ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    <?php endif; ?>

                    <div>
                        <label class="form-label text-white mb-2 flex items-center">
                            <i data-lucide="message-square" class="w-4 h-4 mr-2 text-green-400"></i>
                            Reason for Referral
                        </label>
                        <textarea name="reason" rows="5" class="form-input w-full" placeholder="Please describe why you need this referral. Include any relevant details about your medical or psychological needs..."></textarea>
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <label class="form-label text-white mb-2">Preferred Date (Optional)</label>
                            <input type="date" name="appointment_date" class="form-input w-full" min="<?= date('Y-m-d') ?>">
                        </div>
                        <div>
                            <label class="form-label text-white mb-2">Preferred Time (Optional)</label>
                            <input type="time" name="appointment_time" class="form-input w-full">
                        </div>
                    </div>

                    <div class="flex items-center space-x-2 p-4 bg-purple-500/10 border border-purple-500/30 rounded-lg">
                        <input type="checkbox" name="is_anonymous" id="is_anonymous" value="1" class="w-4 h-4 rounded">
                        <label for="is_anonymous" class="text-white/80 text-sm">
                            Request anonymous referral (provider will not see your personal information)
                        </label>
                    </div>

                    <!-- Info Box -->
                    <div class="bg-blue-500/10 border border-blue-500/30 rounded-xl p-4">
                        <div class="flex items-start">
                            <i data-lucide="info" class="w-5 h-5 text-blue-400 mr-3 mt-0.5"></i>
                            <div>
                                <h4 class="font-semibold text-blue-300 mb-2">What happens next?</h4>
                                <ul class="text-blue-200 text-sm space-y-1">
                                    <li>• Your referral request will be sent to the selected provider</li>
                                    <li>• The provider will review your request and contact you</li>
                                    <li>• You can track the status in "My Referrals"</li>
                                    <li>• For urgent cases, please contact emergency services directly</li>
                                </ul>
                            </div>
                        </div>
                    </div>

                    <div class="flex items-center justify-end space-x-4 pt-4">
                        <a href="medical_support.php" class="btn btn-outline">Cancel</a>
                        <button type="submit" class="btn btn-primary">
                            <i data-lucide="send" class="w-4 h-4 mr-2"></i>
                            Submit Referral Request
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </main>

    <script src="https://unpkg.com/lucide@latest/dist/umd/lucide.js"></script>
    <script src="dashboard-enhanced.js"></script>
    <script>
        lucide.createIcons();

        // Form validation
        document.getElementById('referralForm')?.addEventListener('submit', function(e) {
            const providerId = this.querySelector('select[name="provider_id"]').value;
            const referralType = this.querySelector('select[name="referral_type"]').value;

            if (!providerId || !referralType) {
                e.preventDefault();
                alert('Please select a provider and referral type.');
                return false;
            }
        });
    </script>
</body>
</html>

