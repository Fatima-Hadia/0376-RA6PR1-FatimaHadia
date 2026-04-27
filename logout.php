<?php
/**
 * StaffLog - Logout Page
 * Handles user logout and session destruction.
 */

// Start session and load authentication helpers
session_start();
require_once 'includes/auth.php';

// Perform logout
logoutUser();

// Redirect to login page
header('Location: login.php');
exit;