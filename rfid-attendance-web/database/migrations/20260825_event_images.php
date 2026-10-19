<?php
require dirname(__DIR__, 2) . '/src/bootstrap.php';
$pdo = \App\Core\Database::connection();

$pdo->exec('CREATE TABLE IF NOT EXISTS event_images (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    event_id INT UNSIGNED NOT NULL,
    image_path VARCHAR(255) NOT NULL,
    caption VARCHAR(255) NULL,
    display_order INT NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_event_images_event FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE
) ENGINE=InnoDB');

// Seed existing banners into event_images
$eventsWithBanner = $pdo->query('SELECT id, banner_image_path FROM events WHERE banner_image_path IS NOT NULL AND banner_image_path != ""')->fetchAll(PDO::FETCH_ASSOC);
foreach ($eventsWithBanner as $ev) {
    $exists = $pdo->prepare('SELECT COUNT(*) FROM event_images WHERE event_id = :event_id AND image_path = :path');
    $exists->execute(['event_id' => $ev['id'], 'path' => $ev['banner_image_path']]);
    if ((int) $exists->fetchColumn() === 0) {
        $pdo->prepare('INSERT INTO event_images (event_id, image_path, display_order) VALUES (:event_id, :path, 0)')
            ->execute(['event_id' => $ev['id'], 'path' => $ev['banner_image_path']]);
    }
}

echo "event_images table created and synced successfully.\n";
