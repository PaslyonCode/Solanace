-- Run as a MySQL/MariaDB administrative account (for example root).
-- Default database credentials requested for a fresh Solanace installation.
-- CHANGE THE DATABASE PASSWORD after first installation and update config.php.

CREATE DATABASE IF NOT EXISTS solanace CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

CREATE USER IF NOT EXISTS 'admin'@'localhost' IDENTIFIED BY 'admin';
CREATE USER IF NOT EXISTS 'admin'@'127.0.0.1' IDENTIFIED BY 'admin';

GRANT ALL PRIVILEGES ON solanace.* TO 'admin'@'localhost';
GRANT ALL PRIVILEGES ON solanace.* TO 'admin'@'127.0.0.1';
FLUSH PRIVILEGES;
