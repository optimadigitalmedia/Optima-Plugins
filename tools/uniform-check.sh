#!/usr/bin/env bash
# Plugin-first uniform check (docs/plugin-first.md, rules 6-15).
#
#   tools/uniform-check.sh theme <path-to-theme-folder>   checks one theme
#   tools/uniform-check.sh plugins [repo-root]           checks every plugin here
#                                                        (and every screen's menu group)
#   tools/uniform-check.sh screens [repo-root]           only the screen groups
#
# Exits 1 on any failure. Run it before every theme or plugin release.
set -u
fail=0
bad() { echo "FAIL  $1"; fail=1; }
ok()  { echo "ok    $1"; }

check_theme() {
  local t="${1%/}"
  [ -d "$t" ] || { echo "No theme folder at $t"; exit 2; }
  echo "Theme: $t"
  if [ -f "$t/DESIGN.md" ]; then
    if grep -q '^```json[[:space:]]\+optima-design' "$t/DESIGN.md"; then ok "DESIGN.md has an optima-design block"; else bad "DESIGN.md has no optima-design block"; fi
  else bad "missing DESIGN.md"; fi
  if [ -f "$t/optima-setup.json" ]; then
    if python3 -c "import json,sys; json.load(open(sys.argv[1]))" "$t/optima-setup.json" 2>/dev/null; then ok "optima-setup.json is valid JSON"; else bad "optima-setup.json is not valid JSON"; fi
  else bad "missing optima-setup.json"; fi
  [ -d "$t/inc/fallbacks" ] && ok "inc/fallbacks/ exists" || bad "missing inc/fallbacks/"
  [ -f "$t/inc/slots.php" ] && ok "inc/slots.php exists" || bad "missing inc/slots.php"
  if [ -d "$t/inc/fallbacks" ]; then
    for f in "$t"/inc/fallbacks/*.php; do
      [ -e "$f" ] || continue
      grep -q "optima_owns(" "$f" && ok "$(basename "$f") checks optima_owns()" || bad "$(basename "$f") does not check optima_owns()"
    done
  fi
  local menus
  # A menu only for sites without Optima Build (guarded by Optima_Hub_Admin) is a fallback, not a second menu.
  menus=$(grep -rlE "add_menu_page\(" "$t" --include=*.php 2>/dev/null | xargs -r grep -LE "class_exists\(\s*'Optima_Hub_Admin'\s*\)" 2>/dev/null || true)
  [ -z "$menus" ] && ok "no theme admin menus" || bad "theme registers its own admin menu: $menus"
  local fonts
  fonts=$(grep -rlE "fonts\.googleapis|fonts\.gstatic" "$t" --include=*.php 2>/dev/null || true)
  [ -z "$fonts" ] && ok "no remote font loading" || echo "note  remote fonts loaded in: $fonts (self-hosted fonts preferred)"
}

check_plugins() {
  local root="${1:-.}"
  echo "Plugins in: $root"
  for dir in "$root"/optima-*/; do
    local slug; slug=$(basename "$dir")
    [ -f "$dir/$slug.php" ] || { bad "$slug: missing $slug.php"; continue; }
    grep -q "^ \* Version:" "$dir/$slug.php" && ok "$slug: version header" || bad "$slug: no Version header"
    [ -f "$dir/CHANGELOG.md" ] && ok "$slug: CHANGELOG.md" || bad "$slug: missing CHANGELOG.md"
    local hex
    # Visitor-facing CSS must dress from DESIGN.md. Dashboard CSS (optima-admin.css,
    # *-admin.css) is the shared Optima admin kit and is checked separately.
    hex=$(find "$dir" -name '*.css' ! -name 'optima-admin.css' ! -name '*-admin.css' -print0 2>/dev/null | xargs -0 -r grep -oE "#[0-9a-fA-F]{3}([0-9a-fA-F]{3})?\b" 2>/dev/null | wc -l)
    if [ "$hex" -eq 0 ]; then ok "$slug: visitor CSS uses only --optima-* variables"; else bad "$slug: $hex hard-coded colors in visitor CSS (use --optima-* variables)"; fi
    local fl
    # Allowed: pages that render without the theme (rule 8 exception) load the DESIGN.md fonts themselves.
    fl=$(grep -rlE "fonts\.googleapis|fonts\.gstatic" "$dir" --include=*.php 2>/dev/null | grep -v -E "optima-reviews/includes/class-optima-reviews-brand\.php" || true)
    [ -z "$fl" ] && ok "$slug: loads no fonts" || bad "$slug: loads fonts in $fl"
  done
}

