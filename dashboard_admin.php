<?php
/**
 * StaffLog - Admin Dashboard
 * Metrics, red list, charts, and employee overview.
 */

// Start session and load required files
session_start();
require_once 'includes/db.php';
require_once 'includes/auth.php';

// Require admin
requireAdmin();

// Get current user info
$userName = getCurrentUserName();

// Generate CSRF token
$csrf_token = generateCsrfToken();

// Handle actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $submittedToken = $_POST['csrf_token'] ?? '';
    
    if (!verifyCsrfToken($submittedToken)) {
        setFlashMessage('error', 'Token de seguretat invàlid.');
    } else {
        // Deactivate user
        if ($action === 'deactivate_user' && isset($_POST['user_id'])) {
            $targetId = filter_input(INPUT_POST, 'user_id', FILTER_VALIDATE_INT);
            if ($targetId && $targetId != getCurrentUserId()) {
                executeQuery("UPDATE users SET actiu = 0 WHERE id = ?", [$targetId]);
                setFlashMessage('success', 'Usuari desactivat correctament.');
            }
        }
        
        // Activate user
        if ($action === 'activate_user' && isset($_POST['user_id'])) {
            $targetId = filter_input(INPUT_POST, 'user_id', FILTER_VALIDATE_INT);
            if ($targetId) {
                executeQuery("UPDATE users SET actiu = 1 WHERE id = ?", [$targetId]);
                setFlashMessage('success', 'Usuari activat correctament.');
            }
        }
        
        // Create alert for user
        if ($action === 'create_alert' && isset($_POST['user_id'], $_POST['tipus'], $_POST['data'])) {
            $targetId = filter_input(INPUT_POST, 'user_id', FILTER_VALIDATE_INT);
            $tipus = $_POST['tipus'];
            $data = $_POST['data'];
            
            if ($targetId && in_array($tipus, ['absencia', 'retard', 'sortida_aviat'])) {
                executeQuery(
                    "INSERT INTO alerts (user_id, tipus, data) VALUES (?, ?, ?)",
                    [$targetId, $tipus, $data]
                );
                setFlashMessage('success', 'Alerta creada correctament.');
            }
        }
    }
    
    header('Location: dashboard_admin.php');
    exit;
}

// === 4 METRIC CARDS ===

// 1. Active employees right now (working today with no clock-out)
$activeNow = fetchOne("SELECT COUNT(*) as count FROM time_entries WHERE sortida IS NULL AND DATE(entrada) = CURDATE()");
$activeNowCount = $activeNow['count'] ?? 0;

// 2. Unread alerts
$unreadAlerts = fetchOne("SELECT COUNT(*) as count FROM alerts WHERE llegida = 0");
$unreadAlertsCount = $unreadAlerts['count'] ?? 0;

// 3. Total hours today
$todayHours = fetchOne("SELECT COALESCE(SUM(hores_totals), 0) as total FROM time_entries WHERE DATE(entrada) = CURDATE() AND sortida IS NOT NULL");
$todayHoursTotal = number_format($todayHours['total'] ?? 0, 1);

// 4. Active projects
$activeProjects = fetchOne("SELECT COUNT(*) as count FROM projects WHERE estat = 'actiu'");
$activeProjectsCount = $activeProjects['count'] ?? 0;

