<?php
/**
 * Database Connection Handler
 * Provides a secure PDO connection to the StaffLog database.
 * Credentials are never exposed on screen in case of errors.
 */

// Database configuration constants
define('DB_HOST', 'localhost');
define('DB_NAME', 'stafflog_db');
define('DB_CHARSET', 'utf8mb4');

// MySQL credentials (in production, consider using environment variables)
define('DB_USERNAME', 'root');
define('DB_PASSWORD', '');

/**
 * Get PDO database connection
 * @return PDO Database connection object
 * @throws Exception If connection fails
 */
function getDbConnection() {
    static $pdo = null;
    
    if ($pdo === null) {
        $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
        
        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ];
        
        try {
            $pdo = new PDO($dsn, DB_USERNAME, DB_PASSWORD, $options);
        } catch (PDOException $e) {
            // Log error to file (never expose credentials on screen)
            error_log("Database connection error: " . $e->getMessage());
            
            // Show generic error message to user
            die("Database connection failed. Please try again later or contact the administrator.");
        }
    }
    
    return $pdo;
}

/**
 * Execute a prepared statement with parameters
 * @param string $sql SQL query with placeholders
 * @param array $params Parameters to bind
 * @return PDOStatement Executed statement
 */
function executeQuery($sql, $params = []) {
    $pdo = getDbConnection();
    
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    } catch (PDOException $e) {
        error_log("Query execution error: " . $e->getMessage() . " | SQL: " . $sql);
        throw new Exception("Database query failed.");
    }
}

/**
 * Fetch all rows from a query
 * @param string $sql SQL query with placeholders
 * @param array $params Parameters to bind
 * @return array Array of results
 */
function fetchAll($sql, $params = []) {
    $stmt = executeQuery($sql, $params);
    return $stmt->fetchAll();
}

/**
 * Fetch a single row from a query
 * @param string $sql SQL query with placeholders
 * @param array $params Parameters to bind
 * @return array|false Single row or false if not found
 */
function fetchOne($sql, $params = []) {
    $stmt = executeQuery($sql, $params);
    return $stmt->fetch();
}

/**
 * Get the last inserted ID
 * @return string Last insert ID
 */
function lastInsertId() {
    $pdo = getDbConnection();
    return $pdo->lastInsertId();
}