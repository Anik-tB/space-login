<?php
session_start();
header('Content-Type: application/json');

require_once 'db.php'; // Uses $conn (MySQLi)

$data = json_decode(file_get_contents('php://input'), true);

$email = $data['email'] ?? '';
$display_name = $data['displayName'] ?? '';
$firebase_uid = $data['uid'] ?? '';
$provider = $data['provider'] ?? 'firebase';

if (!$email || !$firebase_uid) {
    echo json_encode(['success' => false, 'message' => 'Missing required fields']);
    exit;
}

// Check if user exists
$stmt = $conn->prepare('SELECT id, display_name FROM users WHERE email = ?');
$stmt->bind_param('s', $email);
$stmt->execute();
$result = $stmt->get_result();
if ($result->num_rows > 0) {
    // User exists - get existing data
    $userData = $result->fetch_assoc();
    $user_id = $userData['id'];
    $existing_display_name = $userData['display_name'] ?? '';

    // Preserve existing display_name from database if it exists and is not empty
    // Only use Firebase display_name if database doesn't have one
    $final_display_name = (!empty($existing_display_name)) ? $existing_display_name : $display_name;

    // Update last_login, firebase_uid, provider, and display_name (only if Firebase has a value and DB doesn't)
    $update = $conn->prepare('UPDATE users SET last_login = NOW(), firebase_uid = ?, provider = ? WHERE id = ?');
    $update->bind_param('ssi', $firebase_uid, $provider, $user_id);
    $update->execute();

    // Only update display_name if it's empty in database and Firebase has a value
    if (empty($existing_display_name) && !empty($display_name)) {
        $updateName = $conn->prepare('UPDATE users SET display_name = ? WHERE id = ?');
        $updateName->bind_param('si', $display_name, $user_id);
        $updateName->execute();
        $updateName->close();
        $final_display_name = $display_name;
    }

    $_SESSION['user_id'] = $user_id;
    $_SESSION['display_name'] = $final_display_name;
    $_SESSION['email'] = $email;
} else {
    // Insert new user
    $insert = $conn->prepare('INSERT INTO users (email, display_name, firebase_uid, provider, created_at, last_login, email_verified, status) VALUES (?, ?, ?, ?, NOW(), NOW(), 1, "active")');
    $insert->bind_param('ssss', $email, $display_name, $firebase_uid, $provider);
    $insert->execute();
    $_SESSION['user_id'] = $insert->insert_id;
    $_SESSION['display_name'] = $display_name;
    $_SESSION['email'] = $email;
}
$stmt->close();

// Create user session for tracking
if (isset($_SESSION['user_id'])) {
    $sessionToken = bin2hex(random_bytes(32));
    $_SESSION['session_token'] = $sessionToken;

    // Insert session into database
    $sessionInsert = $conn->prepare('INSERT INTO user_sessions (user_id, session_token, ip_address, user_agent, device_type, is_active) VALUES (?, ?, ?, ?, ?, 1)');
    $deviceType = detectDeviceType($_SERVER['HTTP_USER_AGENT'] ?? '');
    $sessionInsert->bind_param('issss', $_SESSION['user_id'], $sessionToken, $_SERVER['REMOTE_ADDR'], $_SERVER['HTTP_USER_AGENT'], $deviceType);
    $sessionInsert->execute();
    $sessionInsert->close();
}

echo json_encode(['success' => true, 'message' => 'User synced with MySQL', 'user_id' => $_SESSION['user_id']]);

/**
 * Detect device type from user agent
 */
function detectDeviceType($userAgent) {
    $userAgent = strtolower($userAgent);

    if (strpos($userAgent, 'mobile') !== false || strpos($userAgent, 'android') !== false || strpos($userAgent, 'iphone') !== false) {
        return 'mobile';
    } elseif (strpos($userAgent, 'tablet') !== false || strpos($userAgent, 'ipad') !== false) {
        return 'tablet';
    } else {
        return 'desktop';
    }
}