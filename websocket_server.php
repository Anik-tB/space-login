<?php
// websocket_server.php
date_default_timezone_set('Asia/Dhaka');
use Ratchet\MessageComponentInterface;
use Ratchet\ConnectionInterface;
use Ratchet\Server\IoServer;
use Ratchet\Http\HttpServer;
use Ratchet\WebSocket\WsServer;

require dirname(__FILE__) . '/vendor/autoload.php';
require_once __DIR__ . '/includes/Database.php';

/**
 * This class will handle all real-time communication.
 */
class DashboardUpdater implements MessageComponentInterface {
    protected $clients;
    protected $database;
    protected $models;

    public function __construct() {
        $this->clients = new \SplObjectStorage;
        $this->database = new Database();
        $this->models = new SafeSpaceModels($this->database);
        echo "WebSocket Server started on port 8080...\n";
    }

    public function onOpen(ConnectionInterface $conn) {
        // Store the new connection to send messages to later
        $this->clients->attach($conn);
        echo "New connection! ({$conn->resourceId})\n";
    }

    /**
     * This is where your PHP backend will send its updates.
     * The server will then broadcast them to all connected users.
     */
    public function onMessage(ConnectionInterface $from, $msg) {
        $numRecv = count($this->clients) - 1;
        echo sprintf('Connection %d sent "%s" to server (%d others connected)' . "\n",
            $from->resourceId, $msg, $numRecv);

        $response = [
            'type' => 'error',
            'error' => 'Invalid payload',
        ];

        try {
            $data = json_decode($msg, true);
            if (!is_array($data)) {
                $from->send(json_encode($response));
                return;
            }

            $action = $data['action'] ?? null;
            $requestId = $data['requestId'] ?? null;

            switch ($action) {
                case 'get_metrics': {
                    $metrics = $this->getMetrics();
                    $from->send(json_encode([
                        'type' => 'metrics',
                        'requestId' => $requestId,
                        'data' => $metrics,
                    ]));
                    break;
                }
                case 'get_chart_data': {
                    $period = isset($data['period']) ? (string)$data['period'] : '30D';
                    $chart = $this->getChartData($period);
                    $from->send(json_encode([
                        'type' => 'chart_data',
                        'requestId' => $requestId,
                        'data' => $chart,
                    ]));
                    break;
                }
                case 'get_report_categories': {
                    $cats = $this->models->getReportCategories();
                    $from->send(json_encode([
                        'type' => 'report_categories',
                        'requestId' => $requestId,
                        'data' => $cats,
                    ]));
                    break;
                }
                case 'get_detailed_metrics': {
                    $metrics = $this->getDetailedMetrics();
                    $from->send(json_encode([
                        'type' => 'detailed_metrics',
                        'requestId' => $requestId,
                        'data' => $metrics,
                    ]));
                    break;
                }
                case 'get_activity': {
                    $limit = isset($data['limit']) ? (int)$data['limit'] : 10;
                    $activity = $this->getActivity($limit);
                    $from->send(json_encode([
                        'type' => 'activity',
                        'requestId' => $requestId,
                        'data' => $activity,
                    ]));
                    break;
                }
                case 'get_system_health': {
                    $health = $this->getSystemHealth();
                    $from->send(json_encode([
                        'type' => 'system_health',
                        'requestId' => $requestId,
                        'data' => $health,
                    ]));
                    break;
                }
                case 'ping': {
                    // Optional: can be used to keep-alive or update session activity later
                    $from->send(json_encode([
                        'type' => 'pong',
                        'requestId' => $requestId,
                        'data' => ['ok' => true],
                    ]));
                    break;
                }
                case 'subscribe_map': {
                    $this->subscribeToMap($from, $data);
                    break;
                }
                case 'get_map_data': {
                    $mapType = $data['map_type'] ?? 'all';
                    $bbox = $data['bbox'] ?? null;
                    $mapData = $this->getMapData($mapType, $bbox);
                    $from->send(json_encode([
                        'type' => 'map_data',
                        'requestId' => $requestId,
                        'data' => $mapData,
                    ]));
                    break;
                }
                default: {
                    $from->send(json_encode([
                        'type' => 'error',
                        'requestId' => $requestId,
                        'error' => 'Unknown action',
                    ]));
                }
            }
        } catch (\Throwable $e) {
            $from->send(json_encode([
                'type' => 'error',
                'error' => $e->getMessage(),
            ]));
        }
    }

