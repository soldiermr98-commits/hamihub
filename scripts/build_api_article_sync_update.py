#!/usr/bin/env python3
"""Build the incremental 8-file API & article/tags sync update package.

Before creating the ZIP archive, reconstructs the cumulative deployed baseline
in a temporary directory, overlays the 8 synced files, and verifies:
  1. All 84 routes in public_html/api/index.php resolve to existing PSR-4
     controller files with public static handler methods.
  2. All 8 routes in public_html/index.php resolve to existing PSR-4
     controller files with public static handler methods.
  3. All App\\ class imports, cross-class static method calls, and Migrator SQL
     file references across app/, public_html/, and cron/ are valid.
  4. All tracked runtime files (app/, database/, public_html/, cron/) match HEAD
     byte-for-byte after applying the overlay.
"""
from __future__ import annotations

import argparse
import hashlib
from pathlib import Path, PurePosixPath
import re
import stat
import subprocess
import tempfile
import zipfile

from build_update_candidate import check_existing, publish_new_or_same

ROOT = Path(__file__).resolve().parent.parent
RELEASES = ROOT / 'release-files'
DEFAULT_OUTPUT = RELEASES / 'api-and-article-tags-sync'
ZIP_NAME = 'hamihub-update-api-and-article-tags-sync.zip'
GUIDE_NAME = 'RAHNAMA-FA.md'
SHA_NAME = 'SHA256SUMS.txt'
GUIDE_SOURCE = DEFAULT_OUTPUT / GUIDE_NAME

EXPECTED_API_ROUTE_COUNT = 84
EXPECTED_PUBLIC_ROUTE_COUNT = 8

DEPLOYED_ARCHIVE_CHAIN = (
    'release-files/v2026.11.06/hamihub-full-install-v2026.11.06.zip',
    'release-files/v2026.11.06.1/hamihub-update-admin-user-v2026.11.06.1.zip',
    'release-files/v2026.11.07.1/hamihub-update-admin-public-v2026.11.07.1.zip',
    'release-files/v2026.11.07.2/hamihub-update-admin-public-v2026.11.07.2.zip',
    'release-files/v2026.11.07.3/hamihub-update-ai-admin-v2026.11.07.3.zip',
    'release-files/v2026.11.07.4/hamihub-fix-gemini-test-v2026.11.07.4.zip',
    'release-files/v2026.11.07.5/hamihub-update-ai-models-v2026.11.07.5.zip',
    'release-files/after-v2026.11.07.5-rc2/hamihub-update-after-v2026.11.07.5-rc2.zip',
    'release-files/after-rc2-worker-cache/hamihub-update-after-rc2-worker-cache.zip',
    'release-files/business-publish-fix/hamihub-update-business-publish-fix.zip',
)

SYNC_FILES = (
    'app/Controllers/ArticleController.php',
    'app/Controllers/AuthController.php',
    'app/Controllers/PublicSiteController.php',
    'app/Core/Migrator.php',
    'app/Services/ArticleService.php',
    'app/Services/ArticleText.php',
    'database/migrations/011_article_topic_tags.sql',
    'public_html/assets/css/public.css',
)

RUNTIME_TREES = ('app', 'database', 'public_html', 'cron')
FORBIDDEN_PREFIXES = ('config/', 'storage/', 'public_html/uploads/', 'public_html/cache/')
DOC_PREFIXES = ('INSTALL-', 'MANIFEST-', 'RAHNAMA-', 'UPGRADE-')

PHP_COMMENT_OR_LITERAL_RE = re.compile(
    r"//[^\n]*|#(?![\[])[^\n]*|/\*[\s\S]*?\*/"
    r"|\x27(?:\\.|[^\x27\\])*\x27"
    r"|\"(?:\\.|[^\"\\])*\""
    r"|<<<\x27?([A-Za-z_][A-Za-z0-9_]*)\x27?\r?\n[\s\S]*?\r?\n\s*\1;?"
)

PHP_COMMENT_ONLY_RE = re.compile(r"//[^\n]*|#(?![\[])[^\n]*|/\*[\s\S]*?\*/")


class RouteVerificationError(ValueError):
    """Raised when a route handler, class import, or runtime dependency is invalid."""


def git(*args: str) -> bytes:
    return subprocess.check_output(['git', *args], cwd=ROOT)


