<?php
/**
 * StaffLog - Project Report (Admin Only)
 * Detailed project report with chart and CSV export.
 */

session_start();
require_once 'includes/db.php';
require_once 'includes/auth.php';

requireAdmin();

// Get project ID from URL
$projectId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);

if (!$projectId) {
    header('Location: projectes.php');
    exit;
}

// Get project details
$project = fetchOne(
    "SELECT id, nom, client, hores_pressupostades, estat 
     FROM projects WHERE id = ?",
    [$projectId]
);

if (!$project) {
    setFlashMessage('error', 'Projecte no trobat.');
    header('Location: projectes.php');
    exit;
}

// Get total real hours used
$realHours = fetchOne(
    "SELECT COALESCE(SUM(hores_totals), 0) as total 
     FROM time_entries WHERE project_id = ? AND sortida IS NOT NULL",
    [$projectId]
);
$realTotal = (float)($realHours['total'] ?? 0);
$pressupostades = (float)$project['hores_pressupostades'];

// Handle CSV export
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    // Get employee hours for CSV
    $employeeHours = fetchAll("
        SELECT u.nom, COALESCE(SUM(te.hores_totals), 0) as hores
        FROM users u
        LEFT JOIN time_entries te ON u.id = te.user_id AND te.project_id = ? AND te.sortida IS NOT NULL
        WHERE u.rol = 'empleat'
        GROUP BY u.id, u.nom
        ORDER BY hores DESC
    ", [$projectId]);

    // Output CSV headers
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="report_' . preg_replace('/[^a-zA-Z0-9]/', '_', $project['nom']) . '.csv"');
    
    $output = fopen('php://output', 'w');
    
    // BOM for Excel compatibility
    fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
    
    // CSV headers
    fputcsv($output, ['Projecte', $project['nom']]);
    fputcsv($output, ['Client', $project['client'] ?? '']);
    fputcsv($output, ['Hores Pressupostades', $pressupostades]);
    fputcsv($output, ['Hores Reals', $realTotal]);
    fputcsv($output, []);
    fputcsv($output, ['Empleat', 'Hores', 'Percentatge']);
    
    // CSV data
    foreach ($employeeHours as $emp) {
        $percentage = $realTotal > 0 ? round(($emp['hores'] / $realTotal) * 100, 1) : 0;
        fputcsv($output, [$emp['nom'], $emp['hores'], $percentage . '%']);
    }
    
    fclose($output);
    exit;
}

