<?php
/**
 * Authentication and Session Helper Functions
 * Provides secure session management and user authentication.
 */

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/**
 * Redirect to login page if user is not authenticated
 */
function requireLogin() {
    if (!isLoggedIn()) {
        $_SESSION['redirect_after_login'] = $_SERVER['REQUEST_URI'];
        header('Location: login.php');
        exit;
    }
}

/**
 * Redirect to login page if user is not an admin
 */
function requireAdmin() {
    requireLogin();
    if (!isAdmin()) {
        header('Location: dashboard_employee.php');
        exit;
    }
}

/**
 * Check if user is logged in
 * @return bool True if user is logged in
 */
function isLoggedIn() {
    return isset($_SESSION['user_id']) && !empty($_SESSION['user_id']);
}

/**
 * Check if logged in user is an admin
 * @return bool True if user is admin
 */
function isAdmin() {
    return isLoggedIn() && isset($_SESSION['user_rol']) && $_SESSION['user_rol'] === 'admin';
}

/**
 * Get current user's ID
 * @return int|null User ID or null if not logged in
 */
function getCurrentUserId() {
    return $_SESSION['user_id'] ?? null;
}

/**
 * Get current user's name
 * @return string|null User name or null if not logged in
 */
function getCurrentUserName() {
    return $_SESSION['user_nom'] ?? null;
}

/**
 * Get current user's role
 * @return string|null User role or null if not logged in
 */
function getCurrentUserRole() {
    return $_SESSION['user_rol'] ?? null;
}

/**
 * Log in a user
 * @param array $user User data from database
 */
function loginUser($user) {
    // Regenerate session ID to prevent session fixation
    session_regenerate_id(true);
    
    $_SESSION['user_id'] = $user['id'];
    $_SESSION['user_nom'] = $user['nom'];
    $_SESSION['user_email'] = $user['email'];
    $_SESSION['user_rol'] = $user['rol'];
    $_SESSION['login_time'] = time();
    
    // Set session timeout (30 minutes)
    ini_set('session.gc_maxlifetime', 1800);
    setcookie(session_name(), session_id(), time() + 1800, '/');
}

/**
 * Log out the current user
 */
function logoutUser() {
    // Unset all session variables
    $_SESSION = [];
    
    // Delete the session cookie
    if (isset($_COOKIE[session_name()])) {
        setcookie(session_name(), '', time() - 3600, '/');
    }
    
    // Destroy the session
    session_destroy();
}

/**
 * Check session timeout and logout if expired
 */
function checkSessionTimeout() {
    if (isLoggedIn() && isset($_SESSION['login_time'])) {
        $timeout = 1800; // 30 minutes
        if (time() - $_SESSION['login_time'] > $timeout) {
            logoutUser();
            return false;
        }
        // Update login time
        $_SESSION['login_time'] = time();
    }
    return true;
}

/**
 * Sanitize output to prevent XSS
 * @param string $data Data to sanitize
 * @return string Sanitized data
 */
function e($data) {
    return htmlspecialchars($data, ENT_QUOTES, 'UTF-8');
}

/**
 * Generate a CSRF token
 * @return string CSRF token
 */
function generateCsrfToken() {
    if (!isset($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * Verify CSRF token
 * @param string $token Token to verify
 * @return bool True if token is valid
 */
function verifyCsrfToken($token) {
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

/**
 * Set a flash message
 * @param string $type Message type (success, error, warning, info)
 * @param string $message Message text
 */
function setFlashMessage($type, $message) {
    $_SESSION['flash_message'] = [
        'type' => $type,
        'message' => $message
    ];
}

/**
 * Get and clear flash message
 * @return array|null Flash message or null if none
 */
function getFlashMessage() {
    if (isset($_SESSION['flash_message'])) {
        $message = $_SESSION['flash_message'];
        unset($_SESSION['flash_message']);
        return $message;
    }
    return null;
}