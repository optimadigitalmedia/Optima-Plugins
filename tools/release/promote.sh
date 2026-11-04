#!/usr/bin/env bash
# Promote test releases to normal releases (the Promote workflow runs this).
#
#   bash tools/release/promote.sh                      # dry run, every plugin
#   bash tools/release/promote.sh optima-core optima-build
#   bash tools/release/promote.sh --apply               # really flip the flags
#
# For each plugin it finds the newest test release (a GitHub pre-release with
# its <slug>.zip attached) and, when that version is newer than the plugin's
# newest normal release, marks it as a normal release. Only the flag changes:
# nothing is rebuilt, so the zip the test site ran is exactly the zip that
# ships. Optima Core goes first, as on release.
#
# RELEASES_JSON=<file> reads the release list from a file instead of GitHub
# (for a dry run with made-up data). --apply needs gh signed in with
# permission to edit releases.
set -euo pipefail

apply=false
picked=()
for arg in "$@"; do
  case "$arg" in
    --apply) apply=true ;;
    -h|--help) sed -n '2,17p' "$0"; exit 0 ;;
    *) picked+=("$arg") ;;
  esac
done

root="$(cd "$(dirname "$0")/../.." && pwd)"

if [ -n "${RELEASES_JSON:-}" ]; then
  releases="$(jq -c '.' "${RELEASES_JSON}")"
else
  releases="$(gh api --paginate "repos/${GH_REPO:-optimadigitalmedia/Optima-Plugins}/releases?per_page=100" | jq -s -c 'add // []')"
fi

# Which plugins: the ones named, or every plugin folder. Optima Core first.
slugs=()
if [ "${#picked[@]}" -gt 0 ]; then
  for raw in "${picked[@]}"; do
    for slug in ${raw//,/ }; do
      if [ ! -f "${root}/${slug}/${slug}.php" ]; then
        echo "::error::No such plugin: ${slug}" >&2
        exit 1
      fi
      slugs+=("${slug}")
    done
  done
else
  for dir in "${root}"/optima-*/; do
    slug="$(basename "${dir}")"
    [ -f "${dir}${slug}.php" ] && slugs+=("${slug}")
  done
fi
ordered=()
for slug in "${slugs[@]}"; do [ "${slug}" = "optima-core" ] && ordered+=("${slug}"); done
for slug in "${slugs[@]}"; do [ "${slug}" != "optima-core" ] && ordered+=("${slug}"); done

# Newest version of one plugin among releases with the wanted pre-release flag
# and a <slug>.zip asset. Prints nothing when there is none.
newest() {
  local slug="$1" pre="$2"
  jq -r --arg s "${slug}" --argjson pre "${pre}" '
    .[]
    | select((.draft // false) | not)
    | select((.prerelease // false) == $pre)
    | select(.tag_name | startswith($s + "-v"))
    | select(any(.assets[]?; .name == ($s + ".zip")))
    | .tag_name | ltrimstr($s + "-v")' <<< "${releases}" | sort -V | tail -n 1
}

promoted=0
for slug in "${ordered[@]}"; do
  test_v="$(newest "${slug}" true)"
  stable_v="$(newest "${slug}" false)"
  if [ -z "${test_v}" ]; then
    echo "${slug}: no test release, nothing to promote."
    continue
  fi
  if [ -n "${stable_v}" ] && [ "$(printf '%s\n%s\n' "${test_v}" "${stable_v}" | sort -V | tail -n 1)" = "${stable_v}" ]; then
    echo "${slug}: test release ${test_v} is not newer than the normal release ${stable_v}, skipped."
    continue
  fi
  tag="${slug}-v${test_v}"
  if ${apply}; then
    gh release edit "${tag}" --prerelease=false --repo "${GH_REPO:-optimadigitalmedia/Optima-Plugins}" >/dev/null
    echo "${tag}: promoted (was ${stable_v:-no normal release})."
  else
    echo "${tag}: would promote (normal release now ${stable_v:-none})."
  fi
  promoted=$((promoted + 1))
done

echo "${promoted} release(s) $(${apply} && echo promoted || echo to promote)."
if [ -n "${GITHUB_OUTPUT:-}" ]; then
  echo "promoted=${promoted}" >> "${GITHUB_OUTPUT}"
fi
