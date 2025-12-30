<?php
date_default_timezone_set('Asia/Dhaka');
session_start();
header('Content-Type: application/json');
header('Cache-Control: no-cache, must-revalidate');

// Include database handler
require_once 'includes/Database.php';

// Initialize database
$database = new Database();

try {
    $period = $_GET['period'] ?? '30D';
    $userId = $_SESSION['user_id'] ?? 1;

    // Calculate date range based on period
    $endDate = new DateTime();
    $startDate = new DateTime();

    switch($period) {
        case '7D':
            // *** FIX: Corrected date logic for 7 days total
            $startDate->modify('-6 days');
            break;
        case '30D':
            // *** FIX: Corrected date logic for 30 days total
            $startDate->modify('-29 days');
            break;
        case '90D':
            // *** FIX: Corrected date logic for 90 days total
            $startDate->modify('-89 days');
            break;
        default:
            // *** FIX: Corrected date logic for 30 days total
            $startDate->modify('-29 days');
    }

    // *** FIX: Set start time to beginning of the day for an accurate range
    $startDate->setTime(0, 0, 0);

    // Get incident data from database
    $incidentData = [];

         try {
         // Check if incident_reports table exists
         $tableExists = $database->fetchOne("SHOW TABLES LIKE 'incident_reports'");
         if ($tableExists) {

             // *** FIX: Changed reported_date to incident_date and applying timezone offset
             $sql = "SELECT DATE(DATE_ADD(incident_date, INTERVAL 6 HOUR)) as date, COUNT(*) as count
                     FROM incident_reports
                     WHERE DATE(DATE_ADD(incident_date, INTERVAL 6 HOUR)) >= ? AND DATE(DATE_ADD(incident_date, INTERVAL 6 HOUR)) <= ?
                     GROUP BY date
                     ORDER BY date";

             $results = $database->fetchAll($sql, [$startDate->format('Y-m-d'), $endDate->format('Y-m-d')]);

             // *** FIX: Use an efficient map to fill in zero-count days
             $dateMap = [];
             foreach ($results as $row) {
                 $dateMap[$row['date']] = (int)$row['count'];
             }

             $currentDate = clone $startDate;
             while ($currentDate <= $endDate) {
                 $dateStr = $currentDate->format('Y-m-d');
                 $incidentData[] = [
                     'date' => $dateStr,
                     'count' => $dateMap[$dateStr] ?? 0 // Use 0 if no data
                 ];
                 $currentDate->modify('+1 day');
             }
         } else {
             // Generate realistic mock data if table doesn't exist
             $currentDate = clone $startDate;
             while ($currentDate <= $endDate) {
                 $dayOfWeek = $currentDate->format('N'); // 1=Monday, 7=Sunday
                 $isWeekend = ($dayOfWeek >= 6);
                 $baseValue = $isWeekend ? 2 : 1;
                 $randomValue = rand(0, 3) + $baseValue;
                 $incidentData[] = [
                     'date' => $currentDate->format('Y-m-d'),
                     'count' => $randomValue
                 ];
                 $currentDate->modify('+1 day');
             }
         }
     } catch (Exception $e) {
         // Fallback to realistic mock data
         $currentDate = clone $startDate;
         while ($currentDate <= $endDate) {
             $dayOfWeek = $currentDate->format('N');
             $isWeekend = ($dayOfWeek >= 6);
             $baseValue = $isWeekend ? 2 : 1;
             $randomValue = rand(0, 3) + $baseValue;
             $incidentData[] = [
                 'date' => $currentDate->format('Y-m-d'),
                 'count' => $randomValue
             ];
             $currentDate->modify('+1 day');
         }
     }

    // Get additional statistics
    $totalReports = 0;
    $activeAlerts = 0;

         try {
         if ($tableExists) {
             // *** FIX: Changed reported_date to incident_date and applying timezone offset
             $totalReports = $database->fetchOne("SELECT COUNT(*) as count FROM incident_reports WHERE DATE(DATE_ADD(incident_date, INTERVAL 6 HOUR)) >= ? AND DATE(DATE_ADD(incident_date, INTERVAL 6 HOUR)) <= ?",
                 [$startDate->format('Y-m-d'), $endDate->format('Y-m-d')])['count'] ?? 0;
         }

         // Check if alerts table exists
         $alertsTableExists = $database->fetchOne("SHOW TABLES LIKE 'alerts'");
         if ($alertsTableExists) {
             $activeAlerts = $database->fetchOne("SELECT COUNT(*) as count FROM alerts WHERE is_active = 1")['count'] ?? 0;
         }
     } catch (Exception $e) {
         // Use mock data
         $totalReports = array_sum(array_column($incidentData, 'count'));
         $activeAlerts = rand(1, 5);
     }

    // Calculate trend percentage
    $halfPoint = count($incidentData) / 2;
    $firstHalf = array_slice($incidentData, 0, floor($halfPoint));
    $secondHalf = array_slice($incidentData, floor($halfPoint));

    $firstHalfAvg = count($firstHalf) > 0 ? array_sum(array_column($firstHalf, 'count')) / count($firstHalf) : 0;
    $secondHalfAvg = count($secondHalf) > 0 ? array_sum(array_column($secondHalf, 'count')) / count($secondHalf) : 0;

    $trendPercentage = $firstHalfAvg > 0 ? (($secondHalfAvg - $firstHalfAvg) / $firstHalfAvg * 100) : 0;

    // Format labels based on period
    $labels = [];
    foreach ($incidentData as $item) {
        $date = new DateTime($item['date']);
        if ($period === '7D') {
            $labels[] = $date->format('D');
        } elseif ($period === '30D') {
            $labels[] = $date->format('j');
        } else {
            $labels[] = $date->format('M j');
        }
    }

         // Add debugging information
     $debug = [
         'period' => $period,
         'startDate' => $startDate->format('Y-m-d'),
         'endDate' => $endDate->format('Y-m-d'),
         'tableExists' => $tableExists ? 'yes' : 'no',
         'totalRecords' => count($incidentData),
         'nonZeroRecords' => count(array_filter($incidentData, function($item) { return $item['count'] > 0; }))
     ];

     $response = [
         'success' => true,
         'data' => [
             'labels' => $labels,
             'values' => array_column($incidentData, 'count'),
             'statistics' => [
                 
                 'activeAlerts' => $activeAlerts,
                 'trendPercentage' => round($trendPercentage, 1)
             ]
         ],
         'debug' => $debug
     ];

} catch (Exception $e) {
    $response = [
        'success' => false,
        'error' => 'Failed to fetch chart data',
        'message' => $e->getMessage()
    ];
}

echo json_encode($response);
?>