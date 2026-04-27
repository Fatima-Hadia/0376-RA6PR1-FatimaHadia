<?php
/**
 * StaffLog - Employee Dashboard
 * Clock in/out system with daily summary and weekly chart.
 */

// Start session and load required files
session_start();
require_once 'includes/db.php';
require_once 'includes/auth.php';

// Require login
requireLogin();

// Get current user info
$userId = getCurrentUserId();
$userName = getCurrentUserName();

// Generate CSRF token
$csrf_token = generateCsrfToken();

// Handle clock in
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'clock_in') {
    $projectId = filter_input(INPUT_POST, 'project_id', FILTER_VALIDATE_INT);
    $submittedToken = $_POST['csrf_token'] ?? '';
    
    if (verifyCsrfToken($submittedToken) && $projectId) {
        try {
            executeQuery(
                "INSERT INTO time_entries (user_id, project_id, entrada, sortida) VALUES (?, ?, NOW(), NULL)",
                [$userId, $projectId]
            );
            setFlashMessage('success', 'Entrada registrada correctament.');
        } catch (Exception $e) {
            setFlashMessage('error', 'Error en registrar l\'entrada.');
        }
    }
    header('Location: dashboard_employee.php');
    exit;
}

// Handle clock out
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'clock_out') {
    $submittedToken = $_POST['csrf_token'] ?? '';
    
    if (verifyCsrfToken($submittedToken)) {
        try {
            // Get the last open time entry for this user
            $entry = fetchOne(
                "SELECT id, entrada FROM time_entries WHERE user_id = ? AND sortida IS NULL ORDER BY entrada DESC LIMIT 1",
                [$userId]
            );
            
            if ($entry) {
                // Calculate hours and update
                executeQuery(
                    "UPDATE time_entries SET sortida = NOW(), hores_totals = TIMESTAMPDIFF(MINUTE, entrada, NOW()) / 60 WHERE id = ?",
                    [$entry['id']]
                );
                setFlashMessage('success', 'Sortida registrada correctament.');
                
                // === AUTOMATIC ALERT CHECK ===
                // Get user's contracted hours
                $userData = fetchOne("SELECT hores_contractades FROM users WHERE id = ?", [$userId]);
                if ($userData) {
                    // Get total hours worked today
                    $todayHours = fetchOne(
                        "SELECT COALESCE(SUM(hores_totals), 0) as total FROM time_entries 
                         WHERE user_id = ? AND DATE(entrada) = CURDATE() AND sortida IS NOT NULL",
                        [$userId]
                    );
                    $totalToday = (float)($todayHours['total'] ?? 0);
                    $contractedHours = (float)$userData['hores_contractades'];
                    
                    // If worked less than 90% of contracted hours, create alert
                    if ($totalToday < ($contractedHours * 0.9)) {
                        executeQuery(
                            "INSERT INTO alerts (user_id, tipus, data) VALUES (?, 'sortida_aviat', CURDATE())",
                            [$userId]
                        );
                    }
                }
            } else {
                setFlashMessage('error', 'No hi ha cap entrada pendent de sortida.');
            }
        } catch (Exception $e) {
            setFlashMessage('error', 'Error en registrar la sortida.');
        }
    }
    header('Location: dashboard_employee.php');
    exit;
}

// Check if employee has an open time entry today (sortida IS NULL)
$currentEntry = fetchOne(
    "SELECT te.id, te.entrada, p.nom as project_name 
     FROM time_entries te
     JOIN projects p ON te.project_id = p.id
     WHERE te.user_id = ? AND te.sortida IS NULL
     ORDER BY te.entrada DESC LIMIT 1",
    [$userId]
);

// Get active projects for dropdown
$projects = fetchAll("SELECT id, nom FROM projects WHERE estat = 'actiu' ORDER BY nom");

