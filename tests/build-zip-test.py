#!/usr/bin/env python3
"""Tests for bin/build-zip.py (needs only python3; touches nothing in the project, builds into a temp folder).

    python3 tests/build-zip-test.py
"""
import hashlib
import importlib.machinery
import importlib.util
import os
import re
import shutil
import subprocess
import sys
import tempfile
import unittest
import zipfile

HERE = os.path.dirname(os.path.abspath(__file__))
PROJECT = os.path.dirname(HERE)
SCRIPT = os.path.join(PROJECT, 'bin', 'build-zip.py')


def load_module(root=None):
    loader = importlib.machinery.SourceFileLoader('crdr_build_%d' % id(root), SCRIPT)
    spec = importlib.util.spec_from_loader(loader.name, loader)
    mod = importlib.util.module_from_spec(spec)
    loader.exec_module(mod)
    if root:
        mod.ROOT = root
    return mod


def tree_hash(root):
    """sha256 of every file under root except .git (the whole project, not only the allowlist)."""
    h = hashlib.sha256()
    for d, dirs, files in os.walk(root):
        dirs[:] = sorted(x for x in dirs if x != '.git')
        for f in sorted(files):
            p = os.path.join(d, f)
            h.update(os.path.relpath(p, root).encode())
            with open(p, 'rb') as fh:
                h.update(fh.read())
    return h.hexdigest()


def rewrite(src, dst, edit):
    """Copy a zip, letting edit(list of (ZipInfo, bytes)) change the entries."""
    with zipfile.ZipFile(src) as z:
        items = [(i, z.read(i)) for i in z.infolist()]
    items = edit(items)
    with zipfile.ZipFile(dst, 'w', zipfile.ZIP_DEFLATED) as z:
        for info, data in items:
            z.writestr(info, data)


