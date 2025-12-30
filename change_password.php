<?php
session_start();
require 'auth.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.html');
    exit;
}

$user_id = $_SESSION['user_id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $current_password = $_POST['current_password'] ?? '';
    $new_password = $_POST['new_password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';
    $errors = [];

    if (empty($current_password) || empty($new_password) || empty($confirm_password)) {
        $errors[] = 'All fields are required.';
    }
    if ($new_password !== $confirm_password) {
        $errors[] = 'New passwords do not match.';
    }
    if (strlen($new_password) < 8) {
        $errors[] = 'New password must be at least 8 characters.';
    }

    // Fetch current password hash
    $stmt = $pdo->prepare('SELECT password FROM users WHERE id = ?');
    $stmt->execute([$user_id]);
    $user = $stmt->fetch();

    if ($user && !password_verify($current_password, $user['password'])) {
        $errors[] = 'Current password is incorrect.';
    }

    if (empty($errors)) {
        $new_hash = password_hash($new_password, PASSWORD_DEFAULT);
        $stmt = $pdo->prepare('UPDATE users SET password = ? WHERE id = ?');
        $stmt->execute([$new_hash, $user_id]);
        $success = 'Password changed successfully!';
    }
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>Change Password</title>
</head>
<body>
<h2>Change Password</h2>
<?php if (!empty($errors)) { echo '<div style="color:red">' . implode('<br>', $errors) . '</div>'; } ?>
<?php if (!empty($success)) { echo '<div style="color:green">' . $success . '</div>'; } ?>
<form method="post">
    Current Password: <input type="password" name="current_password" required><br>
    New Password: <input type="password" name="new_password" required><br>
    Confirm New Password: <input type="password" name="confirm_password" required><br>
    <button type="submit">Change Password</button>
</form>
<a href="dashboard.php">Back to Dashboard</a>
</body>
</html>