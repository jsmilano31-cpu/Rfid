<?php
require dirname(__DIR__, 2) . '/src/bootstrap.php';
$pdo = \App\Core\Database::connection();
$pdo->exec('CREATE TABLE IF NOT EXISTS grading_settings (
    id INT UNSIGNED PRIMARY KEY,
    has_prelim TINYINT(1) NOT NULL DEFAULT 0,
    has_midterm TINYINT(1) NOT NULL DEFAULT 1,
    has_final TINYINT(1) NOT NULL DEFAULT 1,
    prelim_weight DECIMAL(5,2) NOT NULL DEFAULT 0.00,
    midterm_weight DECIMAL(5,2) NOT NULL DEFAULT 40.00,
    final_weight DECIMAL(5,2) NOT NULL DEFAULT 60.00,
    passing_grade DECIMAL(5,2) NOT NULL DEFAULT 75.00,
    updated_by INT UNSIGNED NULL,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_grading_settings_user FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
)');
$pdo->exec('INSERT INTO grading_settings (id, has_prelim, has_midterm, has_final, prelim_weight, midterm_weight, final_weight, passing_grade, updated_by)
VALUES (1, 0, 1, 1, 0.00, 40.00, 60.00, 75.00, 1)
ON DUPLICATE KEY UPDATE id=id');
print_r($pdo->query('SELECT * FROM grading_settings')->fetch(PDO::FETCH_ASSOC));
