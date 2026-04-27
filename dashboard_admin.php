<?php
/**
 * StaffLog - Admin Dashboard
 * Displays admin overview of all employees, projects, and alerts.
 */

// Start session and load required files
session_start();
require_once 'includes/db.php';
require_once 'includes/auth.php';

// Require admin
requireAdmin();

// Check session timeout
checkSessionTimeout();

// Get current user info
$userName = getCurrentUserName();

// Handle actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $csrf_token = $_POST['csrf_token'] ?? '';
    
    if (!verifyCsrfToken($csrf_token)) {
        setFlashMessage('error', 'Token de seguretat invàlid.');
    } else {
        // Deactivate user
        if ($action === 'deactivate_user' && isset($_POST['user_id'])) {
            $userId = filter_input(INPUT_POST, 'user_id', FILTER_VALIDATE_INT);
            if ($userId && $userId != getCurrentUserId()) {
                executeQuery("UPDATE users SET actiu = 0 WHERE id = :id", ['id' => $userId]);
                setFlashMessage('success', 'Usuari desactivat correctament.');
            }
        }
        
        // Activate user
        if ($action === 'activate_user' && isset($_POST['user_id'])) {
            $userId = filter_input(INPUT_POST, 'user_id', FILTER_VALIDATE_INT);
            if ($userId) {
                executeQuery("UPDATE users SET actiu = 1 WHERE id = :id", ['id' => $userId]);
                setFlashMessage('success', 'Usuari activat correctament.');
            }
        }
        
        // Create alert for user
        if ($action === 'create_alert' && isset($_POST['user_id'], $_POST['tipus'], $_POST['data'])) {
            $userId = filter_input(INPUT_POST, 'user_id', FILTER_VALIDATE_INT);
            $tipus = $_POST['tipus'];
            $data = $_POST['data'];
            
            if ($userId && in_array($tipus, ['absencia', 'retard', 'sortida_aviat'])) {
                executeQuery(
                    "INSERT INTO alerts (user_id, tipus, data) VALUES (:user_id, :tipus, :data)",
                    ['user_id' => $userId, 'tipus' => $tipus, 'data' => $data]
                );
                setFlashMessage('success', 'Alerta creada correctament.');
            }
        }
    }
    
    header('Location: dashboard_admin.php');
    exit;
}