# Every Optima admin screen sits in one of the seven groups of the Optima menu
# (plugin-first rules 9 and 44; dashboard release 1). Reads each add_submenu_page()
# address from the plugins and checks it against Optima_Admin_UI::SCREENS in the
# shared admin kit. Screens in a plugin's own top-level menu (Events, Members,
# Partners) are not in the Optima menu and are skipped.
check_screens() {
  local root="${1:-.}"
  local kit="$root/tools/admin-kit/class-optima-admin-ui.php"
  [ -f "$kit" ] || { bad "screen groups: no admin kit at $kit"; return; }
  # Admin kit 1.7 (upstream, Nov 4 2026) files screens by its own slug lists and
  # carries the grouping test; the SCREENS map below is for older kits.
  if ! grep -q "const SCREENS" "$kit"; then
    if php "$root/tools/tests/admin-kit-groups.php" >/dev/null 2>&1; then ok "screen groups: tools/tests/admin-kit-groups.php passes"; else bad "screen groups: tools/tests/admin-kit-groups.php fails (run it for the list)"; fi
    return
  fi
  local map
  map=$(php -r 'define("ABSPATH", "/"); function add_action() {} function add_filter() {} require $argv[1]; echo json_encode( array( "groups" => Optima_Admin_UI::GROUPS, "screens" => Optima_Admin_UI::SCREENS ) );' "$kit") || { bad "screen groups: could not read the admin kit"; return; }
  local out
  out=$(python3 - "$root" "$map" <<'PY'
import json, os, re, sys

root, data = sys.argv[1], json.loads(sys.argv[2])
groups, screens = data["groups"], data["screens"]
exact = {s: g for g in groups for s in screens.get(g, [])}
patterns = [(p[:-1], g) for g, p in screens.get("_more", {}).items()]

files = {}
for dirpath, _, names in os.walk(root):
    if "/tools" in dirpath.replace(root, "", 1) or "/.git" in dirpath:
        continue
    for n in names:
        if n.endswith(".php") and n != "class-optima-admin-ui.php" and os.path.basename(os.path.relpath(dirpath, root).split(os.sep)[0]).startswith("optima-"):
            path = os.path.join(dirpath, n)
            files[path] = open(path, encoding="utf-8", errors="replace").read()

def split_top(text, sep):
    parts, depth, quote, cur, i = [], 0, None, "", 0
    while i < len(text):
        c = text[i]
        if quote:
            cur += c
            if c == "\\":
                cur += text[i + 1]; i += 1
            elif c == quote:
                quote = None
        elif c in "'\"":
            quote = c; cur += c
        elif c in "([{":
            depth += 1; cur += c
        elif c in ")]}":
            depth -= 1; cur += c
        elif depth == 0 and text.startswith(sep, i):
            parts.append(cur); cur = ""; i += len(sep); continue
        else:
            cur += c
        i += 1
    parts.append(cur)
    return [p.strip() for p in parts]

def call_args(src, start):
    depth, i = 0, start
    while i < len(src):
        if src[i] == "(":
            depth += 1
        elif src[i] == ")":
            depth -= 1
            if depth == 0:
                return split_top(src[start + 1:i], ",")
        elif src[i] in "'\"":
            q = src[i]; i += 1
            while src[i] != q:
                i += 2 if src[i] == "\\" else 1
        i += 1
    return []

def class_file(name):
    for path, src in files.items():
        if re.search(r"\bclass\s+" + re.escape(name) + r"\b", src):
            return path
    return None

def const(path, name):
    m = re.search(r"\bconst\s+" + re.escape(name) + r"\s*=\s*([^;]+);", files.get(path, ""))
    return resolve(m.group(1), path, len(files[path])) if m else ["*"]

def method(path, name):
    m = re.search(r"function\s+" + re.escape(name) + r"\s*\([^)]*\)[^{]*\{\s*return\s+([^;]+);", files.get(path, ""))
    return resolve(m.group(1), path, m.start()) if m else ["*"]

def resolve(expr, path, pos, depth=0):
    expr = re.sub(r"^\((string)\)\s*", "", expr.strip())
    if depth > 6:
        return ["*"]
    tern = split_top(expr, "?")
    if len(tern) == 2:
        sides = split_top(tern[1].replace("::", "\x00"), ":")
        return [r for side in sides for r in resolve(side.replace("\x00", "::"), path, pos, depth + 1)]
    out = [""]
    for part in split_top(expr, "."):
        if re.fullmatch(r"'(?:[^'\\]|\\.)*'|\"[^\"]*\"", part):
            vals = [part[1:-1]]
        elif re.fullmatch(r"(self|static)::[A-Z_]+", part):
            vals = const(path, part.split("::")[1])
        elif re.fullmatch(r"[A-Za-z_]+::[A-Z_]+", part):
            cls, name = part.split("::")
            f = class_file(cls)
            vals = const(f, name) if f else ["*"]
        elif re.fullmatch(r"(self|static)::\w+\(\)", part):
            vals = method(path, part.split("::")[1][:-2])
        elif re.fullmatch(r"[A-Za-z_]+::\w+\(\)", part):
            cls, name = part.split("::")
            f = class_file(cls)
            vals = method(f, name[:-2]) if f else ["*"]
        elif re.fullmatch(r"\$\w+", part):
            found = None
            for m in re.finditer(re.escape(part) + r"\s*=\s*([^;]+);", files[path][:pos]):
                found = m
            vals = resolve(found.group(1), path, found.start(), depth + 1) if found else ["*"]
        else:
            vals = ["*"]
        out = [o + v for o in out for v in vals]
    return out

def group(slug):
    if slug in exact:
        return exact[slug]
    for prefix, g in patterns:
        if slug.startswith(prefix):
            return g
    return ""

seen = {}
for path, src in sorted(files.items()):
    for m in re.finditer(r"\badd_submenu_page\s*\(", src):
        args = call_args(src, m.end() - 1)
        if len(args) < 5 or "SLUG" in args[0]:
            continue
        for slug in resolve(args[4], path, m.start()):
            seen.setdefault(slug, os.path.relpath(path, root))

fail = 0
for slug, where in sorted(seen.items()):
    g = group(slug)
    if g:
        print("ok    screen %s -> %s" % (slug, groups[g]))
    else:
        print("FAIL  screen %s (%s) is in none of the seven groups: add it to Optima_Admin_UI::SCREENS" % (slug, where))
        fail = 1
sys.exit(fail)
PY
)
  local rc=$?
  echo "$out"
  [ "$rc" -eq 0 ] || fail=1
}

case "${1:-}" in
  theme)   check_theme "${2:?theme folder}" ;;
  plugins) check_plugins "${2:-.}"; check_screens "${2:-.}" ;;
  screens) check_screens "${2:-.}" ;;
  *) echo "Usage: $0 theme <folder> | plugins [repo-root] | screens [repo-root]"; exit 2 ;;
esac
[ "$fail" -eq 0 ] && echo "PASS" || echo "FAILED"
exit "$fail"
