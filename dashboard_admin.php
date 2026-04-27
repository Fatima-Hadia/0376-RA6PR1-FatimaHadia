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
</head>
<body>
    <div class="admin-layout">
        <!-- Top Header -->
        <header class="header">
            <div class="d-flex align-center gap-2">
                <button class="hamburger" aria-label="Menu">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <line x1="3" y1="12" x2="21" y2="12"></line>
                        <line x1="3" y1="6" x2="21" y2="6"></line>
                        <line x1="3" y1="18" x2="21" y2="18"></line>
                    </svg>
                </button>
                <h1>
                    <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <rect x="3" y="3" width="18" height="18" rx="2" ry="2"></rect>
                        <line x1="3" y1="9" x2="21" y2="9"></line>
                        <line x1="9" y1="21" x2="9" y2="9"></line>
                    </svg>
                    StaffLog Admin
                </h1>
            </div>
            <nav>
                <span><?php echo e($userName); ?></span>
                <a href="dashboard_employee.php">Veure Com Empleado</a>
                <a href="logout.php">Tancar Sessió</a>
            </nav>
        </header>

        <!-- Sidebar -->
        <aside class="sidebar">
            <nav>
                <div class="nav-section">Principal</div>
                <ul class="sidebar-nav">
                    <li>
                        <a href="dashboard_admin.php" class="active">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <rect x="3" y="3" width="7" height="7"></rect>
                                <rect x="14" y="3" width="7" height="7"></rect>
                                <rect x="14" y="14" width="7" height="7"></rect>
                                <rect x="3" y="14" width="7" height="7"></rect>
                            </svg>
                            Dashboard
                        </a>
                    </li>
                </ul>

                <div class="nav-section">Gestió</div>
                <ul class="sidebar-nav">
                    <li>
                        <a href="empleats.php">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path>
                                <circle cx="9" cy="7" r="4"></circle>
                                <path d="M23 21v-2a4 4 0 0 0-3-3.87"></path>
                                <path d="M16 3.13a4 4 0 0 1 0 7.75"></path>
                            </svg>
                            Empleats
                        </a>
                    </li>
                    <li>
                        <a href="projectes.php">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"></path>
                            </svg>
                            Projectes
                        </a>
                    </li>
                    <li>
                        <a href="alertes.php">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"></path>
                                <path d="M13.73 21a2 2 0 0 1-3.46 0"></path>
                            </svg>
                            Alertes
                            <?php 
                            $unreadAlerts = fetchOne("SELECT COUNT(*) as count FROM alerts WHERE llegida = 0");
                            if ($unreadAlerts['count'] > 0): ?>
                                <span class="badge badge-danger" style="margin-left: auto;"><?php echo $unreadAlerts['count']; ?></span>
                            <?php endif; ?>
                        </a>
                    </li>
                </ul>

                <div class="nav-section">Compte</div>
                <ul class="sidebar-nav">
                    <li>
                        <a href="logout.php">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"></path>
                                <polyline points="16 17 21 12 16 7"></polyline>
                                <line x1="21" y1="12" x2="9" y2="12"></line>
                            </svg>
                            Tancar Sessió
                        </a>
                    </li>
                </ul>
            </nav>
        </aside>

        <!-- Main Content -->
        <main class="main-content">
            <?php if ($flash): ?>
                <div class="alert alert-<?php echo e($flash['type']); ?> fade-in">
                    <?php if ($flash['type'] === 'success'): ?>
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path>
                            <polyline points="22 4 12 14.01 9 11.01"></polyline>
                        </svg>
                    <?php elseif ($flash['type'] === 'error'): ?>
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <circle cx="12" cy="12" r="10"></circle>
                            <line x1="15" y1="9" x2="9" y2="15"></line>
                            <line x1="9" y1="9" x2="15" y2="15"></line>
                        </svg>
                    <?php endif; ?>
                    <?php echo e($flash['message']); ?>
                </div>
            <?php endif; ?>

            <!-- Statistics Cards -->
            <div class="dashboard-grid">
                <div class="stat-card">
                    <div class="stat-number"><?php echo $stats['active_employees']; ?></div>
                    <div class="stat-label">Empleats Actius</div>
                </div>
                
                <div class="stat-card success">
                    <div class="stat-number"><?php echo $stats['active_projects']; ?></div>
                    <div class="stat-label">Projectes Actius</div>
                </div>
                
                <div class="stat-card warning">
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
            <div class="llista-vermella fade-in">
                <h3>
                    <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"></path>
                        <line x1="12" y1="9" x2="12" y2="13"></line>
                        <line x1="12" y1="17" x2="12.01" y2="17"></line>
                    </svg>
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
                                    <td><strong><?php echo e($emp['nom']); ?></strong></td>
                                    <td><?php echo number_format($emp['hores_contractades'], 2); ?>h</td>
                                    <td><?php echo number_format($emp['hores_fetes'], 2); ?>h</td>
                                    <td style="color: var(--danger); font-weight: 600;">
                                        <?php echo number_format($emp['hores_falten'], 2); ?>h
                                    </td>
                                    <td>
                                        <?php 
                                        $percent = ($emp['hores_contractades'] > 0) 
                                            ? ($emp['hores_fetes'] / $emp['hores_contractades']) * 100 
                                            : 0;
                                        ?>
                                        <div class="d-flex align-center gap-1">
                                            <div class="progress-bar" style="width: 80px;">
                                                <div class="progress-fill" style="width: <?php echo $percent; ?>%; background: <?php echo $percent < 50 ? 'var(--danger)' : 'var(--warning)'; ?>;"></div>
                                            </div>
                                            <span style="font-size: 0.8125rem;"><?php echo number_format($percent, 0); ?>%</span>
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
            <div class="card">
                <div class="card-header">
                    <h3>
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M22 12h-4l-3 9L9 3l-3 9H2"></path>
                        </svg>
                        Estat dels Empleats en Temps Real
                    </h3>
                </div>
                <div class="status-grid">
                    <?php foreach ($employeeStatus as $emp): ?>
                        <div class="status-card <?php echo $emp['is_clocked_in'] ? 'online' : 'offline'; ?>">
                            <div class="employee-name"><?php echo e($emp['nom']); ?></div>
                            <?php if ($emp['is_clocked_in']): ?>
                                <span class="status-indicator online">
                                    <span style="width: 6px; height: 6px; border-radius: 50%; background: currentColor;"></span>
                                    Connectat
                                </span>
                                <div class="project-name">
                                    <?php echo $emp['current_project'] ? e($emp['current_project']) : 'Sense projecte'; ?>
                                </div>
                                <div class="clock-time">
                                    Des de: <?php echo $emp['clock_in_time'] ? date('H:i', strtotime($emp['clock_in_time'])) : '-'; ?>
                                </div>
                            <?php else: ?>
                                <span class="status-indicator offline">
                                    <span style="width: 6px; height: 6px; border-radius: 50%; background: currentColor;"></span>
                                    Desconnectat
                                </span>
                                <div class="project-name">-</div>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- Weekly Project Hours Chart -->
            <div class="card">
                <div class="card-header">
                    <h3>
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <line x1="18" y1="20" x2="18" y2="10"></line>
                            <line x1="12" y1="20" x2="12" y2="4"></line>
                            <line x1="6" y1="20" x2="6" y2="14"></line>
                        </svg>
                        Hores per Projecte Aquesta Setmana
                    </h3>
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
                        <h3>
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path>
                                <circle cx="9" cy="7" r="4"></circle>
                                <path d="M23 21v-2a4 4 0 0 0-3-3.87"></path>
                                <path d="M16 3.13a4 4 0 0 1 0 7.75"></path>
                            </svg>
                            Empleats
                        </h3>
                    </div>
                    
                    <?php if (empty($employees)): ?>
                        <p class="text-muted">No hi ha empleats registrats.</p>
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
                                            <td><strong><?php echo e($employee['nom']); ?></strong></td>
                                            <td class="text-muted"><?php echo e($employee['email']); ?></td>
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
                                                        <button type="submit" class="btn btn-danger btn-sm" 
                                                                data-confirm="Segur que vols desactivar aquest usuari?">
                                                            Desactivar
                                                        </button>
                                                    </form>
                                                <?php else: ?>
                                                    <form method="POST" style="display: inline;">
                                                        <input type="hidden" name="action" value="activate_user">
                                                        <input type="hidden" name="user_id" value="<?php echo $employee['id']; ?>">
                                                        <input type="hidden" name="csrf_token" value="<?php echo e($csrf_token); ?>">
                                                        <button type="submit" class="btn btn-success btn-sm">
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
                        <h3>
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"></path>
                            </svg>
                            Projectes
                        </h3>
                    </div>
                    
                    <?php if (empty($projects)): ?>
                        <p class="text-muted">No hi ha projectes registrats.</p>
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
                                            <td><strong><?php echo e($project['nom']); ?></strong></td>
                                            <td class="text-muted"><?php echo e($project['client'] ?? '-'); ?></td>
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
                                                <a href="report_projecte.php?id=<?php echo $project['id']; ?>" class="btn btn-primary btn-sm">
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
                        <h3>
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <circle cx="12" cy="12" r="10"></circle>
                                <polyline points="12 6 12 12 16 14"></polyline>
                            </svg>
                            Registres Recents
                        </h3>
                    </div>
                    
                    <?php if (empty($recentEntries)): ?>
                        <p class="text-muted">No hi ha registres recents.</p>
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
                                            <td><strong><?php echo e($entry['user_name']); ?></strong></td>
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

                <!-- Alerts -->
                <div class="card">
                    <div class="card-header">
                        <h3>
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"></path>
                                <path d="M13.73 21a2 2 0 0 1-3.46 0"></path>
                            </svg>
                            Alertes Pendents
                        </h3>
                    </div>
                    
                    <?php if (empty($alerts)): ?>
                        <p class="text-muted text-center" style="padding: 2rem;">
                            <svg xmlns="http://www.w3.org/2000/svg" width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="var(--success)" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" style="margin-bottom: 0.5rem;">
                                <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path>
                                <polyline points="22 4 12 14.01 9 11.01"></polyline>
                            </svg>
                            <br>No hi ha alertes pendents.
                        </p>
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
                                            <td><strong><?php echo e($alert['user_name']); ?></strong></td>
                                            <td>
                                                <?php
                                                $tipusLabels = [
                                                    'absencia' => 'Absència',
                                                    'retard' => 'Retard',
                                                    'sortida_aviat' => 'Sortida Aviat'
                                                ];
                                                $badgeClass = 'badge-info';
                                                if ($alert['tipus'] === 'absencia') $badgeClass = 'badge-danger';
                                                elseif ($alert['tipus'] === 'retard') $badgeClass = 'badge-warning';
                                                ?>
                                                <span class="badge <?php echo $badgeClass; ?>">
                                                    <?php echo $tipusLabels[$alert['tipus']] ?? $alert['tipus']; ?>
                                                </span>
                                            </td>
                                            <td><?php echo date('d/m/Y', strtotime($alert['data'])); ?></td>
                                            <td>
                                                <form method="POST" style="display: inline;">
                                                    <input type="hidden" name="action" value="mark_alert_read">
                                                    <input type="hidden" name="alert_id" value="<?php echo $alert['id']; ?>">
                                                    <input type="hidden" name="csrf_token" value="<?php echo e($csrf_token); ?>">
                                                    <button type="submit" class="btn btn-primary btn-sm">
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
    </div>

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
                        backgroundColor: 'rgba(59, 130, 246, 0.8)',
                        borderColor: 'rgba(59, 130, 246, 1)',
                        borderWidth: 0,
                        borderRadius: 6
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    indexAxis: 'y',
                    plugins: {
                        legend: {
                            display: false
                        },
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
                            beginAtZero: true,
                            grid: { color: '#E2E8F0', drawBorder: false },
                            ticks: {
                                font: { family: "'Inter', sans-serif", size: 11 },
                                color: '#64748B',
                                callback: function(value) {
                                    return value + 'h';
                                }
                            }
                        },
                        y: {
                            grid: { display: false },
                            ticks: {
                                font: { family: "'Inter', sans-serif", size: 11 },
                                color: '#1E293B'
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