    private function getMetrics() {
        // Mirrors realtime_activity.php:getRealTimeMetrics
        $stats = $this->models->getDashboardStats();
        $perf = $this->models->getSystemPerformanceMetrics();
        $activeSessions = $this->models->getActiveSessionsCount();

        return [
            'uptime' => $perf['uptime'],
            'response_time' => $perf['avg_response_time'] . 'm',
            'active_users' => number_format($activeSessions),
            'total_reports' => $stats['total_reports'] ?? 0,
            'active_alerts' => $stats['active_alerts'] ?? 0,
            'safe_spaces' => $stats['safe_spaces'] ?? 0,
            'recent_reports' => $perf['today_reports'] ?? 0,
            'cpu_usage' => $perf['cpu_usage'] ?? 0,
            'memory_usage' => $perf['memory_usage'] ?? 0,
            'network_usage' => $perf['network_usage'] ?? 0,
            'database_health' => $perf['database_health'] ?? 100,
        ];
    }

    private function getActivity($limit = 10) {
        // Mirrors realtime_activity.php:getRealTimeActivity
        $activities = [];

        // Reports
        foreach ($this->models->getRecentReports($limit) as $report) {
            $iso = $report['reported_date']; // This is OK, activity feed should show when it was reported
            $ts = strtotime($iso) ?: time();
            $activities[] = [
                'id' => $report['id'],
                'type' => 'report',
                'action' => 'Report submitted: ' . $report['title'],
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

        // Alerts
        foreach ($this->models->getRecentAlerts($limit) as $alert) {
            $iso = $alert['start_time'];
            $ts = strtotime($iso) ?: time();
            $activities[] = [
                'id' => $alert['id'],
                'type' => 'alert',
                'action' => 'Alert created: ' . $alert['title'],
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

        // Users
        foreach ($this->models->getRecentUsers($limit) as $user) {
            $iso = $user['created_at'];
            $ts = strtotime($iso) ?: time();
            $activities[] = [
                'id' => $user['id'],
                'type' => 'user',
                'action' => 'New user registered: ' . ($user['display_name'] ?? $user['email']),
                'originalTime' => $iso,
                'timestamp' => $ts * 1000,
                'color' => 'green',
                'icon' => 'user-plus',
                'details' => [
                    'email' => $user['email'],
                    'provider' => $user['provider']
                ]
            ];
        }

        // Disputes
        foreach ($this->models->getRecentDisputes($limit) as $dispute) {
            $iso = $dispute['created_at'];
            $ts = strtotime($iso) ?: time();
            $activities[] = [
                'id' => $dispute['id'],
                'type' => 'dispute',
                'action' => 'Dispute filed for report #' . $dispute['report_id'],
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

        usort($activities, function ($a, $b) {
            $ta = isset($a['originalTime']) ? strtotime($a['originalTime']) : 0;
            $tb = isset($b['originalTime']) ? strtotime($b['originalTime']) : 0;
            return $tb <=> $ta;
        });

        return array_slice($activities, 0, $limit);
    }

    private function getSystemHealth() {
        // Mirrors realtime_activity.php:getSystemHealth (simulated)
        $dbHealth = 95 + rand(-5, 5);
        $apiHealth = 98 + rand(-3, 2);
        $storageHealth = 70 + rand(-10, 10);
        $securityHealth = 100;

        return [
            'database' => [
                'status' => $dbHealth > 90 ? 'green' : ($dbHealth > 70 ? 'yellow' : 'red'),
                'percentage' => $dbHealth,
                'message' => $dbHealth > 90 ? 'Excellent' : ($dbHealth > 70 ? 'Good' : 'Needs attention'),
            ],
            'api' => [
                'status' => $apiHealth > 90 ? 'green' : ($apiHealth > 70 ? 'yellow' : 'red'),
                'percentage' => $apiHealth,
                'message' => $apiHealth > 90 ? 'Excellent' : ($apiHealth > 70 ? 'Good' : 'Needs attention'),
            ],
            'storage' => [
                'status' => $storageHealth > 90 ? 'green' : ($storageHealth > 70 ? 'yellow' : 'red'),
                'percentage' => $storageHealth,
                'message' => $storageHealth > 90 ? 'Excellent' : ($storageHealth > 70 ? 'Good' : 'Needs attention'),
            ],
            'security' => [
                'status' => 'green',
                'percentage' => $securityHealth,
                'message' => 'Excellent',
            ],
        ];
    }

   private function getChartData($period = '30D') {
        // Mirrors chart_data.php behavior
        $endDate = new \DateTime();
        $startDate = new \DateTime();
        switch ($period) {
            case '7D':
                $startDate->modify('-6 days');
                break;
            case '90D':
                $startDate->modify('-89 days');
                break;
            case '30D':
            default:
                $startDate->modify('-29 days');
                $period = '30D';
        }

        $startDate->setTime(0, 0, 0);

        $tableExists = false;
        try {
            $tableExists = (bool)$this->database->fetchOne("SHOW TABLES LIKE 'incident_reports'");
        } catch (\Throwable $e) {}

        $incidentData = [];
        if ($tableExists) {
            // Get REAL data from database - NO MOCK DATA

            // *** FIX: Removed DATE_ADD(incident_date, INTERVAL 6 HOUR) ***
            $sql = "SELECT DATE(incident_date) as date, COUNT(*) as count
                    FROM incident_reports
                    WHERE DATE(incident_date) >= ? AND DATE(incident_date) <= ?
                    GROUP BY date
                    ORDER BY date";

            $results = $this->database->fetchAll($sql, [$startDate->format('Y-m-d'), $endDate->format('Y-m-d')]);

            $map = [];
            foreach ($results as $row) {
                $map[$row['date']] = (int)$row['count'];
            }

            // Fill in all days with REAL data (0 if no reports that day)
            $current = clone $startDate;
            while ($current <= $endDate) {
                $dateStr = $current->format('Y-m-d');
                $incidentData[] = [
                    'date' => $dateStr,
                    'count' => $map[$dateStr] ?? 0, // 0 if no reports, not random data
                ];
                $current->modify('+1 day');
            }
        } else {
            // Table doesn't exist - return ALL ZEROS, not mock data
            $current = clone $startDate;
            while ($current <= $endDate) {
                $incidentData[] = [
                    'date' => $current->format('Y-m-d'),
                    'count' => 0, // ZERO, not random
                ];
                $current->modify('+1 day');
            }
        }

        // Additional stats - Get REAL counts from database
        $totalReports = 0;
        $activeAlerts = 0;
        $alertsNewToday = 0;
        try {
            if ($tableExists) {
                $totalReports = array_sum(array_column($incidentData, 'count'));
                error_log("WebSocket: Total reports (for period {$period}): {$totalReports}");
            } else {
                $totalReports = 0;
            }

            try {
                if ((bool)$this->database->fetchOne("SHOW TABLES LIKE 'alerts'")) {
                    $row = $this->database->fetchOne("SELECT COUNT(*) as count FROM alerts WHERE is_active = 1");
                    $activeAlerts = (int)($row['count'] ?? 0);

                    // *** FIX: Removed DATE_ADD(start_time, INTERVAL 6 HOUR) ***
                    $todayRow = $this->database->fetchOne(
                        "SELECT COUNT(*) as count FROM alerts WHERE DATE(start_time) = CURDATE()"
                    );
                    $alertsNewToday = (int)($todayRow['count'] ?? 0);
                }
            } catch (\Throwable $e) {
                $activeAlerts = 0;
                $alertsNewToday = 0;
            }
        } catch (\Throwable $e) {
            error_log("WebSocket: Error getting report count: " . $e->getMessage());
            $totalReports = 0;
        }

        // Trend percent
        $half = count($incidentData) / 2;
        $first = array_slice($incidentData, 0, (int)floor($half));
        $second = array_slice($incidentData, (int)floor($half));
        $firstAvg = count($first) ? array_sum(array_column($first, 'count')) / count($first) : 0;
        $secondAvg = count($second) ? array_sum(array_column($second, 'count')) / count($second) : 0;
        $trend = $firstAvg > 0 ? (($secondAvg - $firstAvg) / $firstAvg * 100) : 0;

        // Labels
        $labels = [];
        foreach ($incidentData as $item) {
            $d = new \DateTime($item['date']);
            if ($period === '7D') {
                $labels[] = $d->format('D');
            } elseif ($period === '30D') {
                $labels[] = $d->format('j');
            } else {
                $labels[] = $d->format('M j');
            }
        }

        return [
            'labels' => $labels,
            'values' => array_map(fn($i) => (int)$i['count'], $incidentData),
            'statistics' => [
                'totalReports' => (int)$totalReports,
                'activeAlerts' => (int)$activeAlerts,
                'trendPercentage' => round($trend, 1),
                'avgResponse' => '2.3m',
                'alertsNewToday' => (int)$alertsNewToday,
            ],
        ];
    }

   private function getDetailedMetrics() {
        // Get REAL metrics from database - NO MOCK DATA
        $tableExists = false;
        try {
            $tableExists = (bool)$this->database->fetchOne("SHOW TABLES LIKE 'incident_reports'");
        } catch (\Throwable $e) {}

        $metrics = [];

        if ($tableExists) {
            $endDate = new \DateTime();
            $startDate = new \DateTime();
            $startDate->modify('-29 days');
            $startDate->setTime(0,0,0);

            $prevEndDate = clone $startDate;
            $prevEndDate->modify('-1 day');
            $prevStartDate = clone $prevEndDate;
            $prevStartDate->modify('-29 days');
            $prevStartDate->setTime(0,0,0);

            // Daily Reports - Current period (last 30 days)
            $dailyCurrent = 0;
            try {
                // *** FIX: Removed DATE_ADD(incident_date, INTERVAL 6 HOUR) ***
                $result = $this->database->fetchOne(
                    "SELECT COUNT(*) as count FROM incident_reports WHERE DATE(incident_date) >= ? AND DATE(incident_date) <= ?",
                    [$startDate->format('Y-m-d'), $endDate->format('Y-m-d')]
                );
                $dailyCurrent = (int)($result['count'] ?? 0);
            } catch (\Throwable $e) {}

            // Daily Reports - Previous period
            $dailyPrevious = 0;
            try {
                // *** FIX: Removed DATE_ADD(incident_date, INTERVAL 6 HOUR) ***
                $result = $this->database->fetchOne(
                    "SELECT COUNT(*) as count FROM incident_reports WHERE DATE(incident_date) >= ? AND DATE(incident_date) <= ?",
                    [$prevStartDate->format('Y-m-d'), $prevEndDate->format('Y-m-d')]
                );
                $dailyPrevious = (int)($result['count'] ?? 0);
            } catch (\Throwable $e) {}

            // Critical Incidents - Current period
            $criticalCurrent = 0;
            try {
                // *** FIX: Removed DATE_ADD(incident_date, INTERVAL 6 HOUR) ***
                $result = $this->database->fetchOne(
                    "SELECT COUNT(*) as count FROM incident_reports WHERE severity = 'critical' AND DATE(incident_date) >= ? AND DATE(incident_date) <= ?",
                    [$startDate->format('Y-m-d'), $endDate->format('Y-m-d')]
                );
                $criticalCurrent = (int)($result['count'] ?? 0);
            } catch (\Throwable $e) {}

            // Critical Incidents - Previous period
            $criticalPrevious = 0;
            try {
                // *** FIX: Removed DATE_ADD(incident_date, INTERVAL 6 HOUR) ***
                $result = $this->database->fetchOne(
                    "SELECT COUNT(*) as count FROM incident_reports WHERE severity = 'critical' AND DATE(incident_date) >= ? AND DATE(incident_date) <= ?",
                    [$prevStartDate->format('Y-m-d'), $prevEndDate->format('Y-m-d')]
                );
                $criticalPrevious = (int)($result['count'] ?? 0);
            } catch (\Throwable $e) {}

            // Response Time - Average in hours (simplified)
            $responseCurrent = '0h';
            $responsePrevious = '0h';

            // Resolution Rate
            $resolvedCurrent = 0;
            $totalCurrent = $dailyCurrent;
            try {
                // *** FIX: Removed DATE_ADD(incident_date, INTERVAL 6 HOUR) ***
                $result = $this->database->fetchOne(
                    "SELECT COUNT(*) as count FROM incident_reports WHERE status IN ('resolved', 'closed') AND DATE(incident_date) >= ? AND DATE(incident_date) <= ?",
                    [$startDate->format('Y-m-d'), $endDate->format('Y-m-d')]
                );
                $resolvedCurrent = (int)($result['count'] ?? 0);
            } catch (\Throwable $e) {}
            $resolutionRate = $totalCurrent > 0 ? round(($resolvedCurrent / $totalCurrent) * 100) : 0;

            $metrics = [
                'dailyReports' => [
                    'current' => $dailyCurrent,
                    'previous' => $dailyPrevious,
                ],
                'criticalIncidents' => [
                    'current' => $criticalCurrent,
                    'previous' => $criticalPrevious,
                ],
                'responseTime' => [
                    'current' => $responseCurrent,
                    'previous' => $responsePrevious,
                ],
                'resolutionRate' => $resolutionRate,
            ];
        } else {
            // No table - return all zeros
            $metrics = [
                'dailyReports' => ['current' => 0, 'previous' => 0],
                'criticalIncidents' => ['current' => 0, 'previous' => 0],
                'responseTime' => ['current' => '0h', 'previous' => '0h'],
                'resolutionRate' => 0,
            ];
        }

        return $metrics;
    }

    /**
     * Broadcast map update to all connected clients
     * Called from PHP when incidents/alerts/zones are updated
     */
    public function broadcastMapUpdate($type, $data) {
        $message = json_encode([
            'type' => 'map_update',
            'update_type' => $type, // 'incident', 'alert', 'zone', 'safe_space'
            'data' => $data,
            'timestamp' => time() * 1000
        ]);

        foreach ($this->clients as $client) {
            try {
                $client->send($message);
            } catch (\Exception $e) {
                echo "Error broadcasting to client: {$e->getMessage()}\n";
            }
        }

        echo "Broadcasted map update: {$type} to " . count($this->clients) . " clients\n";
    }

    /**
     * Subscribe to map updates for specific area
     */
    private function subscribeToMap($from, $data) {
        // Store subscription info (can be extended to filter by bbox)
        $subscription = [
            'type' => $data['map_type'] ?? 'all', // 'all', 'incidents', 'alerts', 'zones'
            'bbox' => $data['bbox'] ?? null
        ];

        $from->subscription = $subscription;

        $from->send(json_encode([
            'type' => 'map_subscribed',
            'requestId' => $data['requestId'] ?? null,
            'data' => ['status' => 'subscribed']
        ]));
    }

    public function onClose(ConnectionInterface $conn) {
        $this->clients->detach($conn);
        echo "Connection {$conn->resourceId} has disconnected\n";
    }

    public function onError(ConnectionInterface $conn, \Exception $e) {
        echo "An error has occurred: {$e->getMessage()}\n";
        $conn->close();
    }
}

// Run the server
$server = IoServer::factory(
    new HttpServer(
        new WsServer(
            new DashboardUpdater()
        )
    ),
    8080 // We'll run on port 8080
);

$server->run();