#!/usr/bin/env python3
"""Keep the Release workflow's "Run workflow" plugin list equal to the plugin folders.

GitHub cannot build a choice list at run time, so the list is written into
.github/workflows/release.yml. This rewrites it from the folders (every
optima-*/ folder with a main file of the same name), sorted, Optima Core first.

  python3 tools/release/dispatch-list.py           rewrite the list
  python3 tools/release/dispatch-list.py --check   fail when it differs (CI)
"""
import os
import re
import sys

ROOT = os.path.dirname(os.path.dirname(os.path.dirname(os.path.abspath(__file__))))
WORKFLOW = os.path.join(ROOT, '.github', 'workflows', 'release.yml')

# The options block under the workflow_dispatch "plugin" input.
BLOCK = re.compile(r'(?P<head>\n {6}plugin:\n(?: {8}[a-z#].*\n)*? {8}options:\n)(?P<items>(?: {10}- .*\n)+)')


def plugins():
    found = [
        d for d in os.listdir(ROOT)
        if d.startswith('optima-') and os.path.isfile(os.path.join(ROOT, d, d + '.php'))
    ]
    return sorted(found, key=lambda s: (s != 'optima-core', s))


def main():
    text = open(WORKFLOW, encoding='utf-8').read()
    m = BLOCK.search(text)
    if not m:
        print('::error file=.github/workflows/release.yml::Could not find the plugin options list under workflow_dispatch.')
        return 1
    want = ''.join(f'          - {slug}\n' for slug in plugins())
    have = m.group('items')
    if '--check' in sys.argv[1:]:
        if have != want:
            listed = re.findall(r'- (\S+)', have)
            missing = sorted(set(plugins()) - set(listed))
            extra = sorted(set(listed) - set(plugins()))
            print('::error file=.github/workflows/release.yml::The "Run workflow" plugin list differs from the plugin folders'
                  f' (missing: {", ".join(missing) or "none"}; no folder: {", ".join(extra) or "none"}; or the order).'
                  ' Run: python3 tools/release/dispatch-list.py')
            return 1
        print(f'The "Run workflow" list names all {len(plugins())} plugins.')
        return 0
    if have != want:
        text = text[:m.start('items')] + want + text[m.end('items'):]
        open(WORKFLOW, 'w', encoding='utf-8').write(text)
        print(f'Rewrote the list: {len(plugins())} plugins.')
    else:
        print('Already up to date.')
    return 0


if __name__ == '__main__':
    sys.exit(main())
