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
</body>
</html>