#!/usr/bin/env python3
"""Fail when a client or partner name lands in this public repo.

Optima Plugins holds platform code and docs only, no client or partner names
(docs/plugin-first.md rule 36: site facts live in the agency's private fleet
list, never here). This check reads the lines a change adds (and the paths of
the files it adds or renames) and fails when one carries a name on the deny
list.

The deny list below holds SHA-256 hashes, never the names, so publishing this
file does not publish the list. A match is reported by file, line and entry id
only; the matching text is never printed, so CI logs stay clean too.

  python3 tools/client-names.py --base origin/main     lines added since main
  python3 tools/client-names.py --all                  every tracked file, every line
  python3 tools/client-names.py --which "some text"    entry ids the text matches (local use)
  python3 tools/client-names.py --hash "Some Name"     the line to add for a new name

How text is matched: lowercased, accents and apostrophes dropped, split into
words at anything that is not a letter or digit and at camelCase humps, then
every run of one to five words is joined without spaces and hashed. So
"Some Firm", "somefirm" and "SomeFirm" all give the same hash, and a deny
entry is always written as one joined lowercase word.

Intentional mentions (a plugin built for a kind of business may need one) go in
.github/client-names-allow.txt: one path glob per line, optionally followed by
the entry ids it allows (all ids when none are given), with a dated reason in a
comment above it.
"""
import argparse
import fnmatch
import hashlib
import os
import re
import subprocess
import sys
import unicodedata

SALT = 'optima-plugins/client-names/v1:'
MAX_WORDS = 5
ALLOW_FILE = '.github/client-names-allow.txt'

# id -> sha256(SALT + joined lowercase name). Add a line with --hash. Keep the
# ids stable: allow entries refer to them.
DENY = {
    'c01': 'e00f53570d8ce872c6bf8091f3358f7f8bd323d527faff99ce774b7d2055a7f5',
    'c02': 'a32910abe4aa3e1392f20d6ebfc794132597466bda790d2d7ce6b63e431552ff',
    'c03': 'f4b56c94516a4987dce081a578735b1677446c5864690b0f6879bed85cbbf6ee',
    'c04': '30ab7843a5c6543a1a70a15a4c7151623f64f4968ff0de2384a4a09dd709f47f',
    'c05': '6837761627a50eceb561162e41fed112eeecbdab9875916c64d7bd41ae4e8dbf',
    'c06': '572ef55f83a098122ebbc41c3ef5ae576fbd5a6f1ddf474425e8da89251e1152',
    'c07': '368d639fe169ec638970737130505b51dbf3091e7a9f2fee0a7571b653aad32d',
    'c08': 'dde44a07f2e548e6b3e92f3faf2b7b2072b910c09f5a7da9b4c0d36e86b9c9bd',
    'c09': '9616f4cf03d83c0f6f5a99ce5247c48f509e1ca9f39c1985cd80b4a060b829f2',
    'c10': 'b47d51df453f7d76fbb92ebecd52d0823ca5ea11a6a6ed5a34660dc7fe990979',
    'c11': '5a0e197b8bff06d4f9830a58f78830457d13c22e33e9f278fd1ffccec01329db',
    'c12': 'a54571793738c7f7a124a10ec7f51a2e52540c0b3dc2f6921bc8f63a65548999',
    'c13': 'a89d4c77cc192f24681bcf5d7bf6ea341fb0f89d3d4aca1542706c73c0bf1a1a',
    'c14': 'ae6e38a39611f201bb9368b953a6583dc43d116f520c5e56768774e3be35db2f',
    'c15': '066bbbe59151df77dac8d7991e8c23691717c3c06ec4ca19b9926a9b89e4754e',
    'c16': '1ff2f1289acae425d38f81b49c7284ef741b0cbbf7750f890d93eb4c69558648',
    'c17': '0af56c9ae8e3058a9b54d6810ae0d0ca5ca529979ef1fbb95a36e3dee51a0ff1',
    'c18': 'e82b8fa69ef0e09f0a82202cec0b53f09a301ae6eff693b8bcff1f6ece4b6cc7',
    'c19': 'c691671d25cb607b932c7a77b6aef0e9776815558a6434a606cc682ff022bb44',
    'c20': 'ff139fa66631d36c68232079fdafe1f729df5da89be2164f349e4d11ff35c4c0',
    'c21': '2447f0883925ff14d01ca3709988e7ada2ee44b0b8c7a5100e17b5725263e366',
    'c22': 'bf553f3fd06b23e2a99e2210bef0913a25ad795a0a4347dc6188d5c23a75654a',
    'c23': '76e8fd154660ec827be846823fa8d374b2eca2f73b0b3403a4580ddb23e38f5a',
    'c24': 'd993cdcaad0413bc3fe3bb2815d82e328bc3b247266547ceb059fda52c19493d',
}

WORD = re.compile(r'[a-z0-9]+')
HUMP = re.compile(r'[A-Z]+(?=[A-Z][a-z])|[A-Z]?[a-z]+|[A-Z]+|[0-9]+')


