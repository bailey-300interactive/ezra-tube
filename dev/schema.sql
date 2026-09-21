-- KidTube database schema
--
-- This creates the tables only - it doesn't assume a database name. Create
-- your database first with whatever name you want (e.g. "ezra_tube"), then
-- import into it:
--
--   mysql -u root -p -e "CREATE DATABASE ezra_tube CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
--   mysql -u root -p ezra_tube < schema.sql

CREATE TABLE IF NOT EXISTS admin_users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS media (
    id INT AUTO_INCREMENT PRIMARY KEY,
    content_type ENUM('youtube', 'video', 'image') NOT NULL DEFAULT 'youtube',
    youtube_id VARCHAR(20) DEFAULT NULL,
    file_path VARCHAR(500) DEFAULT NULL,
    title VARCHAR(255) NOT NULL,
    thumbnail_url VARCHAR(500) DEFAULT NULL,
    category VARCHAR(100) NOT NULL DEFAULT 'General',
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    added_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_youtube_id (youtube_id)
) ENGINE=InnoDB;

-- No admin account is inserted here on purpose. The first time the site
-- loads, includes/db.php automatically creates one using the
-- DEFAULT_ADMIN_USER / DEFAULT_ADMIN_PASS values from config.php, hashed
-- properly with PHP's own password_hash(). Change that password from the
-- admin panel right after your first login.
