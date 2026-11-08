#!/usr/bin/env python3
"""Build the staging-only post-.5 overlay from pinned, committed Git objects.

No working-tree application files, configuration, storage, uploads, or secrets
are included. This is an incremental candidate, never a full installation.
"""
from __future__ import annotations

import argparse
import hashlib
import os
from pathlib import Path
import subprocess
import zipfile

ROOT = Path(__file__).resolve().parent.parent
BASE = '35476933f98ebd2d5d442ae4a8eb54dc7e32e5b0'  # PR #12, .5 code base
TARGET = '543b0d6eeed8e51b1986268a43bee12cb564cc67'  # PR #17 merge
DEFAULT_OUTPUT = ROOT / 'release-files/after-v2026.11.07.5-rc1'
ZIP_NAME = 'hamihub-update-after-v2026.11.07.5-rc1.zip'
BASELINE_NAME = 'BASELINE-SHA256.txt'
GUIDE = DEFAULT_OUTPUT / 'RAHNAMA-FA.md'
RUNTIME_PREFIXES = ('app/', 'database/', 'public_html/')
FORBIDDEN_PREFIXES = ('public_html/uploads/', 'public_html/cache/')
META = ('UPGRADE-RC-FA.md', 'UPGRADE-RC-SOURCE.txt', 'UPGRADE-RC-MANIFEST.txt', 'UPGRADE-RC-BASELINE-SHA256.txt')


def git(*args: str) -> bytes:
    return subprocess.check_output(['git', *args], cwd=ROOT)


def runtime_changes() -> dict[str, bytes]:
    for revision in (BASE, TARGET):
        if git('rev-parse', '--verify', f'{revision}^{{commit}}').decode().strip() != revision:
            raise ValueError(f'Unexpected commit for {revision}')
    output = git('diff', '--no-renames', '--name-status', '-z', BASE, TARGET)
    fields = output.decode('utf-8').split('\0')
    entries: dict[str, bytes] = {}
    for i in range(0, len(fields) - 1, 2):
        status, path = fields[i:i + 2]
        if not status:
            continue
        if path.startswith(('cron/', 'collector-remote/')) or (path.startswith('config/') and path != 'config/config.sample.php'):
            raise ValueError(f'Runtime change needs explicit packaging review: {path}')
        if not path.startswith(RUNTIME_PREFIXES):
            continue
        if status not in ('A', 'M') or path.startswith(FORBIDDEN_PREFIXES):
            raise ValueError(f'Unexpected runtime change (deletion, rename, or data): {status} {path}')
        if path.startswith('/') or '..' in Path(path).parts or '\\' in path:
            raise ValueError(f'Unsafe path: {path}')
        tree = git('ls-tree', TARGET, '--', path).decode().strip()
        if not tree.startswith(('100644 blob ', '100755 blob ')):
            raise ValueError(f'Not a regular tracked file: {path}')
        entries[path] = git('show', f'{TARGET}:{path}')
    if not entries or not all(f'database/migrations/{n}' in entries for n in (
        '008_advertising_reporting_contracts.sql',
        '009_advertising_finance.sql',
        '010_advertising_receipt_uniqueness.sql',
    )):
        raise ValueError('The expected runtime changes/migrations are missing.')
    return dict(sorted(entries.items()))


def digest(data: bytes) -> str:
    return hashlib.sha256(data).hexdigest()


def baseline_manifest() -> bytes:
    paths = git('ls-tree', '-r', '-z', '--name-only', BASE, '--', 'app', 'database', 'public_html').decode().split('\0')
    entries = sorted(p for p in paths if p.startswith(RUNTIME_PREFIXES) and not p.startswith(FORBIDDEN_PREFIXES))
    if not entries or any(p.startswith('/') or '..' in Path(p).parts or '\\' in p for p in entries):
        raise ValueError('Unexpected baseline runtime paths')
    return ''.join(f'{digest(git("show", f"{BASE}:{path}"))}  {path}\n' for path in entries).encode()


def build(output: Path) -> Path:
    changes = runtime_changes()
    guide = GUIDE.read_bytes()
    baseline = baseline_manifest()
    source = (f'STAGING ONLY — incremental update, not a full installation\n'
              f'Baseline code: {BASE} (PR #12; .5 is itself incremental)\n'
              f'Target code:   {TARGET} (PR #17)\n'
              f'Runtime files: {len(changes)}; SQL files are not executed on extraction.\n'
              'Baseline manifest lists expected old code files, not configuration or data.\n'
              'No config, storage, uploads, user data, or credentials are included.\n'
              'Online payment routes are default-off; no real payment is enabled.\n').encode()
    manifest = ''.join(f'{digest(data)}  {path}\n' for path, data in changes.items()).encode()
    files = {'UPGRADE-RC-FA.md': guide, 'UPGRADE-RC-SOURCE.txt': source,
             'UPGRADE-RC-MANIFEST.txt': manifest, 'UPGRADE-RC-BASELINE-SHA256.txt': baseline, **changes}
    output.mkdir(parents=True, exist_ok=True)
    archive = output / ZIP_NAME
    # Stable names, ordering, permissions and timestamps. Compression output may
    # still differ between Python/zlib versions.
    temp = output / (ZIP_NAME + '.tmp')
    try:
        with zipfile.ZipFile(temp, 'w', compression=zipfile.ZIP_DEFLATED, compresslevel=9) as z:
            for path, data in sorted(files.items()):
                info = zipfile.ZipInfo(path, (2026, 11, 7, 0, 0, 0))
                info.create_system = 3
                info.external_attr = 0o100644 << 16
                info.compress_type = zipfile.ZIP_DEFLATED
                z.writestr(info, data, compress_type=zipfile.ZIP_DEFLATED, compresslevel=9)
        os.replace(temp, archive)
    finally:
        if temp.exists():
            temp.unlink()
    (output / BASELINE_NAME).write_bytes(baseline)
    archive_hash = digest(archive.read_bytes())
    (output / 'SHA256SUMS.txt').write_text(
        f'{archive_hash}  {ZIP_NAME}\n{digest(baseline)}  {BASELINE_NAME}\n', encoding='utf-8')
    print(f'{archive}\nSHA-256 {archive_hash}\n{len(changes)} runtime files + {len(META)} information files')
    return archive


if __name__ == '__main__':
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--output', type=Path, default=DEFAULT_OUTPUT, help='output directory')
    args = parser.parse_args()
    build(args.output.resolve())
