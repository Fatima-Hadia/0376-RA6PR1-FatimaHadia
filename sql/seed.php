<?php
/**
 * StaffLog Database Seeder
 * Run this script after setting up the database to insert test data.
 * 
 * Usage: php sql/seed.php
 * Or access via browser: http://localhost/staffweb/sql/seed.php
 */

// Database configuration
$host = 'localhost';
$dbname = 'stafflog_db';
$username = 'staffuser'; // Change this to your MySQL username
$password = 'staffpass123'; // Change this to your MySQL password

try {
    // Create connection with error handling
    $pdo = new PDO(
        "mysql:host=$host;dbname=$dbname;charset=utf8mb4",
        $username,
        $password,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
        ]
    );

    echo "Connected successfully to $dbname\n";

    // Insert admin user
    $adminPassword = password_hash('admin123', PASSWORD_DEFAULT);
    $stmt = $pdo->prepare("
        INSERT INTO users (nom, email, password_hash, rol, hores_contractades, actiu) 
        VALUES (:nom, :email, :password_hash, :rol, :hores_contractades, :actiu)
    ");
    
    $stmt->execute([
        ':nom' => 'Administrador',
        ':email' => 'admin@stafflog.com',
        ':password_hash' => $adminPassword,
        ':rol' => 'admin',
        ':hores_contractades' => 8.00,
        ':actiu' => 1
    ]);
    echo "Admin user created: admin@stafflog.com / admin123\n";

    // Insert employee users
    $employeePassword = password_hash('test123', PASSWORD_DEFAULT);
    
    $employees = [
        [
            'nom' => 'Employee One',
            'email' => 'employee1@stafflog.com'
        ],
        [
            'nom' => 'Employee Two',
            'email' => 'employee2@stafflog.com'
        ],
        [
            'nom' => 'Employee Three',
            'email' => 'employee3@stafflog.com'
        ]
    ];

    foreach ($employees as $employee) {
        $stmt->execute([
            ':nom' => $employee['nom'],
            ':email' => $employee['email'],
            ':password_hash' => $employeePassword,
            ':rol' => 'empleat',
            ':hores_contractades' => 8.00,
            ':actiu' => 1
        ]);
        echo "Employee created: {$employee['email']} / test123\n";
    }

    // Insert projects
    $stmt = $pdo->prepare("
        INSERT INTO projects (nom, client, hores_pressupostades, estat) 
        VALUES (:nom, :client, :hores_pressupostades, :estat)
    ");

    $projects = [
        [
            'nom' => 'Projecte A',
            'client' => 'Client A',
            'hores_pressupostades' => 100.00,
            'estat' => 'actiu'
        ],
        [
            'nom' => 'Projecte B',
            'client' => 'Client B',
            'hores_pressupostades' => 150.00,
            'estat' => 'actiu'
        ],
        [
            'nom' => 'Reunions',
            'client' => 'Intern',
            'hores_pressupostades' => 50.00,
            'estat' => 'actiu'
        ]
    ];

    foreach ($projects as $project) {
        $stmt->execute([
            ':nom' => $project['nom'],
            ':client' => $project['client'],
            ':hores_pressupostades' => $project['hores_pressupostades'],
            ':estat' => $project['estat']
        ]);
        echo "Project created: {$project['nom']}\n";
    }

    echo "\nSeeding completed successfully!\n";
    echo "\nTest credentials:\n";
    echo "Admin: admin@stafflog.com / admin123\n";
    echo "Employees: employee1@stafflog.com / test123 (and 2, 3)\n";

} catch (PDOException $e) {
    // Log error to file in production, show generic message
    error_log("Database seeding error: " . $e->getMessage());
    die("An error occurred while seeding the database. Please check the logs.");
}
