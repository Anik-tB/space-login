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

$groupId = $_GET['group_id'] ?? null;

if (!$groupId) {
    header('Location: community_groups.php');
    exit;
}

// Get group details
$group = $models->getNeighborhoodGroupById($groupId);

if (!$group || $group['status'] !== 'active') {
    header('Location: community_groups.php?error=Group not found');
    exit;
}

// Check if user is member
$isMember = $models->isGroupMember($groupId, $userId);

// Get filters
$filters = [
    'file_type' => $_GET['type'] ?? '',
    'limit' => 100
];

// Get all group media
$groupMedia = $models->getGroupMedia($groupId, $filters);

// Handle delete
$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $_POST['action'] === 'delete_media' && $isMember) {
    $mediaId = intval($_POST['media_id'] ?? 0);
    if ($mediaId) {
        $result = $models->deleteGroupMedia($mediaId, $userId);
        if ($result) {
            $message = 'Media deleted successfully!';
            $groupMedia = $models->getGroupMedia($groupId, $filters);
        } else {
            $error = 'You do not have permission to delete this media.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="<?= $lang ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Media Gallery - <?= htmlspecialchars($group['group_name']) ?> - SafeSpace Portal</title>

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
                    <a href="community_groups.php" class="text-white/70 hover:text-white transition-colors duration-200">Community Groups</a>
                </nav>
                <div class="flex items-center space-x-4">
                    <a href="group_detail.php?id=<?= $groupId ?>" class="btn btn-ghost">
                        <i data-lucide="arrow-left" class="w-4 h-4"></i>
                        Back to Group
                    </a>
                </div>
            </div>
        </div>
    </header>

    <main class="pt-20 pb-8">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <section class="mb-8 animate-fade-in">
                <div class="text-center">
                    <div class="w-20 h-20 bg-gradient-to-r from-purple-500 via-pink-500 to-red-500 rounded-3xl flex items-center justify-center mx-auto mb-6 shadow-2xl ring-4 ring-purple-500/20">
                        <i data-lucide="image" class="w-10 h-10 text-white drop-shadow-lg"></i>
                    </div>
                    <h1 class="heading-1 mb-4 text-white">Media Gallery</h1>
                    <p class="body-large max-w-2xl mx-auto text-white/80 leading-relaxed">
                        <?= htmlspecialchars($group['group_name']) ?> - All shared pictures, videos, and documents
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

            <!-- Filters -->
            <section class="mb-6">
                <div class="card card-glass p-4">
                    <form method="GET" action="group_media_gallery.php" class="flex items-center space-x-4">
                        <input type="hidden" name="group_id" value="<?= $groupId ?>">
                        <label class="form-label text-white">Filter by Type:</label>
                        <select name="type" class="form-input" onchange="this.form.submit()">
                            <option value="">All Types</option>
                            <option value="image" <?= $filters['file_type'] === 'image' ? 'selected' : '' ?>>Images</option>
                            <option value="video" <?= $filters['file_type'] === 'video' ? 'selected' : '' ?>>Videos</option>
                            <option value="document" <?= $filters['file_type'] === 'document' ? 'selected' : '' ?>>Documents</option>
                        </select>
                    </form>
                </div>
            </section>

            <!-- Media Grid -->
            <section>
                <div class="mb-6 flex items-center justify-between">
                    <h2 class="heading-2 text-white">All Media (<?= count($groupMedia) ?>)</h2>
                </div>

                <?php if (empty($groupMedia)): ?>
                    <div class="card card-glass text-center p-12">
                        <div class="w-20 h-20 bg-gradient-to-r from-gray-500 to-gray-600 rounded-full flex items-center justify-center mx-auto mb-6">
                            <i data-lucide="image-off" class="w-10 h-10 text-white"></i>
                        </div>
                        <h3 class="heading-3 text-white mb-3">No Media Found</h3>
                        <p class="text-white/60 mb-6">No media has been uploaded to this group yet.</p>
                        <a href="group_detail.php?id=<?= $groupId ?>" class="btn btn-primary">
                            <i data-lucide="arrow-left" class="w-4 h-4 mr-2"></i>
                            Back to Group
                        </a>
                    </div>
                <?php else: ?>
                    <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-4 xl:grid-cols-5 gap-4">
                        <?php foreach ($groupMedia as $index => $media):
                            $memberInfo = $isMember ? $models->isGroupMember($groupId, $userId) : null;
                            $canDelete = $isMember && ($media['uploaded_by'] == $userId || in_array($memberInfo['role'] ?? '', ['admin', 'moderator', 'founder']));
                        ?>
                            <div class="card card-glass p-3 group relative animate-slide-in" style="animation-delay: <?= $index * 0.05 ?>s;">
                                <?php if ($media['file_type'] === 'image'): ?>
                                    <a href="<?= htmlspecialchars($media['file_path']) ?>" target="_blank" class="block" onclick="incrementMediaView(<?= $media['id'] ?>)">
                                        <img src="<?= htmlspecialchars($media['thumbnail_path'] ?? $media['file_path']) ?>"
                                             alt="<?= htmlspecialchars($media['file_name']) ?>"
                                             class="w-full h-32 object-cover rounded-lg border border-white/10 hover:border-purple-400/50 transition-colors">
                                    </a>
                                <?php elseif ($media['file_type'] === 'video'): ?>
                                    <a href="<?= htmlspecialchars($media['file_path']) ?>" target="_blank" class="block relative" onclick="incrementMediaView(<?= $media['id'] ?>)">
                                        <div class="w-full h-32 bg-gradient-to-br from-purple-500/20 to-pink-500/20 rounded-lg border border-white/10 flex items-center justify-center hover:border-purple-400/50 transition-colors">
                                            <i data-lucide="play" class="w-10 h-10 text-white/70"></i>
                                        </div>
                                    </a>
                                <?php else: ?>
                                    <a href="group_media_handler.php?action=download&id=<?= $media['id'] ?>" class="block">
                                        <div class="w-full h-32 bg-gradient-to-br from-blue-500/20 to-cyan-500/20 rounded-lg border border-white/10 flex items-center justify-center hover:border-blue-400/50 transition-colors">
                                            <i data-lucide="file-text" class="w-10 h-10 text-white/70"></i>
                                        </div>
                                    </a>
                                <?php endif; ?>

                                <div class="mt-2">
                                    <p class="text-xs text-white/80 font-medium truncate" title="<?= htmlspecialchars($media['file_name']) ?>">
                                        <?= htmlspecialchars($media['file_name']) ?>
                                    </p>
                                    <div class="flex items-center justify-between mt-1 text-xs text-white/50">
                                        <span><?= $media['views_count'] ?> views</span>
                                        <span><?= date('M j', strtotime($media['created_at'])) ?></span>
                                    </div>
                                </div>

                                <?php if ($canDelete): ?>
                                    <form method="POST" class="absolute top-2 right-2 opacity-0 group-hover:opacity-100 transition-opacity">
                                        <input type="hidden" name="action" value="delete_media">
                                        <input type="hidden" name="media_id" value="<?= $media['id'] ?>">
                                        <button type="submit" onclick="return confirm('Delete this media?')" class="w-7 h-7 bg-red-500/80 hover:bg-red-500 rounded-full flex items-center justify-center">
                                            <i data-lucide="x" class="w-4 h-4 text-white"></i>
                                        </button>
                                    </form>
                                <?php endif; ?>
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

        function incrementMediaView(mediaId) {
            fetch('group_media_handler.php?action=view&id=' + mediaId, { method: 'POST' })
                .catch(err => console.error('Error tracking view:', err));
        }
    </script>
</body>
</html>

