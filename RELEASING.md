# Releasing

Sites update themselves from this repo's GitHub releases. Every release is
checked on one test site (the agency's own staging site) before any client
site gets it.

## Release channels

| Channel | Who | What the site installs |
|---|---|---|
| Test | The agency's own staging site | Test releases (GitHub pre-releases) and normal releases, whichever is newest |
| Stable (default) | Every other site | Normal releases only |

A site's channel is set on **Optima → Build → Updates → Release channel**
(operators only), stored in the `optima_hub_channel` option. To fix it in
`wp-config.php` instead (the card then only shows it):

```php
define( 'OPTIMA_HUB_CHANNEL', 'test' );
```

A site without Optima Build is always on stable. Theme updates are not part of
this: each theme still updates from its own repo.

## The flow

1. Bump `Version:` in the plugin's main file (and its `OPTIMA_*_VERSION` constant).
2. Add a dated entry at the top of the plugin's `CHANGELOG.md`.
3. Merge to `main`. The Release workflow tags `<slug>-v<version>`, builds the
   zip and publishes it as a **test release** (a GitHub pre-release). Then it
   rewrites the version list sites read (`releases.json` on the
   `version-list` branch). `optima-core` always ships first; other plugins
   call it.
4. **Check it on the agency's own staging site.** Open Build → Updates and
   press Check for new versions; the update is labeled "Test release". Press
   its Update now (it updates in place and comes back to Updates), then look at
   the pages and screens the release touches and at the Site health block.
   Client sites do not see it.
5. **Promote it.** Actions tab → Promote → Run workflow. Leave "Plugins" empty
   to promote the newest test release of every plugin, or name plugins
   (`optima-core optima-build`). Tick "Dry run" first to see the list. Promote
   marks each one as a normal release and rewrites the version list; client
   sites install it within a few hours.
6. Something wrong on the test site? Do not promote. Fix it, bump the version
   again and merge; the new test release replaces the bad one on the test
   site, and Promote only ever sends the newest one.

Promote only flips the pre-release flag. It never rebuilds, so the zip the
test site ran is exactly the zip every site installs. A test release that is
not newer than the plugin's newest normal release is skipped.

## The switch

`.github/release-channel` decides how step 3 publishes:

- `stable`: a normal release (every site takes it within hours; the old flow).
- `test`: a test release (only the test site takes it until Promote).

The Release workflow reads the file at the commit it releases, so a change to
it applies from the next release on. A missing or unknown value means
`stable`.

**Turning the test channel on (once):** the release that brought release
channels (Optima Build 0.7.9.14, Optima Core 1.6.0.10 and every plugin's
matching bump) ships with the file set to `stable`, so every site gets the
channel-aware updater as a normal release. After every site runs it:

1. On the agency's own staging site, Build → Updates → Release channel →
   Switch to Test. Site health shows a Release channel row; it must read Test,
   with no warning.
2. In a pull request, change `.github/release-channel` to `test` and merge.
   With no version bump in it, that merge releases nothing; every release
   after it is a test release.

To go back to the old flow, set the file to `stable` again. Test releases
already published stay test releases until promoted.

## Checks before anything ships

The Release workflow's first job runs the uniform check, the Optima Core
contract, the client-names check on every tracked file, the updater and
catalog copy checks, the "Run workflow" list check, and every test in
`tools/tests` that runs without WordPress (`tools/tests/run-all.sh`). If any
fails, nothing is tagged, built or listed.

The "Run workflow" plugin list is written from the plugin folders:
`python3 tools/release/dispatch-list.py` after adding a plugin (the Lint
workflow fails until it is run).

## Download safety

- **Pinned addresses.** The updater (and Optima Build) only installs a zip
  from `https://github.com/optimadigitalmedia/Optima-Plugins/releases/download/`
  (`Optima_Plugin_Updater::DOWNLOAD_PREFIX`). A release listed anywhere else
  is skipped, and an update of an Optima plugin from any other address is
  refused.
- **Checksums.** `releases.json` lists each zip's `sha256` (GitHub's digest of
  the uploaded asset, checked against the zip the Release job built). Sites
  download the zip, compare, and only then let WordPress unpack it; a mismatch
  stops the install with an error and a line in the PHP error log. A release
  listed without a `sha256` still installs, with a log line.
- **The catalog goes through the channels.** Optima Build reads the feature
  catalog from the `version-list` branch, never from `main`:
  `catalog-test.json` (written from the released commit on every run) on the
  test channel, `catalog.json` on stable. On the test switch only Promote
  copies `catalog-test.json` to `catalog.json`; on the stable switch every run
  writes both. The copy bundled in Optima Build is the fallback.

The updater copy every plugin ships is refreshed with
`bash tools/updater/sync.sh` (`--check` lists copies that differ).

## Rules

Releases are immutable once published: never re-tag a shipped version, and
never point sites at a release asset that has to change later. Marking a
release as a pre-release or not is allowed on an immutable release; that is
all Promote changes.

Sites running an updater older than release channels skip pre-releases (they
always did), so they wait for Promote too.

The repo must stay **public** so sites can download releases without a token.

## Tests

- `php tools/tests/release-channel.php`: which version each channel is offered.
- `bash tools/tests/promote-dry-run.sh`: what Promote picks, on made-up releases.
- `RELEASES_JSON=<file> bash tools/release/promote.sh`: a dry run on any release list.
- `bash tools/tests/feed-build.sh`: the version list and catalog the workflows publish, on made-up data.
- `bash tools/tests/run-all.sh`: every test above and the rest of `tools/tests` that needs no WordPress.

The updater's source copy is `tools/updater/class-optima-plugin-updater.php`;
every plugin ships an identical copy, and the Lint workflow fails when one differs.
