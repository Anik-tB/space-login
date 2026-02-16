<?php
/**
 * SafeSpace Database Handler
 * Handles all database operations for the SafeSpace system
 *
 * Database Optimizations:
 * - Users table: Indexed on status, is_active for fast filtering
 * - Incident Reports: Indexed on reported_date, user_id+status (composite), incident_date+status (composite)
 * - Alerts: Indexed on location_name (with prefix), is_active, start_time
 *
 * Note: The 'nid' column has been removed from users table. Use 'nid_number' instead.
 */

class Database {
    private $conn;
    private $host;
    private $user;
    private $pass;
    private $db;

    public function __construct() {
        $this->host = getenv('SPACE_DB_HOST') ?: 'localhost';
        // Use XAMPP default 'root' and empty password
        $this->user = getenv('SPACE_DB_USER') ?: 'root';
        $this->pass = getenv('SPACE_DB_PASS') ?: '';
        $this->db = getenv('SPACE_DB_NAME') ?: 'space_login';
        $this->connect();
    }

    /**
     * Establish database connection
     */
    private function connect() {
        try {
            $this->conn = new mysqli($this->host, $this->user, $this->pass, $this->db);

            if ($this->conn->connect_error) {
                throw new Exception('Connection failed: ' . $this->conn->connect_error);
            }

            // Set charset to utf8mb4
            $this->conn->set_charset("utf8mb4");

        } catch (Exception $e) {
            error_log('Database connection error: ' . $e->getMessage());
            throw new Exception('Database connection failed');
        }
    }

    /**
     * Get database connection
     */
    public function getConnection() {
        return $this->conn;
    }

    /**
     * Prepare and execute a query with parameters
     */
    public function query($sql, $params = []) {
        try {
            $stmt = $this->conn->prepare($sql);

            if (!$stmt) {
                throw new Exception('Query preparation failed: ' . $this->conn->error);
            }

            if (!empty($params)) {
                $types = '';
                $bindParams = [];

                foreach ($params as $param) {
                    if (is_int($param)) {
                        $types .= 'i';
                    } elseif (is_float($param)) {
                        $types .= 'd';
                    } elseif (is_string($param)) {
                        $types .= 's';
                    } else {
                        $types .= 'b';
                    }
                    $bindParams[] = $param;
                }

                array_unshift($bindParams, $types);
                $stmt->bind_param(...$bindParams);
            }

            $stmt->execute();
            return $stmt;

        } catch (Exception $e) {
            error_log('Database query error: ' . $e->getMessage() . ' | SQL: ' . $sql);
            throw new Exception('Database operation failed: ' . $e->getMessage());
        }
    }

    /**
     * Fetch a single row
     */
    public function fetchOne($sql, $params = []) {
        $stmt = $this->query($sql, $params);
        $result = $stmt->get_result();
        return $result->fetch_assoc();
    }

    /**
     * Fetch all rows
     */
    public function fetchAll($sql, $params = []) {
        $stmt = $this->query($sql, $params);
        $result = $stmt->get_result();
        return $result->fetch_all(MYSQLI_ASSOC);
    }

    /**
     * Insert a record and return the last insert ID
     */
    public function insert($sql, $params = []) {
        $stmt = $this->query($sql, $params);
        return $this->conn->insert_id;
    }

    /**
     * Update records and return affected rows
     */
    public function update($sql, $params = []) {
        $stmt = $this->query($sql, $params);
        $affectedRows = $stmt->affected_rows;
        $stmt->close();
        return $affectedRows;
    }

    /**
     * Delete records and return affected rows
     */
    public function delete($sql, $params = []) {
        $stmt = $this->query($sql, $params);
        return $stmt->affected_rows;
    }

    /**
     * Begin transaction
     */
    public function beginTransaction() {
        $this->conn->begin_transaction();
    }

    /**
     * Commit transaction
     */
    public function commit() {
        $this->conn->commit();
    }

    /**
     * Rollback transaction
     */
    public function rollback() {
        $this->conn->rollback();
    }

    /**
     * Close database connection
     */
    public function close() {
        if ($this->conn) {
            $this->conn->close();
        }
    }

    /**
     * Escape string for safe SQL usage
     */
    public function escape($string) {
        return $this->conn->real_escape_string($string);
    }

    /**
     * Get last error
     */
    public function getLastError() {
        return $this->conn->error;
    }

    /**
     * Check if table exists
     */
    public function tableExists($tableName) {
        // Escape table name to prevent SQL injection
        $escapedTableName = $this->escape($tableName);
        // Use INFORMATION_SCHEMA instead of SHOW TABLES for better compatibility
        $sql = "SELECT COUNT(*) as count
                FROM information_schema.tables
                WHERE table_schema = ?
                AND table_name = ?";
        $result = $this->fetchOne($sql, [$this->db, $tableName]);
        return !empty($result) && ($result['count'] > 0);
    }

    /**
     * Get table structure
     */
    public function getTableStructure($tableName) {
        $sql = "DESCRIBE " . $this->escape($tableName);
        return $this->fetchAll($sql);
    }

    /**
     * Execute raw SQL (use with caution)
     */
    public function executeRaw($sql) {
        return $this->conn->query($sql);
    }

    /**
     * Get database statistics
     */
    public function getDatabaseStats() {
        $stats = [];

        // Get table sizes
        $sql = "SELECT
                    table_name,
                    ROUND(((data_length + index_length) / 1024 / 1024), 2) AS 'size_mb'
                FROM information_schema.tables
                WHERE table_schema = ?";

        $stats['table_sizes'] = $this->fetchAll($sql, [$this->db]);

        // Get record counts for main tables
        $tables = ['users', 'incident_reports', 'alerts', 'safe_spaces', 'disputes', 'notifications'];
        $stats['record_counts'] = [];

        foreach ($tables as $table) {
            if ($this->tableExists($table)) {
                $sql = "SELECT COUNT(*) as count FROM " . $this->escape($table);
                $result = $this->fetchOne($sql);
                $stats['record_counts'][$table] = $result['count'];
            }
        }


        return $stats;
    }

    /**
     * Backup database (basic implementation)
     */
    public function backup($filename = null) {
        if (!$filename) {
            $filename = 'safespace_backup_' . date('Y-m-d_H-i-s') . '.sql';
        }

        $tables = [];
        $result = $this->executeRaw("SHOW TABLES");

        while ($row = $result->fetch_array()) {
            $tables[] = $row[0];
        }

        $backup = '';

        foreach ($tables as $table) {
            // Get create table statement
            $result = $this->executeRaw("SHOW CREATE TABLE `$table`");
            $row = $result->fetch_array();
            $backup .= "\n\n" . $row[1] . ";\n\n";

            // Get table data
            $result = $this->executeRaw("SELECT * FROM `$table`");
            while ($row = $result->fetch_array()) {
                $backup .= "INSERT INTO `$table` VALUES (";
                foreach ($row as $data) {
                    $backup .= "'" . $this->escape($data) . "',";
                }
                $backup = rtrim($backup, ',');
                $backup .= ");\n";
            }
        }

        return $backup;
    }




    /**
     * Destructor to ensure connection is closed
     */
    public function __destruct() {
        $this->close();
    }
}

/**
 * SafeSpace Data Models
 * Specific database operations for SafeSpace entities
 */

class SafeSpaceModels {
    private $db;

    public function __construct($database) {
        $this->db = $database;
    }

    /**
     * User Operations
     */
    public function getUserById($id) {
        $sql = "SELECT * FROM users WHERE id = ?";
        return $this->db->fetchOne($sql, [$id]);
    }

    public function getUserByEmail($email) {
        $sql = "SELECT * FROM users WHERE email = ?";
        return $this->db->fetchOne($sql, [$email]);
    }

    public function createUser($data) {
        $sql = "INSERT INTO users (username, email, password, display_name, firebase_uid, provider)
                VALUES (?, ?, ?, ?, ?, ?)";
        return $this->db->insert($sql, [
            $data['username'] ?? null,
            $data['email'],
            $data['password'] ?? null,
            $data['display_name'] ?? null,
            $data['firebase_uid'] ?? null,
            $data['provider'] ?? 'local'
        ]);
    }

    public function updateUser($id, $data) {
        $sql = "UPDATE users SET
                username = ?,
                display_name = ?,
                email_verified = ?,
                status = ?,
                is_active = ?,
                last_login = NOW()
                WHERE id = ?";
        return $this->db->update($sql, [
            $data['username'] ?? null,
            $data['display_name'] ?? null,
            $data['email_verified'] ?? 0,
            $data['status'] ?? 'active',
            $data['is_active'] ?? 1,
            $id
        ]);
    }

    /**
     * Get users by status and active state (uses composite index)
     */
    public function getUsersByStatus($status = 'active', $isActive = 1, $limit = null) {
        $sql = "SELECT * FROM users WHERE status = ? AND is_active = ? ORDER BY created_at DESC";
        if ($limit) {
            $sql .= " LIMIT ?";
            return $this->db->fetchAll($sql, [$status, $isActive, $limit]);
        }
        return $this->db->fetchAll($sql, [$status, $isActive]);
    }

    /**
     * Incident Report Operations
     */
    public function createIncidentReport($data) {
        $sql = "INSERT INTO incident_reports (user_id, title, description, category, severity,
                location_name, latitude, longitude, address, incident_date, is_anonymous, is_public, evidence_files, witness_count)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
        $reportId = $this->db->insert($sql, [
            $data['user_id'],
            $data['title'],
            $data['description'],
            $data['category'],
            $data['severity'],
            $data['location_name'] ?? null,
            $data['latitude'] ?? null,
            $data['longitude'] ?? null,
            $data['address'] ?? null,
            $data['incident_date'] ?? null,
            $data['is_anonymous'] ?? 0,
            $data['is_public'] ?? 0,
            $data['evidence_files'] ?? null,
            $data['witness_count'] ?? 0
        ]);

        // Auto-update safety scores for the area
        if ($reportId) {
            $this->updateAreaSafetyScoreFromIncident($data);
        }

