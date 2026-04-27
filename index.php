<?php
/**
 * StaffLog - Main Entry Point
 * Redirects to login page or appropriate dashboard based on authentication status.
 */

// Start session and load authentication helpers
session_start();
require_once 'includes/auth.php';

// Check if user is already logged in
if (isLoggedIn()) {
    // Redirect to appropriate dashboard based on role
    if (isAdmin()) {
        header('Location: dashboard_admin.php');
    } else {
        header('Location: dashboard_employee.php');
    }
    exit;
}

// Redirect to login page
header('Location: login.php');
exit;