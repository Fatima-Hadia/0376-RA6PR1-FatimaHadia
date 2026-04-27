<?php
/**
 * StaffLog - Alerts Management (Admin Only)
 * View unread alerts, mark as read.
 */

session_start();
require_once 'includes/db.php';
require_once 'includes/auth.php';

requireAdmin();

$userName = getCurrentUserName();
$csrf_token = generateCsrfToken();
$flash = getFlashMessage();

// Handle mark as read
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'mark_read') {
    $submittedToken = $_POST['csrf_token'] ?? '';
    if (verifyCsrfToken($submittedToken)) {
        $alertId = filter_input(INPUT_POST, 'alert_id', FILTER_VALIDATE_INT);
        if ($alertId) {
            executeQuery("UPDATE alerts SET llegida = 1 WHERE id = :id", ['id' => $alertId]);
            setFlashMessage('success', 'Alerta marcada com a llegida.');
        }
    }
    header('Location: alertes.php');
    exit;
}

// Handle mark all as read
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'mark_all_read') {
    $submittedToken = $_POST['csrf_token'] ?? '';
    if (verifyCsrfToken($submittedToken)) {
        executeQuery("UPDATE alerts SET llegida = 1 WHERE llegida = 0");
        setFlashMessage('success', 'Totes les alertes marcades com a llegides.');
    }
    header('Location: alertes.php');
    exit;
}

// Get all unread alerts
$alerts = fetchAll("
    SELECT a.id, a.tipus, a.data, a.llegida, u.nom as user_name, u.email
    FROM alerts a
    JOIN users u ON a.user_id = u.id
    WHERE a.llegida = 0
    ORDER BY a.data DESC
");

// Get count of unread alerts
$unreadCount = fetchOne("SELECT COUNT(*) as count FROM alerts WHERE llegida = 0");
$unreadTotal = $unreadCount['count'] ?? 0;

// Alert type labels
$tipusLabels = [
    'absencia' => 'Absència',
    'retard' => 'Retard',
    'sortida_aviat' => 'Sortida Aviat'
];
?>
<!DOCTYPE html>
<html lang="ca">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>StaffLog - Alertes</title>
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body>
    <header class="header">
        <div class="container">
            <h1>StaffLog - Alertes</h1>
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

        <div class="card">
            <div class="card-header" style="display: flex; justify-content: space-between; align-items: center;">
                <h3>Alertes Sense Llegir (<?php echo $unreadTotal; ?>)</h3>
                <?php if ($unreadTotal > 0): ?>
                    <form method="POST" style="display: inline;">
                        <input type="hidden" name="action" value="mark_all_read">
                        <input type="hidden" name="csrf_token" value="<?php echo e($csrf_token); ?>">
                        <button type="submit" class="btn btn-primary" style="padding: 0.5rem 1rem; font-size: 0.9rem;">
                            Marcar totes com a llegides
                        </button>
                    </form>
                <?php endif; ?>
            </div>
            
            <?php if (empty($alerts)): ?>
                <div style="text-align: center; padding: 3rem;">
                    <p style="font-size: 1.2rem; color: #27ae60;">✅ No hi ha alertes pendents de llegir.</p>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table>
                        <thead>
                            <tr>
                                <th>Usuari</th>
                                <th>Email</th>
                                <th>Tipus</th>
                                <th>Data</th>
                                <th>Acció</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($alerts as $alert): ?>
                                <tr>
                                    <td><?php echo e($alert['user_name']); ?></td>
                                    <td><?php echo e($alert['email']); ?></td>
                                    <td>
                                        <?php
                                        $badgeClass = 'badge-info';
                                        if ($alert['tipus'] === 'absencia') $badgeClass = 'badge-danger';
                                        elseif ($alert['tipus'] === 'retard') $badgeClass = 'badge-warning';
                                        elseif ($alert['tipus'] === 'sortida_aviat') $badgeClass = 'badge-warning';
                                        ?>
                                        <span class="badge <?php echo $badgeClass; ?>">
                                            <?php echo $tipusLabels[$alert['tipus']] ?? $alert['tipus']; ?>
                                        </span>
                                    </td>
                                    <td><?php echo date('d/m/Y', strtotime($alert['data'])); ?></td>
                                    <td>
                                        <form method="POST" style="display: inline;">
                                            <input type="hidden" name="csrf_token" value="<?php echo e($csrf_token); ?>">
                                            <input type="hidden" name="alert_id" value="<?php echo $alert['id']; ?>">
                                            <input type="hidden" name="action" value="mark_read">
                                            <button type="submit" class="btn btn-success" 
                                                    style="padding: 0.25rem 0.5rem; font-size: 0.85rem;">
                                                Marcar com a llegida
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
    </main>

    <footer class="footer">
        <div class="container">
            <p>StaffLog &copy; <?php echo date('Y'); ?> - Gestió de temps i projectes</p>
        </div>
    </footer>
</body>
</html>