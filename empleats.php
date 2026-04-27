<?php
/**
 * StaffLog - Employee Management (Admin Only)
 * Create, activate/deactivate employees.
 */

session_start();
require_once 'includes/db.php';
require_once 'includes/auth.php';

requireAdmin();

$userName = getCurrentUserName();
$csrf_token = generateCsrfToken();
$flash = getFlashMessage();

// Handle create employee
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'create') {
    $submittedToken = $_POST['csrf_token'] ?? '';
    if (verifyCsrfToken($submittedToken)) {
        $nom = $_POST['nom'] ?? '';
        $email = $_POST['email'] ?? '';
        $password = $_POST['password'] ?? '';
        $rol = $_POST['rol'] ?? 'empleat';
        $hores = $_POST['hores_contractades'] ?? 8.00;
        
        if (!empty($nom) && !empty($email) && !empty($password)) {
            $passwordHash = password_hash($password, PASSWORD_DEFAULT);
            try {
                executeQuery(
                    "INSERT INTO users (nom, email, password_hash, rol, hores_contractades, actiu) 
                     VALUES (:nom, :email, :password_hash, :rol, :hores_contractades, 1)",
                    [
                        ':nom' => $nom,
                        ':email' => $email,
                        ':password_hash' => $passwordHash,
                        ':rol' => $rol,
                        ':hores_contractades' => $hores
                    ]
                );
                setFlashMessage('success', 'Empleat creat correctament.');
            } catch (Exception $e) {
                setFlashMessage('error', 'Error: probablement l\'email ja existeix.');
            }
        } else {
            setFlashMessage('error', 'Tots els camps són obligatoris.');
        }
        header('Location: empleats.php');
        exit;
    }
}

// Handle activate/deactivate
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && in_array($_POST['action'], ['activate', 'deactivate'])) {
    $submittedToken = $_POST['csrf_token'] ?? '';
    if (verifyCsrfToken($submittedToken)) {
        $userId = filter_input(INPUT_POST, 'user_id', FILTER_VALIDATE_INT);
        if ($userId && $userId != getCurrentUserId()) {
            $actiu = ($_POST['action'] === 'activate') ? 1 : 0;
            executeQuery("UPDATE users SET actiu = ? WHERE id = ?", [$actiu, $userId]);
            setFlashMessage('success', $actiu ? 'Usuari activat.' : 'Usuari desactivat.');
        }
    }
    header('Location: empleats.php');
    exit;
}

// Get all employees
$employees = fetchAll("
    SELECT id, nom, email, rol, hores_contractades, actiu, creat_el
    FROM users WHERE rol = 'empleat' ORDER BY nom
");
?>
<!DOCTYPE html>
<html lang="ca">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>StaffLog - Gestió d'Empleats</title>
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body>
    <header class="header">
        <div class="container">
            <h1>StaffLog - Empleats</h1>
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
            <!-- Create Employee Form -->
            <div class="card">
                <div class="card-header">
                    <h3>Nou Empleat</h3>
                </div>
                <form method="POST">
                    <input type="hidden" name="action" value="create">
                    <input type="hidden" name="csrf_token" value="<?php echo e($csrf_token); ?>">
                    
                    <div class="form-group">
                        <label for="nom">Nom complet</label>
                        <input type="text" id="nom" name="nom" required placeholder="Nom de l'empleado">
                    </div>
                    
                    <div class="form-group">
                        <label for="email">Correu electrònic</label>
                        <input type="email" id="email" name="email" required placeholder="email@empresa.com">
                    </div>
                    
                    <div class="form-group">
                        <label for="password">Contrasenya</label>
                        <input type="password" id="password" name="password" required placeholder="••••••••">
                    </div>
                    
                    <div class="form-group">
                        <label for="rol">Rol</label>
                        <select id="rol" name="rol" required>
                            <option value="empleat">Empleat</option>
                            <option value="admin">Administrador</option>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label for="hores_contractades">Hores contractades (diàries)</label>
                        <input type="number" id="hores_contractades" name="hores_contractades" 
                               step="0.5" min="1" max="12" value="8.00" required>
                    </div>
                    
                    <button type="submit" class="btn btn-success btn-block">Crear Empleat</button>
                </form>
            </div>

            <!-- Employee List -->
            <div class="card" style="grid-column: span 2;">
                <div class="card-header">
                    <h3>Llista d'Empleats</h3>
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
                                    <th>Rol</th>
                                    <th>Hores/dia</th>
                                    <th>Estat</th>
                                    <th>Accions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($employees as $emp): ?>
                                    <tr>
                                        <td><?php echo e($emp['nom']); ?></td>
                                        <td><?php echo e($emp['email']); ?></td>
                                        <td>
                                            <span class="badge <?php echo $emp['rol'] === 'admin' ? 'badge-warning' : 'badge-info'; ?>">
                                                <?php echo e(ucfirst($emp['rol'])); ?>
                                            </span>
                                        </td>
                                        <td><?php echo number_format($emp['hores_contractades'], 1); ?>h</td>
                                        <td>
                                            <?php if ($emp['actiu']): ?>
                                                <span class="badge badge-success">Actiu</span>
                                            <?php else: ?>
                                                <span class="badge badge-danger">Inactiu</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <form method="POST" style="display: inline;">
                                                <input type="hidden" name="csrf_token" value="<?php echo e($csrf_token); ?>">
                                                <input type="hidden" name="user_id" value="<?php echo $emp['id']; ?>">
                                                <?php if ($emp['actiu']): ?>
                                                    <input type="hidden" name="action" value="deactivate">
                                                    <button type="submit" class="btn btn-danger" 
                                                            style="padding: 0.25rem 0.5rem; font-size: 0.85rem;"
                                                            onclick="return confirm('Desactivar aquest usuari?')">
                                                        Desactivar
                                                    </button>
                                                <?php else: ?>
                                                    <input type="hidden" name="action" value="activate">
                                                    <button type="submit" class="btn btn-success" 
                                                            style="padding: 0.25rem 0.5rem; font-size: 0.85rem;">
                                                        Activar
                                                    </button>
                                                <?php endif; ?>
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
            <p>StaffLog &copy; <?php echo date('Y'); ?> - Gestió de temps i projectes</p>
        </div>
    </footer>
</body>
</html>