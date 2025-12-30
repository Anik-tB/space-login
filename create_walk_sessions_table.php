<?php
/**
 * Create Walk Sessions Table
 * This script ensures the walk_sessions table exists for the Walk With Me feature
 */

require_once 'includes/Database.php';

try {
    $database = new Database();
    $conn = $database->getConnection();

    // Check if table exists
    $tableExists = $database->tableExists('walk_sessions');

    if (!$tableExists) {
        echo "Creating walk_sessions table...<br>";

        $sql = "CREATE TABLE IF NOT EXISTS `walk_sessions` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `user_id` int(11) NOT NULL,
            `session_token` varchar(64) NOT NULL,
            `start_time` datetime DEFAULT current_timestamp(),
            `end_time` datetime DEFAULT NULL,
            `status` enum('active', 'completed', 'emergency') DEFAULT 'active',
            `destination` varchar(255) DEFAULT NULL,
            `estimated_duration_minutes` int(11) DEFAULT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `session_token` (`session_token`),
            KEY `user_id` (`user_id`),
            KEY `status` (`status`),
            KEY `start_time` (`start_time`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;";

        $database->executeRaw($sql);
        echo "✅ walk_sessions table created successfully!<br>";
    } else {
        echo "✅ walk_sessions table already exists!<br>";
    }

    // Check table structure
    $sql = "DESCRIBE walk_sessions";
    $columns = $database->fetchAll($sql);

    echo "<h3>Table Structure:</h3>";
    echo "<table border='1' style='border-collapse: collapse; width: 100%;'>";
    echo "<tr><th>Field</th><th>Type</th><th>Null</th><th>Key</th><th>Default</th></tr>";

    foreach ($columns as $column) {
        echo "<tr>";
        echo "<td>" . htmlspecialchars($column['Field']) . "</td>";
        echo "<td>" . htmlspecialchars($column['Type']) . "</td>";
        echo "<td>" . htmlspecialchars($column['Null']) . "</td>";
        echo "<td>" . htmlspecialchars($column['Key']) . "</td>";
        echo "<td>" . htmlspecialchars($column['Default'] ?? 'NULL') . "</td>";
        echo "</tr>";
    }
    echo "</table>";

    echo "<br><p style='color: green;'>✅ Walk With Me feature is ready to use!</p>";

} catch (Exception $e) {
    echo "<p style='color: red;'>❌ Error: " . htmlspecialchars($e->getMessage()) . "</p>";
}
?>
