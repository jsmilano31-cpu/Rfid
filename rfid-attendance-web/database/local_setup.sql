CREATE DATABASE IF NOT EXISTS rfid_attendance
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;

CREATE USER IF NOT EXISTS 'rfid_app'@'127.0.0.1' IDENTIFIED BY 'rfid_app_local';
ALTER USER 'rfid_app'@'127.0.0.1' IDENTIFIED BY 'rfid_app_local';
GRANT ALL PRIVILEGES ON rfid_attendance.* TO 'rfid_app'@'127.0.0.1';
FLUSH PRIVILEGES;

USE rfid_attendance;
SOURCE database/schema.sql;
SOURCE database/seed.sql;
SOURCE database/catalog.sql;
