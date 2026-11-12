#!/usr/bin/env bash
# Run every test in tools/tests that needs no WordPress install. The Lint
# workflow and the Release workflow's checks both run this, so a new test file
# is picked up without editing either workflow.
#
#   bash tools/tests/run-all.sh
#
# A PHP test that loads a real WordPress (it requires wp-load.php, like
# site-sync.php) is skipped here and run by hand against a throwaway site.
set -uo pipefail
here="$(cd "$(dirname "$0")" && pwd)"
self="$(basename "$0")"

failed=()
ran=0
for t in "${here}"/*.php "${here}"/*.sh; do
  [ -f "${t}" ] || continue
  name="$(basename "${t}")"
  [ "${name}" = "${self}" ] && continue
  if [ "${t##*.}" = "php" ] && grep -q "wp-load\.php" "${t}"; then
    echo "== ${name}: skipped (needs a WordPress install)"
    continue
  fi
  echo "== ${name}"
  ran=$((ran + 1))
  if [ "${t##*.}" = "php" ]; then
    php "${t}" || failed+=("${name}")
  else
    bash "${t}" || failed+=("${name}")
  fi
done

echo
if [ "${#failed[@]}" -gt 0 ]; then
  echo "::error::${#failed[@]} of ${ran} test files failed: ${failed[*]}"
  exit 1
fi
echo "All ${ran} test files passed."
