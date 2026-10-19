"""Initialize only this workspace's loopback-only CampusTrack database."""
import json
from pathlib import Path
import secrets
import socket
import subprocess
import time

import pymysql

LOCAL = Path(__file__).resolve().parent
WEB = LOCAL.parent / 'rfid-attendance-web'
DB_HOME = LOCAL / 'tools/mariadb-11.8.8-winx64'
DATA = LOCAL / 'data/mysql'
PRIVATE = LOCAL / 'private'
CONFIG = LOCAL / 'config'
STATE = LOCAL / 'state'

def protect(path):
    sid = subprocess.run(['whoami.exe', '/user', '/fo', 'csv', '/nh'], capture_output=True, text=True, check=True).stdout.strip().split(',')[-1].strip('"')
    subprocess.run(['icacls.exe', str(path), '/inheritance:r', '/grant:r', f'*{sid}:(OI)(CI)F' if path.is_dir() else f'*{sid}:F',
                    '*S-1-5-18:(OI)(CI)F' if path.is_dir() else '*S-1-5-18:F'], check=True, capture_output=True)

def main():
    for directory in (PRIVATE, CONFIG, STATE, LOCAL / 'logs'):
        directory.mkdir(parents=True, exist_ok=True)
    protect(PRIVATE)
    created = not (DATA / 'mysql').exists()
    if created:
        if DATA.exists() and any(DATA.iterdir()):
            raise RuntimeError('Existing database directory is not empty; preserved without initialization')
        DATA.parent.mkdir(parents=True, exist_ok=True)
        protect(DATA.parent)
        subprocess.run([str(DB_HOME / 'bin/mariadb-install-db.exe'), '--datadir=' + str(DATA), '--port=3306'], check=True)
    my_ini = CONFIG / 'my.ini'
    my_ini.write_text('[mysqld]\nbasedir="' + DB_HOME.as_posix() + '"\ndatadir="' + DATA.as_posix() +
                      '"\nport=3306\nbind-address=127.0.0.1\ncharacter-set-server=utf8mb4\ncollation-server=utf8mb4_unicode_ci\n'
                      'innodb_buffer_pool_size=128M\nmax_allowed_packet=64M\n'
                      'log_error="' + (LOCAL / 'logs/mysql-error.log').as_posix() + '"\n', encoding='utf-8')
    try:
        with socket.create_connection(('127.0.0.1', 3306), timeout=1):
            pass
    except OSError:
        process = subprocess.Popen([str(DB_HOME / 'bin/mariadbd.exe'), '--defaults-file=' + str(my_ini)],
                                   creationflags=subprocess.CREATE_NO_WINDOW)
        (STATE / 'database.pid').write_text(str(process.pid), encoding='ascii')
        for _ in range(100):
            if process.poll() is not None:
                raise RuntimeError('Local database exited; inspect .campustrack-local/logs/mysql-error.log')
            try:
                with socket.create_connection(('127.0.0.1', 3306), timeout=.2):
                    break
            except OSError:
                time.sleep(.2)
        else:
            raise RuntimeError('Local database did not start')
    secret_file = PRIVATE / 'local-db.json'
    if secret_file.exists():
        credentials = json.loads(secret_file.read_text(encoding='utf-8'))
    else:
        credentials = {'root_password': secrets.token_urlsafe(32), 'app_password': secrets.token_urlsafe(32), 'root_configured': False}
        secret_file.write_text(json.dumps(credentials), encoding='utf-8')
    protect(secret_file)
    try:
        connection = pymysql.connect(host='127.0.0.1', port=3306, user='root', password=credentials['root_password'], autocommit=True)
    except pymysql.err.OperationalError:
        if credentials['root_configured']:
            raise
        connection = pymysql.connect(host='127.0.0.1', port=3306, user='root', password='', autocommit=True)
    with connection.cursor() as cursor:
        cursor.execute('SELECT @@datadir')
        actual = Path(cursor.fetchone()[0]).resolve()
        if actual != DATA.resolve():
            raise RuntimeError('Database is not this workspace instance; no changes made')
        if not credentials['root_configured']:
            cursor.execute("ALTER USER 'root'@'localhost' IDENTIFIED BY %s", (credentials['root_password'],))
            credentials['root_configured'] = True
            secret_file.write_text(json.dumps(credentials), encoding='utf-8')
        cursor.execute('CREATE DATABASE IF NOT EXISTS rfid_attendance CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci')
        for account_host in ('127.0.0.1', 'localhost'):
            cursor.execute('CREATE USER IF NOT EXISTS %s@%s IDENTIFIED BY %s', ('campustrack_local', account_host, credentials['app_password']))
            cursor.execute('GRANT ALL PRIVILEGES ON rfid_attendance.* TO %s@%s', ('campustrack_local', account_host))
        cursor.execute("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA='rfid_attendance'")
        table_count = cursor.fetchone()[0]
    client_ini = PRIVATE / 'mysql-client.ini'
    client_ini.write_text('[client]\nhost=127.0.0.1\nport=3306\nuser=root\npassword=' + credentials['root_password'] +
                          '\ndefault-character-set=utf8mb4\n', encoding='utf-8')
    protect(client_ini)
    env_file = WEB / '.env'
    if not env_file.exists():
        env_file.write_text('APP_ENV=local\nAPP_DEBUG=true\nAPP_URL=http://127.0.0.1:8080\nPUBLIC_DIRECTORY=public\n'
                            'DB_HOST=127.0.0.1\nDB_PORT=3306\nDB_DATABASE=rfid_attendance\nDB_USERNAME=campustrack_local\n'
                            'DB_PASSWORD=' + credentials['app_password'] + '\n', encoding='utf-8')
        protect(env_file)
    else:
        existing = dict(line.split('=', 1) for line in env_file.read_text(encoding='utf-8-sig').splitlines() if '=' in line and not line.startswith('#'))
        if any(existing.get(key) != value for key, value in {'APP_ENV': 'local', 'DB_HOST': '127.0.0.1', 'DB_PORT': '3306',
                                                           'DB_DATABASE': 'rfid_attendance', 'DB_USERNAME': 'campustrack_local'}.items()):
            raise RuntimeError('Existing .env differs from the local configuration; preserved without overwriting')
    if table_count == 0:
        commands = 'USE rfid_attendance;\nSOURCE database/schema.sql;\nSOURCE database/seed.sql;\nSOURCE database/catalog.sql;\n'
        result = subprocess.run([str(DB_HOME / 'bin/mariadb.exe'), '--defaults-extra-file=' + str(client_ini), '--batch'],
                                input=commands, cwd=WEB, text=True, capture_output=True)
        if result.returncode:
            raise RuntimeError('Local schema import failed: ' + result.stderr)
        (STATE / 'base-imported.json').write_text(json.dumps({'schema': 'database/schema.sql', 'seed': 'database/seed.sql', 'catalog': 'database/catalog.sql'}), encoding='utf-8')
        print('Local schema, demo records, and academic catalog imported.', flush=True)
    else:
        print('Existing local database retained; base schema and demo seed were not reimported.', flush=True)
    connection.close()
    print('Workspace MariaDB ready at 127.0.0.1:3306; local credentials saved privately.', flush=True)

if __name__ == '__main__':
    main()
