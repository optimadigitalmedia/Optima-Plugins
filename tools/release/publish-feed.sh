#!/usr/bin/env bash
# Publish the version list sites read (releases.json on the version-list
# branch), and the feature catalog Optima Build reads beside it. The Release
# and Promote workflows run this after every change.
#
# Every release is listed with its pre-release flag. Sites on the stable
# channel skip pre-releases (test releases); sites on the test channel use
# them. Each zip is listed with its sha256, and sites check the download
# against it before installing.
#
# Where each sha256 comes from, first match wins:
#   1. GitHub's own digest for the asset (the API's "digest" field).
#   2. The sha256 the Release job computed from the zip it built
#      (SHA256_DIR, one "<download address> <sha256>" line per file).
#   3. The previous releases.json on the version-list branch.
#   4. The zip itself, downloaded and hashed here.
# When 1 and 2 both exist and differ, nothing is published.
#
# The catalog goes through the release channels too:
#   catalog-test.json  the repo's catalog.json at the commit being released.
#                      Sites on the test channel read it.
#   catalog.json       what every other site reads. On the stable switch it is
#                      written with catalog-test.json; on the test switch it is
#                      kept as it was, and only Promote (FEED_EVENT=promote)
#                      copies catalog-test.json over it.
#
# Needs GH_TOKEN and GH_REPO, unless run offline for a test:
#   RELEASES_JSON=<file>  the GitHub release list (an array) instead of the API
#   PREV_DIR=<dir>        the previous version-list files instead of the branch
#   OUT_DIR=<dir>         write the files there and push nothing
#   CHANNEL=test|stable   instead of reading .github/release-channel
set -euo pipefail

root="$(cd "$(dirname "$0")/../.." && pwd)"
offline=false
[ -n "${OUT_DIR:-}" ] && offline=true
if ! "${offline}"; then
  : "${GH_TOKEN:?GH_TOKEN is required}"
  : "${GH_REPO:?GH_REPO is required}"
fi
repo="${GH_REPO:-optimadigitalmedia/Optima-Plugins}"
prefix="https://github.com/${repo}/releases/download/"

dir="$(mktemp -d)"
trap 'rm -rf "${dir}"' EXIT
mkdir -p "${dir}/out" "${dir}/prev"

# The release list, flattened to one array.
if [ -n "${RELEASES_JSON:-}" ]; then
  jq -c 'if type == "array" then . else [] end' "${RELEASES_JSON}" > "${dir}/api.json"
else
  gh api --paginate "repos/${repo}/releases?per_page=100" | jq -s -c 'add // []' > "${dir}/api.json"
fi

# The previous version-list files (missing ones stay missing).
for f in releases.json catalog.json catalog-test.json; do
  if [ -n "${PREV_DIR:-}" ]; then
    [ -f "${PREV_DIR}/${f}" ] && cp "${PREV_DIR}/${f}" "${dir}/prev/${f}"
  elif ! "${offline}"; then
    gh api -H "Accept: application/vnd.github.raw" "repos/${repo}/contents/${f}?ref=version-list" > "${dir}/prev/${f}" 2>/dev/null || rm -f "${dir}/prev/${f}"
  fi
  if [ -f "${dir}/prev/${f}" ] && ! jq -e . "${dir}/prev/${f}" >/dev/null 2>&1; then
    echo "::warning::The previous ${f} on the version-list branch is not valid JSON; ignoring it."
    rm -f "${dir}/prev/${f}"
  fi
done
[ -f "${dir}/prev/releases.json" ] || echo '[]' > "${dir}/prev/releases.json"

# Checksums the Release job computed from the zips it built.
: > "${dir}/known.txt"
if [ -n "${SHA256_DIR:-}" ] && [ -d "${SHA256_DIR}" ]; then
  find "${SHA256_DIR}" -type f -name '*.sha256' -exec cat {} + >> "${dir}/known.txt" || true
fi

