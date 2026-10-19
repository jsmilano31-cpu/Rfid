"""Deploy only campus grading policy web files, with baseline drift checks and verified backups."""
from pathlib import Path
import argparse, datetime, ftplib, hashlib, importlib.util, io, json, subprocess, secrets, ssl, urllib.request, urllib.error

ROOT=Path(__file__).resolve().parent.parent
DOMAIN='domains/jsmkj.space'
FILES=['public/assets/css/campus-policy.css', 'public/assets/js/grading-policy.js', 'templates/partials/grading_policy_form.php', 'src/Services/GradeService.php', 'src/Controllers/AdminController.php', 'public/grades.php', 'templates/layouts/app.php', 'public/assets/js/app.js', 'public/assets/js/admin-control.js', 'templates/admin/attendance.php', 'templates/admin/grades.php', 'templates/admin/profile.php', 'templates/partials/grade_management.php', 'templates/portal/grades.php', 'templates/portal/directory_profile.php']

spec=importlib.util.spec_from_file_location('ftp_tools',ROOT/'scripts/deploy-mobile-api.py')
ftp_tools=importlib.util.module_from_spec(spec); spec.loader.exec_module(ftp_tools)

def remote(path):
    return DOMAIN+('/public_html/'+path.removeprefix('public/') if path.startswith('public/') else '/'+path)

def baseline(path):
    result=subprocess.run(['git','show','HEAD:'+path],cwd=ROOT,capture_output=True)
    return result.stdout if result.returncode==0 else None

def equal(a,b):
    return a is not None and b is not None and a.replace(b'\r\n',b'\n')==b.replace(b'\r\n',b'\n')

def restore_permission(ftp, backup):
    """Restore only the missing built-in administrator permission, never grading data."""
    key=secrets.token_hex(32)
    runner='_campustrack_grading_'+secrets.token_hex(12)+'.php'
    runner_source=r'''<?php
declare(strict_types=1);
if (!hash_equals('TOKEN', $_SERVER['HTTP_X_DEPLOY_KEY'] ?? '')) { http_response_code(404); exit; }
require dirname(__DIR__).'/src/bootstrap.php';
header('Content-Type: application/json; charset=UTF-8'); header('Cache-Control: no-store');
try {
    $pdo=\App\Core\Database::connection();
    $role=$pdo->query('SELECT id,name FROM roles WHERE id=1 AND name="Administrator"')->fetch(\PDO::FETCH_ASSOC);
    if (!$role) throw new \RuntimeException('Expected built-in role is absent');
    if (($_GET['phase'] ?? '')==='restore' && $_SERVER['REQUEST_METHOD']==='POST') {
        $pdo->beginTransaction();
        $pdo->exec('INSERT IGNORE INTO permissions(code,description) VALUES("manage_grading_settings","Configure institutional grading terms, weights, and passing criteria")');
        $changed=$pdo->exec('INSERT IGNORE INTO role_permissions(role_id,permission_id) SELECT 1,id FROM permissions WHERE code="manage_grading_settings"');
        if ($changed) \App\Services\AuditService::log(null,'restore_grading_policy_permission','role',1,'Scoped deployment restored the Administrator grading-policy permission. Grading settings and records unchanged.');
        $pdo->commit();
    }
    $permission=$pdo->query('SELECT id,code,description FROM permissions WHERE code="manage_grading_settings"')->fetch(\PDO::FETCH_ASSOC);
    $assigned=(bool)$pdo->query('SELECT 1 FROM role_permissions JOIN permissions ON permissions.id=role_permissions.permission_id WHERE role_id=1 AND permissions.code="manage_grading_settings"')->fetchColumn();
    $settings=$pdo->query('SELECT has_prelim,has_midterm,has_final,prelim_weight,midterm_weight,final_weight,passing_grade FROM grading_settings WHERE id=1')->fetch(\PDO::FETCH_ASSOC);
    echo json_encode(['ok'=>true,'permission'=>$permission,'administrator_role'=>$role,'assigned'=>$assigned,'grading_settings'=>$settings],JSON_THROW_ON_ERROR);
} catch (\Throwable $error) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    http_response_code(500); echo json_encode(['ok'=>false]);
}
'''.replace('TOKEN',key).encode('utf-8')
    runner_remote=DOMAIN+'/public_html/'+runner
    def request(phase, method='GET'):
        req=urllib.request.Request('https://jsmkj.space/'+runner+'?phase='+phase,headers={'X-Deploy-Key':key,'Accept':'application/json'},method=method)
        with urllib.request.urlopen(req,timeout=45,context=ssl.create_default_context()) as response: payload=json.load(response)
        if not payload.get('ok'): raise RuntimeError('The permission restore could not be completed.')
        return payload
    ftp.storbinary('STOR '+runner_remote,io.BytesIO(runner_source))
    try:
        before=request('inspect')
        (backup/'grading-permission-before.json').write_text(json.dumps(before,indent=2),encoding='utf-8')
        after=request('restore','POST')
        if not after['assigned'] or after['grading_settings']!=before['grading_settings']: raise RuntimeError('Permission or grading settings verification failed')
        (backup/'grading-permission-after.json').write_text(json.dumps(after,indent=2),encoding='utf-8')
        print('Verified Administrator grading-policy permission; existing grading settings unchanged.',flush=True)
    finally:
        ftp.delete(runner_remote)
        try: urllib.request.urlopen('https://jsmkj.space/'+runner,timeout=30)
        except urllib.error.HTTPError as error:
            if error.code!=404: raise RuntimeError('Temporary permission runner cleanup could not be verified')
        else: raise RuntimeError('Temporary permission runner is still accessible')
        print('Temporary permission runner removed and 404 verified.',flush=True)