def sha(data: bytes) -> str:
    return hashlib.sha256(data).hexdigest()


def clean_php(src: str) -> str:
    """Replace PHP comments and string literals with whitespace while preserving line numbers."""
    return PHP_COMMENT_OR_LITERAL_RE.sub(lambda m: '\n' * m.group(0).count('\n') + ' ', src)


def strip_php_comments(src: str) -> str:
    """Strip PHP comments while preserving string literals (used for route pattern parsing)."""
    return PHP_COMMENT_ONLY_RE.sub(lambda m: '\n' * m.group(0).count('\n') + ' ', src)


def safe_zip_entries(archive_path: Path) -> dict[str, bytes]:
    entries: dict[str, bytes] = {}
    with zipfile.ZipFile(archive_path) as zf:
        if zf.testzip() is not None:
            raise ValueError(f'CRC check failed for {archive_path}')
        for info in zf.infolist():
            name = info.filename
            parts = PurePosixPath(name).parts
            mode = info.external_attr >> 16
            if name.startswith('/') or '\\' in name or '..' in parts or stat.S_ISLNK(mode):
                raise ValueError(f'Unsafe ZIP entry in {archive_path}: {name}')
            if info.is_dir():
                continue
            if name in entries:
                raise ValueError(f'Duplicate ZIP entry in {archive_path}: {name}')
            entries[name] = zf.read(info)
    return entries


def reconstruct_deployed_baseline(stage: Path) -> None:
    """Replay the published installation and update archives into stage."""
    for rel_zip in DEPLOYED_ARCHIVE_CHAIN:
        entries = safe_zip_entries(ROOT / rel_zip)
        for name, data in entries.items():
            if '/' not in name and name.startswith(DOC_PREFIXES):
                continue
            dest = stage / name
            dest.parent.mkdir(parents=True, exist_ok=True)
            dest.write_bytes(data)


def index_php_types(stage: Path) -> dict[str, dict[str, object]]:
    """Index all App\\ classes/interfaces/traits/enums and their methods under stage/app."""
    app_dir = stage / 'app'
    if not app_dir.is_dir():
        raise RouteVerificationError(f'Missing app directory in {stage}')
    types: dict[str, dict[str, object]] = {}
    for php_file in sorted(app_dir.rglob('*.php')):
        rel = php_file.relative_to(stage).as_posix()
        # Explicitly vendored PHPMailer classes use their upstream namespace.
        if rel in ('app/ThirdParty/PHPMailer/PHPMailer.php', 'app/ThirdParty/PHPMailer/SMTP.php', 'app/ThirdParty/PHPMailer/Exception.php'):
            continue
        cleaned = clean_php(php_file.read_text(encoding='utf-8'))
        ns_match = re.search(r'\bnamespace\s+(App(?:\\[A-Za-z0-9_]+)*)\s*;', cleaned)
        if not ns_match:
            raise RouteVerificationError(f'Missing App\\ namespace declaration in {rel}')
        ns = ns_match.group(1)
        decl_re = re.compile(
            r'\b(?:final\s+|abstract\s+|readonly\s+)*(class|interface|trait|enum)\s+([A-Za-z_][A-Za-z0-9_]*)\b'
        )
        found_any = False
        for dm in decl_re.finditer(cleaned):
            found_any = True
            kind, cname = dm.group(1), dm.group(2)
            brace_open = cleaned.find('{', dm.end())
            if brace_open == -1:
                raise RouteVerificationError(f'Missing opening brace for {cname} in {rel}')
            depth = 0
            brace_close = -1
            for idx in range(brace_open, len(cleaned)):
                ch = cleaned[idx]
                if ch == '{':
                    depth += 1
                elif ch == '}':
                    depth -= 1
                    if depth == 0:
                        brace_close = idx
                        break
            if brace_close == -1:
                raise RouteVerificationError(f'Unclosed brace for {cname} in {rel}')
            body = cleaned[brace_open + 1:brace_close]
            pub_static = set(
                re.findall(
                    r'\b(?:public\s+static|static\s+public)\s+function\s+([A-Za-z_][A-Za-z0-9_]*)\s*\(',
                    body,
                )
            )
            all_methods = set(re.findall(r'\bfunction\s+([A-Za-z_][A-Za-z0-9_]*)\s*\(', body))
            fqcn = f'{ns}\\{cname}'
            if fqcn in types:
                raise RouteVerificationError(f'Duplicate type declaration {fqcn} in {rel}')
            types[fqcn] = {
                'file': rel,
                'kind': kind,
                'public_static': pub_static,
                'methods': all_methods,
            }
        if not found_any:
            raise RouteVerificationError(f'No PHP type declaration found in {rel}')
    return types


