#!/usr/bin/env bash
# Optima Core contract check.
#
# Other Optima plugins call into Optima Core. If a Optima Core change renames or
# removes one of these, every client site running the dependent plugin breaks
# on its next automatic update. This check runs in CI (Lint workflow) and fails
# the build first, so the dependent plugin is updated in the same change.
#
# Each line: file | exact text that must still be there | who depends on it.
# When a plugin starts relying on a new Optima Core API, add a line here.
set -euo pipefail
cd "$(dirname "$0")/.."

fail=0
while IFS='|' read -r file needle who; do
  file="$(echo "$file" | xargs)"; needle="$(echo "$needle" | sed 's/^ *//;s/ *$//')"; who="$(echo "$who" | xargs)"
  [ -z "$file" ] && continue
  if ! grep -qF -- "$needle" "$file"; then
    echo "::error file=${file}::Optima Core contract broken: '${needle}' is gone from ${file}. Used by: ${who}. Update the dependent plugin in this same change, then this line."
    fail=1
  else
    echo "ok  ${file}: ${needle}"
  fi
done <<'CONTRACT'
optima-core/includes/business.php | final class Optima_Core_Business | optima-cards (business identity: name, phone, email, city, region, postal, same_as)
optima-core/includes/business.php | public static function get() | optima-cards (Optima_Core_Business::get())
optima-core/includes/business.php | 'same_as' | optima-cards (social profile URLs)
optima-core/includes/admin.php | 'manage_options', 'optima', | optima-cards (adds Cards & Links under the 'optima' menu slug)
optima-core/includes/seo/class-seo-head.php | final class Optima_SEO_Head | optima-cards (removes Optima Core head tags on card pages)
optima-core/includes/seo/class-seo-head.php | add_action( 'wp_head', array( __CLASS__, 'output' ), 1 ) | optima-cards (remove_action must match this hook and priority)
optima-core/includes/business.php | public static function get() | optima-contact (Optima_Core_Business::get() for the default phone number)
optima-core/includes/business.php | 'phone' | optima-contact (default phone number for the call bar)
optima-core/includes/schema/class-optima-schema.php | apply_filters( 'optima_schema_graph' | optima-hours, optima-team (add hours to the business node; add Person/ProfilePage nodes)
optima-core/includes/schema/class-optima-schema.php | home_url( '/#business' ) | optima-hours, optima-team (business @id they extend and point worksFor at)
optima-core/includes/schema/class-optima-schema.php | $graph['article'] | optima-team (sets the BlogPosting author to the Person)
optima-core/includes/business.php | final class Optima_Core_Business | optima-accessibility (business name, email and phone for the statement)
optima-core/includes/business.php | public static function get() | optima-aeo (/llms.txt name, contact line; optima-build AI checks)
optima-core/optima-core.php | define( 'OPTIMA_SEO_META_NOINDEX' | optima-aeo (noindexed pages stay out of /llms.txt)
optima-core/optima-core.php | define( 'OPTIMA_SEO_META_CANONICAL' | optima-aeo (pages canonical to another URL stay out of /llms.txt)
optima-core/includes/seo/class-seo-setup.php | public static function active() | optima-aeo (setup noindex paths stay out of /llms.txt)
optima-core/includes/seo/class-seo-setup.php | public static function data() | optima-aeo (setup noindex paths stay out of /llms.txt)
optima-core/includes/admin.php | public static function checks() | optima-build (Site health: search listing checks)
optima-core/includes/admin.php | public static function render_checks( array $checks ) | optima-build (Site health: same check cards)
optima-core/includes/schema/class-optima-schema.php | apply_filters( 'optima_schema_graph' | optima-events (adds Event and Person nodes to the graph)
optima-core/includes/schema/class-optima-schema.php | home_url( '/#business' ) | optima-events (Event organizer points at the business)
optima-core/includes/sync/class-optima-sync.php | public static function summary( | optima-build (Updates: the Approved copy step reads the counts)
optima-core/includes/sync/class-optima-sync.php | const UPDATE | optima-build (Updates: copy waiting from the theme)
optima-core/includes/sync/class-optima-sync.php | public static function plan( | optima-build (Updates: Apply approved copy)
optima-core/includes/sync/class-optima-sync-admin.php | const ACTION = 'optima_sync'; | optima-build (Updates posts the Approved copy form to this action)
optima-core/includes/schema/class-optima-schema.php | public static function plain( | optima-core breadcrumbs (schema names as plain text)
optima-build/includes/class-optima-hub-access.php | public static function operators(): array | optima-core (staging mail guard and Change log read the Build operator list)
optima-build/includes/class-optima-hub-access.php | const DEFAULT_OPERATOR_DOMAIN_HASHES | optima-core (staging mail guard: the partner domain, as a hash)
optima-build/includes/class-optima-hub-access.php | public static function is_operator( | optima-core (Change log: edits by operators are not logged)
optima-core/includes/offices.php | public static function language_nodes() | optima-hours (availableLanguage on the intake ContactPoint)
optima-hours/includes/class-optima-hours-schema.php | public static function properties(): array | optima-core offices (office nodes take the Optima Hours schedule while it owns the hours job)
optima-core/includes/protection.php | 'login_limit' | optima-build (Site health row: Failed-login limit)
optima-core/includes/login-limit.php | final class Optima_Core_Login_Limit | optima-build (Site health row: Failed-login limit)
CONTRACT

exit "$fail"
