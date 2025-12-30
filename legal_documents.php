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

// Handle document download
if (isset($_GET['download']) && is_numeric($_GET['download'])) {
    $docId = intval($_GET['download']);
    $document = $models->getLegalDocumentById($docId);

    if ($document && $document['status'] === 'active') {
        // Increment download count
        $models->incrementDocumentDownload($docId);

        // Redirect to document URL or file
        if ($document['file_url']) {
            header('Location: ' . $document['file_url']);
            exit;
        } elseif ($document['file_path'] && file_exists($document['file_path'])) {
            header('Content-Type: application/pdf');
            header('Content-Disposition: attachment; filename="' . basename($document['file_path']) . '"');
            readfile($document['file_path']);
            exit;
        }
    }
}

// Get filters
$filters = [
    'document_type' => $_GET['type'] ?? '',
    'category' => $_GET['category'] ?? '',
    'language' => $_GET['language'] ?? '',
    'is_premium' => $_GET['premium'] ?? ''
];

// Search functionality
$searchTerm = $_GET['search'] ?? '';
$documents = [];

if (!empty($searchTerm)) {
    $documents = $models->searchLegalDocuments($searchTerm);
} else {
    $documents = $models->getLegalDocuments($filters);
}

// Get unique categories
$allDocs = $models->getLegalDocuments([]);
$categories = array_unique(array_filter(array_column($allDocs, 'category')));
sort($categories);
?>
<!DOCTYPE html>
<html lang="<?= $lang ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Legal Documents - SafeSpace Portal</title>

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
                    <a href="my_consultations.php" class="text-white/70 hover:text-white transition-colors duration-200">My Consultations</a>
                    <a href="legal_documents.php" class="text-white font-medium">Documents</a>
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
                    <div class="w-20 h-20 bg-gradient-to-r from-orange-500 via-red-500 to-pink-500 rounded-3xl flex items-center justify-center mx-auto mb-6 shadow-2xl ring-4 ring-orange-500/20">
                        <i data-lucide="file-text" class="w-10 h-10 text-white drop-shadow-lg"></i>
                    </div>
                    <h1 class="heading-1 mb-4 text-white">Legal Documents Library</h1>
                    <p class="body-large max-w-2xl mx-auto text-white/80 leading-relaxed">
                        Access legal forms, templates, guidelines, and reference documents. Download and use them for your legal needs.
                    </p>
                </div>
            </section>

            <!-- Search and Filters -->
            <section class="mb-8">
                <div class="card card-glass p-6">
                    <form method="GET" action="legal_documents.php" class="space-y-4">
                        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
                            <!-- Search -->
                            <div class="lg:col-span-4">
                                <label class="form-label text-white mb-2">Search</label>
                                <input type="text" name="search" value="<?= htmlspecialchars($searchTerm) ?>"
                                       placeholder="Search documents..."
                                       class="form-input w-full">
                            </div>

                            <!-- Document Type -->
                            <div>
                                <label class="form-label text-white mb-2">Document Type</label>
                                <select name="type" class="form-input w-full">
                                    <option value="">All Types</option>
                                    <option value="form" <?= $filters['document_type'] === 'form' ? 'selected' : '' ?>>Form</option>
                                    <option value="template" <?= $filters['document_type'] === 'template' ? 'selected' : '' ?>>Template</option>
                                    <option value="guideline" <?= $filters['document_type'] === 'guideline' ? 'selected' : '' ?>>Guideline</option>
                                    <option value="law_reference" <?= $filters['document_type'] === 'law_reference' ? 'selected' : '' ?>>Law Reference</option>
                                    <option value="case_study" <?= $filters['document_type'] === 'case_study' ? 'selected' : '' ?>>Case Study</option>
                                </select>
                            </div>

                            <!-- Category -->
                            <div>
                                <label class="form-label text-white mb-2">Category</label>
                                <select name="category" class="form-input w-full">
                                    <option value="">All Categories</option>
                                    <?php foreach ($categories as $cat): ?>
                                        <option value="<?= htmlspecialchars($cat) ?>" <?= $filters['category'] === $cat ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($cat) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <!-- Language -->
                            <div>
                                <label class="form-label text-white mb-2">Language</label>
                                <select name="language" class="form-input w-full">
                                    <option value="">All Languages</option>
                                    <option value="bn" <?= $filters['language'] === 'bn' ? 'selected' : '' ?>>Bengali</option>
                                    <option value="en" <?= $filters['language'] === 'en' ? 'selected' : '' ?>>English</option>
                                </select>
                            </div>

                            <!-- Premium -->
                            <div>
                                <label class="form-label text-white mb-2">Premium</label>
                                <select name="premium" class="form-input w-full">
                                    <option value="">All</option>
                                    <option value="0" <?= $filters['is_premium'] === '0' ? 'selected' : '' ?>>Free</option>
                                    <option value="1" <?= $filters['is_premium'] === '1' ? 'selected' : '' ?>>Premium</option>
                                </select>
                            </div>
                        </div>

                        <div class="flex items-center space-x-4">
                            <button type="submit" class="btn btn-primary">
                                <i data-lucide="search" class="w-4 h-4 mr-2"></i>
                                Search
                            </button>
                            <a href="legal_documents.php" class="btn btn-outline">
                                <i data-lucide="refresh-cw" class="w-4 h-4 mr-2"></i>
                                Reset
                            </a>
                        </div>
                    </form>
                </div>
            </section>

            <!-- Documents List -->
            <section>
                <div class="mb-6 flex items-center justify-between">
                    <h2 class="heading-2 text-white">Available Documents (<?= count($documents) ?>)</h2>
                </div>

                <?php if (empty($documents)): ?>
                    <div class="card card-glass text-center p-12">
                        <div class="w-20 h-20 bg-gradient-to-r from-gray-500 to-gray-600 rounded-full flex items-center justify-center mx-auto mb-6">
                            <i data-lucide="file-x" class="w-10 h-10 text-white"></i>
                        </div>
                        <h3 class="heading-3 text-white mb-3">No Documents Found</h3>
                        <p class="text-white/60 mb-6">Try adjusting your search filters or search terms.</p>
                        <a href="legal_documents.php" class="btn btn-primary">View All Documents</a>
                    </div>
                <?php else: ?>
                    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                        <?php foreach ($documents as $index => $doc):
                            $typeIcons = [
                                'form' => 'file-edit',
                                'template' => 'file-template',
                                'guideline' => 'file-text',
                                'law_reference' => 'scale',
                                'case_study' => 'briefcase'
                            ];
                            $icon = $typeIcons[$doc['document_type']] ?? 'file-text';

                            $typeColors = [
                                'form' => 'from-blue-500 to-cyan-500',
                                'template' => 'from-purple-500 to-pink-500',
                                'guideline' => 'from-green-500 to-emerald-500',
                                'law_reference' => 'from-orange-500 to-red-500',
                                'case_study' => 'from-indigo-500 to-purple-500'
                            ];
                            $colorClass = $typeColors[$doc['document_type']] ?? 'from-gray-500 to-gray-600';
                        ?>
                            <div class="card card-glass card-hover animate-slide-in" style="animation-delay: <?= $index * 0.1 ?>s;">
                                <div class="card-body p-6">
                                    <div class="flex items-start justify-between mb-4">
                                        <div class="w-12 h-12 bg-gradient-to-r <?= $colorClass ?> rounded-xl flex items-center justify-center">
                                            <i data-lucide="<?= $icon ?>" class="w-6 h-6 text-white"></i>
                                        </div>
                                        <?php if ($doc['is_premium']): ?>
                                            <span class="px-2 py-1 rounded-full text-xs font-semibold bg-yellow-500/20 text-yellow-300 border border-yellow-500/30">
                                                Premium
                                            </span>
                                        <?php endif; ?>
                                    </div>

                                    <h3 class="heading-4 text-white mb-2"><?= htmlspecialchars($doc['title']) ?></h3>

                                    <div class="flex items-center space-x-2 mb-3">
                                        <span class="text-xs px-2 py-1 rounded-full bg-white/10 text-white/70">
                                            <?= ucfirst(str_replace('_', ' ', $doc['document_type'])) ?>
                                        </span>
                                        <?php if ($doc['category']): ?>
                                            <span class="text-xs px-2 py-1 rounded-full bg-white/10 text-white/70">
                                                <?= htmlspecialchars($doc['category']) ?>
                                            </span>
                                        <?php endif; ?>
                                        <?php if ($doc['language'] !== 'both'): ?>
                                            <span class="text-xs px-2 py-1 rounded-full bg-white/10 text-white/70">
                                                <?= strtoupper($doc['language']) ?>
                                            </span>
                                        <?php endif; ?>
                                    </div>

                                    <?php if ($doc['description']): ?>
                                        <p class="text-sm text-white/70 mb-4 line-clamp-3">
                                            <?= htmlspecialchars($doc['description']) ?>
                                        </p>
                                    <?php endif; ?>

                                    <div class="flex items-center justify-between pt-4 border-t border-white/10">
                                        <div class="flex items-center text-xs text-white/50">
                                            <i data-lucide="download" class="w-4 h-4 mr-1"></i>
                                            <?= number_format($doc['download_count']) ?> downloads
                                        </div>
                                        <a href="legal_documents.php?download=<?= $doc['id'] ?>"
                                           class="btn btn-primary btn-sm"
                                           target="<?= $doc['file_url'] ? '_blank' : '_self' ?>">
                                            <i data-lucide="download" class="w-4 h-4 mr-2"></i>
                                            Download
                                        </a>
                                    </div>
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

