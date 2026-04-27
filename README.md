# StaffLog — Work Hours Tracking Application

![PHP](https://img.shields.io/badge/PHP-8+-777BB4?style=for-the-badge&logo=php)
![MySQL](https://img.shields.io/badge/MySQL-8.0-4479A1?style=for-the-badge&logo=mysql)
![License](https://img.shields.io/badge/License-MIT-green?style=for-the-badge)

## 📋 What is StaffLog?

**StaffLog** is a comprehensive work hours tracking and project management application designed for small to medium-sized businesses. It enables employees to clock in/out of work, assign time to specific projects, and allows administrators to monitor attendance, manage teams, and generate detailed reports.

### Purpose
- Track employee work hours accurately
- Assign time entries to specific projects
- Monitor daily attendance and contracted hours compliance
- Generate project-based reports with budget tracking
- Manage employee alerts and notifications

### Target Audience
- **Administrators**: Full control over employees, projects, and reports
- **Employees**: Simple interface for clock in/out and viewing personal statistics

### Problem Solved
StaffLog eliminates manual time tracking, reduces administrative overhead, provides real-time visibility into project hours, and ensures compliance with contracted working hours.

---

## ✨ Features

### Employee Features
- **Clock In/Out**: Simple one-click time tracking with project selection
- **Live Clock**: Real-time display of hours worked today
- **Weekly Chart**: Visual representation of hours worked each day
- **Project Breakdown**: Progress bars showing time distribution across projects
- **History**: View personal time entry history
- **Alerts**: Receive notifications about attendance issues

### Admin Features
- **Dashboard**: Overview of all employees, projects, and statistics
- **Llista Vermella (Red List)**: Employees below contracted hours highlighted in red
- **Real-time Status Grid**: See who is currently clocked in and on which project
- **Employee Management**: Create, activate, and deactivate employee accounts
- **Project Management**: Create projects, set budgets, track progress
- **Alert System**: Create and manage attendance alerts
- **Project Reports**: Detailed reports with budget vs. actual hours
- **CSV Export**: Export project data for external analysis
- **Weekly Charts**: Visual overview of hours per project

---

## 🛠 Technology Stack

| Technology | Purpose |
|------------|---------|
| **PHP 8+** | Backend logic, sessions, PDO database access |
| **MySQL 8.0** | Relational database for all data storage |
| **HTML5 + CSS3** | Semantic markup and modern styling |
| **Chart.js** | Interactive charts and graphs |
| **Google Fonts** | Inter (body) + Plus Jakarta Sans (headings) |
| **Apache** | Web server |

### Design System
- **Color Palette**: Dark navy (#1E293B), Blue (#3B82F6), Success (#10B981), Danger (#EF4444)
- **Typography**: Inter for readability, Plus Jakarta Sans for impact
- **Components**: Cards with 12px border-radius, subtle shadows, responsive grid

---

## 📁 Project Structure

```
stafflog/
├── 0376-RA6PR1-FatimaHadia/
│   ├── assets/
│   │   ├── css/
│   │   │   └── style.css          # Main stylesheet with design system
│   │   └── js/
│   │       └── main.js            # JavaScript for modals, validation, charts
│   ├── includes/
│   │   ├── auth.php               # Authentication and session helpers
│   │   └── db.php                 # PDO database connection and helpers
│   ├── sql/
│   │   ├── setup.sql              # Database schema creation
│   │   └── seed.php               # Sample data for testing
│   ├── dashboard_admin.php        # Admin dashboard with sidebar
│   ├── dashboard_employee.php     # Employee dashboard with clock
│   ├── empleats.php               # Employee management (CRUD)
│   ├── projectes.php              # Project management
│   ├── alertes.php                # Alert management
│   ├── report_projecte.php        # Project detailed report
│   ├── login.php                  # Login page
│   ├── logout.php                 # Session termination
│   ├── index.php                  # Entry point (redirects)
│   └── README.md                  # This file
└── README.md
```

### File Descriptions

| File | Description |
|------|-------------|
| `style.css` | Complete design system with CSS variables, components, responsive styles |
| `main.js` | Modal system, form validation, sidebar toggle, chart initialization |
| `auth.php` | Login/logout, session management, CSRF protection, role checking |
| `db.php` | PDO connection, prepared statement helpers (fetchAll, fetchOne, executeQuery) |
| `setup.sql` | Creates all 4 tables: users, projects, time_entries, alerts |
| `seed.php` | Inserts test data: 1 admin, 3 employees, 3 projects, sample time entries |

---

## 🗄 Database Schema

### Table: `users`
Stores all user accounts (employees and admins).

| Field | Type | Description |
|-------|------|-------------|
| `id` | INT PRIMARY KEY AUTO_INCREMENT | Unique user ID |
| `nom` | VARCHAR(100) | Full name |
| `email` | VARCHAR(100) UNIQUE | Email address (login username) |
| `password_hash` | VARCHAR(255) | Bcrypt hashed password |
| `rol` | ENUM('empleat', 'admin') | User role |
| `hores_contractades` | DECIMAL(5,2) | Daily contracted hours (e.g., 8.00) |
| `actiu` | TINYINT(1) | Active status (1 = active, 0 = inactive) |
| `creat_el` | TIMESTAMP DEFAULT CURRENT_TIMESTAMP | Account creation date |

### Table: `projects`
Stores all projects that employees can work on.

| Field | Type | Description |
|-------|------|-------------|
| `id` | INT PRIMARY KEY AUTO_INCREMENT | Unique project ID |
| `nom` | VARCHAR(100) | Project name |
| `client` | VARCHAR(100) NULL | Client name (optional) |
| `hores_pressupostades` | DECIMAL(10,2) | Budgeted hours for the project |
| `estat` | ENUM('actiu', 'tancat') | Project status |
| `creat_el` | TIMESTAMP DEFAULT CURRENT_TIMESTAMP | Creation date |

### Table: `time_entries`
Records each clock in/out session.

| Field | Type | Description |
|-------|------|-------------|
| `id` | INT PRIMARY KEY AUTO_INCREMENT | Unique entry ID |
| `user_id` | INT FOREIGN KEY → users.id | Employee who clocked in |
| `project_id` | INT FOREIGN KEY → projects.id | Project being worked on |
| `entrada` | DATETIME | Clock-in timestamp |
| `sortida` | DATETIME NULL | Clock-out timestamp (NULL if still working) |
| `hores_totals` | DECIMAL(8,2) NULL | Total hours (calculated on clock-out) |

### Table: `alerts`
Stores attendance alerts for employees.

| Field | Type | Description |
|-------|------|-------------|
| `id` | INT PRIMARY KEY AUTO_INCREMENT | Unique alert ID |
| `user_id` | INT FOREIGN KEY → users.id | Employee receiving alert |
| `tipus` | ENUM('absencia', 'retard', 'sortida_aviat') | Alert type |
| `data` | DATETIME | When the alert occurred |
| `llegida` | TINYINT(1) DEFAULT 0 | Read status (0 = unread, 1 = read) |

---

## 👥 User Roles

### Employee (`empleat`)
Employees have access to:
- **Dashboard**: Clock in/out, view personal statistics, weekly charts
- **Project Selection**: Choose which project to work on when clocking in
- **History**: View their own time entries
- **Alerts**: See notifications about attendance issues

### Admin (`admin`)
Administrators have full access to:
- **Everything an employee has**, plus:
- **Dashboard**: Overview of all employees, projects, and company-wide statistics
- **Llista Vermella**: Red-highlighted list of employees below contracted hours
- **Real-time Status**: Grid showing who is currently working
- **Employee Management**: Create, activate, deactivate accounts
- **Project Management**: Create projects, set budgets, close projects
- **Alert Management**: Create attendance alerts, mark as read
- **Reports**: Detailed project reports with budget vs. actual comparison
- **CSV Export**: Download project data

---

## 🔐 Test Credentials

| Role | Email | Password |
|------|-------|----------|
| Admin | admin@stafflog.com | admin123 |
| Employee | employee1@stafflog.com | test123 |
| Employee | employee2@stafflog.com | test123 |
| Employee | employee3@stafflog.com | test123 |

---

## 📦 How to Install

### Prerequisites
- PHP 8.0 or higher
- MySQL 8.0 or higher
- Apache web server
- Composer (optional, for dependencies)

### Step-by-Step Installation

1. **Clone the repository**
   ```bash
   git clone https://github.com/Fatima-Hadia/0376-RA6PR1-FatimaHadia.git
   cd 0376-RA6PR1-FatimaHadia
   ```

2. **Create the database**
   ```sql
   CREATE DATABASE stafflog_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
   ```

3. **Import the schema**
   ```bash
   mysql -u root -p stafflog_db < sql/setup.sql
   ```

4. **Insert sample data**
   ```bash
   php sql/seed.php
   ```

5. **Configure database connection**
   Edit `includes/db.php` if your MySQL credentials differ:
   ```php
   define('DB_HOST', 'localhost');
   define('DB_NAME', 'stafflog_db');
   define('DB_USERNAME', 'root');
   define('DB_PASSWORD', '');
   ```

6. **Copy to web server**
   ```bash
   sudo cp -r . /var/www/html/stafflog
   sudo chown -R www-data:www-data /var/www/html/stafflog
   ```

7. **Open in browser**
   ```
   http://localhost/stafflog
   ```

8. **Login**
   Use the admin credentials from the test table above.

---

## 📖 How to Use

### As an Employee

1. **Login** with your email and password
2. **Clock In**:
   - Select a project from the dropdown
   - Click "Fitxar Entrada" (Clock In)
3. **Work** on your assigned project
4. **Clock Out**:
   - Click "Fitxar Sortida" (Clock Out) when done
   - Hours are automatically calculated
5. **View Statistics**:
   - See your daily, weekly, and monthly hours
   - Check project time distribution
   - Review your entry history

### As an Admin

1. **Login** with admin credentials
2. **Dashboard Overview**:
   - Check total active employees and projects
   - Monitor company-wide hours
3. **Llista Vermella**:
   - Review employees below contracted hours
   - Take action if needed
4. **Real-time Status**:
   - See who is currently working
   - Check which project each employee is on
5. **Manage Employees**:
   - Click "Empleats" in sidebar
   - Create new employees with the "Nou Empleat" button
   - Activate/deactivate accounts as needed
6. **Manage Projects**:
   - Click "Projectes" in sidebar
   - Create new projects with budget hours
   - Close completed projects
7. **View Reports**:
   - Click "Report" on any project
   - See budget vs. actual hours comparison
   - View hours per employee breakdown
   - Export data as CSV
8. **Manage Alerts**:
   - Click "Alertes" in sidebar
   - Create attendance alerts
   - Mark alerts as read

---

## 🔒 Security

StaffLog implements industry-standard security practices:

### Password Security
- **password_hash()**: All passwords are hashed using PHP's bcrypt
- **password_verify()**: Secure verification without exposing hashes
- **No passwords in cookies**: Session-based authentication only

### Database Security
- **PDO Prepared Statements**: All queries use parameterized queries
- **Named placeholders**: `:user_id`, `:project_id` prevent SQL injection
- **Error handling**: Database errors are logged, never exposed to users

### Output Security
- **htmlspecialchars()**: All user-generated content is escaped via `e()` helper
- **XSS Prevention**: Prevents cross-site scripting attacks
- **Content-Type headers**: Proper headers prevent MIME-type attacks

### Session Security
- **session_start()**: At the top of every protected page
- **session_regenerate_id()**: On login to prevent session fixation
- **Session timeout**: 30-minute inactivity timeout
- **CSRF Protection**: Token verification on all forms

### Additional Measures
- **filter_input()**: Input validation for IDs and emails
- **Role-based access**: `requireAdmin()` and `requireLogin()` guards
- **Active status check**: Inactive users cannot login

---

## 📊 Charts & Visualizations

StaffLog uses **Chart.js** for interactive data visualization:

- **Weekly Hours Bar Chart**: Shows hours worked each day of the week
- **Project Hours Horizontal Bar**: Compares hours across projects
- **Budget vs. Actual Doughnut**: Visual budget tracking on project reports

All charts are responsive and update automatically.

---

## 🌐 Browser Compatibility

- Chrome 90+
- Firefox 88+
- Safari 14+
- Edge 90+

Mobile responsive design works on all modern smartphones and tablets.

---

## 📄 License

This project is licensed under the MIT License.

---

## 🔗 GitHub Repository

**https://github.com/Fatima-Hadia/0376-RA6PR1-FatimaHadia**

---

## 🤝 Support

For issues, questions, or contributions:
1. Open an issue on GitHub
2. Check the existing documentation
3. Review the code comments

---

## 📝 Changelog

### Version 2.0 (Current)
- Complete visual redesign with modern UI
- Added Google Fonts (Inter + Plus Jakarta Sans)
- New color scheme and design system
- Fixed sidebar navigation for admin
- Modal system for forms
- Improved Chart.js styling
- Enhanced security with named PDO parameters
- Mobile responsive improvements

### Version 1.0
- Initial release with basic time tracking
- Employee and admin dashboards
- Project management
- Basic reporting

---

*Built with ❤️ using PHP, MySQL, and modern web technologies*