// Get hours per employee (for display)
$employeeHours = fetchAll("
    SELECT u.nom, COALESCE(SUM(te.hores_totals), 0) as hores,
           ROUND(COALESCE(SUM(te.hores_totals), 0) * 100 / NULLIF(?, 0), 1) as percentatge
    FROM users u
    LEFT JOIN time_entries te ON u.id = te.user_id AND te.project_id = ? AND te.sortida IS NOT NULL
    WHERE u.rol = 'empleat'
    GROUP BY u.id, u.nom
    ORDER BY hores DESC
", [$realTotal, $projectId]);

// Data for doughnut chart
$chartPressupostades = $pressupostades;
$chartReals = $realTotal;
$chartRemaining = max(0, $pressupostades - $realTotal);

$csrf_token = generateCsrfToken();
$flash = getFlashMessage();
$userName = getCurrentUserName();
?>
<!DOCTYPE html>
<html lang="ca">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Report - <?php echo e($project['nom']); ?></title>
    <link rel="stylesheet" href="assets/css/style.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        .report-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.5rem;
        }
        .report-title h2 {
            margin: 0;
            color: var(--primary-color);
        }
        .report-title p {
            margin: 0.25rem 0 0;
            color: #666;
        }
        .report-actions {
            display: flex;
            gap: 0.5rem;
        }
        .metric-row {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 1rem;
            margin-bottom: 1.5rem;
        }
        .metric-item {
            background: white;
            padding: 1.25rem;
            border-radius: 8px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
            text-align: center;
        }
        .metric-value {
            font-size: 2rem;
            font-weight: 700;
            color: var(--primary-color);
        }
        .metric-label {
            font-size: 0.85rem;
            color: #666;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .chart-section {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1.5rem;
            margin-bottom: 1.5rem;
        }
        .chart-container {
            position: relative;
            height: 280px;
            display: flex;
            justify-content: center;
            align-items: center;
        }
        .employee-table th,
        .employee-table td {
            padding: 1rem;
            text-align: left;
        }
        .progress-bar {
            width: 100%;
            height: 8px;
            background: #eee;
            border-radius: 4px;
            overflow: hidden;
        }
        .progress-fill {
            height: 100%;
            background: var(--secondary-color);
            border-radius: 4px;
            transition: width 0.3s;
        }
        .progress-fill.over {
            background: var(--danger-color);
        }
        @media (max-width: 768px) {
            .report-header {
                flex-direction: column;
                align-items: flex-start;
                gap: 1rem;
            }
            .metric-row {
                grid-template-columns: 1fr;
            }
            .chart-section {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
    <header class="header">
        <div class="container">
            <h1>StaffLog - Report</h1>
            <nav>
                <span>Benvingut/da, <?php echo e($userName); ?></span>
                <a href="dashboard_admin.php">Dashboard</a>
                <a href="empleats.php">Empleats</a>
                <a href="projectes.php">Projectes</a>
                <a href="alertes.php">Alertes</a>
                <a href="logout.php">Tancar Sessió</a>
            </nav>
        </div>
    </header>

    <main class="container" style="margin-top: 1.5rem;">
        <?php if ($flash): ?>
            <div class="alert alert-<?php echo e($flash['type']); ?>">
                <?php echo e($flash['message']); ?>
            </div>
        <?php endif; ?>

        <!-- Report Header -->
        <div class="report-header">
            <div class="report-title">
                <h2><?php echo e($project['nom']); ?></h2>
                <p><?php echo e($project['client'] ?? 'Sense client'); ?> 
                   | Estat: 
                   <span class="badge <?php echo $project['estat'] === 'actiu' ? 'badge-success' : 'badge-danger'; ?>">
                       <?php echo $project['estat'] === 'actiu' ? 'Actiu' : 'Tancat'; ?>
                   </span>
                </p>
            </div>
            <div class="report-actions">
                <a href="projectes.php" class="btn btn-primary">Tornar als Projectes</a>
                <a href="?id=<?php echo $projectId; ?>&export=csv" class="btn btn-success">Exportar CSV</a>
            </div>
        </div>

        <!-- Metrics -->
        <div class="metric-row">
            <div class="metric-item">
                <div class="metric-value"><?php echo number_format($pressupostades, 1); ?>h</div>
                <div class="metric-label">Hores Pressupostades</div>
            </div>
            <div class="metric-item">
                <div class="metric-value"><?php echo number_format($realTotal, 1); ?>h</div>
                <div class="metric-label">Hores Reals</div>
            </div>
            <div class="metric-item">
                <div class="metric-value" style="color: <?php echo $realTotal > $pressupostades ? '#e74c3c' : '#27ae60'; ?>">
                    <?php echo $realTotal > 0 ? number_format(($realTotal / $pressupostades) * 100, 1) : 0; ?>%
                </div>
                <div class="metric-label">Ús del Pressupost</div>
            </div>
        </div>

        <!-- Chart Section -->
        <div class="chart-section">
            <div class="card">
                <div class="card-header">
                    <h3>Pressupost vs Real</h3>
                </div>
                <div class="chart-container">
                    <canvas id="budgetChart"></canvas>
                </div>
            </div>
            
            <div class="card">
                <div class="card-header">
                    <h3>Resum</h3>
                </div>
                <div style="padding: 1rem;">
                    <div style="margin-bottom: 1.5rem;">
                        <div style="display: flex; justify-content: space-between; margin-bottom: 0.5rem;">
                            <span>Hores utilitzades</span>
                            <strong><?php echo number_format($realTotal, 1); ?>h</strong>
                        </div>
                        <div class="progress-bar">
                            <div class="progress-fill <?php echo $realTotal > $pressupostades ? 'over' : ''; ?>" 
                                 style="width: <?php echo min(100, ($realTotal / $pressupostades) * 100); ?>%;"></div>
                        </div>
                    </div>
                    
                    <div style="margin-bottom: 1.5rem;">
                        <div style="display: flex; justify-content: space-between; margin-bottom: 0.5rem;">
                            <span>Horas restantes</span>
                            <strong><?php echo number_format(max(0, $pressupostades - $realTotal), 1); ?>h</strong>
                        </div>
                        <div class="progress-bar">
                            <div class="progress-fill" style="width: <?php echo min(100, max(0, (($pressupostades - $realTotal) / $pressupostades) * 100)); ?>%; background: #27ae60;"></div>
                        </div>
                    </div>

                    <?php if ($realTotal > $pressupostades): ?>
                        <div class="alert alert-error">
                            ⚠️ S'han superat les hores pressupostades en <?php echo number_format($realTotal - $pressupostades, 1); ?>h
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Employee Hours Table -->
        <div class="card">
            <div class="card-header">
                <h3>Hores per Empleat</h3>
            </div>
            
            <?php if (empty($employeeHours) || $realTotal == 0): ?>
                <p>No hi ha hores registrades per a aquest projecte.</p>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="employee-table">
                        <thead>
                            <tr>
                                <th>Empleat</th>
                                <th>Hores</th>
                                <th>Percentatge</th>
                                <th>Gràfic</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($employeeHours as $emp): ?>
                                <?php if ($emp['hores'] > 0): ?>
                                    <tr>
                                        <td><?php echo e($emp['nom']); ?></td>
                                        <td><strong><?php echo number_format($emp['hores'], 2); ?>h</strong></td>
                                        <td><?php echo $emp['percentatge']; ?>%</td>
                                        <td style="width: 150px;">
                                            <div class="progress-bar">
                                                <div class="progress-fill" style="width: <?php echo $emp['percentatge']; ?>%;"></div>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </main>

    <footer class="footer">
        <div class="container">
            <p>StaffLog &copy; <?php echo date('Y'); ?> - Gestió de temps i projectes</p>
        </div>
    </footer>

    <script>
        // Doughnut chart: Budget vs Real
        const ctx = document.getElementById('budgetChart').getContext('2d');
        new Chart(ctx, {
            type: 'doughnut',
            data: {
                labels: ['Hores Reals', 'Restants'],
                datasets: [{
                    data: [<?php echo $realTotal; ?>, <?php echo max(0, $pressupostades - $realTotal); ?>],
                    backgroundColor: [
                        'rgba(52, 152, 219, 0.8)',
                        'rgba(39, 174, 96, 0.8)'
                    ],
                    borderColor: [
                        'rgba(52, 152, 219, 1)',
                        'rgba(39, 174, 96, 1)'
                    ],
                    borderWidth: 2
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'bottom',
                        labels: {
                            padding: 20,
                            font: {
                                size: 14
                            }
                        }
                    },
                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                return context.label + ': ' + context.raw.toFixed(1) + 'h';
                            }
                        }
                    }
                }
            }
        });
    </script>
</body>
</html>