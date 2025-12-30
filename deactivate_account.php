<?php
session_start();
require 'auth.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.html');
    exit;
}

$user_id = $_SESSION['user_id'];
$errors = [];
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $password = $_POST['password'] ?? '';
    $action = $_POST['action'] ?? '';

    // Fetch current password hash
    $stmt = $pdo->prepare('SELECT password FROM users WHERE id = ?');
    $stmt->execute([$user_id]);
    $user = $stmt->fetch();

    if (!$user || !password_verify($password, $user['password'])) {
        $errors[] = 'Password is incorrect.';
    } else {
        if ($action === 'deactivate') {
            $stmt = $pdo->prepare('UPDATE users SET is_active = 0 WHERE id = ?');
            $stmt->execute([$user_id]);
            session_unset();
            session_destroy();
            header('Location: login.html');
            exit;
        } elseif ($action === 'delete') {
            // Remove user and related data
            $stmt = $pdo->prepare('DELETE FROM users WHERE id = ?');
            $stmt->execute([$user_id]);
            // Optionally, delete from password_resets and email_verifications
            $pdo->prepare('DELETE FROM password_resets WHERE email = (SELECT email FROM users WHERE id = ?)')->execute([$user_id]);
            $pdo->prepare('DELETE FROM email_verifications WHERE user_id = ?')->execute([$user_id]);
            session_unset();
            session_destroy();
            header('Location: register.html');
            exit;
        }
    }
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>Deactivate/Delete Account</title>
</head>
<body>
<h2>Deactivate or Delete Account</h2>
<?php if (!empty($errors)) { echo '<div style="color:red">' . implode('<br>', $errors) . '</div>'; } ?>
<form method="post">
    <label>Enter your password to confirm:</label><br>
    <input type="password" name="password" required><br><br>
    <button type="submit" name="action" value="deactivate">Deactivate Account</button>
    <button type="submit" name="action" value="delete" onclick="return confirm('Are you sure? This cannot be undone!')">Delete Account Permanently</button>
</form>
<a href="dashboard.php">Back to Dashboard</a>
</body>
</html>