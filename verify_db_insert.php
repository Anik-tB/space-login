<?php
require_once 'includes/Database.php';

try {
    $db = new Database();
    $conn = $db->getConnection();

    $user_id = 1; // Assuming user 1 exists
    $token = bin2hex(random_bytes(32));
    $destination = 'Test Direct DB';
    $duration = 10;

    $stmt = $conn->prepare("INSERT INTO walk_sessions (user_id, session_token, destination, estimated_duration_minutes, status) VALUES (?, ?, ?, ?, 'active')");
    $stmt->bind_param("issi", $user_id, $token, $destination, $duration);

    if ($stmt->execute()) {
        echo "Direct DB Insert Successful. ID: " . $stmt->insert_id;

        // Clean up
        $conn->query("DELETE FROM walk_sessions WHERE id = " . $stmt->insert_id);
    } else {
        echo "Direct DB Insert Failed: " . $stmt->error;
    }
} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
}
?>
