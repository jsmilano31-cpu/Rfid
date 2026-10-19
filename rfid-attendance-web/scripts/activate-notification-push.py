"""Install private FCM credentials over verified FTPS and run guarded diagnostics."""
from pathlib import Path
import importlib.util, io, json, re, secrets, datetime, urllib.request, urllib.error

ROOT = Path(__file__).resolve().parent.parent
DOMAIN = 'domains/jsmkj.space'
spec = importlib.util.spec_from_file_location('ftp_tools', ROOT/'scripts/deploy-mobile-api.py')
ftp_tools = importlib.util.module_from_spec(spec)
spec.loader.exec_module(ftp_tools)

def probe(ftp, code):
    secret = secrets.token_hex(32)
    name = '_notification_setup_' + secrets.token_hex(12) + '.php'
    target = DOMAIN+'/public_html/'+name
    source = "<?php\nif (!hash_equals('"+secret+"', $_SERVER['HTTP_X_SETUP_KEY'] ?? '')) { http_response_code(404); exit; }\nrequire dirname(__DIR__).'/src/bootstrap.php';\nsession_write_close();\nheader('Content-Type: application/json');\n"+code
    try:
        ftp.storbinary('STOR '+target, io.BytesIO(source.encode()))
        request = urllib.request.Request('https://jsmkj.space/'+name, headers={'X-Setup-Key':secret})
        with urllib.request.urlopen(request, timeout=50) as response:
            return json.load(response)
    finally:
        ftp.delete(target)
        try:
            urllib.request.urlopen('https://jsmkj.space/'+name, timeout=20)
        except urllib.error.HTTPError as error:
            if error.code != 404: raise
        else: raise RuntimeError('Setup probe was not removed')

def main():
    ftp = ftp_tools.connect()
    try:
        info = probe(ftp, "echo json_encode(['base'=>BASE_PATH,'php'=>PHP_BINARY,'exec'=>function_exists('exec'),'worker'=>is_file(BASE_PATH.'/scripts/notification-worker.php')]);")
        base = info['base']
        if not re.fullmatch(r'/home/[a-zA-Z0-9_-]+/domains/jsmkj\.space', base):
            raise RuntimeError('Unexpected private application directory')
        print('Verified server directory: '+base, flush=True)
        backup = ROOT/'storage/notification-backups'/datetime.datetime.now().strftime('%Y%m%d-%H%M%S-activation')
        backup.mkdir(parents=True, exist_ok=False)
        previous = io.BytesIO(); ftp.retrbinary('RETR '+DOMAIN+'/.env', previous.write)
        (backup/'.env').write_bytes(previous.getvalue())
        environment = previous.getvalue().decode('utf-8-sig')
        files = [('firebase-service-account.json','FCM_SERVICE_ACCOUNT_FILE')]
        if (ROOT/'storage/private/firebase-web.json').exists(): files.append(('firebase-web.json','FCM_WEB_CONFIG_FILE'))
        ftp_tools.mkdirs(ftp, DOMAIN+'/storage/private')
        for filename, variable in files:
            content = (ROOT/'storage/private'/filename).read_bytes()
            target = DOMAIN+'/storage/private/'+filename
            ftp.storbinary('STOR '+target, io.BytesIO(content))
            ftp.sendcmd('SITE CHMOD 600 '+target)
            check = io.BytesIO(); ftp.retrbinary('RETR '+target,check.write)
            if check.getvalue() != content: raise RuntimeError('Private file verification failed')
            line = variable+'='+base+'/storage/private/'+filename
            if re.search(r'^'+variable+r'=.*$',environment,re.M): environment=re.sub(r'^'+variable+r'=.*$',line,environment,flags=re.M)
            else: environment=environment.rstrip()+'\n'+line+'\n'
        ftp.storbinary('STOR '+DOMAIN+'/.env',io.BytesIO(environment.encode()))
        ftp.sendcmd('SITE CHMOD 600 '+DOMAIN+'/.env')
        result = probe(ftp, r'''
try {
    $class=new ReflectionClass(App\Services\FcmService::class);
    $account=$class->getMethod('credentials')->invoke(null);
    $token=$class->getMethod('accessToken')->invoke(null,$account);
    $result=['sender_authorized'=>strlen($token)>0,'web_configured'=>!empty(App\Services\FcmService::webConfig()),'exec_available'=>function_exists('exec')];
    if (function_exists('exec')) {
        exec('command -v crontab 2>/dev/null',$out,$status);
        $result['crontab_available']=$status===0;
        $result['php_cli_candidates']=array_values(array_filter(['/usr/bin/php','/usr/bin/php83','/opt/alt/php83/usr/bin/php'],'is_executable'));
    }
    echo json_encode($result);
} catch (Throwable $error) { echo json_encode(['error'=>'Sender authorization or configuration failed']); }
''')
        print(json.dumps(result),flush=True)
    finally: ftp.close()

if __name__ == '__main__': main()
