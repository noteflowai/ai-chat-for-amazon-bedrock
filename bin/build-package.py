#!/usr/bin/env python3
"""Build the distributable ZIP, and report exactly what goes into it.

The exclusion list lives in .distignore and nowhere else. Every other check reads it
from here, so a new development directory cannot end up in a release because one of
several hand written lists was forgotten.

Usage:
  bin/build-package.py                 # build dist/<slug>.<version>.zip
  bin/build-package.py --list          # print the files that would ship
  bin/build-package.py --verify FILE   # check an existing ZIP against the rules
"""

import argparse
import glob
import hashlib
import os
import re
import shutil
import subprocess
import sys
import tempfile
import zipfile
from pathlib import Path

SLUG = 'ai-chat-for-amazon-bedrock'
PROJECT_DIR = Path(__file__).resolve().parent.parent
SECRET_PATTERNS = (
    re.compile(r'AKIA[0-9A-Z]{16}'),
    re.compile(r'svn_[A-Za-z0-9]{20,}'),
    re.compile(r'-----BEGIN [A-Z ]*PRIVATE KEY-----'),
)
TEXT_SUFFIXES = {'.php', '.js', '.css', '.txt', '.json', '.pot', '.po', '.md', '.xml'}
# Scripts and styles that get a minified copy in the package, beside the readable file the
# plugin falls back to (see AI_Chat_Bedrock::minified_src()).
MINIFY_DIRS = {'public/js', 'public/css', 'admin/js', 'admin/css'}
# The esbuild the minified copies were checked with. Another version is not used unless
# AICFAB_ESBUILD_ANY=1 says so: what goes into the package should not depend on whatever
# happens to be in a cache on the machine that builds it.
ESBUILD_VERSION = '0.28.2'


def read_distignore():
    """Parse .distignore into directory and file rules."""
    path = PROJECT_DIR / '.distignore'
    if not path.exists():
        raise SystemExit('.distignore is missing, so the package contents are undefined.')

    directories, files = set(), set()
    for raw in path.read_text().splitlines():
        line = raw.strip()
        if not line or line.startswith('#'):
            continue
        entry = line.strip('/')
        if line.endswith('/'):
            directories.add(entry)
        else:
            files.add(entry)

    # Always excluded: version control, dependencies and anything hidden.
    directories.update({'.git', '.svn', 'node_modules', 'vendor', '.tools', '.github'})
    return directories, files


def shipped_files():
    """Every file that belongs in the package, relative to the project root."""
    directories, files = read_distignore()
    selected = []

    for path in sorted(PROJECT_DIR.rglob('*')):
        if path.is_dir():
            continue
        rel = path.relative_to(PROJECT_DIR)
        parts = rel.parts

        if any(part in directories for part in parts):
            continue
        if rel.as_posix() in files or rel.name in files:
            continue
        # Hidden files never ship: the directory rejects them.
        if any(part.startswith('.') for part in parts):
            continue
        selected.append(rel)

    return selected


def plugin_version():
    header = (PROJECT_DIR / f'{SLUG}.php').read_text()
    match = re.search(r'^\s*\*\s*Version:\s*([0-9.]+)', header, re.M)
    if not match:
        raise SystemExit('Could not read the version from the plugin header.')
    return match.group(1)


def minifiable(rel):
    return rel.parent.as_posix() in MINIFY_DIRS and rel.suffix in ('.js', '.css') and not rel.stem.endswith('.min')


def minified_name(rel):
    return rel.with_name(f'{rel.stem}.min{rel.suffix}')


def esbuild_version(binary):
    try:
        return subprocess.run([binary, '--version'], capture_output=True, text=True, check=False, timeout=30).stdout.strip()
    except (OSError, subprocess.SubprocessError):
        return ''


def esbuild_binary():
    """The pinned esbuild from AICFAB_ESBUILD, the PATH, or npx's cache; None when there is none."""
    candidates = [os.environ.get('AICFAB_ESBUILD', ''), shutil.which('esbuild') or '']
    candidates += sorted(glob.glob(os.path.expanduser('~/.npm/_npx/*/node_modules/esbuild/bin/esbuild')))
    found = [c for c in candidates if c and os.access(c, os.X_OK)]
    for candidate in found:
        if esbuild_version(candidate) == ESBUILD_VERSION:
            return candidate
    if found and os.environ.get('AICFAB_ESBUILD_ANY') == '1':
        print(f'warning: using esbuild {esbuild_version(found[0])}, not the pinned {ESBUILD_VERSION}')
        return found[0]
    if found:
        print(f'warning: no esbuild {ESBUILD_VERSION} found (found {", ".join(sorted({esbuild_version(c) for c in found}))}); set AICFAB_ESBUILD to it, or AICFAB_ESBUILD_ANY=1')
    return None


