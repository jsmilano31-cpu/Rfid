"""Install local development tools from vendor HTTPS downloads with checksums."""
import hashlib
import json
import os
from pathlib import Path
import re
import subprocess
import sys
import urllib.request
import xml.etree.ElementTree as ET
import zipfile

LOCAL = Path(__file__).resolve().parent

def fetch(url):
    if 'apachelounge.com/' in url:
        return subprocess.run(['curl.exe', '--fail', '--silent', '--show-error', '--location',
                               '--proto', '=https', '--proto-redir', '=https', url],
                              check=True, capture_output=True).stdout
    request = urllib.request.Request(url, headers={'User-Agent': 'Mozilla/5.0'})
    with urllib.request.urlopen(request, timeout=90) as response:
        if not response.url.startswith('https://'):
            raise RuntimeError('Download redirected away from HTTPS')
        return response.read()

def install(kind):
    downloads = LOCAL / 'downloads'
    downloads.mkdir(exist_ok=True)
    if kind == 'sdk':
        metadata = ET.fromstring(fetch('https://dl.google.com/android/repository/repository2-1.xml'))
        package = next(p for p in metadata.findall('remotePackage') if p.attrib['path'] == 'cmdline-tools;latest')
        archive = next(a for a in package.findall('./archives/archive') if a.findtext('host-os') == 'windows')
        name = archive.findtext('./complete/url')
        expected = archive.findtext('./complete/checksum')
        algorithm = 'sha1'  # Google's repository metadata publishes this checksum.
        url = 'https://dl.google.com/android/repository/' + name
        destination = Path(os.environ['LOCALAPPDATA']) / 'Android/Sdk/cmdline-tools/latest'
        prefix = 'cmdline-tools/'
    elif kind == 'database':
        version = '11.8.8'
        metadata = json.loads(fetch('https://downloads.mariadb.org/rest-api/mariadb/' + version))
        name = f'mariadb-{version}-winx64.zip'
        archive = next(a for a in metadata['release_data'][version]['files'] if a['file_name'] == name)
        expected = archive['checksum']['sha256sum']
        algorithm = 'sha256'
        url = archive['file_download_url'].replace('http://', 'https://', 1)
        destination = LOCAL / 'tools' / f'mariadb-{version}-winx64'
        prefix = f'mariadb-{version}-winx64/'
    elif kind == 'apache':
        name = 'httpd-2.4.69-261002-Win64-VS18.zip'
        url = 'https://www.apachelounge.com/download/VS18/binaries/' + name
        checksums = fetch(url + '.txt').decode('utf-8-sig')
        expected = re.search(r'SHA256-Checksum[^\n]*\n([A-Fa-f0-9]{64})', checksums).group(1).lower()
        algorithm = 'sha256'
        destination = LOCAL / 'tools' / 'Apache24'
        prefix = 'Apache24/'
    else:
        raise ValueError('Use sdk, database, or apache')
    if destination.exists():
        raise RuntimeError('Tool directory already exists; existing installation preserved')
    target = downloads / name
    print('Downloading ' + name, flush=True)
    existing_valid = False
    if target.exists():
        with target.open('rb') as stream:
            existing_valid = hashlib.file_digest(stream, algorithm).hexdigest() == expected
    if not existing_valid:
        partial = target.with_suffix(target.suffix + '.part')
        if 'apachelounge.com/' in url:
            subprocess.run(['curl.exe', '--fail', '--silent', '--show-error', '--location',
                            '--proto', '=https', '--proto-redir', '=https', '--output', str(partial), url], check=True)
        else:
            request = urllib.request.Request(url, headers={'User-Agent': 'Mozilla/5.0'})
            with urllib.request.urlopen(request, timeout=90) as response, partial.open('wb') as output:
                if not response.url.startswith('https://'):
                    raise RuntimeError('Download redirected away from HTTPS')
                while block := response.read(1024 * 1024):
                    output.write(block)
        partial.replace(target)
    with target.open('rb') as stream:
        actual = hashlib.file_digest(stream, algorithm).hexdigest()
    if actual != expected:
        raise RuntimeError('Vendor checksum mismatch; archive was not extracted')
    print('Vendor checksum verified', flush=True)
    with zipfile.ZipFile(target) as bundle:
        for member in bundle.infolist():
            if not member.filename.startswith(prefix):
                continue
            relative = member.filename[len(prefix):]
            if not relative:
                continue
            output = (destination / relative).resolve()
            if not output.is_relative_to(destination.resolve()):
                raise RuntimeError('Unsafe archive path')
            if member.is_dir():
                output.mkdir(parents=True, exist_ok=True)
            else:
                output.parent.mkdir(parents=True, exist_ok=True)
                output.write_bytes(bundle.read(member))
    print('Installed ' + kind + ': ' + str(destination), flush=True)

if __name__ == '__main__':
    install(sys.argv[1])
