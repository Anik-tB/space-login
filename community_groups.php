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

// Get filters
$statusFilter = $_GET['status'] ?? '';
$filters = [
    'district' => $_GET['district'] ?? '',
    'upazila' => $_GET['upazila'] ?? '',
    'area_name' => $_GET['area_name'] ?? '',
    'is_verified' => $_GET['is_verified'] ?? '',
    'privacy_level' => $_GET['privacy_level'] ?? '',
    'status' => $statusFilter,
    'user_id' => $userId, // Include user_id to show their pending groups
    'limit' => $_GET['limit'] ?? 50
];

// Search functionality
$searchTerm = $_GET['search'] ?? '';
$groups = [];
$pendingGroups = [];

if (!empty($searchTerm)) {
    $groups = $models->searchNeighborhoodGroups($searchTerm);
} else {
    // Get all groups (active + user's pending)
    $allGroups = $models->getNeighborhoodGroups($filters);

    // Separate active and pending groups
    foreach ($allGroups as $group) {
        if ($group['status'] === 'pending_approval' && $group['created_by'] == $userId) {
            $pendingGroups[] = $group;
        } else {
            $groups[] = $group;
        }
    }
}

// Get user's groups (active membership)
$userGroups = $models->getUserGroups($userId);

// Get statistics
$allGroupsForStats = $models->getNeighborhoodGroups(['status' => 'all', 'user_id' => $userId]);
$totalGroups = count($allGroupsForStats);
$activeGroups = count(array_filter($allGroupsForStats, fn($g) => $g['status'] === 'active'));
$pendingCount = count(array_filter($allGroupsForStats, fn($g) => $g['status'] === 'pending_approval' && $g['created_by'] == $userId));
$myGroupsCount = count($userGroups);

// Get unique districts and upazilas from all active groups
$allActiveGroups = $models->getNeighborhoodGroups(['status' => 'active']);
$districts = array_unique(array_filter(array_column($allActiveGroups, 'district')));
$upazilas = array_unique(array_filter(array_column($allActiveGroups, 'upazila')));
sort($districts);
sort($upazilas);

