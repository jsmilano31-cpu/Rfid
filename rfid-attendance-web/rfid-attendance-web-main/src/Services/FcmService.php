<?php
declare(strict_types=1);
namespace App\Services;

use App\Core\Database;

/** FCM HTTP v1 transport only. MySQL remains the system of record. */
final class FcmService
{
    public static function configured(): bool { return (string)env('FCM_SERVICE_ACCOUNT_FILE','') !== ''; }

    public static function webConfig(): array
    {
        $path=(string)env('FCM_WEB_CONFIG_FILE','');
        if ($path==='' || !is_file($path)) return [];
        $config=json_decode(file_get_contents($path),true);
        if (!is_array($config)) return [];
        $result=[];
        foreach (['apiKey','authDomain','projectId','messagingSenderId','appId','vapidKey'] as $key) {
            if (!isset($config[$key]) || !is_string($config[$key]) || $config[$key]==='') return [];
            $result[$key]=$config[$key];
        }
        return $result;
    }

    private static function request(string $url,string $body,array $headers): array
    {
        $curl=curl_init($url);
        curl_setopt_array($curl,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>$body,CURLOPT_HTTPHEADER=>$headers,CURLOPT_RETURNTRANSFER=>true,CURLOPT_CONNECTTIMEOUT=>5,CURLOPT_TIMEOUT=>12]);
        $raw=curl_exec($curl); $status=(int)curl_getinfo($curl,CURLINFO_HTTP_CODE); curl_close($curl);
        if ($raw===false) throw new \RuntimeException('FCM transport unavailable');
        return [$status,json_decode($raw,true) ?: []];
    }

    private static function credentials(): array
    {
        $path=realpath((string)env('FCM_SERVICE_ACCOUNT_FILE',''));
        $public=realpath(PUBLIC_PATH);
        if (!$path || ($public && str_starts_with(strtolower($path),strtolower($public).DIRECTORY_SEPARATOR))) throw new \RuntimeException('FCM credential must be a private server file');
        $account=json_decode(file_get_contents($path),true,16,JSON_THROW_ON_ERROR);
        if (empty($account['private_key']) || empty($account['client_email']) || !preg_match('/\A[a-z0-9-]+\z/',$account['project_id']??'')) throw new \RuntimeException('Invalid FCM service account');
        return $account;
    }

    private static function accessToken(array $account): string
    {
        static $token=null,$expires=0;
        if ($token && $expires>time()+60) return $token;
        $b64=static fn(string $s):string=>rtrim(strtr(base64_encode($s),'+/','-_'),'=');
        $now=time();
        $jwt=$b64(json_encode(['alg'=>'RS256','typ'=>'JWT'])).'.'.$b64(json_encode(['iss'=>$account['client_email'],'scope'=>'https://www.googleapis.com/auth/firebase.messaging','aud'=>'https://oauth2.googleapis.com/token','iat'=>$now,'exp'=>$now+3600]));
        if (!openssl_sign($jwt,$signature,$account['private_key'],OPENSSL_ALGO_SHA256)) throw new \RuntimeException('FCM credential signing failed');
        [$status,$data]=self::request('https://oauth2.googleapis.com/token',http_build_query(['grant_type'=>'urn:ietf:params:oauth:grant-type:jwt-bearer','assertion'=>$jwt.'.'.$b64($signature)]),['Content-Type: application/x-www-form-urlencoded']);
        if ($status!==200 || empty($data['access_token'])) throw new \RuntimeException('FCM authorization failed');
        $expires=$now+3300; return $token=$data['access_token'];
    }

    public static function dispatch(int $limit=50): int
    {
        if (!self::configured() || !NotificationService::enabled()) return 0;
        $pdo=Database::connection();
        if (!$pdo->query("SELECT GET_LOCK('campus_notification_dispatch',0)")->fetchColumn()) return 0;
        $sent=0;
        try {
            $account=self::credentials(); $bearer=self::accessToken($account);
            $limit=max(1,min(100,$limit));
            $jobs=$pdo->query("SELECT o.*,n.user_id,n.event_key,n.kind,n.target_type,n.target_id,n.revision current_revision,n.read_at,n.expires_at,d.user_id device_user,d.active,d.token,d.updated_at device_updated,u.status user_status FROM notification_outbox o JOIN notifications n ON n.id=o.notification_id JOIN notification_devices d ON d.id=o.device_id JOIN users u ON u.id=n.user_id WHERE o.status='pending' AND o.available_at<=NOW() ORDER BY o.id LIMIT {$limit}")->fetchAll();
            foreach ($jobs as $job) {
                if (str_ends_with($job['event_key'],':24h')) {
                    $newer=$pdo->prepare('SELECT id FROM notifications WHERE user_id=? AND target_type=? AND target_id=? AND event_key LIKE ? AND id>? LIMIT 1');
                    $newer->execute([$job['user_id'],$job['target_type'],$job['target_id'],'%:1h',$job['notification_id']]);
                    if ($newer->fetchColumn()) { $pdo->prepare('UPDATE notification_outbox SET status="superseded" WHERE id=?')->execute([$job['id']]); continue; }
                }
                if (!$job['active'] || $job['user_status']!=='active' || $job['user_id']!=$job['device_user'] || !UserPreferenceService::get((int)$job['user_id'])['push_enabled'] || !UserPreferenceService::allowsKind((int)$job['user_id'],(string)$job['kind'],(string)$job['target_type']) || $job['revision']!=$job['current_revision'] || $job['read_at'] || strtotime($job['device_updated'])<time()-30*86400 || ($job['expires_at'] && strtotime($job['expires_at'])<=time())) {
                    $pdo->prepare('UPDATE notification_outbox SET status="superseded" WHERE id=?')->execute([$job['id']]); continue;
                }
                // Data-only: client verifies active account before displaying anything. No grades,
                // student names, subject details, or attendance content leave MySQL in this payload.
                $payload=['message'=>['token'=>$job['token'],'data'=>['notification_id'=>(string)$job['notification_id'],'revision'=>(string)$job['revision'],'account_id'=>(string)$job['user_id']],
                    'android'=>['priority'=>'high','ttl'=>'3600s'], 'webpush'=>['headers'=>['Urgency'=>'high','TTL'=>'3600']]]];
                try {
                    [$status,$response]=self::request('https://fcm.googleapis.com/v1/projects/'.$account['project_id'].'/messages:send',json_encode($payload,JSON_THROW_ON_ERROR),['Authorization: Bearer '.$bearer,'Content-Type: application/json']);
                    if ($status===200) { $pdo->prepare('UPDATE notification_outbox SET status="sent",last_error=NULL WHERE id=?')->execute([$job['id']]); $sent++; continue; }
                    $code=$response['error']['details'][0]['errorCode']??($response['error']['status']??'FCM_ERROR');
                    if ($code==='UNREGISTERED') $pdo->prepare('UPDATE notification_devices SET active=0 WHERE id=?')->execute([$job['device_id']]);
                    $error='HTTP '.$status.' '.substr((string)$code,0,60);
                } catch (\Throwable) { $error='Transport failure'; }
                $attempts=(int)$job['attempts']+1; $delay=min(3600,30*(2**min($attempts,7)));
                $pdo->prepare('UPDATE notification_outbox SET attempts=?,last_error=?,available_at=?,status=? WHERE id=?')
                    ->execute([$attempts,$error,date('Y-m-d H:i:s',time()+$delay),$attempts>=8?'failed':'pending',$job['id']]);
            }
        } finally { $pdo->query("SELECT RELEASE_LOCK('campus_notification_dispatch')"); }
        return $sent;
    }
}
