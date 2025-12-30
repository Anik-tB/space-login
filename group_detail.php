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

$groupId = $_GET['id'] ?? null;

if (!$groupId) {
    header('Location: community_groups.php');
    exit;
}

// Get group details
$group = $models->getNeighborhoodGroupById($groupId);

if (!$group) {
    header('Location: community_groups.php?error=Group not found');
    exit;
}

// Allow viewing if active OR if user is the creator (even if pending)
$canView = ($group['status'] === 'active') || ($group['created_by'] == $userId);

if (!$canView) {
    header('Location: community_groups.php?error=Group not available');
    exit;
}

// Check if user is member
$isMember = $models->isGroupMember($groupId, $userId);
$memberInfo = $isMember ? $models->isGroupMember($groupId, $userId) : null;

// Get group members
$members = $models->getGroupMembers($groupId);

// --- FIX 1: Re-add the $alerts query to get the count for the stats card ---
$alertFilters = [
    'status' => 'active' // We only need active alerts for the stat count
];
$alerts = $models->getGroupAlerts($groupId, $alertFilters);


// Handle alert creation and media upload
$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'create_alert' && $isMember) {
        try {
            $alertData = [
                'group_id' => $groupId,
                'posted_by' => $userId,
                'alert_type' => $_POST['alert_type'] ?? 'general',
                'title' => trim($_POST['title']),
                'message' => trim($_POST['message']),
                'location_details' => trim($_POST['location_details'] ?? ''),
                'severity' => $_POST['severity'] ?? 'medium',
                'expires_at' => !empty($_POST['expires_at']) ? $_POST['expires_at'] : null
            ];

            if (empty($alertData['title']) || empty($alertData['message'])) {
                throw new Exception('Please fill in title and message.');
            }

            $alertId = $models->createGroupAlert($alertData);

            if ($alertId) {
                // Handle file uploads if any
                if (!empty($_FILES['media_files']['name'][0])) {
                    $uploadDir = 'uploads/group_media/';
                    if (!is_dir($uploadDir)) {
                        mkdir($uploadDir, 0755, true);
                    }

                    $allowedTypes = [
                        'image/jpeg', 'image/jpg', 'image/png', 'image/gif', 'image/webp',
                        'video/mp4', 'video/mpeg', 'video/quicktime', 'video/x-msvideo', 'video/webm',
                        'application/pdf', 'application/msword',
                        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                        'text/plain'
                    ];

                    $maxImageSize = 10 * 1024 * 1024; // 10MB
                    $maxVideoSize = 100 * 1024 * 1024; // 100MB
                    $maxDocSize = 20 * 1024 * 1024; // 20MB

                    foreach ($_FILES['media_files']['tmp_name'] as $key => $tmp_name) {
                        if ($_FILES['media_files']['error'][$key] === UPLOAD_ERR_OK) {
                            $fileName = $_FILES['media_files']['name'][$key];
                            $fileType = $_FILES['media_files']['type'][$key];
                            $fileSize = $_FILES['media_files']['size'][$key];
                            $fileExtension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));

                            // Determine file type category
                            $fileTypeCategory = 'other';
                            if (strpos($fileType, 'image/') === 0) {
                                $fileTypeCategory = 'image';
                                $maxSize = $maxImageSize;
                            } elseif (strpos($fileType, 'video/') === 0) {
                                $fileTypeCategory = 'video';
                                $maxSize = $maxVideoSize;
                            } elseif (strpos($fileType, 'application/') === 0 || strpos($fileType, 'text/') === 0) {
                                $fileTypeCategory = 'document';
                                $maxSize = $maxDocSize;
                            } else {
                                continue; // Skip unsupported types
                            }

                            if ($fileSize > $maxSize) {
                                continue; // Skip oversized files
                            }

                            $uniqueFileName = 'group_' . $groupId . '_' . uniqid() . '_' . time() . '_' . preg_replace('/[^a-zA-Z0-9._-]/', '_', $fileName);
                            $filePath = $uploadDir . $uniqueFileName;

                            if (move_uploaded_file($tmp_name, $filePath)) {
                                // Create thumbnail for images (using same path for now)
                                $thumbnailPath = null;
                                if ($fileTypeCategory === 'image') {
                                    $thumbnailPath = $filePath; // For now, use same path
                                }

                                // Save media record
                                $models->createGroupMedia([
                                    'group_id' => $groupId,
                                    'alert_id' => $alertId,
                                    'uploaded_by' => $userId,
                                    'file_name' => $fileName,
                                    'file_path' => $filePath,
                                    'file_type' => $fileTypeCategory,
                                    'file_size_bytes' => $fileSize,
                                    'mime_type' => $fileType,
                                    'thumbnail_path' => $thumbnailPath,
                                    'is_public' => 1
                                ]);
                            }
                        }
                    }
                }

                $message = 'Alert posted successfully!';
                // Refresh alerts count
                $alerts = $models->getGroupAlerts($groupId, $alertFilters);
            } else {
                throw new Exception('Failed to post alert. Please try again.');
            }
        } catch (Exception $e) {
            $error = $e->getMessage();
        }
    } elseif ($_POST['action'] === 'upload_media' && $isMember) {
        try {
            if (empty($_FILES['media_files']['name'][0])) {
                throw new Exception('Please select at least one file to upload.');
            }

            $uploadDir = 'uploads/group_media/';
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0755, true);
            }

            $alertId = !empty($_POST['alert_id']) ? intval($_POST['alert_id']) : null;
            $uploadedCount = 0;

            foreach ($_FILES['media_files']['tmp_name'] as $key => $tmp_name) {
                if ($_FILES['media_files']['error'][$key] === UPLOAD_ERR_OK) {
                    $fileName = $_FILES['media_files']['name'][$key];
                    $fileType = $_FILES['media_files']['type'][$key];
                    $fileSize = $_FILES['media_files']['size'][$key];

                    // Determine file type category
                    $fileTypeCategory = 'other';
                    $maxSize = 20 * 1024 * 1024; // 20MB default

                    if (strpos($fileType, 'image/') === 0) {
                        $fileTypeCategory = 'image';
                        $maxSize = 10 * 1024 * 1024; // 10MB
                    } elseif (strpos($fileType, 'video/') === 0) {
                        $fileTypeCategory = 'video';
                        $maxSize = 100 * 1024 * 1024; // 100MB
                    } elseif (strpos($fileType, 'application/') === 0 || strpos($fileType, 'text/') === 0) {
                        $fileTypeCategory = 'document';
                        $maxSize = 20 * 1024 * 1024; // 20MB
                    }

                    if ($fileSize > $maxSize) {
                        continue;
                    }

                    $uniqueFileName = 'group_' . $groupId . '_' . uniqid() . '_' . time() . '_' . preg_replace('/[^a-zA-Z0-9._-]/', '_', $fileName);
                    $filePath = $uploadDir . $uniqueFileName;

                    if (move_uploaded_file($tmp_name, $filePath)) {
                        $thumbnailPath = null;
                        if ($fileTypeCategory === 'image') {
                            // Create thumbnail (simplified - you might want to use GD or Imagick)
                            $thumbnailPath = $filePath; // For now, use same path
                        }

                        $models->createGroupMedia([
                            'group_id' => $groupId,
                            'alert_id' => $alertId,
                            'uploaded_by' => $userId,
                            'file_name' => $fileName,
                            'file_path' => $filePath,
                            'file_type' => $fileTypeCategory,
                            'file_size_bytes' => $fileSize,
                            'mime_type' => $fileType,
                            'thumbnail_path' => $thumbnailPath,
                            'description' => trim($_POST['description'] ?? ''),
                            'is_public' => 1
                        ]);
                        $uploadedCount++;
                    }
                }
            }

            if ($uploadedCount > 0) {
                $message = "Successfully uploaded $uploadedCount file(s)!";
            } else {
                throw new Exception('Failed to upload files. Please check file sizes and types.');
            }
        } catch (Exception $e) {
            $error = $e->getMessage();
        }
    }
}