def parse_use_aliases(cleaned_php: str) -> dict[str, str]:
    aliases: dict[str, str] = {}
    for um in re.finditer(
        r'\buse\s+\\?(App\\[A-Za-z0-9_\\]+)(?:\s+as\s+([A-Za-z_][A-Za-z0-9_]*))?\s*;',
        cleaned_php,
    ):
        fqcn = um.group(1)
        alias = um.group(2) or fqcn.split('\\')[-1]
        aliases[alias] = fqcn
    return aliases


def verify_routes_file(
    stage: Path,
    rel_entrypoint: str,
    *,
    expected_count: int,
    types: dict[str, dict[str, object]] | None = None,
) -> list[tuple[str, str, str, str]]:
    """Verify every $router->(get|post|put|delete) registration in rel_entrypoint."""
    if types is None:
        types = index_php_types(stage)
    entry_path = stage / rel_entrypoint
    if not entry_path.is_file():
        raise RouteVerificationError(f'Missing entrypoint file: {rel_entrypoint}')

    raw_src = entry_path.read_text(encoding='utf-8')
    cleaned = clean_php(raw_src)
    uncommented = strip_php_comments(raw_src)
    aliases = parse_use_aliases(cleaned)

    for alias, fqcn in aliases.items():
        if fqcn not in types:
            raise RouteVerificationError(
                f'{rel_entrypoint}: imported class {fqcn} (as {alias}) does not exist in app/'
            )

    # Ensure every $router->get/post/put/delete call uses standard [Class::class, 'method'] syntax.
    raw_router_calls = re.findall(r'\$router\s*->\s*(?:get|post|put|delete)\s*\(', cleaned)
    route_re = re.compile(
        r'\$router\s*->\s*(get|post|put|delete)\s*\(\s*'
        r'\x27([^\x27]+)\x27\s*,\s*'
        r'\[\s*\\?([A-Za-z0-9_\\]+)::class\s*,\s*\x27([A-Za-z_][A-Za-z0-9_]*)\x27\s*\]\s*\)'
    )
    routes = route_re.findall(uncommented)
    if len(routes) != len(raw_router_calls):
        raise RouteVerificationError(
            f'{rel_entrypoint}: parsed {len(routes)} structured routes but found {len(raw_router_calls)} router calls'
        )
    if len(routes) != expected_count:
        raise RouteVerificationError(
            f'{rel_entrypoint}: expected {expected_count} routes, found {len(routes)}'
        )

    verified: list[tuple[str, str, str, str]] = []
    for http_method, pattern, cls_ref, method_name in routes:
        fqcn = cls_ref if cls_ref.startswith('App\\') else aliases.get(cls_ref, '')
        if not fqcn:
            raise RouteVerificationError(
                f'{rel_entrypoint}: {http_method.upper()} {pattern} references unimported class {cls_ref}'
            )
        expected_rel_path = 'app/' + fqcn[len('App\\'):].replace('\\', '/') + '.php'
        if not (stage / expected_rel_path).is_file():
            raise RouteVerificationError(
                f'{rel_entrypoint}: {http_method.upper()} {pattern} -> missing PSR-4 file {expected_rel_path} for {fqcn}'
            )
        type_info = types.get(fqcn)
        if type_info is None or type_info['file'] != expected_rel_path or type_info['kind'] != 'class':
            raise RouteVerificationError(
                f'{rel_entrypoint}: {http_method.upper()} {pattern} -> {fqcn} is not declared as class in {expected_rel_path}'
            )
        pub_static = type_info['public_static']
        assert isinstance(pub_static, set)
        if method_name not in pub_static:
            raise RouteVerificationError(
                f'{rel_entrypoint}: {http_method.upper()} {pattern} -> uncallable handler {fqcn}::{method_name}'
            )
        verified.append((http_method.upper(), pattern, fqcn, method_name))

    return verified