def digest(joined):
    return hashlib.sha256((SALT + joined).encode('utf-8')).hexdigest()


def fold(text):
    text = unicodedata.normalize('NFKD', text)
    text = ''.join(c for c in text if not unicodedata.combining(c))
    return re.sub("['’‘`]", '', text)


def words(text):
    """Lowercase words, split at non-alphanumerics and at camelCase humps."""
    out = []
    for raw in re.findall(r'[A-Za-z0-9]+', fold(text)):
        parts = HUMP.findall(raw)
        out.extend(p.lower() for p in (parts or [raw]))
        if len(parts) > 1:
            out.append(None)  # a boundary marker so the whole token is also tried
            out.append(raw.lower())
            out.append(None)
    return out


def matches(text, lookup):
    """Entry ids whose name appears in the text."""
    found = set()
    seq = words(text)
    for i in range(len(seq)):
        if seq[i] is None:
            continue
        joined = ''
        for n in range(MAX_WORDS):
            if i + n >= len(seq) or seq[i + n] is None:
                break
            joined += seq[i + n]
            hit = lookup.get(digest(joined))
            if hit:
                found.add(hit)
    return found


def load_allow(root):
    rules = []
    path = os.path.join(root, ALLOW_FILE)
    if not os.path.isfile(path):
        return rules
    for line in open(path, encoding='utf-8'):
        line = line.split('#', 1)[0].strip()
        if not line:
            continue
        parts = line.split()
        rules.append((parts[0], set(parts[1:])))
    return rules


def allowed(path, entry, rules):
    for glob, ids in rules:
        if fnmatch.fnmatch(path, glob) and (not ids or entry in ids):
            return True
    return False


def git(root, *args):
    return subprocess.run(['git', '-C', root, *args], check=True, capture_output=True, text=True).stdout


def added_lines(root, base):
    """(path, line number, text) for every line added since base, plus new paths."""
    out = []
    for path in git(root, 'diff', '--name-only', '--diff-filter=AR', base + '...HEAD').splitlines():
        out.append((path, 0, path))
    path, num = None, 0
    diff = git(root, 'diff', '-U0', '--no-color', '--no-ext-diff', base + '...HEAD')
    for line in diff.splitlines():
        if line.startswith('+++ '):
            path = line[6:] if line.startswith('+++ b/') else None
        elif line.startswith('@@'):
            m = re.search(r'\+(\d+)', line)
            num = int(m.group(1)) if m else 0
        elif path and line.startswith('+'):
            out.append((path, num, line[1:]))
            num += 1
    return out


def all_lines(root):
    out = []
    for path in git(root, 'ls-files').splitlines():
        out.append((path, 0, path))
        full = os.path.join(root, path)
        try:
            with open(full, encoding='utf-8') as fh:
                for num, line in enumerate(fh, 1):
                    out.append((path, num, line))
        except (UnicodeDecodeError, IsADirectoryError, FileNotFoundError):
            continue
    return out


def main():
    ap = argparse.ArgumentParser(description=__doc__.split('\n')[0])
    ap.add_argument('--base', help='compare against this ref (lines added since it)')
    ap.add_argument('--all', action='store_true', help='scan every tracked file')
    ap.add_argument('--which', help='print the entry ids this text matches')
    ap.add_argument('--hash', help='print a deny-list line for this name')
    args = ap.parse_args()
    root = git(os.getcwd(), 'rev-parse', '--show-toplevel').strip()
    lookup = {h: i for i, h in DENY.items()}

    if args.hash:
        joined = ''.join(WORD.findall(fold(args.hash).lower()))
        print(f"    'cNN': '{digest(joined)}',")
        return 0
    if args.which is not None:
        print(' '.join(sorted(matches(args.which, lookup))) or 'no match')
        return 0
    if not args.all and not args.base:
        ap.error('give --base <ref> or --all')

    rows = all_lines(root) if args.all else added_lines(root, args.base)
    rules = load_allow(root)
    bad = 0
    skipped = 0
    for path, num, text in rows:
        if path == 'tools/client-names.py':
            continue
        for entry in sorted(matches(text, lookup)):
            if allowed(path, entry, rules):
                skipped += 1
                continue
            where = f'file={path},line={num}' if num else f'file={path}'
            what = f'line {num}' if num else 'the file name'
            print(f'::error {where}::{path} {what} carries a client or partner name (deny-list entry {entry}). '
                  f'This repo is public: describe the client by type instead, or, for an '
                  f'intentional mention, add the path to {ALLOW_FILE} with a dated reason.')
            bad += 1
    scope = 'every tracked file' if args.all else f'lines added since {args.base}'
    print(f'Checked {len(rows)} lines ({scope}) against {len(DENY)} names: {bad} found, {skipped} allowed.')
    return 1 if bad else 0


if __name__ == '__main__':
    sys.exit(main())
