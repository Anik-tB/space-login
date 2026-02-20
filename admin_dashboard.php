<?php
session_start();

// Handle AJAX requests for chart data refresh
if (isset($_GET['ajax']) && $_GET['ajax'] == '1') {
    header('Content-Type: application/json');
require_once __DIR__ . '/includes/Database.php';

    try {
$database = new Database();
        $severityCounts = [];
        $categoryCounts = [];
        $dailyTrend = [];
        $alertSeverity = [];

        if ($database->tableExists('incident_reports')) {
            $severityCountsRows = $database->fetchAll(
                "SELECT severity, COUNT(*) as count FROM incident_reports GROUP BY severity"
            );
            foreach ($severityCountsRows as $row) {
                $severityCounts[$row['severity']] = (int)$row['count'];
            }

            $categoryCountsRows = $database->fetchAll(
                "SELECT category, COUNT(*) as count
                 FROM incident_reports
                 GROUP BY category
                 ORDER BY count DESC
                 LIMIT 8"
            );
            foreach ($categoryCountsRows as $row) {
                $categoryCounts[$row['category']] = (int)$row['count'];
            }

            $dailyTrendRows = $database->fetchAll(
                "SELECT DATE(reported_date) as day,
                        COUNT(*) as total,
                        SUM(status IN ('resolved','closed')) as resolved,
                        SUM(severity = 'critical') as critical
                 FROM incident_reports
                 WHERE reported_date >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
                 GROUP BY DATE(reported_date)
                 ORDER BY day ASC"
            );
            foreach ($dailyTrendRows as $row) {
                $dailyTrend[] = [
                    'day'      => $row['day'],
                    'total'    => (int)$row['total'],
                    'resolved' => (int)$row['resolved'],
                    'critical' => (int)$row['critical'],
                ];
            }
        }

        if ($database->tableExists('alerts')) {
            $alertSeverityRows = $database->fetchAll(
                "SELECT severity, COUNT(*) as count FROM alerts GROUP BY severity"
            );
            foreach ($alertSeverityRows as $row) {
                $alertSeverity[$row['severity']] = (int)$row['count'];
            }
        }

        echo json_encode([
            'success' => true,
            'chartData' => [
                'severity'    => !empty($severityCounts) ? $severityCounts : ['low' => 0, 'medium' => 0, 'high' => 0, 'critical' => 0],
                'categories'  => !empty($categoryCounts) ? $categoryCounts : [],
                'trend'       => !empty($dailyTrend) ? $dailyTrend : [],
                'alerts'      => !empty($alertSeverity) ? $alertSeverity : [],
            ],
            'timestamp' => date('Y-m-d H:i:s')
        ]);
    } catch (Exception $e) {
        echo json_encode([
            'success' => false,
            'error' => $e->getMessage()
        ]);
    }
    exit;
}

require_once __DIR__ . '/includes/Database.php';

// STRICT ADMIN AUTHENTICATION - Only allow users with is_admin = 1
$userId = $_SESSION['user_id'] ?? null;

// Check if user is logged in and has admin session flag
if (!$userId || !isset($_SESSION['is_admin']) || $_SESSION['is_admin'] !== true) {
    session_destroy();
    header('Location: admin_login.php?error=access_denied');
    exit;
}

$database = new Database();

// Verify user exists and is admin in database
$user = $database->fetchOne("SELECT id, email, display_name, is_admin FROM users WHERE id = ?", [$userId]);
if (!$user || $user['is_admin'] != 1) {
    // User is not admin or doesn't exist - destroy session and redirect
    session_destroy();
    header('Location: admin_login.php?error=not_authorized');
    exit;
}

// Set admin name in session for display
$_SESSION['admin_name'] = $user['display_name'] ?? $user['email'] ?? 'Admin';

$models   = new SafeSpaceModels($database);

$error             = null;
$metrics           = [];
$metrics['reports_last_24h']   = 0;
$metrics['reports_prev_24h']   = 0;
$metrics['reports_change_24h'] = 0;
$metrics['resolved_rate']      = 0;
$metrics['active_investigations'] = 0;
$metrics['public_reports']     = 0;
$metrics['public_ratio']       = 0;
$metrics['anonymous_ratio']    = 0;
$metrics['sla_met_percent']    = 0;
$metrics['critical_backlog']   = 0;
$metrics['avg_witness']        = 0;
$metrics['avg_response_time']  = 0.0;
$statusCounts      = [];
$severityCounts    = [];
$categoryCounts    = [];
$recentReports     = [];
$recentAlerts      = [];
$disputeSnapshot   = [];
$topLocations      = [];
$activityFeed      = [];
$tableData         = [];
$tableTotal        = 0;
$dailyTrend        = [];
$alertSeverity     = [];
$systemDiagnostics = [];

$statusOptions   = ['all', 'pending', 'under_review', 'investigating', 'resolved', 'closed', 'disputed'];
$severityOptions = ['all', 'low', 'medium', 'high', 'critical'];

$statusFilter   = isset($_GET['status']) ? strtolower(trim($_GET['status'])) : 'all';
$severityFilter = isset($_GET['severity']) ? strtolower(trim($_GET['severity'])) : 'all';
$searchTerm     = isset($_GET['q']) ? trim($_GET['q']) : '';

if (!in_array($statusFilter, $statusOptions, true)) {
    $statusFilter = 'all';
}

if (!in_array($severityFilter, $severityOptions, true)) {
    $severityFilter = 'all';
}

function percentage($part, $total, $decimals = 1) {
    if ($total <= 0) {
        return 0;
    }
    return round(($part / $total) * 100, $decimals);
}

