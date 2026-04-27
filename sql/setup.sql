-- StaffLog Database Setup
-- Create database
CREATE DATABASE IF NOT EXISTS stafflog_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

USE stafflog_db;

-- Users table
CREATE TABLE users (
  id INT AUTO_INCREMENT PRIMARY KEY,
  nom VARCHAR(100) NOT NULL,
  email VARCHAR(150) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  rol ENUM('empleat','admin') NOT NULL DEFAULT 'empleat',
  hores_contractades DECIMAL(4,2) NOT NULL DEFAULT 8.00,
  actiu TINYINT(1) NOT NULL DEFAULT 1,
  creat_el DATETIME DEFAULT CURRENT_TIMESTAMP
);

-- Projects table
CREATE TABLE projects (
  id INT AUTO_INCREMENT PRIMARY KEY,
  nom VARCHAR(150) NOT NULL,
  client VARCHAR(150),
  hores_pressupostades DECIMAL(8,2) DEFAULT 0,
  estat ENUM('actiu','tancat') DEFAULT 'actiu',
  creat_el DATETIME DEFAULT CURRENT_TIMESTAMP
);

-- Time entries table
CREATE TABLE time_entries (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  project_id INT NOT NULL,
  entrada DATETIME NOT NULL,
  sortida DATETIME DEFAULT NULL,
  hores_totals DECIMAL(5,2) DEFAULT NULL,
  FOREIGN KEY (user_id) REFERENCES users(id),
  FOREIGN KEY (project_id) REFERENCES projects(id)
);

-- Alerts table
CREATE TABLE alerts (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  tipus ENUM('absencia','retard','sortida_aviat') NOT NULL,
  data DATE NOT NULL,
  llegida TINYINT(1) DEFAULT 0,
  FOREIGN KEY (user_id) REFERENCES users(id)
);