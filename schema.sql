CREATE DATABASE IF NOT EXISTS tfs_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE tfs_db;

-- ==============================
-- USERS TABLE
-- ==============================
CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    full_name VARCHAR(150) NOT NULL,
    role ENUM('staff','head','director','admin') NOT NULL DEFAULT 'staff',
    department VARCHAR(100),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- ==============================
-- PROJECTS TABLE
-- ==============================
CREATE TABLE IF NOT EXISTS projects (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    title VARCHAR(255) NOT NULL,
    description TEXT,
    budget_total DECIMAL(15,2) DEFAULT 0,
    budget_spent DECIMAL(15,2) DEFAULT 0,
    start_date DATE,
    end_date DATE,
    status ENUM('draft','active','completed','cancelled') DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

-- ==============================
-- PROJECT PHASES TABLE (7 Steps from infographic)
-- ==============================
CREATE TABLE IF NOT EXISTS project_phases (
    id INT AUTO_INCREMENT PRIMARY KEY,
    project_id INT NOT NULL,
    phase_number TINYINT NOT NULL,   -- 1 to 7
    phase_name VARCHAR(255) NOT NULL,
    description TEXT,
    deadline_date DATE,
    completed_date DATE,
    status ENUM('pending','in_progress','completed','overdue') DEFAULT 'pending',
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE
);

-- ==============================
-- ACTIVITIES TABLE (Sub-units of a Project)
-- ==============================
CREATE TABLE IF NOT EXISTS activities (
    id INT AUTO_INCREMENT PRIMARY KEY,
    project_id INT NOT NULL,
    activity_name VARCHAR(255) NOT NULL,
    description TEXT,
    location VARCHAR(255),
    planned_start DATE,
    planned_end DATE,
    planned_participants INT DEFAULT 0,
    planned_budget DECIMAL(15,2) DEFAULT 0,
    status ENUM('planned','ongoing','completed','cancelled') DEFAULT 'planned',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE
);

-- ==============================
-- ACTIVITY REPORTS TABLE (Each session/day of an activity)
-- ==============================
CREATE TABLE IF NOT EXISTS activity_reports (
    id INT AUTO_INCREMENT PRIMARY KEY,
    activity_id INT NOT NULL,
    report_date DATE NOT NULL,
    location VARCHAR(255),
    participants INT DEFAULT 0,
    budget_spent DECIMAL(15,2) DEFAULT 0,
    summary TEXT,
    notes TEXT,
    reported_by INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (activity_id) REFERENCES activities(id) ON DELETE CASCADE,
    FOREIGN KEY (reported_by) REFERENCES users(id) ON DELETE SET NULL
);

-- ==============================
-- FILE ATTACHMENTS TABLE (for both phases and activity_reports)
-- ==============================
CREATE TABLE IF NOT EXISTS attachments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    entity_type ENUM('phase','activity_report','activity_phase') NOT NULL,
    entity_id INT NOT NULL,
    file_name VARCHAR(255) NOT NULL,
    file_path VARCHAR(500) NOT NULL,
    file_type VARCHAR(100),
    file_size INT,
    uploaded_by INT,
    uploaded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (uploaded_by) REFERENCES users(id) ON DELETE SET NULL
);

-- ==============================
-- SEED DATA
-- ==============================
-- Password: 'password123' (bcrypt)
INSERT INTO users (username, password, full_name, role, department) VALUES
('admin',   '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'ผู้ดูแลระบบ (Admin)', 'admin', 'ฝ่ายไอที'),
('director1', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'ผู้อำนวยการ สมศักดิ์', 'director', 'ฝ่ายบริหาร'),
('head1',   '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'หัวหน้า วิภา',          'head',     'ฝ่ายวิชาการ'),
('staff1',  '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'นาย ประสิทธิ์ มีชัย',   'staff',    'ฝ่ายวิชาการ'),
('staff2',  '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'นางสาว สุดา ดาวเรือง',  'staff',    'ฝ่ายแผนงาน')
ON DUPLICATE KEY UPDATE username=username;
