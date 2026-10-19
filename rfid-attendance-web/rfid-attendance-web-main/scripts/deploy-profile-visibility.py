"""Deploy profile visibility and quiet notification/session UI via verified FTPS."""
from pathlib import Path
import argparse, datetime, importlib.util, io, json, re

ROOT = Path(__file__).resolve().parent.parent
DOMAIN = 'domains/jsmkj.space'

def load(name, path):
    spec = importlib.util.spec_from_file_location(name, path)
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module

ftp_tools = load('profile_ftp', ROOT / 'scripts/deploy-mobile-api.py')
migrations = load('profile_migrations', ROOT / 'scripts/deploy-physical-rfid.py')

PRIVATE = [
    'src/Services/DirectoryProfileService.php',
    'src/Controllers/PortalController.php',
    'templates/portal/profile.php',
    'templates/portal/directory_profile.php',
    'database/schema.sql',
    'database/migrations/20260908_profile_visibility.sql',
]
PUBLIC = ['auth/login.php', 'auth/logout.php', 'assets/js/notifications.js', 'assets/css/app.css']

def mappings():
    return ([(ROOT / path, DOMAIN + '/' + path) for path in PRIVATE] +
            [(ROOT / 'public' / path, DOMAIN + '/public_html/' + path) for path in PUBLIC])

def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--deploy', action='store_true')
    args = parser.parse_args()
    pairs = mappings()
    if not args.deploy:
        for source, target in pairs:
            print(f'{source.relative_to(ROOT)} -> {target}')
        return
    backup = ROOT / 'storage/profile-visibility-backups' / datetime.datetime.now().strftime('%Y%m%d-%H%M%S')
    backup.mkdir(parents=True, exist_ok=False)
    ftp = ftp_tools.connect()
    try:
        for source, target in pairs:
            old = io.BytesIO()
            try:
                ftp.retrbinary('RETR ' + target, old.write)
            except Exception as error:
                if not str(error).startswith('550'):
                    raise
            else:
                saved = backup / target
                saved.parent.mkdir(parents=True, exist_ok=True)
                saved.write_bytes(old.getvalue())
        sql = re.sub(r'^\s*--.*$', '', (ROOT / 'database/migrations/20260908_profile_visibility.sql').read_text(), flags=re.M)
        migrations.migrate(ftp, [statement.strip() for statement in sql.split(';') if statement.strip()], 'profile-visibility')
        for source, target in pairs:
            ftp_tools.mkdirs(ftp, target.rsplit('/', 1)[0])
            body = source.read_bytes()
            ftp.storbinary('STOR ' + target, io.BytesIO(body))
            check = io.BytesIO()
            ftp.retrbinary('RETR ' + target, check.write)
            if check.getvalue() != body:
                raise RuntimeError('Upload verification failed: ' + target)
            print('Verified ' + target, flush=True)
        (backup / 'manifest.json').write_text(json.dumps([target for _, target in pairs], indent=2))
    finally:
        ftp.close()

if __name__ == '__main__':
    main()
