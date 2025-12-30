<?php
session_start();
$lang = $_SESSION['lang'] ?? 'en';
$lang_file = $lang === 'bn' ? 'lang_bn.php' : 'lang_en.php';
$L = include($lang_file);

// Include database handler
require_once 'includes/Database.php';

// Initialize database
$database = new Database();
$models = new SafeSpaceModels($database);

// Get user ID from session
$userId = $_SESSION['user_id'] ?? 1; // Default for demo

// Get report ID from URL
$reportId = $_GET['id'] ?? null;

if (!$reportId) {
    header('Location: my_reports.php');
    exit;
}

// Get report details
$report = $models->getIncidentReportById($reportId);

// Security Check: Ensure report exists and belongs to the logged-in user
if (!$report || $report['user_id'] != $userId) {
    // You can set an error message in the session if you want
    $_SESSION['error'] = "Report not found or access denied.";
    header('Location: my_reports.php');
    exit;
}

$message = '';
$error = '';

// Handle form submission
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    try {
        // Collect data from form
        $updateData = [
            'title' => $_POST['title'] ?? $report['title'],
            'description' => $_POST['description'] ?? $report['description'],
            'incident_date' => $_POST['incident_date'] ?? $report['incident_date'],
            'category' => $_POST['category'] ?? $report['category'],
            'severity' => $_POST['severity'] ?? $report['severity'],
            'location_name' => $_POST['location_name'] ?? $report['location_name'],
            'address' => $_POST['address'] ?? $report['address'],
            'latitude' => empty($_POST['latitude']) ? null : $_POST['latitude'],
            'longitude' => empty($_POST['longitude']) ? null : $_POST['longitude'],
            'witness_count' => empty($_POST['witness_count']) ? 0 : intval($_POST['witness_count']),
            'is_anonymous' => isset($_POST['is_anonymous']) ? 1 : 0,
            'is_public' => isset($_POST['is_public']) ? 1 : 0,
            'updated_date' => date('Y-m-d H:i:s')
            // 'evidence_files' is more complex and would require file upload handling.
            // For simplicity, we'll skip re-uploading files in this edit form.
        ];

        // Update the report in the database
        if ($models->updateIncidentReport($reportId, $updateData)) {
            $message = "Report updated successfully!";
            // Re-fetch report data to show updated info
            $report = $models->getIncidentReportById($reportId);

            // Redirect back to the view page after a short delay
            header("Refresh:2; url=view_report.php?id=$reportId");

        } else {
            $error = "Failed to update report.";
        }
    } catch (Exception $e) {
        $error = "An error occurred: " . $e->getMessage();
    }
}