?>
<!DOCTYPE html>
<html lang="<?= $lang ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($group['group_name']) ?> - SafeSpace Portal</title>

    <link rel="icon" type="image/svg+xml" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><text y='.9em' font-size='90'>🛡️</text></svg>">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&family=Poppins:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/lucide/0.263.1/lucide.min.css">
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="design-system.css">
    <link rel="stylesheet" href="dashboard-styles.css">
</head>
<body class="bg-gradient-to-br from-slate-900 via-purple-900 to-slate-900 ">
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
                    <a href="community_groups.php" class="text-white font-medium">Community Groups</a>
                </nav>
                <div class="flex items-center space-x-4">
                    <a href="community_groups.php" class="btn btn-ghost">
                        <i data-lucide="arrow-left" class="w-4 h-4"></i>
                        Back
                    </a>
                </div>
            </div>
        </div>
    </header>

    <main class="pt-20 pb-8">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <section class="mb-6">
                <div class="card card-glass p-6">
                    <div class="flex items-start justify-between mb-4">
                        <div class="flex-1">
                            <div class="flex items-center space-x-3 mb-3">
                                <h1 class="heading-1 text-white"><?= htmlspecialchars($group['group_name']) ?></h1>
                                <?php if ($group['is_verified']): ?>
                                    <i data-lucide="badge-check" class="w-5 h-5 text-blue-400" title="Verified Group"></i>
                                <?php endif; ?>
                                <?php if ($group['status'] === 'pending_approval'): ?>
                                    <span class="px-3 py-1 rounded-full text-xs font-semibold bg-yellow-500/20 text-yellow-300 border border-yellow-500/30">
                                        Pending Approval
                                    </span>
                                <?php endif; ?>
                            </div>
                            <p class="text-white/80 mb-3 line-clamp-2"><?= htmlspecialchars($group['description'] ?? 'No description provided.') ?></p>
                            <div class="flex flex-wrap items-center gap-3 text-sm text-white/70">
                                <span class="flex items-center"><i data-lucide="map-pin" class="w-4 h-4 mr-1.5 text-blue-400"></i> <?= htmlspecialchars($group['area_name']) ?>, <?= htmlspecialchars($group['district']) ?></span>
                                <span class="flex items-center"><i data-lucide="users" class="w-4 h-4 mr-1.5 text-green-400"></i> <?= $group['member_count'] ?? 0 ?> members</span>
                                <span class="flex items-center"><i data-lucide="lock" class="w-4 h-4 mr-1.5 text-purple-400"></i> <?= ucfirst(str_replace('_', ' ', $group['privacy_level'])) ?></span>
                                <?php if ($group['ward_number']): ?>
                                    <span class="flex items-center"><i data-lucide="hash" class="w-4 h-4 mr-1.5 text-orange-400"></i> Ward <?= htmlspecialchars($group['ward_number']) ?></span>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="ml-4">
                            <?php if (!$isMember && $group['status'] === 'active'): ?>
                                <a href="group_handler.php?action=join&group_id=<?= $groupId ?>" class="btn btn-primary">
                                    <i data-lucide="user-plus" class="w-4 h-4 mr-2"></i>
                                    Join Group
                                </a>
                            <?php elseif ($isMember): ?>
                                <span class="px-4 py-2 rounded-lg bg-purple-500/20 text-purple-300 border border-purple-500/30 text-sm font-semibold inline-flex items-center">
                                    <i data-lucide="user-check" class="w-4 h-4 mr-2"></i>
                                    <?= ucfirst($memberInfo['role'] ?? 'Member') ?>
                                </span>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="mt-4">
                        <button onclick="toggleDetails()" class="flex items-center justify-between w-full p-3 bg-white/5 hover:bg-white/8 rounded-lg transition-colors">
                            <span class="text-sm font-semibold text-white flex items-center">
                                <i data-lucide="info" class="w-4 h-4 mr-2 text-blue-400"></i>
                                Group Details
                            </span>
                            <i data-lucide="chevron-down" id="detailsChevron" class="w-4 h-4 text-white/60 transition-transform"></i>
                        </button>
                        <div id="detailsContent" class="hidden mt-3 space-y-3">
                            <?php if ($group['rules']): ?>
                                <div class="p-4 bg-blue-500/10 border border-blue-500/30 rounded-lg">
                                    <h4 class="font-semibold text-blue-300 mb-2 flex items-center text-sm">
                                        <i data-lucide="file-text" class="w-4 h-4 mr-2"></i>
                                        Group Rules
                                    </h4>
                                    <p class="text-sm text-blue-200 whitespace-pre-line"><?= htmlspecialchars($group['rules']) ?></p>
                                </div>
                            <?php endif; ?>
                            <div class="grid grid-cols-2 md:grid-cols-3 gap-4 p-4 bg-white/5 rounded-lg">
                                <?php if ($group['creator_name']): ?>
                                    <div>
                                        <p class="text-xs text-white/50 mb-1">Created By</p>
                                        <p class="text-sm text-white font-medium"><?= htmlspecialchars($group['creator_name']) ?></p>
                                    </div>
                                <?php endif; ?>
                                <div>
                                    <p class="text-xs text-white/50 mb-1">Created</p>
                                    <p class="text-sm text-white font-medium"><?= date('M j, Y', strtotime($group['created_at'])) ?></p>
                                </div>
                                <?php if ($group['upazila']): ?>
                                    <div>
                                        <p class="text-xs text-white/50 mb-1">Upazila</p>
                                        <p class="text-sm text-white font-medium"><?= htmlspecialchars($group['upazila']) ?></p>
                                    </div>
                                <?php endif; ?>
                                <?php if ($group['union_name']): ?>
                                    <div>
                                        <p class="text-xs text-white/50 mb-1">Union</p>
                                        <p class="text-sm text-white font-medium"><?= htmlspecialchars($group['union_name']) ?></p>
                                    </div>
                                <?php endif; ?>
                                <?php if ($group['division']): ?>
                                    <div>
                                        <p class="text-xs text-white/50 mb-1">Division</p>
                                        <p class="text-sm text-white font-medium"><?= htmlspecialchars($group['division']) ?></p>
                                    </div>
                                <?php endif; ?>
                                <div>
                                    <p class="text-xs text-white/50 mb-1">Status</p>
                                    <p class="text-sm text-white font-medium capitalize"><?= str_replace('_', ' ', $group['status']) ?></p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </section>

            <?php if ($message): ?>
                <div class="mb-4 card card-glass border-l-4 border-green-500">
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

            <?php if ($group['status'] === 'pending_approval'): ?>
                <div class="mb-4 card card-glass border-l-4 border-yellow-500">
                    <div class="card-body">
                        <div class="flex items-center">
                            <i data-lucide="clock" class="w-5 h-5 text-yellow-500 mr-3"></i>
                            <div>
                                <p class="text-yellow-300 font-semibold mb-1">Group Pending Approval</p>
                                <p class="text-yellow-200 text-sm">This group is awaiting administrator approval. Once approved, it will be visible to all users and you'll be able to post alerts and manage members.</p>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

           <div class="grid grid-cols-1 lg:grid-cols-3 gap-4 lg:items-start">
                <div class="lg:col-span-2 space-y-4">
                    <?php if ($isMember && $group['status'] === 'active'): ?>
                        <div class="card card-glass p-5">
                            <h2 class="heading-3 text-white mb-4 flex items-center">
                                <i data-lucide="alert-circle" class="w-5 h-5 mr-2 text-orange-400"></i>
                                Post New Alert
                            </h2>
                            <form method="POST" enctype="multipart/form-data" class="space-y-4" id="alertForm">
                                <input type="hidden" name="action" value="create_alert">

                                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                    <div>
                                        <label class="form-label text-white mb-2">Alert Type</label>
                                        <select name="alert_type" class="form-input w-full" required>
                                            <option value="general">General</option>
                                            <option value="safety_warning">Safety Warning</option>
                                            <option value="missing_person">Missing Person</option>
                                            <option value="suspicious_activity">Suspicious Activity</option>
                                            <option value="emergency">Emergency</option>
                                        </select>
                                    </div>
                                    <div>
                                        <label class="form-label text-white mb-2">Severity</label>
                                        <select name="severity" class="form-input w-full" required>
                                            <option value="low">Low</option>
                                            <option value="medium" selected>Medium</option>
                                            <option value="high">High</option>
                                            <option value="critical">Critical</option>
                                        </select>
                                    </div>
                                </div>

                                <div>
                                    <label class="form-label text-white mb-2">Title</label>
                                    <input type="text" name="title" class="form-input w-full" required maxlength="255">
                                </div>

                                <div>
                                    <label class="form-label text-white mb-2">Message</label>
                                    <textarea name="message" rows="4" class="form-input w-full" required></textarea>
                                </div>

                                <div>
                                    <label class="form-label text-white mb-2">Location Details</label>
                                    <input type="text" name="location_details" class="form-input w-full" maxlength="255">
                                </div>

                                <div>
                                    <label class="form-label text-white mb-2 flex items-center">
                                        <i data-lucide="paperclip" class="w-4 h-4 mr-2 text-purple-400"></i>
                                        Attach Media (Pictures, Videos, Documents)
                                    </label>
                                    <div class="border-2 border-dashed border-white/20 rounded-lg p-6 bg-white/5 hover:bg-white/8 transition-colors">
                                        <input type="file" name="media_files[]" id="mediaFiles" class="hidden" multiple
                                               accept="image/*,video/*,.pdf,.doc,.docx,.txt">
                                        <div class="text-center">
                                            <i data-lucide="upload" class="w-12 h-12 text-white/40 mx-auto mb-3"></i>
                                            <p class="text-white/70 mb-2">Click to upload or drag and drop</p>
                                            <p class="text-xs text-white/50 mb-4">Images (10MB), Videos (100MB), Documents (20MB)</p>
                                            <button type="button" onclick="document.getElementById('mediaFiles').click()" class="btn btn-outline btn-sm">
                                                <i data-lucide="folder-open" class="w-4 h-4 mr-2"></i>
                                                Choose Files
                                            </button>
                                        </div>
                                        <div id="filePreviewList" class="mt-4 space-y-2"></div>
                                    </div>
                                </div>

                                <div>
                                    <label class="form-label text-white mb-2">Expires At (Optional)</label>
                                    <input type="datetime-local" name="expires_at" class="form-input w-full" min="<?= date('Y-m-d\TH:i') ?>">
                                </div>

                                <button type="submit" class="btn btn-primary">
                                    <i data-lucide="send" class="w-4 h-4 mr-2"></i>
                                    Post Alert
                                </button>
                            </form>
                        </div>
                    <?php endif; ?>

                    <a href="group_alerts.php?id=<?= $groupId ?>" class="card card-glass p-5 card-hover group flex items-center justify-between transition-all duration-200">
    <div class="flex items-center space-x-4">
        <div class="w-12 h-12 bg-gradient-to-r from-blue-500 to-purple-600 rounded-lg flex items-center justify-center flex-shrink-0">
            <i data-lucide="bell" class="w-6 h-6 text-white"></i>
        </div>
        <div>
            <h3 class="heading-3 text-white">Group Alerts</h3>
            <p class="text-white/70 text-sm">View all active and resolved alerts for this group.</p>
        </div>
    </div>
    <div class="btn btn-sm btn-outline text-white/70 border-white/20 group-hover:bg-white/10 group-hover:text-white group-hover:border-white/30 transition-all">
        View
        <i data-lucide="arrow-right" class="w-4 h-4 ml-2"></i>
    </div>
