"""Deploy shared web/mobile user settings and its idempotent schema migration."""
from pathlib import Path
import argparse, datetime, importlib.util, io, json, re

ROOT = Path(__file__).resolve().parent.parent
DOMAIN = 'domains/jsmkj.space'

def load(name, path):
    spec = importlib.util.spec_from_file_location(name, path)
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module

ftp_tools = load('settings_ftp', ROOT / 'scripts/deploy-mobile-api.py')
migrations = load('settings_migrations', ROOT / 'scripts/deploy-physical-rfid.py')

PRIVATE = [
    'src/Services/UserPreferenceService.php', 'src/Services/NotificationService.php',
    'src/Services/FcmService.php', 'src/Controllers/PortalController.php',
    'src/Controllers/AdminController.php', 'templates/layouts/app.php',
    'templates/portal/settings.php', 'templates/admin/settings.php', 'templates/admin/grades.php',
    'database/schema.sql', 'database/migrations/20260908_user_preferences.sql',
]
PUBLIC = ['assets/css/app.css', 'assets/css/notifications.css', 'assets/js/settings.js',
          'assets/js/admin-control.js', 'auth/login.php', 'index.php']
MOBILE_SRC = ['src/StudentApi.php']
MOBILE_PUBLIC = ['public/student/settings.php']

def mappings():
    pairs = [(ROOT / path, DOMAIN + '/' + path) for path in PRIVATE]
    pairs += [(ROOT / 'public' / path, DOMAIN + '/public_html/' + path) for path in PUBLIC]
    pairs += [(ROOT / 'mobile-api' / path, DOMAIN + '/mobile-api/' + path) for path in MOBILE_SRC]
    pairs += [(ROOT / 'mobile-api' / path, DOMAIN + '/public_html/rfid-attendance-web/public/' + Path(path).relative_to('public').as_posix()) for path in MOBILE_PUBLIC]
    return pairs

def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--deploy', action='store_true')
    args = parser.parse_args()
    pairs = mappings()
    if not args.deploy:
        for source, target in pairs: print(f'{source.relative_to(ROOT)} -> {target}')
        return
    backup = ROOT / 'storage/settings-backups' / datetime.datetime.now().strftime('%Y%m%d-%H%M%S')
    backup.mkdir(parents=True, exist_ok=False)
    ftp = ftp_tools.connect()
    try:
        for source, target in pairs:
            old = io.BytesIO()
            try: ftp.retrbinary('RETR ' + target, old.write)
            except Exception as error:
                if not str(error).startswith('550'): raise
            else:
                saved = backup / target
                saved.parent.mkdir(parents=True, exist_ok=True)
                saved.write_bytes(old.getvalue())
        sql = re.sub(r'^\s*--.*$', '', (ROOT / 'database/migrations/20260908_user_preferences.sql').read_text(), flags=re.M)
        migrations.migrate(ftp, [statement.strip() for statement in sql.split(';') if statement.strip()], 'user-settings')
        for source, target in pairs:
            ftp_tools.mkdirs(ftp, target.rsplit('/', 1)[0])
            body = source.read_bytes()
            ftp.storbinary('STOR ' + target, io.BytesIO(body))
            check = io.BytesIO(); ftp.retrbinary('RETR ' + target, check.write)
            if check.getvalue() != body: raise RuntimeError('Upload verification failed: ' + target)
            print('Verified ' + target, flush=True)
        (backup / 'manifest.json').write_text(json.dumps([target for _, target in pairs], indent=2))
    finally: ftp.close()

if __name__ == '__main__': main()
