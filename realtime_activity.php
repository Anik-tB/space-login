<?php
/**
 * Real-time Activity API
 * Provides real-time activity data for the dashboard
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST');
header('Access-Control-Allow-Headers: Content-Type');

// Include database handler
require_once 'includes/Database.php';

// Initialize database
$database = new Database();
$models = new SafeSpaceModels($database);

try {
    $action = $_GET['action'] ?? 'get_activity';

    switch ($action) {
        case 'get_activity':
            $limit = intval($_GET['limit'] ?? 10);
            $type = $_GET['type'] ?? 'all';
            $activity = getRealTimeActivity($models, $limit, $type);
            echo json_encode(['success' => true, 'data' => $activity]);
            break;

        case 'get_metrics':
            $metrics = getRealTimeMetrics($models);
            echo json_encode(['success' => true, 'data' => $metrics]);
            break;

        case 'get_system_health':
            $health = getSystemHealth($models);
            echo json_encode(['success' => true, 'data' => $health]);
            break;

        case 'get_performance':
            $performance = getPerformanceMetrics($models);
            echo json_encode(['success' => true, 'data' => $performance]);
            break;

        default:
            echo json_encode(['success' => false, 'error' => 'Invalid action']);
    }

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}

/**
 * Get real-time activity data
 */
function getRealTimeActivity($models, $limit = 10, $type = 'all') {
    $activities = [];

    // Get recent incident reports
    if ($type === 'all' || $type === 'reports') {
        $reports = $models->getRecentReports($limit);
        foreach ($reports as $report) {
            $iso = $report['reported_date'];
            $ts = strtotime($iso) ?: time();
            $activities[] = [
                'id' => $report['id'],
                'type' => 'report',
                'action' => 'Report submitted: ' . $report['title'],
                'time' => formatRelativeTime($iso),
                'originalTime' => $iso,
                'timestamp' => $ts * 1000,
                'color' => 'blue',
                'icon' => 'file-text',
                'details' => [
                    'category' => $report['category'],
                    'severity' => $report['severity'],
                    'status' => $report['status']
                ]
            ];
        }
    }

    // Get recent alerts
    if ($type === 'all' || $type === 'alerts') {
        $alerts = $models->getRecentAlerts($limit);
        foreach ($alerts as $alert) {
            $iso = $alert['start_time'];
            $ts = strtotime($iso) ?: time();
            $activities[] = [
                'id' => $alert['id'],
                'type' => 'alert',
                'action' => 'Alert created: ' . $alert['title'],
                'time' => formatRelativeTime($iso),
                'originalTime' => $iso,
                'timestamp' => $ts * 1000,
                'color' => 'yellow',
                'icon' => 'bell',
                'details' => [
                    'type' => $alert['type'],
                    'severity' => $alert['severity'],
                    'location' => $alert['location_name']
                ]
            ];
        }
    }

    // Get recent user registrations
    if ($type === 'all' || $type === 'users') {
        $users = $models->getRecentUsers($limit);
        foreach ($users as $user) {

            // --- MODIFICATION START ---
            // Logic: Show display_name if it exists, otherwise show 'ID #[user_id]'

            $displayTitle = !empty($user['display_name'])
                            ? htmlspecialchars($user['display_name'])
                            : 'ID #' . $user['id'];

            $iso = $user['created_at'];
            $ts = strtotime($iso) ?: time();
            $activities[] = [
                'id' => $user['id'],
                'type' => 'user',
                'action' => 'New user registered', // This is now the main title
                'time' => formatRelativeTime($iso),
                'originalTime' => $iso,
                'timestamp' => $ts * 1000,
                'color' => 'green',
                'icon' => 'user-plus',
                'details' => [
                    // The JS file uses the 'email' key for the subtitle, so we'll put our new title here
                    'email' => $displayTitle,
                    'provider' => $user['provider']
                ]
            ];
            // --- MODIFICATION END ---
        }
    }

    // Get recent disputes
    if ($type === 'all' || $type === 'disputes') {
        $disputes = $models->getRecentDisputes($limit);
        foreach ($disputes as $dispute) {
            $iso = $dispute['created_at'];
            $ts = strtotime($iso) ?: time();
            $activities[] = [
                'id' => $dispute['id'],
                'type' => 'dispute',
                'action' => 'Dispute filed for report #' . $dispute['report_id'],
                'time' => formatRelativeTime($iso),
                'originalTime' => $iso,
                'timestamp' => $ts * 1000,
                'color' => 'purple',
                'icon' => 'gavel',
                'details' => [
                    'reason' => $dispute['reason'],
                    'status' => $dispute['status']
                ]
            ];
        }
    }

    // Sort by original timestamp (most recent first)
    usort($activities, function($a, $b) {
        $ta = isset($a['originalTime']) ? strtotime($a['originalTime']) : 0;
        $tb = isset($b['originalTime']) ? strtotime($b['originalTime']) : 0;
        if ($ta === $tb) return 0;
        return ($tb <=> $ta);
    });

    return array_slice($activities, 0, $limit);
}

