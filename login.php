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
            <div class="logo">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect>
                    <line x1="16" y1="2" x2="16" y2="6"></line>
                    <line x1="8" y1="2" x2="8" y2="6"></line>
                    <line x1="3" y1="10" x2="21" y2="10"></line>
                </svg>
            </div>
            <h2>StaffLog</h2>
            <p>Gestió de temps i projectes</p>
            
            <?php if ($error): ?>
                <div class="alert alert-error">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <circle cx="12" cy="12" r="10"></circle>
                        <line x1="15" y1="9" x2="9" y2="15"></line>
                        <line x1="9" y1="9" x2="15" y2="15"></line>
                    </svg>
                    <?php echo e($error); ?>
                </div>
            <?php endif; ?>
            
            <?php if ($flash): ?>
                <div class="alert alert-<?php echo e($flash['type']); ?>">
                    <?php if ($flash['type'] === 'success'): ?>
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path>
                            <polyline points="22 4 12 14.01 9 11.01"></polyline>
                        </svg>
                    <?php endif; ?>
                    <?php echo e($flash['message']); ?>
                </div>
            <?php endif; ?>
            
            <form method="POST" action="login.php" data-validate>
                <input type="hidden" name="csrf_token" value="<?php echo e($csrf_token); ?>">
                
                <div class="form-group">
                    <label for="email">Correu Electrònic</label>
                    <div class="input-wrapper">
                        <svg class="icon" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"></path>
                            <polyline points="22,6 12,13 2,6"></polyline>
                        </svg>
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
                </div>
                
                <div class="form-group">
                    <label for="password">Contrasenya</label>
                    <div class="input-wrapper">
                        <svg class="icon" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect>
                            <path d="M7 11V7a5 5 0 0 1 10 0v4"></path>
                        </svg>
                        <input 
                            type="password" 
                            id="password" 
                            name="password" 
                            required 
                            autocomplete="current-password"
                            placeholder="••••••••"
                        >
                    </div>
                </div>
                
                <button type="submit" class="btn btn-primary btn-block btn-lg">
                    <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4"></path>
                        <polyline points="10 17 15 12 10 7"></polyline>
                        <line x1="15" y1="12" x2="3" y2="12"></line>
                    </svg>
                    Iniciar Sessió
                </button>
            </form>
            
            <p class="text-center mt-3" style="font-size: 0.8125rem; color: var(--text-muted);">
                StaffLog &copy; <?php echo date('Y'); ?> - Gestió de temps i projectes
            </p>
        </div>
    </div>
    
    <script src="assets/js/main.js"></script>
</body>
</html>