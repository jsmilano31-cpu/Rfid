<?php
declare(strict_types=1);

namespace App\Core;

use PDO;

/** Server-owned connection metadata consumed by database audit triggers. */
final class AuditContext
{
    public static function configure(PDO $pdo, ?int $actorId = null, ?string $systemName = null): void
    {
        $actorId ??= (int) ($_SESSION['user_id'] ?? 0);
        $name = $systemName ?: 'System';
        $schoolId = null;
        $role = $systemName ? 'Scanner' : 'System';
        if ($actorId > 0) {
            $statement = $pdo->prepare('SELECT users.id,CONCAT_WS(" ",first_name,middle_name,last_name) name,school_id,roles.name role FROM users JOIN roles ON roles.id=users.role_id WHERE users.id=?');
            $statement->execute([$actorId]);
            if ($actor = $statement->fetch()) {
                $name = $actor['name']; $schoolId = $actor['school_id']; $role = $actor['role'];
                $preview = (int) ($_SESSION['admin_preview_role_id'] ?? 0);
                if ($preview > 0) {
                    $statement = $pdo->prepare('SELECT name FROM roles WHERE id=?'); $statement->execute([$preview]);
                    if ($label = $statement->fetchColumn()) $role .= ' · View As ' . $label;
                }
            } else $actorId = 0;
        }
        $requestId = $_SERVER['CAMPUS_AUDIT_REQUEST_ID'] ??= bin2hex(random_bytes(16));
        $pdo->prepare('SET @ct_actor_id=?,@ct_actor_name=?,@ct_actor_school_id=?,@ct_actor_role=?,@ct_request_id=?,@ct_ip=?')
            ->execute([$actorId ?: null,mb_substr($name,0,240),$schoolId,mb_substr($role,0,160),$requestId,mb_substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''),0,45) ?: null]);
    }
}
