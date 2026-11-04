#!/usr/bin/env bash
# Plugin-first uniform check (docs/plugin-first.md, rules 6-15).
#
#   tools/uniform-check.sh theme <path-to-theme-folder>   checks one theme
#   tools/uniform-check.sh plugins [repo-root]           checks every plugin here
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
  menus=$(grep -rlE "add_menu_page\(" "$t" --include=*.php 2>/dev/null || true)
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

case "${1:-}" in
  theme)   check_theme "${2:?theme folder}" ;;
  plugins) check_plugins "${2:-.}" ;;
  *) echo "Usage: $0 theme <folder> | plugins [repo-root]"; exit 2 ;;
esac
[ "$fail" -eq 0 ] && echo "PASS" || echo "FAILED"
exit "$fail"
