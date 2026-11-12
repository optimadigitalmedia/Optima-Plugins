#!/usr/bin/env bash
# The version list and catalog the Release and Promote workflows publish, on
# made-up data. No GitHub needed:
#   bash tools/tests/feed-build.sh
set -euo pipefail
root="$(cd "$(dirname "$0")/../.." && pwd)"
tmp="$(mktemp -d)"
trap 'rm -rf "${tmp}"' EXIT
P="https://github.com/optimadigitalmedia/Optima-Plugins/releases/download"
A="$(printf 'a%.0s' $(seq 64))"
B="$(printf 'b%.0s' $(seq 64))"
C="$(printf 'c%.0s' $(seq 64))"

fail=0
ok() { if eval "$1"; then echo "PASS $2"; else echo "FAIL $2"; fail=$((fail + 1)); fi; }

# GitHub's list: one asset with a digest, one with none (hashed from the previous list).
jq -n --arg p "${P}" --arg a "${A}" '[
  {tag_name:"optima-core-v1.0.1", draft:false, prerelease:true, body:"new", assets:[{name:"optima-core.zip", browser_download_url:($p + "/optima-core-v1.0.1/optima-core.zip"), digest:("sha256:" + $a)}]},
  {tag_name:"optima-core-v1.0.0", draft:false, prerelease:false, body:"old", assets:[{name:"optima-core.zip", browser_download_url:($p + "/optima-core-v1.0.0/optima-core.zip"), digest:null}]},
  {tag_name:"feed", draft:false, prerelease:false, body:"", assets:[]}
]' > "${tmp}/api.json"
mkdir -p "${tmp}/prev" "${tmp}/sha"
jq -n --arg p "${P}" --arg b "${B}" '[{tag_name:"optima-core-v1.0.0", draft:false, prerelease:false, body:"old", assets:[{name:"optima-core.zip", browser_download_url:($p + "/optima-core-v1.0.0/optima-core.zip"), sha256:$b}]}]' > "${tmp}/prev/releases.json"
echo '{"features":[{"slug":"optima-old","name":"Old"}]}' > "${tmp}/prev/catalog.json"
echo '{"features":[{"slug":"optima-older-test","name":"Older test"}]}' > "${tmp}/prev/catalog-test.json"
echo "${P}/optima-core-v1.0.1/optima-core.zip ${A}" > "${tmp}/sha/optima-core-v1.0.1.sha256"

run() { RELEASES_JSON="${tmp}/api.json" PREV_DIR="${tmp}/prev" SHA256_DIR="${tmp}/sha" OUT_DIR="${tmp}/out" bash "${root}/tools/release/publish-feed.sh" > "${tmp}/log" 2>&1; }

# 1. A release run on the test switch.
rm -rf "${tmp}/out"
CHANNEL=test run
R="${tmp}/out/releases.json"
ok '[ "$(jq length "$R")" = 2 ]' 'the "feed" tag is left out'
ok '[ "$(jq -r ".[0].assets[0].sha256" "$R")" = "$A" ]' "GitHub's digest becomes the sha256, without its sha256: prefix"
ok '[ "$(jq -r ".[1].assets[0].sha256" "$R")" = "$B" ]' 'an asset with no digest keeps the sha256 from the previous list'
ok '[ "$(jq -r ".[0].assets[0] | keys | join(\",\")" "$R")" = "browser_download_url,name,sha256" ]' 'assets carry only name, address and sha256 (old updaters ignore the new key)'
ok 'cmp -s "${root}/catalog.json" "${tmp}/out/catalog-test.json"' 'test switch: catalog-test.json is the repo catalog'
ok '[ "$(jq -r ".features[0].slug" "${tmp}/out/catalog.json")" = optima-old ]' 'test switch: catalog.json waits for Promote'

# 2. The stable switch writes both.
rm -rf "${tmp}/out"
CHANNEL=stable run
ok 'cmp -s "${root}/catalog.json" "${tmp}/out/catalog.json"' 'stable switch: catalog.json is the repo catalog too'

# 3. Promote copies the test catalog over.
rm -rf "${tmp}/out"
FEED_EVENT=promote run
ok '[ "$(jq -r ".features[0].slug" "${tmp}/out/catalog.json")" = optima-older-test ]' 'promote: catalog.json becomes the previous catalog-test.json'
ok 'cmp -s "${tmp}/out/catalog.json" "${tmp}/out/catalog-test.json"' 'promote: both catalogs match afterwards'

# 4. A first run with no catalog on the branch yet seeds both from the repo.
rm -rf "${tmp}/out" "${tmp}/prev/catalog.json"
CHANNEL=test run
ok 'cmp -s "${root}/catalog.json" "${tmp}/out/catalog.json"' 'first run on the test switch: catalog.json is seeded from the repo'

# 5. The build and GitHub disagree: nothing is published.
rm -rf "${tmp}/out"
echo "${P}/optima-core-v1.0.1/optima-core.zip ${C}" > "${tmp}/sha/optima-core-v1.0.1.sha256"
if CHANNEL=test run; then r=0; else r=1; fi
ok '[ "$r" = 1 ] && [ ! -e "${tmp}/out" ]' 'a zip that differs from the one the Release job built stops the publish'

echo
if [ "${fail}" -gt 0 ]; then echo "${fail} failed"; exit 1; fi
echo "All passed"
