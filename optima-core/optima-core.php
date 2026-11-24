<?php
/**
 * Plugin Name:       Optima Core
 * Plugin URI:        https://optimadigitalmedia.com
 * Description:       The Optima engine for a client site: business identity, content model (post types, fields, Block Bindings), the JSON-LD schema graph, and the in-house SEO head tags and sitemap tuning. One plugin, one Optima dashboard. The Yoast replacement, built to survive any future redesign.
 * Version:           1.15.0.4
 * Requires at least: 6.5
 * Requires PHP:      8.0
 * Author:            Optima Digital
 * Author URI:        https://optimadigitalmedia.com
 * License:           GPL-2.0-or-later
 * Text Domain:       optima-core
 * Update URI:        https://github.com/optimadigitalmedia/Optima-Plugins/optima-core
 *
 * @package Optima_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'OPTIMA_CORE_VERSION', '1.15.0.4' );
define( 'OPTIMA_CORE_DIR', plugin_dir_path( __FILE__ ) );
define( 'OPTIMA_CORE_FILE', __FILE__ );

/*
 * SEO per-post meta keys (were optima-seo; folded in). Protected, edited through
 * the Optima SEO box.
 */
define( 'OPTIMA_SEO_META_TITLE', '_optima_seo_title' );
define( 'OPTIMA_SEO_META_DESC', '_optima_seo_desc' );
define( 'OPTIMA_SEO_META_CANONICAL', '_optima_seo_canonical' );
define( 'OPTIMA_SEO_META_NOINDEX', '_optima_seo_noindex' );

/* ---- Content model (business identity, post types, fields, bindings) ---- */
require_once OPTIMA_CORE_DIR . 'includes/controls.php';
require_once OPTIMA_CORE_DIR . 'includes/registry.php';
require_once OPTIMA_CORE_DIR . 'includes/fieldsets.php';
require_once OPTIMA_CORE_DIR . 'includes/settings-groups.php';
require_once OPTIMA_CORE_DIR . 'includes/helpers.php';
require_once OPTIMA_CORE_DIR . 'includes/post-types.php';
require_once OPTIMA_CORE_DIR . 'includes/meta.php';
require_once OPTIMA_CORE_DIR . 'includes/meta-box.php';
require_once OPTIMA_CORE_DIR . 'includes/block-bindings.php';
require_once OPTIMA_CORE_DIR . 'includes/business.php';
require_once OPTIMA_CORE_DIR . 'includes/universal-types.php';

/* ---- Release 2 page layer: offices, breadcrumbs, primary practice, content date ---- */
require_once OPTIMA_CORE_DIR . 'includes/offices.php';
require_once OPTIMA_CORE_DIR . 'includes/breadcrumbs.php';
require_once OPTIMA_CORE_DIR . 'includes/practice.php';
require_once OPTIMA_CORE_DIR . 'includes/content-changed.php';
Optima_Core_Offices::init();
Optima_Core_Breadcrumbs::init();
Optima_Core_Practice::init();
Optima_Core_Content_Changed::init();

/* ---- Editable pages: words and photos on the page, layout locked (theme opt-in) ---- */
require_once OPTIMA_CORE_DIR . 'includes/editable-pages.php';

/* ---- Spam protection and the security baseline (plugin-first rule 19), every switch on by default ---- */
/* The visitor's real address: Cloudflare's header only from Cloudflare's own ranges (optima_client_ip()). */
require_once OPTIMA_CORE_DIR . 'includes/client-ip.php';
require_once OPTIMA_CORE_DIR . 'includes/operators.php';
require_once OPTIMA_CORE_DIR . 'includes/protection.php';
Optima_Core_Protection::init();
/* Failed logins: 5 in 15 minutes lock that address or username out for 15 minutes (switch login_limit). */
require_once OPTIMA_CORE_DIR . 'includes/login-limit.php';
Optima_Core_Login_Limit::init();
/* Staging copies email only operators and the admin email, and call no outside services. */
require_once OPTIMA_CORE_DIR . 'includes/staging-guard.php';
Optima_Core_Staging::init();
/* Caches purge themselves after a theme or Optima plugin update. */
require_once OPTIMA_CORE_DIR . 'includes/cache-purge.php';
Optima_Core_Cache::init();
/* Privacy and terms drafts, for the firm to approve (never published from here). */
require_once OPTIMA_CORE_DIR . 'includes/policies.php';
Optima_Core_Policies::init();
/* [optima_map]: Google's map loads only on a tap. */
require_once OPTIMA_CORE_DIR . 'includes/map.php';
Optima_Core_Map::init();

/* ---- Schema module (was optima-schema) ---- */
require_once OPTIMA_CORE_DIR . 'includes/schema/class-optima-schema.php';
require_once OPTIMA_CORE_DIR . 'includes/schema/class-optima-schema-conflicts.php';

/* ---- SEO module (was optima-seo) ---- */
require_once OPTIMA_CORE_DIR . 'includes/seo/class-seo-head.php';
require_once OPTIMA_CORE_DIR . 'includes/seo/class-seo-setup.php';
require_once OPTIMA_CORE_DIR . 'includes/seo/class-seo-box.php';
require_once OPTIMA_CORE_DIR . 'includes/seo/class-seo-sitemap.php';
require_once OPTIMA_CORE_DIR . 'includes/seo/class-seo-oembed.php';
require_once OPTIMA_CORE_DIR . 'includes/seo/class-seo-hreflang.php';

