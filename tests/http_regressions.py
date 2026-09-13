"""HTTP regressions for an explicitly isolated, running WordPress fixture."""
import csv
import html
import http.cookiejar
import io
import json
import os
from pathlib import Path
import re
import subprocess
import urllib.error
import urllib.parse
import urllib.request
import uuid

root = Path(os.environ['GTLM_TEST_WP_ROOT'])
base = os.environ.get('GTLM_TEST_URL', 'http://127.0.0.1:18990').rstrip('/')
assert urllib.parse.urlparse(base).hostname in ('127.0.0.1', 'localhost'), 'Local fixture only'
checks = []


def php(code):
    guard = 'require getenv("GTLM_TEST_WP_ROOT")."/wp-load.php";if(!defined("GTLM_TEST_FIXTURE")||!GTLM_TEST_FIXTURE||!str_starts_with(DB_NAME,"gtlm_test_"))exit(2);'
    result = subprocess.run(['php', '-r', guard + code], capture_output=True, text=True, check=True)
    return json.loads(result.stdout)


def check(ok, label):
    checks.append({'ok': bool(ok), 'check': label})
    assert ok, label


php('echo json_encode(true);')
client = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
page = base + '/wp-admin/admin.php?page=gtlm-links-import-export'


def request(url, fields=None, upload=None):
    data = None
    headers = {}
    if upload is not None:
        boundary = 'gtlm-' + uuid.uuid4().hex
        parts = []
        for key, value in fields.items():
            parts.append(f'--{boundary}\r\nContent-Disposition: form-data; name="{key}"\r\n\r\n{value}\r\n'.encode())
        filename, content = upload
        parts.append(f'--{boundary}\r\nContent-Disposition: form-data; name="import_file"; filename="{filename}"\r\nContent-Type: text/csv\r\n\r\n'.encode() + content + b'\r\n')
        parts.append(f'--{boundary}--\r\n'.encode())
        data = b''.join(parts)
        headers['Content-Type'] = 'multipart/form-data; boundary=' + boundary
    elif fields is not None:
        data = urllib.parse.urlencode(fields).encode()
    try:
        r = client.open(urllib.request.Request(url, data=data, headers=headers), timeout=20)
    except urllib.error.HTTPError as error:
        r = error
    return r.status, r.headers, r.read().decode('utf-8', errors='replace')


def nonce(body):
    return html.unescape(re.search(r'name="_wpnonce" value="([^"]+)"', body)[1])


def preview_state():
    return php('$s=get_transient("gtlm_import_preview_1");echo json_encode($s);')


def post_action(action, extras=None, upload=None):
    body = request(page)[2]
    fields = {'_wpnonce': nonce(body), 'gtlm_import_export_action': action}
    fields.update(extras or {})
    return request(page, fields, upload)


request(base + '/wp-login.php')
status, _, body = request(base + '/wp-login.php', {'log': os.environ['GTLM_TEST_USER'], 'pwd': os.environ['GTLM_TEST_PASSWORD'], 'wp-submit': 'Log In', 'redirect_to': page, 'testcookie': '1'})
check('Preview CSV' in body, 'Fixture login succeeds')
# Use a conforming CSV writer for the quote/backslash fixture.
sink = io.StringIO(newline='')
csv.writer(sink).writerows([['name', 'slug', 'url', 'notes'], ['=1+1', 'http-security-fixture', 'https://example.invalid/target', 'backslash \", =SUM(A1:A2)']])
source = sink.getvalue().encode()
status, _, body = post_action('preview_csv', {'preset': 'generic'}, ('fixture.csv', source))
state = preview_state()
check(status == 200 and 'Map Columns' in body and bool(state), 'Authenticated multipart CSV stages and previews')
path = Path(state['file_path'])
check(path.is_file() and root.resolve() not in path.resolve().parents and path.stat().st_mode & 0o077 == 0, 'Staged CSV is outside web root with private permissions')
check('file_path' not in body and str(path) not in body, 'Preview does not disclose storage path')
post_action('cancel_import')
check(not path.exists() and not preview_state(), 'Cancel deletes staged file and preview state')
# Failed mapping must also delete the file.
post_action('preview_csv', {'preset': 'generic'}, ('fixture.csv', source))
state = preview_state()
post_action('import_csv', {'preview_token': state['token'], 'map[name]': '-1', 'map[url]': '2'})
check(not Path(state['file_path']).exists() and not preview_state(), 'Invalid mapping cleans staged CSV')
status, _, _ = post_action('preview_csv', {'preset': 'generic'}, ('fixture.txt', source))
check(status >= 400 and not preview_state(), 'Non-CSV filename is rejected')
status, _, _ = post_action('preview_csv', {'preset': 'generic'}, ('fixture.csv', b'x' * (5 * 1024 * 1024 + 1)))
check(not preview_state(), 'Oversized upload creates no preview')
post_action('preview_csv', {'preset': 'generic'}, ('fixture.csv', source))
state = preview_state()
post_action('import_csv', {'preview_token': state['token'], 'map[name]': '0', 'map[slug]': '1', 'map[url]': '2', 'map[notes]': '3', 'duplicate_mode': 'overwrite'})
check(not Path(state['file_path']).exists() and not preview_state(), 'Successful import cleans staged CSV')
row = php('$r=(new GTLM_DB())->get_link_by_exact_slug("http-security-fixture");echo json_encode($r);')
check(row['name'] == '=1+1', 'Imported formula-like name remains literal configuration')
status, headers, body = post_action('export_csv', {'export_search': 'http-security-fixture'})
rows = list(csv.DictReader(io.StringIO(body)))
check('text/csv' in headers.get('Content-Type', '') and len(rows) == 1 and rows[0]['name'] == "'=1+1" and rows[0]['format_version'] == '3' and rows[0]['notes'] == row['notes'], 'Real exporter protects formulas and preserves RFC CSV columns')
post_action('preview_csv', {'preset': 'generic'}, ('roundtrip.csv', body.encode()))
state = preview_state()
fields = {'preview_token': state['token'], 'duplicate_mode': 'overwrite'}
for key in ['name', 'slug', 'url', 'notes']:
    fields['map[' + key + ']'] = str(list(rows[0]).index(key))
post_action('import_csv', fields)
restored = php('$r=(new GTLM_DB())->get_link_by_exact_slug("http-security-fixture");echo json_encode($r);')
check(restored['name'] == row['name'] and restored['notes'] == row['notes'], 'Format 3 export/import roundtrip is lossless')
php('$db=new GTLM_DB();$r=$db->get_link_by_exact_slug("http-security-fixture");$db->delete_link((int)$r["id"]);echo json_encode(true);')
print(json.dumps({'passed': len(checks), 'checks': checks, 'failed': []}, indent=2))
