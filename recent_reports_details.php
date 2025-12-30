<?php
session_start();

// Include database handler
require_once 'includes/Database.php'; // Make sure this path is correct

// Initialize database
$database = new Database();
$models = new SafeSpaceModels($database); // We still need this for the database connection

// Get user ID from session
$userId = $_SESSION['user_id'] ?? 1; // You are User #1

// --- MODIFICATION: Fetch reports directly from the database ---
// 1. Define the SQL query
$sql = "SELECT * FROM incident_reports
        WHERE
            is_public = 1                     -- Must be public
            AND user_id != ?                  -- Must NOT be from the current user
            AND reported_date >= DATE_SUB(NOW(), INTERVAL 7 DAY) -- Must be in the last 7 days
        ORDER BY
            reported_date DESC
        LIMIT 50";

// 2. Define the parameters for the query
$params = [$userId];

// 3. Fetch the reports
$recentReports = $database->fetchAll($sql, $params);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Recent Public Reports - SafeSpace</title>

    <link rel="stylesheet" href="design-system.css">
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/lucide/0.263.1/lucide.min.css">

    <style>
        /* (Your CSS is correct, omitted here for brevity) */
        :root {
            --bg-primary: #0f172a;
            --bg-secondary: #1e293b;
            --border-primary: rgba(255, 255, 255, 0.1);
            --text-primary: #f8fafc;
            --text-secondary: #cbd5e1;
            --text-tertiary: #94a3b8;
        }
        body {
            background: var(--bg-primary);
            color: var(--text-primary);
            font-family: 'Inter', sans-serif;
        }
        .report-card-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(350px, 1fr));
            gap: 1.5rem;
        }
        .report-card {
            background: linear-gradient(145deg, rgba(255, 255, 255, 0.05), rgba(255, 255, 255, 0.01));
            border-radius: 16px;
            border: 1px solid var(--border-primary);
            padding: 1.5rem;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.2);
            backdrop-filter: blur(10px);
            transition: all 0.3s ease;
        }
        .report-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 8px 20px rgba(0, 0, 0, 0.3);
            border-color: rgba(255, 255, 255, 0.2);
        }
        .report-card-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 0.75rem;
        }
        .report-card-title {
            font-size: 1.25rem;
            font-weight: 700;
            color: var(--text-primary);
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
            max-width: 250px;
        }
        .report-card-badge {
            display: inline-block;
            padding: 0.25rem 0.75rem;
            border-radius: 9999px;
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
        }
        .badge-pending {
            background-color: rgba(245, 158, 11, 0.2);
            color: #fbbf24;
            border: 1px solid rgba(245, 158, 11, 0.3);
        }
        .badge-resolved {
            background-color: rgba(34, 197, 94, 0.2);
            color: #22c55e;
            border: 1px solid rgba(34, 197, 94, 0.3);
        }
        .badge-rejected {
            background-color: rgba(239, 68, 68, 0.2);
            color: #ef4444;
            border: 1px solid rgba(239, 68, 68, 0.3);
        }
        .report-card-description {
            font-size: 0.875rem;
            color: var(--text-secondary);
            margin-bottom: 1rem;
            overflow: hidden;
            text-overflow: ellipsis;
            display: -webkit-box;
            -webkit-line-clamp: 2;
            line-clamp: 2;
            -webkit-box-orient: vertical;
        }
        .report-card-footer {
            display: flex;
            justify-content: space-between;
            align-items: center;
            border-top: 1px solid var(--border-primary);
            padding-top: 1rem;
            margin-top: 1rem;
        }
        .report-card-meta {
            display: flex;
            gap: 1rem;
            font-size: 0.875rem;
            color: var(--text-tertiary);
        }
        .report-card-meta span {
            display: flex;
            align-items: center;
            gap: 0.375rem;
        }
        .report-card-actions {
            display: flex;
            gap: 0.75rem;
        }
        .btn-details {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            background-color: #3b82f6;
            color: white;
            padding: 0.5rem 1rem;
            border-radius: 8px;
            font-size: 0.875rem;
            font-weight: 500;
            transition: background-color 0.2s;
        }
        .btn-details:hover {
            background-color: #2563eb;
        }
    </style>
</head>
<body class="min-h-screen p-8">

    <div class="max-w-7xl mx-auto">
       <div class="mb-8 flex justify-between items-center">
            <div>
                <h1 class="text-3xl font-bold mb-2" style="color: var(--text-primary);">Recent Public Reports</h1>
                <p style="color: var(--text-secondary);">Publicly visible reports from other users in the last 7 days.</p>
            </div>
            <div>
                <a href="dashboard.php" class="btn-details">
                    <i data-lucide="arrow-left" class="w-4 h-4"></i>
                    Back to Dashboard
                </a>
            </div>
        </div>

        <div class="report-card-grid">
            <?php
            // --- MODIFICATION: Get count directly from the array ---
            $reportsFound = count($recentReports);

            if ($reportsFound > 0):
                foreach ($recentReports as $report):

                    // --- ALL FILTERS ARE NOW IN THE SQL QUERY ---

                    // Determine badge class based on status
                    $status = $report['status'] ?? 'pending';
                    $badgeClass = 'badge-pending';
                    if ($status === 'resolved') {
                        $badgeClass = 'badge-resolved';
                    } elseif ($status === 'rejected' || $status === 'disputed') {
                        $badgeClass = 'badge-rejected';
                    }
            ?>
                    <div class="report-card">
                        <div class="report-card-header">
                            <h3 class="report-card-title"><?= htmlspecialchars($report['title']) ?></h3>
                            <span class="report-card-badge <?= $badgeClass ?>"><?= htmlspecialchars($status) ?></span>
                        </div>

                        <p class="report-card-description">
                            Report submitted regarding an incident of <?= htmlspecialchars($report['category']) ?>.
                        </p>

                        <div class="report-card-footer">
                            <div class="report-card-meta">
                                <span>
                                    <i data-lucide="tag" class="w-4 h-4"></i>
                                    <?= htmlspecialchars(ucfirst($report['category'])) ?>
                                </span>
                                <span>
                                    <i data-lucide="calendar" class="w-4 h-4"></i>
                                    <?= date('M j, Y', strtotime($report['reported_date'])) ?>
                                </span>
                                <span>
                                    <i data-lucide="hash" class="w-4 h-4"></i>
                                    ID: <?= $report['id'] ?>
                                </span>
                            </div>

                            <div class="report-card-actions">
                               <a href="view_public_reports.php?id=<?= $report['id'] ?>" class="btn-details">
                                <i data-lucide="eye" class="w-4 h-4"></i>
                                View Details
                            </a>
                            </div>
                        </div>
                    </div>
                <?php
                endforeach;
            else:
            ?>
                <p style="color: var(--text-secondary); grid-column: 1 / -1; text-align: center; font-size: 1.25rem; padding: 4rem 0;">No public reports from other users found in the last 7 days.</p>
            <?php
            endif;
            ?>
        </div>

    </div>

    <script src="https://unpkg.com/lucide@latest/dist/umd/lucide.js"></script>
    <script>
        lucide.createIcons();
    </script>
</body>
</html>