def verify_static_calls_and_migrations(
    stage: Path,
    types: dict[str, dict[str, object]] | None = None,
) -> int:
    """Verify all App\\ imports, cross-class static calls, and Migrator SQL references."""
    if types is None:
        types = index_php_types(stage)

    checked_calls = 0
    for sub in ('app', 'public_html', 'cron'):
        base_dir = stage / sub
        if not base_dir.is_dir():
            continue
        for php_file in sorted(base_dir.rglob('*.php')):
            rel = php_file.relative_to(stage).as_posix()
            if rel in ('public_html/check.php', 'public_html/install.php'):
                continue
            raw = php_file.read_text(encoding='utf-8')
            cleaned = clean_php(raw)
            ns_match = re.search(r'\bnamespace\s+(App(?:\\[A-Za-z0-9_]+)*)\s*;', cleaned)
            cur_ns = ns_match.group(1) if ns_match else ''
            aliases = parse_use_aliases(cleaned)
            for alias, fqcn in aliases.items():
                if fqcn not in types:
                    raise RouteVerificationError(f'{rel}: imported missing type {fqcn} (as {alias})')
            for cm in re.finditer(r'(?<!\\)\b([A-Z][A-Za-z0-9_]*)::([a-zA-Z_][A-Za-z0-9_]*)\s*\(', cleaned):
                cls_ref, meth = cm.group(1), cm.group(2)
                target = aliases.get(cls_ref) or (
                    f'{cur_ns}\\{cls_ref}' if cur_ns and f'{cur_ns}\\{cls_ref}' in types else None
                )
                if target:
                    pub_static = types[target]['public_static']
                    assert isinstance(pub_static, set)
                    if meth not in pub_static:
                        raise RouteVerificationError(
                            f'{rel}: static call to missing/non-public {target}::{meth}()'
                        )
                    checked_calls += 1

    migrator_path = stage / 'app/Core/Migrator.php'
    if not migrator_path.is_file():
        raise RouteVerificationError('Missing app/Core/Migrator.php')
    migrator_uncommented = strip_php_comments(migrator_path.read_text(encoding='utf-8'))
    sql_refs = set(re.findall(r'\x27([0-9]{3}_[a-z0-9_]+\.sql)\x27', migrator_uncommented))
    if '011_article_topic_tags.sql' not in sql_refs:
        raise RouteVerificationError('Migrator.php does not reference 011_article_topic_tags.sql')
    for sql_name in sorted(sql_refs):
        sql_path = stage / 'database/migrations' / sql_name
        if not sql_path.is_file() or sql_path.read_text(encoding='utf-8').strip() == '':
            raise RouteVerificationError(
                f'Migrator.php references missing or empty SQL file: database/migrations/{sql_name}'
            )

    return checked_calls


def verify_tree(stage: Path, *, check_matches_head: bool = True) -> dict[str, int]:
    """Run full route, static-call, migration, and optional HEAD-parity verification on stage."""
    types = index_php_types(stage)
    api_routes = verify_routes_file(
        stage,
        'public_html/api/index.php',
        expected_count=EXPECTED_API_ROUTE_COUNT,
        types=types,
    )
    public_routes = verify_routes_file(
        stage,
        'public_html/index.php',
        expected_count=EXPECTED_PUBLIC_ROUTE_COUNT,
        types=types,
    )
    static_calls = verify_static_calls_and_migrations(stage, types=types)

    tracked_count = 0
    if check_matches_head:
        tracked = git('ls-tree', '-r', '-z', '--name-only', 'HEAD', '--', *RUNTIME_TREES).decode().split('\0')
        tracked_paths = sorted(p for p in tracked if p)
        for rel in tracked_paths:
            staged_file = stage / rel
            if not staged_file.is_file():
                raise RouteVerificationError(f'Missing runtime file after overlay: {rel}')
            expected_bytes = git('show', f'HEAD:{rel}')
            if staged_file.read_bytes() != expected_bytes:
                raise RouteVerificationError(f'Runtime file diverges from HEAD after overlay: {rel}')
        tracked_count = len(tracked_paths)

    return {
        'types': len(types),
        'api_routes': len(api_routes),
        'public_routes': len(public_routes),
        'static_calls': static_calls,
        'tracked_runtime_files': tracked_count,
    }


