<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/src/bootstrap.php';

use App\Core\Database;

$pdo = Database::connection();

/**
 * @param array<string, callable(mixed): string> $columns
 */
function normalizeEntityRows(PDO $pdo, string $table, array $columns): int
{
    $columnNames = array_keys($columns);
    $rows = $pdo->query('SELECT id, ' . implode(', ', $columnNames) . ' FROM ' . $table)->fetchAll(PDO::FETCH_ASSOC);
    $assignments = implode(', ', array_map(static fn(string $column): string => $column . ' = :' . $column, $columnNames));
    $update = $pdo->prepare('UPDATE ' . $table . ' SET ' . $assignments . ' WHERE id = :id');
    $changed = 0;

    foreach ($rows as $row) {
        $values = ['id' => (int) $row['id']];
        $isChanged = false;
        foreach ($columns as $column => $normalizer) {
            if ($row[$column] === null) {
                $values[$column] = null;
                continue;
            }
            $values[$column] = $normalizer($row[$column]);
            $isChanged = $isChanged || $values[$column] !== $row[$column];
        }
        if ($isChanged) {
            $update->execute($values);
            $changed++;
        }
    }

    return $changed;
}

$title = static fn(mixed $value): string => normalize_title_case($value);
$person = static fn(mixed $value): string => normalize_person_name($value);
$code = static fn(mixed $value): string => mb_strtoupper(normalize_label_whitespace($value), 'UTF-8');

$targets = [
    'users' => ['first_name' => $person, 'middle_name' => $person, 'last_name' => $person],
    'departments' => ['code' => $code, 'name' => $title],
    'programs' => ['code' => $code, 'name' => $title],
    'courses' => ['course_code' => $code, 'title' => $title],
    'roles' => ['name' => $title],
    'rooms' => ['room_code' => $code, 'room_name' => $title, 'building' => $title, 'floor' => $title],
    'devices' => ['device_code' => $code, 'name' => $title],
    'events' => ['title' => $title, 'venue' => $title],
    'recollections' => ['title' => $title, 'venue' => $title, 'facilitator' => $person],
    'room_seat_plans' => ['name' => $title],
    'seat_plan_presets' => ['name' => $title],
];

$pdo->beginTransaction();
try {
    foreach ($targets as $table => $columns) {
        $count = normalizeEntityRows($pdo, $table, $columns);
        echo $table . ': ' . $count . " row(s) normalized.\n";
    }
    $pdo->commit();
} catch (Throwable $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    throw $exception;
}
