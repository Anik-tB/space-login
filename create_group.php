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

// Handle form submission
$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'create_group') {
    try {
        $groupData = [
            'group_name' => trim($_POST['group_name']),
            'description' => trim($_POST['description'] ?? ''),
            'area_name' => trim($_POST['area_name']),
            'ward_number' => trim($_POST['ward_number'] ?? ''),
            'union_name' => trim($_POST['union_name'] ?? ''),
            'upazila' => trim($_POST['upazila'] ?? ''),
            'district' => trim($_POST['district']),
            'division' => trim($_POST['division'] ?? ''),
            'privacy_level' => $_POST['privacy_level'] ?? 'public',
            'rules' => trim($_POST['rules'] ?? ''),
            'created_by' => $userId
        ];

        if (empty($groupData['group_name']) || empty($groupData['area_name']) || empty($groupData['district'])) {
            throw new Exception('Please fill in all required fields (Group Name, Area Name, District).');
        }

        if (strlen($groupData['group_name']) < 5) {
            throw new Exception('Group name must be at least 5 characters long.');
        }

        $groupId = $models->createNeighborhoodGroup($groupData);

        if ($groupId) {
            // Create notification
            $models->createNotification([
                'user_id' => $userId,
                'title' => 'Group Created Successfully',
                'message' => 'Your community group "' . $groupData['group_name'] . '" has been created and is pending approval.',
                'type' => 'system',
                'action_url' => 'community_groups.php'
            ]);

            header('Location: community_groups.php?success=1');
            exit;
        } else {
            throw new Exception('Failed to create group. Please try again.');
        }
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

$divisionOptions = $models->getDivisionsList();
$districtOptions = $models->getDistrictsList();
$upazilaOptions = $models->getUpazilasList();

// Fallback values if region tables are still empty
if (empty($divisionOptions)) {
    $fallbackDivisions = ['Dhaka', 'Chittagong', 'Sylhet', 'Rajshahi', 'Khulna', 'Barisal', 'Rangpur', 'Mymensingh'];
    $divisionOptions = array_map(function ($name) {
        return ['id' => null, 'name' => $name];
    }, $fallbackDivisions);
}

if (empty($districtOptions)) {
    $fallbackDistricts = ['Dhaka', 'Gazipur', 'Narayanganj', 'Tangail', 'Manikganj', 'Munshiganj', 'Narsingdi', 'Faridpur', 'Gopalganj', 'Madaripur', 'Shariatpur', 'Rajbari', 'Kishoreganj', 'Netrokona', 'Jamalpur', 'Sherpur', 'Mymensingh', 'Chittagong', 'Cox\'s Bazar', 'Bandarban', 'Rangamati', 'Khagrachhari', 'Feni', 'Lakshmipur', 'Noakhali', 'Chandpur', 'Comilla', 'Brahmanbaria', 'Sylhet', 'Moulvibazar', 'Habiganj', 'Sunamganj'];
    $districtOptions = array_map(function ($name) {
        return ['id' => null, 'name' => $name];
    }, $fallbackDistricts);
}

if (empty($upazilaOptions)) {
    $fallbackUpazilas = ['Dhanmondi', 'Gulshan', 'Mirpur', 'Uttara', 'Banani', 'Wari', 'Motijheel'];
    $upazilaOptions = array_map(function ($name) {
        return ['id' => null, 'name' => $name];
    }, $fallbackUpazilas);
}
?>
<!DOCTYPE html>
<html lang="<?= $lang ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Create Community Group - SafeSpace Portal</title>

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
        <div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8">
            <section class="mb-8 animate-fade-in">
                <div class="text-center">
                    <div class="w-20 h-20 bg-gradient-to-r from-green-500 via-emerald-500 to-teal-500 rounded-3xl flex items-center justify-center mx-auto mb-6 shadow-2xl ring-4 ring-green-500/20">
                        <i data-lucide="plus-circle" class="w-10 h-10 text-white drop-shadow-lg"></i>
                    </div>
                    <h1 class="heading-1 mb-4 text-white">Create Community Group</h1>
                    <p class="body-large max-w-2xl mx-auto text-white/80 leading-relaxed">
                        Start a new neighborhood safety group for your area. Bring your community together to stay safe and informed.
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

            <section class="animate-fade-in-up">
                <div class="liquid-glass rounded-3xl p-8">
                    <form method="POST" class="space-y-6" id="groupForm">
                        <input type="hidden" name="action" value="create_group">

                        <!-- Group Name -->
                        <div>
                            <label class="form-label text-white mb-2 flex items-center">
                                <i data-lucide="users" class="w-4 h-4 mr-2 text-green-400"></i>
                                Group Name <span class="text-red-400">*</span>
                            </label>
                            <input type="text" name="group_name" class="form-input-enhanced w-full"
                                   placeholder="e.g., Dhanmondi Community Watch" required
                                   minlength="5" maxlength="255">
                            <p class="text-xs text-white/50 mt-1">Minimum 5 characters</p>
                        </div>

                        <!-- Description -->
                        <div>
                            <label class="form-label text-white mb-2 flex items-center">
                                <i data-lucide="align-left" class="w-4 h-4 mr-2 text-blue-400"></i>
                                Description
                            </label>
                            <textarea name="description" rows="4" class="form-input-enhanced w-full"
                                      placeholder="Describe the purpose and goals of this community group..."></textarea>
                        </div>

                        <!-- Location Information -->
                        <div class="space-y-4">
                            <h3 class="heading-3 text-white mb-4">Location Information</h3>

                            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                <!-- Division -->
                                <div>
                                    <label class="form-label text-white mb-2">Division</label>
                                    <select name="division" class="form-input-enhanced w-full">
                                        <option value="">Select Division</option>
                                        <?php foreach ($divisionOptions as $division): ?>
                                            <option value="<?= htmlspecialchars($division['name']) ?>">
                                                <?= htmlspecialchars($division['name']) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                                <!-- District -->
                                <div>
                                    <label class="form-label text-white mb-2">District <span class="text-red-400">*</span></label>
                                    <select name="district" class="form-input-enhanced w-full" required>
                                        <option value="">Select District</option>
                                        <?php foreach ($districtOptions as $district): ?>
                                            <option value="<?= htmlspecialchars($district['name']) ?>">
                                                <?= htmlspecialchars($district['name']) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                                <!-- Upazila -->
                                <div>
                                    <label class="form-label text-white mb-2">Upazila</label>
                                    <input type="text" name="upazila" class="form-input-enhanced w-full"
                                           placeholder="e.g., Dhanmondi" list="available-upazilas">
                                    <datalist id="available-upazilas">
                                        <?php foreach ($upazilaOptions as $upazilaOption): ?>
                                            <option value="<?= htmlspecialchars($upazilaOption['name']) ?>"></option>
                                        <?php endforeach; ?>
                                    </datalist>
                                </div>

                                <!-- Union -->
                                <div>
                                    <label class="form-label text-white mb-2">Union</label>
                                    <input type="text" name="union_name" class="form-input-enhanced w-full"
                                           placeholder="e.g., Dhanmondi">
                                </div>

                                <!-- Ward Number -->
                                <div>
                                    <label class="form-label text-white mb-2">Ward Number</label>
                                    <input type="text" name="ward_number" class="form-input-enhanced w-full"
                                           placeholder="e.g., 27">
                                </div>

                                <!-- Area Name -->
                                <div>
                                    <label class="form-label text-white mb-2">Area Name <span class="text-red-400">*</span></label>
                                    <input type="text" name="area_name" class="form-input-enhanced w-full"
                                           placeholder="e.g., Dhanmondi, Gulshan, Mirpur" required>
                                </div>
                            </div>
                        </div>

                        <!-- Privacy Level -->
                        <div>
                            <label class="form-label text-white mb-2 flex items-center">
                                <i data-lucide="lock" class="w-4 h-4 mr-2 text-purple-400"></i>
                                Privacy Level
                            </label>
                            <select name="privacy_level" class="form-input-enhanced w-full">
                                <option value="public">Public - Anyone can join</option>
                                <option value="private">Private - Approval required</option>
                                <option value="invite_only">Invite Only - Members must be invited</option>
                            </select>
                        </div>

                        <!-- Group Rules -->
                        <div>
                            <label class="form-label text-white mb-2 flex items-center">
                                <i data-lucide="file-text" class="w-4 h-4 mr-2 text-orange-400"></i>
                                Group Rules (Optional)
                            </label>
                            <textarea name="rules" rows="4" class="form-input-enhanced w-full"
                                      placeholder="Set rules for your group members. For example:&#10;1. Be respectful to all members&#10;2. Only post verified information&#10;3. No personal attacks"></textarea>
                        </div>

                        <!-- Info Box -->
                        <div class="bg-blue-500/10 border border-blue-500/30 rounded-xl p-4">
                            <div class="flex items-start">
                                <i data-lucide="info" class="w-5 h-5 text-blue-400 mr-3 mt-0.5"></i>
                                <div>
                                    <h4 class="font-semibold text-blue-300 mb-2">Group Creation Process</h4>
                                    <ul class="text-blue-200 text-sm space-y-1">
                                        <li>• Your group will be created and set to "Pending Approval" status</li>
                                        <li>• Administrators will review your group within 24-48 hours</li>
                                        <li>• Once approved, your group will be visible to other users</li>
                                        <li>• You will be the founder and can manage group settings</li>
                                        <li>• Verified groups get priority in search results</li>
                                    </ul>
                                </div>
                            </div>
                        </div>

                        <!-- Submit Button -->
                        <div class="flex items-center justify-end space-x-4 pt-4">
                            <a href="community_groups.php" class="btn btn-outline">Cancel</a>
                            <button type="submit" class="btn btn-primary">
                                <i data-lucide="check" class="w-4 h-4 mr-2"></i>
                                Create Group
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
        document.getElementById('groupForm').addEventListener('submit', function(e) {
            const groupName = this.querySelector('input[name="group_name"]').value.trim();
            const areaName = this.querySelector('input[name="area_name"]').value.trim();
            const district = this.querySelector('select[name="district"]').value;

            if (!groupName || !areaName || !district) {
                e.preventDefault();
                alert('Please fill in all required fields (Group Name, Area Name, District).');
                return false;
            }

            if (groupName.length < 5) {
                e.preventDefault();
                alert('Group name must be at least 5 characters long.');
                return false;
            }
        });
    </script>
</body>
</html>