// Get overall statistics
$stats = fetchOne("
    SELECT 
        (SELECT COUNT(*) FROM users WHERE actiu = 1 AND rol = 'empleat') as active_employees,
        (SELECT COUNT(*) FROM users WHERE rol = 'admin') as admins,
        (SELECT COUNT(*) FROM projects WHERE estat = 'actiu') as active_projects,
        (SELECT COALESCE(SUM(hores_totals), 0) FROM time_entries 
         WHERE YEAR(entrada) = YEAR(CURRENT_DATE()) AND MONTH(entrada) = MONTH(CURRENT_DATE()) 
         AND sortida IS NOT NULL) as total_hours_this_month
");

// Get all employees
$employees = fetchAll("
    SELECT u.*, 
           COALESCE(SUM(te.hores_totals), 0) as hours_this_month,
           (SELECT COUNT(*) FROM alerts WHERE user_id = u.id AND llegida = 0) as unread_alerts
    FROM users u
    LEFT JOIN time_entries te ON u.id = te.user_id 
        AND YEAR(te.entrada) = YEAR(CURRENT_DATE()) 
        AND MONTH(te.entrada) = MONTH(CURRENT_DATE())
        AND te.sortida IS NOT NULL
    WHERE u.rol = 'empleat'
    GROUP BY u.id
    ORDER BY u.nom
");

// Get all projects
$projects = fetchAll("
    SELECT p.*, 
           COALESCE(SUM(te.hores_totals), 0) as hours_logged,
           (SELECT COUNT(*) FROM users) as team_size
    FROM projects p
    LEFT JOIN time_entries te ON p.id = te.project_id AND te.sortida IS NOT NULL
    GROUP BY p.id
    ORDER BY p.estat DESC, p.nom
");

// Get recent time entries from all users
$recentEntries = fetchAll("
    SELECT te.*, u.nom as user_name, p.nom as project_name
    FROM time_entries te
    JOIN users u ON te.user_id = u.id
    JOIN projects p ON te.project_id = p.id
    ORDER BY te.entrada DESC
    LIMIT 15
");

// Get all unresolved alerts
$alerts = fetchAll("
    SELECT a.*, u.nom as user_name
    FROM alerts a
    JOIN users u ON a.user_id = u.id
    WHERE a.llegida = 0
    ORDER BY a.data DESC
");

// LLISTA VERMELLA - Employees who haven't met their contracted hours today
$llistaVermella = fetchAll("
    SELECT u.id, u.nom, u.hores_contractades, 
           COALESCE(SUM(te.hores_totals), 0) as hores_fetes,
           (u.hores_contractades - COALESCE(SUM(te.hores_totals), 0)) as hores_falten
    FROM users u
    LEFT JOIN time_entries te ON u.id = te.user_id 
        AND DATE(te.entrada) = CURDATE() 
        AND te.sortida IS NOT NULL
    WHERE u.rol = 'empleat' AND u.actiu = 1
    GROUP BY u.id, u.nom, u.hores_contractades
    HAVING hores_fetes < u.hores_contractades
    ORDER BY hores_fetes ASC
");

// REAL-TIME EMPLOYEE STATUS - Currently clocked in employees
$employeeStatus = fetchAll("
    SELECT u.id, u.nom, 
           CASE WHEN te.sortida IS NULL THEN 1 ELSE 0 END as is_clocked_in,
           te.entrada as clock_in_time,
           p.nom as current_project,
           p.id as project_id
    FROM users u
    LEFT JOIN time_entries te ON u.id = te.user_id AND te.sortida IS NULL
    LEFT JOIN projects p ON te.project_id = p.id
    WHERE u.rol = 'empleat' AND u.actiu = 1
    ORDER BY is_clocked_in DESC, u.nom
");

// WEEKLY PROJECT HOURS CHART DATA
$weeklyProjectHours = fetchAll("
    SELECT p.nom, COALESCE(SUM(te.hores_totals), 0) as total_hours
    FROM projects p
    LEFT JOIN time_entries te ON p.id = te.project_id 
        AND te.sortida IS NOT NULL
        AND WEEK(te.entrada) = WEEK(NOW())
    GROUP BY p.id, p.nom
    ORDER BY total_hours DESC
    LIMIT 10
");

$chartProjectLabels = [];
$chartProjectData = [];
foreach ($weeklyProjectHours as $row) {
    $chartProjectLabels[] = $row['nom'];
    $chartProjectData[] = (float)$row['total_hours'];
}

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
    <title>StaffLog - Dashboard Administrador</title>
    <link rel="stylesheet" href="assets/css/style.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        .llista-vermella {
            background: linear-gradient(135deg, #fff5f5, #ffe0e0);
            border: 2px solid #e74c3c;
            border-radius: var(--border-radius);
            padding: 1.5rem;
            margin-bottom: 1.5rem;
        }
        .llista-vermella h3 {
            color: #c0392b;
            margin: 0 0 1rem 0;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        .llista-vermella table {
            box-shadow: none;
        }
        .llista-vermella table thead {
            background: #e74c3c;
        }
        .status-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
            gap: 1rem;
            margin-top: 1rem;
        }
        .status-card {
            background: white;
            padding: 1rem;
            border-radius: var(--border-radius);
            box-shadow: var(--shadow);
            text-align: center;
            border-left: 4px solid #ccc;
        }
        .status-card.clocked-in {
            border-left-color: var(--success-color);
        }
        .status-card.not-clocked {
            border-left-color: var(--danger-color);
        }
        .status-card .employee-name {
            font-weight: 600;
            margin-bottom: 0.5rem;
            color: var(--primary-color);
        }
        .status-card .status-badge {
            display: inline-block;
            padding: 0.25rem 0.5rem;
            border-radius: 12px;
            font-size: 0.75rem;
            font-weight: 600;
            margin-bottom: 0.25rem;
        }
        .status-card .status-badge.online {
            background: #d4edda;
            color: #155724;
        }
        .status-card .status-badge.offline {
            background: #f8d7da;
            color: #721c24;
        }
        .status-card .project-name {
            font-size: 0.85rem;
            color: #666;
        }
        .status-card .clock-time {
            font-size: 0.75rem;
            color: #999;
            margin-top: 0.25rem;
        }
        .chart-container {
            position: relative;
            height: 250px;
            margin-top: 1rem;
        }
        .section-title {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            margin-bottom: 1rem;
        }
        .full-width-card {
            grid-column: 1 / -1;
        }
    </style>
</head>
<body>
    <header class="header">
        <div class="container">
            <h1>StaffLog - Admin</h1>
            <nav>
                <span>Benvingut/da, <?php echo e($userName); ?></span>
                <a href="dashboard_employee.php">Veure Com Empleado</a>
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

        <!-- Statistics Cards -->
        <div class="dashboard-grid">
            <div class="stat-card">
                <div class="stat-number"><?php echo $stats['active_employees']; ?></div>
                <div class="stat-label">Empleats Actius</div>
            </div>
            
            <div class="stat-card">
                <div class="stat-number"><?php echo $stats['active_projects']; ?></div>
                <div class="stat-label">Projectes Actius</div>
            </div>
            
            <div class="stat-card">
                <div class="stat-number"><?php echo number_format($stats['total_hours_this_month'], 1); ?>h</div>
                <div class="stat-label">Hores Aquest Mes</div>
            </div>
            
            <div class="stat-card">
                <div class="stat-number"><?php echo $stats['admins']; ?></div>
                <div class="stat-label">Administradors</div>
            </div>
        </div>

        <!-- LLISTA VERMELLA Section -->
        <?php if (!empty($llistaVermella)): ?>
        <div class="llista-vermella">
            <h3>
                <span>⚠️</span>
                Llista Vermella - Empleats per sota d'hores contractades avui
            </h3>
            <div class="table-responsive">
                <table>
                    <thead>
                        <tr>
                            <th>Nom</th>
                            <th>Hores Contractades</th>
                            <th>Hores Fetes Avui</th>
                            <th>Falten</th>
                            <th>% Completat</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($llistaVermella as $emp): ?>
                            <tr>
                                <td><?php echo e($emp['nom']); ?></td>
                                <td><?php echo number_format($emp['hores_contractades'], 2); ?>h</td>
                                <td><?php echo number_format($emp['hores_fetes'], 2); ?>h</td>
                                <td style="color: #e74c3c; font-weight: 600;">
                                    <?php echo number_format($emp['hores_falten'], 2); ?>h
                                </td>
                                <td>
                                    <?php 
                                    $percent = ($emp['hores_contractades'] > 0) 
                                        ? ($emp['hores_fetes'] / $emp['hores_contractades']) * 100 
                                        : 0;
                                    ?>
                                    <div style="display: flex; align-items: center; gap: 0.5rem;">
                                        <div style="width: 80px; height: 8px; background: #eee; border-radius: 4px; overflow: hidden;">
                                            <div style="width: <?php echo $percent; ?>%; height: 100%; background: <?php echo $percent < 50 ? '#e74c3c' : '#f39c12'; ?>; border-radius: 4px;"></div>
                                        </div>
                                        <span><?php echo number_format($percent, 0); ?>%</span>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>

        <!-- Real-time Employee Status Grid -->
        <div class="card full-width-card">
            <div class="section-title">
                <h3 style="margin: 0;">📡 Estat dels Empleats en Temps Real</h3>
            </div>
            <div class="status-grid">
                <?php foreach ($employeeStatus as $emp): ?>
                    <div class="status-card <?php echo $emp['is_clocked_in'] ? 'clocked-in' : 'not-clocked'; ?>">
                        <div class="employee-name"><?php echo e($emp['nom']); ?></div>
                        <?php if ($emp['is_clocked_in']): ?>
                            <span class="status-badge online">● Connectat</span>
                            <div class="project-name">
                                <?php echo $emp['current_project'] ? e($emp['current_project']) : 'Sense projecte'; ?>
                            </div>
                            <div class="clock-time">
                                Des de: <?php echo $emp['clock_in_time'] ? date('H:i', strtotime($emp['clock_in_time'])) : '-'; ?>
                            </div>
                        <?php else: ?>
                            <span class="status-badge offline">○ Desconnectat</span>
                            <div class="project-name">-</div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Weekly Project Hours Chart -->
        <div class="card full-width-card">
            <div class="section-title">
                <h3 style="margin: 0;">📊 Hores per Projecte Aquesta Setmana</h3>
            </div>
            <div class="chart-container">
                <canvas id="projectChart"></canvas>
            </div>
        </div>

        <!-- Employees and Projects Section -->
        <div class="dashboard-grid" style="margin-top: 2rem;">
            <!-- Employees List -->
            <div class="card">
                <div class="card-header">
                    <h3>Empleats</h3>
                </div>
                
                <?php if (empty($employees)): ?>
                    <p>No hi ha empleats registrats.</p>
                <?php else: ?>
                    <div class="table-responsive">
                        <table>
                            <thead>
                                <tr>
                                    <th>Nom</th>
                                    <th>Email</th>
                                    <th>Hores Mes</th>
                                    <th>Estat</th>
                                    <th>Accions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($employees as $employee): ?>
                                    <tr>
                                        <td><?php echo e($employee['nom']); ?></td>
                                        <td><?php echo e($employee['email']); ?></td>
                                        <td><?php echo number_format($employee['hours_this_month'], 2); ?>h</td>
                                        <td>
                                            <?php if ($employee['actiu']): ?>
                                                <span class="badge badge-success">Actiu</span>
                                            <?php else: ?>
                                                <span class="badge badge-danger">Inactiu</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($employee['actiu']): ?>
                                                <form method="POST" style="display: inline;">
                                                    <input type="hidden" name="action" value="deactivate_user">
                                                    <input type="hidden" name="user_id" value="<?php echo $employee['id']; ?>">
                                                    <input type="hidden" name="csrf_token" value="<?php echo e($csrf_token); ?>">
                                                    <button type="submit" class="btn btn-danger" style="padding: 0.25rem 0.5rem; font-size: 0.85rem;" 
                                                            data-confirm="Segur que vols desactivar aquest usuari?">
                                                        Desactivar
                                                    </button>
                                                </form>
                                            <?php else: ?>
                                                <form method="POST" style="display: inline;">
                                                    <input type="hidden" name="action" value="activate_user">
                                                    <input type="hidden" name="user_id" value="<?php echo $employee['id']; ?>">
                                                    <input type="hidden" name="csrf_token" value="<?php echo e($csrf_token); ?>">
                                                    <button type="submit" class="btn btn-success" style="padding: 0.25rem 0.5rem; font-size: 0.85rem;">
                                                        Activar
                                                    </button>
                                                </form>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Projects List -->
            <div class="card">
                <div class="card-header">
                    <h3>Projectes</h3>
                </div>
                
                <?php if (empty($projects)): ?>
                    <p>No hi ha projectes registrats.</p>
                <?php else: ?>
                    <div class="table-responsive">
                        <table>
                            <thead>
                                <tr>
                                    <th>Nom</th>
                                    <th>Client</th>
                                    <th>Hores Pressupostades</th>
                                    <th>Hores Registrades</th>
                                    <th>Estat</th>
                                    <th>Report</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($projects as $project): ?>
                                    <tr>
                                        <td><?php echo e($project['nom']); ?></td>
                                        <td><?php echo e($project['client'] ?? '-'); ?></td>
                                        <td><?php echo number_format($project['hores_pressupostades'], 2); ?>h</td>
                                        <td><?php echo number_format($project['hours_logged'], 2); ?>h</td>
                                        <td>
                                            <?php if ($project['estat'] === 'actiu'): ?>
                                                <span class="badge badge-success">Actiu</span>
                                            <?php else: ?>
                                                <span class="badge badge-danger">Tancat</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <a href="report_projecte.php?id=<?php echo $project['id']; ?>" class="btn btn-primary" style="padding: 0.25rem 0.5rem; font-size: 0.85rem;">
                                                Veure
                                            </a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Recent Entries and Alerts -->
        <div class="dashboard-grid" style="margin-top: 1.5rem;">
            <!-- Recent Time Entries -->
            <div class="card">
                <div class="card-header">
                    <h3>Registres Recents</h3>
                </div>
                
                <?php if (empty($recentEntries)): ?>
                    <p>No hi ha registres recents.</p>
                <?php else: ?>
                    <div class="table-responsive">
                        <table>
                            <thead>
                                <tr>
                                    <th>Usuari</th>
                                    <th>Projecte</th>
                                    <th>Entrada</th>
                                    <th>Sortida</th>
                                    <th>Hores</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($recentEntries as $entry): ?>
                                    <tr>
                                        <td><?php echo e($entry['user_name']); ?></td>
                                        <td><?php echo e($entry['project_name']); ?></td>
                                        <td><?php echo date('d/m/Y H:i', strtotime($entry['entrada'])); ?></td>
                                        <td>
                                            <?php if ($entry['sortida']): ?>
                                                <?php echo date('d/m/Y H:i', strtotime($entry['sortida'])); ?>
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

            <!-- Alerts -->
            <div class="card">
                <div class="card-header">
                    <h3>Alertes Pendents</h3>
                </div>
                
                <?php if (empty($alerts)): ?>
                    <p>No hi ha alertes pendents.</p>
                <?php else: ?>
                    <div class="table-responsive">
                        <table>
                            <thead>
                                <tr>
                                    <th>Usuari</th>
                                    <th>Tipus</th>
                                    <th>Data</th>
                                    <th>Acció</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($alerts as $alert): ?>
                                    <tr>
                                        <td><?php echo e($alert['user_name']); ?></td>
                                        <td>
                                            <?php
                                            $tipusLabels = [
                                                'absencia' => 'Absència',
                                                'retard' => 'Retard',
                                                'sortida_aviat' => 'Sortida Aviat'
                                            ];
                                            echo $tipusLabels[$alert['tipus']] ?? $alert['tipus'];
                                            ?>
                                        </td>
                                        <td><?php echo date('d/m/Y', strtotime($alert['data'])); ?></td>
                                        <td>
                                            <form method="POST" style="display: inline;">
                                                <input type="hidden" name="action" value="mark_alert_read">
                                                <input type="hidden" name="alert_id" value="<?php echo $alert['id']; ?>">
                                                <input type="hidden" name="csrf_token" value="<?php echo e($csrf_token); ?>">
                                                <button type="submit" class="btn btn-primary" style="padding: 0.25rem 0.5rem; font-size: 0.85rem;">
                                                    Marcar com Llegida
                                                </button>
                                            </form>
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
            <p>StaffLog Admin &copy; <?php echo date('Y'); ?> - Gestió de temps i projectes</p>
        </div>
    </footer>

    <script src="assets/js/main.js"></script>
    <script>
    document.addEventListener('DOMContentLoaded', function() {
        // Weekly Project Hours Chart
        const projectCtx = document.getElementById('projectChart');
        if (projectCtx) {
            new Chart(projectCtx.getContext('2d'), {
                type: 'bar',
                data: {
                    labels: <?php echo json_encode($chartProjectLabels); ?>,
                    datasets: [{
                        label: 'Hores treballades',
                        data: <?php echo json_encode($chartProjectData); ?>,
                        backgroundColor: 'rgba(52, 152, 219, 0.7)',
                        borderColor: 'rgba(52, 152, 219, 1)',
                        borderWidth: 1
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    indexAxis: 'y',
                    scales: {
                        x: {
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