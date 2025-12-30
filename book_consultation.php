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
    $provider = $models->getLegalAidProviderById($providerId);
}

// Get all providers for dropdown
$allProviders = $models->getLegalAidProviders(['limit' => 100]);

// Get user reports for linking
$userReports = $models->getUserReports($userId);

// Handle form submission
$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'book_consultation') {
    try {
        $consultationData = [
            'user_id' => $userId,
            'report_id' => !empty($_POST['report_id']) ? $_POST['report_id'] : null,
            'provider_id' => $_POST['provider_id'],
            'consultation_type' => $_POST['consultation_type'] ?? 'initial',
            'subject' => trim($_POST['subject']),
            'description' => trim($_POST['description']),
            'preferred_date' => !empty($_POST['preferred_date']) ? $_POST['preferred_date'] : null,
            'preferred_time' => !empty($_POST['preferred_time']) ? $_POST['preferred_time'] : null,
            'cost_bdt' => 0.00
        ];

        if (empty($consultationData['subject']) || empty($consultationData['description']) || empty($consultationData['provider_id'])) {
            throw new Exception('Please fill in all required fields.');
        }

        $consultationId = $models->createLegalConsultation($consultationData);

        if ($consultationId) {
            // Create notification
            $models->createNotification([
                'user_id' => $userId,
                'title' => 'Consultation Request Submitted',
                'message' => 'Your legal consultation request #' . $consultationId . ' has been submitted. The provider will contact you within 48 hours.',
                'type' => 'report_update',
                'action_url' => 'my_consultations.php'
            ]);

            header('Location: legal_aid.php?success=1');
            exit;
        } else {
            throw new Exception('Failed to submit consultation request. Please try again.');
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
    <title>Book Legal Consultation - SafeSpace Portal</title>

    <link rel="icon" type="image/svg+xml" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><text y='.9em' font-size='90'>🛡️</text></svg>">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&family=Poppins:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/lucide/0.263.1/lucide.min.css">
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="design-system.css">
    <link rel="stylesheet" href="dashboard-styles.css">

    <style>
        .liquid-glass {
            background: linear-gradient(135deg, rgba(255, 255, 255, 0.1), rgba(255, 255, 255, 0.05));
            backdrop-filter: blur(20px);
            border: 1px solid rgba(255, 255, 255, 0.1);
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.1);
        }

        .form-input-enhanced {
            background: rgba(255, 255, 255, 0.05);
            border: 2px solid rgba(255, 255, 255, 0.1);
            border-radius: 12px;
            padding: 16px;
            color: white;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            backdrop-filter: blur(10px);
        }

        .form-input-enhanced:focus {
            background: rgba(255, 255, 255, 0.08);
            border-color: var(--accent-teal);
            box-shadow: 0 0 0 4px rgba(0, 212, 255, 0.1);
            outline: none;
        }

        .provider-preview {
            background: linear-gradient(135deg, rgba(139, 92, 246, 0.15), rgba(139, 92, 246, 0.05));
            border: 1px solid rgba(139, 92, 246, 0.3);
            border-radius: 16px;
            padding: 20px;
        }
    </style>
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
                    <a href="legal_aid.php" class="text-white font-medium">Legal Aid</a>
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
        <div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8">
            <section class="mb-8 animate-fade-in">
                <div class="text-center">
                    <div class="w-20 h-20 bg-gradient-to-r from-purple-500 via-indigo-500 to-blue-500 rounded-3xl flex items-center justify-center mx-auto mb-6 shadow-2xl ring-4 ring-purple-500/20">
                        <i data-lucide="calendar-plus" class="w-10 h-10 text-white drop-shadow-lg"></i>
                    </div>
                    <h1 class="heading-1 mb-4 text-white">Book Legal Consultation</h1>
                    <p class="body-large max-w-2xl mx-auto text-white/80 leading-relaxed">
                        Request a consultation with a verified legal aid provider. Fill out the form below and the provider will contact you within 48 hours.
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

            <?php if ($provider): ?>
                <div class="mb-6 provider-preview">
                    <h3 class="heading-4 text-white mb-3">Selected Provider</h3>
                    <div class="flex items-start justify-between">
                        <div class="flex-1">
                            <h4 class="text-lg font-semibold text-white mb-1"><?= htmlspecialchars($provider['organization_name']) ?></h4>
                            <?php if ($provider['contact_person']): ?>
                                <p class="text-sm text-white/70 mb-2"><?= htmlspecialchars($provider['contact_person']) ?></p>
                            <?php endif; ?>
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

            <section class="animate-fade-in-up">
                <div class="liquid-glass rounded-3xl p-8">
                    <form method="POST" class="space-y-6" id="consultationForm">
                        <input type="hidden" name="action" value="book_consultation">

                        <!-- Provider Selection -->
                        <div>
                            <label class="form-label text-white mb-2 flex items-center">
                                <i data-lucide="scale" class="w-4 h-4 mr-2 text-purple-400"></i>
                                Legal Aid Provider <span class="text-red-400">*</span>
                            </label>
                            <select name="provider_id" class="form-input-enhanced w-full" required>
                                <option value="">Select a provider...</option>
                                <?php foreach ($allProviders as $p): ?>
                                    <option value="<?= $p['id'] ?>" <?= $providerId == $p['id'] ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($p['organization_name']) ?> - <?= htmlspecialchars($p['city'] ?? 'N/A') ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <!-- Consultation Type -->
                        <div>
                            <label class="form-label text-white mb-2 flex items-center">
                                <i data-lucide="file-text" class="w-4 h-4 mr-2 text-blue-400"></i>
                                Consultation Type
                            </label>
                            <select name="consultation_type" class="form-input-enhanced w-full">
                                <option value="initial">Initial Consultation</option>
                                <option value="follow_up">Follow-up</option>
                                <option value="emergency">Emergency</option>
                                <option value="document_review">Document Review</option>
                            </select>
                        </div>

                        <!-- Link to Report (Optional) -->
                        <?php if (!empty($userReports)): ?>
                            <div>
                                <label class="form-label text-white mb-2 flex items-center">
                                    <i data-lucide="alert-triangle" class="w-4 h-4 mr-2 text-orange-400"></i>
                                    Link to Incident Report (Optional)
                                </label>
                                <select name="report_id" class="form-input-enhanced w-full">
                                    <option value="">None - General Consultation</option>
                                    <?php foreach ($userReports as $report): ?>
                                        <option value="<?= $report['id'] ?>">
                                            Report #<?= $report['id'] ?> - <?= htmlspecialchars($report['title']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        <?php endif; ?>

                        <!-- Subject -->
                        <div>
                            <label class="form-label text-white mb-2 flex items-center">
                                <i data-lucide="message-square" class="w-4 h-4 mr-2 text-green-400"></i>
                                Subject <span class="text-red-400">*</span>
                            </label>
                            <input type="text" name="subject" class="form-input-enhanced w-full"
                                   placeholder="Brief subject of your consultation" required
                                   maxlength="255">
                        </div>

                        <!-- Description -->
                        <div>
                            <label class="form-label text-white mb-2 flex items-center">
                                <i data-lucide="align-left" class="w-4 h-4 mr-2 text-indigo-400"></i>
                                Description <span class="text-red-400">*</span>
                            </label>
                            <textarea name="description" rows="6" class="form-input-enhanced w-full"
                                      placeholder="Please provide details about your legal issue. Include relevant information, dates, and any questions you have." required></textarea>
                        </div>

                        <!-- Preferred Date and Time -->
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div>
                                <label class="form-label text-white mb-2 flex items-center">
                                    <i data-lucide="calendar" class="w-4 h-4 mr-2 text-pink-400"></i>
                                    Preferred Date
                                </label>
                                <input type="date" name="preferred_date" class="form-input-enhanced w-full"
                                       min="<?= date('Y-m-d') ?>">
                            </div>
                            <div>
                                <label class="form-label text-white mb-2 flex items-center">
                                    <i data-lucide="clock" class="w-4 h-4 mr-2 text-cyan-400"></i>
                                    Preferred Time
                                </label>
                                <input type="time" name="preferred_time" class="form-input-enhanced w-full">
                            </div>
                        </div>

                        <!-- Info Box -->
                        <div class="bg-blue-500/10 border border-blue-500/30 rounded-xl p-4">
                            <div class="flex items-start">
                                <i data-lucide="info" class="w-5 h-5 text-blue-400 mr-3 mt-0.5"></i>
                                <div>
                                    <h4 class="font-semibold text-blue-300 mb-2">What happens next?</h4>
                                    <ul class="text-blue-200 text-sm space-y-1">
                                        <li>• Your consultation request will be sent to the selected provider</li>
                                        <li>• The provider will review your request within 24-48 hours</li>
                                        <li>• You will be contacted via phone or email to schedule the consultation</li>
                                        <li>• You can track the status in "My Consultations"</li>
                                    </ul>
                                </div>
                            </div>
                        </div>

                        <!-- Submit Button -->
                        <div class="flex items-center justify-end space-x-4 pt-4">
                            <a href="legal_aid.php" class="btn btn-outline">Cancel</a>
                            <button type="submit" class="btn btn-primary">
                                <i data-lucide="send" class="w-4 h-4 mr-2"></i>
                                Submit Request
                            </button>
                        </div>
                    </form>
                </div>
            </section>
        </div>
    </main>

    <script src="https://unpkg.com/lucide@latest/dist/umd/lucide.js"></script>
    <script src="dashboard-enhanced.js"></script>
    <script>
        lucide.createIcons();

        // Form validation
        document.getElementById('consultationForm').addEventListener('submit', function(e) {
            const providerId = this.querySelector('select[name="provider_id"]').value;
            const subject = this.querySelector('input[name="subject"]').value.trim();
            const description = this.querySelector('textarea[name="description"]').value.trim();

            if (!providerId || !subject || !description) {
                e.preventDefault();
                alert('Please fill in all required fields.');
                return false;
            }

            if (description.length < 20) {
                e.preventDefault();
                alert('Please provide a more detailed description (at least 20 characters).');
                return false;
            }
        });
    </script>
</body>
</html>

