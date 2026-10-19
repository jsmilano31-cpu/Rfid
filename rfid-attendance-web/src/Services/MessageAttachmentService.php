<?php
declare(strict_types=1);
namespace App\Services;

use App\Core\Database;
use DomainException;
use Throwable;

final class MessageAttachmentService
{
    public const MAX_FILE = 5_242_880;
    public const MAX_TOTAL = 20_971_520;
    public const MAX_COUNT = 10;
    private const TYPES = [
        'jpg'=>['image/jpeg'], 'jpeg'=>['image/jpeg'], 'png'=>['image/png'], 'webp'=>['image/webp'], 'gif'=>['image/gif'],
        'pdf'=>['application/pdf'], 'txt'=>['text/plain'], 'csv'=>['text/plain','text/csv','application/csv'], 'md'=>['text/plain'],
        'zip'=>['application/zip','application/x-zip-compressed'],
        'docx'=>['application/zip','application/vnd.openxmlformats-officedocument.wordprocessingml.document'],
        'xlsx'=>['application/zip','application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
        'pptx'=>['application/zip','application/vnd.openxmlformats-officedocument.presentationml.presentation'],
        'doc'=>['application/msword','application/CDFV2','application/x-ole-storage'],
        'xls'=>['application/vnd.ms-excel','application/CDFV2','application/x-ole-storage'],
        'ppt'=>['application/vnd.ms-powerpoint','application/CDFV2','application/x-ole-storage'],
    ];

    public static function limits(): array
    {
        $bytes = static function(string $value): int {
            $number=(float)$value; return (int)($number * match(strtolower(substr(trim($value),-1))) {'g'=>1073741824,'m'=>1048576,'k'=>1024,default=>1});
        };
        $upload=$bytes((string)ini_get('upload_max_filesize')); $post=$bytes((string)ini_get('post_max_size'));
        return ['file'=>min(self::MAX_FILE,$upload>0?$upload:self::MAX_FILE),
            'total'=>min(self::MAX_TOTAL,$post>0?max(0,$post-262144):self::MAX_TOTAL),
            'count'=>min(self::MAX_COUNT,max(1,(int)ini_get('max_file_uploads')))];
    }

    public static function list(int $messageId): array
    {
        $statement=Database::connection()->prepare('SELECT id,original_name,mime_type,byte_size FROM message_attachments WHERE message_id=? ORDER BY id');
        $statement->execute([$messageId]);
        return array_map(self::publicRecord(...),$statement->fetchAll());
    }

    private static function publicRecord(array $row): array
    {
        $url=url('/api/message-attachment.php?id='.$row['id']);
        return ['id'=>(int)$row['id'],'name'=>$row['original_name'],'mime'=>$row['mime_type'],'size'=>(int)$row['byte_size'],
            'is_image'=>in_array($row['mime_type'],['image/jpeg','image/png','image/webp','image/gif'],true),
            'url'=>$url,'preview_url'=>$url.'&inline=1'];
    }

    public static function find(int $userId, int $attachmentId): array
    {
        $statement=Database::connection()->prepare('SELECT a.* FROM message_attachments a JOIN messages m ON m.id=a.message_id
            WHERE a.id=:id AND (m.sender_id=:sender OR EXISTS(SELECT 1 FROM message_recipients r WHERE r.message_id=m.id AND r.user_id=:recipient))');
        $statement->execute(['id'=>$attachmentId,'sender'=>$userId,'recipient'=>$userId]);
        $record=$statement->fetch();
        if (!$record) throw new DomainException('This attachment is unavailable or you do not have access to it.');
        return $record;
    }

    public static function path(string $name): string
    {
        if (!preg_match('/\A[a-f0-9]{48}\.[a-z0-9]{1,8}\z/',$name)) throw new DomainException('The attachment is unavailable.');
        $base=realpath(BASE_PATH.'/storage/message-attachments'); $path=realpath(BASE_PATH.'/storage/message-attachments/'.$name);
        if (!$base || !$path || dirname($path)!==$base || !is_file($path)) throw new DomainException('The attachment is unavailable.');
        return $path;
    }

    /** Validate the entire batch before writing; copied forwards get independent private files. */
    public static function store(?array $files, array $forwardIds, int $senderId): array
    {
        $pending=[]; $saved=[]; $total=0;
        if ($files && !is_array($files['name'] ?? null)) throw new DomainException('Invalid attachment upload.');
        if (count($files['name'] ?? [])+count($forwardIds)>self::MAX_COUNT) throw new DomainException('Attach up to 10 files.');
        foreach ($files['name'] ?? [] as $index=>$original) {
            $error=$files['error'][$index] ?? UPLOAD_ERR_NO_FILE;
            if ($error===UPLOAD_ERR_NO_FILE) continue;
            if ($error!==UPLOAD_ERR_OK) throw new DomainException('An attachment upload failed or exceeded the server limit. Remove it and try again.');
            $temporary=$files['tmp_name'][$index] ?? '';
            if (!is_string($temporary) || !is_uploaded_file($temporary)) throw new DomainException('The attachment upload could not be verified.');
            $size=filesize($temporary);
            if (!$size || $size>self::MAX_FILE) throw new DomainException('Each attachment must be between 1 byte and 5 MB.');
            $name=mb_substr(basename(str_replace('\\','/',(string)$original)),0,200);
            $name=preg_replace('/[\x00-\x1f\x7f]/u','',$name);
            $extension=strtolower(pathinfo((string)$name,PATHINFO_EXTENSION));
            $mime=(new \finfo(FILEINFO_MIME_TYPE))->file($temporary);
            if (!isset(self::TYPES[$extension]) || !in_array($mime,self::TYPES[$extension],true)) throw new DomainException('Use JPG, PNG, WebP, GIF, PDF, text, CSV, ZIP, or Word/Excel/PowerPoint files.');
            if (str_starts_with((string)$mime,'image/')) {
                $dimensions=@getimagesize($temporary);
                if (!$dimensions || $dimensions[0]*$dimensions[1]>40_000_000) throw new DomainException('The image is invalid or too large to preview.');
            }
            if (in_array($extension,['docx','xlsx','pptx'],true)) {
                $zip=new \ZipArchive();
                if ($zip->open($temporary)!==true) throw new DomainException('The Office attachment is invalid.');
                $entry=match($extension) {'docx'=>'word/document.xml','xlsx'=>'xl/workbook.xml','pptx'=>'ppt/presentation.xml'};
                $valid=$zip->locateName('[Content_Types].xml')!==false && $zip->locateName($entry)!==false; $zip->close();
                if (!$valid) throw new DomainException('The Office attachment does not match its file extension.');
            }
            $pending[]=['temporary'=>$temporary,'name'=>$name ?: 'attachment.'.$extension,'mime'=>$mime,'size'=>$size,'extension'=>$extension,'upload'=>true]; $total+=$size;
        }
        foreach ($forwardIds as $id) {
            if (!is_scalar($id) || !ctype_digit((string)$id) || (int)$id<1) throw new DomainException('Invalid forwarded attachment.');
            $record=self::find($senderId,(int)$id); $source=self::path($record['storage_name']);
            $pending[]=['temporary'=>$source,'name'=>$record['original_name'],'mime'=>$record['mime_type'],'size'=>filesize($source),'extension'=>pathinfo($source,PATHINFO_EXTENSION),'upload'=>false]; $total+=filesize($source);
        }
        if ($total>self::MAX_TOTAL) throw new DomainException('Attachments may total up to 20 MB.');
        if (!$pending) return [];
        $directory=BASE_PATH.'/storage/message-attachments';
        if (!is_dir($directory) && !mkdir($directory,0700,true) && !is_dir($directory)) throw new DomainException('Private attachment storage is unavailable.');
        try {
            foreach ($pending as $file) {
                $file['storage_name']=bin2hex(random_bytes(24)).'.'.$file['extension'];
                $path=$directory.'/'.$file['storage_name'];
                $success=$file['upload'] ? move_uploaded_file($file['temporary'],$path) : copy($file['temporary'],$path);
                if (!$success) throw new DomainException('An attachment could not be saved. Try again.');
                @chmod($path,0600); $saved[]=$file;
            }
            return $saved;
        } catch (Throwable $error) { self::remove($saved); throw $error; }
    }

    public static function remove(array $files): void
    {
        foreach ($files as $file) { try { @unlink(self::path($file['storage_name'])); } catch (DomainException) { /* Already absent. */ } }
    }
}