?>
<!DOCTYPE html>
<html lang="<?= $lang ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Report #<?= $reportId ?> - SafeSpace Portal</title>

    <link rel="icon" type="image/svg+xml" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><text y='.9em' font-size='90'>🛡️</text></svg>">

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&family=Poppins:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">

    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/lucide/0.263.1/lucide.min.css">

    <script src="https://cdn.tailwindcss.com"></script>

    <link rel="stylesheet" href="design-system.css">
    <link rel="stylesheet" href="dashboard-styles.css">

    <style>
        .form-input:focus {
            transform: scale(1.02);
            box-shadow: 0 0 0 3px rgba(14, 165, 233, 0.3);
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
                    <a href="my_reports.php" class="text-white font-medium">My Reports</a>
                    <a href="dispute_center.php" class="text-white/70 hover:text-white transition-colors duration-200">Disputes</a>
                    <a href="safety_resources.php" class="text-white/70 hover:text-white transition-colors duration-200">Resources</a>
                </nav>
                <div class="flex items-center space-x-4">
                    <a href="view_report.php?id=<?= $reportId ?>" class="btn btn-ghost">
                        <i data-lucide="arrow-left" class="w-4 h-4"></i>
                        Back to View Report
                    </a>
                </div>
            </div>
        </div>
    </header>

    <main class="pt-20 pb-8">
        <div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8">

            <section class="mb-8 animate-on-scroll">
                <div class="flex items-center space-x-4 mb-4">
                    <div class="w-16 h-16 bg-gradient-to-r from-primary-500 to-secondary-500 rounded-2xl flex items-center justify-center animate-scale-in">
                        <i data-lucide="edit" class="w-8 h-8 text-white"></i>
                    </div>
                    <div class="animate-fade-in-left">
                        <h1 class="heading-1 mb-2">Edit Report #<?= $reportId ?></h1>
                        <p class="body-large" style="color: var(--text-secondary);">
                            Update the details of your incident report.
                        </p>
                    </div>
                </div>
            </section>

            <?php if ($message): ?>
                <div class="mb-8 card card-glass border-l-4 border-green-500">
                    <div class="card-body">
                        <div class="flex items-center">
                            <i data-lucide="check-circle" class="w-5 h-5 text-green-500 mr-3"></i>
                            <p class="text-green-300"><?= $message ?> Redirecting...</p>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <?php if ($error): ?>
                <div class="mb-8 card card-glass border-l-4 border-red-500">
                    <div class="card-body">
                        <div class="flex items-center">
                            <i data-lucide="alert-circle" class="w-5 h-5 text-red-500 mr-3"></i>
                            <p class="text-red-300"><?= $error ?></p>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <section class="animate-on-scroll">
                <div class="card card-glass">
                    <div class="card-body">
                        <form method="POST" action="edit_report.php?id=<?= $reportId ?>" class="space-y-6">

                            <div>
                                <h2 class="heading-3 border-b border-white/10 pb-2 mb-4" style="color: var(--text-primary);">Basic Information</h2>
                                <div class="grid grid-cols-1 gap-6">
                                    <div>
                                        <label for="title" class="form-label font-semibold">Incident Title</label>
                                        <input type="text" id="title" name="title" class="form-input mt-2"
                                               value="<?= htmlspecialchars($report['title']) ?>" required>
                                    </div>
                                    <div>
                                        <label for="description" class="form-label font-semibold">Description</label>
                                        <textarea id="description" name="description" rows="5" class="form-input mt-2"
                                                  required><?= htmlspecialchars($report['description']) ?></textarea>
                                    </div>
                                </div>
                            </div>

                            <div>
                                <h2 class="heading-3 border-b border-white/10 pb-2 mb-4" style="color: var(--text-primary);">Incident Details</h2>
                                <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                                    <div>
                                        <label for="incident_date" class="form-label font-semibold">Incident Date & Time</label>
                                        <input type="datetime-local" id="incident_date" name="incident_date" class="form-input mt-2"
                                               value="<?= date('Y-m-d\TH:i', strtotime($report['incident_date'])) ?>" required>
                                    </div>
                                    <div>
                                        <label for="category" class="form-label font-semibold">Category</label>
                                        <select id="category" name="category" class="form-input mt-2" required>
                                            <option value="harassment" <?= $report['category'] === 'harassment' ? 'selected' : '' ?>>Harassment</option>
                                            <option value="assault" <?= $report['category'] === 'assault' ? 'selected' : '' ?>>Assault</option>
                                            <option value="theft" <?= $report['category'] === 'theft' ? 'selected' : '' ?>>Theft</option>
                                            <option value="vandalism" <?= $report['category'] === 'vandalism' ? 'selected' : '' ?>>Vandalism</option>
                                            <option value="stalking" <?= $report['category'] === 'stalking' ? 'selected' : '' ?>>Stalking</option>
                                            <option value="cyberbullying" <?= $report['category'] === 'cyberbullying' ? 'selected' : '' ?>>Cyberbullying</option>
                                            <option value="discrimination" <?= $report['category'] === 'discrimination' ? 'selected' : '' ?>>Discrimination</option>
                                            <option value="other" <?= $report['category'] === 'other' ? 'selected' : '' ?>>Other</option>
                                        </select>
                                    </div>
                                    <div>
                                        <label for="severity" class="form-label font-semibold">Severity</label>
                                        <select id="severity" name="severity" class="form-input mt-2" required>
                                            <option value="low" <?= $report['severity'] === 'low' ? 'selected' : '' ?>>Low</option>
                                            <option value="medium" <?= $report['severity'] === 'medium' ? 'selected' : '' ?>>Medium</option>
                                            <option value="high" <?= $report['severity'] === 'high' ? 'selected' : '' ?>>High</option>
                                            <option value="critical" <?= $report['severity'] === 'critical' ? 'selected' : '' ?>>Critical</option>
                                        </select>
                                    </div>
                                </div>
                            </div>

                            <div>
                                <h2 class="heading-3 border-b border-white/10 pb-2 mb-4" style="color: var(--text-primary);">Location & Witnesses</h2>
                                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                                    <div>
                                        <label for="location_name" class="form-label font-semibold">Location Name (Optional)</label>
                                        <input type="text" id="location_name" name="location_name" class="form-input mt-2"
                                               value="<?= htmlspecialchars($report['location_name']) ?>">
                                    </div>
                                    <div>
                                        <label for="address" class="form-label font-semibold">Address (Optional)</label>
                                        <input type="text" id="address" name="address" class="form-input mt-2"
                                               value="<?= htmlspecialchars($report['address']) ?>">
                                    </div>
                                    <div>
                                        <label for="latitude" class="form-label font-semibold">Latitude (Optional)</label>
                                        <input type="text" id="latitude" name="latitude" class="form-input mt-2"
                                               value="<?= htmlspecialchars($report['latitude']) ?>">
                                    </div>
                                    <div>
                                        <label for="longitude" class="form-label font-semibold">Longitude (Optional)</label>
                                        <input type="text" id="longitude" name="longitude" class="form-input mt-2"
                                               value="<?= htmlspecialchars($report['longitude']) ?>">
                                    </div>
                                    <div>
                                        <label for="witness_count" class="form-label font-semibold">Witness Count (Optional)</label>
                                        <input type="number" id="witness_count" name="witness_count" class="form-input mt-2"
                                               value="<?= htmlspecialchars($report['witness_count']) ?>" min="0">
                                    </div>
                                </div>
                            </div>

                            <div>
                                <h2 class="heading-3 border-b border-white/10 pb-2 mb-4" style="color: var(--text-primary);">Privacy Settings</h2>
                                <div class="space-y-4">
                                    <label class="flex items-center space-x-3 cursor-pointer">
                                        <input type="checkbox" id="is_anonymous" name="is_anonymous" class="form-checkbox"
                                               <?= $report['is_anonymous'] ? 'checked' : '' ?>>
                                        <span class="text-sm font-medium" style="color: var(--text-secondary);">Submit Anonymously</span>
                                    </label>
                                    <label class="flex items-center space-x-3 cursor-pointer">
                                        <input type="checkbox" id="is_public" name="is_public" class="form-checkbox"
                                               <?= $report['is_public'] ? 'checked' : '' ?>>
                                        <span class="text-sm font-medium" style="color: var(--text-secondary);">Make this report public (visible to community)</span>
                                    </label>
                                </div>
                            </div>

                            <div class="flex items-center justify-end space-x-4 pt-6 border-t border-white/10">
                                <a href="view_report.php?id=<?= $reportId ?>" class="btn btn-outline">
                                    Cancel
                                </a>
                                <button type="submit" class="btn btn-primary btn-lg btn-animate">
                                    <i data-lucide="save" class="w-5 h-5 mr-2"></i>
                                    Save Changes
                                </button>
                            </div>

                        </form>
                    </div>
                </div>
            </section>

        </div>
    </main>

    <script src="https://unpkg.com/lucide@latest/dist/umd/lucide.js"></script>
    <script src="dashboard-enhanced.js"></script>
    <script>
        // Initialize Lucide icons
        lucide.createIcons();
    </script>
</body>
</html>