def minify(paths):
    """Minified copies of the shipped scripts and styles, by their package path.

    Targets ES2017, so no newer syntax than the readable scripts use reaches a browser. Each
    minified script is parsed again by Node when it is available.
    """
    binary = esbuild_binary()
    if not binary:
        print('warning: the package ships readable scripts and styles only')
        return {}
    node = shutil.which('node')
    copies = {}
    for rel in paths:
        if not minifiable(rel):
            continue
        result = subprocess.run(
            [binary, str(PROJECT_DIR / rel), '--minify', '--target=es2017', '--log-level=error'],
            capture_output=True, check=False,
        )
        if result.returncode != 0 or not result.stdout:
            raise SystemExit(f'Could not minify {rel}: {result.stderr.decode(errors="ignore").strip()}')
        if rel.suffix == '.js' and node:
            with tempfile.NamedTemporaryFile(suffix='.js') as handle:
                handle.write(result.stdout)
                handle.flush()
                if subprocess.run([node, '--check', handle.name], capture_output=True, check=False).returncode != 0:
                    raise SystemExit(f'The minified copy of {rel} does not parse.')
        copies[minified_name(rel)] = result.stdout
    return copies


def audit(paths):
    """Report anything that must never ship."""
    problems = []
    for rel in paths:
        if rel.suffix.lower() not in TEXT_SUFFIXES:
            continue
        body = (PROJECT_DIR / rel).read_text(errors='ignore')
        for pattern in SECRET_PATTERNS:
            if pattern.search(body):
                problems.append(f'{rel}: matches {pattern.pattern}')
    return problems


def build(destination):
    version = plugin_version()
    paths = shipped_files()
    problems = audit(paths)
    if problems:
        for problem in problems:
            print(f'  {problem}')
        raise SystemExit('Refusing to build: the files above look like they contain a secret.')

    destination.mkdir(parents=True, exist_ok=True)
    archive = destination / f'{SLUG}.{version}.zip'
    if archive.exists():
        archive.unlink()

    copies = minify(paths)
    with zipfile.ZipFile(archive, 'w', zipfile.ZIP_DEFLATED) as zip_file:
        for rel in paths:
            zip_file.write(PROJECT_DIR / rel, f'{SLUG}/{rel.as_posix()}')
        for rel, body in sorted(copies.items()):
            zip_file.writestr(f'{SLUG}/{rel.as_posix()}', body)

    digest = hashlib.sha256(archive.read_bytes()).hexdigest()
    print(f'version={version}')
    print(f'files={len(paths) + len(copies)}')
    print(f'minified={len(copies)}')
    print(f'bytes={archive.stat().st_size}')
    print(f'sha256={digest}')
    print(f'archive={archive}')
    return archive


def verify(archive):
    shipped = shipped_files()
    expected = {f'{SLUG}/{rel.as_posix()}' for rel in shipped}
    # Minified copies are built into the package, all of them or, without esbuild, none.
    copies = {f'{SLUG}/{minified_name(rel).as_posix()}' for rel in shipped if minifiable(rel)}
    with zipfile.ZipFile(archive) as zip_file:
        actual = {name for name in zip_file.namelist() if not name.endswith('/')}

    present = actual & copies
    if present and present != copies:
        for name in sorted(copies - present):
            print(f'  minified copy missing from the archive: {name}')
        raise SystemExit(f'{archive} has some minified copies but not all.')
    missing = sorted(expected - actual)
    extra = sorted(actual - expected - copies)
    for name in missing:
        print(f'  missing from the archive: {name}')
    for name in extra:
        print(f'  should not be in the archive: {name}')

    if missing or extra:
        raise SystemExit(f'{archive} does not match the rules in .distignore.')
    print(f'{archive} matches .distignore ({len(actual)} files).')


def main():
    parser = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    parser.add_argument('--list', action='store_true', help='print the files that would ship')
    parser.add_argument('--verify', metavar='FILE', help='check an existing ZIP')
    parser.add_argument('--dest', default=str(PROJECT_DIR / 'dist'), help='output directory')
    args = parser.parse_args()

    if args.list:
        paths = shipped_files()
        for rel in paths:
            print(rel.as_posix())

        problems = audit(paths)
        for problem in problems:
            print(problem, file=sys.stderr)
        print(f'--- {len(paths)} files', file=sys.stderr)
        if problems:
            return 1
        if not paths:
            print('no files resolved, which cannot be right', file=sys.stderr)
            return 1
        return 0

    if args.verify:
        verify(Path(args.verify))
        return 0

    build(Path(args.dest))
    return 0


if __name__ == '__main__':
    sys.exit(main())
