<?php
/**
 * Twilio TwiML Generator for Emergency Calls
 * This generates TwiML XML for Twilio to speak the emergency message
 */

header('Content-Type: text/xml');

$message = $_GET['message'] ?? 'Emergency alert from SafeSpace. Please respond immediately.';

// Escape XML special characters
$message = htmlspecialchars($message, ENT_XML1, 'UTF-8');

echo '<?xml version="1.0" encoding="UTF-8"?>';
?>
<Response>
    <Say voice="alice" language="en-US">
        This is an emergency alert from SafeSpace.
        <?= $message ?>
        Please respond immediately.
    </Say>
    <Pause length="2"/>
    <Say voice="alice" language="en-US">
        This is an emergency alert. Please respond immediately.
    </Say>
</Response>

