# Releasing

Sites update themselves from this repo's GitHub releases.

1. Bump `Version:` in the plugin's main file (and its `OPTIMA_*_VERSION` constant).
2. Add a dated entry at the top of the plugin's `CHANGELOG.md`.
3. Merge to `main`. The Release workflow tags `<slug>-v<version>`, builds the
   zip, publishes the release and refreshes the `feed` release that sites read.
   `optima-core` always ships first; other plugins call it.

The repo must stay **public** so sites can download releases without a token.
