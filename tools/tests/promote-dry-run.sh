#!/usr/bin/env bash
# The Promote workflow's choice of releases, on made-up data. No GitHub needed:
#   bash tools/tests/promote-dry-run.sh
set -euo pipefail
root="$(cd "$(dirname "$0")/../.." && pwd)"
tmp="$(mktemp -d)"
trap 'rm -rf "${tmp}"' EXIT

rel() { # tag prerelease draft [zip-name]
  local slug="${1%-v*}"
  jq -n --arg t "$1" --argjson p "$2" --argjson d "$3" --arg z "${4-${slug}.zip}" \
    '{tag_name:$t, prerelease:$p, draft:$d, assets:(if $z == "" then [] else [{name:$z, browser_download_url:("https://example.invalid/" + $t)}] end)}'
}
{
  rel optima-core-v1.6.0.9 false false
  rel optima-core-v1.6.0.10 true false
  rel optima-core-v1.6.0.11 true true        # draft: never promoted
  rel optima-build-v0.7.9.13 false false
  rel optima-build-v0.7.9.9 true false       # old test release: older than the normal one
  rel optima-team-v0.1.9.7 false false
  rel optima-team-v0.1.9.8 true false
  rel optima-team-v0.1.9.10 true false       # newest by number, not by text
  rel optima-hours-v0.1.8.9 true false ""    # no zip: not installable
  rel optima-cards-v1.0.21.8 true false      # first ever release of a plugin
} | jq -s '.' > "${tmp}/releases.json"

out="$(RELEASES_JSON="${tmp}/releases.json" bash "${root}/tools/release/promote.sh")"
echo "${out}"

fail=0
expect() { grep -qF -- "$1" <<< "${out}" && echo "PASS $2" || { echo "FAIL $2"; fail=1; }; }
expect "optima-core-v1.6.0.10: would promote (normal release now 1.6.0.9)" "core: newest test release promoted"
expect "optima-build: test release 0.7.9.9 is not newer than the normal release 0.7.9.13, skipped." "build: an older test release is skipped"
expect "optima-team-v0.1.9.10: would promote" "team: 0.1.9.10 beats 0.1.9.8 (numbers, not text)"
expect "optima-hours: no test release, nothing to promote." "hours: a test release without its zip is ignored"
expect "optima-cards-v1.0.21.8: would promote (normal release now none)" "cards: first release can be promoted"
expect "3 release(s) to promote." "three releases in all, nothing changed (dry run)"
[ "$(head -n 1 <<< "${out}" | cut -d: -f1)" = "optima-core-v1.6.0.10" ] && echo "PASS Optima Core goes first" || { echo "FAIL Optima Core goes first"; fail=1; }

named="$(RELEASES_JSON="${tmp}/releases.json" bash "${root}/tools/release/promote.sh" optima-team)"
grep -q "optima-team-v0.1.9.10: would promote" <<< "${named}" && ! grep -q optima-core <<< "${named}" \
  && echo "PASS a named plugin promotes only that plugin" || { echo "FAIL a named plugin promotes only that plugin"; fail=1; }
if RELEASES_JSON="${tmp}/releases.json" bash "${root}/tools/release/promote.sh" optima-nope >/dev/null 2>&1; then
  echo "FAIL an unknown plugin name is refused"; fail=1
else
  echo "PASS an unknown plugin name is refused"
fi
exit "${fail}"
