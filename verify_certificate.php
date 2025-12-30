<?php
session_start();
$lang = $_SESSION['lang'] ?? 'en';
$lang_file = $lang === 'bn' ? 'lang_bn.php' : 'lang_en.php';
$L = include($lang_file);

require_once 'includes/Database.php';

$database = new Database();
$models = new SafeSpaceModels($database);

$verificationCode = $_GET['code'] ?? '';

$certificate = null;
$isValid = false;

if (!empty($verificationCode)) {
    $certificate = $models->verifyCertificate($verificationCode);
    $isValid = ($certificate !== null);
}
?>
<!DOCTYPE html>
<html lang="<?= $lang ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Verify Certificate - SafeSpace Portal</title>

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
            </div>
        </div>
    </header>

    <main class="pt-20 pb-8">
        <div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8">
            <section class="mb-8 animate-fade-in">
                <div class="text-center">
                    <div class="w-20 h-20 bg-gradient-to-r from-yellow-500 via-amber-500 to-orange-500 rounded-3xl flex items-center justify-center mx-auto mb-6 shadow-2xl ring-4 ring-yellow-500/20">
                        <i data-lucide="shield-check" class="w-10 h-10 text-white drop-shadow-lg"></i>
                    </div>
                    <h1 class="heading-1 mb-4 text-white">Verify Certificate</h1>
                    <p class="body-large max-w-2xl mx-auto text-white/80 leading-relaxed">
                        Enter a certificate verification code to verify its authenticity.
                    </p>
                </div>
            </section>

            <div class="card card-glass p-6">
                <?php if (empty($verificationCode)): ?>
                    <form method="GET" class="space-y-5">
                        <div>
                            <label class="form-label text-white mb-2 flex items-center">
                                <i data-lucide="key" class="w-4 h-4 mr-2 text-yellow-400"></i>
                                Verification Code
                            </label>
                            <input type="text" name="code" class="form-input w-full"
                                   placeholder="Enter certificate verification code" required>
                            <p class="text-xs text-white/50 mt-2">
                                <i data-lucide="info" class="w-3 h-3 inline mr-1"></i>
                                Enter the verification code found on your certificate
                            </p>
                        </div>
                        <button type="submit" class="btn btn-primary w-full">
                            <i data-lucide="search" class="w-4 h-4 mr-2"></i>
                            Verify Certificate
                        </button>
                    </form>
                <?php elseif ($isValid && $certificate): ?>
                    <div class="text-center">
                        <div class="w-24 h-24 bg-gradient-to-r from-green-500 to-emerald-500 rounded-full flex items-center justify-center mx-auto mb-6">
                            <i data-lucide="check-circle" class="w-12 h-12 text-white"></i>
                        </div>
                        <h2 class="heading-2 text-white mb-2">Certificate Verified</h2>
                        <p class="text-green-300 mb-6">This certificate is authentic and valid.</p>

                        <div class="text-left space-y-3 mb-6">
                            <div class="p-4 bg-white/5 rounded-lg border border-white/10">
                                <p class="text-white/60 text-sm mb-1 flex items-center">
                                    <i data-lucide="book" class="w-4 h-4 mr-2 text-blue-400"></i>
                                    Course Title
                                </p>
                                <p class="text-white font-semibold text-lg"><?= htmlspecialchars($certificate['course_title']) ?></p>
                            </div>
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                                <div class="p-4 bg-white/5 rounded-lg border border-white/10">
                                    <p class="text-white/60 text-sm mb-1 flex items-center">
                                        <i data-lucide="hash" class="w-4 h-4 mr-2 text-purple-400"></i>
                                        Certificate Number
                                    </p>
                                    <p class="text-white font-mono text-sm"><?= htmlspecialchars($certificate['certificate_number']) ?></p>
                                </div>
                                <div class="p-4 bg-white/5 rounded-lg border border-white/10">
                                    <p class="text-white/60 text-sm mb-1 flex items-center">
                                        <i data-lucide="user" class="w-4 h-4 mr-2 text-green-400"></i>
                                        Issued To
                                    </p>
                                    <p class="text-white font-semibold"><?= htmlspecialchars($certificate['display_name'] ?? 'User') ?></p>
                                </div>
                                <div class="p-4 bg-white/5 rounded-lg border border-white/10">
                                    <p class="text-white/60 text-sm mb-1 flex items-center">
                                        <i data-lucide="calendar" class="w-4 h-4 mr-2 text-orange-400"></i>
                                        Issue Date
                                    </p>
                                    <p class="text-white"><?= date('F j, Y', strtotime($certificate['issued_at'])) ?></p>
                                </div>
                                <?php if ($certificate['expires_at']): ?>
                                    <div class="p-4 bg-white/5 rounded-lg border border-white/10">
                                        <p class="text-white/60 text-sm mb-1 flex items-center">
                                            <i data-lucide="clock" class="w-4 h-4 mr-2 text-red-400"></i>
                                            Expires On
                                        </p>
                                        <p class="text-white"><?= date('F j, Y', strtotime($certificate['expires_at'])) ?></p>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>

                        <div class="flex items-center justify-center space-x-4">
                            <a href="verify_certificate.php" class="btn btn-outline">
                                <i data-lucide="arrow-left" class="w-4 h-4 mr-2"></i>
                                Verify Another
                            </a>
                            <?php if ($certificate['certificate_file_path']): ?>
                                <a href="<?= htmlspecialchars($certificate['certificate_file_path']) ?>" class="btn btn-primary" target="_blank">
                                    <i data-lucide="download" class="w-4 h-4 mr-2"></i>
                                    Download
                                </a>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="text-center">
                        <div class="w-24 h-24 bg-gradient-to-r from-red-500 to-rose-500 rounded-full flex items-center justify-center mx-auto mb-6">
                            <i data-lucide="x-circle" class="w-12 h-12 text-white"></i>
                        </div>
                        <h2 class="heading-2 text-white mb-2">Certificate Not Found</h2>
                        <p class="text-red-300 mb-8">The verification code you entered is invalid or the certificate does not exist.</p>
                        <a href="verify_certificate.php" class="btn btn-outline">
                            <i data-lucide="arrow-left" class="w-4 h-4 mr-2"></i>
                            Try Again
                        </a>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </main>

    <script src="https://unpkg.com/lucide@latest/dist/umd/lucide.js"></script>
    <script src="dashboard-enhanced.js"></script>
    <script>
        lucide.createIcons();
    </script>
</body>
</html>

