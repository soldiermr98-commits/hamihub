#!/usr/bin/env python3
"""Automated regression, route-callability (all 84 API routes), and overlay smoke test."""
from __future__ import annotations

import hashlib
from pathlib import Path
import re
import subprocess
import sys
import tempfile

ROOT = Path(__file__).resolve().parent.parent
sys.path.insert(0, str(ROOT / 'scripts'))
import build_api_article_sync_update as builder  # noqa: E402

if not __debug__:
    raise RuntimeError('Run without Python optimization; assertions are required.')

SENTINELS = {
    'config/config.php': b'<?php /* live config sentinel: must never be overwritten */ return [];\n',
    'config/config.local.php': b'<?php /* local config sentinel */ return [];\n',
    'storage/private/ai-provider-master.key': b'sentinel-ai-master-key\n',
    'storage/private/advertising-payment-master.key': b'sentinel-payment-master-key\n',
    'storage/installed.lock': b'sentinel-installed-lock\n',
    'public_html/uploads/existing-business.webp': b'sentinel-upload-bytes\n',
    'public_html/cache/existing-page.html': b'<!-- sentinel cached html -->\n',
}


def sha(data: bytes) -> str:
    return hashlib.sha256(data).hexdigest()


def parse_manifest(data: bytes) -> dict[str, str]:
    manifest: dict[str, str] = {}
    for line in data.decode('utf-8').splitlines():
        digest, path = line.split('  ', 1)
        assert re.fullmatch(r'[0-9a-f]{64}', digest) and path not in manifest, line
        manifest[path] = digest
    return manifest


def run_cli(*args: str, expected_failure: str | None = None) -> subprocess.CompletedProcess[str]:
    proc = subprocess.run(
        [sys.executable, str(ROOT / 'scripts/build_api_article_sync_update.py'), *args],
        cwd=ROOT,
        text=True,
        capture_output=True,
    )
    if expected_failure is None:
        assert proc.returncode == 0, (proc.stdout, proc.stderr)
    else:
        assert proc.returncode != 0 and expected_failure in proc.stderr, (proc.stdout, proc.stderr)
    return proc


def snapshot_other_releases() -> dict[str, str]:
    result: dict[str, str] = {}
    for child in sorted((ROOT / 'release-files').iterdir()):
        if child.name == 'api-and-article-tags-sync' or not child.is_dir():
            continue
        for f in sorted(child.rglob('*')):
            if f.is_file():
                result[f.relative_to(ROOT).as_posix()] = sha(f.read_bytes())
    return result


def expect_route_error(fn, substring: str) -> None:
    try:
        fn()
    except builder.RouteVerificationError as exc:
        assert substring in str(exc), f'Expected {substring!r} in {str(exc)!r}'
        return
    raise AssertionError(f'Expected RouteVerificationError containing {substring!r}')


