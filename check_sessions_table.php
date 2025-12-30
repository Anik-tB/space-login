<?php
/**
 * Check and Create User Sessions Table
 * This script ensures the user_sessions table exists
 */

require_once 'includes/Database.php';

try {
    $database = new Database();
    
    // Check if user_sessions table exists
    $sql = "SHOW TABLES LIKE 'user_sessions'";
    $result = $database->fetchOne($sql);
    
    if (!$result) {
        echo "Creating user_sessions table...<br>";
        
        // Create user_sessions table
        $createTableSQL = "
        CREATE TABLE user_sessions (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            session_token VARCHAR(255) NOT NULL,
            ip_address VARCHAR(45),
            user_agent TEXT,
            device_type ENUM('desktop', 'mobile', 'tablet') DEFAULT 'desktop',
            location_data TEXT,
            
            -- Session details
            login_time DATETIME DEFAULT CURRENT_TIMESTAMP,
            last_activity DATETIME DEFAULT CURRENT_TIMESTAMP,
            logout_time DATETIME,
            is_active TINYINT(1) DEFAULT 1,
            
            -- Security
            is_secure TINYINT(1) DEFAULT 0,
            two_factor_verified TINYINT(1) DEFAULT 0,
            
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
            
            INDEX (user_id),
            INDEX (session_token),
            INDEX (is_active),
            INDEX (login_time)
        )";
        
        $database->executeRaw($createTableSQL);
        echo "✅ user_sessions table created successfully!<br>";
    } else {
        echo "✅ user_sessions table already exists!<br>";
    }
    
    // Check table structure
    $sql = "DESCRIBE user_sessions";
    $columns = $database->fetchAll($sql);
    
    echo "<h3>Table Structure:</h3>";
    echo "<table border='1'>";
    echo "<tr><th>Field</th><th>Type</th><th>Null</th><th>Key</th><th>Default</th></tr>";
    
    foreach ($columns as $column) {
        echo "<tr>";
        echo "<td>" . $column['Field'] . "</td>";
        echo "<td>" . $column['Type'] . "</td>";
        echo "<td>" . $column['Null'] . "</td>";
        echo "<td>" . $column['Key'] . "</td>";
        echo "<td>" . $column['Default'] . "</td>";
        echo "</tr>";
    }
    echo "</table>";
    
    // Check if there are any existing sessions
    $sql = "SELECT COUNT(*) as count FROM user_sessions";
    $result = $database->fetchOne($sql);
    echo "<br>Current sessions in database: " . $result['count'] . "<br>";
    
    echo "<br><a href='dashboard.php'>Go to Dashboard</a>";
    
} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage();
}
?> 