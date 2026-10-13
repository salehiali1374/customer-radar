#!/usr/bin/env python3
"""Build (and verify) the Customer Radar release ZIP from an ALLOWLIST.

    bin/build-zip.py --out /path/customer-radar-<version>.zip     build
    bin/build-zip.py --verify /path/customer-radar-<version>.zip  check an existing archive (build also verifies its own output)

Only the files named below can ever enter the archive: anything else in the working tree (tests, fixtures, docs, .git, dist,
local configuration, editor files, the marketplace listing draft) is simply never looked at. Source files are only READ.

The archive is reproducible: entries are sorted, permissions are fixed (files 0644), timestamps are fixed
(SOURCE_DATE_EPOCH, default below), so building twice from the same tree gives the same bytes.

An existing output file is never overwritten unless --force is given.
Exit status: 0 = built / verified, 1 = a problem (listed on stderr), 2 = bad usage.
"""
import argparse
import glob
import os
import re
import sys
import tempfile
import time
import zipfile

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
SLUG = 'customer-radar'
MAIN = 'customer-radar.php'
ROOT_FILES = ['customer-radar.php', 'readme.txt', 'uninstall.php']
ASSET_GLOBS = ['assets/*.css', 'assets/*.js']
INCLUDE_GLOB = 'includes/class-crdr-*.php'
DEFAULT_EPOCH = 1767225600  # 2026-01-01T00:00:00Z

# Whatever happens to the allowlist, none of this may ever be inside the archive.
FORBIDDEN_PARTS = ('tests/', 'docs/', 'bin/', 'dist/', '.git', 'node_modules/', 'vendor/', 'fixtures/')
FORBIDDEN_NAMES = re.compile(r'(^|/)(\.env[^/]*|wp-config[^/]*|.*\.(sql|csv|log|bak|zip|pem|key|md|json|py|sh|orig|swp))$', re.I)


def read(path):
    with open(path, 'rb') as f:
        return f.read()


def header_version(main_src):
    m = re.search(r'^\s*\*\s*Version:\s*(\S+)', main_src, re.M)
    return m.group(1) if m else None


def required_includes(main_src):
    """The names listed in the foreach() of customer-radar.php, as 'includes/class-crdr-<name>.php'."""
    m = re.search(r'foreach \( array\((.*?)\) as \$crdr_file', main_src, re.S)
    if not m:
        return None
    return ['includes/class-crdr-%s.php' % n for n in re.findall(r"'([a-z0-9\-]+)'", m.group(1))]


def consistency_problems(files):
    """files: name -> bytes (the content that goes into / is in the archive, names relative to the plugin folder)."""
    out = []
    if MAIN not in files:
        return ['%s is missing' % MAIN]
    main = files[MAIN].decode('utf-8', 'replace')
    ver = header_version(main)
    const = re.search(r"define\(\s*'CRDR_VERSION',\s*'([^']+)'", main)
    stable = re.search(r'^Stable tag:\s*(\S+)', files.get('readme.txt', b'').decode('utf-8', 'replace'), re.M)
    if not ver:
        out.append('no "Version:" in the plugin header')
    if not const or const.group(1) != ver:
        out.append('CRDR_VERSION (%s) differs from the header version (%s)' % (const.group(1) if const else None, ver))
    if not stable or stable.group(1) != ver:
        out.append('readme.txt "Stable tag" (%s) differs from the header version (%s)' % (stable.group(1) if stable else None, ver))
    readme = files.get('readme.txt', b'').decode('utf-8', 'replace')
    if ver and not re.search(r'(?m)^= %s =\s*$' % re.escape(ver), readme):
        out.append('readme.txt has no changelog entry "= %s ="' % ver)
    admin = files.get('includes/class-crdr-admin.php', b'').decode('utf-8', 'replace')
    for m in re.finditer(r'wp_enqueue_(?:style|script)\(([^;]*)\);', admin):
        if 'CRDR_URL' in m.group(1) and 'CRDR_VERSION' not in m.group(1):
            out.append('an asset is enqueued without CRDR_VERSION (browsers would keep serving the old file after an update): %s' % m.group(1)[:60])
    req = required_includes(main)
    if req is None:
        out.append('cannot read the include list of %s' % MAIN)
    else:
        for r in req:
            if r not in files:
                out.append('required file is missing: %s' % r)
        extra = sorted(n for n in files if n.startswith('includes/') and n not in req)
        for n in extra:
            out.append('included but never required (stray file?): %s' % n)
    for name, data in files.items():
        if name.endswith('.php') and not data.startswith(b'<?php'):
            out.append('%s does not start with "<?php" (BOM or stray output would break headers)' % name)
        if name.endswith('.php') and b'?>' in data[-20:]:
            out.append('%s ends with a closing PHP tag' % name)
    return out


def placeholder_warnings(files):
    """Things that are not errors for a test build but must be filled in before the archive is uploaded to a marketplace."""
    out = []
    for name in ('customer-radar.php', 'readme.txt'):
        text = files.get(name, b'').decode('utf-8', 'replace')
        if 'YOUR_NAME' in text:
            out.append('%s still contains the placeholder YOUR_NAME (author / contributor): set the real name before uploading' % name)
    return out


def collect():
    """The allowlist, resolved against the working tree: name -> absolute path."""
    found = {}
    for n in ROOT_FILES:
        found[n] = os.path.join(ROOT, n)
    for pattern in ASSET_GLOBS + [INCLUDE_GLOB]:
        for p in sorted(glob.glob(os.path.join(ROOT, pattern))):
            found[os.path.relpath(p, ROOT).replace(os.sep, '/')] = p
    return found