        return $reportId;
    }

    public function getIncidentReportById($id) {
        $sql = "SELECT * FROM incident_reports WHERE id = ?";
        return $this->db->fetchOne($sql, [$id]);
    }

    public function updateIncidentReport($id, $data) {
        $updateFields = [];
        $params = [];

        if (isset($data['status'])) {
            $updateFields[] = "status = ?";
            $params[] = $data['status'];

            // Set resolved_at when status changes to resolved
            if ($data['status'] === 'resolved' && !isset($data['resolved_at'])) {
                $updateFields[] = "resolved_at = NOW()";
            }
        }

        if (isset($data['updated_date'])) {
            $updateFields[] = "updated_date = ?";
            $params[] = $data['updated_date'];
        }

        if (isset($data['resolved_at'])) {
            $updateFields[] = "resolved_at = ?";
            $params[] = $data['resolved_at'];
        }

        if (empty($updateFields)) {
            return false;
        }

        $params[] = $id;
        $sql = "UPDATE incident_reports SET " . implode(", ", $updateFields) . " WHERE id = ?";
        $result = $this->db->update($sql, $params);

        // Auto-update safety scores when incident status changes
        if ($result && isset($data['status'])) {
            $report = $this->getIncidentReportById($id);
            if ($report) {
                $this->updateAreaSafetyScoreFromIncident([
                    'address' => $report['address'],
                    'location_name' => $report['location_name']
                ]);
            }
        }

        return $result;
    }

    public function getUserReports($userId, $limit = null) {
        $sql = "SELECT * FROM incident_reports WHERE user_id = ? ORDER BY reported_date DESC";
        if ($limit) {
            $sql .= " LIMIT ?";
            return $this->db->fetchAll($sql, [$userId, $limit]);
        }
        return $this->db->fetchAll($sql, [$userId]);
    }

    public function getDisputesByReportId($reportId) {
        $sql = "SELECT * FROM disputes WHERE report_id = ? ORDER BY created_at DESC";
        return $this->db->fetchAll($sql, [$reportId]);
    }

    public function getIncidentReports($userId = null, $limit = 10, $offset = 0) {
        if ($userId) {
            $sql = "SELECT * FROM incident_reports WHERE user_id = ? ORDER BY reported_date DESC LIMIT ? OFFSET ?";
            return $this->db->fetchAll($sql, [$userId, $limit, $offset]);
        } else {
            $sql = "SELECT * FROM incident_reports ORDER BY reported_date DESC LIMIT ? OFFSET ?";
            return $this->db->fetchAll($sql, [$limit, $offset]);
        }
    }

    /**
     * Get incident reports by user and status (uses composite index idx_user_status)
     */
    public function getUserReportsByStatus($userId, $status, $limit = null) {
        $sql = "SELECT * FROM incident_reports WHERE user_id = ? AND status = ? ORDER BY reported_date DESC";
        if ($limit) {
            $sql .= " LIMIT ?";
            return $this->db->fetchAll($sql, [$userId, $status, $limit]);
        }
        return $this->db->fetchAll($sql, [$userId, $status]);
    }

    /**
     * Get incident reports by date range and status (uses composite index idx_date_status)
     */
    public function getReportsByDateRangeAndStatus($startDate, $endDate, $status = null, $limit = null) {
        $sql = "SELECT * FROM incident_reports WHERE incident_date BETWEEN ? AND ?";
        $params = [$startDate, $endDate];

        if ($status) {
            $sql .= " AND status = ?";
            $params[] = $status;
        }

        $sql .= " ORDER BY incident_date DESC";

        if ($limit) {
            $sql .= " LIMIT ?";
            $params[] = $limit;
        }

        return $this->db->fetchAll($sql, $params);
    }

    /**
     * Alert Operations
     */
    public function createAlert($data) {
        $sql = "INSERT INTO alerts (title, description, type, severity, location_name,
                latitude, longitude, radius_km, source_type, source_user_id, related_report_id)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
        return $this->db->insert($sql, [
            $data['title'],
            $data['description'],
            $data['type'] ?? 'info',
            $data['severity'] ?? 'medium',
            $data['location_name'] ?? null,
            $data['latitude'] ?? null,
            $data['longitude'] ?? null,
            $data['radius_km'] ?? 1.0,
            $data['source_type'] ?? 'system',
            $data['source_user_id'] ?? null,
            $data['related_report_id'] ?? null
        ]);
    }

    public function getActiveAlerts($latitude = null, $longitude = null, $radius = 10) {
        // Base query for system alerts (select specific columns to match UNION)
        $systemAlertsQuery = "SELECT id, title, description, type, severity, location_name, latitude, longitude, radius_km, start_time, 'system' as source FROM alerts WHERE is_active = 1";

        // Query for panic alerts (Walk With Me / SOS)
        // Mapping panic_alerts columns to match alerts schema
        // stored radius_km as 5.0 for panic alerts
        $panicAlertsQuery = "SELECT id, 'SOS ALERT - HELP NEEDED' as title, message as description, 'emergency' as type, 'critical' as severity, location_name, latitude, longitude, 5.0 as radius_km, triggered_at as start_time, 'panic' as source FROM panic_alerts WHERE status = 'active'";

        if ($latitude && $longitude) {
            // Get alerts within radius (simplified distance calculation)
            $latRange = $radius / 111; // Approximate km to degrees
            $lonRange = $radius / (111 * cos(deg2rad($latitude)));

            $systemAlertsQuery .= " AND (latitude BETWEEN ? - ? AND ? + ?) AND (longitude BETWEEN ? - ? AND ? + ?)";
            $panicAlertsQuery .= " AND (latitude BETWEEN ? - ? AND ? + ?) AND (longitude BETWEEN ? - ? AND ? + ?)";

            $sql = "($systemAlertsQuery) UNION ALL ($panicAlertsQuery) ORDER BY start_time DESC";

            // Parameters for both queries (doubled)
            $params = [
                $latitude, $latRange, $latitude, $latRange,
                $longitude, $lonRange, $longitude, $lonRange,
                $latitude, $latRange, $latitude, $latRange,
                $longitude, $lonRange, $longitude, $lonRange
            ];

            return $this->db->fetchAll($sql, $params);
        } else {
            $sql = "($systemAlertsQuery) UNION ALL ($panicAlertsQuery) ORDER BY start_time DESC";
            return $this->db->fetchAll($sql);
        }
    }

    /**
     * Safe Space Operations
     */
    public function getSafeSpaces($latitude = null, $longitude = null, $radius = 5) {
        if ($latitude && $longitude) {
            // Get safe spaces within radius
            $sql = "SELECT * FROM safe_spaces WHERE status = 'active'
                    AND (latitude BETWEEN ? - ? AND ? + ?)
                    AND (longitude BETWEEN ? - ? AND ? + ?)
                    ORDER BY average_rating DESC";
            $latRange = $radius / 111;
            $lonRange = $radius / (111 * cos(deg2rad($latitude)));
            return $this->db->fetchAll($sql, [
                $latitude, $latRange, $latitude, $latRange,
                $longitude, $lonRange, $longitude, $lonRange
            ]);
        } else {
            $sql = "SELECT * FROM safe_spaces WHERE status = 'active' ORDER BY average_rating DESC";
            return $this->db->fetchAll($sql);
        }
    }

    /**
     * Safe Spaces Operations
     */
    public function createSafeSpace($data) {
        $sql = "INSERT INTO safe_spaces
                (name, description, category, address, latitude, longitude, city, state, country,
                 phone, email, website, hours_of_operation, features, accessibility_features,
                 created_by, status, is_verified)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending_verification', 0)";
        return $this->db->insert($sql, [
            $data['name'],
            $data['description'] ?? null,
            $data['category'],
            $data['address'],
            $data['latitude'],
            $data['longitude'],
            $data['city'] ?? null,
            $data['state'] ?? null,
            $data['country'] ?? 'Bangladesh',
            $data['phone'] ?? null,
            $data['email'] ?? null,
            $data['website'] ?? null,
            $data['hours_of_operation'] ?? null,
            $data['features'] ?? null,
            $data['accessibility_features'] ?? null,
            $data['created_by'] ?? null
        ]);
    }

    public function getSafeSpaceById($id) {
        $sql = "SELECT * FROM safe_spaces WHERE id = ?";
        return $this->db->fetchOne($sql, [$id]);
    }

    public function updateSafeSpace($id, $data) {
        $updateFields = [];
        $params = [];

        $fields = ['name', 'description', 'category', 'address', 'latitude', 'longitude',
                   'city', 'state', 'phone', 'email', 'website', 'hours_of_operation',
                   'features', 'accessibility_features', 'status', 'is_verified', 'verified_by'];

        foreach ($fields as $field) {
            if (isset($data[$field])) {
                $updateFields[] = "$field = ?";
                $params[] = $data[$field];
            }
        }

        if (isset($data['is_verified']) && $data['is_verified'] == 1) {
            $updateFields[] = "verified_date = NOW()";
        }

        if (empty($updateFields)) {
            return false;
        }

        $updateFields[] = "updated_at = NOW()";
        $params[] = $id;

        $sql = "UPDATE safe_spaces SET " . implode(', ', $updateFields) . " WHERE id = ?";
        return $this->db->update($sql, $params);
    }

    /**
     * Safety Resources Operations
     */
    public function getSafetyResources($category = null) {
        if ($category) {
            $sql = "SELECT * FROM safety_resources WHERE status = 'active' AND category = ? ORDER BY is_24_7 DESC, title ASC";
            return $this->db->fetchAll($sql, [$category]);
        } else {
            $sql = "SELECT * FROM safety_resources WHERE status = 'active' ORDER BY is_24_7 DESC, title ASC";
            return $this->db->fetchAll($sql);
        }
    }

    public function getSafetyResourceById($id) {
        $sql = "SELECT * FROM safety_resources WHERE id = ?";
        return $this->db->fetchOne($sql, [$id]);
    }

    public function createSafetyResource($data) {
        $sql = "INSERT INTO safety_resources (title, description, category, phone, email, website, address,
                is_24_7, hours_of_operation, languages, city, state, country, is_national, is_verified)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
        return $this->db->insert($sql, [
            $data['title'],
            $data['description'],
            $data['category'],
            $data['phone'] ?? null,
            $data['email'] ?? null,
            $data['website'] ?? null,
            $data['address'] ?? null,
            $data['is_24_7'] ?? 0,
            $data['hours_of_operation'] ?? null,
            $data['languages'] ?? null,
            $data['city'] ?? null,
            $data['state'] ?? null,
            $data['country'] ?? 'Bangladesh',
            $data['is_national'] ?? 0,
            $data['is_verified'] ?? 0
        ]);
    }

    /**
     * Notification Operations
     */
    public function createNotification($data) {
        $sql = "INSERT INTO notifications (user_id, title, message, type, action_url, action_data)
                VALUES (?, ?, ?, ?, ?, ?)";
        return $this->db->insert($sql, [
            $data['user_id'],
            $data['title'],
            $data['message'],
            $data['type'] ?? 'system',
            $data['action_url'] ?? null,
            $data['action_data'] ?? null
        ]);
    }

    public function getUserNotifications($userId, $limit = 20) {
        $sql = "SELECT * FROM notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT ?";
        return $this->db->fetchAll($sql, [$userId, $limit]);
    }

    public function markNotificationAsRead($notificationId) {
        $sql = "UPDATE notifications SET is_read = 1, read_at = NOW() WHERE id = ?";
        return $this->db->update($sql, [$notificationId]);
    }

    /**
     * Statistics Operations
     */
    public function getDashboardStats() {
        $stats = [];

        // Total reports
        $sql = "SELECT COUNT(*) as count FROM incident_reports";
        $result = $this->db->fetchOne($sql);
        $stats['total_reports'] = $result['count'];

        // Active alerts
        $sql = "SELECT COUNT(*) as count FROM alerts WHERE is_active = 1";
        $result = $this->db->fetchOne($sql);
        $stats['active_alerts'] = $result['count'];

        // Safe spaces
        $sql = "SELECT COUNT(*) as count FROM safe_spaces WHERE status = 'active'";
        $result = $this->db->fetchOne($sql);
        $stats['safe_spaces'] = $result['count'];

        // Recent reports (last 30 days)
        $sql = "SELECT COUNT(*) as count FROM incident_reports WHERE reported_date >= DATE_SUB(NOW(), INTERVAL 30 DAY)";
        $result = $this->db->fetchOne($sql);
        $stats['recent_reports'] = $result['count'];
        // Total users
        $sql = "SELECT COUNT(*) as count FROM users";
        $result = $this->db->fetchOne($sql);
        $stats['total_users'] = $result['count'];

        return $stats;
    }

    public function getReportCategories() {
        $sql = "SELECT category, COUNT(*) as count FROM incident_reports GROUP BY category ORDER BY count DESC";
        return $this->db->fetchAll($sql);
    }

  public function getRecentActivity($limit = 10) {
        $sql = "(SELECT 'report' as type, id, title, reported_date as date, user_id
                FROM incident_reports
                WHERE reported_date >= DATE_SUB(NOW(), INTERVAL 7 DAY))
                UNION ALL
                (SELECT 'alert' as type, id, title, start_time as date, source_user_id as user_id
                FROM alerts
                WHERE start_time >= DATE_SUB(NOW(), INTERVAL 7 DAY))
                UNION ALL
                (SELECT 'user' as type, id, email as title, created_at as date, id as user_id
                FROM users
                WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY))
                ORDER BY date DESC LIMIT ?";
        return $this->db->fetchAll($sql, [$limit]);
    }
    /**
     * Get recent reports for activity feed
     */
   public function getRecentReports($limit = 10) {
        $sql = "SELECT id, title, category, severity, status, reported_date, user_id
                FROM incident_reports
                WHERE reported_date >= DATE_SUB(NOW(), INTERVAL 7 DAY)
                ORDER BY reported_date DESC
                LIMIT ?";
        return $this->db->fetchAll($sql, [$limit]);
    }

    /**
     * Get recent alerts for activity feed
     */
 public function getRecentAlerts($limit = 10) {
        $sql = "SELECT id, title, type, severity, location_name, start_time, source_user_id
                FROM alerts
                WHERE is_active = 1
                AND start_time >= DATE_SUB(NOW(), INTERVAL 7 DAY)
                ORDER BY start_time DESC
                LIMIT ?";
        return $this->db->fetchAll($sql, [$limit]);
    }

    /**
     * Get recent user registrations
     */
   public function getRecentUsers($limit = 10) {
        $sql = "SELECT id, email, display_name, provider, created_at
                FROM users
                WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
                ORDER BY created_at DESC
                LIMIT ?";
        return $this->db->fetchAll($sql, [$limit]);
    }

    /**
     * Get recent disputes
     */
    public function getRecentDisputes($limit = 10) {
        $sql = "SELECT id, user_id, report_id, reason, status, created_at
                FROM disputes
                ORDER BY created_at DESC
                LIMIT ?";
        return $this->db->fetchAll($sql, [$limit]);
    }

    /**
     * Get active user sessions count
     */
    public function getActiveSessionsCount() {
        $sql = "SELECT COUNT(*) as count FROM user_sessions
                WHERE is_active = 1 AND last_activity > DATE_SUB(NOW(), INTERVAL 30 MINUTE)";
        $result = $this->db->fetchOne($sql);
        return $result['count'];
    }

    /**
     * Get user sessions by device type
     */
    public function getSessionsByDeviceType() {
        $sql = "SELECT device_type, COUNT(*) as count
                FROM user_sessions
                WHERE is_active = 1 AND last_activity > DATE_SUB(NOW(), INTERVAL 30 MINUTE)
                GROUP BY device_type";
        return $this->db->fetchAll($sql);
    }

    /**
     * Get recent user sessions
     */
    public function getRecentSessions($limit = 10) {
        $sql = "SELECT us.*, u.display_name, u.email
                FROM user_sessions us
                LEFT JOIN users u ON us.user_id = u.id
                WHERE us.is_active = 1
                ORDER BY us.last_activity DESC
                LIMIT ?";
        return $this->db->fetchAll($sql, [$limit]);
    }

    /**
     * Get system performance metrics
     */
    public function getSystemPerformanceMetrics() {
        $metrics = [];

        // Active users (last 30 minutes)
        $sql = "SELECT COUNT(*) as count FROM user_sessions
                WHERE is_active = 1 AND last_activity > DATE_SUB(NOW(), INTERVAL 30 MINUTE)";
        $result = $this->db->fetchOne($sql);
        $metrics['active_users'] = $result['count'];

        // Today's reports
        $sql = "SELECT COUNT(*) as count FROM incident_reports
                WHERE DATE(reported_date) = CURDATE()";
        $result = $this->db->fetchOne($sql);
        $metrics['today_reports'] = $result['count'];

        // Average response time (mock calculation - you can implement real calculation)
        $sql = "SELECT AVG(response_time_minutes) as avg_time FROM incident_reports
                WHERE response_time_minutes IS NOT NULL AND status = 'resolved'";
        $result = $this->db->fetchOne($sql);
        $metrics['avg_response_time'] = $result['avg_time'] ? round($result['avg_time'], 1) : 2.3;

        // Database health (connection test)
        $metrics['database_health'] = 100; // You can implement real health check

        // System uptime (mock - you can implement real uptime tracking)
        $metrics['uptime'] = '99.9%';

        // CPU and Memory usage (mock - you can implement real system monitoring)
        $metrics['cpu_usage'] = rand(20, 50);
        $metrics['memory_usage'] = rand(60, 80);
        $metrics['network_usage'] = rand(100, 150);

        return $metrics;
    }

    /**
     * Dispute Operations
     */
    public function createDispute($data) {
        $sql = "INSERT INTO disputes (user_id, report_id, reason, description)
                VALUES (?, ?, ?, ?)";
        return $this->db->insert($sql, [
            $data['user_id'],
            $data['report_id'],
            $data['reason'],
            $data['description']
        ]);
    }

    public function getUserDisputes($userId) {
        $sql = "SELECT * FROM disputes WHERE user_id = ? ORDER BY created_at DESC";
        return $this->db->fetchAll($sql, [$userId]);
    }

    public function getDisputableReports($userId) {
        // Get reports where the user might be mentioned or involved
        // This is a simplified version - you might want to implement more sophisticated logic
        $sql = "SELECT * FROM incident_reports
                WHERE (description LIKE ? OR title LIKE ?)
                AND user_id != ?
                AND status != 'disputed'
                ORDER BY reported_date DESC";
        $searchTerm = "%user%"; // You might want to search for the user's name or email
        return $this->db->fetchAll($sql, [$searchTerm, $searchTerm, $userId]);
    }

    public function getDisputeById($id) {
        $sql = "SELECT * FROM disputes WHERE id = ?";
        return $this->db->fetchOne($sql, [$id]);
    }

    public function updateDispute($id, $data) {
        $sql = "UPDATE disputes SET
                status = ?,
                review_notes = ?,
                reviewed_by = ?,
                reviewed_at = NOW()
                WHERE id = ?";
        return $this->db->update($sql, [
            $data['status'],
            $data['review_notes'] ?? null,
            $data['reviewed_by'] ?? null,
            $id
        ]);
    }

    /**
     * Legal Aid Provider Operations
     */
    public function getLegalAidProviders($filters = []) {
        $sql = "SELECT * FROM legal_aid_providers WHERE status = 'active'";
        $params = [];

        if (!empty($filters['city'])) {
            $sql .= " AND city = ?";
            $params[] = $filters['city'];
        }

        if (!empty($filters['district'])) {
            $sql .= " AND district = ?";
            $params[] = $filters['district'];
        }

        if (!empty($filters['specialization'])) {
            $sql .= " AND specialization LIKE ?";
            $params[] = '%' . $filters['specialization'] . '%';
        }

        if (!empty($filters['fee_structure'])) {
            $sql .= " AND fee_structure = ?";
            $params[] = $filters['fee_structure'];
        }

        if (!empty($filters['language'])) {
            $sql .= " AND language_support LIKE ?";
            $params[] = '%' . $filters['language'] . '%';
        }

        if (isset($filters['is_verified']) && $filters['is_verified'] !== '') {
            $sql .= " AND is_verified = ?";
            $params[] = $filters['is_verified'];
        }

        $sql .= " ORDER BY rating DESC, review_count DESC";

        if (!empty($filters['limit'])) {
            $sql .= " LIMIT ?";
            $params[] = (int)$filters['limit'];
        }

        return $this->db->fetchAll($sql, $params);
    }

    public function getLegalAidProviderById($id) {
        $sql = "SELECT * FROM legal_aid_providers WHERE id = ?";
        return $this->db->fetchOne($sql, [$id]);
    }

    public function searchLegalAidProviders($searchTerm) {
        $sql = "SELECT * FROM legal_aid_providers
                WHERE status = 'active'
                AND (organization_name LIKE ?
                     OR contact_person LIKE ?
                     OR specialization LIKE ?
                     OR address LIKE ?)
                ORDER BY rating DESC";
        $search = '%' . $searchTerm . '%';
        return $this->db->fetchAll($sql, [$search, $search, $search, $search]);
    }

    public function getLegalAidProviderSpecializations() {
        $sql = "SELECT DISTINCT specialization FROM legal_aid_providers WHERE status = 'active'";
        $results = $this->db->fetchAll($sql);
        $specializations = [];
        foreach ($results as $row) {
            $specs = explode(',', $row['specialization']);
            foreach ($specs as $spec) {
                $spec = trim($spec);
                if (!in_array($spec, $specializations)) {
                    $specializations[] = $spec;
                }
            }
        }
        return $specializations;
    }

    /**
     * Legal Consultation Operations
     */
    public function createLegalConsultation($data) {
        $sql = "INSERT INTO legal_consultations
                (user_id, report_id, provider_id, consultation_type, subject, description,
                 preferred_date, preferred_time, status, cost_bdt)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'requested', ?)";
        return $this->db->insert($sql, [
            $data['user_id'],
            $data['report_id'] ?? null,
            $data['provider_id'],
            $data['consultation_type'] ?? 'initial',
            $data['subject'],
            $data['description'],
            $data['preferred_date'] ?? null,
            $data['preferred_time'] ?? null,
            $data['cost_bdt'] ?? 0.00
        ]);
    }

    public function getUserConsultations($userId) {
        $sql = "SELECT lc.*, lap.organization_name, lap.phone, lap.email, lap.address
                FROM legal_consultations lc
                LEFT JOIN legal_aid_providers lap ON lc.provider_id = lap.id
                WHERE lc.user_id = ?
                ORDER BY lc.created_at DESC";
        return $this->db->fetchAll($sql, [$userId]);
    }

    public function getConsultationById($id) {
        $sql = "SELECT lc.*, lap.organization_name, lap.contact_person, lap.phone,
                       lap.email, lap.address, lap.city, lap.district, u.email as user_email, u.display_name
                FROM legal_consultations lc
                LEFT JOIN legal_aid_providers lap ON lc.provider_id = lap.id
                LEFT JOIN users u ON lc.user_id = u.id
                WHERE lc.id = ?";
        return $this->db->fetchOne($sql, [$id]);
    }

    public function updateConsultation($id, $data) {
        $updateFields = [];
        $params = [];

        if (isset($data['status'])) {
            $updateFields[] = "status = ?";
            $params[] = $data['status'];
        }

        if (isset($data['scheduled_at'])) {
            $updateFields[] = "scheduled_at = ?";
            $params[] = $data['scheduled_at'];
        }

        if (isset($data['provider_notes'])) {
            $updateFields[] = "provider_notes = ?";
            $params[] = $data['provider_notes'];
        }

        if (isset($data['user_feedback'])) {
            $updateFields[] = "user_feedback = ?";
            $params[] = $data['user_feedback'];
        }

        if (isset($data['rating'])) {
            $updateFields[] = "rating = ?";
            $params[] = $data['rating'];
        }

        if (isset($data['completed_at'])) {
            $updateFields[] = "completed_at = ?";
            $params[] = $data['completed_at'];
        }

        if (empty($updateFields)) {
            return false;
        }

        $params[] = $id;
        $sql = "UPDATE legal_consultations SET " . implode(", ", $updateFields) . " WHERE id = ?";
        $result = $this->db->update($sql, $params);

        // Update provider rating if rating was provided
        if ($result && isset($data['rating']) && $data['rating'] > 0) {
            $consultation = $this->getConsultationById($id);
            if ($consultation && $consultation['provider_id']) {
                $this->updateLegalProviderRating($consultation['provider_id']);
            }
        }

        return $result;
    }

    private function updateLegalProviderRating($providerId) {
        $sql = "SELECT AVG(rating) as avg_rating, COUNT(*) as review_count
                FROM legal_consultations
                WHERE provider_id = ? AND rating IS NOT NULL AND rating > 0";
        $result = $this->db->fetchOne($sql, [$providerId]);

        if ($result && $result['avg_rating']) {
            $updateSql = "UPDATE legal_aid_providers
                         SET rating = ?, review_count = ?
                         WHERE id = ?";
            $this->db->update($updateSql, [
                round($result['avg_rating'], 2),
                $result['review_count'],
                $providerId
            ]);
        }
    }

    public function getConsultationsByProvider($providerId, $filters = []) {
        $sql = "SELECT lc.*, u.display_name, u.email
                FROM legal_consultations lc
                LEFT JOIN users u ON lc.user_id = u.id
                WHERE lc.provider_id = ?";
        $params = [$providerId];

        if (!empty($filters['status'])) {
            $sql .= " AND lc.status = ?";
            $params[] = $filters['status'];
        }

        $sql .= " ORDER BY lc.created_at DESC";

        return $this->db->fetchAll($sql, $params);
    }

    /**
     * Legal Documents Operations
     */
    public function getLegalDocuments($filters = []) {
        $sql = "SELECT * FROM legal_documents WHERE status = 'active'";
        $params = [];

        if (!empty($filters['document_type'])) {
            $sql .= " AND document_type = ?";
            $params[] = $filters['document_type'];
        }

        if (!empty($filters['category'])) {
            $sql .= " AND category = ?";
            $params[] = $filters['category'];
        }

        if (!empty($filters['language'])) {
            $sql .= " AND (language = ? OR language = 'both')";
            $params[] = $filters['language'];
        }

        if (isset($filters['is_premium']) && $filters['is_premium'] !== '') {
            $sql .= " AND is_premium = ?";
            $params[] = $filters['is_premium'];
        }

        $sql .= " ORDER BY download_count DESC, created_at DESC";

        return $this->db->fetchAll($sql, $params);
    }

    public function getLegalDocumentById($id) {
        $sql = "SELECT * FROM legal_documents WHERE id = ?";
        return $this->db->fetchOne($sql, [$id]);
    }

    public function incrementDocumentDownload($id) {
        $sql = "UPDATE legal_documents SET download_count = download_count + 1 WHERE id = ?";
        return $this->db->update($sql, [$id]);
    }

    public function searchLegalDocuments($searchTerm) {
        $sql = "SELECT * FROM legal_documents
                WHERE status = 'active'
                AND (title LIKE ? OR description LIKE ? OR category LIKE ?)
                ORDER BY download_count DESC";
        $search = '%' . $searchTerm . '%';
        return $this->db->fetchAll($sql, [$search, $search, $search]);
    }

    /**
     * Community Groups Operations
     */
   public function getNeighborhoodGroups($filters = []) {
        // FIX: Changed alias 'div' to 'd' because 'div' is a reserved MySQL keyword
        $sql = "SELECT ng.*, u.display_name as creator_name,
                       d.name AS division,
                       dist.name AS district,
                       up.name AS upazila,
                       (SELECT COUNT(*) FROM group_members WHERE group_id = ng.id AND status = 'active') as member_count
                FROM neighborhood_groups ng
                LEFT JOIN users u ON ng.created_by = u.id
                LEFT JOIN divisions d ON ng.division_id = d.id
                LEFT JOIN districts dist ON ng.district_id = dist.id
                LEFT JOIN upazilas up ON ng.upazila_id = up.id
                WHERE 1=1";
        $params = [];

        // Status filter - show active by default, but allow showing all or user's pending groups
        if (!empty($filters['status'])) {
            if ($filters['status'] === 'all') {
                // Show all statuses
            } elseif ($filters['status'] === 'my_pending') {
                // Show user's pending groups
                if (!empty($filters['user_id'])) {
                    $sql .= " AND ng.created_by = ? AND ng.status = 'pending_approval'";
                    $params[] = $filters['user_id'];
                } else {
                    $sql .= " AND ng.status = 'pending_approval'";
                }
            } else {
                $sql .= " AND ng.status = ?";
                $params[] = $filters['status'];
            }
        } else {
            // Default: show active groups OR groups created by the user (even if pending)
            if (!empty($filters['user_id'])) {
                $sql .= " AND (ng.status = 'active' OR (ng.created_by = ? AND ng.status = 'pending_approval'))";
                $params[] = $filters['user_id'];
            } else {
                $sql .= " AND ng.status = 'active'";
            }
        }

        if (!empty($filters['division'])) {
            $sql .= " AND d.name = ?"; // Updated alias here
            $params[] = $filters['division'];
        }

        if (!empty($filters['district'])) {
            $sql .= " AND dist.name = ?";
            $params[] = $filters['district'];
        }

        if (!empty($filters['upazila'])) {
            $sql .= " AND up.name = ?";
            $params[] = $filters['upazila'];
        }

        if (!empty($filters['area_name'])) {
            $sql .= " AND ng.area_name LIKE ?";
            $params[] = '%' . $filters['area_name'] . '%';
        }

        if (isset($filters['is_verified']) && $filters['is_verified'] !== '') {
            $sql .= " AND ng.is_verified = ?";
            $params[] = $filters['is_verified'];
        }

        if (!empty($filters['privacy_level'])) {
            $sql .= " AND ng.privacy_level = ?";
            $params[] = $filters['privacy_level'];
        }

        $sql .= " ORDER BY ng.is_verified DESC, ng.member_count DESC, ng.created_at DESC";

        if (!empty($filters['limit'])) {
            $sql .= " LIMIT ?";
            $params[] = $filters['limit'];
        }

        return $this->db->fetchAll($sql, $params);
    }

  public function getNeighborhoodGroupById($id) {
        // FIX: Changed alias 'div' to 'd'
        $sql = "SELECT ng.*, u.display_name as creator_name, u.email as creator_email,
                       d.name AS division,
                       dist.name AS district,
                       up.name AS upazila
                FROM neighborhood_groups ng
                LEFT JOIN users u ON ng.created_by = u.id
                LEFT JOIN divisions d ON ng.division_id = d.id
                LEFT JOIN districts dist ON ng.district_id = dist.id
                LEFT JOIN upazilas up ON ng.upazila_id = up.id
                WHERE ng.id = ?";
        return $this->db->fetchOne($sql, [$id]);
    }

    public function createNeighborhoodGroup($data) {
        $divisionId = $data['division_id'] ?? null;
        if (!$divisionId && !empty($data['division'])) {
            $divisionId = $this->resolveDivisionId($data['division'], true);
        }

        $districtId = $data['district_id'] ?? $this->resolveDistrictId($data['district'] ?? null, $divisionId, true);
        if (!$districtId) {
            throw new Exception('District is required to create a community group.');
        }

        if (!$divisionId) {
            $divisionRow = $this->db->fetchOne("SELECT division_id FROM districts WHERE id = ? LIMIT 1", [$districtId]);
            $divisionId = $divisionRow ? (int)$divisionRow['division_id'] : null;
        }

        if (!$divisionId) {
            $divisionId = $this->resolveDivisionId('Unknown', true);
        }

        $upazilaId = $data['upazila_id'] ?? null;
        if (!$upazilaId && !empty($data['upazila'])) {
            $upazilaId = $this->resolveUpazilaId($data['upazila'], $districtId, true);
        }

        $sql = "INSERT INTO neighborhood_groups
                (group_name, description, area_name, ward_number, union_name, division_id, district_id, upazila_id,
                 created_by, privacy_level, rules, status)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending_approval')";
        $groupId = $this->db->insert($sql, [
            $data['group_name'],
            $data['description'] ?? null,
            $data['area_name'],
            $data['ward_number'] ?? null,
            $data['union_name'] ?? null,
            $divisionId,
            $districtId,
            $upazilaId,
            $data['created_by'],
            $data['privacy_level'] ?? 'public',
            $data['rules'] ?? null
        ]);

        // Add creator as founder
        if ($groupId) {
            $this->addGroupMember($groupId, $data['created_by'], 'founder');
        }

        return $groupId;
    }

    public function addGroupMember($groupId, $userId, $role = 'member') {
        $sql = "INSERT INTO group_members (group_id, user_id, role, status)
                VALUES (?, ?, ?, 'active')
                ON DUPLICATE KEY UPDATE status = 'active', role = ?";
        $result = $this->db->insert($sql, [$groupId, $userId, $role, $role]);

        // Update member count
        $this->updateGroupMemberCount($groupId);

        return $result;
    }

    public function removeGroupMember($groupId, $userId) {
        $sql = "UPDATE group_members SET status = 'inactive' WHERE group_id = ? AND user_id = ?";
        $result = $this->db->update($sql, [$groupId, $userId]);

        // Update member count
        $this->updateGroupMemberCount($groupId);

        return $result;
    }

   public function updateGroupMemberCount($groupId) {
        $sql = "UPDATE neighborhood_groups SET
                member_count = (SELECT COUNT(*) FROM group_members WHERE group_id = ? AND status = 'active'),
                active_members = (SELECT COUNT(*) FROM group_members WHERE group_id = ? AND status = 'active')
                WHERE id = ?";
        return $this->db->update($sql, [$groupId, $groupId, $groupId]);
    }

   public function getUserGroups($userId) {
        // FIX: Changed alias 'div' to 'd' to avoid MySQL reserved keyword conflict
        $sql = "SELECT ng.*, gm.role, gm.contribution_score, gm.joined_at,
                       d.name AS division,
                       dist.name AS district,
                       up.name AS upazila
                FROM neighborhood_groups ng
                INNER JOIN group_members gm ON ng.id = gm.group_id
                LEFT JOIN divisions d ON ng.division_id = d.id
                LEFT JOIN districts dist ON ng.district_id = dist.id
                LEFT JOIN upazilas up ON ng.upazila_id = up.id
                WHERE gm.user_id = ? AND gm.status = 'active' AND ng.status = 'active'
                ORDER BY gm.joined_at DESC";
        return $this->db->fetchAll($sql, [$userId]);
    }

    public function isGroupMember($groupId, $userId) {
        $sql = "SELECT * FROM group_members WHERE group_id = ? AND user_id = ? AND status = 'active'";
        return $this->db->fetchOne($sql, [$groupId, $userId]);
    }

    public function getGroupMembers($groupId) {
        $sql = "SELECT gm.*, u.display_name, u.email, u.phone
                FROM group_members gm
                LEFT JOIN users u ON gm.user_id = u.id
                WHERE gm.group_id = ? AND gm.status = 'active'
                ORDER BY
                    CASE gm.role
                        WHEN 'founder' THEN 1
                        WHEN 'admin' THEN 2
                        WHEN 'moderator' THEN 3
                        ELSE 4
                    END,
                    gm.contribution_score DESC";
        return $this->db->fetchAll($sql, [$groupId]);
    }

    public function createGroupAlert($data) {
        $sql = "INSERT INTO group_alerts
                (group_id, posted_by, alert_type, title, message, location_details, severity, expires_at, status)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'active')";
        $alertId = $this->db->insert($sql, [
            $data['group_id'],
            $data['posted_by'],
            $data['alert_type'] ?? 'general',
            $data['title'],
            $data['message'],
            $data['location_details'] ?? null,
            $data['severity'] ?? 'medium',
            $data['expires_at'] ?? null
        ]);

        // Increment contribution score for poster
        if ($alertId) {
            $this->incrementMemberContribution($data['group_id'], $data['posted_by'], 5);
        }

        return $alertId;
    }

    public function getGroupAlerts($groupId, $filters = []) {
        $sql = "SELECT ga.*, u.display_name as poster_name, u.email as poster_email
                FROM group_alerts ga
                LEFT JOIN users u ON ga.posted_by = u.id
                WHERE ga.group_id = ?";
        $params = [$groupId];

        if (!empty($filters['alert_type'])) {
            $sql .= " AND ga.alert_type = ?";
            $params[] = $filters['alert_type'];
        }

        if (!empty($filters['severity'])) {
            $sql .= " AND ga.severity = ?";
            $params[] = $filters['severity'];
        }

        if (!empty($filters['status'])) {
            $sql .= " AND ga.status = ?";
            $params[] = $filters['status'];
        } else {
            $sql .= " AND ga.status = 'active'";
        }

        $sql .= " ORDER BY
                    CASE ga.severity
                        WHEN 'critical' THEN 1
                        WHEN 'high' THEN 2
                        WHEN 'medium' THEN 3
                        ELSE 4
                    END,
                    ga.created_at DESC";

        if (!empty($filters['limit'])) {
            $sql .= " LIMIT ?";
            $params[] = $filters['limit'];
        }

        return $this->db->fetchAll($sql, $params);
    }

    public function getGroupAlertById($id) {
        $sql = "SELECT ga.*, u.display_name as poster_name, u.email as poster_email,
                       ng.group_name, ng.area_name
                FROM group_alerts ga
                LEFT JOIN users u ON ga.posted_by = u.id
                LEFT JOIN neighborhood_groups ng ON ga.group_id = ng.id
                WHERE ga.id = ?";
        return $this->db->fetchOne($sql, [$id]);
    }

    public function acknowledgeAlert($alertId, $userId) {
        // Check if already acknowledged
        $sql = "SELECT * FROM group_alert_acknowledgments WHERE alert_id = ? AND user_id = ?";
        $existing = $this->db->fetchOne($sql, [$alertId, $userId]);

        if (!$existing) {
            $sql = "INSERT INTO group_alert_acknowledgments (alert_id, user_id) VALUES (?, ?)";
            $this->db->insert($sql, [$alertId, $userId]);

            // Update acknowledgment count
            $sql = "UPDATE group_alerts SET acknowledgments = acknowledgments + 1 WHERE id = ?";
            $this->db->update($sql, [$alertId]);
        }
    }

    public function incrementMemberContribution($groupId, $userId, $points) {
        $sql = "UPDATE group_members SET contribution_score = contribution_score + ?
                WHERE group_id = ? AND user_id = ?";
        return $this->db->update($sql, [$points, $groupId, $userId]);
    }

    public function updateGroupAlert($id, $data) {
        $updateFields = [];
        $params = [];

        if (isset($data['status'])) {
            $updateFields[] = "status = ?";
            $params[] = $data['status'];
        }

        if (isset($data['is_verified'])) {
            $updateFields[] = "is_verified = ?";
            $params[] = $data['is_verified'];
            if ($data['is_verified']) {
                $updateFields[] = "verified_by = ?";
                $params[] = $data['verified_by'] ?? null;
            }
        }

        if (empty($updateFields)) {
            return false;
        }

        $params[] = $id;
        $sql = "UPDATE group_alerts SET " . implode(", ", $updateFields) . " WHERE id = ?";
        return $this->db->update($sql, $params);
    }