// Today's hours per project: SELECT project name and SUM(hores_totals) grouped by project
$todayHours = fetchAll(
    "SELECT p.nom, COALESCE(SUM(te.hores_totals), 0) as total_hours
     FROM time_entries te
     JOIN projects p ON p.id = te.project_id
     WHERE te.user_id = ? AND DATE(te.entrada) = CURDATE() AND te.sortida IS NOT NULL
     GROUP BY p.id, p.nom
     ORDER BY total_hours DESC",
    [$userId]
);

// Weekly hours for Chart.js: total hours per day for the last 7 days
$weeklyData = fetchAll(
    "SELECT DATE(entrada) as date, COALESCE(SUM(hores_totals), 0) as hours
     FROM time_entries
     WHERE user_id = ? AND DATE(entrada) >= DATE_SUB(CURDATE(), INTERVAL 6 DAY) AND sortida IS NOT NULL
     GROUP BY DATE(entrada)
     ORDER BY date",
    [$userId]
);

// Build arrays for Chart.js (last 7 days, including days with 0 hours)
$chartLabels = [];
$chartData = [];
for ($i = 6; $i >= 0; $i--) {
    $date = date('Y-m-d', strtotime("-{$i} days"));
    $chartLabels[] = date('d/m', strtotime($date));
    $hours = 0;
    foreach ($weeklyData as $row) {
        if ($row['date'] === $date) {
            $hours = (float)$row['hours'];
            break;
        }
    }
    $chartData[] = $hours;
}

