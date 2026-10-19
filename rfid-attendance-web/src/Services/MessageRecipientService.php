<?php
declare(strict_types=1);
namespace App\Services;

use App\Core\Database;
use DomainException;
use PDO;

final class MessageRecipientService
{
    public static function targets(array $data): array
    {
        if (isset($data['recipients_json'])) {
            try { $targets = json_decode((string)$data['recipients_json'], true, 8, JSON_THROW_ON_ERROR); }
            catch (\JsonException) { throw new DomainException('Choose valid To and CC recipients.'); }
        } else {
            $targets = [['kind'=>'to','type'=>$data['recipient_type'] ?? 'user','id'=>$data['target_id'] ?? 0]];
        }
        if (!is_array($targets) || count($targets)>50) throw new DomainException('Choose up to 50 people or groups.');
        $pdo = Database::connection(); $unique = [];
        foreach ($targets as $target) {
            if (!is_array($target)) throw new DomainException('Invalid recipient.');
            $kind = $target['kind'] ?? 'to'; $type = $target['type'] ?? '';
            if (!in_array($kind,['to','cc'],true) || !in_array($type,['user','section','role','all'],true)) throw new DomainException('Choose a valid recipient type.');
            $id = $type === 'all' ? null : filter_var($target['id'] ?? 0, FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]);
            if ($id === false) throw new DomainException('Select a recipient from the search results.');
            $sql = match ($type) {
                'user'=>"SELECT CONCAT_WS(' ',first_name,NULLIF(middle_name,''),last_name) FROM users WHERE id=? AND status='active'",
                'section'=>"SELECT name FROM sections WHERE id=? AND status='active'",
                'role'=>'SELECT name FROM roles WHERE id=?',
                default=>null,
            };
            $label = 'All active users';
            if ($sql) { $statement=$pdo->prepare($sql); $statement->execute([$id]); $label=$statement->fetchColumn(); }
            if (!$label) throw new DomainException('A selected person or group is no longer available. Remove it and choose another.');
            $unique[$kind.':'.$type.':'.$id] = ['kind'=>$kind,'type'=>$type,'id'=>$id,'label'=>mb_substr((string)$label,0,190)];
        }
        if (!array_filter($unique,static fn($target)=>$target['kind']==='to')) throw new DomainException('Add at least one To recipient.');
        return array_values($unique);
    }

    public static function deliver(PDO $pdo, int $messageId, int $senderId, array $targets): void
    {
        foreach (['to','cc'] as $kind) {
            $clauses=[]; $params=['message'=>$messageId,'sender'=>$senderId];
            foreach ($targets as $index=>$target) {
                if ($target['kind']!==$kind) continue;
                if ($target['type']==='all') { $clauses[]='1=1'; continue; }
                $params['target'.$index]=$target['id'];
                $clauses[] = match ($target['type']) {
                    'user'=>'u.id=:target'.$index,
                    'section'=>'u.section_id=:target'.$index,
                    'role'=>'(u.role_id=:primary'.$index.' OR EXISTS(SELECT 1 FROM user_roles ur WHERE ur.user_id=u.id AND ur.role_id=:target'.$index.'))',
                };
                if ($target['type']==='role') $params['primary'.$index]=$target['id'];
            }
            if (!$clauses) continue;
            // NOT EXISTS avoids duplicate To/CC deliveries without suppressing other database errors.
            $query="INSERT INTO message_recipients(message_id,user_id,recipient_kind)
                SELECT :message,u.id,'{$kind}' FROM users u WHERE u.status='active' AND u.id<>:sender AND (".implode(' OR ',$clauses).')';
            if ($kind==='cc') { $query.=' AND NOT EXISTS(SELECT 1 FROM message_recipients existing WHERE existing.message_id=:existing_message AND existing.user_id=u.id)'; $params['existing_message']=$messageId; }
            $statement=$pdo->prepare($query); $statement->execute($params);
            if ($kind==='to' && $statement->rowCount()<1) throw new DomainException('No active To recipients matched that selection.');
        }
        $statement=$pdo->prepare('INSERT INTO message_targets(message_id,recipient_kind,target_type,target_id,label) VALUES(?,?,?,?,?)');
        foreach ($targets as $target) $statement->execute([$messageId,$target['kind'],$target['type'],$target['id'],$target['label']]);
    }
}