try {
    // Test database connection first
    $testConnection = $database->getConnection();
    if (!$testConnection) {
        throw new Exception('Cannot establish database connection. Please check your database settings.');
    }

    $incidentTableExists = $database->tableExists('incident_reports');
    $alertsTableExists   = $database->tableExists('alerts');
    $usersTableExists    = $database->tableExists('users');
    $disputesTableExists = $database->tableExists('disputes');

    // --- Metrics ------------------------------------------------------------------

    $metrics['total_reports'] = $incidentTableExists
        ? (int)($database->fetchOne("SELECT COUNT(*) as count FROM incident_reports")['count'] ?? 0)
        : 0;

    $metrics['critical_pending'] = $incidentTableExists
        ? (int)($database->fetchOne(
            "SELECT COUNT(*) as count FROM incident_reports WHERE severity = 'critical' AND status IN ('pending','under_review','investigating')"
        )['count'] ?? 0)
        : 0;

    $metrics['resolved_this_month'] = $incidentTableExists
        ? (int)($database->fetchOne(
            "SELECT COUNT(*) as count
             FROM incident_reports
             WHERE status IN ('resolved','closed')
               AND reported_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)"
        )['count'] ?? 0)
        : 0;

    $metrics['avg_response_time'] = 0.0;

    $metrics['total_users'] = $usersTableExists
        ? (int)($database->fetchOne("SELECT COUNT(*) as count FROM users")['count'] ?? 0)
        : 0;

    $metrics['active_alerts'] = $alertsTableExists
        ? (int)($database->fetchOne("SELECT COUNT(*) as count FROM alerts WHERE is_active = 1")['count'] ?? 0)
        : 0;

    $metrics['open_disputes'] = $disputesTableExists
        ? (int)($database->fetchOne("SELECT COUNT(*) as count FROM disputes WHERE status IN ('pending','under_review')")['count'] ?? 0)
        : 0;

    // --- Distribution data -------------------------------------------------------

    if ($incidentTableExists) {
        $incidentColumns = [];
        try {
            $structureRows = $database->getTableStructure('incident_reports');
            foreach ($structureRows as $column) {
                if (isset($column['Field'])) {
                    $incidentColumns[$column['Field']] = true;
                }
            }
        } catch (Exception $e) {
            $incidentColumns = [];
        }

        $hasIsPublic       = isset($incidentColumns['is_public']);
        $hasIsAnonymous    = isset($incidentColumns['is_anonymous']);
        $hasResponseTime   = isset($incidentColumns['response_time_minutes']);
        $hasWitnessCount   = isset($incidentColumns['witness_count']);
        $hasAssignedColumn = isset($incidentColumns['assigned_to']);
        $hasUpdatedColumn  = isset($incidentColumns['updated_date']);
        $hasLocationColumn = isset($incidentColumns['location_name']);

        $reportsLast24 = (int)($database->fetchOne(
            "SELECT COUNT(*) as count FROM incident_reports WHERE reported_date >= DATE_SUB(NOW(), INTERVAL 1 DAY)"
        )['count'] ?? 0);

        $reportsPrev24 = (int)($database->fetchOne(
            "SELECT COUNT(*) as count
             FROM incident_reports
             WHERE reported_date >= DATE_SUB(NOW(), INTERVAL 2 DAY)
               AND reported_date < DATE_SUB(NOW(), INTERVAL 1 DAY)"
        )['count'] ?? 0);

        $resolvedTotal = (int)($database->fetchOne(
            "SELECT COUNT(*) as count FROM incident_reports WHERE status IN ('resolved','closed')"
        )['count'] ?? 0);

        $activeInvestigations = (int)($database->fetchOne(
            "SELECT COUNT(*) as count FROM incident_reports WHERE status IN ('under_review','investigating')"
        )['count'] ?? 0);

        $publicReports = 0;
        if ($hasIsPublic) {
            $publicReports = (int)($database->fetchOne(
                "SELECT COUNT(*) as count FROM incident_reports WHERE is_public = 1"
            )['count'] ?? 0);
        }

        $anonymousReports = 0;
        if ($hasIsAnonymous) {
            $anonymousReports = (int)($database->fetchOne(
                "SELECT COUNT(*) as count FROM incident_reports WHERE is_anonymous = 1"
            )['count'] ?? 0);
        }

        $slaMet = 0;
        if ($hasResponseTime) {
            $slaMet = (int)($database->fetchOne(
                "SELECT COUNT(*) as count FROM incident_reports WHERE response_time_minutes IS NOT NULL AND response_time_minutes <= 45"
            )['count'] ?? 0);
        }

        $criticalBacklog = (int)($database->fetchOne(
            "SELECT COUNT(*) as count
             FROM incident_reports
             WHERE severity = 'critical'
               AND status IN ('pending','under_review','investigating','disputed')"
        )['count'] ?? 0);

        $avgWitnessCount = 0.0;
        if ($hasWitnessCount) {
            $avgWitnessCount = (float)($database->fetchOne(
                "SELECT AVG(witness_count) as avg FROM incident_reports WHERE witness_count IS NOT NULL"
            )['avg'] ?? 0);
        }

        $metrics['reports_last_24h']    = $reportsLast24;
        $metrics['reports_prev_24h']    = $reportsPrev24;
        $metrics['reports_change_24h']  = $reportsPrev24 > 0
            ? round((($reportsLast24 - $reportsPrev24) / $reportsPrev24) * 100, 1)
            : ($reportsLast24 > 0 ? 100 : 0);
        $metrics['resolved_rate']       = percentage($resolvedTotal, $metrics['total_reports'] ?? 0);
        $metrics['active_investigations'] = $activeInvestigations;
        $metrics['public_reports']      = $publicReports;
        if ($publicReports > 0) {
            $metrics['public_ratio'] = percentage($publicReports, $metrics['total_reports'] ?? 0);
        }
        if ($anonymousReports > 0) {
            $metrics['anonymous_ratio'] = percentage($anonymousReports, $metrics['total_reports'] ?? 0);
        }
        if ($hasResponseTime && $slaMet > 0) {
            $metrics['sla_met_percent'] = percentage($slaMet, $metrics['total_reports'] ?? 0);
        }
        $metrics['critical_backlog']    = $criticalBacklog;
        $metrics['avg_witness']         = round($avgWitnessCount, 1);
        if ($hasResponseTime) {
            $metrics['avg_response_time'] = round((float)($database->fetchOne(
                "SELECT AVG(response_time_minutes) as avg FROM incident_reports WHERE response_time_minutes IS NOT NULL"
            )['avg'] ?? 0), 1);
        }

        $statusCountsRows = $database->fetchAll(
            "SELECT status, COUNT(*) as count FROM incident_reports GROUP BY status"
        );
        foreach ($statusCountsRows as $row) {
            $statusCounts[$row['status']] = (int)$row['count'];
        }

        $severityCountsRows = $database->fetchAll(
            "SELECT severity, COUNT(*) as count FROM incident_reports GROUP BY severity"
        );
        foreach ($severityCountsRows as $row) {
            $severityCounts[$row['severity']] = (int)$row['count'];
        }

        $categoryCountsRows = $database->fetchAll(
            "SELECT category, COUNT(*) as count
             FROM incident_reports
             GROUP BY category
             ORDER BY count DESC
             LIMIT 8"
        );
        foreach ($categoryCountsRows as $row) {
            $categoryCounts[$row['category']] = (int)$row['count'];
        }

        if ($hasLocationColumn) {
            $topLocationsRows = $database->fetchAll(
                "SELECT location_name, COUNT(*) as count
                 FROM incident_reports
                 WHERE location_name IS NOT NULL AND location_name <> ''
                 GROUP BY location_name
                 ORDER BY count DESC
                 LIMIT 6"
            );
            foreach ($topLocationsRows as $row) {
                $topLocations[] = [
                    'location' => $row['location_name'],
                    'count'    => (int)$row['count'],
                ];
            }
        }

        $dailyTrendRows = $database->fetchAll(
            "SELECT DATE(reported_date) as day,
                    COUNT(*) as total,
                    SUM(status IN ('resolved','closed')) as resolved,
                    SUM(severity = 'critical') as critical
             FROM incident_reports
             WHERE reported_date >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
             GROUP BY DATE(reported_date)
             ORDER BY day ASC"
        );
        foreach ($dailyTrendRows as $row) {
            $dailyTrend[] = [
                'day'      => $row['day'],
                'total'    => (int)$row['total'],
                'resolved' => (int)$row['resolved'],
                'critical' => (int)$row['critical'],
            ];
        }

        $recentReports = $database->fetchAll(
            "SELECT id, title, category, severity, status, reported_date, location_name, reporter_name, reporter_email
             FROM vw_active_incidents_with_user
             ORDER BY reported_date DESC
             LIMIT 8"
        );

        // Activity feed: last updates
        if ($hasAssignedColumn && $hasUpdatedColumn) {
            $activityFeed = $database->fetchAll(
                "SELECT ir.title, ir.status, ir.updated_date, u.display_name
                 FROM incident_reports ir
                 LEFT JOIN users u ON u.id = ir.assigned_to
                 ORDER BY ir.updated_date DESC
                 LIMIT 6"
            );
        }
    }

    if ($alertsTableExists) {
        $recentAlerts = $database->fetchAll(
            "SELECT id, title, severity, type, start_time, is_active
             FROM alerts
             ORDER BY start_time DESC
             LIMIT 5"
        );

        $alertSeverityRows = $database->fetchAll(
            "SELECT severity, COUNT(*) as count
             FROM alerts
             GROUP BY severity"
        );
        foreach ($alertSeverityRows as $row) {
            $alertSeverity[$row['severity']] = (int)$row['count'];
        }
    }

    if ($disputesTableExists) {
        $disputeSnapshot = $database->fetchAll(
            "SELECT status, COUNT(*) as count
             FROM disputes
             GROUP BY status"
        );
    }

    if ($usersTableExists) {
        $verifiedUsers = (int)($database->fetchOne(
            "SELECT COUNT(*) as count FROM users WHERE email_verified = 1"
        )['count'] ?? 0);
        $activeUsers30 = (int)($database->fetchOne(
            "SELECT COUNT(*) as count FROM users WHERE last_login >= DATE_SUB(NOW(), INTERVAL 30 DAY)"
        )['count'] ?? 0);

        $metrics['verified_users']      = $verifiedUsers;
        $metrics['verification_rate']   = percentage($verifiedUsers, $metrics['total_users'] ?? 0);
        $metrics['active_users_30']     = $activeUsers30;
    }

    try {
        $systemDiagnostics = $database->getDatabaseStats();
    } catch (Exception $e) {
        $systemDiagnostics = [];
    }

    // --- Tabular data for detailed view -----------------------------------------

    if ($incidentTableExists) {
        $tableSql    = "SELECT * FROM vw_active_incidents_with_user WHERE 1=1";
        $tableParams = [];

        if ($statusFilter !== 'all') {
            $tableSql      .= " AND status = ?";
            $tableParams[] = $statusFilter;
        }

        if ($severityFilter !== 'all') {
            $tableSql      .= " AND severity = ?";
            $tableParams[] = $severityFilter;
        }

        if ($searchTerm !== '') {
            $likeTerm       = '%' . $searchTerm . '%';
            $tableSql      .= " AND (title LIKE ? OR location_name LIKE ? OR reporter_email LIKE ?)";
            $tableParams[]  = $likeTerm;
            $tableParams[]  = $likeTerm;
            $tableParams[]  = $likeTerm;
        }

        $tableSql .= " ORDER BY reported_date DESC LIMIT ?";
        $tableParams[] = 25;

        $tableData = $database->fetchAll($tableSql, $tableParams);

        // Total for filters overview
        // Total for filters overview
        $countSql    = "SELECT COUNT(*) as count FROM vw_active_incidents_with_user WHERE 1=1";
        $countParams = [];

        if ($statusFilter !== 'all') {
            $countSql      .= " AND status = ?";
            $countParams[]  = $statusFilter;
        }
        if ($severityFilter !== 'all') {
            $countSql      .= " AND severity = ?";
            $countParams[]  = $severityFilter;
        }
        if ($searchTerm !== '') {
            if ($statusFilter === 'all' || $severityFilter === 'all') {
                // reuse same condition to keep parameters aligned
            }
            $likeTerm       = '%' . $searchTerm . '%';
            $countSql      .= " AND (title LIKE ? OR location_name LIKE ? OR reporter_email LIKE ?)";
            $countParams[]  = $likeTerm;
            $countParams[]  = $likeTerm;
            $countParams[]  = $likeTerm;
        }

        $tableTotal = (int)($database->fetchOne($countSql, $countParams)['count'] ?? 0);
    }

    // --- Pending Items for Approval -----------------------------------------
    $pendingReports = [];
    $pendingGroups = [];
    $pendingDisputes = [];
    $unverifiedLegalProviders = [];
    $unverifiedMedicalProviders = [];
    $pendingAlerts = [];
    $pendingSafeSpaces = [];

    // Pending incident reports
    if ($incidentTableExists) {
        $pendingReports = $database->fetchAll(
            "SELECT *, reporter_email as email, reporter_name as display_name
             FROM vw_active_incidents_with_user
             WHERE status = 'pending'
             ORDER BY reported_date DESC
             LIMIT 20"
        );
    }

    // Pending community groups
    if ($database->tableExists('neighborhood_groups')) {
        $pendingGroups = $database->fetchAll(
            "SELECT ng.*, u.email, u.display_name
             FROM neighborhood_groups ng
             LEFT JOIN users u ON u.id = ng.created_by
             WHERE ng.status = 'pending_approval'
             ORDER BY ng.created_at DESC
             LIMIT 20"
        );
    }

    // Pending disputes
    if ($disputesTableExists) {
        $pendingDisputes = $database->fetchAll(
            "SELECT d.*, u.email, u.display_name, ir.title as report_title
             FROM disputes d
             LEFT JOIN users u ON u.id = d.user_id
             LEFT JOIN incident_reports ir ON ir.id = d.report_id
             WHERE d.status = 'pending'
             ORDER BY d.created_at DESC
             LIMIT 20"
        );
    }

    // Unverified legal providers
    if ($database->tableExists('legal_aid_providers')) {
        $unverifiedLegalProviders = $database->fetchAll(
            "SELECT * FROM legal_aid_providers
             WHERE is_verified = 0 OR is_verified IS NULL
             ORDER BY id DESC
             LIMIT 20"
        );
    }

    // Unverified medical providers
    if ($database->tableExists('medical_support_providers')) {
        $unverifiedMedicalProviders = $database->fetchAll(
            "SELECT * FROM medical_support_providers
             WHERE is_verified = 0 OR is_verified IS NULL
             ORDER BY id DESC
             LIMIT 20"
        );
    }

    // Pending alerts
    if ($alertsTableExists) {
        $pendingAlerts = $database->fetchAll(
            "SELECT * FROM alerts
             WHERE is_active = 0
             ORDER BY start_time DESC
             LIMIT 20"
        );
    }

    // Pending safe spaces (using 'pending_verification' to match database enum)
    if ($database->tableExists('safe_spaces')) {
        $pendingSafeSpaces = $database->fetchAll(
            "SELECT * FROM safe_spaces
             WHERE status = 'pending_verification' OR status IS NULL
             ORDER BY id DESC
             LIMIT 20"
        );
    }

} catch (Exception $e) {
    $error = 'Database operation failed: ' . $e->getMessage();
    error_log('Admin Dashboard Error: ' . $e->getMessage() . ' | Trace: ' . $e->getTraceAsString());
}