</a>

                </div>

                <div class="space-y-4">
                    <div class="card card-glass p-5">
                        <h3 class="heading-3 text-white mb-4">Group Statistics</h3>
                        <div class="space-y-3">
                            <div class="flex items-center justify-between p-2 rounded-lg bg-white/5">
                                <span class="text-white/70 text-sm flex items-center">
                                    <i data-lucide="users" class="w-4 h-4 mr-2 text-green-400"></i>
                                    Members
                                </span>
                                <span class="text-white font-semibold"><?= $group['member_count'] ?? 0 ?></span>
                            </div>
                            <div class="flex items-center justify-between p-2 rounded-lg bg-white/5">
                                <span class="text-white/70 text-sm flex items-center">
                                    <i data-lucide="bell" class="w-4 h-4 mr-2 text-orange-400"></i>
                                    Active Alerts
                                </span>
                                <span class="text-white font-semibold"><?= count($alerts) ?></span>
                            </div>
                            <div class="flex items-center justify-between p-2 rounded-lg bg-white/5">
                                <span class="text-white/70 text-sm flex items-center">
                                    <i data-lucide="calendar" class="w-4 h-4 mr-2 text-blue-400"></i>
                                    Created
                                </span>
                                <span class="text-white font-semibold"><?= date('M Y', strtotime($group['created_at'])) ?></span>
                            </div>
                        </div>
                    </div>

                    <div class="card card-glass p-5">
                        <h3 class="heading-3 text-white mb-4">Members (<?= count($members) ?>)</h3>
                        <div class="space-y-2 max-h-80 overflow-y-auto">
                            <?php if (empty($members)): ?>
                                <p class="text-sm text-white/50 text-center py-4">No members yet</p>
                            <?php else: ?>
                                <?php foreach (array_slice($members, 0, 8) as $member): ?>
                                    <div class="flex items-center justify-between p-2 rounded-lg hover:bg-white/5 transition-colors">
                                        <div class="flex items-center space-x-2">
                                            <div class="w-8 h-8 bg-gradient-to-r from-purple-500 to-pink-500 rounded-full flex items-center justify-center flex-shrink-0">
                                                <span class="text-xs font-semibold text-white"><?= strtoupper(substr($member['display_name'] ?? $member['email'] ?? 'U', 0, 1)) ?></span>
                                            </div>
                                            <div class="min-w-0 flex-1">
                                                <p class="text-sm font-medium text-white truncate"><?= htmlspecialchars($member['display_name'] ?? 'Member') ?></p>
                                                <p class="text-xs text-white/50"><?= ucfirst($member['role']) ?></p>
                                            </div>
                                        </div>
                                        <div class="text-xs text-white/50 flex-shrink-0 ml-2">
                                            <?= $member['contribution_score'] ?? 0 ?> pts
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                                <?php if (count($members) > 8): ?>
                                    <p class="text-sm text-white/50 text-center py-2">+<?= count($members) - 8 ?> more members</p>
                                <?php endif; ?>
                            <?php endif; ?>
                        </div>
                    </div>

                    <?php if ($isMember && $group['status'] === 'active'): ?>
                        <div class="card card-glass p-5">
                            <h2 class="heading-3 text-white mb-4 flex items-center">
                                <i data-lucide="image" class="w-5 h-5 mr-2 text-blue-400"></i>
                                Upload Media
                            </h2>
                            <form method="POST" enctype="multipart/form-data" class="space-y-4" id="mediaUploadForm">
                                <input type="hidden" name="action" value="upload_media">

                                <div>
                                    <label class="form-label text-white mb-2">Select Files</label>
                                    <div class="border-2 border-dashed border-white/20 rounded-lg p-6 bg-white/5 hover:bg-white/8 transition-colors">
                                        <input type="file" name="media_files[]" id="standaloneMediaFiles" class="hidden" multiple
                                               accept="image/*,video/*,.pdf,.doc,.docx,.txt">
                                        <div class="text-center">
                                            <i data-lucide="upload" class="w-12 h-12 text-white/40 mx-auto mb-3"></i>
                                            <p class="text-white/70 mb-2">Upload pictures, videos, or documents</p>
                                            <p class="text-xs text-white/50 mb-4">Images (10MB), Videos (100MB), Documents (20MB)</p>
                                            <button type="button" onclick="document.getElementById('standaloneMediaFiles').click()" class="btn btn-outline btn-sm">
                                                <i data-lucide="folder-open" class="w-4 h-4 mr-2"></i>
                                                Choose Files
                                            </button>
                                        </div>
                                        <div id="standaloneFilePreviewList" class="mt-4 space-y-2"></div>
                                    </div>
                                </div>

                                <div>
                                    <label class="form-label text-white mb-2">Description (Optional)</label>
                                    <textarea name="description" rows="2" class="form-input w-full" placeholder="Add a description for the uploaded files..."></textarea>
                                </div>

                                <button type="submit" class="btn btn-primary">
                                    <i data-lucide="upload" class="w-4 h-4 mr-2"></i>
                                    Upload Media
                                </button>
                            </form>
                        </div>
                    <?php endif; ?>

                   <a href="group_media_gallery.php?group_id=<?= $groupId ?>" class="card card-glass p-5 card-hover group flex items-center justify-between transition-all duration-200">
    <div class="flex items-center space-x-4">
        <div class="w-12 h-12 bg-gradient-to-r from-purple-500 to-pink-600 rounded-lg flex items-center justify-center flex-shrink-0">
            <i data-lucide="image" class="w-6 h-6 text-white"></i>
        </div>
        <div>
            <h3 class="heading-3 text-white">Media Gallery</h3>
            <p class="text-white/70 text-sm">Browse all photos and videos shared by the group.</p>
        </div>
    </div>
    <div class="btn btn-sm btn-outline text-white/70 border-white/20 group-hover:bg-white/10 group-hover:text-white group-hover:border-white/30 transition-all">
        View
        <i data-lucide="arrow-right" class="w-4 h-4 ml-2"></i>
    </div>
