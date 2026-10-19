<?php
require dirname(__DIR__, 2) . '/src/bootstrap.php';
$pdo = \App\Core\Database::connection();

$columns = $pdo->query('SHOW COLUMNS FROM events LIKE "is_announcement"')->fetchAll();
if (empty($columns)) {
    $pdo->exec('ALTER TABLE events ADD COLUMN is_announcement TINYINT(1) NOT NULL DEFAULT 0 AFTER check_out_required');
    echo "Added is_announcement column to events table.\n";
} else {
    echo "is_announcement column already exists.\n";
}

// Mark sample announcement or update existing sample
$sample = $pdo->query("SELECT id FROM events WHERE title LIKE '%NIR DTX 500%' OR title LIKE '%Radio%' LIMIT 1")->fetchColumn();
if ($sample) {
    $pdo->prepare("UPDATE events SET is_announcement = 1 WHERE id = :id")->execute(['id' => $sample]);
    echo "Marked event #{$sample} as an announcement.\n";
}
