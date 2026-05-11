-- FULL 3D ANIMATED FILM PRODUCTION & MANAGEMENT SYSTEM DATABASE SCHEMA
-- Database: nexus_core

CREATE DATABASE IF NOT EXISTS nexus_core;
USE nexus_core;

-- 1. USERS & ROLES
CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) NOT NULL UNIQUE,
    full_name VARCHAR(100) NOT NULL,
    email VARCHAR(100) NOT NULL UNIQUE,
    phone VARCHAR(20) NOT NULL,
    age INT NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    security_phrase VARCHAR(255) DEFAULT NULL,
    role ENUM('super_admin', 'sub_admin', 'team_moderator', 'user') NOT NULL DEFAULT 'user',
    status ENUM('pending', 'approved', 'rejected', 'active', 'suspended', 'banned') NOT NULL DEFAULT 'pending',
    team_id INT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Seed Initial Super Admin
INSERT INTO users (username, full_name, email, phone, age, password_hash, security_phrase, role, status)
VALUES (
    'subham', 'Subham', 'subhambusiness566@gmail.com', '000-000-0000', 25,
    '25122006', 'Subham Sorry dorry LOVE', 'super_admin', 'active'
);

-- 2. TEAMS & DEPARTMENTS
CREATE TABLE IF NOT EXISTS teams (
    id INT AUTO_INCREMENT PRIMARY KEY,
    department VARCHAR(100) NOT NULL, -- e.g., 'Pre-Production', '3D Production'
    team_name VARCHAR(100) NOT NULL, -- e.g., 'Storyboarding Team'
    allowed_extensions VARCHAR(255) NOT NULL, -- e.g., 'PNG,JPG,PDF,PSD'
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- 3. PRODUCTIONS (Projects)
CREATE TABLE IF NOT EXISTS productions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(200) NOT NULL,
    description TEXT,
    status ENUM('planning', 'active', 'on_hold', 'completed', 'archived') DEFAULT 'planning',
    progress INT DEFAULT 0,
    created_by INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
);

-- 4. TASKS
CREATE TABLE IF NOT EXISTS tasks (
    id INT AUTO_INCREMENT PRIMARY KEY,
    production_id INT NOT NULL,
    team_id INT NOT NULL,
    assigned_to INT DEFAULT NULL,
    title VARCHAR(200) NOT NULL,
    description TEXT,
    status ENUM('pending', 'in_progress', 'on_hold', 'completed') DEFAULT 'pending',
    deadline DATE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (production_id) REFERENCES productions(id) ON DELETE CASCADE,
    FOREIGN KEY (team_id) REFERENCES teams(id) ON DELETE CASCADE,
    FOREIGN KEY (assigned_to) REFERENCES users(id) ON DELETE SET NULL
);

-- 5. WORK UPDATES / SUBMISSIONS
CREATE TABLE IF NOT EXISTS work_submissions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    task_id INT NOT NULL,
    user_id INT NOT NULL,
    team_id INT NOT NULL,
    production_id INT NOT NULL,
    work_description TEXT NOT NULL,
    screenshot_path VARCHAR(255) DEFAULT NULL,
    file_path VARCHAR(255) DEFAULT NULL,
    progress_percentage INT DEFAULT 0,
    status ENUM('pending_review', 'approved', 'rejected', 'needs_fix', 'featured') DEFAULT 'pending_review',
    submitted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (task_id) REFERENCES tasks(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (team_id) REFERENCES teams(id) ON DELETE CASCADE,
    FOREIGN KEY (production_id) REFERENCES productions(id) ON DELETE CASCADE
);

-- 6. CYBERSECURITY LOGS
CREATE TABLE IF NOT EXISTS security_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT DEFAULT NULL,
    action_type VARCHAR(100) NOT NULL, -- e.g., 'login_success', 'failed_attempt', 'file_upload'
    ip_address VARCHAR(45),
    device_info TEXT,
    details TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
);

-- 7. TEAM CHAT / MESSAGES
CREATE TABLE IF NOT EXISTS messages (
    id INT AUTO_INCREMENT PRIMARY KEY,
    sender_id INT NOT NULL,
    team_id INT DEFAULT NULL,
    receiver_id INT DEFAULT NULL, -- Null if sent to a team channel
    message TEXT NOT NULL,
    sent_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (sender_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (team_id) REFERENCES teams(id) ON DELETE CASCADE,
    FOREIGN KEY (receiver_id) REFERENCES users(id) ON DELETE CASCADE
);
