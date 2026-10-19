"""Verify stored title-case normalization against an isolated MySQL database."""
from pathlib import Path
import os
import re
import subprocess
import sys
import uuid

WEB = Path(__file__).resolve().parent.parent
WORKSPACE = WEB.parent
sys.path.insert(0, str(WORKSPACE / '.codex-tmp/audit-deps'))
import pymysql

database_name = 'text_case_' + uuid.uuid4().hex
database_user = 'case_' + uuid.uuid4().hex[:12]
database_password = 'CaseRunner123!'
connection = pymysql.connect(host='127.0.0.1', port=3338, user='root', autocommit=True)
environment = os.environ.copy()
environment.update({
    'DB_HOST': '127.0.0.1',
    'DB_PORT': '3338',
    'DB_DATABASE': database_name,
    'DB_USERNAME': database_user,
    'DB_PASSWORD': database_password,
    'APP_ENV': 'testing',
    'APP_DEBUG': 'true',
    'APP_URL': 'http://127.0.0.1',
})

try:
    with connection.cursor() as cursor:
        cursor.execute(f'CREATE DATABASE `{database_name}` CHARACTER SET utf8mb4')
        cursor.execute(f"CREATE USER `{database_user}`@'%%' IDENTIFIED BY %s", (database_password,))
        cursor.execute(f'GRANT ALL PRIVILEGES ON `{database_name}`.* TO `{database_user}`@\'%\'')
        cursor.execute(f'USE `{database_name}`')
        for source in ['schema.sql', 'seed.sql']:
            sql = re.sub(r'^\s*--.*$', '', (WEB / 'database' / source).read_text(encoding='utf-8-sig'), flags=re.M)
            for statement in sql.split(';'):
                if statement.strip():
                    cursor.execute(statement)
        cursor.execute("UPDATE users SET first_name='JZHIRUBESON', middle_name='margareth', last_name='yao' WHERE id=3")
        cursor.execute("UPDATE courses SET course_code='itc103', title='introduction to RFID and iot systems' WHERE id=1")

    migration = subprocess.run(
        ['php', str(WEB / 'database/migrations/20260926_normalize_entity_title_case.php')],
        cwd=WEB,
        env=environment,
        text=True,
        capture_output=True,
        check=False,
    )
    assert migration.returncode == 0, migration.stdout + migration.stderr
    assert 'users: 1 row(s) normalized.' in migration.stdout, migration.stdout

    php = r'''
require 'src/bootstrap.php';
$id = App\Services\UserService::create([
    'school_id' => 'CASE-001',
    'first_name' => 'jezryl',
    'middle_name' => 'margareth',
    'last_name' => 'villarma',
    'email' => 'case-001@example.test',
    'password' => 'Password123!',
    'role_id' => 3,
], 1);
echo $id;
'''
    created_user_id = int(subprocess.run(
        ['php', '-r', php], cwd=WEB, env=environment, text=True, capture_output=True, check=True
    ).stdout.strip())

    with connection.cursor() as cursor:
        cursor.execute(f'USE `{database_name}`')
        cursor.execute('SELECT first_name, middle_name, last_name FROM users WHERE id=3')
        assert cursor.fetchone() == ('Jzhirubeson', 'Margareth', 'Yao')
        cursor.execute('SELECT course_code, title FROM courses WHERE id=1')
        assert cursor.fetchone() == ('ITC103', 'Introduction to RFID and IoT Systems')
        cursor.execute('SELECT first_name, middle_name, last_name FROM users WHERE id=%s', (created_user_id,))
        assert cursor.fetchone() == ('Jezryl', 'Margareth', 'Villarma')

    print('text normalization integration checks passed')
finally:
    with connection.cursor() as cursor:
        cursor.execute(f'DROP DATABASE IF EXISTS `{database_name}`')
        cursor.execute(f"DROP USER IF EXISTS `{database_user}`@'%'")
    connection.close()
