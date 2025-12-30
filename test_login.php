<?php
/**
 * Simple Test Login - For Testing Admin Panel
 * This bypasses Firebase and directly sets session
 */
session_start();

$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = $_POST['email'] ?? '';
    $password = $_POST['password'] ?? '';

    if (empty($email)) {
        $error = 'Email is required';
    } else {
        try {
            require_once __DIR__ . '/includes/Database.php';
            $database = new Database();

            // Find user by email
            $user = $database->fetchOne("SELECT id, email, display_name FROM users WHERE email = ?", [$email]);

            if ($user) {
                // Set session
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['display_name'] = $user['display_name'] ?? $user['email'];
                $_SESSION['email'] = $user['email'];

                $message = 'Login successful! Redirecting...';
                header('Location: admin_dashboard_debug.php');
                exit;
            } else {
                // Create new user if doesn't exist
                $userId = $database->insert(
                    "INSERT INTO users (email, display_name, created_at, last_login, email_verified, status)
                     VALUES (?, ?, NOW(), NOW(), 1, 'active')",
                    [$email, explode('@', $email)[0]]
                );

                $_SESSION['user_id'] = $userId;
                $_SESSION['display_name'] = explode('@', $email)[0];
                $_SESSION['email'] = $email;

                $message = 'User created and logged in! Redirecting...';
                header('Location: admin_dashboard_debug.php');
                exit;
            }
        } catch (Exception $e) {
            $error = 'Error: ' . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>Test Login - Admin Panel</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            max-width: 400px;
            margin: 100px auto;
            padding: 20px;
            background: #f5f5f5;
        }
        .container {
            background: white;
            padding: 30px;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        h2 {
            color: #333;
            margin-bottom: 20px;
        }
        input {
            width: 100%;
            padding: 10px;
            margin: 10px 0;
            border: 1px solid #ddd;
            border-radius: 5px;
            box-sizing: border-box;
        }
        button {
            width: 100%;
            padding: 12px;
            background: #4f46e5;
            color: white;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            font-size: 16px;
            margin-top: 10px;
        }
        button:hover {
            background: #4338ca;
        }
        .message {
            padding: 10px;
            margin: 10px 0;
            border-radius: 5px;
        }
        .success {
            background: #dcfce7;
            color: #166534;
            border: 1px solid #10b981;
        }
        .error {
            background: #fee2e2;
            color: #b91c1c;
            border: 1px solid #ef4444;
        }
        .info {
            background: #dbeafe;
            color: #1e40af;
            padding: 15px;
            border-radius: 5px;
            margin-bottom: 20px;
            font-size: 14px;
        }
        .quick-login {
            margin-top: 20px;
            padding-top: 20px;
            border-top: 1px solid #eee;
        }
        .quick-login button {
            background: #10b981;
            margin-bottom: 10px;
        }
        .quick-login button:hover {
            background: #059669;
        }
    </style>
</head>
<body>
    <div class="container">
        <h2>🔐 Test Login</h2>

        <div class="info">
            <strong>Note:</strong> This is a test login page. Enter any email address to login.
            <br><br>
            <strong>For Admin Access:</strong> Use an email containing "admin" (e.g., admin@test.com)
        </div>

        <?php if ($message): ?>
            <div class="message success"><?= htmlspecialchars($message) ?></div>
        <?php endif; ?>

        <?php if ($error): ?>
            <div class="message error"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <form method="POST">
            <label>Email:</label>
            <input type="email" name="email" placeholder="Enter your email" required
                   value="<?= htmlspecialchars($_POST['email'] ?? '') ?>">

            <label>Password (not used, just for form):</label>
            <input type="password" name="password" placeholder="Password" value="test">

            <button type="submit">Login</button>
        </form>

        <div class="quick-login">
            <h3>Quick Login Options:</h3>
            <form method="POST">
                <input type="hidden" name="email" value="admin@test.com">
                <button type="submit">Login as Admin (admin@test.com)</button>
            </form>
            <form method="POST">
                <input type="hidden" name="email" value="admin@safespace.com">
                <button type="submit">Login as Admin (admin@safespace.com)</button>
            </form>
            <form method="POST">
                <input type="hidden" name="email" value="test@example.com">
                <button type="submit">Login as Regular User</button>
            </form>
        </div>

        <div style="margin-top: 20px; text-align: center;">
            <a href="login.html" style="color: #4f46e5;">Use Firebase Login Instead</a>
        </div>
    </div>
</body>
</html>

