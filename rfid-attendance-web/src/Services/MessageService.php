<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\Auth;
use DomainException;
use Throwable;

final class MessageService
{
    private static function withSentTime(array $message): array
    {
        // created_at is a database TIMESTAMP, read in the connection's campus zone.
        // Preserve it for older clients and expose an unambiguous instant plus
        // server-formatted labels for all mailbox views and reply/forward quotes.
        $sent = new \DateTimeImmutable((string) $message['created_at'], new \DateTimeZone(config('app')['timezone']));
        $message['sent_at'] = $sent->format(DATE_ATOM);
        $message['sent_at_display'] = $sent->format('M j, Y · g:i A');
        $message['sent_at_date'] = $sent->format('M j, Y');
        return $message;
    }

    public static function mailbox(int $userId, string $folder, string $query, int $page): array
    {
        if (!in_array($folder, ['inbox', 'starred', 'sent', 'trash'], true)) throw new DomainException('Choose a valid mailbox.');
        $pdo = Database::connection();
        $page = max(1, $page);
        $query = mb_substr(trim($query), 0, 180);
        $sent = $folder === 'sent';
        $from = ' FROM messages m JOIN users u ON u.id=m.sender_id ' . ($sent ? '' : 'JOIN message_recipients r ON r.message_id=m.id ');
        $where = $sent ? 'm.sender_id=:user' : 'r.user_id=:user AND r.deleted_at IS ' . ($folder === 'trash' ? 'NOT NULL' : 'NULL');
        if ($folder === 'starred') $where .= ' AND r.starred_at IS NOT NULL';
        $params = ['user'=>$userId];
        if ($query !== '') {
            $where .= " AND CONCAT_WS(' ',m.subject,m.body,u.first_name,u.middle_name,u.last_name) LIKE :query ESCAPE '!'";
            $params['query'] = '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $query) . '%';
        }
        $statement = $pdo->prepare('SELECT COUNT(*)' . $from . 'WHERE ' . $where);
        $statement->execute($params);
        $total = (int)$statement->fetchColumn();
        $page = min($page, max(1, (int)ceil($total / 20)));
        $state = $sent ? 'NULL read_at,NULL starred_at,NULL deleted_at' : 'r.read_at,r.starred_at,r.deleted_at';
        $statement = $pdo->prepare("SELECT m.id,m.sender_id,m.subject,LEFT(m.body,180) preview,m.is_announcement,m.created_at,
            (SELECT COUNT(*) FROM message_attachments WHERE message_id=m.id) attachment_count,
            CONCAT_WS(' ',u.first_name,NULLIF(u.middle_name,''),u.last_name) sender_name,{$state},
            (SELECT COUNT(*) FROM message_recipients WHERE message_id=m.id) recipient_count" . $from . 'WHERE ' . $where . ' ORDER BY m.created_at DESC,m.id DESC LIMIT 20 OFFSET ' . (($page-1)*20));
        $statement->execute($params);
        $counts = $pdo->prepare('SELECT COUNT(CASE WHEN deleted_at IS NULL THEN 1 END) inbox,
            COUNT(CASE WHEN deleted_at IS NULL AND read_at IS NULL THEN 1 END) unread,
            COUNT(CASE WHEN deleted_at IS NULL AND starred_at IS NOT NULL THEN 1 END) starred,
            COUNT(CASE WHEN deleted_at IS NOT NULL THEN 1 END) trash FROM message_recipients WHERE user_id=:user');
        $counts->execute(['user'=>$userId]);
        return ['items'=>array_map([self::class, 'withSentTime'], $statement->fetchAll()), 'total'=>$total, 'page'=>$page, 'pages'=>max(1,(int)ceil($total/20)), 'counts'=>$counts->fetch()];
    }

    public static function message(int $userId, int $messageId): array
    {
        $statement = Database::connection()->prepare("SELECT m.*,CONCAT_WS(' ',u.first_name,NULLIF(u.middle_name,''),u.last_name) sender_name,
            r.read_at,r.starred_at,r.deleted_at,r.id recipient_id,r.recipient_kind,
            (SELECT COUNT(*) FROM message_recipients WHERE message_id=m.id) recipient_count
            FROM messages m JOIN users u ON u.id=m.sender_id
            LEFT JOIN message_recipients r ON r.message_id=m.id AND r.user_id=:recipient
            WHERE m.id=:message AND (m.sender_id=:sender OR r.id IS NOT NULL)");
        $statement->execute(['recipient'=>$userId,'message'=>$messageId,'sender'=>$userId]);
        $message = $statement->fetch();
        if (!$message) throw new DomainException('This message is unavailable or you do not have access to it.');
        // Only opening the authorized full message records an open; bulk "read" does not.
        if ($message['recipient_id'] && !$message['deleted_at']) {
            static $tracksOpen = null;
            $tracksOpen ??= (bool) Database::connection()->query('SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME="message_recipients" AND COLUMN_NAME="first_opened_at"')->fetchColumn();
            if ($tracksOpen) Database::connection()->prepare('UPDATE message_recipients SET first_opened_at=NOW() WHERE id=? AND first_opened_at IS NULL')->execute([$message['recipient_id']]);
        }
        $message['can_manage'] = (bool)$message['recipient_id'];
        $message['sender_url'] = url('/index/?page=profile&user_id=' . $message['sender_id']);
        if ($message['body_html'] !== null) $message['body_html'] = MessageContentService::clean($message['body_html']);
        $message['attachments'] = MessageAttachmentService::list($messageId);
        $targets = Database::connection()->prepare('SELECT recipient_kind kind,target_type type,target_id id,label FROM message_targets WHERE message_id=? ORDER BY message_targets.id');
        $targets->execute([$messageId]); $message['targets'] = $targets->fetchAll();
        return self::withSentTime($message);
    }

    /** Changes only this recipient's mailbox; the shared message and other copies remain intact. */
    public static function change(int $userId, array $ids, string $action): void
    {
        $expressions = ['read'=>'read_at=COALESCE(read_at,NOW())', 'unread'=>'read_at=NULL',
            'star'=>'starred_at=COALESCE(starred_at,NOW())', 'unstar'=>'starred_at=NULL',
            'trash'=>'deleted_at=COALESCE(deleted_at,NOW())', 'restore'=>'deleted_at=NULL'];
        if (!isset($expressions[$action]) || !$ids || count($ids)>100) throw new DomainException('Choose messages and a valid action.');
        foreach ($ids as $id) if (!is_scalar($id) || !ctype_digit((string)$id) || (int)$id<1) throw new DomainException('Invalid message selection.');
        $ids = array_values(array_unique(array_map('intval', $ids)));
        $pdo = Database::connection();
        $pdo->beginTransaction();
        try {
            $placeholders = implode(',',array_fill(0,count($ids),'?'));
            $statement = $pdo->prepare("SELECT message_id FROM message_recipients WHERE user_id=? AND message_id IN ({$placeholders}) FOR UPDATE");
            $statement->execute([$userId,...$ids]);
            if (count($statement->fetchAll()) !== count($ids)) throw new DomainException('A selected message is unavailable or you do not have access to it.');
            $pdo->prepare("UPDATE message_recipients SET {$expressions[$action]} WHERE user_id=? AND message_id IN ({$placeholders})")->execute([$userId,...$ids]);
            $pdo->commit();
        } catch (Throwable $error) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $error; }
    }

    public static function send(array $data, int $senderId, ?array $files = null): int
    {
        $subject = trim((string)($data['subject'] ?? ''));
        $html = isset($data['body_html']) ? MessageContentService::clean((string)$data['body_html']) : null;
        $body = $html !== null ? MessageContentService::text($html) : trim((string)($data['body'] ?? ''));
        if ($subject === '' || $body === '') throw new DomainException('Subject and message body are required.');
        if (mb_strlen($subject)>180 || strlen($body)>65535) throw new DomainException('Use a subject up to 180 characters and a message up to 65 KB.');
        $targets = MessageRecipientService::targets($data);
        try { $forwardIds = json_decode((string)($data['forward_attachment_ids'] ?? '[]'),true,8,JSON_THROW_ON_ERROR); }
        catch (\JsonException) { throw new DomainException('Invalid forwarded attachments.'); }
        if (!is_array($forwardIds) || count($forwardIds)>MessageAttachmentService::MAX_COUNT) throw new DomainException('Invalid forwarded attachments.');
        $forwardIds = array_values(array_unique($forwardIds, SORT_REGULAR));
        if ($forwardIds && !Auth::can('view_messages')) throw new DomainException('You do not have permission to forward message attachments.');
        $pdo = Database::connection(); $saved = [];
        $pdo->beginTransaction();
        try {
            $saved = MessageAttachmentService::store($files,$forwardIds,$senderId);
            $pdo->prepare('INSERT INTO messages(sender_id,subject,body,body_html,is_announcement,created_at) VALUES(?,?,?,?,?,CURRENT_TIMESTAMP)')
                ->execute([$senderId,$subject,$body,$html,isset($data['is_announcement'])?1:0]);
            $messageId = (int)$pdo->lastInsertId();
            MessageRecipientService::deliver($pdo,$messageId,$senderId,$targets);
            $insert = $pdo->prepare('INSERT INTO message_attachments(message_id,storage_name,original_name,mime_type,byte_size) VALUES(?,?,?,?,?)');
            foreach ($saved as $file) $insert->execute([$messageId,$file['storage_name'],$file['name'],$file['mime'],$file['size']]);
            AuditService::log($senderId,'send_message','message',$messageId,'Recipients: '.count($targets).'; attachments: '.count($saved));
            $pdo->commit(); return $messageId;
        } catch (Throwable $error) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            MessageAttachmentService::remove($saved); throw $error;
        }
    }
}
