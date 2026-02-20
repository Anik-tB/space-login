<?php
session_start();
$lang = $_SESSION['lang'] ?? 'en';
$lang_file = $lang === 'bn' ? 'lang_bn.php' : 'lang_en.php';
$L = include($lang_file);

require_once 'includes/error_handler.php';
require_once 'includes/security.php';
require_once 'includes/Database.php';

$database = new Database();
$models = new SafeSpaceModels($database);

$userId = $_SESSION['user_id'] ?? null;

if (!$userId) {
    header('Location: login.html');
    exit;
}

// Handle form submissions
$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'add') {
        $data = [
            'user_id' => $userId,
            'contact_name' => trim($_POST['contact_name'] ?? ''),
            'phone_number' => trim($_POST['phone_number'] ?? ''),
            'relationship' => trim($_POST['relationship'] ?? ''),
            'priority' => intval($_POST['priority'] ?? 1),
            'notification_methods' => implode(',', $_POST['notification_methods'] ?? ['sms', 'call'])
        ];

        if ($data['contact_name'] && $data['phone_number']) {
            $contactId = $models->addEmergencyContact($data);
            if ($contactId) {
                $message = 'Emergency contact added successfully!';
            } else {
                $error = 'Failed to add emergency contact.';
            }
        } else {
            $error = 'Please fill in all required fields.';
        }
    } elseif ($action === 'update') {
        $contactId = intval($_POST['contact_id'] ?? 0);
        $data = [
            'contact_name' => trim($_POST['contact_name'] ?? ''),
            'phone_number' => trim($_POST['phone_number'] ?? ''),
            'relationship' => trim($_POST['relationship'] ?? ''),
            'priority' => intval($_POST['priority'] ?? 1),
            'notification_methods' => implode(',', $_POST['notification_methods'] ?? ['sms', 'call']),
            'is_active' => isset($_POST['is_active']) ? 1 : 0
        ];

        if ($contactId && $data['contact_name'] && $data['phone_number']) {
            $result = $models->updateEmergencyContact($contactId, $data);
            if ($result) {
                $message = 'Emergency contact updated successfully!';
            } else {
                $error = 'Failed to update emergency contact.';
            }
        } else {
            $error = 'Please fill in all required fields.';
        }
    } elseif ($action === 'delete') {
        $contactId = intval($_POST['contact_id'] ?? 0);
        if ($contactId) {
            $result = $models->deleteEmergencyContact($contactId, $userId);
            if ($result) {
                $message = 'Emergency contact deleted successfully!';
            } else {
                $error = 'Failed to delete emergency contact.';
            }
        }
    }
}

// Get all emergency contacts
$contacts = $models->getUserEmergencyContacts($userId);
$editingContact = null;

if (isset($_GET['edit'])) {
    $editId = intval($_GET['edit']);
    $editingContact = $models->getEmergencyContactById($editId);
    if (!$editingContact || $editingContact['user_id'] != $userId) {
        $editingContact = null;
    }
}
?>
<!DOCTYPE html>
<html lang="<?= $lang ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Emergency Contacts - SafeSpace Portal</title>

    <link rel="icon" type="image/svg+xml" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><text y='.9em' font-size='90'>🛡️</text></svg>">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&family=Poppins:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/lucide/0.263.1/lucide.min.css">
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="design-system.css">
    <link rel="stylesheet" href="dashboard-styles.css">