class BuildZip(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.tmp = tempfile.mkdtemp(prefix='crdr-zip-test-')
        cls.mod = load_module()
        cls.before = tree_hash(PROJECT)
        cls.zip = os.path.join(cls.tmp, 'a.zip')
        cls.problems = cls.mod.build(cls.zip)

    @classmethod
    def tearDownClass(cls):
        shutil.rmtree(cls.tmp, ignore_errors=True)

    def names(self, path=None):
        with zipfile.ZipFile(path or self.zip) as z:
            return [i.filename for i in z.infolist()]

    def test_build_has_no_problems(self):
        self.assertEqual([], self.problems)
        self.assertEqual([], self.mod.verify(self.zip, against_tree=True))

    def test_release_candidate_version_is_consistent_everywhere(self):
        main = open(os.path.join(PROJECT, 'customer-radar.php'), encoding='utf-8').read()
        self.assertEqual('1.0.2', self.mod.header_version(main))
        self.assertIn("define( 'CRDR_VERSION', '1.0.2' );", main)
        readme = open(os.path.join(PROJECT, 'readme.txt'), encoding='utf-8').read()
        self.assertRegex(readme, r'(?m)^Stable tag: 1\.0\.2$')
        self.assertRegex(readme, r'(?m)^= 1\.0\.2 =$')

    def test_stray_root_files_such_as_the_untracked_pdf_are_never_inside(self):
        stray = [f for f in os.listdir(PROJECT) if f.lower().endswith('.pdf')]
        names = self.names()
        for f in stray:
            self.assertNotIn('customer-radar/' + f, names)
        self.assertFalse([n for n in names if n.lower().endswith('.pdf')])

    def test_placeholder_author_is_reported_not_hidden(self):
        w = self.mod.placeholder_warnings({'customer-radar.php': b'Author: YOUR_NAME', 'readme.txt': b'ok'})
        self.assertEqual(1, len(w))
        self.assertEqual([], self.mod.placeholder_warnings({'customer-radar.php': b'Author: Someone', 'readme.txt': b'Contributors: Someone'}))

    def test_every_required_include_is_inside(self):
        main = open(os.path.join(PROJECT, 'customer-radar.php'), encoding='utf-8').read()
        req = self.mod.required_includes(main)
        self.assertGreater(len(req), 20)
        names = self.names()
        for r in req:
            self.assertIn('customer-radar/' + r, names)
        for n in ('customer-radar/customer-radar.php', 'customer-radar/readme.txt', 'customer-radar/uninstall.php', 'customer-radar/assets/admin.js', 'customer-radar/assets/admin.css', 'customer-radar/assets/charts.js'):
            self.assertIn(n, names)

    def test_nothing_that_must_stay_home_is_inside(self):
        for n in self.names():
            rel = n.split('/', 1)[1]
            for bad in ('tests/', 'docs/', 'bin/', 'dist/', 'fixtures', '.git', '.env', 'wp-config', 'marketplace'):
                self.assertNotIn(bad, rel, n)
            self.assertFalse(rel.endswith(('.md', '.json', '.py', '.sh', '.zip', '.csv', '.sql', '.log')), n)
            self.assertTrue(n.startswith('customer-radar/'), n)

    def test_permissions_are_normalised(self):
        with zipfile.ZipFile(self.zip) as z:
            for i in z.infolist():
                self.assertEqual(0o644, (i.external_attr >> 16) & 0o7777, i.filename)
                self.assertEqual((2026, 1, 1, 0, 0, 0), i.date_time, i.filename)

    def test_building_does_not_change_any_source_file(self):
        out = os.path.join(self.tmp, 'again.zip')
        self.assertEqual([], self.mod.build(out))
        self.assertEqual(self.before, tree_hash(PROJECT))

    def test_build_is_reproducible(self):
        out = os.path.join(self.tmp, 'again2.zip')
        self.assertEqual([], self.mod.build(out))
        with open(out, 'rb') as a, open(self.zip, 'rb') as b:
            self.assertEqual(a.read(), b.read())

    def test_never_overwrites_without_force(self):
        out = os.path.join(self.tmp, 'precious.zip')
        with open(out, 'wb') as f:
            f.write(b'keep me')
        self.assertTrue(any('already exists' in p for p in self.mod.build(out)))
        with open(out, 'rb') as f:
            self.assertEqual(b'keep me', f.read())
        self.assertEqual([], self.mod.build(out, force=True))

    def test_failed_build_leaves_no_partial_archive(self):
        root = os.path.join(self.tmp, 'broken-tree')
        shutil.copytree(PROJECT, root, ignore=shutil.ignore_patterns('.git', 'dist', 'tests'))
        os.unlink(os.path.join(root, 'includes', 'class-crdr-send.php'))
        mod = load_module(root)
        out = os.path.join(self.tmp, 'never.zip')
        self.assertTrue(any('missing' in p for p in mod.build(out)))
        self.assertFalse(os.path.exists(out))
        self.assertEqual([], [f for f in os.listdir(self.tmp) if f.startswith('.crdr-build-')])

    def test_tree_problems_are_refused(self):
        def tree(edit):
            root = tempfile.mkdtemp(dir=self.tmp)
            shutil.copytree(PROJECT, root, dirs_exist_ok=True, ignore=shutil.ignore_patterns('.git', 'dist', 'tests'))
            edit(root)
            return load_module(root), root

        def stray(root):
            with open(os.path.join(root, 'includes', 'class-crdr-scratch.php'), 'w') as f:
                f.write('<?php\n')
        mod, _ = tree(stray)
        self.assertTrue(any('stray' in p for p in mod.build(os.path.join(self.tmp, 's1.zip'))))

        def version(root):
            p = os.path.join(root, 'readme.txt')
            with open(p, encoding='utf-8') as f:
                t = re.sub(r'(?m)^Stable tag:.*$', 'Stable tag: 9.9.9', f.read())
            with open(p, 'w', encoding='utf-8') as f:
                f.write(t)
        mod, _ = tree(version)
        self.assertTrue(any('Stable tag' in p for p in mod.build(os.path.join(self.tmp, 's2.zip'))))

        def changelog(root):
            p = os.path.join(root, 'readme.txt')
            with open(p, encoding='utf-8') as f:
                t = f.read().replace('= 1.0.2 =', '= 1.0.0 =', 1)
            with open(p, 'w', encoding='utf-8') as f:
                f.write(t)
        mod, _ = tree(changelog)
        self.assertTrue(any('changelog entry' in p for p in mod.build(os.path.join(self.tmp, 's2b.zip'))))

        def cache(root):
            p = os.path.join(root, 'includes', 'class-crdr-admin.php')
            with open(p, encoding='utf-8') as f:
                t = f.read().replace("CRDR_URL . 'assets/admin.css', array(), CRDR_VERSION", "CRDR_URL . 'assets/admin.css', array(), '1.0'", 1)
            with open(p, 'w', encoding='utf-8') as f:
                f.write(t)
        mod, _ = tree(cache)
        self.assertTrue(any('CRDR_VERSION' in p for p in mod.build(os.path.join(self.tmp, 's2c.zip'))))

        def bom(root):
            p = os.path.join(root, 'includes', 'class-crdr-ui.php')
            with open(p, 'rb') as f:
                data = f.read()
            with open(p, 'wb') as f:
                f.write(b'\xef\xbb\xbf' + data)
        mod, _ = tree(bom)
        self.assertTrue(any('<?php' in p for p in mod.build(os.path.join(self.tmp, 's3.zip'))))

    def test_verify_catches_bad_archives(self):
        def check(edit, needle, against_tree=False):
            bad = os.path.join(self.tmp, 'bad-%s.zip' % abs(hash(needle)))
            rewrite(self.zip, bad, edit)
            problems = self.mod.verify(bad, against_tree=against_tree)
            self.assertTrue(any(needle in p for p in problems), (needle, problems))

        def drop(items):
            return [x for x in items if not x[0].filename.endswith('class-crdr-send.php')]
        check(drop, 'required file is missing')

        def add_tests(items):
            info = zipfile.ZipInfo('customer-radar/tests/phase1-safety.php')
            info.external_attr = 0o100644 << 16
            return items + [(info, b'<?php\n')]
        check(add_tests, 'must not be shipped')
        check(add_tests, 'not on the allowlist')

        def world_writable(items):
            items[0][0].external_attr = 0o100777 << 16
            return items
        check(world_writable, 'instead of 644')

        def other_folder(items):
            info = zipfile.ZipInfo('other/evil.php')
            info.external_attr = 0o100644 << 16
            return items + [(info, b'<?php\n')]
        check(other_folder, 'outside the customer-radar/ folder')

        def secret(items):
            info = zipfile.ZipInfo('customer-radar/.env')
            info.external_attr = 0o100644 << 16
            return items + [(info, b'KEY=1\n')]
        check(secret, 'must not be shipped')

        def stale(items):
            out = []
            for info, data in items:
                if info.filename.endswith('class-crdr-sync.php'):
                    data = data + b'\n// old\n'
                out.append((info, data))
            return out
        check(stale, 'stale: includes/class-crdr-sync.php differs', against_tree=True)

    def test_command_line(self):
        out = os.path.join(self.tmp, 'cli.zip')
        r = subprocess.run([sys.executable, SCRIPT, '--out', out], capture_output=True, text=True)
        self.assertEqual(0, r.returncode, r.stderr)
        r = subprocess.run([sys.executable, SCRIPT, '--verify', out], capture_output=True, text=True)
        self.assertEqual(0, r.returncode, r.stderr)
        r = subprocess.run([sys.executable, SCRIPT, '--out', out], capture_output=True, text=True)
        self.assertEqual(1, r.returncode)


if __name__ == '__main__':
    unittest.main(verbosity=2)