// === BAR CHART: Total hours per project this week ===
$projectHours = fetchAll("
    SELECT p.nom, COALESCE(SUM(te.hores_totals), 0) as total_hours
    FROM projects p
    LEFT JOIN time_entries te ON p.id = te.project_id 
        AND te.entrada >= DATE_SUB(NOW(), INTERVAL 7 DAY)
        AND te.sortida IS NOT NULL
    GROUP BY p.id, p.nom
    ORDER BY total_hours DESC
");

$chartLabels = [];
$chartData = [];
foreach ($projectHours as $row) {
    $chartLabels[] = $row['nom'];
    $chartData[] = (float)$row['total_hours'];
}

// === RED LIST: Employees who worked fewer hours than contracted today ===
$redList = fetchAll("
    SELECT u.nom, u.hores_contractades, COALESCE(SUM(te.hores_totals), 0) as hores_fetes
    FROM users u
    LEFT JOIN time_entries te ON u.id = te.user_id AND DATE(te.entrada) = CURDATE() AND te.sortida IS NOT NULL
    WHERE u.rol = 'empleat' AND u.actiu = 1
    GROUP BY u.id, u.nom, u.hores_contractades
    HAVING hores_fetes < u.hores_contractades
    ORDER BY hores_fetes ASC
");

// === ALL EMPLOYEES with current status ===
$allEmployees = fetchAll("
    SELECT u.id, u.nom, u.email, u.actiu, u.hores_contractades,
           CASE WHEN te.sortida IS NULL AND te.entrada IS NOT NULL THEN 'working' ELSE 'idle' END as status,
           p.nom as current_project
    FROM users u
    LEFT JOIN time_entries te ON u.id = te.user_id AND te.sortida IS NULL
    LEFT JOIN projects p ON te.project_id = p.id
    WHERE u.rol = 'empleat'
    ORDER BY u.nom
");

// Get flash message
$flash = getFlashMessage();
?>
<!DOCTYPE html>
<html lang="ca">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>StaffLog - Admin Dashboard</title>
    <link rel="stylesheet" href="assets/css/style.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        .admin-layout {
            display: flex;
            gap: 1.5rem;
        }
        .sidebar {
            width: 220px;
            flex-shrink: 0;
        }
        .sidebar-nav {
            background: white;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            padding: 1rem 0;
            position: sticky;
            top: 20px;
        }
        .sidebar-nav h3 {
            padding: 0 1.2rem 0.8rem;
            margin: 0;
            font-size: 0.85rem;
            text-transform: uppercase;
            color: #888;
            letter-spacing: 1px;
            border-bottom: 1px solid #eee;
            margin-bottom: 0.5rem;
        }
        .sidebar-nav a {
            display: block;
            padding: 0.75rem 1.2rem;
            color: #333;
            text-decoration: none;
            transition: all 0.2s;
            border-left: 3px solid transparent;
        }
        .sidebar-nav a:hover {
            background: #f8f9fa;
            border-left-color: var(--secondary-color);
        }
        .sidebar-nav a.active {
            background: #e8f4fd;
            border-left-color: var(--secondary-color);
            color: var(--secondary-color);
            font-weight: 600;
        }
        .main-content {
            flex: 1;
            min-width: 0;
        }
        .metric-cards {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 1rem;
            margin-bottom: 1.5rem;
        }
        .metric-card {
            background: white;
            border-radius: 8px;
            padding: 1.25rem;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
            border-left: 4px solid var(--secondary-color);
        }
        .metric-card.warning {
            border-left-color: var(--warning-color);
        }
        .metric-card.success {
            border-left-color: var(--success-color);
        }
        .metric-card.danger {
            border-left-color: var(--danger-color);
        }
        .metric-number {
            font-size: 2rem;
            font-weight: 700;
            color: var(--primary-color);
        }
        .metric-label {
            font-size: 0.85rem;
            color: #666;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-top: 0.25rem;
        }
        .two-columns {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1.5rem;
            margin-bottom: 1.5rem;
        }
        .red-list {
            border-left-color: #e74c3c;
        }
        .red-list-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 0.75rem 0;
            border-bottom: 1px solid #eee;
        }
        .red-list-item:last-child {
            border-bottom: none;
        }
        .red-list-name {
            font-weight: 600;
        }
        .red-list-hours {
            font-size: 0.9rem;
            color: #e74c3c;
        }
        .chart-container {
            position: relative;
            height: 280px;
        }
        .employee-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
            gap: 1rem;
        }
        .employee-card {
            background: white;
            border-radius: 8px;
            padding: 1rem;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }
        .employee-avatar {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: var(--secondary-color);
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            font-size: 0.9rem;
            flex-shrink: 0;
        }
        .employee-info {
            min-width: 0;
        }
        .employee-name {
            font-weight: 600;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .employee-status {
            font-size: 0.8rem;
            color: #666;
        }
        .status-dot {
            display: inline-block;
            width: 8px;
            height: 8px;
            border-radius: 50%;
            margin-right: 4px;
        }
        .status-dot.working {
            background: #27ae60;
        }
        .status-dot.idle {
            background: #95a5a6;
        }
        @media (max-width: 1024px) {
            .admin-layout {
                flex-direction: column;
            }
            .sidebar {
                width: 100%;
            }
            .sidebar-nav {
                position: static;
                display: flex;
                flex-wrap: wrap;
            }
            .sidebar-nav h3 {
                width: 100%;
            }
            .sidebar-nav a {
                flex: 1;
                min-width: 120px;
                border-left: none;
                border-bottom: 3px solid transparent;
            }
            .sidebar-nav a.active {
                border-left: none;
                border-bottom-color: var(--secondary-color);
            }
            .metric-cards {
                grid-template-columns: repeat(2, 1fr);
            }
            .two-columns {
                grid-template-columns: 1fr;
            }
        }
        @media (max-width: 600px) {
            .metric-cards {
                grid-template-columns: 1fr;
            }
            .employee-grid {
                grid-template-columns: 1fr;
            }
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

    <div class="container" style="margin-top: 1.5rem;">
        <?php if ($flash): ?>
            <div class="alert alert-<?php echo e($flash['type']); ?>">
                <?php echo e($flash['message']); ?>
            </div>
        <?php endif; ?>

        <div class="admin-layout">
            <!-- Sidebar Navigation -->
            <aside class="sidebar">
                <nav class="sidebar-nav">
                    <h3>Navegació</h3>
                    <a href="dashboard_admin.php" class="active">Dashboard</a>
                    <a href="#empleats">Empleats</a>
                    <a href="#projectes">Projectes</a>
                    <a href="#alertes">Alertes</a>
                </nav>
            </aside>

            <!-- Main Content -->
            <main class="main-content">
                <!-- 4 Metric Cards -->
                <div class="metric-cards">
                    <div class="metric-card success">
                        <div class="metric-number"><?php echo $activeNowCount; ?></div>
                        <div class="metric-label">Empleats treballant ara</div>
                    </div>
                    
                    <div class="metric-card warning">
                        <div class="metric-number"><?php echo $unreadAlertsCount; ?></div>
                        <div class="metric-label">Alertes sense llegir</div>
                    </div>
                    
                    <div class="metric-card">
                        <div class="metric-number"><?php echo $todayHoursTotal; ?>h</div>
                        <div class="metric-label">Hores avui</div>
                    </div>
                    
                    <div class="metric-card">
                        <div class="metric-number"><?php echo $activeProjectsCount; ?></div>
                        <div class="metric-label">Projectes actius</div>
                    </div>
                </div>

                <!-- Two Columns: Chart + Red List -->
                <div class="two-columns">
                    <!-- Bar Chart -->
                    <div class="card">
                        <div class="card-header">
                            <h3>Hores per Projecte (Aquesta Setmana)</h3>
                        </div>
                        <div class="chart-container">
                            <canvas id="projectChart"></canvas>
                        </div>
                    </div>

                    <!-- Red List -->
                    <div class="card red-list">
                        <div class="card-header">
                            <h3 style="color: #e74c3c;">⚠️ Llista Vermella</h3>
                        </div>
                        <?php if (empty($redList)): ?>
                            <p style="color: #27ae60; padding: 1rem 0;">✅ Tots els empleats han completat les seves hores avui.</p>
                        <?php else: ?>
                            <?php foreach ($redList as $employee): ?>
                                <div class="red-list-item">
                                    <div>
                                        <div class="red-list-name"><?php echo e($employee['nom']); ?></div>
                                        <div style="font-size: 0.85rem; color: #666;">Contractades: <?php echo number_format($employee['hores_contractades'], 1); ?>h/dia</div>
                                    </div>
                                    <div class="red-list-hours">
                                        <?php echo number_format($employee['hores_fetes'], 2); ?>h fetes
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- All Employees Grid -->
                <div class="card" id="empleats">
                    <div class="card-header">
                        <h3>Tots els Empleats</h3>
                    </div>
                    
                    <?php if (empty($allEmployees)): ?>
                        <p>No hi ha empleats registrats.</p>
                    <?php else: ?>
                        <div class="employee-grid">
                            <?php foreach ($allEmployees as $emp): ?>
                                <div class="employee-card">
                                    <div class="employee-avatar">
                                        <?php echo strtoupper(substr($emp['nom'], 0, 1)); ?>
                                    </div>
                                    <div class="employee-info">
                                        <div class="employee-name"><?php echo e($emp['nom']); ?></div>
                                        <div class="employee-status">
                                            <span class="status-dot <?php echo $emp['status']; ?>"></span>
                                            <?php if ($emp['status'] === 'working'): ?>
                                                Treballant
                                                <?php if ($emp['current_project']): ?>
                                                    a <?php echo e($emp['current_project']); ?>
                                                <?php endif; ?>
                                            <?php else: ?>
                                                No fitxat
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </main>
        </div>
    </div>

    <footer class="footer">
        <div class="container">
            <p>StaffLog Admin &copy; <?php echo date('Y'); ?> - Gestió de temps i projectes</p>
        </div>
    </footer>

    <script>
        // Project hours bar chart
        const ctx = document.getElementById('projectChart').getContext('2d');
        new Chart(ctx, {
            type: 'bar',
            data: {
                labels: <?php echo json_encode($chartLabels); ?>,
                datasets: [{
                    label: 'Hores treballades',
                    data: <?php echo json_encode($chartData); ?>,
                    backgroundColor: [
                        'rgba(52, 152, 219, 0.8)',
                        'rgba(46, 204, 113, 0.8)',
                        'rgba(155, 89, 182, 0.8)',
                        'rgba(241, 196, 15, 0.8)',
                        'rgba(230, 126, 34, 0.8)',
                        'rgba(231, 76, 60, 0.8)'
                    ],
                    borderColor: [
                        'rgba(52, 152, 219, 1)',
                        'rgba(46, 204, 113, 1)',
                        'rgba(155, 89, 182, 1)',
                        'rgba(241, 196, 15, 1)',
                        'rgba(230, 126, 34, 1)',
                        'rgba(231, 76, 60, 1)'
                    ],
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
                            text: 'Projecte'
                        }
                    }
                }
            }
        });
    </script>
</body>
</html>