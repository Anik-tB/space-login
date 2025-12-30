<?php
session_start();
header('Content-Type: application/json');
require_once __DIR__ . '/../includes/Database.php';

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$user_id = $_SESSION['user_id'];
$data = json_decode(file_get_contents('php://input'), true);
$action = $data['action'] ?? '';

try {
    $db = new Database();
    $conn = $db->getConnection();

    if ($action === 'start') {
        $destination = $data['destination'] ?? '';
        $duration = $data['duration'] ?? 0;
        $token = bin2hex(random_bytes(32));

        $stmt = $conn->prepare("INSERT INTO walk_sessions (user_id, session_token, destination, estimated_duration_minutes, status) VALUES (?, ?, ?, ?, 'active')");
        $stmt->bind_param("issi", $user_id, $token, $destination, $duration);

        if ($stmt->execute()) {
            echo json_encode([
                'success' => true,
                'session_id' => $stmt->insert_id,
                'token' => $token
            ]);
        } else {
            throw new Exception("Failed to start session");
        }
    } elseif ($action === 'end') {
        $token = $data['token'] ?? '';
        $stmt = $conn->prepare("UPDATE walk_sessions SET status = 'completed', end_time = NOW() WHERE session_token = ? AND user_id = ?");
        $stmt->bind_param("si", $token, $user_id);
        $stmt->execute();
        echo json_encode(['success' => true]);
    } elseif ($action === 'sos') {
        $token = $data['token'] ?? '';
        $latitude = $data['latitude'] ?? null;
        $longitude = $data['longitude'] ?? null;
        $message = $data['message'] ?? '';

        $stmt = $conn->prepare("UPDATE walk_sessions SET status = 'emergency' WHERE session_token = ? AND user_id = ?");
        $stmt->bind_param("si", $token, $user_id);
        $stmt->execute();

        // Get session details for notification
        $sessionStmt = $conn->prepare("SELECT * FROM walk_sessions WHERE session_token = ? AND user_id = ?");
        $sessionStmt->bind_param("si", $token, $user_id);
        $sessionStmt->execute();
        $session = $sessionStmt->get_result()->fetch_assoc();

        // Get user info
        $userStmt = $conn->prepare("SELECT display_name, email, phone FROM users WHERE id = ?");
        $userStmt->bind_param("i", $user_id);
        $userStmt->execute();
        $user = $userStmt->get_result()->fetch_assoc();

        // Get emergency contacts
        $contactsStmt = $conn->prepare("SELECT * FROM emergency_contacts WHERE user_id = ? AND is_active = 1 ORDER BY priority ASC");
        $contactsStmt->bind_param("i", $user_id);
        $contactsStmt->execute();
        $contacts = $contactsStmt->get_result()->fetch_all(MYSQLI_ASSOC);

        // Create tracking link
        $basePath = dirname(dirname($_SERVER['PHP_SELF']));
        $trackingLink = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http") . "://" . $_SERVER['HTTP_HOST'] . $basePath . "/track_walk.php?token=" . $token;

        // Create panic alert linked to walk session
        require_once __DIR__ . '/../includes/Database.php';
        $models = new SafeSpaceModels($db);

        // Get current location from Firebase if available (or use provided lat/lng)
        $currentLat = $latitude;
        $currentLng = $longitude;

        $panicAlertData = [
            'user_id' => $user_id,
            'trigger_method' => 'walk_with_me',
            'location_name' => $session['destination'] ?? 'During Walk',
            'latitude' => $latitude,
            'longitude' => $longitude,
            'message' => "SOS triggered during Walk With Me session. " . ($message ?: "Emergency alert from active walk session.") . "\n\nTracking Link: " . $trackingLink,
            'police_notified' => 1,
            'ambulance_notified' => 0,
            'fire_service_notified' => 0
        ];

        $panicAlertId = $models->createPanicAlert($panicAlertData);

        // Link panic alert to walk session (store in walk_sessions if we add a panic_alert_id column, or use audit log)
        if ($panicAlertId) {
            $linkStmt = $conn->prepare("INSERT INTO audit_logs (user_id, action, table_name, record_id, new_values, created_at) VALUES (?, 'panic_alert_from_walk', 'walk_sessions', ?, ?, NOW())");
            $linkData = json_encode(['panic_alert_id' => $panicAlertId, 'walk_session_token' => $token]);
            $linkStmt->bind_param("iis", $user_id, $session['id'], $linkData);
            $linkStmt->execute();
        }

        // Create notification for each contact
        foreach ($contacts as $contact) {
            $contactMessage = "🚨 SOS ALERT - Walk With Me 🚨\n\n";
            $contactMessage .= "User: " . ($user['display_name'] ?? 'Unknown') . "\n";
            $contactMessage .= "Phone: " . ($user['phone'] ?? 'N/A') . "\n";
            $contactMessage .= "Location: " . ($session['destination'] ?? 'Active Walk Session') . "\n";
            if ($latitude && $longitude) {
                $contactMessage .= "GPS: " . $latitude . ", " . $longitude . "\n";
                $contactMessage .= "Map: https://maps.google.com/?q=" . $latitude . "," . $longitude . "\n";
            }
            $contactMessage .= "Live Tracking: " . $trackingLink . "\n";
            $contactMessage .= "Time: " . date('Y-m-d H:i:s') . "\n";
            if ($message) {
                $contactMessage .= "Message: " . $message . "\n";
            }
            $contactMessage .= "\n⚠️ EMERGENCY - Please check on them immediately!";

            // Insert notification
            $notifStmt = $conn->prepare("INSERT INTO notifications (user_id, type, title, message, is_read, created_at) VALUES (?, 'emergency', 'SOS Alert - Walk With Me', ?, 0, NOW())");
            $notifStmt->bind_param("is", $user_id, $contactMessage);
            $notifStmt->execute();
        }

        // Log the SOS event
        $logStmt = $conn->prepare("INSERT INTO audit_logs (user_id, action, table_name, record_id, new_values, created_at) VALUES (?, 'sos_alert', 'walk_sessions', ?, ?, NOW())");
        $logData = json_encode(['session_token' => $token, 'status' => 'emergency', 'contacts_notified' => count($contacts), 'panic_alert_id' => $panicAlertId]);
        $logStmt->bind_param("iis", $user_id, $session['id'], $logData);
        $logStmt->execute();

        echo json_encode([
            'success' => true,
            'contacts_notified' => count($contacts),
            'tracking_link' => $trackingLink,
            'panic_alert_id' => $panicAlertId
        ]);
    } else {
        throw new Exception("Invalid action");
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>