// Get flash message
$flash = getFlashMessage();
?>
<!DOCTYPE html>
<html lang="ca">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>StaffLog - Dashboard Empleado</title>
    <link rel="stylesheet" href="assets/css/style.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        .clock-btn {
            width: 100%;
            padding: 1.5rem 2rem;
            font-size: 1.5rem;
            font-weight: 700;
            border: none;
            border-radius: 12px;
            cursor: pointer;
            transition: all 0.3s ease;
            text-transform: uppercase;
            letter-spacing: 1px;
        }
        .clock-btn-in {
            background: linear-gradient(135deg, #27ae60, #2ecc71);
            color: white;
            box-shadow: 0 4px 15px rgba(39, 174, 96, 0.4);
        }
        .clock-btn-in:hover {
            transform: translateY(-3px);
            box-shadow: 0 6px 20px rgba(39, 174, 96, 0.5);
        }
        .clock-btn-out {
            background: linear-gradient(135deg, #e74c3c, #c0392b);
            color: white;
            box-shadow: 0 4px 15px rgba(231, 76, 60, 0.4);
        }
        .clock-btn-out:hover {
            transform: translateY(-3px);
            box-shadow: 0 6px 20px rgba(231, 76, 60, 0.5);
        }
        .clock-status {
            text-align: center;
            padding: 1rem;
            font-size: 1.1rem;
        }
        .project-info {
            text-align: center;
            padding: 0.5rem;
            color: #666;
        }
        .chart-container {
            position: relative;
            height: 250px;
            margin-top: 1rem;
        }
        .today-summary table {
            width: 100%;
            margin-top: 0.5rem;
        }
        .today-summary th,
        .today-summary td {
            padding: 0.75rem;
            text-align: left;
        }
        @media (max-width: 768px) {
            .clock-btn {
                font-size: 1.2rem;
                padding: 1.2rem 1.5rem;
            }
            .dashboard-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
    <header class="header">
        <div class="container">
            <h1>StaffLog</h1>
            <nav>
                <span>Benvingut/da, <?php echo e($userName); ?></span>
                <a href="logout.php">Tancar Sessió</a>
            </nav>
        </div>
    </header>

    <main class="container">
        <?php if ($flash): ?>
            <div class="alert alert-<?php echo e($flash['type']); ?>">
                <?php echo e($flash['message']); ?>
            </div>
        <?php endif; ?>

        <div class="dashboard-grid">
            <!-- Clock In/Out Card -->
            <div class="card">
                <div class="card-header">
                    <h3>Fitxatge</h3>
                </div>
                
                <?php if ($currentEntry): ?>
                    <!-- Clocked In - Show Clock Out Button -->
                    <div class="clock-status">
                        <span class="badge badge-success" style="font-size: 1rem;">● Treballant</span>
                    </div>
                    <div class="project-info">
                        <strong>Projecte:</strong> <?php echo e($currentEntry['project_name']); ?>
                    </div>
                    <div class="project-info">
                        <strong>Entrada:</strong> <?php echo date('H:i:s', strtotime($currentEntry['entrada'])); ?>
                    </div>
                    
                    <form method="POST" style="margin-top: 1.5rem;">
                        <input type="hidden" name="action" value="clock_out">
                        <input type="hidden" name="csrf_token" value="<?php echo e($csrf_token); ?>">
                        <button type="submit" class="clock-btn clock-btn-out">
                            Marcar Sortida
                        </button>
                    </form>
                <?php else: ?>
                    <!-- Not Clocked In - Show Clock In Form -->
                    <div class="clock-status">
                        <span class="badge badge-danger" style="font-size: 1rem;">● No fitxat</span>
                    </div>
                    
                    <form method="POST" style="margin-top: 1.5rem;">
                        <input type="hidden" name="action" value="clock_in">
                        <input type="hidden" name="csrf_token" value="<?php echo e($csrf_token); ?>">
                        
                        <div class="form-group">
                            <label for="project-select">Selecciona un projecte</label>
                            <select name="project_id" id="project-select" required style="padding: 0.75rem; font-size: 1rem;">
                                <option value="">-- Projecte --</option>
                                <?php foreach ($projects as $project): ?>
                                    <option value="<?php echo $project['id']; ?>">
                                        <?php echo e($project['nom']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <button type="submit" class="clock-btn clock-btn-in">
                            Marcar Entrada
                        </button>
                    </form>
                <?php endif; ?>
            </div>

            <!-- Today's Summary Card -->
            <div class="card today-summary">
                <div class="card-header">
                    <h3>Resum d'Avui</h3>
                </div>
                
                <?php if (empty($todayHours)): ?>
                    <p>No hi ha registres d'entrada sortida avui.</p>
                <?php else: ?>
                    <table>
                        <thead>
                            <tr>
                                <th>Projecte</th>
                                <th style="text-align: right;">Hores</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($todayHours as $row): ?>
                                <tr>
                                    <td><?php echo e($row['nom']); ?></td>
                                    <td style="text-align: right; font-weight: 600;">
                                        <?php echo number_format($row['total_hours'], 2); ?>h
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>

            <!-- Weekly Chart Card -->
            <div class="card" style="grid-column: span 2;">
                <div class="card-header">
                    <h3>Resum Setmanal</h3>
                </div>
                <div class="chart-container">
                    <canvas id="weeklyChart"></canvas>
                </div>
            </div>
        </div>
    </main>

    <footer class="footer">
        <div class="container">
            <p>StaffLog &copy; <?php echo date('Y'); ?> - Gestió de temps i projectes</p>
        </div>
    </footer>

    <script>
        // Weekly bar chart
        const ctx = document.getElementById('weeklyChart').getContext('2d');
        new Chart(ctx, {
            type: 'bar',
            data: {
                labels: <?php echo json_encode($chartLabels); ?>,
                datasets: [{
                    label: 'Hores treballades',
                    data: <?php echo json_encode($chartData); ?>,
                    backgroundColor: 'rgba(52, 152, 219, 0.7)',
                    borderColor: 'rgba(52, 152, 219, 1)',
                    borderWidth: 1,
                    borderRadius: 4
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        display: false
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: {
                            callback: function(value) {
                                return value + 'h';
                            }
                        },
                        title: {
                            display: true,
                            text: 'Hores'
                        }
                    },
                    x: {
                        title: {
                            display: true,
                            text: 'Data'
                        }
                    }
                }
            }
        });
    </script>
</body>
</html>