def make_archive(files: dict[str, bytes]) -> bytes:
    with tempfile.TemporaryFile() as temp:
        with zipfile.ZipFile(temp, 'w', compression=zipfile.ZIP_DEFLATED, compresslevel=9) as zf:
            for name, content in sorted(files.items()):
                info = zipfile.ZipInfo(name, (2026, 11, 9, 0, 0, 0))
                info.create_system = 3
                info.external_attr = 0o100644 << 16
                info.compress_type = zipfile.ZIP_DEFLATED
                zf.writestr(info, content, compress_type=zipfile.ZIP_DEFLATED, compresslevel=9)
        temp.seek(0)
        return temp.read()


def safe_output(path: Path) -> Path:
    path = path.expanduser().absolute()
    if any(part.is_symlink() for part in (path, *path.parents)):
        raise ValueError('Refusing to write through a symlinked output directory.')
    resolved = path.resolve()
    releases, allowed = RELEASES.resolve(), DEFAULT_OUTPUT.resolve()
    if resolved == ROOT or resolved == releases:
        raise ValueError('Refusing to write at the repository or release root.')
    if releases in resolved.parents and resolved != allowed and allowed not in resolved.parents:
        raise ValueError('Refusing to write inside another published release.')
    return resolved


def build(output: Path) -> Path:
    output = safe_output(output)
    if not GUIDE_SOURCE.is_file():
        raise FileNotFoundError(f'Missing guide file: {GUIDE_SOURCE}')
    guide_bytes = GUIDE_SOURCE.read_bytes()

    payload_files: dict[str, bytes] = {}
    for rel in SYNC_FILES:
        if rel.startswith('/') or '\\' in rel or '..' in PurePosixPath(rel).parts:
            raise ValueError(f'Unsafe runtime path: {rel}')
        if rel.startswith(FORBIDDEN_PREFIXES):
            raise ValueError(f'Forbidden path in sync package: {rel}')
        tree_entry = git('ls-tree', 'HEAD', '--', rel).decode().strip()
        if not tree_entry.startswith('100644 blob '):
            raise ValueError(f'Not a regular tracked file in HEAD: {rel}')
        head_bytes = git('show', f'HEAD:{rel}')
        work_bytes = (ROOT / rel).read_bytes()
        if head_bytes != work_bytes:
            raise ValueError(f'Working tree file differs from HEAD: {rel}')
        payload_files[rel] = head_bytes

    # Pre-build gate: reconstruct the deployed server state, apply the 8 files,
    # and require all 84 API routes + 8 public routes + static calls + HEAD parity to pass.
    with tempfile.TemporaryDirectory(prefix='hhx-prebuild-verify-') as temp_dir:
        stage = Path(temp_dir)
        reconstruct_deployed_baseline(stage)
        for rel, content in payload_files.items():
            dest = stage / rel
            dest.parent.mkdir(parents=True, exist_ok=True)
            dest.write_bytes(content)
        stats = verify_tree(stage, check_matches_head=True)

    manifest_bytes = ''.join(f'{sha(data)}  {path}\n' for path, data in sorted(payload_files.items())).encode()
    zip_entries = {
        'INSTALL-FA.md': guide_bytes,
        'MANIFEST-SHA256.txt': manifest_bytes,
        **payload_files,
    }
    zip_bytes = make_archive(zip_entries)
    products = {
        ZIP_NAME: zip_bytes,
        GUIDE_NAME: guide_bytes,
    }
    products[SHA_NAME] = ''.join(f'{sha(data)}  {name}\n' for name, data in products.items()).encode()

    output.mkdir(parents=True, exist_ok=True)
    for name, data in products.items():
        check_existing(output / name, data)
    for name, data in products.items():
        publish_new_or_same(output / name, data)

    archive_path = output / ZIP_NAME
    print(
        f'{archive_path}\n'
        f'SHA-256 {sha(zip_bytes)}\n'
        f'Pre-build verified: {stats["api_routes"]} API routes, {stats["public_routes"]} public routes, '
        f'{stats["static_calls"]} static calls, {stats["tracked_runtime_files"]} runtime files matched HEAD'
    )
    return archive_path


if __name__ == '__main__':
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument(
        '--output',
        type=Path,
        default=DEFAULT_OUTPUT,
        help='output directory (default: release-files/api-and-article-tags-sync)',
    )
    args = parser.parse_args()
    build(args.output)
