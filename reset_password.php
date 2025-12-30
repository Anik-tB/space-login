<?php
include 'db.php';

function is_strong_password($password) {
    return strlen($password) >= 8;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['token'] ?? '';
    $password = $_POST['password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';

    if (!$token) {
        echo 'Invalid or missing token.';
        exit;
    }
    if (!is_strong_password($password)) {
        echo 'Password must be at least 8 characters.';
        exit;
    }
    if ($password !== $confirm_password) {
        echo 'Passwords do not match.';
        exit;
    }

    $stmt = $conn->prepare('SELECT id, reset_token_expires FROM users WHERE password_reset_token = ?');
    $stmt->bind_param('s', $token);
    $stmt->execute();
    $stmt->store_result();
    if ($stmt->num_rows > 0) {
        $stmt->bind_result($user_id, $expires);
        $stmt->fetch();
        if (strtotime($expires) < time()) {
            echo 'Reset link has expired.';
            exit;
        }
        $hashed_password = password_hash($password, PASSWORD_DEFAULT);
        $update = $conn->prepare('UPDATE users SET password = ?, password_reset_token = NULL, reset_token_expires = NULL WHERE id = ?');
        $update->bind_param('si', $hashed_password, $user_id);
        $update->execute();
        echo 'Password reset successful! You can now <a href="index.html">login</a>.';
    } else {
        echo 'Invalid or expired reset link.';
    }
    $stmt->close();
    $conn->close();
} else {
    echo 'Invalid request.';
}
?>