</head>
<body class="bg-gradient-to-br from-slate-900 via-red-900 to-slate-900 min-h-screen">
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
                    <a href="panic_button.php" class="text-white/70 hover:text-white transition-colors duration-200">Panic Button</a>
                    <a href="my_emergencies.php" class="text-white/70 hover:text-white transition-colors duration-200">My Emergencies</a>
                </nav>
                <div class="flex items-center space-x-4">
                    <a href="panic_button.php" class="btn btn-ghost">
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
                    <div class="w-20 h-20 bg-gradient-to-r from-red-500 via-rose-500 to-pink-500 rounded-3xl flex items-center justify-center mx-auto mb-6 shadow-2xl ring-4 ring-red-500/20">
                        <i data-lucide="users" class="w-10 h-10 text-white drop-shadow-lg"></i>
                    </div>
                    <h1 class="heading-1 mb-4 text-white">Emergency Contacts</h1>
                    <p class="body-large max-w-2xl mx-auto text-white/80 leading-relaxed">
                        Manage your emergency contacts who will be notified when you trigger the panic button.
                    </p>
                </div>
            </section>

        <?php /* Messages are now rendered via toast.js after DOM load */ ?>

            <!-- Add/Edit Contact Form -->
            <section class="mb-8">
                <div class="card card-glass p-6">
                    <h2 class="heading-2 text-white mb-6">
                        <?= $editingContact ? 'Edit Emergency Contact' : 'Add Emergency Contact' ?>
                    </h2>
                    <form method="POST" class="space-y-4">
                        <input type="hidden" name="action" value="<?= $editingContact ? 'update' : 'add' ?>">
                        <?php if ($editingContact): ?>
                            <input type="hidden" name="contact_id" value="<?= $editingContact['id'] ?>">
                        <?php endif; ?>

                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div>
                                <label class="form-label text-white mb-2">Contact Name *</label>
                                <input type="text" name="contact_name"
                                       value="<?= htmlspecialchars($editingContact['contact_name'] ?? '') ?>"
                                       class="form-input w-full" required>
                            </div>

                            <div>
                                <label class="form-label text-white mb-2">Phone Number *</label>
                                <input type="tel" name="phone_number"
                                       value="<?= htmlspecialchars($editingContact['phone_number'] ?? '') ?>"
                                       class="form-input w-full" required>
                            </div>
                        </div>

                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div>
                                <label class="form-label text-white mb-2">Relationship</label>
                                <input type="text" name="relationship"
                                       value="<?= htmlspecialchars($editingContact['relationship'] ?? '') ?>"
                                       class="form-input w-full"
                                       placeholder="e.g., Family, Friend, Colleague">
                            </div>

                            <div>
                                <label class="form-label text-white mb-2">Priority</label>
                                <select name="priority" class="form-input w-full">
                                    <option value="1" <?= ($editingContact['priority'] ?? 1) == 1 ? 'selected' : '' ?>>1 - Highest</option>
                                    <option value="2" <?= ($editingContact['priority'] ?? 1) == 2 ? 'selected' : '' ?>>2 - High</option>
                                    <option value="3" <?= ($editingContact['priority'] ?? 1) == 3 ? 'selected' : '' ?>>3 - Medium</option>
                                    <option value="4" <?= ($editingContact['priority'] ?? 1) == 4 ? 'selected' : '' ?>>4 - Low</option>
                                </select>
                            </div>
                        </div>

                        <div>
                            <label class="form-label text-white mb-2">Notification Methods</label>
                            <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
                                <?php
                                $methods = ['sms', 'call', 'whatsapp', 'email'];
                                $selectedMethods = $editingContact ? explode(',', $editingContact['notification_methods']) : ['sms', 'call'];
                                foreach ($methods as $method):
                                ?>
                                    <label class="flex items-center space-x-2 cursor-pointer">
                                        <input type="checkbox" name="notification_methods[]"
                                               value="<?= $method ?>"
                                               <?= in_array($method, $selectedMethods) ? 'checked' : '' ?>
                                               class="form-checkbox">
                                        <span class="text-white/80 capitalize"><?= $method ?></span>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                        </div>

                        <?php if ($editingContact): ?>
                            <div>
                                <label class="flex items-center space-x-2 cursor-pointer">
                                    <input type="checkbox" name="is_active" value="1"
                                           <?= $editingContact['is_active'] ? 'checked' : '' ?>
                                           class="form-checkbox">
                                    <span class="text-white/80">Active (will receive notifications)</span>
                                </label>
                            </div>
                        <?php endif; ?>

                        <div class="flex items-center space-x-4 pt-4">
                            <?php if ($editingContact): ?>
                                <a href="emergency_contacts.php" class="btn btn-outline">
                                    Cancel
                                </a>
                            <?php endif; ?>
                            <button type="submit" class="btn btn-primary flex-1">
                                <i data-lucide="<?= $editingContact ? 'save' : 'plus' ?>" class="w-4 h-4 mr-2"></i>
                                <?= $editingContact ? 'Update Contact' : 'Add Contact' ?>
                            </button>
                        </div>
                    </form>
                </div>
            </section>

            <!-- Contacts List -->
            <section>
                <div class="mb-6 flex items-center justify-between">
                    <h2 class="heading-2 text-white">My Emergency Contacts (<?= count($contacts) ?>)</h2>
                </div>

                <?php if (empty($contacts)): ?>
                    <div class="card card-glass text-center p-12">
                        <i data-lucide="users" class="w-16 h-16 text-white/30 mx-auto mb-4"></i>
                        <h3 class="heading-3 text-white mb-3">No Emergency Contacts</h3>
                        <p class="text-white/60">Add emergency contacts above to be notified during emergencies.</p>
                    </div>
                <?php else: ?>
                    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                        <?php foreach ($contacts as $contact): ?>
                            <div class="card card-glass p-6 <?= !$contact['is_active'] ? 'opacity-60' : '' ?>">
                                <div class="flex items-start justify-between mb-4">
                                    <div class="flex-1">
                                        <h3 class="heading-4 text-white mb-1"><?= htmlspecialchars($contact['contact_name']) ?></h3>
                                        <?php if ($contact['relationship']): ?>
                                            <p class="text-sm text-white/60 mb-2"><?= htmlspecialchars($contact['relationship']) ?></p>
                                        <?php endif; ?>
                                        <p class="text-white/80 font-mono mb-3"><?= htmlspecialchars($contact['phone_number']) ?></p>
                                        <div class="flex items-center space-x-2 mb-3">
                                            <span class="px-2 py-1 rounded-full text-xs font-semibold bg-blue-500/20 text-blue-300 border border-blue-500/30">
                                                Priority <?= $contact['priority'] ?>
                                            </span>
                                            <?php if (!$contact['is_active']): ?>
                                                <span class="px-2 py-1 rounded-full text-xs font-semibold bg-gray-500/20 text-gray-300 border border-gray-500/30">
                                                    Inactive
                                                </span>
                                            <?php endif; ?>
                                        </div>
                                        <div class="flex items-center space-x-2 text-sm text-white/60">
                                            <i data-lucide="bell" class="w-4 h-4"></i>
                                            <span><?= htmlspecialchars($contact['notification_methods']) ?></span>
                                        </div>
                                    </div>
                                </div>

                                <div class="flex items-center space-x-2 pt-4 border-t border-white/10">
                                    <a href="emergency_contacts.php?edit=<?= $contact['id'] ?>" class="btn btn-outline flex-1">
                                        <i data-lucide="edit" class="w-4 h-4 mr-2"></i>
                                        Edit
                                    </a>
                                    <form method="POST" class="flex-1" onsubmit="return confirm('Are you sure you want to delete this contact?')">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="contact_id" value="<?= $contact['id'] ?>">
                                        <button type="submit" class="btn btn-outline w-full text-red-400 hover:text-red-300 hover:border-red-400">
                                            <i data-lucide="trash-2" class="w-4 h-4 mr-2"></i>
                                            Delete
                                        </button>
                                    </form>
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
    <script src="js/toast.js"></script>
    <?php if ($message): ?>
    <div data-toast="<?= htmlspecialchars($message, ENT_QUOTES) ?>" data-toast-type="success" hidden></div>
    <?php endif; ?>
    <?php if ($error): ?>
    <div data-toast="<?= htmlspecialchars($error, ENT_QUOTES) ?>" data-toast-type="error" hidden></div>
    <?php endif; ?>
    <script>
        lucide.createIcons();
    </script>
</body>
</html>

