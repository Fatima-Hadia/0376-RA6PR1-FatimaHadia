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
</body>
</html>