public function searchNeighborhoodGroups($searchTerm) {
        // FIX: Changed alias 'div' to 'd'
        $sql = "SELECT ng.*, u.display_name as creator_name,
                       d.name AS division,
                       dist.name AS district,
                       up.name AS upazila
                FROM neighborhood_groups ng
                LEFT JOIN users u ON ng.created_by = u.id
                LEFT JOIN divisions d ON ng.division_id = d.id
                LEFT JOIN districts dist ON ng.district_id = dist.id
                LEFT JOIN upazilas up ON ng.upazila_id = up.id
                WHERE ng.status = 'active'
                AND (ng.group_name LIKE ?
                     OR ng.area_name LIKE ?
                     OR ng.description LIKE ?
                     OR dist.name LIKE ?)
                ORDER BY ng.is_verified DESC, ng.member_count DESC";
        $search = '%' . $searchTerm . '%';
        return $this->db->fetchAll($sql, [$search, $search, $search, $search]);
    }

public function getMissingPersonAlerts($filters = []) {
        // FIX: Changed alias 'div' to 'd'
        $sql = "SELECT ga.*, ng.group_name, ng.area_name, u.display_name as poster_name,
                       dist.name AS district,
                       up.name AS upazila,
                       d.name AS division
                FROM group_alerts ga
                LEFT JOIN neighborhood_groups ng ON ga.group_id = ng.id
                LEFT JOIN districts dist ON ng.district_id = dist.id
                LEFT JOIN upazilas up ON ng.upazila_id = up.id
                LEFT JOIN divisions d ON ng.division_id = d.id
                LEFT JOIN users u ON ga.posted_by = u.id
                WHERE ga.alert_type = 'missing_person' AND ga.status = 'active'";
        $params = [];

        if (!empty($filters['district'])) {
            $sql .= " AND dist.name = ?";
            $params[] = $filters['district'];
        }

        $sql .= " ORDER BY ga.created_at DESC";

        if (!empty($filters['limit'])) {
            $sql .= " LIMIT ?";
            $params[] = (int)$filters['limit'];
        }

        return $this->db->fetchAll($sql, $params);
    }


    /**
     * Group Media Operations
     */
    public function createGroupMedia($data) {
        $sql = "INSERT INTO group_media
                (group_id, alert_id, uploaded_by, file_name, file_path, file_type, file_size_bytes,
                 mime_type, thumbnail_path, description, is_public)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
        $mediaId = $this->db->insert($sql, [
            $data['group_id'],
            $data['alert_id'] ?? null,
            $data['uploaded_by'],
            $data['file_name'],
            $data['file_path'],
            $data['file_type'],
            $data['file_size_bytes'] ?? null,
            $data['mime_type'] ?? null,
            $data['thumbnail_path'] ?? null,
            $data['description'] ?? null,
            $data['is_public'] ?? 1
        ]);

        // Increment contribution score for uploading media
        if ($mediaId) {
            $this->incrementMemberContribution($data['group_id'], $data['uploaded_by'], 3);
        }

        return $mediaId;
    }

    public function getGroupMedia($groupId, $filters = []) {
        $sql = "SELECT gm.*, u.display_name as uploader_name, u.email as uploader_email
                FROM group_media gm
                LEFT JOIN users u ON gm.uploaded_by = u.id
                WHERE gm.group_id = ? AND gm.status = 'active'";
        $params = [$groupId];

        if (!empty($filters['alert_id'])) {
            $sql .= " AND gm.alert_id = ?";
            $params[] = $filters['alert_id'];
        }

        if (!empty($filters['file_type'])) {
            $sql .= " AND gm.file_type = ?";
            $params[] = $filters['file_type'];
        }

        if (isset($filters['is_public']) && $filters['is_public'] !== '') {
            $sql .= " AND gm.is_public = ?";
            $params[] = $filters['is_public'];
        }

        $sql .= " ORDER BY gm.created_at DESC";

        if (!empty($filters['limit'])) {
            $sql .= " LIMIT ?";
            $params[] = $filters['limit'];
        }

        return $this->db->fetchAll($sql, $params);
    }

    public function getGroupMediaById($id) {
        $sql = "SELECT gm.*, u.display_name as uploader_name, ng.group_name
                FROM group_media gm
                LEFT JOIN users u ON gm.uploaded_by = u.id
                LEFT JOIN neighborhood_groups ng ON gm.group_id = ng.id
                WHERE gm.id = ?";
        return $this->db->fetchOne($sql, [$id]);
    }

    public function incrementMediaViews($mediaId) {
        $sql = "UPDATE group_media SET views_count = views_count + 1 WHERE id = ?";
        return $this->db->update($sql, [$mediaId]);
    }

    public function incrementMediaDownloads($mediaId) {
        $sql = "UPDATE group_media SET download_count = download_count + 1 WHERE id = ?";
        return $this->db->update($sql, [$mediaId]);
    }

    public function deleteGroupMedia($mediaId, $userId) {
        // Check if user is the uploader or has admin/moderator role
        $media = $this->getGroupMediaById($mediaId);
        if (!$media) {
            return false;
        }

        $isUploader = $media['uploaded_by'] == $userId;
        $memberInfo = $this->isGroupMember($media['group_id'], $userId);
        $isAdmin = $memberInfo && in_array($memberInfo['role'], ['admin', 'moderator', 'founder']);

        if (!$isUploader && !$isAdmin) {
            return false;
        }

        $sql = "UPDATE group_media SET status = 'deleted' WHERE id = ?";
        return $this->db->update($sql, [$mediaId]);
    }

    public function getAlertMedia($alertId) {
        $sql = "SELECT gm.*, u.display_name as uploader_name
                FROM group_media gm
                LEFT JOIN users u ON gm.uploaded_by = u.id
                WHERE gm.alert_id = ? AND gm.status = 'active'
                ORDER BY gm.created_at DESC";
        return $this->db->fetchAll($sql, [$alertId]);
    }

    /**
     * Medical Support Provider Operations
     */
    public function getMedicalProviders($filters = []) {
        $sql = "SELECT * FROM medical_support_providers WHERE status = 'active'";
        $params = [];

        if (!empty($filters['provider_type'])) {
            $sql .= " AND provider_type = ?";
            $params[] = $filters['provider_type'];
        }

        if (!empty($filters['district'])) {
            $sql .= " AND district = ?";
            $params[] = $filters['district'];
        }

        if (!empty($filters['city'])) {
            $sql .= " AND city = ?";
            $params[] = $filters['city'];
        }

        if (!empty($filters['specialization'])) {
            $sql .= " AND specialization LIKE ?";
            $params[] = '%' . $filters['specialization'] . '%';
        }

        if (!empty($filters['language'])) {
            $sql .= " AND languages LIKE ?";
            $params[] = '%' . $filters['language'] . '%';
        }

        if (!empty($filters['fee_structure'])) {
            $sql .= " AND fee_structure = ?";
            $params[] = $filters['fee_structure'];
        }

        if (isset($filters['is_24_7']) && $filters['is_24_7'] !== '') {
            $sql .= " AND is_24_7 = ?";
            $params[] = $filters['is_24_7'];
        }

        if (isset($filters['accepts_insurance']) && $filters['accepts_insurance'] !== '') {
            $sql .= " AND accepts_insurance = ?";
            $params[] = $filters['accepts_insurance'];
        }

        if (isset($filters['is_verified']) && $filters['is_verified'] !== '') {
            $sql .= " AND is_verified = ?";
            $params[] = $filters['is_verified'];
        }

        $sql .= " ORDER BY is_verified DESC, rating DESC, review_count DESC";

        if (!empty($filters['limit'])) {
            $sql .= " LIMIT ?";
            $params[] = $filters['limit'];
        }

        return $this->db->fetchAll($sql, $params);
    }

    public function getMedicalProviderById($id) {
        $sql = "SELECT * FROM medical_support_providers WHERE id = ?";
        return $this->db->fetchOne($sql, [$id]);
    }

    public function searchMedicalProviders($searchTerm) {
        $sql = "SELECT * FROM medical_support_providers
                WHERE status = 'active'
                AND (provider_name LIKE ?
                     OR address LIKE ?
                     OR specialization LIKE ?
                     OR city LIKE ?
                     OR district LIKE ?)
                ORDER BY rating DESC, review_count DESC";
        $search = '%' . $searchTerm . '%';
        return $this->db->fetchAll($sql, [$search, $search, $search, $search, $search]);
    }

    public function getProviderSpecializations() {
        $sql = "SELECT DISTINCT specialization FROM medical_support_providers WHERE status = 'active'";
        $results = $this->db->fetchAll($sql);
        $specializations = [];
        foreach ($results as $row) {
            if ($row['specialization']) {
                $specs = explode(',', $row['specialization']);
                foreach ($specs as $spec) {
                    $spec = trim($spec);
                    if (!empty($spec) && !in_array($spec, $specializations)) {
                        $specializations[] = $spec;
                    }
                }
            }
        }
        return $specializations;
    }

    /**
     * Support Referral Operations
     */
    public function createSupportReferral($data) {
        $sql = "INSERT INTO support_referrals
                (user_id, report_id, provider_id, referral_type, priority, reason, status, is_anonymous, appointment_date)
                VALUES (?, ?, ?, ?, ?, ?, 'pending', ?, ?)";
        return $this->db->insert($sql, [
            $data['user_id'],
            $data['report_id'] ?? null,
            $data['provider_id'],
            $data['referral_type'],
            $data['priority'] ?? 'medium',
            $data['reason'] ?? null,
            $data['is_anonymous'] ?? 0,
            $data['appointment_date'] ?? null
        ]);
    }

    public function getUserReferrals($userId) {
        $sql = "SELECT sr.*, msp.provider_name, msp.provider_type, msp.phone, msp.email, msp.address, msp.city, msp.district
                FROM support_referrals sr
                LEFT JOIN medical_support_providers msp ON sr.provider_id = msp.id
                WHERE sr.user_id = ?
                ORDER BY sr.referred_at DESC";
        return $this->db->fetchAll($sql, [$userId]);
    }

    public function getReferralById($id) {
        $sql = "SELECT sr.*, msp.provider_name, msp.provider_type, msp.phone, msp.email, msp.address,
                       msp.city, msp.district, msp.website, u.email as user_email, u.display_name
                FROM support_referrals sr
                LEFT JOIN medical_support_providers msp ON sr.provider_id = msp.id
                LEFT JOIN users u ON sr.user_id = u.id
                WHERE sr.id = ?";
        return $this->db->fetchOne($sql, [$id]);
    }

    public function updateReferral($id, $data) {
        $updateFields = [];
        $params = [];

        if (isset($data['status'])) {
            $updateFields[] = "status = ?";
            $params[] = $data['status'];
        }

        if (isset($data['appointment_date'])) {
            $updateFields[] = "appointment_date = ?";
            $params[] = $data['appointment_date'];
        }

        if (isset($data['provider_notes'])) {
            $updateFields[] = "provider_notes = ?";
            $params[] = $data['provider_notes'];
        }

        if (isset($data['user_feedback'])) {
            $updateFields[] = "user_feedback = ?";
            $params[] = $data['user_feedback'];
        }

        if (isset($data['rating'])) {
            $updateFields[] = "rating = ?";
            $params[] = $data['rating'];
        }

        if (isset($data['completed_at'])) {
            $updateFields[] = "completed_at = ?";
            $params[] = $data['completed_at'];
        }

        if (empty($updateFields)) {
            return false;
        }

        $params[] = $id;
        $sql = "UPDATE support_referrals SET " . implode(", ", $updateFields) . " WHERE id = ?";
        $result = $this->db->update($sql, $params);

        // Update provider rating if user provided a rating
        if ($result && isset($data['rating']) && $data['rating'] > 0) {
            $referral = $this->getReferralById($id);
            if ($referral) {
                $this->updateProviderRating($referral['provider_id']);
            }
        }

        return $result;
    }

    private function updateProviderRating($providerId) {
        $sql = "SELECT AVG(rating) as avg_rating, COUNT(*) as review_count
                FROM support_referrals
                WHERE provider_id = ? AND rating IS NOT NULL AND rating > 0";
        $result = $this->db->fetchOne($sql, [$providerId]);

        if ($result && $result['avg_rating']) {
            $updateSql = "UPDATE medical_support_providers
                         SET rating = ?, review_count = ?
                         WHERE id = ?";
            $this->db->update($updateSql, [
                round($result['avg_rating'], 2),
                $result['review_count'],
                $providerId
            ]);
        }
    }

    public function getReferralsByProvider($providerId, $filters = []) {
        $sql = "SELECT sr.*, u.display_name, u.email as user_email
                FROM support_referrals sr
                LEFT JOIN users u ON sr.user_id = u.id
                WHERE sr.provider_id = ?";
        $params = [$providerId];

        if (!empty($filters['status'])) {
            $sql .= " AND sr.status = ?";
            $params[] = $filters['status'];
        }

        $sql .= " ORDER BY sr.referred_at DESC";

        return $this->db->fetchAll($sql, $params);
    }

    public function getReferralsByReport($reportId) {
        $sql = "SELECT sr.*, msp.provider_name, msp.provider_type
                FROM support_referrals sr
                LEFT JOIN medical_support_providers msp ON sr.provider_id = msp.id
                WHERE sr.report_id = ?
                ORDER BY sr.referred_at DESC";
        return $this->db->fetchAll($sql, [$reportId]);
    }

    /**
     * Administrative region helpers
     */
    public function getDivisionsList() {
        return $this->db->fetchAll("SELECT id, name, code FROM divisions ORDER BY name ASC");
    }

    public function getDistrictsList($divisionId = null) {
        if ($divisionId) {
            return $this->db->fetchAll(
                "SELECT id, name, division_id FROM districts WHERE division_id = ? ORDER BY name ASC",
                [$divisionId]
            );
        }

        return $this->db->fetchAll("SELECT id, name, division_id FROM districts ORDER BY name ASC");
    }

    public function getUpazilasList($districtId = null) {
        if ($districtId) {
            return $this->db->fetchAll(
                "SELECT id, name, district_id FROM upazilas WHERE district_id = ? ORDER BY name ASC",
                [$districtId]
            );
        }

        return $this->db->fetchAll("SELECT id, name, district_id FROM upazilas ORDER BY name ASC");
    }

    private function normalizeRegionName($name) {
        if ($name === null) {
            return null;
        }
        $trimmed = trim($name);
        if ($trimmed === '') {
            return null;
        }
        return ucwords(strtolower($trimmed));
    }

    private function resolveDivisionId($name, $createIfMissing = false) {
        $normalized = $this->normalizeRegionName($name);
        if (!$normalized) {
            return null;
        }

        $existing = $this->db->fetchOne(
            "SELECT id FROM divisions WHERE LOWER(name) = LOWER(?) LIMIT 1",
            [$normalized]
        );

        if ($existing) {
            return (int)$existing['id'];
        }

        if (!$createIfMissing) {
            return null;
        }

        return $this->db->insert(
            "INSERT INTO divisions (name, code) VALUES (?, NULL)",
            [$normalized]
        );
    }

    private function resolveDistrictId($name, $divisionId = null, $createIfMissing = false) {
        $normalized = $this->normalizeRegionName($name);
        if (!$normalized) {
            return null;
        }

        $params = [$normalized];
        $sql = "SELECT id, division_id FROM districts WHERE LOWER(name) = LOWER(?)";
        if ($divisionId) {
            $sql .= " AND division_id = ?";
            $params[] = $divisionId;
        }
        $sql .= " LIMIT 1";

        $existing = $this->db->fetchOne($sql, $params);
        if ($existing) {
            return (int)$existing['id'];
        }

        if (!$createIfMissing) {
            return null;
        }

        if (!$divisionId) {
            $divisionId = $this->resolveDivisionId('Unknown', true);
        }

        return $this->db->insert(
            "INSERT INTO districts (division_id, name) VALUES (?, ?)",
            [$divisionId, $normalized]
        );
    }

    private function resolveUpazilaId($name, $districtId = null, $createIfMissing = false) {
        $normalized = $this->normalizeRegionName($name);
        if (!$normalized) {
            return null;
        }

        $params = [$normalized];
        $sql = "SELECT id, district_id FROM upazilas WHERE LOWER(name) = LOWER(?)";
        if ($districtId) {
            $sql .= " AND district_id = ?";
            $params[] = $districtId;
        }
        $sql .= " LIMIT 1";

        $existing = $this->db->fetchOne($sql, $params);
        if ($existing) {
            return (int)$existing['id'];
        }

        if (!$createIfMissing) {
            return null;
        }

        if (!$districtId) {
            $districtId = $this->resolveDistrictId('Unknown', null, true);
        }

        return $this->db->insert(
            "INSERT INTO upazilas (district_id, name) VALUES (?, ?)",
            [$districtId, $normalized]
        );
    }

    /**
     * Area Safety Score Operations
     */
    public function getAreaSafetyScores($filters = []) {
        $sql = "SELECT ass.*,
                       d.name AS division,
                       dist.name AS district,
                       up.name AS upazila,
                       (SELECT COUNT(*) FROM user_area_ratings WHERE area_id = ass.id) as rating_count,
                       (SELECT AVG(safety_rating) FROM user_area_ratings WHERE area_id = ass.id) as user_rating_avg
                FROM area_safety_scores ass
                LEFT JOIN divisions d ON ass.division_id = d.id
                LEFT JOIN districts dist ON ass.district_id = dist.id
                LEFT JOIN upazilas up ON ass.upazila_id = up.id
                WHERE 1=1";
        $params = [];

        if (!empty($filters['district'])) {
            $sql .= " AND dist.name = ?";
            $params[] = $filters['district'];
        }

        if (!empty($filters['upazila'])) {
            $sql .= " AND up.name = ?";
            $params[] = $filters['upazila'];
        }

        if (!empty($filters['division'])) {
            $sql .= " AND d.name = ?";
            $params[] = $filters['division'];
        }

        if (!empty($filters['min_score'])) {
            $sql .= " AND ass.safety_score >= ?";
            $params[] = $filters['min_score'];
        }

        if (!empty($filters['max_score'])) {
            $sql .= " AND ass.safety_score <= ?";
            $params[] = $filters['max_score'];
        }

        $sql .= " ORDER BY ass.safety_score DESC, ass.total_incidents ASC";

        if (!empty($filters['limit'])) {
            $sql .= " LIMIT ?";
            $params[] = (int)$filters['limit'];
        }

        return $this->db->fetchAll($sql, $params);
    }

    public function getAreaSafetyScoreById($id) {
        $sql = "SELECT ass.*,
                       d.name AS division,
                       dist.name AS district,
                       up.name AS upazila,
                       (SELECT COUNT(*) FROM user_area_ratings WHERE area_id = ass.id) as rating_count,
                       (SELECT AVG(safety_rating) FROM user_area_ratings WHERE area_id = ass.id) as user_rating_avg
                FROM area_safety_scores ass
                LEFT JOIN divisions d ON ass.division_id = d.id
                LEFT JOIN districts dist ON ass.district_id = dist.id
                LEFT JOIN upazilas up ON ass.upazila_id = up.id
                WHERE ass.id = ?";
        return $this->db->fetchOne($sql, [$id]);
    }

    public function getAreaByLocation($district, $upazila = null, $unionName = null, $wardNumber = null) {
        $districtId = is_numeric($district)
            ? (int)$district
            : $this->resolveDistrictId($district, null, false);

        if (!$districtId) {
            return null;
        }

        $sql = "SELECT ass.*,
                       d.name AS division,
                       dist.name AS district,
                       up.name AS upazila
                FROM area_safety_scores ass
                LEFT JOIN divisions d ON ass.division_id = d.id
                LEFT JOIN districts dist ON ass.district_id = dist.id
                LEFT JOIN upazilas up ON ass.upazila_id = up.id
                WHERE ass.district_id = ?";
        $params = [$districtId];

        if ($upazila) {
            $upazilaId = is_numeric($upazila)
                ? (int)$upazila
                : $this->resolveUpazilaId($upazila, $districtId, false);

            if (!$upazilaId) {
                return null;
            }

            $sql .= " AND ass.upazila_id = ?";
            $params[] = $upazilaId;
        }

        if ($unionName) {
            $sql .= " AND ass.union_name = ?";
            $params[] = $unionName;
        }

        if ($wardNumber) {
            $sql .= " AND ass.ward_number = ?";
            $params[] = $wardNumber;
        }

        $sql .= " LIMIT 1";

        return $this->db->fetchOne($sql, $params);
    }

    public function createOrUpdateAreaSafetyScore($data) {
        $divisionId = $data['division_id'] ?? null;
        $districtId = $data['district_id'] ?? null;
        $upazilaId = $data['upazila_id'] ?? null;

        if (!$divisionId && !empty($data['division'])) {
            $divisionId = $this->resolveDivisionId($data['division'], true);
        }

        if (!$districtId) {
            $districtId = $this->resolveDistrictId($data['district'] ?? null, $divisionId, true);
        }

        if (!$districtId) {
            throw new Exception('District information is required for area safety scores.');
        }

        if (!$divisionId) {
            $divisionRow = $this->db->fetchOne("SELECT division_id FROM districts WHERE id = ? LIMIT 1", [$districtId]);
            if ($divisionRow) {
                $divisionId = (int)$divisionRow['division_id'];
            }
        }

        if (!$upazilaId && !empty($data['upazila'])) {
            $upazilaId = $this->resolveUpazilaId($data['upazila'], $districtId, true);
        }

        $data['division_id'] = $divisionId;
        $data['district_id'] = $districtId;
        $data['upazila_id'] = $upazilaId;

        if (!empty($data['id'])) {
            $existing = $this->getAreaSafetyScoreById($data['id']);
        } else {
            $sql = "SELECT * FROM area_safety_scores WHERE district_id = ?";
            $params = [$districtId];

            if ($upazilaId) {
                $sql .= " AND upazila_id = ?";
                $params[] = $upazilaId;
            }

            if (!empty($data['union_name'])) {
                $sql .= " AND union_name = ?";
                $params[] = $data['union_name'];
            }

            if (!empty($data['ward_number'])) {
                $sql .= " AND ward_number = ?";
                $params[] = $data['ward_number'];
            }

            $sql .= " LIMIT 1";
            $existing = $this->db->fetchOne($sql, $params);
        }

        $metricColumns = [
            'safety_score',
            'incident_rate_score',
            'resolution_rate_score',
            'response_time_score',
            'user_ratings_score',
            'critical_incidents_score',
            'total_incidents',
            'resolved_incidents',
            'critical_incidents',
            'response_time_avg_hours',
            'area_name',
            'ward_number',
            'union_name',
            'division_id',
            'district_id',
            'upazila_id'
        ];

        if ($existing) {
            $updateFields = [];
            $params = [];

            foreach ($metricColumns as $column) {
                if (array_key_exists($column, $data)) {
                    $updateFields[] = "$column = ?";
                    $params[] = $data[$column];
                }
            }

            if (empty($updateFields)) {
                return true;
            }

            $updateFields[] = "last_updated = NOW()";
            $params[] = $existing['id'];

            $sql = "UPDATE area_safety_scores SET " . implode(", ", $updateFields) . " WHERE id = ?";
            return $this->db->update($sql, $params);
        }

        $sql = "INSERT INTO area_safety_scores
                (area_name, ward_number, union_name, division_id, district_id, upazila_id,
                 safety_score, incident_rate_score, resolution_rate_score, response_time_score,
                 user_ratings_score, critical_incidents_score, total_incidents, resolved_incidents,
                 critical_incidents, response_time_avg_hours, created_at, last_updated)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())";

        return $this->db->insert($sql, [
            $data['area_name'] ?? ($data['upazila'] ?? $data['district'] ?? 'Unknown Area'),
            $data['ward_number'] ?? null,
            $data['union_name'] ?? null,
            $divisionId,
            $districtId,
            $upazilaId,
            $data['safety_score'] ?? 0.00,
            $data['incident_rate_score'] ?? null,
            $data['resolution_rate_score'] ?? null,
            $data['response_time_score'] ?? null,
            $data['user_ratings_score'] ?? null,
            $data['critical_incidents_score'] ?? null,
            $data['total_incidents'] ?? 0,
            $data['resolved_incidents'] ?? 0,
            $data['critical_incidents'] ?? 0,
            $data['response_time_avg_hours'] ?? 0.00
        ]);
    }

    /**
     * Extract district and upazila from address or location name
     */
    private function extractLocationFromAddress($address, $locationName = null) {
        $text = ($address ?? '') . ' ' . ($locationName ?? '');
        $text = strtolower(trim($text));

        // Common districts in Bangladesh
        $districts = ['dhaka', 'chittagong', 'sylhet', 'rajshahi', 'khulna', 'barisal', 'rangpur', 'mymensingh'];
        $foundDistrict = null;
        $foundUpazila = null;

        foreach ($districts as $district) {
            if (stripos($text, $district) !== false) {
                $foundDistrict = ucfirst($district);
                break;
            }
        }

        // Common upazilas/areas in Dhaka
        $dhakaAreas = ['dhanmondi', 'gulshan', 'uttara', 'banani', 'mirpur', 'wari', 'motijheel', 'tejgaon', 'ramna', 'lalbagh'];
        if ($foundDistrict === 'Dhaka' || stripos($text, 'dhaka') !== false) {
            foreach ($dhakaAreas as $area) {
                if (stripos($text, $area) !== false) {
                    $foundUpazila = ucfirst($area);
                    break;
                }
            }
        }

        return ['district' => $foundDistrict, 'upazila' => $foundUpazila];
    }

    /**
     * Update area safety score when an incident is created or updated
     */
    private function updateAreaSafetyScoreFromIncident($incidentData) {
        $location = $this->extractLocationFromAddress($incidentData['address'] ?? null, $incidentData['location_name'] ?? null);

        if ($location['district']) {
            // Find or create area record
            $area = $this->getAreaByLocation($location['district'], $location['upazila']);

            if ($area) {
                // Recalculate score for this area
                $this->calculateAreaSafetyScore($area['id']);
            } else {
                // Create new area record if it doesn't exist
                $areaName = $location['upazila'] ?? $location['district'];
                $this->createOrUpdateAreaSafetyScore([
                    'area_name' => $areaName,
                    'district' => $location['district'],
                    'upazila' => $location['upazila'],
                    'safety_score' => 5.0, // Default score, will be recalculated
                    'total_incidents' => 0,
                    'resolved_incidents' => 0,
                    'critical_incidents' => 0,
                    'response_time_avg_hours' => 0
                ]);

                // Get the newly created area and calculate score
                $newArea = $this->getAreaByLocation($location['district'], $location['upazila']);
                if ($newArea) {
                    $this->calculateAreaSafetyScore($newArea['id']);
                }
            }
        }
    }

    public function calculateAreaSafetyScore($areaId) {
        $area = $this->getAreaSafetyScoreById($areaId);
        if (!$area) {
            return false;
        }

        // Get incident data for this area by matching district and upazila from address/location
        $allIncidents = $this->db->fetchAll("SELECT * FROM incident_reports ORDER BY incident_date DESC");

        // Filter incidents that match this area
        $incidents = [];
        foreach ($allIncidents as $incident) {
            $incidentLocation = $this->extractLocationFromAddress($incident['address'] ?? null, $incident['location_name'] ?? null);

            $districtMatch = ($incidentLocation['district'] === $area['district']);
            $upazilaMatch = true;

            if ($area['upazila']) {
                $upazilaMatch = ($incidentLocation['upazila'] === $area['upazila']);
            }

            if ($districtMatch && $upazilaMatch) {
                $incidents[] = $incident;
            }
        }

        $totalIncidents = count($incidents);
        $resolvedIncidents = count(array_filter($incidents, fn($i) => $i['status'] === 'resolved'));
        $criticalIncidents = count(array_filter($incidents, fn($i) => in_array($i['severity'], ['high', 'critical'])));

        // Calculate response time (average hours between incident and resolution)
        $responseTimes = [];
        foreach ($incidents as $incident) {
            if ($incident['status'] === 'resolved') {
                $incidentTime = null;
                if ($incident['incident_date']) {
                    $incidentTime = strtotime($incident['incident_date']);
                } elseif ($incident['reported_date']) {
                    $incidentTime = strtotime($incident['reported_date']);
                }

                $resolvedTime = null;
                if (!empty($incident['resolved_at'])) {
                    $resolvedTime = strtotime($incident['resolved_at']);
                } elseif (!empty($incident['updated_date']) && $incident['status'] === 'resolved') {
                    $resolvedTime = strtotime($incident['updated_date']);
                }

                if ($incidentTime && $resolvedTime && $resolvedTime > $incidentTime) {
                    $responseTimes[] = ($resolvedTime - $incidentTime) / 3600; // Convert to hours
                }
            }
        }
        $avgResponseTime = !empty($responseTimes) ? array_sum($responseTimes) / count($responseTimes) : 0;

        // Get user ratings
        $userRatings = $this->getAreaRatings($areaId);
        $avgUserRating = !empty($userRatings) ? array_sum(array_column($userRatings, 'safety_rating')) / count($userRatings) : 0;

        // Calculate safety score (0-10 scale)
        // Factors:
        // - Incident rate: 30% (lower is better)
        // - Resolution rate: 25% (higher is better)
        // - Response time: 20% (lower is better)
        // - User ratings: 15% (higher is better)
        // - Critical incidents: 10% (lower is better)

        // Improved scoring algorithm
        // Incident rate: Based on incidents per month (assuming 30-day window)
        $recentIncidents = array_filter($incidents, function($inc) {
            $incidentDate = strtotime($inc['incident_date'] ?? $inc['reported_date'] ?? 'now');
            return (time() - $incidentDate) < (30 * 24 * 3600); // Last 30 days
        });
        $recentCount = count($recentIncidents);
        $incidentRateScore = min(10, max(0, 10 - ($recentCount / 5))); // 5 incidents per month = 0 score

        // Resolution rate: Higher is better
        $resolutionRateScore = ($totalIncidents > 0) ? ($resolvedIncidents / $totalIncidents) * 10 : 7.5;

        // Response time: Faster is better (0-24 hours = 10, 48+ hours = 0)
        $responseTimeScore = max(0, min(10, 10 - ($avgResponseTime / 2.4))); // 24 hours = 0 score

        // User ratings: Convert 1-5 scale to 0-10
        $userRatingScore = $avgUserRating > 0 ? $avgUserRating * 2 : 5; // Default to 5 if no ratings

        // Critical incidents: Fewer is better
        $criticalIncidentScore = max(0, min(10, 10 - ($criticalIncidents / 3))); // 3+ critical = 0 score

        $safetyScore = (
            $incidentRateScore * 0.30 +
            $resolutionRateScore * 0.25 +
            $responseTimeScore * 0.20 +
            $userRatingScore * 0.15 +
            $criticalIncidentScore * 0.10
        );

        $this->createOrUpdateAreaSafetyScore([
            'id' => $area['id'],
            'area_name' => $area['area_name'],
            'division_id' => $area['division_id'],
            'district_id' => $area['district_id'],
            'upazila_id' => $area['upazila_id'],
            'division' => $area['division'] ?? null,
            'district' => $area['district'] ?? null,
            'upazila' => $area['upazila'] ?? null,
            'union_name' => $area['union_name'],
            'ward_number' => $area['ward_number'],
            'safety_score' => round($safetyScore, 2),
            'incident_rate_score' => round($incidentRateScore, 2),
            'resolution_rate_score' => round($resolutionRateScore, 2),
            'response_time_score' => round($responseTimeScore, 2),
            'user_ratings_score' => round($userRatingScore, 2),
            'critical_incidents_score' => round($criticalIncidentScore, 2),
            'total_incidents' => $totalIncidents,
            'resolved_incidents' => $resolvedIncidents,
            'critical_incidents' => $criticalIncidents,
            'response_time_avg_hours' => round($avgResponseTime, 2)
        ]);

        return round($safetyScore, 2);
    }

    /**
     * User Area Rating Operations
     */
    public function createOrUpdateAreaRating($data) {
        // Check if user already rated this area
        $existing = $this->db->fetchOne(
            "SELECT * FROM user_area_ratings WHERE user_id = ? AND area_id = ?",
            [$data['user_id'], $data['area_id']]
        );

        if ($existing) {
            // Update existing rating
            $sql = "UPDATE user_area_ratings
                    SET safety_rating = ?, comments = ?, factors = ?, updated_at = NOW()
                    WHERE id = ?";
            return $this->db->update($sql, [
                $data['safety_rating'],
                $data['comments'] ?? null,
                isset($data['factors']) ? (is_array($data['factors']) ? json_encode($data['factors']) : $data['factors']) : null,
                $existing['id']
            ]);
        } else {
            // Create new rating
            $sql = "INSERT INTO user_area_ratings
                    (user_id, area_id, safety_rating, comments, factors, is_verified_resident)
                    VALUES (?, ?, ?, ?, ?, ?)";
            return $this->db->insert($sql, [
                $data['user_id'],
                $data['area_id'],
                $data['safety_rating'],
                $data['comments'] ?? null,
                isset($data['factors']) ? (is_array($data['factors']) ? json_encode($data['factors']) : $data['factors']) : null,
                $data['is_verified_resident'] ?? 0
            ]);
        }
    }

    public function getAreaRatings($areaId, $filters = []) {
        $sql = "SELECT uar.*, u.display_name, u.email
                FROM user_area_ratings uar
                LEFT JOIN users u ON uar.user_id = u.id
                WHERE uar.area_id = ?";
        $params = [$areaId];

        if (isset($filters['is_verified_resident']) && $filters['is_verified_resident'] !== '') {
            $sql .= " AND uar.is_verified_resident = ?";
            $params[] = $filters['is_verified_resident'];
        }

        $sql .= " ORDER BY uar.is_verified_resident DESC, uar.created_at DESC";

        if (!empty($filters['limit'])) {
            $sql .= " LIMIT ?";
            $params[] = (int)$filters['limit'];
        }

        return $this->db->fetchAll($sql, $params);
    }

    public function getUserAreaRating($userId, $areaId) {
        $sql = "SELECT * FROM user_area_ratings WHERE user_id = ? AND area_id = ?";
        return $this->db->fetchOne($sql, [$userId, $areaId]);
    }

    public function searchAreas($searchTerm) {
        $sql = "SELECT ass.*,
                       d.name AS division,
                       dist.name AS district,
                       up.name AS upazila,
                       (SELECT COUNT(*) FROM user_area_ratings WHERE area_id = ass.id) as rating_count,
                       (SELECT AVG(safety_rating) FROM user_area_ratings WHERE area_id = ass.id) as user_rating_avg
                FROM area_safety_scores ass
                LEFT JOIN divisions d ON ass.division_id = d.id
                LEFT JOIN districts dist ON ass.district_id = dist.id
                LEFT JOIN upazilas up ON ass.upazila_id = up.id
                WHERE ass.area_name LIKE ?
                   OR dist.name LIKE ?
                   OR up.name LIKE ?
                   OR ass.union_name LIKE ?
                ORDER BY ass.safety_score DESC";
        $search = '%' . $searchTerm . '%';
        return $this->db->fetchAll($sql, [$search, $search, $search, $search]);
    }

    /**
     * Recalculate all area safety scores
     */
    public function recalculateAllAreaScores() {
        $areas = $this->getAreaSafetyScores([]);
        $results = [];

        foreach ($areas as $area) {
            $score = $this->calculateAreaSafetyScore($area['id']);
            $results[] = [
                'area_id' => $area['id'],
                'area_name' => $area['area_name'],
                'new_score' => $score
            ];
        }

        return $results;
    }

    /**
     * Get areas that need score recalculation (haven't been updated recently)
     */
    public function getAreasNeedingRecalculation($hoursThreshold = 24) {
        $sql = "SELECT * FROM area_safety_scores
                WHERE last_updated < DATE_SUB(NOW(), INTERVAL ? HOUR)
                OR last_updated IS NULL
                ORDER BY last_updated ASC";
        return $this->db->fetchAll($sql, [$hoursThreshold]);
    }

    /**
     * Safety Education & Training Course Operations
     */
    public function getSafetyCourses($filters = []) {
        $sql = "SELECT * FROM safety_courses WHERE status = 'active'";
        $params = [];

        if (!empty($filters['category'])) {
            $sql .= " AND category = ?";
            $params[] = $filters['category'];
        }

        if (!empty($filters['target_audience'])) {
            $sql .= " AND target_audience LIKE ?";
            $params[] = '%' . $filters['target_audience'] . '%';
        }

        if (!empty($filters['language'])) {
            $sql .= " AND (language = ? OR language = 'both')";
            $params[] = $filters['language'];
        }

        if (isset($filters['is_premium']) && $filters['is_premium'] !== '') {
            $sql .= " AND is_premium = ?";
            $params[] = $filters['is_premium'];
        }

        $sql .= " ORDER BY enrollment_count DESC, average_rating DESC";

        if (!empty($filters['limit'])) {
            $sql .= " LIMIT ?";
            $params[] = $filters['limit'];
        }

        return $this->db->fetchAll($sql, $params);
    }

    public function getSafetyCourseById($id) {
        $sql = "SELECT * FROM safety_courses WHERE id = ?";
        return $this->db->fetchOne($sql, [$id]);
    }

    public function searchSafetyCourses($searchTerm) {
        $sql = "SELECT * FROM safety_courses
                WHERE status = 'active'
                AND (course_title LIKE ?
                     OR course_description LIKE ?
                     OR instructor_name LIKE ?)
                ORDER BY enrollment_count DESC, average_rating DESC";
        $search = '%' . $searchTerm . '%';
        return $this->db->fetchAll($sql, [$search, $search, $search]);
    }

    /**
     * Course Enrollment Operations
     */
    public function enrollInCourse($userId, $courseId) {
        // Check if already enrolled
        $existing = $this->db->fetchOne(
            "SELECT * FROM course_enrollments WHERE user_id = ? AND course_id = ?",
            [$userId, $courseId]
        );

        if ($existing) {
            return $existing['id'];
        }

        // Create enrollment
        $sql = "INSERT INTO course_enrollments (user_id, course_id, status, started_at)
                VALUES (?, ?, 'enrolled', NOW())";
        $enrollmentId = $this->db->insert($sql, [$userId, $courseId]);

        // Update course enrollment count
        if ($enrollmentId) {
            $this->db->update(
                "UPDATE safety_courses SET enrollment_count = enrollment_count + 1 WHERE id = ?",
                [$courseId]
            );
        }

        return $enrollmentId;
    }

    public function getUserEnrollments($userId, $filters = []) {
        $sql = "SELECT ce.*, sc.course_title, sc.course_description, sc.category, sc.duration_minutes,
                       sc.thumbnail_url, sc.instructor_name, sc.language
                FROM course_enrollments ce
                LEFT JOIN safety_courses sc ON ce.course_id = sc.id
                WHERE ce.user_id = ?";
        $params = [$userId];

        if (!empty($filters['status'])) {
            $sql .= " AND ce.status = ?";
            $params[] = $filters['status'];
        }

        $sql .= " ORDER BY ce.started_at DESC";

        return $this->db->fetchAll($sql, $params);
    }

    public function getEnrollmentById($id) {
        $sql = "SELECT ce.*, sc.course_title, sc.course_description, sc.category, sc.duration_minutes,
                       sc.thumbnail_url, sc.instructor_name, sc.content_url, sc.content_type
                FROM course_enrollments ce
                LEFT JOIN safety_courses sc ON ce.course_id = sc.id
                WHERE ce.id = ?";
        return $this->db->fetchOne($sql, [$id]);
    }

    public function getEnrollmentByUserAndCourse($userId, $courseId) {
        $sql = "SELECT ce.*, sc.course_title, sc.course_description, sc.category, sc.duration_minutes,
                       sc.thumbnail_url, sc.instructor_name, sc.content_url, sc.content_type
                FROM course_enrollments ce
                LEFT JOIN safety_courses sc ON ce.course_id = sc.id
                WHERE ce.user_id = ? AND ce.course_id = ?";
        return $this->db->fetchOne($sql, [$userId, $courseId]);
    }

    public function updateEnrollmentProgress($enrollmentId, $progressPercentage, $status = null) {
        $updateFields = [];
        $params = [];

        $updateFields[] = "progress_percentage = ?";
        $params[] = $progressPercentage;

        $updateFields[] = "last_accessed_at = NOW()";

        if ($status) {
            $updateFields[] = "status = ?";
            $params[] = $status;

            if ($status === 'completed') {
                $updateFields[] = "completed_at = NOW()";
            }
        }

        $params[] = $enrollmentId;
        $sql = "UPDATE course_enrollments SET " . implode(", ", $updateFields) . " WHERE id = ?";
        $result = $this->db->update($sql, $params);

        // Update course completion count if completed
        if ($result && $status === 'completed') {
            $enrollment = $this->getEnrollmentById($enrollmentId);
            if ($enrollment) {
                $this->db->update(
                    "UPDATE safety_courses SET completion_count = completion_count + 1 WHERE id = ?",
                    [$enrollment['course_id']]
                );
            }
        }

        return $result;
    }

    public function updateEnrollmentRating($enrollmentId, $rating, $feedback = null) {
        $sql = "UPDATE course_enrollments
                SET rating = ?, feedback = ?
                WHERE id = ?";
        $result = $this->db->update($sql, [$rating, $feedback, $enrollmentId]);

        // Update course average rating
        if ($result) {
            $enrollment = $this->getEnrollmentById($enrollmentId);
            if ($enrollment) {
                $this->updateCourseRating($enrollment['course_id']);
            }
        }

        return $result;
    }

    private function updateCourseRating($courseId) {
        $sql = "SELECT AVG(rating) as avg_rating, COUNT(*) as rating_count
                FROM course_enrollments
                WHERE course_id = ? AND rating IS NOT NULL AND rating > 0";
        $result = $this->db->fetchOne($sql, [$courseId]);

        if ($result && $result['avg_rating']) {
            $updateSql = "UPDATE safety_courses
                         SET average_rating = ?, rating_count = ?
                         WHERE id = ?";
            $this->db->update($updateSql, [
                round($result['avg_rating'], 2),
                $result['rating_count'],
                $courseId
            ]);
        }
    }

    /**
     * Certificate Operations
     */
    public function issueCertificate($userId, $courseId, $enrollmentId) {
        // Check if certificate already exists
        $existing = $this->db->fetchOne(
            "SELECT * FROM certificates WHERE user_id = ? AND course_id = ?",
            [$userId, $courseId]
        );

        if ($existing) {
            return $existing;
        }

        // Generate certificate number and verification code
        $certificateNumber = 'CERT-' . strtoupper(substr(md5($userId . $courseId . time()), 0, 8)) . '-' . date('Y');
        $verificationCode = strtoupper(substr(md5($userId . $courseId . time() . rand()), 0, 12));

        $sql = "INSERT INTO certificates
                (user_id, course_id, enrollment_id, certificate_number, verification_code, issued_at)
                VALUES (?, ?, ?, ?, ?, NOW())";
        $certificateId = $this->db->insert($sql, [
            $userId,
            $courseId,
            $enrollmentId,
            $certificateNumber,
            $verificationCode
        ]);

        if ($certificateId) {
            // Update enrollment
            $this->db->update(
                "UPDATE course_enrollments SET certificate_issued = 1, certificate_id = ? WHERE id = ?",
                [$certificateNumber, $enrollmentId]
            );

            return $this->getCertificateById($certificateId);
        }

        return false;
    }

    public function getCertificateById($id) {
        $sql = "SELECT c.*, sc.course_title, u.display_name, u.email
                FROM certificates c
                LEFT JOIN safety_courses sc ON c.course_id = sc.id
                LEFT JOIN users u ON c.user_id = u.id
                WHERE c.id = ?";
        return $this->db->fetchOne($sql, [$id]);
    }

    public function getUserCertificates($userId) {
        $sql = "SELECT c.*, sc.course_title, sc.category, sc.instructor_name
                FROM certificates c
                LEFT JOIN safety_courses sc ON c.course_id = sc.id
                WHERE c.user_id = ?
                ORDER BY c.issued_at DESC";
        return $this->db->fetchAll($sql, [$userId]);
    }

    public function verifyCertificate($verificationCode) {
        $sql = "SELECT c.*, sc.course_title, u.display_name, u.email
                FROM certificates c
                LEFT JOIN safety_courses sc ON c.course_id = sc.id
                LEFT JOIN users u ON c.user_id = u.id
                WHERE c.verification_code = ?";
        $certificate = $this->db->fetchOne($sql, [$verificationCode]);

        if ($certificate) {
            // Mark as verified
            $this->db->update(
                "UPDATE certificates SET is_verified = 1 WHERE id = ?",
                [$certificate['id']]
            );
            $certificate['is_verified'] = 1;
        }

        return $certificate;
    }

    public function getCourseEnrollments($courseId, $filters = []) {
        $sql = "SELECT ce.*, u.display_name, u.email
                FROM course_enrollments ce
                LEFT JOIN users u ON ce.user_id = u.id
                WHERE ce.course_id = ?";
        $params = [$courseId];

        if (!empty($filters['status'])) {
            $sql .= " AND ce.status = ?";
            $params[] = $filters['status'];
        }

        $sql .= " ORDER BY ce.started_at DESC";

        return $this->db->fetchAll($sql, $params);
    }

    /**
     * Panic Button & Emergency SOS Operations
     */
    public function createPanicAlert($data) {
        $sql = "INSERT INTO panic_alerts
                (user_id, trigger_method, location_name, latitude, longitude, message,
                 emergency_contacts_notified, police_notified, ambulance_notified, fire_service_notified,
                 status, triggered_at)
                VALUES (?, ?, ?, ?, ?, ?, 0, ?, ?, ?, 'active', NOW())";

        $alertId = $this->db->insert($sql, [
            $data['user_id'],
            $data['trigger_method'] ?? 'app_button',
            $data['location_name'] ?? null,
            $data['latitude'] ?? null,
            $data['longitude'] ?? null,
            $data['message'] ?? null,
            $data['police_notified'] ?? 0,
            $data['ambulance_notified'] ?? 0,
            $data['fire_service_notified'] ?? 0
        ]);

        if ($alertId) {
            // Notify emergency contacts only if enabled (default to true if not specified)
            $shouldNotifyContacts = $data['notify_emergency_contacts'] ?? true;
            $contactsNotified = 0;

            if ($shouldNotifyContacts) {
                $contactsNotified = $this->notifyEmergencyContacts($alertId, $data['user_id']);
            }

            // Update notification count
            $this->db->update(
                "UPDATE panic_alerts SET emergency_contacts_notified = ? WHERE id = ?",
                [$contactsNotified, $alertId]
            );
        }

        return $alertId;
    }

    public function getPanicAlertById($id) {
        $sql = "SELECT pa.*, u.display_name, u.email, u.phone
                FROM panic_alerts pa
                LEFT JOIN users u ON pa.user_id = u.id
                WHERE pa.id = ?";
        return $this->db->fetchOne($sql, [$id]);
    }

    public function getUserPanicAlerts($userId, $filters = []) {
        $sql = "SELECT * FROM panic_alerts WHERE user_id = ?";
        $params = [$userId];

        if (!empty($filters['status'])) {
            $sql .= " AND status = ?";
            $params[] = $filters['status'];
        }

        if (!empty($filters['limit'])) {
            $sql .= " ORDER BY triggered_at DESC LIMIT ?";
            $params[] = $filters['limit'];
        } else {
            $sql .= " ORDER BY triggered_at DESC";
        }

        return $this->db->fetchAll($sql, $params);
    }

    public function updatePanicAlertStatus($id, $status, $responseTime = null) {
        $updateFields = ["status = ?"];
        $params = [$status];

        if ($status === 'resolved' || $status === 'false_alarm') {
            $updateFields[] = "resolved_at = NOW()";
        }

        if ($responseTime !== null) {
            $updateFields[] = "response_time_seconds = ?";
            $params[] = $responseTime;
        }

        $params[] = $id;
        $sql = "UPDATE panic_alerts SET " . implode(", ", $updateFields) . " WHERE id = ?";
        return $this->db->update($sql, $params);
    }

    private function notifyEmergencyContacts($alertId, $userId) {
        $contacts = $this->getUserEmergencyContacts($userId, ['is_active' => 1]);
        $notifiedCount = 0;

        foreach ($contacts as $contact) {
            $notificationMethods = explode(',', $contact['notification_methods'] ?? 'sms,call');

            foreach ($notificationMethods as $method) {
                $method = trim($method);
                $this->createPanicNotification($alertId, $contact['id'], $method, $contact['phone_number']);
                $notifiedCount++;
            }
        }

        return $notifiedCount;
    }

    public function createPanicNotification($alertId, $contactId, $notificationType, $recipient, $message = null) {
        if (!$message) {
            $alert = $this->getPanicAlertById($alertId);
            $user = $this->db->fetchOne("SELECT display_name, phone FROM users WHERE id = ?", [$alert['user_id']]);

            $location = $alert['location_name'] ?? 'Location not available';
            if ($alert['latitude'] && $alert['longitude']) {
                $location = "https://maps.google.com/?q={$alert['latitude']},{$alert['longitude']}";
            }

            $message = "🚨 EMERGENCY ALERT 🚨\n\n";
            $message .= "User: " . ($user['display_name'] ?? 'Unknown') . "\n";
            $message .= "Location: " . $location . "\n";
            $message .= "Time: " . date('Y-m-d H:i:s', strtotime($alert['triggered_at'])) . "\n";
            if ($alert['message']) {
                $message .= "Message: " . $alert['message'] . "\n";
            }
            $message .= "\nPlease respond immediately!";
        }

        $sql = "INSERT INTO panic_notifications
                (panic_alert_id, contact_id, notification_type, recipient, message, status)
                VALUES (?, ?, ?, ?, ?, 'pending')";

        $notificationId = $this->db->insert($sql, [
            $alertId,
            $contactId,
            $notificationType,
            $recipient,
            $message
        ]);

        // Try to send actual notification
        require_once __DIR__ . '/NotificationSender.php';
        $sender = new NotificationSender();
        $sendResult = ['success' => false, 'error' => 'Service not configured'];

        try {
            switch (strtolower($notificationType)) {
                case 'sms':
                    $sendResult = $sender->sendSMS($recipient, $message);
                    break;
                case 'email':
                    $sendResult = $sender->sendEmail($recipient, '🚨 SafeSpace Emergency Alert', $message);
                    break;
                case 'call':
                    $sendResult = $sender->makeCall($recipient, $message);
                    break;
                case 'whatsapp':
                    $sendResult = $sender->sendWhatsApp($recipient, $message);
                    break;
                default:
                    $sendResult = ['success' => false, 'error' => 'Unknown notification type'];
            }
        } catch (Exception $e) {
            $sendResult = ['success' => false, 'error' => $e->getMessage()];
        }

        // Update notification status based on send result
        if ($notificationId) {
            if ($sendResult['success']) {
                // Update status to sent
                $updateSql = "UPDATE panic_notifications SET status = 'sent', sent_at = NOW() WHERE id = ?";
                $this->db->update($updateSql, [$notificationId]);
            } else {
                // Log the error and mark as failed
                $this->db->update(
                    "UPDATE panic_notifications SET status = 'failed', error_message = ? WHERE id = ?",
                    [substr($sendResult['error'] ?? 'Unknown error', 0, 255), $notificationId]
                );
                error_log("Failed to send notification #$notificationId: " . ($sendResult['error'] ?? 'Unknown error'));
            }
        }

        return $notificationId;
    }

    /**
     * Emergency Contacts Operations
     */
    public function addEmergencyContact($data) {
        $sql = "INSERT INTO emergency_contacts
                (user_id, contact_name, phone_number, relationship, priority,
                 notification_methods, is_active, is_verified)
                VALUES (?, ?, ?, ?, ?, ?, 1, 0)";

        return $this->db->insert($sql, [
            $data['user_id'],
            $data['contact_name'],
            $data['phone_number'],
            $data['relationship'] ?? null,
            $data['priority'] ?? 1,
            $data['notification_methods'] ?? 'sms,call'
        ]);
    }

    public function getUserEmergencyContacts($userId, $filters = []) {
        $sql = "SELECT * FROM emergency_contacts WHERE user_id = ?";
        $params = [$userId];

        if (isset($filters['is_active']) && $filters['is_active'] !== '') {
            $sql .= " AND is_active = ?";
            $params[] = $filters['is_active'];
        }

        $sql .= " ORDER BY priority ASC, contact_name ASC";

        return $this->db->fetchAll($sql, $params);
    }

    public function getEmergencyContactById($id) {
        $sql = "SELECT * FROM emergency_contacts WHERE id = ?";
        return $this->db->fetchOne($sql, [$id]);
    }

    public function updateEmergencyContact($id, $data) {
        $updateFields = [];
        $params = [];

        $allowedFields = ['contact_name', 'phone_number', 'relationship', 'priority',
                          'notification_methods', 'is_active', 'is_verified'];

        foreach ($allowedFields as $field) {
            if (isset($data[$field])) {
                $updateFields[] = "$field = ?";
                $params[] = $data[$field];
            }
        }

        if (empty($updateFields)) {
            return false;
        }

        $params[] = $id;
        $sql = "UPDATE emergency_contacts SET " . implode(", ", $updateFields) . " WHERE id = ?";
        return $this->db->update($sql, $params);
    }

    public function deleteEmergencyContact($id, $userId) {
        // Verify ownership
        $contact = $this->getEmergencyContactById($id);
        if (!$contact || $contact['user_id'] != $userId) {
            return false;
        }

        $sql = "DELETE FROM emergency_contacts WHERE id = ?";
        return $this->db->delete($sql, [$id]);
    }

    public function getPanicNotifications($alertId) {
        $sql = "SELECT pn.*, ec.contact_name
                FROM panic_notifications pn
                LEFT JOIN emergency_contacts ec ON pn.contact_id = ec.id
                WHERE pn.panic_alert_id = ?
                ORDER BY pn.sent_at DESC";
        return $this->db->fetchAll($sql, [$alertId]);
    }

    public function getActivePanicAlerts($filters = []) {
        $sql = "SELECT pa.*, u.display_name, u.phone
                FROM panic_alerts pa
                LEFT JOIN users u ON pa.user_id = u.id
                WHERE pa.status = 'active'";
        $params = [];

        if (!empty($filters['limit'])) {
            $sql .= " ORDER BY pa.triggered_at DESC LIMIT ?";
            $params[] = $filters['limit'];
        } else {
            $sql .= " ORDER BY pa.triggered_at DESC";
        }

        return $this->db->fetchAll($sql, $params);
    }


}
?>
