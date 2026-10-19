<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use DomainException;
use PDO;
use Throwable;

final class RfidService
{
    public static function normalizeUid(string $uid): string
    {
        $uid = strtoupper(preg_replace('/[^A-Fa-f0-9]/', '', $uid) ?? '');
        if (!preg_match('/^[A-F0-9]{8,20}$/', $uid)) {
            throw new DomainException('RFID UID must contain 8 to 20 hexadecimal characters.');
        }
        return $uid;
    }

    public static function assign(int $userId, string $uid, int $actorId, bool $allowReassignment = false): string
    {
        $uid = self::normalizeUid($uid);
        $pdo = Database::connection();
        $ownsTransaction = !$pdo->inTransaction();
        if ($ownsTransaction) {
            $pdo->beginTransaction();
        }

        try {
            $statement = $pdo->prepare('SELECT id FROM users WHERE id = :id AND status = "active"');
            $statement->execute(['id' => $userId]);
            if (!$statement->fetchColumn()) {
                throw new DomainException('The selected user is not active.');
            }

            $statement = $pdo->prepare('SELECT * FROM rfid_cards WHERE uid = :uid FOR UPDATE');
            $statement->execute(['uid' => $uid]);
            $existingUid = $statement->fetch();
            if ($existingUid && $existingUid['user_id'] && (int) $existingUid['user_id'] !== $userId) {
                if (!$allowReassignment) {
                    throw new DomainException('That RFID card belongs to another user. Confirm reassignment to continue.');
                }
                $previousUserId = (int) $existingUid['user_id'];
                $pdo->prepare('UPDATE rfid_cards SET user_id = NULL, status = "replaced" WHERE id = :id')
                    ->execute(['id' => $existingUid['id']]);
                $pdo->prepare(
                    'INSERT INTO rfid_assignment_history (rfid_card_id, user_id, action, performed_by, notes)
                     VALUES (:card_id, :user_id, "replaced", :actor_id, "Card reassigned to another user")'
                )->execute(['card_id' => $existingUid['id'], 'user_id' => $previousUserId, 'actor_id' => $actorId]);
                $existingUid['user_id'] = null;
            }

            $statement = $pdo->prepare('SELECT * FROM rfid_cards WHERE user_id = :user_id FOR UPDATE');
            $statement->execute(['user_id' => $userId]);
            $current = $statement->fetch();
            if ($current && (!$existingUid || (int) $current['id'] !== (int) $existingUid['id'])) {
                $pdo->prepare('UPDATE rfid_cards SET user_id = NULL, status = "replaced" WHERE id = :id')
                    ->execute(['id' => $current['id']]);
                $pdo->prepare(
                    'INSERT INTO rfid_assignment_history (rfid_card_id, user_id, action, performed_by, notes)
                     VALUES (:card_id, :user_id, "replaced", :actor_id, "Replaced during RFID registration")'
                )->execute(['card_id' => $current['id'], 'user_id' => $userId, 'actor_id' => $actorId]);
            }

            if ($existingUid) {
                $cardId = (int) $existingUid['id'];
                $pdo->prepare('UPDATE rfid_cards SET user_id = :user_id, status = "active", assigned_at = NOW() WHERE id = :id')
                    ->execute(['user_id' => $userId, 'id' => $cardId]);
            } else {
                $pdo->prepare('INSERT INTO rfid_cards (uid, user_id, status, assigned_at) VALUES (:uid, :user_id, "active", NOW())')
                    ->execute(['uid' => $uid, 'user_id' => $userId]);
                $cardId = (int) $pdo->lastInsertId();
            }

            $pdo->prepare(
                'INSERT INTO rfid_assignment_history (rfid_card_id, user_id, action, performed_by, notes)
                 VALUES (:card_id, :user_id, "assigned", :actor_id, "RFID card assignment")'
            )->execute(['card_id' => $cardId, 'user_id' => $userId, 'actor_id' => $actorId]);

            AuditService::log($actorId, 'assign_rfid', 'rfid_card', $cardId, "Assigned {$uid} to user {$userId}");
            if ($ownsTransaction) {
                $pdo->commit();
            }
            return $uid;
        } catch (Throwable $exception) {
            if ($ownsTransaction && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        }
    }

    public static function unassign(int $userId, int $actorId): bool
    {
        $pdo = Database::connection();
        $ownsTransaction = !$pdo->inTransaction();
        if ($ownsTransaction) {
            $pdo->beginTransaction();
        }

        try {
            $statement = $pdo->prepare('SELECT id, uid FROM rfid_cards WHERE user_id = :user_id FOR UPDATE');
            $statement->execute(['user_id' => $userId]);
            $card = $statement->fetch();
            if (!$card) {
                if ($ownsTransaction) {
                    $pdo->commit();
                }
                return false;
            }

            $pdo->prepare('UPDATE rfid_cards SET user_id = NULL, status = "inactive" WHERE id = :id')
                ->execute(['id' => $card['id']]);
            $pdo->prepare(
                'INSERT INTO rfid_assignment_history (rfid_card_id, user_id, action, performed_by, notes)
                 VALUES (:card_id, :user_id, "deactivated", :actor_id, "Removed through user management")'
            )->execute(['card_id' => $card['id'], 'user_id' => $userId, 'actor_id' => $actorId]);
            AuditService::log($actorId, 'unassign_rfid', 'rfid_card', (int) $card['id'], "Removed {$card['uid']} from user {$userId}");
            if ($ownsTransaction) {
                $pdo->commit();
            }
            return true;
        } catch (Throwable $exception) {
            if ($ownsTransaction && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        }
    }

    public static function userForUid(string $uid): array
    {
        $statement = Database::connection()->prepare(
            'SELECT users.*, rfid_cards.uid
             FROM rfid_cards JOIN users ON users.id = rfid_cards.user_id
             WHERE rfid_cards.uid = :uid AND rfid_cards.status = "active" AND users.status = "active" LIMIT 1'
        );
        $statement->execute(['uid' => self::normalizeUid($uid)]);
        $user = $statement->fetch(PDO::FETCH_ASSOC);
        if (!$user) {
            throw new DomainException('No active user is assigned to that RFID UID.');
        }
        return $user;
    }
}
