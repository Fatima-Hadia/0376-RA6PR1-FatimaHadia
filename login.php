<?php
/**
 * StaffLog - Login Page
 * Handles user authentication.
 */

// Start session and load required files
session_start();
require_once 'includes/db.php';
require_once 'includes/auth.php';

// If already logged in, redirect to dashboard
if (isLoggedIn()) {
    if (isAdmin()) {
        header('Location: dashboard_admin.php');
    } else {
        header('Location: dashboard_employee.php');
    }
    exit;
}

// Initialize variables
$error = '';
$email = '';

// Process login form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = filter_input(INPUT_POST, 'email', FILTER_SANITIZE_EMAIL);
    $password = $_POST['password'] ?? '';
    $csrf_token = $_POST['csrf_token'] ?? '';
    
    // Verify CSRF token
    if (!verifyCsrfToken($csrf_token)) {
        $error = 'Token de seguretat invàlid. Si us plau, torna-ho a intentar.';
    } elseif (empty($email) || empty($password)) {
        $error = 'Si us plau, ompli tots els camps.';
    } else {
        try {
            // Find user by email
            $user = fetchOne(
                "SELECT id, nom, email, password_hash, rol, actiu 
                 FROM users 
                 WHERE email = :email",
                ['email' => $email]
            );
            
            if ($user && password_verify($password, $user['password_hash'])) {
                // Check if user is active
                if ($user['actiu'] == 1) {
                    // Login successful
                    loginUser($user);
                    
                    // Set flash message
                    setFlashMessage('success', 'Benvingut/da, ' . e($user['nom']) . '!');
                    
                    // Redirect to appropriate dashboard
                    if ($user['rol'] === 'admin') {
                        header('Location: dashboard_admin.php');
                    } else {
                        header('Location: dashboard_employee.php');
                    }
                    exit;
                } else {
                    $error = 'El teu compte està desactivat. Contacta amb l\'administrador.';
                }
            } else {
                // Invalid credentials (don't reveal which part is wrong)
                $error = 'Email o contrasenya incorrectes.';
            }
        } catch (Exception $e) {
            error_log('Login error: ' . $e->getMessage());
            $error = 'Ha ocorregut un error. Si us plau, torna-ho a intentar més tard.';
        }
    }
}

// Generate CSRF token
$csrf_token = generateCsrfToken();

// Check for flash message
$flash = getFlashMessage();
?>
<!DOCTYPE html>
<html lang="ca">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>StaffLog - Inici de Sessió</title>
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body>
    <div class="login-container">
        <div class="login-box">
            <h2>StaffLog</h2>
            
            <?php if ($error): ?>
                <div class="alert alert-error">
                    <?php echo e($error); ?>
                </div>
            <?php endif; ?>
            
            <?php if ($flash): ?>
                <div class="alert alert-<?php echo e($flash['type']); ?>">
                    <?php echo e($flash['message']); ?>
                </div>
            <?php endif; ?>
            
            <form method="POST" action="login.php" data-validate>
                <input type="hidden" name="csrf_token" value="<?php echo e($csrf_token); ?>">
                
                <div class="form-group">
                    <label for="email">Correu Electrònic</label>
                    <input 
                        type="email" 
                        id="email" 
                        name="email" 
                        required 
                        autocomplete="email"
                        value="<?php echo e($email); ?>"
                        placeholder="nom@exemple.com"
                    >
                </div>
                
                <div class="form-group">
                    <label for="password">Contrasenya</label>
                    <input 
                        type="password" 
                        id="password" 
                        name="password" 
                        required 
                        autocomplete="current-password"
                        placeholder="••••••••"
                    >
                </div>
                
                <button type="submit" class="btn btn-primary btn-block">
                    Iniciar Sessió
                </button>
            </form>
            
            <p class="text-center mt-2" style="font-size: 0.9rem; color: #666;">
                StaffLog &copy; <?php echo date('Y'); ?> - Gestió de temps i projectes
            </p>
        </div>
    </div>
    
    <script src="assets/js/main.js"></script>
</body>
</html>