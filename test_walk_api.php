<?php
// Mock session for testing
session_start();
$_SESSION['user_id'] = 1; // Assuming user ID 1 exists

$url = 'http://localhost/space-login/api/walk_control.php';
$data = ['action' => 'start', 'destination' => 'Test Destination', 'duration' => 15];

$options = [
    'http' => [
        'header'  => "Content-type: application/json\r\nCookie: PHPSESSID=" . session_id(),
        'method'  => 'POST',
        'content' => json_encode($data),
    ],
];
$context  = stream_context_create($options);
$result = file_get_contents($url, false, $context);

echo "Response: " . $result . "\n";

$response = json_decode($result, true);
if ($response['success']) {
    echo "Session started successfully. Token: " . $response['token'] . "\n";

    // Test End
    $dataEnd = ['action' => 'end', 'token' => $response['token']];
    $optionsEnd = [
        'http' => [
            'header'  => "Content-type: application/json\r\nCookie: PHPSESSID=" . session_id(),
            'method'  => 'POST',
            'content' => json_encode($dataEnd),
        ],
    ];
    $contextEnd  = stream_context_create($optionsEnd);
    $resultEnd = file_get_contents($url, false, $contextEnd);
    echo "End Response: " . $resultEnd . "\n";
} else {
    echo "Failed to start session.\n";
}
?>
