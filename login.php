<?php
session_start();

// If already logged in, go straight to dashboard
if (isset($_SESSION['user_id'])) {
    header('Location: dashboard.php');
    exit;
}

// Only process POST (form submission)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = trim($_POST['password'] ?? '');

    if (empty($username) || empty($password)) {
        header('Location: index.html?error=missing');
        exit;
    }

    // Connect to DB
    $host = 'localhost';
    $db = 'space_login'; // correct DB name with underscore
    $user = 'root';
    $pass = '';

    $conn = new mysqli($host, $user, $pass, $db);
    if ($conn->connect_error) {
        header('Location: index.html?error=db');
        exit;
    }

    // Lookup user by username OR email
    $stmt = $conn->prepare('SELECT id, password, display_name, email FROM users WHERE username = ? OR email = ? LIMIT 1');
    $stmt->bind_param('ss', $username, $username);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    $stmt->close();
    $conn->close();

    if ($row && password_verify($password, $row['password'])) {
        // Set session
        $_SESSION['user_id'] = $row['id'];
        $_SESSION['display_name'] = $row['display_name'] ?? $username;
        $_SESSION['email'] = $row['email'] ?? '';
        $_SESSION['login_time'] = time();
        session_regenerate_id(true);
        header('Location: dashboard.php');
        exit;
    }
    else {
        header('Location: index.html?error=invalid');
        exit;
    }
}

// GET request — just go to login page
header('Location: index.html');
exit;
?>