</a>
                </div>
            </div>
        </div>
    </main>

    <script src="https://unpkg.com/lucide@latest/dist/umd/lucide.js"></script>
    <script src="dashboard-enhanced.js"></script>
    <script>
        lucide.createIcons();

        function filterAlerts() {
            // This function is now on group_alerts.php
        }

        function filterMedia() {
            // This function is now on group_media_gallery.php
        }

        // File preview for alert form
        document.getElementById('mediaFiles')?.addEventListener('change', function(e) {
            const previewList = document.getElementById('filePreviewList');
            previewList.innerHTML = '';

            Array.from(this.files).forEach((file, index) => {
                const fileItem = document.createElement('div');
                fileItem.className = 'flex items-center justify-between p-3 bg-white/5 rounded-lg border border-white/10';

                const fileIcon = file.type.startsWith('image/') ? 'image' :
                                file.type.startsWith('video/') ? 'video' : 'file-text';
                const fileSize = (file.size / (1024 * 1024)).toFixed(2) + ' MB';

                fileItem.innerHTML = `
                    <div class="flex items-center space-x-3">
                        <i data-lucide="${fileIcon}" class="w-5 h-5 text-purple-400"></i>
                        <div>
                            <p class="text-sm text-white font-medium" data-file-name="${file.name}">${file.name}</p>
                            <p class="text-xs text-white/50">${fileSize}</p>
                        </div>
                    </div>
                    <button type="button" onclick="removeFilePreview(this, 'mediaFiles')" class="text-red-400 hover:text-red-300">
                        <i data-lucide="x" class="w-4 h-4"></i>
                    </button>
                `;
                previewList.appendChild(fileItem);
            });
            lucide.createIcons();
        });

        // File preview for standalone media upload
        document.getElementById('standaloneMediaFiles')?.addEventListener('change', function(e) {
            const previewList = document.getElementById('standaloneFilePreviewList');
            previewList.innerHTML = '';

            Array.from(this.files).forEach((file, index) => {
                const fileItem = document.createElement('div');
                fileItem.className = 'flex items-center justify-between p-3 bg-white/5 rounded-lg border border-white/10';

                const fileIcon = file.type.startsWith('image/') ? 'image' :
                                file.type.startsWith('video/') ? 'video' : 'file-text';
                const fileSize = (file.size / (1024 * 1024)).toFixed(2) + ' MB';

                fileItem.innerHTML = `
                    <div class="flex items-center space-x-3">
                        <i data-lucide="${fileIcon}" class="w-5 h-5 text-blue-400"></i>
                        <div>
                            <p class="text-sm text-white font-medium" data-file-name="${file.name}">${file.name}</p>
                            <p class="text-xs text-white/50">${fileSize}</p>
                        </div>
                    </div>
                    <button type="button" onclick="removeFilePreview(this, 'standaloneMediaFiles')" class="text-red-400 hover:text-red-300">
                        <i data-lucide="x" class="w-4 h-4"></i>
                    </button>
                `;
                previewList.appendChild(fileItem);
            });
            lucide.createIcons();
        });

        function removeFilePreview(button, inputId) {
            const fileItem = button.closest('.flex');
            const fileName = fileItem.querySelector('p.text-sm').getAttribute('data-file-name');
            const input = document.getElementById(inputId);

            const dt = new DataTransfer();
            const files = input.files;

            for (let i = 0; i < files.length; i++) {
                if (files[i].name !== fileName) {
                    dt.items.add(files[i]);
                }
            }

            input.files = dt.files; // Assign the updated file list
            fileItem.remove(); // Remove the preview item
        }

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

        // Drag and drop
        ['mediaFiles', 'standaloneMediaFiles'].forEach(id => {
            const input = document.getElementById(id);
            if (input) {
                const container = input.closest('.border-dashed');
                if (container) {
                    container.addEventListener('dragover', (e) => {
                        e.preventDefault();
                        container.classList.add('border-purple-400/50', 'bg-white/10');
                    });
                    container.addEventListener('dragleave', () => {
                        container.classList.remove('border-purple-400/50', 'bg-white/10');
                    });
                    container.addEventListener('drop', (e) => {
                        e.preventDefault();
                        container.classList.remove('border-purple-400/50', 'bg-white/10');
                        input.files = e.dataTransfer.files;
                        input.dispatchEvent(new Event('change'));
                    });
                }
            }
        });
    </script>
</body>
</html>