def main() -> None:
    other_before = snapshot_other_releases()
    published_dir = builder.DEFAULT_OUTPUT
    published_zip = published_dir / builder.ZIP_NAME
    assert published_zip.is_file(), f'Published archive missing: {published_zip}'

    with tempfile.TemporaryDirectory(prefix='hhx-api-sync-smoke-') as tmp:
        tmp_root = Path(tmp)

        # 1. Regression proof: the pre-fix deployed state MUST fail on line 47's route
        #    (POST /api/v1/system/update-article-tags-schema -> AuthController::updateArticleTagsSchema).
        pre_fix = tmp_root / 'pre-fix-host'
        pre_fix.mkdir()
        builder.reconstruct_deployed_baseline(pre_fix)
        expect_route_error(
            lambda: builder.verify_routes_file(
                pre_fix,
                'public_html/api/index.php',
                expected_count=builder.EXPECTED_API_ROUTE_COUNT,
            ),
            'POST /api/v1/system/update-article-tags-schema -> uncallable handler App\\Controllers\\AuthController::updateArticleTagsSchema',
        )

        # 2. Apply published ZIP onto the pre-fix host with sentinel files present.
        for rel, content in SENTINELS.items():
            dest = pre_fix / rel
            dest.parent.mkdir(parents=True, exist_ok=True)
            dest.write_bytes(content)

        zip_entries = builder.safe_zip_entries(published_zip)
        for secret in SENTINELS.values():
            assert not any(secret in data for data in zip_entries.values()), 'Sentinel data leaked into ZIP'

        assert set(zip_entries) == {'INSTALL-FA.md', 'MANIFEST-SHA256.txt', *builder.SYNC_FILES}
        assert zip_entries['INSTALL-FA.md'] == (published_dir / builder.GUIDE_NAME).read_bytes()

        manifest = parse_manifest(zip_entries['MANIFEST-SHA256.txt'])
        assert set(manifest) == set(builder.SYNC_FILES)
        for rel in builder.SYNC_FILES:
            assert sha(zip_entries[rel]) == manifest[rel], f'Manifest SHA mismatch for {rel}'
            assert zip_entries[rel] == (ROOT / rel).read_bytes(), f'Archive differs from repo file {rel}'

        ext_sums = parse_manifest((published_dir / builder.SHA_NAME).read_bytes())
        assert set(ext_sums) == {builder.ZIP_NAME, builder.GUIDE_NAME}
        for name, digest in ext_sums.items():
            assert sha((published_dir / name).read_bytes()) == digest, f'SHA256SUMS mismatch for {name}'

        for name, content in zip_entries.items():
            dest = pre_fix / name
            dest.parent.mkdir(parents=True, exist_ok=True)
            dest.write_bytes(content)

        # 3. Verify all 84 API routes, 8 public routes, static calls, migrations, and 100% HEAD parity!
        stats = builder.verify_tree(pre_fix, check_matches_head=True)
        assert stats['api_routes'] == 84, stats
        assert stats['public_routes'] == 8, stats
        assert stats['tracked_runtime_files'] >= 100, stats

        for rel, content in SENTINELS.items():
            assert (pre_fix / rel).read_bytes() == content, f'Sentinel modified: {rel}'

        # Check public CSS & admin asset cache-busting consistency in overlaid site.
        psc_src = (pre_fix / 'app/Controllers/PublicSiteController.php').read_text(encoding='utf-8')
        pub_css = (pre_fix / 'public_html/assets/css/public.css').read_text(encoding='utf-8')
        assert '/assets/css/public.css?v=s10.2' in psc_src
        assert '.hx-article-topic-tags' in pub_css
        admin_html = (pre_fix / 'public_html/admin/index.html').read_text(encoding='utf-8')
        for asset_name in ('admin.js', 'admin.css'):
            asset_hash = sha((pre_fix / 'public_html/admin/assets' / asset_name).read_bytes())[:12]
            assert f'/admin/assets/{asset_name}?v={asset_hash}' in admin_html

        # 4. Negative mutation tests: prove pre-build verifier catches every class of breakage.
        # 4a. Non-public handler method
        mut_priv = tmp_root / 'mut-private'
        mut_priv.mkdir()
        builder.reconstruct_deployed_baseline(mut_priv)
        for rel in builder.SYNC_FILES:
            dest = mut_priv / rel
            dest.parent.mkdir(parents=True, exist_ok=True)
            dest.write_bytes((ROOT / rel).read_bytes())
        biz_ctrl = mut_priv / 'app/Controllers/BusinessController.php'
        biz_ctrl.write_text(
            biz_ctrl.read_text(encoding='utf-8').replace(
                'public static function publishSaved(',
                'private static function publishSaved(',
                1,
            ),
            encoding='utf-8',
        )
        expect_route_error(
            lambda: builder.verify_tree(mut_priv, check_matches_head=False),
            'POST /api/v1/businesses/publish -> uncallable handler App\\Controllers\\BusinessController::publishSaved',
        )

        # 4b. Missing migration file referenced in Migrator.php
        biz_ctrl.write_bytes((ROOT / 'app/Controllers/BusinessController.php').read_bytes())
        (mut_priv / 'database/migrations/011_article_topic_tags.sql').unlink()
        expect_route_error(
            lambda: builder.verify_tree(mut_priv, check_matches_head=False),
            '011_article_topic_tags.sql',
        )

        # 4c. Broken cross-class static method call
        (mut_priv / 'database/migrations/011_article_topic_tags.sql').write_bytes(
            (ROOT / 'database/migrations/011_article_topic_tags.sql').read_bytes()
        )
        auth_ctrl = mut_priv / 'app/Controllers/AuthController.php'
        auth_ctrl.write_text(
            auth_ctrl.read_text(encoding='utf-8').replace(
                'Migrator::runArticleTagsUpdate()',
                'Migrator::missingTagsMethod()',
                1,
            ),
            encoding='utf-8',
        )
        expect_route_error(
            lambda: builder.verify_tree(mut_priv, check_matches_head=False),
            'App\\Core\\Migrator::missingTagsMethod()',
        )

        # 4d. Unexpected route count change in public_html/api/index.php
        auth_ctrl.write_bytes((ROOT / 'app/Controllers/AuthController.php').read_bytes())
        api_idx = mut_priv / 'public_html/api/index.php'
        api_idx.write_text(
            api_idx.read_text(encoding='utf-8').replace(
                "$router->get('/api/v1/auth/me'",
                "// $router->get('/api/v1/auth/me'",
                1,
            ),
            encoding='utf-8',
        )
        expect_route_error(
            lambda: builder.verify_tree(mut_priv, check_matches_head=False),
            'expected 84 routes, found 83',
        )

        # 5. Deterministic CLI rebuild and overwrite/path-safety guards.
        rebuilt = tmp_root / 'rebuilt'
        run_cli('--output', str(rebuilt))
        assert builder.safe_zip_entries(rebuilt / builder.ZIP_NAME) == zip_entries
        assert (rebuilt / builder.SHA_NAME).read_bytes() == (published_dir / builder.SHA_NAME).read_bytes()

        conflict = tmp_root / 'conflict'
        conflict.mkdir()
        (conflict / builder.ZIP_NAME).write_bytes(b'do-not-overwrite\n')
        run_cli('--output', str(conflict), expected_failure='Refusing to change existing candidate file')
        assert (conflict / builder.ZIP_NAME).read_bytes() == b'do-not-overwrite\n'

        sym = tmp_root / 'symlink-out'
        sym.symlink_to(rebuilt, target_is_directory=True)
        run_cli('--output', str(sym), expected_failure='symlinked output directory')

        run_cli('--output', str(ROOT / 'release-files/business-publish-fix'), expected_failure='another published release')

    assert snapshot_other_releases() == other_before, 'Other release-files directories were modified!'
    print(
        f'PASS: pre-fix TypeError reproduced; ZIP overlay verified ({stats["api_routes"]} API routes, '
        f'{stats["public_routes"]} public routes, {stats["static_calls"]} static calls, '
        f'{stats["tracked_runtime_files"]} runtime files match HEAD); negative mutation & safety guards passed.'
    )


if __name__ == '__main__':
    main()
