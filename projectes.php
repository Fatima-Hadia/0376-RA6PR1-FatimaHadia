<?php
/**
 * StaffLog - Project Management (Admin Only)
 * Create projects, close projects, view hours.
 */

session_start();
require_once 'includes/db.php';
require_once 'includes/auth.php';

requireAdmin();

$userName = getCurrentUserName();
$csrf_token = generateCsrfToken();
$flash = getFlashMessage();

// Handle create project
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'create') {
    $submittedToken = $_POST['csrf_token'] ?? '';
    if (verifyCsrfToken($submittedToken)) {
        $nom = $_POST['nom'] ?? '';
        $client = $_POST['client'] ?? '';
        $hores = $_POST['hores_pressupostades'] ?? 0;
        
        if (!empty($nom)) {
            try {
                executeQuery(
                    "INSERT INTO projects (nom, client, hores_pressupostades, estat) 
                     VALUES (:nom, :client, :hores_pressupostades, 'actiu')",
                    [
                        ':nom' => $nom,
                        ':client' => $client,
                        ':hores_pressupostades' => $hores
                    ]
                );
                setFlashMessage('success', 'Projecte creat correctament.');
            } catch (Exception $e) {
                setFlashMessage('error', 'Error en crear el projecte.');
            }
        } else {
            setFlashMessage('error', 'El nom del projecte és obligatori.');
        }
        header('Location: projectes.php');
        exit;
    }
}

// Handle close project
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'close') {
    $submittedToken = $_POST['csrf_token'] ?? '';
    if (verifyCsrfToken($submittedToken)) {
        $projectId = filter_input(INPUT_POST, 'project_id', FILTER_VALIDATE_INT);
        if ($projectId) {
            executeQuery("UPDATE projects SET estat = 'tancat' WHERE id = ?", [$projectId]);
            setFlashMessage('success', 'Projecte tancat correctament.');
        }
    }
    header('Location: projectes.php');
    exit;
}

// Get all projects with hours used
$projects = fetchAll("
    SELECT p.id, p.nom, p.client, p.hores_pressupostades, p.estat,
           COALESCE(SUM(te.hores_totals), 0) as hores_utilitzades
    FROM projects p
    LEFT JOIN time_entries te ON p.id = te.project_id AND te.sortida IS NOT NULL
    GROUP BY p.id, p.nom, p.client, p.hores_pressupostades, p.estat
    ORDER BY p.estat DESC, p.nom
");
?>
<!DOCTYPE html>
<html lang="ca">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>StaffLog - Gestió de Projectes</title>
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body>
    <header class="header">
        <div class="container">
            <h1>StaffLog - Projectes</h1>
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

        <div class="dashboard-grid">
            <!-- Create Project Form -->
            <div class="card">
                <div class="card-header">
                    <h3>Nou Projecte</h3>
                </div>
                <form method="POST">
                    <input type="hidden" name="action" value="create">
                    <input type="hidden" name="csrf_token" value="<?php echo e($csrf_token); ?>">
                    
                    <div class="form-group">
                        <label for="nom">Nom del projecte</label>
                        <input type="text" id="nom" name="nom" required placeholder="Nom del projecte">
                    </div>
                    
                    <div class="form-group">
                        <label for="client">Client</label>
                        <input type="text" id="client" name="client" placeholder="Nom del client (opcional)">
                    </div>
                    
                    <div class="form-group">
                        <label for="hores_pressupostades">Hores pressupostades</label>
                        <input type="number" id="hores_pressupostades" name="hores_pressupostades" 
                               step="0.5" min="0" value="0" required>
                    </div>
                    
                    <button type="submit" class="btn btn-success btn-block">Crear Projecte</button>
                </form>
            </div>

            <!-- Project List -->
            <div class="card" style="grid-column: span 2;">
                <div class="card-header">
                    <h3>Llista de Projectes</h3>
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
                                    <th>Hores Utilitzades</th>
                                    <th>Progrés</th>
                                    <th>Estat</th>
                                    <th>Accions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($projects as $project): ?>
                                    <tr>
                                        <td><?php echo e($project['nom']); ?></td>
                                        <td><?php echo e($project['client'] ?? '-'); ?></td>
                                        <td><?php echo number_format($project['hores_pressupostades'], 1); ?>h</td>
                                        <td><?php echo number_format($project['hores_utilitzades'], 1); ?>h</td>
                                        <td>
                                            <?php 
                                            $progress = $project['hores_pressupostades'] > 0 
                                                ? min(100, ($project['hores_utilitzades'] / $project['hores_pressupostades']) * 100) 
                                                : 0;
                                            $progressColor = $progress > 90 ? '#e74c3c' : ($progress > 70 ? '#f39c12' : '#27ae60');
                                            ?>
                                            <div style="width: 60px; background: #eee; border-radius: 4px; overflow: hidden;">
                                                <div style="width: <?php echo $progress; ?>%; background: <?php echo $progressColor; ?>; height: 8px;"></div>
                                            </div>
                                            <span style="font-size: 0.8rem;"><?php echo number_format($progress, 0); ?>%</span>
                                        </td>
                                        <td>
                                            <?php if ($project['estat'] === 'actiu'): ?>
                                                <span class="badge badge-success">Actiu</span>
                                            <?php else: ?>
                                                <span class="badge badge-danger">Tancat</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <a href="report_projecte.php?id=<?php echo $project['id']; ?>" 
                                               class="btn btn-primary" 
                                               style="padding: 0.25rem 0.5rem; font-size: 0.85rem; margin-right: 0.25rem;">
                                                Report
                                            </a>
                                            <?php if ($project['estat'] === 'actiu'): ?>
                                                <form method="POST" style="display: inline;">
                                                    <input type="hidden" name="csrf_token" value="<?php echo e($csrf_token); ?>">
                                                    <input type="hidden" name="project_id" value="<?php echo $project['id']; ?>">
                                                    <input type="hidden" name="action" value="close">
                                                    <button type="submit" class="btn btn-danger" 
                                                            style="padding: 0.25rem 0.5rem; font-size: 0.85rem;"
                                                            onclick="return confirm('Tancar aquest projecte?')">
                                                        Tancar
                                                    </button>
                                                </form>
                                            <?php else: ?>
                                                <span style="color: #999; font-size: 0.85rem;">-</span>
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
</body>
</html>