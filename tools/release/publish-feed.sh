#!/usr/bin/env bash
# Publish the version list sites read (releases.json on the version-list
# branch). The Release and Promote workflows run this after every change.
#
# Every release is listed with its pre-release flag. Sites on the stable
# channel skip pre-releases (test releases); sites on the test channel use
# them. Needs GH_TOKEN and GH_REPO.
set -euo pipefail

: "${GH_TOKEN:?GH_TOKEN is required}"
: "${GH_REPO:?GH_REPO is required}"

dir="$(mktemp -d)"
gh api --paginate "repos/${GH_REPO}/releases?per_page=100" \
  | jq -s '[ .[][] | select(.tag_name != "feed") | {tag_name, draft, prerelease, published_at, body: ((.body // "")[0:1500]), assets: [ .assets[] | {name, browser_download_url} ]} ]' \
  > "${dir}/releases.json"
echo "$(jq length "${dir}/releases.json") releases listed, $(jq '[.[] | select(.prerelease)] | length' "${dir}/releases.json") of them test releases."
# Releases are immutable in this org: an asset cannot be added or replaced
# once a release is published. The list lives on its own branch instead, one
# file, replaced on every run.
cd "${dir}"
git init -q -b version-list
git config user.name "github-actions[bot]"
git config user.email "41898282+github-actions[bot]@users.noreply.github.com"
git add releases.json
git commit -q -m "Version list for ${GITHUB_SHA:-manual run}"
git push -q --force "https://x-access-token:${GH_TOKEN}@github.com/${GH_REPO}.git" HEAD:version-list
echo "releases.json published on the version-list branch."