/* ---- SEO global defaults, the unified Optima admin, and the update tracker ---- */
require_once OPTIMA_CORE_DIR . 'includes/seo/seo-settings.php';
require_once OPTIMA_CORE_DIR . 'includes/class-optima-admin-ui.php';
require_once OPTIMA_CORE_DIR . 'includes/admin.php';

/* ---- Site Sync: theme copy in, hand edits kept (docs/site-sync-spec.md) ---- */
require_once OPTIMA_CORE_DIR . 'includes/sync/class-optima-sync.php';
require_once OPTIMA_CORE_DIR . 'includes/sync/class-optima-sync-admin.php';
/* Change log: who changed what, when it wasn't an operator (operators only). */
require_once OPTIMA_CORE_DIR . 'includes/changelog/class-optima-changelog.php';
require_once OPTIMA_CORE_DIR . 'includes/changelog/class-optima-changelog-admin.php';
Optima_Changelog::init();
Optima_Changelog_Admin::init();
Optima_Sync::init();
Optima_Sync_Admin::init();
if ( defined( 'WP_CLI' ) && WP_CLI ) {
	require_once OPTIMA_CORE_DIR . 'includes/sync/class-optima-sync-cli.php';
	WP_CLI::add_command( 'optima sync', 'Optima_Sync_CLI' );
}

/*
 * Self-updating from the Optima Plugins releases. Replaces the earlier hosted
 * manifest tracker, which needed a JSON file hosted somewhere and never got
 * switched on.
 */
// Self-updating from the Optima Plugins releases (see includes/class-optima-plugin-updater.php).
// Loaded on plugins_loaded, after every plugin's own file, so older Optima
// plugins that still declare the shared class at load time always go first.
add_action(
	'plugins_loaded',
	static function () {
		require_once __DIR__ . '/includes/class-optima-plugin-updater.php';
		Optima_Plugin_Updater::boot( __DIR__ . '/optima-core.php', 'optima-core' );
	},
	0
);

/*
 * Registration pipeline. The registry is filled at init:1 via `optima_core_register`
 * that universal types and companion plugins hook, then post types, meta, and
 * bindings read from that one registry so they can never drift.
 */
add_action( 'init', function () { do_action( 'optima_core_register' ); }, 1 );
add_action( 'init', array( 'Optima_Core_Post_Types', 'register_all' ), 5 );
add_action( 'init', array( 'Optima_Core_Meta', 'register_all' ), 6 );
add_action( 'init', array( 'Optima_Core_Fieldsets', 'register_meta' ), 6 );
add_action( 'init', array( 'Optima_Core_Block_Bindings', 'register' ), 7 );

add_action( 'add_meta_boxes', array( 'Optima_Core_Meta_Box', 'add' ) );
add_action( 'save_post', array( 'Optima_Core_Meta_Box', 'save' ), 10, 2 );

/* Page fieldsets: the box, the save, the classic screen for matched posts. */
add_action( 'add_meta_boxes', array( 'Optima_Core_Fieldsets', 'add_boxes' ), 10, 2 );
add_action( 'save_post', array( 'Optima_Core_Fieldsets', 'save' ), 10, 2 );
add_filter( 'use_block_editor_for_post', array( 'Optima_Core_Fieldsets', 'use_block_editor' ), 10, 2 );

/* Shared admin assets: the media picker and section layout. */
add_action( 'admin_enqueue_scripts', array( 'Optima_Core_Controls', 'enqueue_admin' ) );

/* Settings storage (options + sanitizers). The UI is the unified Optima admin. */
add_action( 'admin_init', array( 'Optima_Core_Business', 'register_settings' ) );
add_action( 'admin_init', array( 'Optima_Core_SEO_Settings', 'register_settings' ) );
add_action( 'admin_init', array( 'Optima_Core_Settings_Groups', 'register_settings' ) );

/* Schema: render the graph, keep its cache fresh. */
add_action( 'wp_head', array( 'Optima_Schema', 'render' ), 20 );
add_action( 'save_post_testimonial', array( 'Optima_Schema', 'clear_cache' ) );
add_action( 'deleted_post', array( 'Optima_Schema', 'clear_cache' ) );
add_action( 'update_option_optima_business', array( 'Optima_Schema', 'clear_cache' ) );

/* SEO: head tags, the per-post box, sitemap tuning. */
Optima_SEO_Head::init();
Optima_SEO_Setup::init();
Optima_SEO_Box::init();
Optima_SEO_Sitemap::init();
Optima_SEO_OEmbed::init();
Optima_SEO_Hreflang::init();
Optima_Schema_Conflicts::init();

/* The one Optima menu (Dashboard / Business / SEO). */
Optima_Core_Admin::init();

/*
 * On activation register the types once and flush rewrite rules so archive/slug
 * routes resolve immediately. Deactivation flushes them back out.
 */
register_activation_hook( __FILE__, function () {
	do_action( 'optima_core_register' );
	Optima_Core_Post_Types::register_all();
	flush_rewrite_rules();
} );
register_deactivation_hook( __FILE__, function () {
	Optima_SEO_Sitemap::unschedule();
	wp_clear_scheduled_hook( Optima_Changelog::CRON ); // The Change log weekly email.
	flush_rewrite_rules();
} );
