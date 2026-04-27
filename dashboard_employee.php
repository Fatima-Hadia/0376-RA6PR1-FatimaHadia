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
                executeQuery(
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

// Get weekly hours data for Chart.js
$weeklyHours = fetchAll(
    "SELECT DAYOFWEEK(entrada) as day_num, SUM(hores_totals) as total_hours
     FROM time_entries
     WHERE user_id = :user_id AND WEEK(entrada) = WEEK(NOW())
     GROUP BY DAYOFWEEK(entrada)
     ORDER BY day_num",
    ['user_id' => $userId]
);

// Build array for chart
$chartWeeklyData = [0, 0, 0, 0, 0, 0, 0];
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

// If currently clocked in, add current session
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
</head>
<body>
    <div class="employee-layout">
        <!-- Top Header -->
        <header class="header">
            <h1>
                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <rect x="3" y="3" width="18" height="18" rx="2" ry="2"></rect>
                    <line x1="16" y1="2" x2="16" y2="6"></line>
                    <line x1="8" y1="2" x2="8" y2="6"></line>
                    <line x1="3" y1="10" x2="21" y2="10"></line>
                </svg>
                StaffLog
            </h1>
            <nav>
                <span><?php echo e($userName); ?></span>
                <a href="logout.php">Tancar Sessió</a>
            </nav>
        </header>

        <!-- Main Content -->
        <main class="main-content">
            <?php if ($flash): ?>
                <div class="alert alert-<?php echo e($flash['type']); ?> fade-in">
                    <?php if ($flash['type'] === 'success'): ?>
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path>
                            <polyline points="22 4 12 14.01 9 11.01"></polyline>
                        </svg>
                    <?php endif; ?>
                    <?php echo e($flash['message']); ?>
                </div>
            <?php endif; ?>

            <?php if (!empty($alerts)): ?>
                <div class="alert alert-warning">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"></path>
                        <line x1="12" y1="9" x2="12" y2="13"></line>
                        <line x1="12" y1="17" x2="12.01" y2="17"></line>
                    </svg>
                    Tens <?php echo count($alerts); ?> alertes sense llegir.
                </div>
            <?php endif; ?>

            <!-- Live Clock -->
            <?php if ($currentEntry): ?>
                <div class="live-clock fade-in" id="live-clock">
                    <span class="label">Temps treballat avui</span>
                    <span class="time" id="clock-display">00:00:00</span>
                </div>
            <?php else: ?>
                <div class="live-clock" style="background: linear-gradient(135deg, #64748B, #94A3B8);">
                    <span class="label">Estat actual</span>
                    <span class="time" style="color: var(--danger);">No fitxat</span>
                </div>
            <?php endif; ?>

            <div class="dashboard-grid">
                <!-- Clock In/Out Card -->
                <div class="card">
                    <div class="card-header">
                        <h3>
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <circle cx="12" cy="12" r="10"></circle>
                                <polyline points="12 6 12 12 16 14"></polyline>
                            </svg>
                            Fitxatge
                        </h3>
                    </div>
                    
                    <?php if ($currentEntry): ?>
                        <div class="text-center" style="padding: 1rem 0;">
                            <span class="badge badge-success" style="font-size: 0.875rem; padding: 0.5rem 1rem;">
                                <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="margin-right: 0.25rem;">
                                    <circle cx="12" cy="12" r="10"></circle>
                                    <circle cx="12" cy="12" r="3"></circle>
                                </svg>
                                Treballant
                            </span>
                        </div>
                        <p><strong>Projecte:</strong> <?php echo e($currentEntry['project_name']); ?></p>
                        <p><strong>Hora d'entrada:</strong> <?php echo date('H:i:s', strtotime($currentEntry['entrada'])); ?></p>
                        
                        <form method="POST" id="clock-out-form">
                            <input type="hidden" name="action" value="clock_out">
                            <input type="hidden" name="csrf_token" value="<?php echo e($csrf_token); ?>">
                            <button type="submit" class="btn btn-danger btn-block btn-lg" id="clock-out-btn">
                                <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"></path>
                                    <polyline points="16 17 21 12 16 7"></polyline>
                                    <line x1="21" y1="12" x2="9" y2="12"></line>
                                </svg>
                                Fitxar Sortida
                            </button>
                        </form>
                    <?php else: ?>
                        <div class="text-center" style="padding: 1rem 0;">
                            <span class="badge badge-danger" style="font-size: 0.875rem; padding: 0.5rem 1rem;">
                                <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="margin-right: 0.25rem;">
                                    <circle cx="12" cy="12" r="10"></circle>
                                    <line x1="15" y1="9" x2="9" y2="15"></line>
                                    <line x1="9" y1="9" x2="15" y2="15"></line>
                                </svg>
                                No fitxat
                            </span>
                        </div>
                        
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
                            
                            <button type="submit" class="btn btn-success btn-block btn-lg" id="clock-in-btn">
                                <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4"></path>
                                    <polyline points="10 17 15 12 10 7"></polyline>
                                    <line x1="15" y1="12" x2="3" y2="12"></line>
                                </svg>
                                Fitxar Entrada
                            </button>
                        </form>
                    <?php endif; ?>
                </div>

                <!-- Statistics Card -->
                <div class="card">
                    <div class="card-header">
                        <h3>
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <line x1="18" y1="20" x2="18" y2="10"></line>
                                <line x1="12" y1="20" x2="12" y2="4"></line>
                                <line x1="6" y1="20" x2="6" y2="14"></line>
                            </svg>
                            Estadístiques del Mes
                        </h3>
                    </div>
                    
                    <div class="text-center" style="padding: 1.5rem 0;">
                        <div class="stat-number" style="font-size: 2.5rem; color: var(--primary);"><?php echo number_format($stats['total_hours'], 2); ?>h</div>
                        <div class="stat-label">Hores Treballades</div>
                    </div>
                    
                    <div style="margin-top: 1rem; padding-top: 1rem; border-top: 1px solid var(--border);">
                        <div class="d-flex justify-between mb-2">
                            <span class="text-muted">Hores Contractades</span>
                            <strong><?php echo number_format($userData['hores_contractades'], 2); ?>h/dia</strong>
                        </div>
                        <div class="d-flex justify-between">
                            <span class="text-muted">Registres aquest mes</span>
                            <strong><?php echo $stats['total_entries']; ?></strong>
                        </div>
                    </div>
                </div>

                <!-- Weekly Hours Chart -->
                <div class="card">
                    <div class="card-header">
                        <h3>
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <line x1="18" y1="20" x2="18" y2="10"></line>
                                <line x1="12" y1="20" x2="12" y2="4"></line>
                                <line x1="6" y1="20" x2="6" y2="14"></line>
                            </svg>
                            Hores Setmanals
                        </h3>
                    </div>
                    <div class="chart-container">
                        <canvas id="weeklyChart"></canvas>
                    </div>
                </div>

                <!-- Today's Project Hours Breakdown -->
                <?php if (!empty($todayProjectHours)): ?>
                <div class="card">
                    <div class="card-header">
                        <h3>
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <circle cx="12" cy="12" r="10"></circle>
                                <line x1="12" y1="8" x2="12" y2="12"></line>
                                <line x1="12" y1="16" x2="12.01" y2="16"></line>
                            </svg>
                            Hores per Projecte (Avui)
                        </h3>
                    </div>
                    <div>
                        <?php 
                        $colors = ['#3B82F6', '#10B981', '#F59E0B', '#EF4444', '#8B5CF6', '#EC4899'];
                        $colorIdx = 0;
                        foreach ($todayProjectHours as $proj): 
                            if ($proj['total_hours'] == 0 && !$currentEntry) continue;
                            $percentage = $todayTotalFromProjects > 0 
                                ? ($proj['total_hours'] / $todayTotalFromProjects) * 100 
                                : 0;
                            $color = $colors[$colorIdx % count($colors)];
                        ?>
                            <div style="padding: 0.75rem 0; border-bottom: 1px solid var(--border);">
                                <div class="d-flex justify-between mb-1">
                                    <span style="font-weight: 500;"><?php echo e($proj['nom']); ?></span>
                                    <span class="text-muted"><?php echo number_format($proj['total_hours'], 2); ?>h</span>
                                </div>
                                <div class="progress-bar">
                                    <div class="progress-fill" style="width: <?php echo max(2, $percentage); ?>%; background: <?php echo $color; ?>;"></div>
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
                        <h3>
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path>
                                <polyline points="14 2 14 8 20 8"></polyline>
                                <line x1="16" y1="13" x2="8" y2="13"></line>
                                <line x1="16" y1="17" x2="8" y2="17"></line>
                                <polyline points="10 9 9 9 8 9"></polyline>
                            </svg>
                            Últims Registres
                        </h3>
                    </div>
                    
                    <?php if (empty($timeEntries)): ?>
                        <p class="text-muted text-center" style="padding: 2rem;">No hi ha registres recents.</p>
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
                                            <td><strong><?php echo e($entry['project_name']); ?></strong></td>
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
                                                    <strong><?php echo number_format($entry['hores_totals'], 2); ?>h</strong>
                                                <?php else: ?>
                                                    <span class="text-muted">-</span>
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
    </div>

    <footer class="footer">
        <div class="container">
            <p>StaffLog &copy; <?php echo date('Y'); ?> - Gestió de temps i projectes</p>
        </div>
    </footer>

    <script src="assets/js/main.js"></script>
    <script>
    document.addEventListener('DOMContentLoaded', function() {
        // Live Clock
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

        // Weekly Hours Chart
        const weeklyCtx = document.getElementById('weeklyChart');
        if (weeklyCtx) {
            new Chart(weeklyCtx.getContext('2d'), {
                type: 'bar',
                data: {
                    labels: ['Dg', 'Dl', 'Dt', 'Dc', 'Dj', 'Dv', 'Ds'],
                    datasets: [{
                        label: 'Hores treballades',
                        data: <?php echo json_encode($chartWeeklyData); ?>,
                        backgroundColor: 'rgba(59, 130, 246, 0.8)',
                        borderColor: 'rgba(59, 130, 246, 1)',
                        borderWidth: 0,
                        borderRadius: 6
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: { display: false },
                        tooltip: {
                            backgroundColor: '#1E293B',
                            titleFont: { family: "'Inter', sans-serif", size: 13 },
                            bodyFont: { family: "'Inter', sans-serif", size: 12 },
                            padding: 12,
                            cornerRadius: 8,
                            callbacks: {
                                label: function(context) {
                                    return context.raw + ' hores';
                                }
                            }
                        }
                    },
                    scales: {
                        x: {
                            grid: { display: false },
                            ticks: {
                                font: { family: "'Inter', sans-serif", size: 11 },
                                color: '#64748B'
                            }
                        },
                        y: {
                            beginAtZero: true,
                            grid: { color: '#E2E8F0', drawBorder: false },
                            ticks: {
                                font: { family: "'Inter', sans-serif", size: 11 },
                                color: '#64748B',
                                callback: function(value) {
                                    return value + 'h';
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