// Ensure chart data always has structure, even if empty
$chartPayload = [
    'severity'    => !empty($severityCounts) ? $severityCounts : ['low' => 0, 'medium' => 0, 'high' => 0, 'critical' => 0],
    'categories'  => !empty($categoryCounts) ? $categoryCounts : [],
    'status'      => !empty($statusCounts) ? $statusCounts : [],
    'locations'   => !empty($topLocations) ? $topLocations : [],
    'trend'       => !empty($dailyTrend) ? $dailyTrend : [],
    'alerts'      => !empty($alertSeverity) ? $alertSeverity : [],
];

$tableSizes   = $systemDiagnostics['table_sizes'] ?? [];
$recordCounts = $systemDiagnostics['record_counts'] ?? [];

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Government Command Console | SafeSpace</title>

    <!-- Favicon -->
    <link rel="icon" type="image/svg+xml" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><text y='.9em' font-size='90'>🛡️</text></svg>">

    <!-- Modern Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&family=Poppins:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">

    <!-- Modern Icons -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/lucide/0.263.1/lucide.min.css">

    <!-- Chart.js for interactive charts -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

    <!-- ApexCharts for advanced visualizations -->
    <script src="https://cdn.jsdelivr.net/npm/apexcharts"></script>

    <!-- TailwindCSS for rapid styling -->
    <script src="https://cdn.tailwindcss.com"></script>

    <!-- Design System -->
    <link rel="stylesheet" href="design-system.css">

    <!-- Enhanced Dashboard Styles -->
    <link rel="stylesheet" href="dashboard-styles.css">
    <link rel="stylesheet" href="admin-dashboard.css">

    <script>
        tailwind.config = {
            theme: {
                extend: {
                    colors: {
                        primary: {
                            50: '#f0f9ff',
                            100: '#e0f2fe',
                            200: '#bae6fd',
                            300: '#7dd3fc',
                            400: '#38bdf8',
                            500: '#0ea5e9',
                            600: '#0284c7',
                            700: '#0369a1',
                            800: '#075985',
                            900: '#0c4a6e',
                        },
                        secondary: {
                            50: '#fdf4ff',
                            100: '#fae8ff',
                            200: '#f5d0fe',
                            300: '#f0abfc',
                            400: '#e879f9',
                            500: '#d946ef',
                            600: '#c026d3',
                            700: '#a21caf',
                            800: '#86198f',
                            900: '#701a75',
                        },
                        accent: {
                            50: '#f0fdf4',
                            100: '#dcfce7',
                            200: '#bbf7d0',
                            300: '#86efac',
                            400: '#4ade80',
                            500: '#22c55e',
                            600: '#16a34a',
                            700: '#15803d',
                            800: '#166534',
                            900: '#14532d',
                        },
                        neutral: {
                            50: '#fafafa',
                            100: '#f5f5f5',
                            200: '#e5e5e5',
                            300: '#d4d4d4',
                            400: '#a3a3a3',
                            500: '#737373',
                            600: '#525252',
                            700: '#404040',
                            800: '#262626',
                            900: '#171717',
                        },
                        error: {
                            50: '#fef2f2',
                            100: '#fee2e2',
                            200: '#fecaca',
                            300: '#fca5a5',
                            400: '#f87171',
                            500: '#ef4444',
                            600: '#dc2626',
                            700: '#b91c1c',
                            800: '#991b1b',
                            900: '#7f1d1d',
                        }
                    },
                    fontFamily: {
                        'sans': ['Inter', 'system-ui', 'sans-serif'],
                        'display': ['Poppins', 'system-ui', 'sans-serif'],
                    },
                    animation: {
                        'fade-in': 'fadeIn 0.3s ease-out',
                        'slide-up': 'slideUp 0.25s ease-out',
                        'scale-in': 'scaleIn 0.15s ease-out',
                        'bounce-gentle': 'bounceGentle 2s infinite',
                    },
                    keyframes: {
                        fadeIn: {
                            '0%': { opacity: '0' },
                            '100%': { opacity: '1' },
                        },
                        slideUp: {
                            '0%': { transform: 'translateY(15px)', opacity: '0' },
                            '100%': { transform: 'translateY(0)', opacity: '1' },
                        },
                        scaleIn: {
                            '0%': { transform: 'scale(0.98)', opacity: '0' },
                            '100%': { transform: 'scale(1)', opacity: '1' },
                        },
                        bounceGentle: {
                            '0%, 100%': { transform: 'translateY(0)' },
                            '50%': { transform: 'translateY(-3px)' },
                        }
                    }
                }
            }
        }
    </script>
    <style>
        :root {
            --admin-bg-light: #ffffff;
            --admin-bg-dark: #0f172a;
            --admin-text-light: #1e293b;
            --admin-text-dark: #f1f5f9;
            --admin-card-light: #ffffff;
            --admin-card-dark: #1e293b;
            --admin-border-light: #e2e8f0;
            --admin-border-dark: #334155;
            --md-elevation-1: 0 1px 3px 0 rgb(0 0 0 / 0.1), 0 1px 2px -1px rgb(0 0 0 / 0.1);
            --md-elevation-2: 0 4px 6px -1px rgb(0 0 0 / 0.1), 0 2px 4px -2px rgb(0 0 0 / 0.1);
            --md-elevation-3: 0 10px 15px -3px rgb(0 0 0 / 0.1), 0 4px 6px -4px rgb(0 0 0 / 0.1);
            --md-elevation-4: 0 20px 25px -5px rgb(0 0 0 / 0.1), 0 8px 10px -6px rgb(0 0 0 / 0.1);
        }

        /* Glassmorphism effect */
        .glass {
            background: rgba(255, 255, 255, 0.1);
            backdrop-filter: blur(20px);
            border: 1px solid rgba(255, 255, 255, 0.2);
        }

        /* Smooth scrolling */
        html {
            scroll-behavior: smooth;
        }

        body.admin-body {
            background: var(--admin-bg-light);
            color: var(--admin-text-light);
            transition: background 0.3s ease, color 0.3s ease;
            font-family: 'Inter', system-ui, sans-serif;
        }

        body.admin-body.dark-theme {
            background: var(--admin-bg-dark);
            color: var(--admin-text-dark);
        }

        .theme-toggle {
            position: relative;
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: linear-gradient(135deg, #6366f1, #8b5cf6);
            border: 2px solid rgba(255, 255, 255, 0.2);
            color: white;
            cursor: pointer;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 18px;
            transition: all 0.3s ease;
            flex-shrink: 0;
        }

        .theme-toggle:hover {
            transform: scale(1.08);
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
        }

        .theme-toggle:active {
            transform: scale(0.95);
        }

        /* Ensure header-actions displays theme toggle properly */
        .header-actions {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        /* Responsive adjustments */
        @media (max-width: 768px) {
            .theme-toggle {
                width: 36px;
                height: 36px;
                font-size: 16px;
            }
        }
    </style>
    <script>
        window.SAFE_SPACE_ADMIN_DATA = <?=
            json_encode($chartPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_NUMERIC_CHECK);
        ?>;
    </script>
    <script>
        // Theme Toggle Functionality
        document.addEventListener('DOMContentLoaded', function() {
            const themeToggle = document.getElementById('themeToggle');
            const adminBody = document.getElementById('adminBody');

            if (!themeToggle || !adminBody) return;

            // Load saved theme
            const savedTheme = localStorage.getItem('adminTheme') || 'light';
            if (savedTheme === 'dark') {
                adminBody.classList.add('dark-theme');
                themeToggle.textContent = '☀️';
                themeToggle.title = 'Switch to light mode';
                themeToggle.setAttribute('aria-label', 'Switch to light mode');
            } else {
                themeToggle.textContent = '🌙';
                themeToggle.title = 'Switch to dark mode';
                themeToggle.setAttribute('aria-label', 'Switch to dark mode');
            }

            // Toggle theme
            themeToggle.addEventListener('click', function(e) {
                e.preventDefault();
                e.stopPropagation();

                adminBody.classList.toggle('dark-theme');
                const isDark = adminBody.classList.contains('dark-theme');

                themeToggle.textContent = isDark ? '☀️' : '🌙';
                themeToggle.title = isDark ? 'Switch to light mode' : 'Switch to dark mode';
                themeToggle.setAttribute('aria-label', isDark ? 'Switch to light mode' : 'Switch to dark mode');
                localStorage.setItem('adminTheme', isDark ? 'dark' : 'light');

                // Update charts if they exist
                if (window.updateChartsTheme) {
                    window.updateChartsTheme(isDark);
                }
            });
        });
    </script>
    <script src="admin-dashboard.js" defer></script>
    <script src="js/admin-alert-management.js" defer></script>
</head>
<body class="admin-body" id="adminBody">
<a href="#main-content" class="skip-link">Skip to main content</a>
<div class="dashboard-shell">
    <aside class="dashboard-nav" aria-label="Admin navigation">
        <div class="dashboard-nav__brand">
            <span class="brand-mark" aria-hidden="true">🛡️</span>
            <div>
                <strong>SafeSpace</strong>
                <small>Command Suite</small>
            </div>
        </div>
        <nav class="dashboard-nav__links">
            <a class="nav-item active" href="admin_dashboard.php">
                <span class="nav-item__icon" aria-hidden="true">📊</span> Overview
            </a>
            <a class="nav-item" href="admin_view_all_alerts.php">
                <span class="nav-item__icon" aria-hidden="true">🚨</span> All Alerts
            </a>
            <a class="nav-item" href="dashboard.php">
                <span class="nav-item__icon" aria-hidden="true">👥</span> Citizen Portal
            </a>
            <a class="nav-item" href="report_incident.php">
                <span class="nav-item__icon" aria-hidden="true">📝</span> Intake Desk
            </a>
            <a class="nav-item" href="dispute_center.php">
                <span class="nav-item__icon" aria-hidden="true">⚖️</span> Disputes
            </a>
            <a class="nav-item" href="safety_resources.php">
                <span class="nav-item__icon" aria-hidden="true">📚</span> Resources
            </a>
            <a class="nav-item nav-item--logout" href="logout.php" data-confirm-logout="1">
                <span class="nav-item__icon" aria-hidden="true">🚪</span> Logout
            </a>
        </nav>
        <div class="dashboard-nav__meta">
            <span class="dashboard-nav__status-line">
                <span class="status-dot" aria-hidden="true"></span>
                <span>Operational</span>
            </span>
            <small>Data as of <?= date('d M Y, H:i') ?></small>
        </div>
    </aside>
    <main id="main-content" class="dashboard-main" role="main">
        <header class="dashboard-header">
            <div class="header-title">
                <h1>National Safety Dashboard</h1>
                <span class="header-subtitle">Live insights for rapid response</span>
            </div>
            <div class="header-actions">
                <form method="get" action="admin_dashboard.php#incident-registry" class="header-search" role="search">
                    <input type="search" placeholder="Search reports by title, location, or citizen..." name="q" value="<?= htmlspecialchars($searchTerm ?? '', ENT_QUOTES, 'UTF-8'); ?>" aria-label="Search reports" />
                    <button type="submit" class="ghost-btn header-search-btn" title="Search">Search</button>
                </form>
                <button type="button" class="theme-toggle" id="themeToggle" title="Toggle dark mode" aria-label="Toggle dark mode">🌙</button>
                <div class="header-user" role="presentation">
                    <span class="user-avatar" aria-hidden="true"><?= strtoupper(substr($_SESSION['admin_name'] ?? 'Gov', 0, 1)); ?></span>
                    <span class="user-name"><?= htmlspecialchars($_SESSION['admin_name'] ?? 'Control Officer', ENT_QUOTES, 'UTF-8'); ?></span>
                </div>
            </div>
        </header>

        <div class="dashboard-toolbar" aria-live="polite">
            <nav class="breadcrumb" aria-label="Breadcrumb">
                <ol class="breadcrumb__list">
                    <li class="breadcrumb__item"><a href="admin_dashboard.php">Dashboard</a></li>
                    <li class="breadcrumb__item breadcrumb__item--current" aria-current="page">Overview</li>
                </ol>
            </nav>
            <div class="data-freshness">
                <span class="data-freshness__label">Data updated</span>
                <time datetime="<?= date('c'); ?>" class="data-freshness__time"><?= date('d M Y, H:i'); ?></time>
            </div>
        </div>

        <?php if ($error): ?>
            <div class="alert-banner" style="background: #fee2e2; color: #b91c1c; padding: 16px; border-radius: 8px; margin-bottom: 20px; border: 1px solid #fecaca;">
                <strong>⚠️ Error:</strong> <?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?>
                <br><br>
                <small style="color: #991b1b;">
                    <strong>Possible causes:</strong><br>
                    • Database connection issue - Check if MySQL/XAMPP is running<br>
                    • Database name mismatch - Should be 'space_login'<br>
                    • Missing tables - Run the SQL file to create tables<br>
                    • Check error logs in XAMPP for more details
                </small>
            </div>
        <?php endif; ?>

        <section class="summary-strip">
            <a href="view_all_reports.php" class="summary-card summary-card--link" title="View all reports">
                <span class="summary-label">Total Reports</span>
                <strong><?= number_format($metrics['total_reports'] ?? 0); ?></strong>
                <small><?= number_format($metrics['reports_last_24h'] ?? 0); ?> past 24h</small>
            </a>
            <a href="admin_dashboard.php?severity=critical#incident-registry" class="summary-card summary-card--link" title="View critical backlog">
                <span class="summary-label">Critical Backlog</span>
                <strong><?= number_format($metrics['critical_backlog'] ?? 0); ?></strong>
                <small><?= number_format($metrics['active_investigations'] ?? 0); ?> in review</small>
            </a>
            <article class="summary-card">
                <span class="summary-label">Response Time</span>
                <strong><?= number_format($metrics['avg_response_time'] ?? 0, 1); ?>m</strong>
                <small><?= number_format($metrics['sla_met_percent'] ?? 0, 1); ?>% SLA</small>
            </article>
            <article class="summary-card">
                <span class="summary-label">Verified Citizens</span>
                <strong><?= number_format($metrics['total_users'] ?? 0); ?></strong>
                <small><?= number_format($metrics['verification_rate'] ?? 0, 1); ?>% verified</small>
            </article>
        </section>

        <section class="grid grid--xl">
            <article class="panel panel--wide">
                <header>
                    <h2>Activity (7 days)</h2>
                    <button type="button" data-trend-refresh class="ghost-btn">Refresh</button>
                </header>
                <canvas id="trendChart" height="200"></canvas>
            </article>
            <article class="panel">
                <header><h2>Severity Mix</h2></header>
                <canvas id="severityChart" height="180"></canvas>
            </article>
            <article class="panel">
                <header><h2>Top Categories</h2></header>
                <canvas id="categoryChart" height="180"></canvas>
            </article>
            <article class="panel">
                <header><h2>Alerts</h2></header>
                <canvas id="alertChart" height="180"></canvas>
            </article>
            <article class="panel">
                <header><h2>Hotspots</h2></header>
                <ul class="list-cards">
                    <?php if (!empty($topLocations)): ?>
                        <?php foreach ($topLocations as $location): ?>
                            <li>
                                <strong><?= htmlspecialchars($location['location'], ENT_QUOTES, 'UTF-8'); ?></strong>
                                <span><?= number_format($location['count']); ?></span>
                            </li>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <li class="list-empty">No data</li>
                    <?php endif; ?>
                </ul>
            </article>
        </section>

        <section class="grid">
            <article class="panel">
                <header class="panel-header-with-action">
                    <h2>Recent Reports</h2>
                    <a href="view_all_reports.php" class="feed-view-all-link">View All →</a>
                </header>
                <ul class="feed">
                    <?php if (!empty($recentReports)): ?>
                        <?php foreach (array_slice($recentReports, 0, 5) as $report): ?>
                            <li class="feed-item">
                                <span class="feed-pill severity-<?= htmlspecialchars($report['severity']); ?>"></span>
                                <div class="feed-body">
                                    <strong><?= htmlspecialchars(mb_strimwidth($report['title'], 0, 35, '…', 'UTF-8'), ENT_QUOTES, 'UTF-8'); ?></strong>
                                    <small><?= htmlspecialchars(ucwords(str_replace('_', ' ', $report['status'])), ENT_QUOTES, 'UTF-8'); ?> • <?= date('d M H:i', strtotime($report['reported_date'])); ?> • By: <?= htmlspecialchars($report['reporter_name'] ?: $report['reporter_email'] ?: 'Anonymous', ENT_QUOTES, 'UTF-8'); ?></small>
                                </div>
                                <a href="view_report.php?id=<?= (int)$report['id']; ?>" class="feed-btn-view" title="View full report">View</a>
                            </li>
                        <?php endforeach; ?>
                        <?php if (count($recentReports) > 5): ?>
                            <li class="feed-view-all-row">
                                <a href="view_all_reports.php" class="feed-view-all-link">View All <?= count($recentReports); ?> Reports →</a>
                            </li>
                        <?php endif; ?>
                    <?php else: ?>
                        <li class="list-empty">No recent reports</li>
                    <?php endif; ?>
                </ul>
            </article>
            <article class="panel">
                <header class="panel-header-with-action">
                    <h2>Alert Feed</h2>
                    <a href="admin_view_all_alerts.php" class="feed-view-all-link">View All →</a>
                </header>
                <ul class="feed">
                    <?php if (!empty($recentAlerts)): ?>
                        <?php foreach (array_slice($recentAlerts, 0, 5) as $alert): ?>
                            <li class="feed-item">
                                <span class="feed-pill alert-<?= strtolower($alert['severity']); ?>"></span>
                                <div class="feed-body">
                                    <strong><?= htmlspecialchars(mb_strimwidth($alert['title'], 0, 35, '…', 'UTF-8'), ENT_QUOTES, 'UTF-8'); ?></strong>
                                    <small><?= htmlspecialchars(ucwords($alert['severity']), ENT_QUOTES, 'UTF-8'); ?> • <?= date('d M H:i', strtotime($alert['start_time'])); ?></small>
                                </div>
                                <a href="admin_view_all_alerts.php" class="feed-btn-view" title="View in Alert Management">View</a>
                            </li>
                        <?php endforeach; ?>
                        <?php if (count($recentAlerts) > 5): ?>
                            <li class="feed-view-all-row">
                                <a href="admin_view_all_alerts.php" class="feed-view-all-link">View All <?= count($recentAlerts); ?> Alerts →</a>
                            </li>
                        <?php endif; ?>
                    <?php else: ?>
                        <li class="list-empty">No alerts</li>
                    <?php endif; ?>
                </ul>
                <?php if (!empty($disputeSnapshot)): ?>
                    <div class="mini-cards">
                        <?php foreach ($disputeSnapshot as $row): ?>
                            <div class="mini-card">
                                <span><?= htmlspecialchars(ucwords(str_replace('_', ' ', $row['status'])), ENT_QUOTES, 'UTF-8'); ?></span>
                                <strong><?= number_format($row['count']); ?></strong>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </article>
        </section>

        <!-- Alert Management - Full width for better screen use -->
        <section class="alert-management-section">
            <article class="panel alert-management-panel">
                <header class="alert-management-header">
                    <h2 class="alert-management-title">🚨 Alert Management</h2>
                    <div class="alert-management-actions">
                        <a href="admin_view_all_alerts.php" class="btn-view-all-alerts">View All →</a>
                        <button type="button" onclick="openCreateAlertModal()" class="btn-create-alert">
                            <span class="btn-create-alert-icon">➕</span> Create New Alert
                        </button>
                    </div>
                </header>
                <div id="alertManagementContainer" class="alert-management-container">
                    <p class="alert-management-loading">Loading alerts...</p>
                </div>
            </article>
        </section>

        <section id="incident-registry" class="panel panel--wide">
            <header>
                <h2>Incident Registry</h2>
                <form method="get" action="admin_dashboard.php#incident-registry" class="filter-set">
                    <select name="status">
                        <?php foreach ($statusOptions as $option): ?>
                            <option value="<?= $option; ?>" <?= $statusFilter === $option ? 'selected' : ''; ?>>
                                <?= ucwords(str_replace('_', ' ', $option)); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <select name="severity">
                        <?php foreach ($severityOptions as $option): ?>
                            <option value="<?= $option; ?>" <?= $severityFilter === $option ? 'selected' : ''; ?>>
                                <?= ucwords($option); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <input type="search" name="q" placeholder="Search" value="<?= htmlspecialchars($searchTerm, ENT_QUOTES, 'UTF-8'); ?>" />
                    <button type="submit" class="ghost-btn">Apply</button>
                    <?php if ($statusFilter !== 'all' || $severityFilter !== 'all' || $searchTerm !== ''): ?>
                        <a class="ghost-btn" href="admin_dashboard.php#incident-registry">Reset filters</a>
                    <?php endif; ?>
                </form>
            </header>
            <?php
            $displayCount = count($tableData);
            $shownCount = min(10, $displayCount);
            if ($displayCount > 0): ?>
            <div class="table-toolbar">
                <span class="table-toolbar__count">Showing <?= $shownCount; ?> of <?= $displayCount; ?> reports</span>
            </div>
            <?php endif; ?>
            <div class="table-wrap" style="max-height: 450px; overflow-y: auto;">
                <table class="data-table">
                    <thead>
                    <tr>
                        <th style="width: 60px;">ID</th>
                        <th style="width: 200px;">Title</th>
                        <th style="width: 120px;">Citizen</th>
                        <th style="width: 100px;">Category</th>
                        <th style="width: 90px;">Severity</th>
                        <th style="width: 110px;">Status</th>
                        <th style="width: 120px;">Location</th>
                        <th style="width: 120px;">Reported</th>
                        <th style="width: 80px;">Action</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php if (!empty($tableData)): ?>
                            <?php
                        // Limit to 10 rows initially
                        $displayData = array_slice($tableData, 0, 10);
                        foreach ($displayData as $row):
                            ?>
                            <tr>
                                <td>#<?= (int)$row['id']; ?></td>
                                <td>
                                    <strong style="display: block; margin-bottom: 4px;"><?= htmlspecialchars(mb_strimwidth($row['title'], 0, 40, '…', 'UTF-8'), ENT_QUOTES, 'UTF-8'); ?></strong>
                                </td>
                                <td><?= htmlspecialchars(mb_strimwidth($row['reporter_name'] ?: $row['reporter_email'] ?: 'Anonymous', 0, 20, '…', 'UTF-8'), ENT_QUOTES, 'UTF-8'); ?></td>
                                <td><?= htmlspecialchars(ucwords($row['category']), ENT_QUOTES, 'UTF-8'); ?></td>
                                <td><span class="tag severity-<?= htmlspecialchars($row['severity'], ENT_QUOTES, 'UTF-8'); ?>"><?= ucwords($row['severity']); ?></span></td>
                                <td><span class="tag status-<?= htmlspecialchars($row['status'], ENT_QUOTES, 'UTF-8'); ?>"><?= ucwords(str_replace('_', ' ', $row['status'])); ?></span></td>
                                <td><?= htmlspecialchars(mb_strimwidth($row['location_name'] ?? '—', 0, 15, '…', 'UTF-8'), ENT_QUOTES, 'UTF-8'); ?></td>
                                <td><?= date('d M H:i', strtotime($row['reported_date'])); ?></td>
                                <td><a class="table-link" href="view_report.php?id=<?= (int)$row['id']; ?>" style="padding: 6px 12px; background: #4f46e5; color: white; border-radius: 6px; font-size: 0.8rem; display: inline-block;">View</a></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="9">
                                <div class="empty-state">
                                    <span class="empty-state__icon" aria-hidden="true">📋</span>
                                    <p class="empty-state__title">No reports match filters</p>
                                    <p class="empty-state__text">Try changing status, severity, or search term.</p>
                                    <a href="admin_dashboard.php#incident-registry" class="ghost-btn empty-state__action">Reset filters</a>
                                </div>
                            </td>
                        </tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
            <div class="incident-registry-footer">
                <a href="view_all_reports.php?status=<?= htmlspecialchars($statusFilter, ENT_QUOTES, 'UTF-8'); ?>&severity=<?= htmlspecialchars($severityFilter, ENT_QUOTES, 'UTF-8'); ?>&q=<?= urlencode($searchTerm ?? ''); ?>"
                   class="btn-view-all-reports">
                    View All <?= count($tableData); ?> Reports →
                </a>
            </div>
        </section>

        <!-- Pending Approvals Section -->
        <section class="panel panel--wide">
            <header>
                <h2>Pending Approvals</h2>
                <div class="approval-tabs">
                    <button class="tab-btn active" data-tab="reports">Reports (<?= count($pendingReports ?? []); ?>)</button>
                    <button class="tab-btn" data-tab="groups">Groups (<?= count($pendingGroups ?? []); ?>)</button>
                    <button class="tab-btn" data-tab="disputes">Disputes (<?= count($pendingDisputes ?? []); ?>)</button>
                    <button class="tab-btn" data-tab="legal">Legal Providers (<?= count($unverifiedLegalProviders ?? []); ?>)</button>
                    <button class="tab-btn" data-tab="medical">Medical Providers (<?= count($unverifiedMedicalProviders ?? []); ?>)</button>
                    <button class="tab-btn" data-tab="alerts">Alerts (<?= count($pendingAlerts ?? []); ?>)</button>
                    <button class="tab-btn" data-tab="spaces">Safe Spaces (<?= count($pendingSafeSpaces ?? []); ?>)</button>
                </div>
            </header>

            <!-- Pending Reports Tab -->
            <div class="tab-content active" data-content="reports">
                <?php if (!empty($pendingReports)): ?>
                    <div class="approval-list" id="reports-list">
                        <?php foreach ($pendingReports as $index => $report): ?>
                            <div class="approval-item <?= $index >= 8 ? 'hidden-item' : ''; ?>"
                                 data-id="<?= $report['id']; ?>"
                                 data-type="incident_report"
                                 style="<?= $index >= 8 ? 'display: none;' : ''; ?>">
                                <div class="approval-item__info">
                                    <strong><?= htmlspecialchars($report['title'], ENT_QUOTES, 'UTF-8'); ?></strong>
                                    <small>By: <?= htmlspecialchars($report['display_name'] ?: $report['email'] ?: 'Anonymous', ENT_QUOTES, 'UTF-8'); ?> • <?= date('d M Y H:i', strtotime($report['reported_date'])); ?></small>
                                    <span class="tag severity-<?= htmlspecialchars($report['severity']); ?>"><?= ucwords($report['severity']); ?></span>
                                </div>
                                <div class="approval-item__actions">
                                    <button class="btn-approve" onclick="handleApproval(<?= $report['id']; ?>, 'incident_report', 'approved')">Approve</button>
                                    <button class="btn-reject" onclick="handleApproval(<?= $report['id']; ?>, 'incident_report', 'rejected')">Reject</button>
                                    <a href="view_report.php?id=<?= $report['id']; ?>" class="btn-view">View</a>
                                </div>
                            </div>
                        <?php endforeach; ?>
                        <?php if (count($pendingReports) > 8): ?>
                            <div style="text-align: center; padding: 16px; border-top: 1px solid #e2e8f0; margin-top: 8px;">
                                <button onclick="loadMoreItems('reports', <?= count($pendingReports); ?>)" class="ghost-btn" style="padding: 10px 24px; background: #6366f1; color: white; border: none; border-radius: 8px; cursor: pointer; margin-top: 16px;">
                                    View All <?= count($pendingReports); ?> Pending Reports →
                                </button>
                            </div>
                        <?php endif; ?>
                    </div>
                    <?php else: ?>
                    <p class="list-empty">No pending reports</p>
                    <?php endif; ?>
            </div>

            <!-- Pending Groups Tab -->
            <div class="tab-content" data-content="groups">
                <?php if (!empty($pendingGroups)): ?>
                    <div class="approval-list">
                        <?php foreach ($pendingGroups as $index => $group): ?>
                            <div class="approval-item <?= $index >= 8 ? 'hidden-item' : ''; ?>"
                                 data-id="<?= $group['id']; ?>"
                                 data-type="community_group"
                                 style="<?= $index >= 8 ? 'display: none;' : ''; ?>">
                                <div class="approval-item__info">
                                    <strong><?= htmlspecialchars($group['group_name'] ?? $group['name'] ?? 'Unnamed Group', ENT_QUOTES, 'UTF-8'); ?></strong>
                                    <small>By: <?= htmlspecialchars($group['display_name'] ?: $group['email'] ?: 'Unknown', ENT_QUOTES, 'UTF-8'); ?> • <?= ($group['district'] ?? '') . ($group['upazila'] ? ', ' . $group['upazila'] : ''); ?></small>
                                    <span class="tag"><?= $group['member_count'] ?? 0; ?> members</span>
                                </div>
                                <div class="approval-item__actions">
                                    <button class="btn-approve" onclick="handleApproval(<?= $group['id']; ?>, 'community_group', 'approved')">Approve</button>
                                    <button class="btn-reject" onclick="handleApproval(<?= $group['id']; ?>, 'community_group', 'rejected')">Reject</button>
                                    <a href="group_detail.php?id=<?= $group['id']; ?>" class="btn-view">View</a>
                                </div>
                            </div>
                        <?php endforeach; ?>
                        <?php if (count($pendingGroups) > 8): ?>
                            <div style="text-align: center; padding: 16px; border-top: 1px solid #e2e8f0; margin-top: 8px;">
                                <button onclick="loadMoreItems('groups', <?= count($pendingGroups); ?>)" class="ghost-btn" style="padding: 10px 24px; background: #6366f1; color: white; border: none; border-radius: 8px; cursor: pointer; margin-top: 16px;">
                                    View All <?= count($pendingGroups); ?> Pending Groups →
                                </button>
                            </div>
                        <?php endif; ?>
                    </div>
                    <?php else: ?>
                    <p class="list-empty">No pending groups</p>
                    <?php endif; ?>
            </div>

            <!-- Pending Disputes Tab -->
            <div class="tab-content" data-content="disputes">
                <?php if (!empty($pendingDisputes)): ?>
                    <div class="approval-list">
                        <?php foreach ($pendingDisputes as $dispute): ?>
                            <div class="approval-item" data-id="<?= $dispute['id']; ?>" data-type="dispute">
                                <div class="approval-item__info">
                                    <strong>Dispute #<?= $dispute['id']; ?> - <?= htmlspecialchars($dispute['report_title'] ?? 'Unknown Report', ENT_QUOTES, 'UTF-8'); ?></strong>
                                    <small>By: <?= htmlspecialchars($dispute['display_name'] ?: $dispute['email'] ?: 'Unknown', ENT_QUOTES, 'UTF-8'); ?> • <?= date('d M Y H:i', strtotime($dispute['created_at'])); ?></small>
                                    <span class="tag"><?= ucwords($dispute['reason']); ?></span>
                                </div>
                                <div class="approval-item__actions">
                                    <button class="btn-approve" onclick="handleApproval(<?= $dispute['id']; ?>, 'dispute', 'approved')">Resolve</button>
                                    <button class="btn-reject" onclick="handleApproval(<?= $dispute['id']; ?>, 'dispute', 'rejected')">Dismiss</button>
                                    <a href="dispute_center.php?id=<?= $dispute['id']; ?>" class="btn-view">View</a>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <p class="list-empty">No pending disputes</p>
                <?php endif; ?>
            </div>

            <!-- Unverified Legal Providers Tab -->
            <div class="tab-content" data-content="legal">
                <?php if (!empty($unverifiedLegalProviders)): ?>
                    <div class="approval-list">
                        <?php foreach (array_slice($unverifiedLegalProviders, 0, 8) as $provider): ?>
                            <div class="approval-item" data-id="<?= $provider['id']; ?>" data-type="legal_provider">
                                <div class="approval-item__info">
                                    <strong><?= htmlspecialchars($provider['name'] ?? 'Unknown', ENT_QUOTES, 'UTF-8'); ?></strong>
                                    <small><?= htmlspecialchars($provider['city'] ?? ''); ?>, <?= htmlspecialchars($provider['district'] ?? ''); ?> • <?= htmlspecialchars($provider['specialization'] ?? 'General', ENT_QUOTES, 'UTF-8'); ?></small>
                                    <span class="tag"><?= htmlspecialchars($provider['fee_structure'] ?? 'Unknown', ENT_QUOTES, 'UTF-8'); ?></span>
                                </div>
                                <div class="approval-item__actions">
                                    <button class="btn-approve" onclick="handleApproval(<?= $provider['id']; ?>, 'legal_provider', 'approved')">Verify</button>
                                    <button class="btn-reject" onclick="handleApproval(<?= $provider['id']; ?>, 'legal_provider', 'rejected')">Reject</button>
                                    <a href="legal_provider_detail.php?id=<?= $provider['id']; ?>" class="btn-view">View</a>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <p class="list-empty">No unverified legal providers</p>
                <?php endif; ?>
            </div>

            <!-- Unverified Medical Providers Tab -->
            <div class="tab-content" data-content="medical">
                <?php if (!empty($unverifiedMedicalProviders)): ?>
                    <div class="approval-list">
                        <?php foreach (array_slice($unverifiedMedicalProviders, 0, 8) as $provider): ?>
                            <div class="approval-item" data-id="<?= $provider['id']; ?>" data-type="medical_provider">
                                <div class="approval-item__info">
                                    <strong><?= htmlspecialchars($provider['name'] ?? 'Unknown', ENT_QUOTES, 'UTF-8'); ?></strong>
                                    <small><?= htmlspecialchars($provider['city'] ?? ''); ?>, <?= htmlspecialchars($provider['district'] ?? ''); ?> • <?= htmlspecialchars($provider['specialization'] ?? 'General', ENT_QUOTES, 'UTF-8'); ?></small>
                                    <span class="tag"><?= htmlspecialchars($provider['provider_type'] ?? 'Unknown', ENT_QUOTES, 'UTF-8'); ?></span>
                                </div>
                                <div class="approval-item__actions">
                                    <button class="btn-approve" onclick="handleApproval(<?= $provider['id']; ?>, 'medical_provider', 'approved')">Verify</button>
                                    <button class="btn-reject" onclick="handleApproval(<?= $provider['id']; ?>, 'medical_provider', 'rejected')">Reject</button>
                                    <a href="provider_detail.php?id=<?= $provider['id']; ?>" class="btn-view">View</a>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <p class="list-empty">No unverified medical providers</p>
                <?php endif; ?>
            </div>

            <!-- Pending Alerts Tab -->
            <div class="tab-content" data-content="alerts">
                <?php if (!empty($pendingAlerts)): ?>
                    <div class="approval-list">
                        <?php foreach (array_slice($pendingAlerts, 0, 8) as $alert): ?>
                            <div class="approval-item" data-id="<?= $alert['id']; ?>" data-type="alert">
                                <div class="approval-item__info">
                                    <strong><?= htmlspecialchars($alert['title'], ENT_QUOTES, 'UTF-8'); ?></strong>
                                    <small><?= htmlspecialchars($alert['location_name'] ?? 'Unknown', ENT_QUOTES, 'UTF-8'); ?> • <?= date('d M Y H:i', strtotime($alert['start_time'])); ?></small>
                                    <span class="tag severity-<?= strtolower($alert['severity']); ?>"><?= ucwords($alert['severity']); ?></span>
                                </div>
                                <div class="approval-item__actions">
                                    <button class="btn-approve" onclick="handleApproval(<?= $alert['id']; ?>, 'alert', 'approved')">Activate</button>
                                    <button class="btn-reject" onclick="handleApproval(<?= $alert['id']; ?>, 'alert', 'rejected')">Deactivate</button>
                                    <a href="dashboard.php#alerts" class="btn-view">View</a>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <?php else: ?>
                    <p class="list-empty">No pending alerts</p>
                    <?php endif; ?>
            </div>

            <!-- Pending Safe Spaces Tab -->
            <div class="tab-content" data-content="spaces">
                <?php if (!empty($pendingSafeSpaces)): ?>
                    <div class="approval-list">
                        <?php foreach (array_slice($pendingSafeSpaces, 0, 8) as $space): ?>
                            <div class="approval-item" data-id="<?= $space['id']; ?>" data-type="safe_space">
                                <div class="approval-item__info">
                                    <strong><?= htmlspecialchars($space['name'] ?? 'Unknown', ENT_QUOTES, 'UTF-8'); ?></strong>
                                    <small><?= htmlspecialchars($space['address'] ?? 'Unknown', ENT_QUOTES, 'UTF-8'); ?> • <?= htmlspecialchars($space['category'] ?? 'General', ENT_QUOTES, 'UTF-8'); ?></small>
                                    <span class="tag">Rating: <?= number_format($space['average_rating'] ?? 0, 1); ?></span>
                                </div>
                                <div class="approval-item__actions">
                                    <button class="btn-approve" onclick="handleApproval(<?= $space['id']; ?>, 'safe_space', 'approved')">Approve</button>
                                    <button class="btn-reject" onclick="handleApproval(<?= $space['id']; ?>, 'safe_space', 'rejected')">Reject</button>
                                    <a href="safe_space_map.php?id=<?= $space['id']; ?>" class="btn-view">View</a>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <?php else: ?>
                    <p class="list-empty">No pending safe spaces</p>
                    <?php endif; ?>
            </div>
        </section>


        <footer class="dashboard-footer">
            <span>© <?= date('Y'); ?> SafeSpace Command Center. Authorized use only.</span>
            <span class="dashboard-footer__version">Admin v1.0</span>
        </footer>
    </main>
</div>
    <script src="https://unpkg.com/lucide@latest/dist/umd/lucide.js"></script>
    <script>
        lucide.createIcons();
        // Logout confirmation
        document.querySelectorAll('[data-confirm-logout="1"]').forEach(function(link) {
            link.addEventListener('click', function(e) {
                if (!confirm('Are you sure you want to log out?')) e.preventDefault();
            });
        });
    </script>
</body>
</html>

