<?php
/**
 * StaffLog - Employee Dashboard
 * Displays employee's time entries, projects, and allows clock in/out.
 */

// Start session and load required files
session_start();
require_once 'includes/db.php';
require_once 'includes/auth.php';

// Require login
requireLogin();

// Check session timeout
checkSessionTimeout();

// Get current user info
$userId = getCurrentUserId();
$userName = getCurrentUserName();

// Handle clock in
if (isset($_POST['action']) && $_POST['action'] === 'clock_in') {
    $projectId = filter_input(INPUT_POST, 'project_id', FILTER_VALIDATE_INT);
    $csrf_token = $_POST['csrf_token'] ?? '';
    
    if (verifyCsrfToken($csrf_token) && $projectId) {
        try {
            executeQuery(
                "INSERT INTO time_entries (user_id, project_id, entrada) 
                 VALUES (:user_id, :project_id, NOW())",
                ['user_id' => $userId, 'project_id' => $projectId]
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
if (isset($_POST['action']) && $_POST['action'] === 'clock_out') {
    $csrf_token = $_POST['csrf_token'] ?? '';
    
    if (verifyCsrfToken($csrf_token)) {
        try {
            // Get the last open time entry for this user
            $entry = fetchOne(
                "SELECT id, entrada FROM time_entries 
                 WHERE user_id = :user_id AND sortida IS NULL 
                 ORDER BY entrada DESC LIMIT 1",
                ['user_id' => $userId]
            );
            
            if ($entry) {
                // Calculate hours and update
                $hours = executeQuery(
                    "UPDATE time_entries 
                     SET sortida = NOW(), 
                         hores_totals = TIMESTAMPDIFF(MINUTE, entrada, NOW()) / 60 
                     WHERE id = :id",
                    ['id' => $entry['id']]
                );
                setFlashMessage('success', 'Sortida registrada correctament.');
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

// Get user's current open entry (if any)
$currentEntry = fetchOne(
    "SELECT te.id, te.entrada, p.nom as project_name 
     FROM time_entries te
     JOIN projects p ON te.project_id = p.id
     WHERE te.user_id = :user_id AND te.sortida IS NULL
     ORDER BY te.entrada DESC LIMIT 1",
    ['user_id' => $userId]
);

// Get user's time entries (last 10)
$timeEntries = fetchAll(
    "SELECT te.*, p.nom as project_name 
     FROM time_entries te
     JOIN projects p ON te.project_id = p.id
     WHERE te.user_id = :user_id
     ORDER BY te.entrada DESC LIMIT 10",
    ['user_id' => $userId]
);

// Get user's statistics for current month
$stats = fetchOne(
    "SELECT 
        COUNT(*) as total_entries,
        COALESCE(SUM(hores_totals), 0) as total_hours
     FROM time_entries
     WHERE user_id = :user_id 
        AND YEAR(entrada) = YEAR(CURRENT_DATE())
        AND MONTH(entrada) = MONTH(CURRENT_DATE())
        AND sortida IS NOT NULL",
    ['user_id' => $userId]
);

// Get user's contracted hours
$userData = fetchOne(
    "SELECT hores_contractades FROM users WHERE id = :user_id",
    ['user_id' => $userId]
);

// Get active projects
$projects = fetchAll("SELECT id, nom FROM projects WHERE estat = 'actiu' ORDER BY nom");

// Get user's alerts
$alerts = fetchAll(
    "SELECT * FROM alerts 
     WHERE user_id = :user_id AND llegida = 0
     ORDER BY data DESC",
    ['user_id' => $userId]
);

// Get today's total hours for this user
$todayHours = fetchOne(
    "SELECT COALESCE(SUM(hores_totals), 0) as total_hours
     FROM time_entries
     WHERE user_id = :user_id AND DATE(entrada) = CURDATE() AND sortida IS NOT NULL",
    ['user_id' => $userId]
);

// Get weekly hours data for Chart.js (Mon-Sun)
$weeklyHours = fetchAll(
    "SELECT DAYOFWEEK(entrada) as day_num, SUM(hores_totals) as total_hours
     FROM time_entries
     WHERE user_id = :user_id AND WEEK(entrada) = WEEK(NOW())
     GROUP BY DAYOFWEEK(entrada)
     ORDER BY day_num",
    ['user_id' => $userId]
);

// Build array for chart (index 1=Monday to 7=Sunday, but DAYOFWEEK returns 1=Sunday to 7=Saturday)
$chartWeeklyData = [0, 0, 0, 0, 0, 0, 0]; // Sun to Sat
$dayNames = ['Diumenge', 'Dilluns', 'Dimarts', 'Dimecres', 'Dijous', 'Divendres', 'Dissabte'];
foreach ($weeklyHours as $row) {
    $chartWeeklyData[$row['day_num'] - 1] = (float)$row['total_hours'];
}

// Get today's hours per project for progress bars
$todayProjectHours = fetchAll(
    "SELECT p.nom, SUM(te.hores_totals) as total_hours
     FROM time_entries te
     JOIN projects p ON te.project_id = p.id
     WHERE te.user_id = :user_id AND DATE(te.entrada) = CURDATE() AND te.sortida IS NOT NULL
     GROUP BY p.id, p.nom
     ORDER BY total_hours DESC",
    ['user_id' => $userId]
);

// If currently clocked in, add current session to today's project
if ($currentEntry) {
    $currentProjectName = $currentEntry['project_name'];
    $found = false;
    foreach ($todayProjectHours as &$proj) {
        if ($proj['nom'] === $currentProjectName) {
            $found = true;
            break;
        }
    }
    if (!$found) {
        $todayProjectHours[] = ['nom' => $currentProjectName, 'total_hours' => 0];
    }
}

// Calculate today's total from projects (for progress bar percentages)
$todayTotalFromProjects = array_sum(array_column($todayProjectHours, 'total_hours'));

// Generate CSRF token
$csrf_token = generateCsrfToken();

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
        .live-clock {
            font-size: 2rem;
            font-weight: 700;
            color: var(--primary-color);
            text-align: center;
            padding: 1rem;
            background: linear-gradient(135deg, #f8f9fa, #e9ecef);
            border-radius: var(--border-radius);
            margin-bottom: 1.5rem;
        }
        .live-clock .label {
            font-size: 0.85rem;
            color: #666;
            font-weight: normal;
            display: block;
            margin-bottom: 0.25rem;
        }
        .progress-section {
            margin-top: 1rem;
        }
        .progress-item {
            margin-bottom: 1rem;
        }
        .progress-label {
            display: flex;
            justify-content: space-between;
            margin-bottom: 0.25rem;
            font-size: 0.9rem;
        }
        .progress-bar-container {
            width: 100%;
            height: 20px;
            background: #eee;
            border-radius: 10px;
            overflow: hidden;
        }
        .progress-bar-fill {
            height: 100%;
            background: var(--secondary-color);
            border-radius: 10px;
            transition: width 0.5s ease;
            display: flex;
            align-items: center;
            justify-content: flex-end;
            padding-right: 0.5rem;
            color: white;
            font-size: 0.75rem;
            font-weight: 600;
        }
        .chart-container {
            position: relative;
            height: 250px;
            margin-top: 1rem;
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

        <?php if (!empty($alerts)): ?>
            <div class="alert alert-warning">
                <strong>⚠️ Tens <?php echo count($alerts); ?> alertes sense llegir.</strong>
            </div>
        <?php endif; ?>

        <!-- Live Clock - Shows time worked today -->
        <?php if ($currentEntry): ?>
            <div class="live-clock" id="live-clock">
                <span class="label">Temps treballat avui</span>
                <span id="clock-display">00:00:00</span>
            </div>
        <?php else: ?>
            <div class="live-clock">
                <span class="label">Estat actual</span>
                <span style="font-size: 1.2rem; color: var(--danger-color);">No fitxat</span>
            </div>
        <?php endif; ?>

        <div class="dashboard-grid">
            <!-- Clock In/Out Card -->
            <div class="card">
                <div class="card-header">
                    <h3>Fitxatge</h3>
                </div>
                
                <?php if ($currentEntry): ?>
                    <p><strong>Estat:</strong> <span class="badge badge-success">Treballant</span></p>
                    <p><strong>Projecte:</strong> <?php echo e($currentEntry['project_name']); ?></p>
                    <p><strong>Hora d'entrada:</strong> <?php echo date('H:i:s', strtotime($currentEntry['entrada'])); ?></p>
                    
                    <form method="POST" id="clock-out-form">
                        <input type="hidden" name="action" value="clock_out">
                        <input type="hidden" name="csrf_token" value="<?php echo e($csrf_token); ?>">
                        <button type="submit" class="btn btn-danger" id="clock-out-btn">
                            Fitxar Sortida
                        </button>
                    </form>
                <?php else: ?>
                    <p><strong>Estat:</strong> <span class="badge badge-danger">No fitxat</span></p>
                    
                    <form method="POST" id="clock-form">
                        <input type="hidden" name="action" value="clock_in">
                        <input type="hidden" name="csrf_token" value="<?php echo e($csrf_token); ?>">
                        
                        <div class="form-group">
                            <label for="project-select">Projecte</label>
                            <select name="project_id" id="project-select" required>
                                <option value="">Selecciona un projecte</option>
                                <?php foreach ($projects as $project): ?>
                                    <option value="<?php echo $project['id']; ?>">
                                        <?php echo e($project['nom']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <button type="submit" class="btn btn-success" id="clock-in-btn">
                            Fitxar Entrada
                        </button>
                    </form>
                <?php endif; ?>
            </div>

            <!-- Statistics Card -->
            <div class="card">
                <div class="card-header">
                    <h3>Estadístiques del Mes</h3>
                </div>
                
                <div class="stat-card" style="box-shadow: none; padding: 0;">
                    <div class="stat-number">
                        <?php echo number_format($stats['total_hours'], 2); ?>h
                    </div>
                    <div class="stat-label">Hores Treballades</div>
                </div>
                
                <div style="margin-top: 1rem; padding-top: 1rem; border-top: 1px solid #ddd;">
                    <p><strong>Hores Contractades:</strong> <?php echo number_format($userData['hores_contractades'], 2); ?>h/dia</p>
                    <p><strong>Registres aquest mes:</strong> <?php echo $stats['total_entries']; ?></p>
                </div>
            </div>

            <!-- Weekly Hours Chart -->
            <div class="card">
                <div class="card-header">
                    <h3>Hores Setmanals</h3>
                </div>
                <div class="chart-container">
                    <canvas id="weeklyChart"></canvas>
                </div>
            </div>

            <!-- Today's Project Hours Breakdown -->
            <?php if (!empty($todayProjectHours)): ?>
            <div class="card">
                <div class="card-header">
                    <h3>Hores per Projecte (Avui)</h3>
                </div>
                <div class="progress-section">
                    <?php 
                    $colors = ['#3498db', '#2ecc71', '#e74c3c', '#f39c12', '#9b59b6', '#1abc9c'];
                    $colorIdx = 0;
                    foreach ($todayProjectHours as $proj): 
                        if ($proj['total_hours'] == 0 && !$currentEntry) continue;
                        $percentage = $todayTotalFromProjects > 0 
                            ? ($proj['total_hours'] / $todayTotalFromProjects) * 100 
                            : 0;
                        $color = $colors[$colorIdx % count($colors)];
                    ?>
                        <div class="progress-item">
                            <div class="progress-label">
                                <span><?php echo e($proj['nom']); ?></span>
                                <span><?php echo number_format($proj['total_hours'], 2); ?>h</span>
                            </div>
                            <div class="progress-bar-container">
                                <div class="progress-bar-fill" style="width: <?php echo max(1, $percentage); ?>%; background: <?php echo $color; ?>;">
                                    <?php echo number_format($percentage, 0); ?>%
                                </div>
                            </div>
                        </div>
                    <?php 
                        $colorIdx++;
                    endforeach; ?>
                </div>
            </div>
            <?php endif; ?>

            <!-- Recent Entries Card -->
            <div class="card" style="grid-column: span 2;">
                <div class="card-header">
                    <h3>Últims Registres</h3>
                </div>
                
                <?php if (empty($timeEntries)): ?>
                    <p>No hi ha registres recents.</p>
                <?php else: ?>
                    <div class="table-responsive">
                        <table>
                            <thead>
                                <tr>
                                    <th>Data</th>
                                    <th>Projecte</th>
                                    <th>Entrada</th>
                                    <th>Sortida</th>
                                    <th>Hores</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($timeEntries as $entry): ?>
                                    <tr>
                                        <td><?php echo date('d/m/Y', strtotime($entry['entrada'])); ?></td>
                                        <td><?php echo e($entry['project_name']); ?></td>
                                        <td><?php echo date('H:i', strtotime($entry['entrada'])); ?></td>
                                        <td>
                                            <?php if ($entry['sortida']): ?>
                                                <?php echo date('H:i', strtotime($entry['sortida'])); ?>
                                            <?php else: ?>
                                                <span class="badge badge-success">En curs</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($entry['hores_totals']): ?>
                                                <?php echo number_format($entry['hores_totals'], 2); ?>h
                                            <?php else: ?>
                                                -
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </main>

    <footer class="footer">
        <div class="container">
            <p>StaffLog &copy; <?php echo date('Y'); ?> - Gestió de temps i projectes</p>
        </div>
    </footer>

    <script src="assets/js/main.js"></script>
    <script>
    document.addEventListener('DOMContentLoaded', function() {
        // Live Clock - Update every second
        <?php if ($currentEntry): ?>
        const clockInTime = new Date('<?php echo $currentEntry['entrada']; ?>');
        const clockDisplay = document.getElementById('clock-display');
        
        function updateClock() {
            const now = new Date();
            const diff = now - clockInTime;
            const totalSeconds = Math.floor(diff / 1000);
            const hours = Math.floor(totalSeconds / 3600);
            const minutes = Math.floor((totalSeconds % 3600) / 60);
            const seconds = totalSeconds % 60;
            
            clockDisplay.textContent = 
                String(hours).padStart(2, '0') + ':' +
                String(minutes).padStart(2, '0') + ':' +
                String(seconds).padStart(2, '0');
        }
        
        updateClock();
        setInterval(updateClock, 1000);
        <?php endif; ?>

        // Weekly Hours Chart using Chart.js
        const weeklyCtx = document.getElementById('weeklyChart');
        if (weeklyCtx) {
            new Chart(weeklyCtx.getContext('2d'), {
                type: 'bar',
                data: {
                    labels: ['Diumenge', 'Dilluns', 'Dimarts', 'Dimecres', 'Dijous', 'Divendres', 'Dissabte'],
                    datasets: [{
                        label: 'Hores treballades',
                        data: <?php echo json_encode($chartWeeklyData); ?>,
                        backgroundColor: 'rgba(52, 152, 219, 0.7)',
                        borderColor: 'rgba(52, 152, 219, 1)',
                        borderWidth: 1
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    scales: {
                        y: {
                            beginAtZero: true,
                            ticks: {
                                callback: function(value) {
                                    return value + 'h';
                                }
                            }
                        }
                    },
                    plugins: {
                        legend: {
                            display: false
                        },
                        tooltip: {
                            callbacks: {
                                label: function(context) {
                                    return context.raw + ' hores';
                                }
                            }
                        }
                    }
                }
            });
        }
    });
    </script>
</body>
</html>