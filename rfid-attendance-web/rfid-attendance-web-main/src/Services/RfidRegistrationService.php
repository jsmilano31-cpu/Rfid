<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use DomainException;
use PDO;

final class RfidRegistrationService
{
    public static function start(int $userId, int $deviceId, int $actorId): array
    {
        $pdo = Database::connection();
        $user = $pdo->prepare('SELECT id FROM users WHERE id = :id AND status = "active"');
        $user->execute(['id' => $userId]);
        if (!$user->fetchColumn()) throw new DomainException('Select an active user.');
        $device = $pdo->prepare('SELECT id FROM devices WHERE id = :id AND status = "active" AND api_key_hash IS NOT NULL');
        $device->execute(['id' => $deviceId]);
        if (!$device->fetchColumn()) throw new DomainException('Select an active scanner with an API key.');

        $pdo->prepare('UPDATE rfid_registration_requests SET status = "cancelled" WHERE requested_by = :actor AND status IN ("pending", "captured")')
            ->execute(['actor' => $actorId]);
        $token = bin2hex(random_bytes(32));
        $pdo->prepare(
            'INSERT INTO rfid_registration_requests (request_token, user_id, device_id, requested_by, expires_at)
             VALUES (:token, :user, :device, :actor, DATE_ADD(NOW(), INTERVAL 2 MINUTE))'
        )->execute(['token' => $token, 'user' => $userId, 'device' => $deviceId, 'actor' => $actorId]);
        return ['request_token' => $token, 'status' => 'pending', 'expires_in' => 120];
    }

    public static function status(string $token, int $actorId): array
    {
        $request = self::owned($token, $actorId);
        if ($request['status'] === 'pending' && !(bool) $request['is_active']) {
            Database::connection()->prepare('UPDATE rfid_registration_requests SET status = "expired" WHERE id = :id')->execute(['id' => $request['id']]);
            $request['status'] = 'expired';
        }
        $result = ['status' => $request['status'], 'expires_at' => $request['expires_at']];
        if ($request['status'] === 'captured' && $request['uid']) {
            $result['uid'] = $request['uid'];
            $owner = Database::connection()->prepare(
                'SELECT users.id, users.school_id, CONCAT(users.first_name, " ", users.last_name) name
                 FROM rfid_cards JOIN users ON users.id = rfid_cards.user_id
                 WHERE rfid_cards.uid = :uid AND rfid_cards.status = "active" AND users.id <> :user LIMIT 1'
            );
            $owner->execute(['uid' => $request['uid'], 'user' => $request['user_id']]);
            $existing = $owner->fetch(PDO::FETCH_ASSOC) ?: null;
            $result['requires_reassignment'] = $existing !== null;
            if ($existing) $result['current_owner'] = $existing;
        }
        return $result;
    }

    public static function complete(string $token, int $actorId, bool $allowReassignment): array
    {
        $pdo = Database::connection();
        $pdo->beginTransaction();
        try {
            $request = self::owned($token, $actorId, true);
            if ($request['status'] !== 'captured' || !$request['uid']) throw new DomainException('Scan a card before confirming the assignment.');
            if (!(bool) $request['is_active']) throw new DomainException('This registration request expired. Start another scan.');
            $uid = RfidService::assign((int) $request['user_id'], (string) $request['uid'], $actorId, $allowReassignment);
            $pdo->prepare('UPDATE rfid_registration_requests SET status = "completed", completed_at = NOW() WHERE id = :id')
                ->execute(['id' => $request['id']]);
            $pdo->commit();
            return ['status' => 'completed', 'uid' => $uid, 'user_id' => (int) $request['user_id']];
        } catch (\Throwable $exception) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $exception;
        }
    }

    public static function cancel(string $token, int $actorId): void
    {
        $request = self::owned($token, $actorId);
        if (in_array($request['status'], ['pending', 'captured'], true)) {
            Database::connection()->prepare('UPDATE rfid_registration_requests SET status = "cancelled" WHERE id = :id')->execute(['id' => $request['id']]);
        }
    }

    public static function capture(int $deviceId, string $uid): ?array
    {
        $pdo = Database::connection();
        $pdo->prepare('UPDATE rfid_registration_requests SET status = "expired" WHERE status = "pending" AND expires_at <= NOW()')->execute();
        $statement = $pdo->prepare(
            'SELECT id, request_token FROM rfid_registration_requests
             WHERE device_id = :device AND status = "pending" AND expires_at > NOW()
             ORDER BY created_at DESC LIMIT 1 FOR UPDATE'
        );
        $statement->execute(['device' => $deviceId]);
        $request = $statement->fetch(PDO::FETCH_ASSOC);
        if (!$request) return null;
        $uid = RfidService::normalizeUid($uid);
        $pdo->prepare('UPDATE rfid_registration_requests SET uid = :uid, status = "captured", captured_at = NOW() WHERE id = :id')
            ->execute(['uid' => $uid, 'id' => $request['id']]);
        return ['mode' => 'registration', 'message' => 'Card captured. Confirm the assignment in the web application.'];
    }

    private static function owned(string $token, int $actorId, bool $lock = false): array
    {
        if (!preg_match('/^[a-f0-9]{64}$/', $token)) throw new DomainException('Invalid registration request.');
        $statement = Database::connection()->prepare(
            'SELECT *, expires_at > NOW() AS is_active
             FROM rfid_registration_requests
             WHERE request_token = :token AND requested_by = :actor
             LIMIT 1' . ($lock ? ' FOR UPDATE' : '')
        );
        $statement->execute(['token' => $token, 'actor' => $actorId]);
        $request = $statement->fetch(PDO::FETCH_ASSOC);
        if (!$request) throw new DomainException('The registration request could not be found.');
        return $request;
    }
}