def build(out_path, force=False):
    found = collect()
    missing = [n for n, p in found.items() if not os.path.isfile(p)]
    if missing:
        return ['missing source file: ' + n for n in missing]
    files = {n: read(p) for n, p in found.items()}  # read once: what is checked is what is written
    problems = consistency_problems(files)
    if problems:
        return problems
    if os.path.exists(out_path) and not force:
        return ['%s already exists; choose another --out (nothing was overwritten) or pass --force' % out_path]
    epoch = int(os.environ.get('SOURCE_DATE_EPOCH', DEFAULT_EPOCH))
    t = time.gmtime(epoch)[:6]
    fd, tmp = tempfile.mkstemp(prefix='.crdr-build-', suffix='.zip', dir=os.path.dirname(os.path.abspath(out_path)) or '.')
    os.close(fd)
    try:
        with zipfile.ZipFile(tmp, 'w', zipfile.ZIP_DEFLATED, compresslevel=9) as z:
            for name in sorted(files):
                zi = zipfile.ZipInfo('%s/%s' % (SLUG, name), date_time=t)
                zi.external_attr = (0o100644 << 16)
                zi.create_system = 3
                zi.compress_type = zipfile.ZIP_DEFLATED
                z.writestr(zi, files[name], compresslevel=9)
        bad = verify(tmp)
        if bad:
            return bad
        os.replace(tmp, out_path)  # only a verified archive gets its final name
        tmp = None
    finally:
        if tmp and os.path.exists(tmp):
            os.unlink(tmp)
    return []


def verify(zip_path, against_tree=False):
    """Check an archive. against_tree: also require it to be exactly what the allowlist produces from the working tree now
    (this is what tells a stale archive from a current one: an old build is perfectly self-consistent)."""
    problems = []
    try:
        z = zipfile.ZipFile(zip_path)
    except Exception as e:  # noqa: BLE001
        return ['not a readable zip: %s' % e]
    with z:
        bad = z.testzip()
        if bad:
            problems.append('corrupt entry: %s' % bad)
        names = [i.filename for i in z.infolist()]
        if len(names) != len(set(names)):
            problems.append('duplicate entry names')
        files = {}
        for i in z.infolist():
            n = i.filename
            if i.is_dir():
                continue
            if n.startswith('/') or '..' in n.split('/'):
                problems.append('unsafe path: %s' % n)
                continue
            if not n.startswith(SLUG + '/'):
                problems.append('outside the %s/ folder: %s' % (SLUG, n))
                continue
            rel = n[len(SLUG) + 1:]
            if any(part in rel for part in FORBIDDEN_PARTS) or FORBIDDEN_NAMES.search(rel):
                problems.append('must not be shipped: %s' % rel)
            mode = (i.external_attr >> 16) & 0o7777
            if mode != 0o644:
                problems.append('permissions %s instead of 644: %s' % (oct(mode), rel))
            files[rel] = z.read(n)
        allowed = set(ROOT_FILES)
        for rel in files:
            ok = rel in allowed or re.match(r'^assets/[^/]+\.(css|js)$', rel) or re.match(r'^includes/class-crdr-[a-z0-9\-]+\.php$', rel)
            if not ok:
                problems.append('not on the allowlist: %s' % rel)
        problems += consistency_problems(files)
        if against_tree:
            for name, path in sorted(collect().items()):
                if name not in files:
                    problems.append('stale: %s is in the working tree but not in the archive' % name)
                elif files[name] != read(path):
                    problems.append('stale: %s differs from the working tree' % name)
            for name in sorted(set(files) - set(collect())):
                problems.append('stale: %s is in the archive but no longer in the working tree allowlist' % name)
    return problems


def main():
    ap = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    ap.add_argument('--out', help='archive to write (default: dist/%s-<version>.zip)' % SLUG)
    ap.add_argument('--force', action='store_true', help='overwrite --out if it exists')
    ap.add_argument('--verify', metavar='ZIP', help='only verify this archive (also against the working tree, unless --no-tree)')
    ap.add_argument('--no-tree', action='store_true', help='with --verify: do not compare with the working tree')
    a = ap.parse_args()
    if a.verify:
        problems = verify(a.verify, against_tree=not a.no_tree)
        print('\n'.join(['PROBLEM: ' + p for p in problems]) if problems else 'OK: %s passes every packaging check' % a.verify, file=sys.stderr if problems else sys.stdout)
        if not problems:
            with zipfile.ZipFile(a.verify) as z:
                for w in placeholder_warnings({i.filename[len(SLUG) + 1:]: z.read(i.filename) for i in z.infolist() if not i.is_dir() and i.filename.startswith(SLUG + '/')}):
                    print('WARNING: ' + w, file=sys.stderr)
        return 1 if problems else 0
    out = a.out
    if not out:
        ver = header_version(read(os.path.join(ROOT, MAIN)).decode('utf-8', 'replace')) or 'unknown'
        out = os.path.join(ROOT, 'dist', '%s-%s.zip' % (SLUG, ver))
        os.makedirs(os.path.dirname(out), exist_ok=True)
    problems = build(out, a.force)
    if problems:
        print('\n'.join('PROBLEM: ' + p for p in problems), file=sys.stderr)
        return 1
    print('built %s (%d bytes)' % (out, os.path.getsize(out)))
    for w in placeholder_warnings({n: read(p) for n, p in collect().items()}):
        print('WARNING: ' + w, file=sys.stderr)
    return 0


if __name__ == '__main__':
    sys.exit(main())
