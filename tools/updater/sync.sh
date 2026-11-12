#!/usr/bin/env bash
# Copy the updater's source file into every plugin, so every copy is identical
# (the Lint workflow's "Updater copies match" job fails when one differs).
#
#   bash tools/updater/sync.sh           copy it into every plugin
#   bash tools/updater/sync.sh --check   only list the copies that differ
set -euo pipefail
root="$(cd "$(dirname "$0")/../.." && pwd)"
src="${root}/tools/updater/class-optima-plugin-updater.php"
check=false
[ "${1:-}" = "--check" ] && check=true

status=0
for dir in "${root}"/*/; do
  slug="$(basename "${dir}")"
  [ -f "${dir}${slug}.php" ] || continue
  dest="${dir}includes/class-optima-plugin-updater.php"
  if cmp -s "${src}" "${dest}" 2>/dev/null; then
    continue
  fi
  if "${check}"; then
    echo "differs: ${slug}/includes/class-optima-plugin-updater.php"
    status=1
  else
    mkdir -p "${dir}includes"
    cp "${src}" "${dest}"
    echo "copied:  ${slug}/includes/class-optima-plugin-updater.php"
  fi
done
"${check}" && [ "${status}" -eq 0 ] && echo "Every plugin's updater copy matches tools/updater."
exit "${status}"