# Build the list. sha256 is "" for now where nothing is known yet.
jq -c \
  --slurpfile prev "${dir}/prev/releases.json" \
  --rawfile known "${dir}/known.txt" '
  def hex: ascii_downcase | ltrimstr("sha256:") | if test("^[0-9a-f]{64}$") then . else "" end;
  ($known | split("\n") | map(select(test("\\S")) | split(" ") | map(select(length > 0)) | {(.[0]): (.[1] // "" | hex)}) | add // {}) as $k
  | ([ ($prev[0] // [])[]? | .assets[]? | select(type == "object" and (.sha256? // "") != "") | {(.browser_download_url): (.sha256 | hex)} ] | add // {}) as $p
  | [ .[] | select(.tag_name != "feed") | {
      tag_name, draft, prerelease,
      body: ((.body // "")[0:1500]),
      assets: [ .assets[]? | {
        name, browser_download_url,
        digest: ((.digest // "") | hex),
        built: ($k[.browser_download_url] // ""),
        prev: ($p[.browser_download_url] // "")
      } ]
    } ]' "${dir}/api.json" > "${dir}/draft.json"

conflicts="$(jq -r '.[] | .assets[] | select(.digest != "" and .built != "" and .digest != .built) | "\(.browser_download_url): GitHub says \(.digest), the build said \(.built)"' "${dir}/draft.json")"
if [ -n "${conflicts}" ]; then
  echo "::error::A published zip does not match the zip the Release job built. Nothing was published."
  echo "${conflicts}"
  exit 1
fi

# Fill the rest by downloading the zip, for any asset nothing above covers.
: > "${dir}/fetched.txt"
while IFS= read -r url; do
  [ -z "${url}" ] && continue
  case "${url}" in
    "${prefix}"*) ;;
    *) echo "::warning::Not hashing ${url}: not one of this repo's release downloads."; continue ;;
  esac
  if curl -sSfL --retry 3 -o "${dir}/asset.zip" "${url}"; then
    echo "${url} $(sha256sum "${dir}/asset.zip" | cut -d' ' -f1)" >> "${dir}/fetched.txt"
  else
    echo "::warning::Could not download ${url} to hash it; it is listed without a sha256."
  fi
done < <(jq -r '.[] | .assets[] | select(.digest == "" and .built == "" and .prev == "") | .browser_download_url' "${dir}/draft.json")

jq -c --rawfile fetched "${dir}/fetched.txt" '
  ($fetched | split("\n") | map(select(test("\\S")) | split(" ") | {(.[0]): .[1]}) | add // {}) as $f
  | [ .[] | .assets |= [ .[] |
      ([.digest, .built, .prev, ($f[.browser_download_url] // "")] | map(select(. != "")) | first // "") as $sha
      | {name, browser_download_url} + (if $sha != "" then {sha256: $sha} else {} end)
    ] ]' "${dir}/draft.json" | jq '.' > "${dir}/out/releases.json"

total="$(jq length "${dir}/out/releases.json")"
tests="$(jq '[.[] | select(.prerelease)] | length' "${dir}/out/releases.json")"
missing="$(jq '[.[] | .assets[] | select(has("sha256") | not)] | length' "${dir}/out/releases.json")"
echo "${total} releases listed, ${tests} of them test releases, ${missing} zips without a sha256."

# The catalog.
valid_catalog() { jq -e '(.features | type == "array") and (.features | length > 0)' "$1" >/dev/null 2>&1; }
if [ "${FEED_EVENT:-release}" = "promote" ]; then
  if [ -f "${dir}/prev/catalog-test.json" ]; then
    cp "${dir}/prev/catalog-test.json" "${dir}/out/catalog-test.json"
  else
    cp "${root}/catalog.json" "${dir}/out/catalog-test.json"
  fi
  cp "${dir}/out/catalog-test.json" "${dir}/out/catalog.json"
  echo "Catalog: promoted catalog-test.json to catalog.json."
else
  channel="${CHANNEL:-$(tr -d '[:space:]' < "${root}/.github/release-channel" 2>/dev/null || true)}"
  [ "${channel}" = "test" ] || channel="stable"
  cp "${root}/catalog.json" "${dir}/out/catalog-test.json"
  if [ "${channel}" = "test" ] && [ -f "${dir}/prev/catalog.json" ]; then
    cp "${dir}/prev/catalog.json" "${dir}/out/catalog.json"
    echo "Catalog: catalog-test.json updated; catalog.json kept until Promote."
  else
    cp "${root}/catalog.json" "${dir}/out/catalog.json"
    echo "Catalog: catalog-test.json and catalog.json both updated (${channel} switch)."
  fi
fi
for f in catalog.json catalog-test.json; do
  if ! valid_catalog "${dir}/out/${f}"; then
    echo "::error::${f} has no features list. Nothing was published."
    exit 1
  fi
done

if "${offline}"; then
  mkdir -p "${OUT_DIR}"
  cp "${dir}/out/"*.json "${OUT_DIR}/"
  echo "Written to ${OUT_DIR} (offline run, nothing pushed)."
  exit 0
fi

# Releases are immutable in this org: an asset cannot be added or replaced
# once a release is published. The list lives on its own branch instead,
# replaced on every run.
cd "${dir}/out"
git init -q -b version-list
git config user.name "github-actions[bot]"
git config user.email "41898282+github-actions[bot]@users.noreply.github.com"
git add releases.json catalog.json catalog-test.json
git commit -q -m "Version list for ${GITHUB_SHA:-manual run}"
git push -q --force "https://x-access-token:${GH_TOKEN}@github.com/${repo}.git" HEAD:version-list
echo "releases.json, catalog.json and catalog-test.json published on the version-list branch."
