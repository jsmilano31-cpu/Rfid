<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;

final class AuditService
{
    private static ?bool $extended = null;
    public static function log(?int $userId, string $action, string $entityType, int|string|null $entityId = null, ?string $details = null): void
    {
        $pdo = Database::connection();
        self::$extended ??= (bool) $pdo->query('SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME="audit_logs" AND COLUMN_NAME="search_text"')->fetchColumn();
        if (self::$extended) {
            if (str_contains($entityType,'message')) $details = 'Message activity recorded. Private content is not copied to the audit trail.';
            if (str_contains($action,'rfid') && $details !== null) $details = 'RFID activity recorded. Card credentials are redacted.';
            $pdo->prepare('INSERT INTO audit_logs(user_id,action,entity_type,entity_id,details,ip_address,actor_id,actor_name,actor_school_id,actor_role,request_id,search_text) VALUES(?,?,?,?,?,?,@ct_actor_id,@ct_actor_name,@ct_actor_school_id,@ct_actor_role,@ct_request_id,CONCAT_WS(" ",?,?,?, ?,@ct_actor_name,@ct_actor_school_id))')
                ->execute([$userId,$action,$entityType,$entityId,$details,$_SERVER['REMOTE_ADDR'] ?? null,str_replace('_',' ',$action),str_replace('_',' ',$entityType),$entityId,$details]);
            return;
        }
        $statement = $pdo->prepare(
            'INSERT INTO audit_logs (user_id, action, entity_type, entity_id, details, ip_address)
             VALUES (:user_id, :action, :entity_type, :entity_id, :details, :ip_address)'
        );
        $statement->execute([
            'user_id' => $userId,
            'action' => $action,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'details' => $details,
            'ip_address' => $_SERVER['REMOTE_ADDR'] ?? null,
        ]);
    }
}
