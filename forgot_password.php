<?php
include 'db.php';

function is_valid_email($email) {
    return filter_var($email, FILTER_VALIDATE_EMAIL);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email']);
    if (!is_valid_email($email)) {
        echo 'Invalid email format.';
        exit;
    }
    $stmt = $conn->prepare('SELECT id, email_verified FROM users WHERE email = ?');
    $stmt->bind_param('s', $email);
    $stmt->execute();
    $stmt->store_result();
    if ($stmt->num_rows > 0) {
        $stmt->bind_result($user_id, $email_verified);
        $stmt->fetch();
        if (!$email_verified) {
            echo 'Please verify your email before resetting password.';
            exit;
        }
        $reset_token = bin2hex(random_bytes(32));
        $expires = date('Y-m-d H:i:s', time() + 3600); // 1 hour expiry
        $update = $conn->prepare('UPDATE users SET password_reset_token = ?, reset_token_expires = ? WHERE id = ?');
        $update->bind_param('ssi', $reset_token, $expires, $user_id);
        $update->execute();
        // Send reset email
        $reset_link = "http://" . $_SERVER['HTTP_HOST'] . dirname($_SERVER['REQUEST_URI']) . "/reset_password.html?token=$reset_token";
        $subject = "Password Reset Request";
        $message = "Click the following link to reset your password: $reset_link\n\nThis link will expire in 1 hour.";
        $headers = "From: no-reply@space-login.local";
        mail($email, $subject, $message, $headers);
        echo 'Password reset link sent! Please check your email.';
    } else {
        echo 'No user found with that email.';
    }
    $stmt->close();
    $conn->close();
} else {
    echo 'Invalid request.';
}
?>