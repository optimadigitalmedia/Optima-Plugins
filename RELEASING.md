# Releasing

Sites update themselves from this repo's GitHub releases.

1. Bump `Version:` in the plugin's main file (and its `OPTIMA_*_VERSION` constant).
2. Add a dated entry at the top of the plugin's `CHANGELOG.md`.
3. Merge to `main`. The Release workflow tags `<slug>-v<version>`, builds the
   zip, publishes the release and rewrites the version list sites read
   (`releases.json` on the `version-list` branch). `optima-core` always ships
   first; other plugins call it.

Releases are immutable once published: never re-tag a shipped version, and
never point sites at a release asset that has to change later.

The repo must stay **public** so sites can download releases without a token.