/**
 * Get real-time performance metrics
 */
function getRealTimeMetrics($models) {
    $metrics = [];

    // Get database stats
    $stats = $models->getDashboardStats();

    // Get real system performance metrics
    $performanceMetrics = $models->getSystemPerformanceMetrics();

    // Get active sessions count
    $activeSessions = $models->getActiveSessionsCount();

    $metrics = [
        'uptime' => $performanceMetrics['uptime'],
        'response_time' => $performanceMetrics['avg_response_time'] . 'm',
        'active_users' => number_format($activeSessions),
        'total_reports' => $stats['total_reports'],
        'active_alerts' => $stats['active_alerts'],
        'safe_spaces' => $stats['safe_spaces'],
        'recent_reports' => $performanceMetrics['today_reports'],
        'cpu_usage' => $performanceMetrics['cpu_usage'],
        'memory_usage' => $performanceMetrics['memory_usage'],
        'network_usage' => $performanceMetrics['network_usage'],
        'database_health' => $performanceMetrics['database_health']
    ];

    return $metrics;
}

/**
 * Get system health status
 */
function getSystemHealth($models) {
    $health = [];

    // Database health (simulated based on connection)
    $dbHealth = 95 + rand(-5, 5); // 90-100%

    // API health (simulated)
    $apiHealth = 98 + rand(-3, 2); // 95-100%

    // Storage health (simulated)
    $storageHealth = 70 + rand(-10, 10); // 60-80%

    // Security health (always 100% for demo)
    $securityHealth = 100;

    $health = [
        'database' => [
            'status' => $dbHealth > 90 ? 'green' : ($dbHealth > 70 ? 'yellow' : 'red'),
            'percentage' => $dbHealth,
            'message' => $dbHealth > 90 ? 'Excellent' : ($dbHealth > 70 ? 'Good' : 'Needs attention')
        ],
        'api' => [
            'status' => $apiHealth > 90 ? 'green' : ($apiHealth > 70 ? 'yellow' : 'red'),
            'percentage' => $apiHealth,
            'message' => $apiHealth > 90 ? 'Excellent' : ($apiHealth > 70 ? 'Good' : 'Needs attention')
        ],
        'storage' => [
            'status' => $storageHealth > 90 ? 'green' : ($storageHealth > 70 ? 'yellow' : 'red'),
            'percentage' => $storageHealth,
            'message' => $storageHealth > 90 ? 'Excellent' : ($storageHealth > 70 ? 'Good' : 'Needs attention')
        ],
        'security' => [
            'status' => 'green',
            'percentage' => $securityHealth,
            'message' => 'Excellent'
        ]
    ];

    return $health;
}

/**
 * Get performance metrics
 */
function getPerformanceMetrics($models) {
    $performance = [];

    // Get recent activity counts
    $stats = $models->getDashboardStats();

    // Calculate trends (simulated)
    $reportsTrend = rand(-15, 25); // -15% to +25%
    $alertsTrend = rand(-10, 20);
    $usersTrend = rand(5, 30);

    $performance = [
        'total_reports' => $stats['total_reports'],
        'reports_trend' => $reportsTrend,
        'active_alerts' => $stats['active_alerts'],
        'alerts_trend' => $alertsTrend,
        'safe_spaces' => $stats['safe_spaces'],
        'users_trend' => $usersTrend,
        'avg_response_time' => rand(1, 5) . '.' . rand(0, 9) . 'm',
        'resolution_rate' => rand(70, 95) . '%'
    ];

    return $performance;
}

/**
 * Format relative time with improved accuracy
 */
function formatRelativeTime($datetime) {
    try {
        // Set timezone to match server timezone
        $timezone = new DateTimeZone(date_default_timezone_get());

        $now = new DateTime('now', $timezone);
        $time = new DateTime($datetime, $timezone);
        $diff = $now->diff($time);

        // Handle future dates (shouldn't happen but just in case)
        if ($diff->invert) {
            return 'Just now';
        }

        if ($diff->y > 0) {
            return $diff->y . ' year' . ($diff->y > 1 ? 's' : '') . ' ago';
        } elseif ($diff->m > 0) {
            return $diff->m . ' month' . ($diff->m > 1 ? 's' : '') . ' ago';
        } elseif ($diff->d > 0) {
            return $diff->d . ' day' . ($diff->d > 1 ? 's' : '') . ' ago';
        } elseif ($diff->h > 0) {
            return $diff->h . ' hour' . ($diff->h > 1 ? 's' : '') . ' ago';
        } elseif ($diff->i > 0) {
            return $diff->i . ' minute' . ($diff->i > 1 ? 's' : '') . ' ago';
        } elseif ($diff->s > 30) {
            return 'Less than a minute ago';
        } else {
            return 'Just now';
        }
    } catch (Exception $e) {
        // Fallback to simple format if datetime parsing fails
        return date('M j, Y g:i A', strtotime($datetime));
    }
}
?>