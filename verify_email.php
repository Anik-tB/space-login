<?php
include 'db.php';

if (isset($_GET['token'])) {
    $token = $_GET['token'];
    $stmt = $conn->prepare('SELECT id FROM users WHERE verification_token = ? AND email_verified = 0');
    $stmt->bind_param('s', $token);
    $stmt->execute();
    $stmt->store_result();
    if ($stmt->num_rows > 0) {
        $stmt->bind_result($user_id);
        $stmt->fetch();
        // Mark email as verified
        $update = $conn->prepare('UPDATE users SET email_verified = 1, verification_token = NULL WHERE id = ?');
        $update->bind_param('i', $user_id);
        $update->execute();
        echo 'Email verified! You can now <a href="index.html">login</a>.';
    } else {
        echo 'Invalid or expired verification link.';
    }
    $stmt->close();
    $conn->close();
} else {
    echo 'No token provided.';
}
?>