def main():
    parser=argparse.ArgumentParser(description=__doc__); parser.add_argument('--deploy',action='store_true'); parser.add_argument('--restore-permission',action='store_true'); parser.add_argument('--only',action='append',choices=FILES); args=parser.parse_args()
    files=args.only or FILES
    if not args.deploy and not args.restore_permission:
        for path in files: print(path+' -> '+remote(path))
        return
    backup=ROOT/'storage/grading-policy-backups'/datetime.datetime.now().strftime('%Y%m%d-%H%M%S'); backup.mkdir(parents=True,exist_ok=False)
    ftp=ftp_tools.connect(); previous={}; uploaded=[]
    try:
        if args.restore_permission:
            restore_permission(ftp,backup)
            print('Backup: '+str(backup),flush=True)
            return
        # Read and validate every destination before the first mutation.
        for path in files:
            data=io.BytesIO()
            try: ftp.retrbinary('RETR '+remote(path),data.write); content=data.getvalue()
            except ftplib.error_perm as error:
                if not str(error).startswith('550'): raise
                content=None
            prior=baseline(path)
            if content is None and prior is not None: raise RuntimeError('Expected production file is missing: '+path)
            if content is not None and not equal(content,prior) and not equal(content,(ROOT/path).read_bytes()): raise RuntimeError('Production drift detected: '+path)
            previous[path]=content
            if content is not None:
                saved=backup/path; saved.parent.mkdir(parents=True,exist_ok=True); saved.write_bytes(content)
        manifest={path:{'remote':remote(path),'existed':previous[path] is not None,'sha256':hashlib.sha256((ROOT/path).read_bytes()).hexdigest()} for path in files}
        (backup/'manifest.json').write_text(json.dumps(manifest,indent=2),encoding='utf-8')
        try:
            for path in files:
                content=(ROOT/path).read_bytes(); ftp_tools.mkdirs(ftp,remote(path).rsplit('/',1)[0])
                uploaded.append(path); ftp.storbinary('STOR '+remote(path),io.BytesIO(content))
                received=io.BytesIO(); ftp.retrbinary('RETR '+remote(path),received.write)
                if received.getvalue()!=content: raise RuntimeError('Upload verification failed: '+path)
                print('Verified '+path,flush=True)
        except Exception:
            # Restore the exact snapshot; remove only scoped newly created destinations.
            for path in reversed(uploaded):
                if previous[path] is None: ftp.delete(remote(path))
                else: ftp.storbinary('STOR '+remote(path),io.BytesIO(previous[path]))
            raise
        print('Backup: '+str(backup),flush=True)
        restore_permission(ftp,backup)
    finally: ftp.close()

if __name__=='__main__': main()