// Message handling
$message = '';
$error = '';
if (isset($_GET['success'])) {
    $message = 'Group created successfully! It is pending approval and will be active soon.';
}
if (isset($_GET['joined'])) {
    $message = 'Successfully joined the group!';
}
if (isset($_GET['left'])) {
    $message = 'You have left the group.';
}
?>
<!DOCTYPE html>
<html lang="<?= $lang ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Community Groups - SafeSpace Portal</title>

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

        .group-card {
            background: linear-gradient(135deg, rgba(255, 255, 255, 0.08), rgba(255, 255, 255, 0.03));
            backdrop-filter: blur(15px);
            border: 1px solid rgba(255, 255, 255, 0.1);
            border-radius: 20px;
            padding: 24px;
            transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
            position: relative;
            overflow: hidden;
        }

        .group-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.1), transparent);
            transition: left 0.6s ease;
        }

        .group-card:hover::before {
            left: 100%;
        }

        .group-card:hover {
            background: linear-gradient(135deg, rgba(255, 255, 255, 0.12), rgba(255, 255, 255, 0.06));
            border-color: rgba(255, 255, 255, 0.2);
            transform: translateY(-4px);
            box-shadow: 0 16px 48px rgba(0, 0, 0, 0.2);
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
                    <a href="community_groups.php" class="text-white font-medium">Community Groups</a>
                    <a href="missing_person_alerts.php" class="text-white/70 hover:text-white transition-colors duration-200">Missing Persons</a>
                    <a href="legal_aid.php" class="text-white/70 hover:text-white transition-colors duration-200">Legal Aid</a>
                    <?php
                    // Add admin link if user is admin
                    if ($userId) {
                        try {
                            $adminCheck = $database->fetchOne("SELECT email FROM users WHERE id = ?", [$userId]);
                            if ($adminCheck && (strpos(strtolower($adminCheck['email'] ?? ''), 'admin') !== false || strtolower($adminCheck['email'] ?? '') === 'admin@safespace.com')) {
                                echo '<a href="admin_dashboard.php" class="text-white/70 hover:text-white transition-colors duration-200 flex items-center gap-1">
                                    <i data-lucide="shield-check" class="w-4 h-4"></i>
                                    <span>Admin</span>
                                </a>';
                            }
                        } catch (Exception $e) {}
                    }
                    ?>
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
            <!-- Header Section -->
            <section class="mb-8 animate-fade-in">
                <div class="text-center">
                    <div class="w-20 h-20 bg-gradient-to-r from-green-500 via-emerald-500 to-teal-500 rounded-3xl flex items-center justify-center mx-auto mb-6 shadow-2xl ring-4 ring-green-500/20">
                        <i data-lucide="users" class="w-10 h-10 text-white drop-shadow-lg"></i>
                    </div>
                    <h1 class="heading-1 mb-4 text-white">Community Watch & Safety Groups</h1>
                    <p class="body-large max-w-2xl mx-auto text-white/80 leading-relaxed">
                        Join or create neighborhood safety groups. Work together with your community to stay safe and informed.
                    </p>
                </div>
            </section>

            <!-- Messages -->
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
            <section class="mb-8">
                <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
                    <div class="card card-glass p-6">
                        <div class="flex items-center justify-between">
                            <div>
                                <p class="text-white/60 text-sm mb-1">Total Groups</p>
                                <p class="heading-2 text-white"><?= $totalGroups ?></p>
                            </div>
                            <div class="w-12 h-12 bg-gradient-to-r from-blue-500 to-cyan-500 rounded-lg flex items-center justify-center">
                                <i data-lucide="users" class="w-6 h-6 text-white"></i>
                            </div>
                        </div>
                    </div>
                    <div class="card card-glass p-6">
                        <div class="flex items-center justify-between">
                            <div>
                                <p class="text-white/60 text-sm mb-1">Active Groups</p>
                                <p class="heading-2 text-white"><?= $activeGroups ?></p>
                            </div>
                            <div class="w-12 h-12 bg-gradient-to-r from-green-500 to-emerald-500 rounded-lg flex items-center justify-center">
                                <i data-lucide="check-circle" class="w-6 h-6 text-white"></i>
                            </div>
                        </div>
                    </div>
                    <div class="card card-glass p-6">
                        <div class="flex items-center justify-between">
                            <div>
                                <p class="text-white/60 text-sm mb-1">My Groups</p>
                                <p class="heading-2 text-white"><?= $myGroupsCount ?></p>
                            </div>
                            <div class="w-12 h-12 bg-gradient-to-r from-purple-500 to-pink-500 rounded-lg flex items-center justify-center">
                                <i data-lucide="user-check" class="w-6 h-6 text-white"></i>
                            </div>
                        </div>
                    </div>
                    <div class="card card-glass p-6">
                        <div class="flex items-center justify-between">
                            <div>
                                <p class="text-white/60 text-sm mb-1">Pending Approval</p>
                                <p class="heading-2 text-white"><?= $pendingCount ?></p>
                            </div>
                            <div class="w-12 h-12 bg-gradient-to-r from-yellow-500 to-orange-500 rounded-lg flex items-center justify-center">
                                <i data-lucide="clock" class="w-6 h-6 text-white"></i>
                            </div>
                        </div>
                    </div>
                </div>
            </section>

            <!-- Quick Links -->
            <section class="mb-8 grid grid-cols-1 md:grid-cols-3 gap-4">
                <a href="create_group.php" class="card card-glass card-hover text-center p-6">
                    <div class="w-12 h-12 bg-gradient-to-r from-blue-500 to-cyan-600 rounded-xl flex items-center justify-center mx-auto mb-4">
                        <i data-lucide="plus-circle" class="w-6 h-6 text-white"></i>
                    </div>
                    <h3 class="heading-4 text-white mb-2">Create Group</h3>
                    <p class="text-sm text-white/60">Start a new community safety group</p>
                </a>
                <a href="community_groups.php?my_groups=1" class="card card-glass card-hover text-center p-6">
                    <div class="w-12 h-12 bg-gradient-to-r from-purple-500 to-pink-600 rounded-xl flex items-center justify-center mx-auto mb-4">
                        <i data-lucide="user-check" class="w-6 h-6 text-white"></i>
                    </div>
                    <h3 class="heading-4 text-white mb-2">My Groups</h3>
                    <p class="text-sm text-white/60">View groups you're a member of (<?= $myGroupsCount ?>)</p>
                </a>
                <a href="missing_person_alerts.php" class="card card-glass card-hover text-center p-6">
                    <div class="w-12 h-12 bg-gradient-to-r from-orange-500 to-red-600 rounded-xl flex items-center justify-center mx-auto mb-4">
                        <i data-lucide="user-search" class="w-6 h-6 text-white"></i>
                    </div>
                    <h3 class="heading-4 text-white mb-2">Missing Persons</h3>
                    <p class="text-sm text-white/60">View and share missing person alerts</p>
                </a>
            </section>

            <!-- Pending Groups Section -->
            <?php if (!empty($pendingGroups)): ?>
                <section class="mb-8">
                    <div class="flex items-center justify-between mb-6">
                        <div>
                            <h2 class="heading-2 text-white">My Pending Groups (<?= count($pendingGroups) ?>)</h2>
                            <p class="text-white/60 text-sm mt-1">Groups you created that are awaiting approval</p>
                        </div>
                    </div>
                    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                        <?php foreach ($pendingGroups as $group): ?>
                            <div class="group-card border-l-4 border-yellow-500/50 bg-yellow-500/10">
                                <div class="flex items-start justify-between mb-4">
                                    <div class="flex-1">
                                        <div class="flex items-center space-x-2 mb-2">
                                            <h3 class="heading-4 text-white"><?= htmlspecialchars($group['group_name']) ?></h3>
                                        </div>
                                        <p class="text-sm text-white/60 mb-2"><?= htmlspecialchars($group['area_name']) ?>, <?= htmlspecialchars($group['district']) ?></p>
                                        <?php if ($group['description']): ?>
                                            <p class="text-sm text-white/70 mb-3 line-clamp-2"><?= htmlspecialchars(substr($group['description'], 0, 100)) ?><?= strlen($group['description']) > 100 ? '...' : '' ?></p>
                                        <?php endif; ?>
                                    </div>
                                    <span class="px-2 py-1 rounded-full text-xs font-semibold bg-yellow-500/20 text-yellow-300 border border-yellow-500/30">
                                        Pending
                                    </span>
                                </div>
                                <div class="space-y-2 mb-4">
                                    <div class="flex items-center text-sm text-white/70">
                                        <i data-lucide="map-pin" class="w-4 h-4 mr-2 text-blue-400"></i>
                                        <?= htmlspecialchars($group['district']) ?><?= $group['upazila'] ? ', ' . htmlspecialchars($group['upazila']) : '' ?>
                                    </div>
                                    <div class="flex items-center text-sm text-white/70">
                                        <i data-lucide="users" class="w-4 h-4 mr-2 text-green-400"></i>
                                        <?= $group['member_count'] ?? 1 ?> member<?= ($group['member_count'] ?? 1) != 1 ? 's' : '' ?>
                                    </div>
                                    <div class="flex items-center text-sm text-yellow-300">
                                        <i data-lucide="clock" class="w-4 h-4 mr-2"></i>
                                        Created: <?= date('M j, Y', strtotime($group['created_at'])) ?>
                                    </div>
                                </div>
                                <div class="bg-yellow-500/10 border border-yellow-500/30 rounded-lg p-3 mb-4">
                                    <p class="text-sm text-yellow-200">
                                        <i data-lucide="info" class="w-4 h-4 inline mr-1"></i>
                                        Your group is pending approval. It will be visible to others once approved by administrators.
                                    </p>
                                </div>
                                <a href="group_detail.php?id=<?= $group['id'] ?>" class="btn btn-outline w-full">
                                    <i data-lucide="eye" class="w-4 h-4 mr-2"></i>
                                    View Details
                                </a>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </section>
            <?php endif; ?>

            <!-- My Groups Section -->
            <?php if (!empty($userGroups) && (!isset($_GET['my_groups']) || $_GET['my_groups'] == '1')): ?>
                <section class="mb-8">
                    <div class="flex items-center justify-between mb-6">
                        <h2 class="heading-2 text-white">My Groups (<?= count($userGroups) ?>)</h2>
                        <a href="community_groups.php" class="btn btn-outline btn-sm">View All Groups</a>
                    </div>
                    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                        <?php foreach ($userGroups as $group): ?>
                            <div class="group-card animate-slide-in">
                                <div class="flex items-start justify-between mb-4">
                                    <div class="flex-1">
                                        <div class="flex items-center space-x-2 mb-2">
                                            <h3 class="heading-4 text-white"><?= htmlspecialchars($group['group_name']) ?></h3>
                                            <?php if ($group['is_verified']): ?>
                                                <i data-lucide="badge-check" class="w-5 h-5 text-blue-400" title="Verified"></i>
                                            <?php endif; ?>
                                        </div>
                                        <p class="text-sm text-white/60 mb-2"><?= htmlspecialchars($group['area_name']) ?>, <?= htmlspecialchars($group['district']) ?></p>
                                    </div>
                                    <span class="px-2 py-1 rounded-full text-xs font-semibold bg-purple-500/20 text-purple-300 border border-purple-500/30">
                                        <?= ucfirst($group['role']) ?>
                                    </span>
                                </div>
                                <div class="flex items-center justify-between mb-4">
                                    <div class="flex items-center text-sm text-white/70">
                                        <i data-lucide="users" class="w-4 h-4 mr-2 text-green-400"></i>
                                        <?= $group['member_count'] ?> members
                                    </div>
                                    <div class="flex items-center text-sm text-white/70">
                                        <i data-lucide="star" class="w-4 h-4 mr-1 text-yellow-400"></i>
                                        <?= $group['contribution_score'] ?> points
                                    </div>
                                </div>
                                <a href="group_detail.php?id=<?= $group['id'] ?>" class="btn btn-primary w-full">
                                    <i data-lucide="arrow-right" class="w-4 h-4 mr-2"></i>
                                    View Group
                                </a>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </section>
            <?php endif; ?>

            <!-- Search and Filters -->
            <section class="mb-8">
                <div class="card card-glass p-6">
                    <form method="GET" action="community_groups.php" class="space-y-4">
                        <?php if (isset($_GET['my_groups'])): ?>
                            <input type="hidden" name="my_groups" value="1">
                        <?php endif; ?>
                        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
                            <!-- Search -->
                            <div class="lg:col-span-4">
                                <label class="form-label text-white mb-2">Search</label>
                                <input type="text" name="search" value="<?= htmlspecialchars($searchTerm) ?>"
                                       placeholder="Search by group name, area, or location..."
                                       class="form-input w-full">
                            </div>

                            <!-- District -->
                            <div>
                                <label class="form-label text-white mb-2">District</label>
                                <select name="district" class="form-input w-full">
                                    <option value="">All Districts</option>
                                    <?php foreach ($districts as $district): ?>
                                        <option value="<?= htmlspecialchars($district) ?>" <?= $filters['district'] === $district ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($district) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <!-- Upazila -->
                            <div>
                                <label class="form-label text-white mb-2">Upazila</label>
                                <select name="upazila" class="form-input w-full">
                                    <option value="">All Upazilas</option>
                                    <?php foreach ($upazilas as $upazila): ?>
                                        <option value="<?= htmlspecialchars($upazila) ?>" <?= $filters['upazila'] === $upazila ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($upazila) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <!-- Verified Only -->
                            <div>
                                <label class="form-label text-white mb-2">Verified Only</label>
                                <select name="is_verified" class="form-input w-full">
                                    <option value="">All</option>
                                    <option value="1" <?= $filters['is_verified'] === '1' ? 'selected' : '' ?>>Yes</option>
                                    <option value="0" <?= $filters['is_verified'] === '0' ? 'selected' : '' ?>>No</option>
                                </select>
                            </div>

                            <!-- Privacy Level -->
                            <div>
                                <label class="form-label text-white mb-2">Privacy</label>
                                <select name="privacy_level" class="form-input w-full">
                                    <option value="">All</option>
                                    <option value="public" <?= $filters['privacy_level'] === 'public' ? 'selected' : '' ?>>Public</option>
                                    <option value="private" <?= $filters['privacy_level'] === 'private' ? 'selected' : '' ?>>Private</option>
                                    <option value="invite_only" <?= $filters['privacy_level'] === 'invite_only' ? 'selected' : '' ?>>Invite Only</option>
                                </select>
                            </div>

                            <!-- Status Filter -->
                            <div>
                                <label class="form-label text-white mb-2">Status</label>
                                <select name="status" class="form-input w-full">
                                    <option value="">Active Only</option>
                                    <option value="all" <?= $statusFilter === 'all' ? 'selected' : '' ?>>All Statuses</option>
                                    <option value="active" <?= $statusFilter === 'active' ? 'selected' : '' ?>>Active</option>
                                    <option value="pending_approval" <?= $statusFilter === 'pending_approval' ? 'selected' : '' ?>>Pending Approval</option>
                                </select>
                            </div>
                        </div>

                        <div class="flex items-center space-x-4">
                            <button type="submit" class="btn btn-primary">
                                <i data-lucide="search" class="w-4 h-4 mr-2"></i>
                                Search
                            </button>
                            <a href="community_groups.php" class="btn btn-outline">
                                <i data-lucide="refresh-cw" class="w-4 h-4 mr-2"></i>
                                Reset
                            </a>
                        </div>
                    </form>
                </div>
            </section>

            <!-- Groups List -->
            <section>
                <div class="mb-6 flex items-center justify-between">
                    <div>
                        <h2 class="heading-2 text-white">Available Groups (<?= count($groups) ?>)</h2>
                        <?php if ($statusFilter): ?>
                            <p class="text-white/60 text-sm mt-1">Filtered by: <?= ucfirst(str_replace('_', ' ', $statusFilter)) ?></p>
                        <?php endif; ?>
                    </div>
                    <?php if (!empty($groups)): ?>
                        <div class="flex items-center space-x-2">
                            <span class="text-sm text-white/60">Sort:</span>
                            <select class="form-input text-sm" onchange="window.location.href='community_groups.php?sort=' + this.value">
                                <option value="newest">Newest First</option>
                                <option value="oldest">Oldest First</option>
                                <option value="members">Most Members</option>
                                <option value="verified">Verified First</option>
                            </select>
                        </div>
                    <?php endif; ?>
                </div>

                <?php if (empty($groups)): ?>
                    <div class="card card-glass text-center p-12">
                        <div class="w-20 h-20 bg-gradient-to-r from-gray-500 to-gray-600 rounded-full flex items-center justify-center mx-auto mb-6">
                            <i data-lucide="users-x" class="w-10 h-10 text-white"></i>
                        </div>
                        <h3 class="heading-3 text-white mb-3">No Groups Found</h3>
                        <p class="text-white/60 mb-6">Try adjusting your search filters or create a new group for your area.</p>
                        <a href="create_group.php" class="btn btn-primary">
                            <i data-lucide="plus-circle" class="w-4 h-4 mr-2"></i>
                            Create New Group
                        </a>
                    </div>
                <?php else: ?>
                    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                        <?php foreach ($groups as $index => $group):
                            $isMember = $models->isGroupMember($group['id'], $userId);
                        ?>
                            <div class="group-card animate-slide-in" style="animation-delay: <?= $index * 0.1 ?>s;">
                                <div class="flex items-start justify-between mb-4">
                                    <div class="flex-1">
                                        <div class="flex items-center space-x-2 mb-2">
                                            <h3 class="heading-4 text-white"><?= htmlspecialchars($group['group_name']) ?></h3>
                                            <?php if ($group['is_verified']): ?>
                                                <i data-lucide="badge-check" class="w-5 h-5 text-blue-400" title="Verified"></i>
                                            <?php endif; ?>
                                        </div>
                                        <p class="text-sm text-white/60 mb-2"><?= htmlspecialchars($group['area_name']) ?></p>
                                        <?php if ($group['description']): ?>
                                            <p class="text-sm text-white/70 mb-3 line-clamp-2"><?= htmlspecialchars(substr($group['description'], 0, 100)) ?><?= strlen($group['description']) > 100 ? '...' : '' ?></p>
                                        <?php endif; ?>
                                    </div>
                                </div>

                                <div class="space-y-2 mb-4">
                                    <div class="flex items-center text-sm text-white/70">
                                        <i data-lucide="map-pin" class="w-4 h-4 mr-2 text-blue-400"></i>
                                        <?= htmlspecialchars($group['district']) ?><?= $group['upazila'] ? ', ' . htmlspecialchars($group['upazila']) : '' ?>
                                    </div>
                                    <div class="flex items-center justify-between">
                                        <div class="flex items-center text-sm text-white/70">
                                            <i data-lucide="users" class="w-4 h-4 mr-2 text-green-400"></i>
                                            <?= $group['member_count'] ?? 0 ?> member<?= ($group['member_count'] ?? 0) != 1 ? 's' : '' ?>
                                        </div>
                                        <?php if ($group['creator_name']): ?>
                                            <div class="flex items-center text-sm text-white/60">
                                                <i data-lucide="user" class="w-4 h-4 mr-1"></i>
                                                <?= htmlspecialchars($group['creator_name']) ?>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                    <div class="flex items-center text-sm text-white/70">
                                        <i data-lucide="lock" class="w-4 h-4 mr-2 text-purple-400"></i>
                                        <?= ucfirst(str_replace('_', ' ', $group['privacy_level'])) ?>
                                    </div>
                                    <?php if ($group['ward_number']): ?>
                                        <div class="flex items-center text-sm text-white/70">
                                            <i data-lucide="hash" class="w-4 h-4 mr-2 text-orange-400"></i>
                                            Ward <?= htmlspecialchars($group['ward_number']) ?>
                                        </div>
                                    <?php endif; ?>
                                    <div class="flex items-center text-sm text-white/60">
                                        <i data-lucide="calendar" class="w-4 h-4 mr-2"></i>
                                        Created <?= date('M j, Y', strtotime($group['created_at'])) ?>
                                    </div>
                                </div>

                                <div class="flex items-center space-x-2 pt-4 border-t border-white/10">
                                    <?php if ($isMember): ?>
                                        <a href="group_detail.php?id=<?= $group['id'] ?>" class="btn btn-primary flex-1 text-center">
                                            <i data-lucide="arrow-right" class="w-4 h-4 mr-2"></i>
                                            View Group
                                        </a>
                                    <?php else: ?>
                                        <a href="group_handler.php?action=join&group_id=<?= $group['id'] ?>" class="btn btn-primary flex-1 text-center">
                                            <i data-lucide="user-plus" class="w-4 h-4 mr-2"></i>
                                            Join Group
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

