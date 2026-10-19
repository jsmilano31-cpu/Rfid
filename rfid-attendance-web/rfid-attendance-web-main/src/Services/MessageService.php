<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use DomainException;
use Throwable;

final class MessageService
{
    public static function send(array $data, int $senderId): int
    {
        if (trim($data['subject'] ?? '') === '' || trim($data['body'] ?? '') === '') {
            throw new DomainException('Subject and message body are required.');
        }
        $recipientType = $data['recipient_type'] ?? 'user';
        if (!in_array($recipientType, ['user', 'section', 'role', 'all'], true)) {
            throw new DomainException('Invalid recipient type.');
        }
        $targetId = (int) ($data['target_id'] ?? 0);
        if ($recipientType !== 'all' && $targetId < 1) {
            throw new DomainException('Select a valid recipient.');
        }
        $pdo = Database::connection();
        $pdo->beginTransaction();
        try {
            $pdo->prepare('INSERT INTO messages (sender_id, subject, body, is_announcement) VALUES (:sender, :subject, :body, :announcement)')
                ->execute([
                    'sender' => $senderId, 'subject' => trim($data['subject']), 'body' => trim($data['body']),
                    'announcement' => isset($data['is_announcement']) ? 1 : 0,
                ]);
            $messageId = (int) $pdo->lastInsertId();

            [$condition, $needsTarget] = match ($recipientType) {
                'section' => ['users.section_id = :target_id', true],
                'role' => ['EXISTS(SELECT 1 FROM user_roles WHERE user_roles.user_id = users.id AND user_roles.role_id = :target_id)', true],
                'all' => ['users.status = "active"', false],
                default => ['users.id = :target_id', true],
            };
            $statement = $pdo->prepare(
                "INSERT INTO message_recipients (message_id, user_id)
                 SELECT :message_id, users.id FROM users WHERE users.id <> :sender_id AND {$condition}"
            );
            $params = ['message_id' => $messageId, 'sender_id' => $senderId];
            if ($needsTarget) {
                $params['target_id'] = $targetId;
            }
            $statement->execute($params);
            if ($statement->rowCount() < 1) throw new DomainException('No active recipients matched that selection.');
            AuditService::log($senderId, 'send_message', 'message', $messageId, $recipientType);
            $pdo->commit();
            return $messageId;
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $exception;
        }
    }
}
