<?php
/**
 * Site Sync: one updating system for every Optima site.
 *
 * The theme ships a optima-sync.json manifest that lists the copy it bundles
 * (page content, page fields, SEO, shared settings). This engine compares each
 * item three ways, the copy in the theme, the copy WordPress holds, and the
 * base recorded at the last sync, so it can tell an untouched page (safe to
 * update) from one somebody edited in WordPress (never overwritten silently).
 *
 * Writes are reviewed, locked, journaled before they start, compared again
 * right before each write, and can be undone. Since 1.6.1.0 the manifest can
 * also say how to create a page the site lacks (a page's "create" block) and
 * list Gravity Forms exports ("forms"), so a site owner never hand-imports a
 * WordPress import file or a form file again. The design started from the
 * reviewed content update built for a client site in September 2026 and is
 * generalized here so every site shares it. Spec: docs/site-sync-spec.md.
 *
 * @package Optima_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Optima_Sync {

	const MANIFEST  = 'optima-sync.json';
	const BASES     = 'optima_sync_bases';
	const JOURNAL   = 'optima_sync_journal';
	const LOCK      = 'optima_sync_lock';
	const SEEN      = 'optima_sync_seen';
	const SUMMARY   = 'optima_sync_summary';
	const META_FILE = '_optima_sync_file_hash';
	const META_MADE = '_optima_sync_created';

	/** States a row can be in. */
	const CURRENT  = 'current';   // WordPress already matches the theme.
	const UPDATE   = 'update';    // Theme changed, WordPress untouched: safe.
	const EDITED   = 'edited';    // Edited in WordPress, theme unchanged.
	const CONFLICT = 'conflict';  // Both changed, or an unknown saved value.
	const MISSING  = 'missing';   // The page, post or form the item names is not there.
	const OFF      = 'unavailable'; // A form row while Gravity Forms is not active: shown, never acted on.

	/** Post statuses a manifest may create a page with. */
	const CREATE_STATUSES = array( 'publish', 'draft' );

	/** Form keys that change on every save or install and never count as an edit. */
	const FORM_VOLATILE = array( 'id', 'date_created', 'date_updated', 'is_active', 'is_trash', 'version', 'nextFieldId' );

	/** Options a manifest may set. Anything else is ignored. */
	const ALLOWED_OPTIONS = array( 'blogname', 'blogdescription' );

	/** Array options a manifest may set one key of (themes add theirs with `optima_sync_option_keys`). */
	const ALLOWED_OPTION_KEYS = array( 'optima_business' );

	/** User fields a manifest may set, matched to a user by email. */
	const ALLOWED_USER_FIELDS = array( 'description' );

	public static function init() {
		add_action( 'admin_init', array( __CLASS__, 'maybe_auto_run' ), 20 );
		add_action( 'after_switch_theme', array( __CLASS__, 'forget_seen' ) );
		// A saved edit can change what needs review; recount on the next look.
		add_action( 'save_post', array( __CLASS__, 'forget_summary' ) );
		add_action( 'updated_option', array( __CLASS__, 'forget_summary_option' ) );
		add_action( 'gform_after_save_form', array( __CLASS__, 'forget_summary' ) );
		add_action( 'gform_post_form_trashed', array( __CLASS__, 'forget_summary' ) );
		add_action( 'gform_post_form_restored', array( __CLASS__, 'forget_summary' ) );
	}

	/* ---------- Manifest ---------- */

	/** Absolute path of the active theme's manifest. */
	public static function manifest_path() {
		return (string) apply_filters( 'optima_sync_manifest_path', trailingslashit( get_stylesheet_directory() ) . self::MANIFEST );
	}

	/**
	 * The parsed manifest, or null when the theme ships none.
	 * Companions can add items through the `optima_sync_manifest` filter.
	 *
	 * @return array<string,mixed>|null
	 */
	public static function manifest() {
		$path = self::manifest_path();
		$data = null;
		if ( is_readable( $path ) ) {
			$data = json_decode( (string) file_get_contents( $path ), true );
			if ( ! is_array( $data ) ) {
				$data = array( 'error' => 'optima-sync.json is not valid JSON.' );
			}
		}
		$data = apply_filters( 'optima_sync_manifest', $data );
		return is_array( $data ) ? $data : null;
	}

	/** Read a file the manifest points at, relative to the theme root. */
	private static function source( $relative ) {
		$root = realpath( get_stylesheet_directory() );
		$file = realpath( trailingslashit( get_stylesheet_directory() ) . ltrim( (string) $relative, '/' ) );
		// Only files inside the theme. A manifest never reads elsewhere.
		if ( ! $root || ! $file || 0 !== strpos( $file, $root . DIRECTORY_SEPARATOR ) || ! is_file( $file ) ) {
			return null;
		}
		return $file;
	}

	/**
	 * Every item the manifest declares, flattened to one shape.
	 *
	 * @return array{items: array<int,array<string,mixed>>, errors: string[]}
	 */
	public static function items() {
		$manifest = self::manifest();
		$items    = array();
		$errors   = array();
		if ( null === $manifest ) {
			return array( 'items' => array(), 'errors' => array() );
		}
		if ( ! empty( $manifest['error'] ) ) {
			return array( 'items' => array(), 'errors' => array( $manifest['error'] ) );
		}
		if ( 1 !== (int) ( $manifest['sync_version'] ?? 0 ) ) {
			return array( 'items' => array(), 'errors' => array( 'optima-sync.json needs "sync_version": 1.' ) );
		}

		foreach ( (array) ( $manifest['pages'] ?? array() ) as $page ) {
			$type = sanitize_key( $page['post_type'] ?? 'page' );
			$slug = (string) ( $page['slug'] ?? '' );
			if ( isset( $page['title'] ) ) {
				$items[] = array( 'kind' => 'post', 'post_type' => $type, 'slug' => $slug, 'field' => 'title', 'value' => (string) $page['title'] );
			}
			if ( isset( $page['source'] ) || isset( $page['content'] ) ) {
				$items[] = array( 'kind' => 'post', 'post_type' => $type, 'slug' => $slug, 'field' => 'content', 'source' => $page['source'] ?? null, 'value' => $page['content'] ?? null, 'previous' => $page['previous'] ?? array() );
			}
			if ( isset( $page['create'] ) ) {
				$create = self::create_spec( $page, $type, $slug );
				if ( is_string( $create ) ) {
					$errors[] = $create;
				} else {
					$items[] = $create;
				}
			}
		}
		foreach ( (array) ( $manifest['fields'] ?? array() ) as $f ) {
			$field = '';
			if ( isset( $f['field'] ) ) {
				$field = 'field:' . sanitize_key( $f['field'] );
			} elseif ( isset( $f['seo'] ) ) {
				$field = 'seo:' . ( 'title' === $f['seo'] ? 'title' : 'desc' );
			} elseif ( isset( $f['meta'] ) ) {
				// With "key", one key inside an array meta value, so the rest is left alone.
				$field = 'meta:' . (string) $f['meta'] . ( isset( $f['key'] ) ? '.' . sanitize_key( $f['key'] ) : '' );
			} elseif ( isset( $f['post_field'] ) && in_array( $f['post_field'], array( 'title', 'content', 'excerpt' ), true ) ) {
				$field = $f['post_field'];
			}
			if ( '' === $field ) {
				$errors[] = 'A field entry names no field, seo, meta or post_field.';
				continue;
			}
			$items[] = array(
				'kind'      => 'post',
				'post_type' => sanitize_key( $f['post_type'] ?? 'page' ),
				'slug'      => (string) ( $f['slug'] ?? '' ),
				'field'     => $field,
				'value'     => $f['value'] ?? null,
				'source'    => $f['source'] ?? null,
				'image'     => $f['image'] ?? null,
				'previous'  => $f['previous'] ?? array(),
			);
		}
		foreach ( (array) ( $manifest['settings'] ?? array() ) as $s ) {
			$items[] = array( 'kind' => 'setting', 'group' => sanitize_key( $s['group'] ?? '' ), 'key' => sanitize_key( $s['key'] ?? '' ), 'value' => $s['value'] ?? null, 'source' => $s['source'] ?? null, 'previous' => $s['previous'] ?? array() );
		}
		foreach ( (array) ( $manifest['options'] ?? array() ) as $o ) {
			$name = (string) ( $o['option'] ?? '' );
			$path = isset( $o['key'] ) ? self::path( (string) $o['key'] ) : '';
			$ok   = '' === $path ? in_array( $name, self::ALLOWED_OPTIONS, true ) : in_array( $name, self::option_keys(), true );
			if ( ! $ok || ( isset( $o['key'] ) && '' === $path ) ) {
				$errors[] = 'Option "' . $name . ( isset( $o['key'] ) ? '.' . $o['key'] : '' ) . '" is not one Site Sync may set.';
				continue;
			}
			$items[] = array( 'kind' => 'option', 'option' => $name, 'key' => $path, 'value' => $o['value'] ?? null, 'source' => $o['source'] ?? null, 'previous' => $o['previous'] ?? array() );
		}
		foreach ( (array) ( $manifest['users'] ?? array() ) as $u ) {
			$email = sanitize_email( (string) ( $u['email'] ?? '' ) );
			$field = (string) ( $u['field'] ?? 'description' );
			if ( ! is_email( $email ) || ! in_array( $field, self::ALLOWED_USER_FIELDS, true ) ) {
				$errors[] = 'User entry "' . ( $u['email'] ?? '' ) . '" needs an email and a field Site Sync may set (description).';
				continue;
			}
			$items[] = array( 'kind' => 'user', 'email' => strtolower( $email ), 'field' => $field, 'value' => $u['value'] ?? null, 'source' => $u['source'] ?? null, 'previous' => $u['previous'] ?? array() );
		}

		foreach ( (array) ( $manifest['forms'] ?? array() ) as $f ) {
			$file  = is_array( $f ) ? (string) ( $f['file'] ?? '' ) : '';
			$title = is_array( $f ) ? trim( (string) ( $f['title'] ?? '' ) ) : '';
			if ( '' === $file || '' === $title ) {
				$errors[] = 'A forms entry needs a "file" (the Gravity Forms export in the theme) and a "title".';
				continue;
			}
			$items[] = array( 'kind' => 'form', 'file' => $file, 'title' => $title );
		}

		foreach ( $items as &$item ) {
			$item['id']       = self::item_id( $item );
			$item['previous'] = array_map( 'strval', (array) ( $item['previous'] ?? array() ) );
			if ( 'form' === $item['kind'] ) {
				$file = self::source( $item['file'] );
				$form = $file ? self::export_form( (string) file_get_contents( $file ), $item['title'] ) : null;
				if ( ! $file ) {
					$errors[]       = 'File missing from the theme: ' . $item['file'];
					$item['broken'] = true;
				} elseif ( null === $form ) {
					$errors[]       = $item['file'] . ' holds no Gravity Forms form titled "' . $item['title'] . '".';
					$item['broken'] = true;
				}
				$item['form']  = $form;
				$item['theme'] = $form ? self::form_string( $form ) : '';
			} elseif ( ! empty( $item['image'] ) ) {
				$file = self::source( $item['image'] );
				if ( ! $file ) {
					$errors[]       = 'Image missing from the theme: ' . $item['image'];
					$item['broken'] = true;
				}
				$item['file']  = $file;
				$item['theme'] = $file ? 'img:' . hash_file( 'sha256', $file ) : '';
			} elseif ( null !== ( $item['source'] ?? null ) ) {
				$file = self::source( $item['source'] );
				if ( ! $file ) {
					$errors[]       = 'File missing from the theme: ' . $item['source'];
					$item['broken'] = true;
				}
				$item['theme'] = $file ? (string) file_get_contents( $file ) : '';
			} else {
				$item['theme'] = (string) ( $item['value'] ?? '' );
			}
		}
		unset( $item );
		// An item whose file is missing has no theme copy to compare or write. It
		// is reported as an error and left out, so it can never blank a page.
		$items = array_values(
			array_filter(
				$items,
				static function ( $item ) {
					return empty( $item['broken'] );
				}
			)
		);
		return array( 'items' => $items, 'errors' => array_values( array_unique( $errors ) ) );
	}

	/**
	 * A page's "create" block as an item, or the reason it cannot be one.
	 *
	 * @param array<string,mixed> $page The manifest page entry.
	 * @return array<string,mixed>|string
	 */
	private static function create_spec( array $page, $type, $slug ) {
		$c    = $page['create'];
		$name = '' === $slug ? '(no slug)' : $slug;
		if ( true === $c ) {
			$c = array();
		}
		if ( ! is_array( $c ) ) {
			return 'The "create" block for ' . $name . ' must be an object.';
		}
		$parts = array_map( 'sanitize_title', explode( '/', trim( $slug, '/' ) ) );
		if ( '' === $slug || in_array( '', $parts, true ) ) {
			return 'A page with a "create" block needs a slug.';
		}
		$leaf   = array_pop( $parts );
		$path   = implode( '/', $parts );
		$parent = trim( (string) ( $c['parent'] ?? '' ), '/' );
		if ( '' !== $parent && '' !== $path && $parent !== $path ) {
			return 'The "create" block for ' . $name . ' names parent "' . $parent . '", but its slug puts it under "' . $path . '".';
		}
		$status = (string) ( $c['status'] ?? 'draft' );
		if ( ! in_array( $status, self::CREATE_STATUSES, true ) ) {
			return 'The "create" block for ' . $name . ' has status "' . $status . '"; use "publish" or "draft".';
		}
		$template = (string) ( $c['template'] ?? '' );
		if ( '' !== $template && ( false !== strpos( $template, '..' ) || preg_match( '/[^A-Za-z0-9_\/.\-]/', $template ) ) ) {
			return 'The "create" block for ' . $name . ' names a page template that is not a plain theme file.';
		}
		$title = isset( $page['title'] ) ? (string) $page['title'] : (string) ( $c['title'] ?? '' );
		if ( '' === trim( $title ) ) {
			$title = ucwords( str_replace( '-', ' ', $leaf ) );
		}
		return array(
			'kind'       => 'create',
			'post_type'  => $type,
			'slug'       => $slug,
			'field'      => 'create',
			'leaf'       => $leaf,
			'parent'     => '' !== $path ? $path : $parent,
			'status'     => $status,
			'template'   => $template,
			'menu_order' => (int) ( $c['menu_order'] ?? 0 ),
			'author'     => (string) ( $c['author'] ?? '' ),
			'title'      => $title,
			'source'     => $page['source'] ?? null,
			'value'      => $page['content'] ?? '',
		);
	}

	/** A stable id for an item, e.g. "page/about#content". */
	public static function item_id( array $item ) {
		if ( 'setting' === $item['kind'] ) {
			return 'setting/' . $item['group'] . '#' . $item['key'];
		}
		if ( 'option' === $item['kind'] ) {
			return 'option#' . $item['option'] . ( '' !== ( $item['key'] ?? '' ) ? '.' . $item['key'] : '' );
		}
		if ( 'user' === $item['kind'] ) {
			return 'user/' . $item['email'] . '#' . $item['field'];
		}
		if ( 'form' === $item['kind'] ) {
			return 'form/' . sanitize_title( $item['title'] );
		}
		return $item['post_type'] . '/' . $item['slug'] . '#' . $item['field'];
	}

	/** A plain label for the dashboard. */
	public static function label( array $item ) {
		$field = preg_replace( '/^(field|seo|meta):/', '', $item['field'] ?? '' );
		if ( 'setting' === $item['kind'] ) {
			return 'Site setting: ' . $item['key'];
		}
		if ( 'option' === $item['kind'] ) {
			if ( '' !== ( $item['key'] ?? '' ) ) {
				$names = array( 'optima_business' => 'Business info' );
				return ( $names[ $item['option'] ] ?? $item['option'] ) . ': ' . str_replace( array( '.', '_' ), array( ' ', ' ' ), $item['key'] );
			}
			return 'blogname' === $item['option'] ? 'Site title' : 'Tagline';
		}
		if ( 'user' === $item['kind'] ) {
			return 'Author bio: ' . $item['email'];
		}
		if ( 'form' === $item['kind'] ) {
			return 'Form: ' . $item['title'];
		}
		if ( 'create' === $item['kind'] ) {
			return $item['slug'] . ': new ' . ( 'page' === $item['post_type'] ? 'page' : $item['post_type'] );
		}
		$prefix = 0 === strpos( (string) $item['field'], 'seo:' ) ? 'SEO ' : '';
		return $item['slug'] . ': ' . $prefix . str_replace( '_', ' ', (string) $field );
	}

	/* ---------- Reading what WordPress holds ---------- */

	/** The post an item names, or 0 when it is missing or ambiguous. */
	private static function post_id( array $item, array &$cache ) {
		$key = $item['post_type'] . '/' . $item['slug'];
		if ( ! isset( $cache[ $key ] ) ) {
			$cache[ $key ] = self::find_post( $item['post_type'], $item['slug'] );
		}
		return $cache[ $key ];
	}

	/** The one live post (not trashed) with this type and slug or page path, else 0. Never cached. */
	private static function find_post( $type, $slug ) {
		if ( '' === trim( (string) $slug, '/' ) ) {
			return 0;
		}
		if ( false !== strpos( $slug, '/' ) && is_post_type_hierarchical( $type ) ) {
			$page = get_page_by_path( trim( $slug, '/' ), OBJECT, $type );
			return $page && 'trash' !== $page->post_status && 'auto-draft' !== $page->post_status ? (int) $page->ID : 0;
		}
		$found = get_posts(
			array(
				'post_type'        => $type,
				'name'             => sanitize_title( $slug ),
				'post_status'      => array( 'publish', 'draft', 'pending', 'private', 'future' ),
				'numberposts'      => 2,
				'fields'           => 'ids',
				'suppress_filters' => true,
			)
		);
		return 1 === count( $found ) ? (int) $found[0] : 0;
	}

	/** A trashed post that had this slug (WordPress renames it on the way to the trash), else 0. */
	private static function trashed_post( $type, $leaf ) {
		$found = get_posts(
			array(
				'post_type'        => $type,
				'post_status'      => 'trash',
				'meta_key'         => '_wp_desired_post_slug', // phpcs:ignore WordPress.DB.SlowDBQuery
				'meta_value'       => $leaf,                   // phpcs:ignore WordPress.DB.SlowDBQuery
				'numberposts'      => 1,
				'fields'           => 'ids',
				'suppress_filters' => true,
			)
		);
		return $found ? (int) $found[0] : 0;
	}

	/**
	 * The user a new page is credited to: the manifest's author by email, then by
	 * login; else the person running the sync; else the first administrator.
	 *
	 * @return array{0:int,1:string} User id, and a note when the fallback was used.
	 */
	private static function author( $who ) {
		$who  = trim( (string) $who );
		$user = false;
		if ( '' !== $who ) {
			$user = is_email( $who ) ? get_user_by( 'email', $who ) : get_user_by( 'login', $who );
		}
		if ( $user ) {
			return array( (int) $user->ID, '' );
		}
		$id = get_current_user_id();
		if ( ! $id ) {
			$admins = get_users( array( 'role' => 'administrator', 'number' => 1, 'orderby' => 'ID', 'fields' => 'ID' ) );
			$id     = $admins ? (int) $admins[0] : 0;
		}
		$name = $id ? get_userdata( $id ) : null;
		$by   = $name ? $name->user_login : 'nobody';
		return array( (int) $id, '' === $who ? '' : $who . ' is not a user on this site, so it is credited to ' . $by . '.' );
	}

	/** The raw saved value for an item. */
	private static function read( array $item ) {
		if ( 'setting' === $item['kind'] ) {
			$values = get_option( Optima_Core_Settings_Groups::option_name( $item['group'] ), array() );
			return (string) ( is_array( $values ) ? ( $values[ $item['key'] ] ?? '' ) : '' );
		}
		if ( 'option' === $item['kind'] ) {
			if ( '' !== ( $item['key'] ?? '' ) ) {
				$value = self::dig( get_option( $item['option'], array() ), $item['key'] );
				return is_scalar( $value ) ? (string) $value : '';
			}
			return (string) get_option( $item['option'], '' );
		}
		if ( 'user' === $item['kind'] ) {
			return (string) get_user_meta( (int) $item['user_id'], $item['field'], true );
		}
		$id    = (int) $item['post_id'];
		$field = $item['field'];
		if ( in_array( $field, array( 'title', 'content', 'excerpt' ), true ) ) {
			$post = get_post( $id );
			return $post ? (string) $post->{'post_' . $field} : '';
		}
		$sub = self::meta_sub( $field );
		if ( '' !== $sub ) {
			$value = self::dig( get_post_meta( $id, self::meta_key( $field ), true ), $sub );
			return is_scalar( $value ) ? (string) $value : '';
		}
		return (string) get_post_meta( $id, self::meta_key( $field ), true );
	}

	/** Themes may let Site Sync set keys inside their own array options. */
	private static function option_keys() {
		return array_values( array_unique( array_merge( self::ALLOWED_OPTION_KEYS, (array) apply_filters( 'optima_sync_option_keys', array() ) ) ) );
	}

	/** A dotted key path, each part a plain key. '' when it is not one. */
	private static function path( $key ) {
		$parts = array_map( 'sanitize_key', explode( '.', $key ) );
		return in_array( '', $parts, true ) ? '' : implode( '.', $parts );
	}

	/**
	 * Find the slot a dotted path names. On a list, a part matches the item
	 * whose "slug" equals it, so "people.jordan.title" survives reordering.
	 *
	 * @return array{0:array<int,string|int>,1:bool} Concrete keys, and whether every part was found.
	 */
	private static function locate( $data, $path ) {
		$keys = array();
		foreach ( explode( '.', $path ) as $part ) {
			if ( ! is_array( $data ) ) {
				return array( $keys, false );
			}
			if ( ! array_key_exists( $part, $data ) && array_is_list( $data ) ) {
				foreach ( $data as $i => $entry ) {
					if ( is_array( $entry ) && ( $entry['slug'] ?? null ) === $part ) {
						$part = $i;
						break;
					}
				}
			}
			$keys[] = $part;
			if ( ! array_key_exists( $part, $data ) ) {
				return array( $keys, false );
			}
			$data = $data[ $part ];
		}
		return array( $keys, true );
	}

	/** The value at a dotted path, or null. */
	private static function dig( $data, $path ) {
		list( $keys, $found ) = self::locate( $data, $path );
		if ( ! $found ) {
			return null;
		}
		foreach ( $keys as $k ) {
			$data = $data[ $k ];
		}
		return $data;
	}

	/** Set the value at a dotted path. Missing parents are created as keys. */
	private static function plant( $data, $path, $value ) {
		$data         = is_array( $data ) ? $data : array();
		list( $keys ) = self::locate( $data, $path );
		$keys         = array_merge( $keys, array_slice( explode( '.', $path ), count( $keys ) ) );
		$last         = count( $keys ) - 1;
		$ref          = &$data;
		foreach ( $keys as $i => $k ) {
			if ( $i < $last && ( ! isset( $ref[ $k ] ) || ! is_array( $ref[ $k ] ) ) ) {
				$ref[ $k ] = array();
			}
			$ref = &$ref[ $k ];
		}
		$ref = $value;
		unset( $ref );
		return $data;
	}

	/** The key path inside an array meta field ("meta:_optima_team.role" gives "role"). */
	private static function meta_sub( $field ) {
		if ( 0 !== strpos( $field, 'meta:' ) ) {
			return '';
		}
		$dot = strpos( $field, '.' );
		return false === $dot ? '' : substr( $field, $dot + 1 );
	}

	private static function meta_key( $field ) {
		if ( 0 === strpos( $field, 'field:' ) ) {
			return optima_core_meta_key( substr( $field, 6 ) );
		}
		if ( 'seo:title' === $field ) {
			return OPTIMA_SEO_META_TITLE;
		}
		if ( 'seo:desc' === $field ) {
			return OPTIMA_SEO_META_DESC;
		}
		$key = substr( $field, 5 );
		$dot = strpos( $key, '.' );
		return false === $dot ? $key : substr( $key, 0, $dot );
	}

	/** What the saved value means for comparison: text as is, images by file. */
	private static function comparable( array $item, $raw ) {
		if ( empty( $item['image'] ) ) {
			return self::normalize( $raw );
		}
		$id = (int) $raw;
		if ( ! $id ) {
			return '';
		}
		$hash = (string) get_post_meta( $id, self::META_FILE, true );
		if ( '' === $hash ) {
			$path = get_attached_file( $id );
			$hash = $path && is_file( $path ) ? hash_file( 'sha256', $path ) : 'missing';
		}
		return 'img:' . $hash;
	}

	/** Line endings and trailing space never count as an edit. */
	public static function normalize( $text ) {
		return rtrim( str_replace( array( "\r\n", "\r" ), "\n", (string) $text ) );
	}

	private static function hash( $text ) {
		return hash( 'sha256', (string) $text );
	}

	/* ---------- The plan ---------- */

	/**
	 * Every item with its state. Read-only.
	 *
	 * @return array{rows: array<int,array<string,mixed>>, errors: string[], hash: string, manifest: bool}
	 */
	public static function plan() {
		$parsed = self::items();
		$bases  = get_option( self::BASES, array() );
		$bases  = is_array( $bases ) ? $bases : array();
		$cache  = array();
		$rows   = array();

		$creatable = array();
		foreach ( $parsed['items'] as $item ) {
			if ( 'create' === $item['kind'] ) {
				$creatable[ $item['post_type'] . '/' . trim( $item['slug'], '/' ) ] = true;
				if ( '' !== $item['parent'] && false === strpos( $item['slug'], '/' ) ) {
					$creatable[ $item['post_type'] . '/' . $item['parent'] . '/' . $item['leaf'] ] = true;
				}
			}
		}

		foreach ( $parsed['items'] as $item ) {
			if ( 'create' === $item['kind'] ) {
				$rows[] = self::plan_create( $item, $cache, $creatable );
				continue;
			}
			if ( 'form' === $item['kind'] ) {
				$rows[] = self::plan_form( $item, $bases );
				continue;
			}
			if ( 'post' === $item['kind'] ) {
				$item['post_id'] = self::post_id( $item, $cache );
				if ( ! $item['post_id'] ) {
					$item['state'] = self::MISSING;
					$rows[]        = $item;
					continue;
				}
			}
			if ( 'user' === $item['kind'] ) {
				$user            = get_user_by( 'email', $item['email'] );
				$item['user_id'] = $user ? (int) $user->ID : 0;
				if ( ! $item['user_id'] ) {
					$item['state'] = self::MISSING;
					$rows[]        = $item;
					continue;
				}
			}
			if ( 'setting' === $item['kind'] && ! class_exists( 'Optima_Core_Settings_Groups' ) ) {
				$item['state'] = self::MISSING;
				$rows[]        = $item;
				continue;
			}
			$item['raw']   = self::read( $item );
			$theme         = empty( $item['image'] ) ? self::normalize( $item['theme'] ) : $item['theme'];
			$db            = self::comparable( $item, $item['raw'] );
			$item['t']     = self::hash( $theme );
			$item['d']     = self::hash( $db );
			$base          = $bases[ $item['id'] ] ?? null;
			$item['state'] = self::state( $theme, $db, $item, $base );
			$item['synced'] = is_array( $base );
			$rows[]        = $item;
		}

		// A missing page the theme can create is one row (its "new page" row), not one per field.
		$new = array();
		foreach ( $rows as $row ) {
			if ( 'create' === $row['kind'] && self::MISSING === $row['state'] ) {
				$new[ $row['post_type'] . '/' . $row['slug'] ] = true;
			}
		}
		foreach ( $rows as &$row ) {
			if ( 'post' === $row['kind'] && self::MISSING === $row['state'] && isset( $new[ $row['post_type'] . '/' . $row['slug'] ] ) ) {
				$row['covered'] = true;
			}
		}
		unset( $row );

		$hash = self::hash(
			wp_json_encode(
				array(
					home_url(),
					array_map(
						static function ( $r ) {
							return array( $r['id'], $r['state'], $r['t'] ?? '', $r['d'] ?? '' );
						},
						$rows
					),
				)
			)
		);
		return array( 'rows' => $rows, 'errors' => $parsed['errors'], 'hash' => $hash, 'manifest' => null !== self::manifest() );
	}

	/**
	 * A "new page" row: current once the page exists, missing until then, with
	 * what creating it would do and anything that stops it.
	 *
	 * @param array<string,bool> $creatable Paths the manifest can create, "page/services".
	 */
	private static function plan_create( array $item, array &$cache, array $creatable ) {
		$item['post_id'] = self::post_id( $item, $cache );
		if ( ! $item['post_id'] && '' !== $item['parent'] && false === strpos( $item['slug'], '/' ) ) {
			$item['post_id'] = self::find_post( $item['post_type'], $item['parent'] . '/' . $item['leaf'] );
		}
		$theme         = self::normalize( $item['theme'] );
		$item['t']     = self::hash( wp_json_encode( array( $item['title'], $theme, $item['status'], $item['parent'], $item['template'], $item['menu_order'], $item['author'] ) ) );
		$item['d']     = '';
		$item['raw']   = '';
		$item['state'] = $item['post_id'] ? self::CURRENT : self::MISSING;
		if ( $item['post_id'] ) {
			return $item;
		}
		$item['trashed'] = self::trashed_post( $item['post_type'], $item['leaf'] );
		$item['d']       = (string) $item['trashed'];
		list( $item['author_id'], $item['author_note'] ) = self::author( $item['author'] );
		if ( ! post_type_exists( $item['post_type'] ) ) {
			$item['blocked'] = 'This site has no post type "' . $item['post_type'] . '".';
		} elseif ( '' !== $item['parent'] && ! is_post_type_hierarchical( $item['post_type'] ) ) {
			$item['blocked'] = 'Only pages can have a parent.';
		} elseif ( '' !== $item['parent'] && ! self::find_post( $item['post_type'], $item['parent'] ) && ! isset( $creatable[ $item['post_type'] . '/' . $item['parent'] ] ) ) {
			$item['blocked'] = 'Its parent page, /' . $item['parent'] . '/, is not on the site and the theme cannot create it.';
		} elseif ( '' !== $item['template'] && 'default' !== $item['template'] && ! isset( wp_get_theme()->get_page_templates( null, $item['post_type'] )[ $item['template'] ] ) ) {
			$item['blocked'] = 'The theme has no page template "' . $item['template'] . '".';
		} elseif ( ! $item['author_id'] ) {
			$item['blocked'] = 'No user on this site to credit the page to.';
		}
		return $item;
	}

	/**
	 * A form row. The three-way decision is the same as for copy, on a hash of the
	 * form with the keys that change on every save left out. A form never synced
	 * counts as current when every value the export sets matches, so a form once
	 * imported by hand from the same file is adopted, not flagged.
	 *
	 * @param array<string,array> $bases Bases recorded at the last sync.
	 */
	private static function plan_form( array $item, array $bases ) {
		$item['t'] = self::hash( $item['theme'] );
		$item['d'] = '';
		if ( ! self::forms_ready() ) {
			$item['state'] = self::OFF;
			return $item;
		}
		$base           = $bases[ $item['id'] ] ?? null;
		$item['synced'] = is_array( $base );
		$found          = self::find_form( $item['title'], is_array( $base ) ? (int) ( $base['form_id'] ?? 0 ) : 0 );
		if ( is_string( $found ) ) {
			$item['state']   = self::CONFLICT;
			$item['blocked'] = $found;
			return $item;
		}
		if ( ! $found ) {
			$item['state'] = self::MISSING;
			return $item;
		}
		$item['form_id'] = (int) $found['id'];
		$item['raw']     = $found;
		$item['d']       = self::hash( self::form_string( $found ) );
		$matches         = self::form_string( self::project( $found, $item['form'] ) ) === $item['theme'];
		if ( is_array( $base ) && isset( $base['t'], $base['d'] ) ) {
			$theme_moved = $item['t'] !== $base['t'];
			$db_moved    = $item['d'] !== $base['d'];
			if ( ! $theme_moved && ! $db_moved ) {
				$item['state'] = self::CURRENT;
			} elseif ( $theme_moved && ! $db_moved ) {
				$item['state'] = empty( $base['kept'] ) ? self::UPDATE : self::CONFLICT;
			} else {
				$item['state'] = $theme_moved ? self::CONFLICT : self::EDITED;
			}
			if ( self::CURRENT !== $item['state'] && $matches ) {
				$item['state'] = self::CURRENT; // The form already says what the theme says.
				$item['adopt'] = true;
			}
			if ( self::CURRENT === $item['state'] && (int) ( $base['form_id'] ?? 0 ) !== $item['form_id'] ) {
				$item['adopt'] = true;
			}
		} else {
			$item['state'] = $matches ? self::CURRENT : self::CONFLICT;
			$item['adopt'] = $matches;
		}
		return $item;
	}

	/**
	 * The three-way decision.
	 *
	 * @param array{t:string,d:string}|null $base Hashes recorded at the last sync.
	 */
	private static function state( $theme, $db, array $item, $base ) {
		if ( $theme === $db ) {
			return self::CURRENT;
		}
		$t = self::hash( $theme );
		$d = self::hash( $db );
		if ( is_array( $base ) && isset( $base['t'], $base['d'] ) ) {
			$theme_moved = $t !== $base['t'];
			$db_moved    = $d !== $base['d'];
			if ( ! $theme_moved && ! $db_moved ) {
				return self::CURRENT; // A WordPress copy someone chose to keep.
			}
			if ( $theme_moved && ! $db_moved ) {
				// A kept WordPress copy is never replaced automatically, even when the theme moves on.
				return empty( $base['kept'] ) ? self::UPDATE : self::CONFLICT;
			}
			return $theme_moved ? self::CONFLICT : self::EDITED;
		}
		// Never synced. Empty, or a copy an earlier release shipped, is safe.
		if ( '' === $db || in_array( $db, array_map( array( __CLASS__, 'normalize' ), $item['previous'] ), true ) ) {
			return self::UPDATE;
		}
		return self::CONFLICT;
	}

	/** Counts by state, cached for notices and health checks. */
	public static function summary( $refresh = false ) {
		$cached = get_transient( self::SUMMARY );
		if ( ! $refresh && is_array( $cached ) ) {
			return $cached;
		}
		$plan   = self::plan();
		$counts = array_fill_keys( array( self::CURRENT, self::UPDATE, self::EDITED, self::CONFLICT, self::MISSING, self::OFF ), 0 );
		foreach ( $plan['rows'] as $row ) {
			if ( empty( $row['covered'] ) ) {
				++$counts[ $row['state'] ];
			}
		}
		$summary = array(
			'manifest' => $plan['manifest'],
			'counts'   => $counts,
			'errors'   => count( $plan['errors'] ),
			'review'   => $counts[ self::EDITED ] + $counts[ self::CONFLICT ] + $counts[ self::MISSING ] + $counts[ self::OFF ] + count( $plan['errors'] ),
			'time'     => time(),
		);
		set_transient( self::SUMMARY, $summary, 12 * HOUR_IN_SECONDS );
		return $summary;
	}

	/* ---------- Writing ---------- */

	/**
	 * Apply the theme copy to the chosen rows.
	 *
	 * @param string   $hash The plan hash the person reviewed, or '' for automatic runs.
	 * @param string   $mode 'safe' (every UPDATE row), 'take' (chosen rows, theme copy wins), 'keep' (chosen rows, record the WordPress copy),
	 *                       'create' (chosen missing pages and forms are added) or 'restore' (chosen pages come back from the trash).
	 * @param string[] $ids  Rows for every mode but 'safe'.
	 * @param string   $who  'person', 'auto' or 'cli'.
	 * @return array{written:int,notes:string[]}|WP_Error
	 */
	public static function apply( $hash, $mode = 'safe', array $ids = array(), $who = 'person' ) {
		if ( 'person' === $who && ! current_user_can( 'manage_options' ) ) {
			return new WP_Error( 'forbidden', 'Not allowed.' );
		}
		if ( ! self::lock() ) {
			return new WP_Error( 'locked', 'Another sync is running. If one was interrupted, wait 15 minutes and try again.' );
		}
		try {
			$plan = self::plan();
			if ( 'person' === $who && ! hash_equals( $plan['hash'], (string) $hash ) ) {
				return new WP_Error( 'stale', 'Something changed since you looked. Review it again before syncing.' );
			}
			$journal = get_option( self::JOURNAL, array() );
			if ( in_array( $journal['state'] ?? '', array( 'applying', 'restoring' ), true ) ) {
				return new WP_Error( 'unfinished', 'The last sync did not finish. Undo it first, then sync again.' );
			}

			$pick = array();
			foreach ( $plan['rows'] as $row ) {
				if ( ! empty( $row['blocked'] ) || self::OFF === $row['state'] ) {
					continue;
				}
				$chosen = in_array( $row['id'], $ids, true );
				if ( 'safe' === $mode ) {
					$ok = self::UPDATE === $row['state'];
				} elseif ( 'create' === $mode ) {
					$ok = $chosen && self::MISSING === $row['state'] && ( 'form' === $row['kind'] || ( 'create' === $row['kind'] && empty( $row['trashed'] ) ) );
				} elseif ( 'restore' === $mode ) {
					$ok = $chosen && self::MISSING === $row['state'] && 'create' === $row['kind'] && ! empty( $row['trashed'] );
				} else {
					$ok = $chosen && self::MISSING !== $row['state'] && self::CURRENT !== $row['state'] && 'create' !== $row['kind'];
				}
				if ( $ok ) {
					$pick[] = $row;
				}
			}
			$bases = get_option( self::BASES, array() );
			$bases = is_array( $bases ) ? $bases : array();

			if ( 'safe' === $mode && self::adopt_current( $plan['rows'], $bases ) ) {
				update_option( self::BASES, $bases, false );
			}

			if ( ! $pick ) {
				return array( 'written' => 0, 'notes' => array() );
			}

			if ( 'keep' === $mode ) {
				foreach ( $pick as $row ) {
					$bases[ $row['id'] ] = array( 't' => $row['t'], 'd' => $row['d'], 'kept' => true ) + ( isset( $row['form_id'] ) ? array( 'form_id' => $row['form_id'] ) : array() );
				}
				update_option( self::BASES, $bases, false );
				self::summary( true );
				return array( 'written' => 0, 'notes' => array() );
			}

			// New pages go in parent first; a parent must be on the site or in this same batch.
			if ( 'create' === $mode ) {
				usort(
					$pick,
					static function ( $a, $b ) {
						return self::depth( $a ) <=> self::depth( $b );
					}
				);
				$coming = array();
				foreach ( $pick as $row ) {
					if ( 'create' !== $row['kind'] ) {
						continue;
					}
					if ( '' !== $row['parent'] && ! self::find_post( $row['post_type'], $row['parent'] ) && ! isset( $coming[ $row['post_type'] . '/' . $row['parent'] ] ) ) {
						return new WP_Error( 'parent', 'Tick its parent page too: ' . self::label( $row ) . ' goes under /' . $row['parent'] . '/. Nothing was changed.' );
					}
					$coming[ $row['post_type'] . '/' . self::path_of( $row ) ] = true;
				}
			}

			// Images are prepared before any text changes, so a failed import changes nothing.
			foreach ( $pick as &$row ) {
				$row['write'] = 'form' === $row['kind'] ? $row['form'] : $row['theme'];
				if ( ! empty( $row['image'] ) ) {
					$attachment = self::import_image( $row['file'] );
					if ( ! $attachment ) {
						return new WP_Error( 'media', 'Could not add the image for ' . self::label( $row ) . '. Nothing was changed.' );
					}
					$row['write'] = (string) $attachment;
				}
			}
			unset( $row );

			$journal = array(
				'state' => 'applying',
				'time'  => gmdate( 'c' ),
				'user'  => get_current_user_id(),
				'who'   => $who,
				'theme' => self::theme_key(),
				'rows'  => array_map( array( __CLASS__, 'journal_row' ), $pick ),
			);
			if ( ! self::save_journal( $journal ) ) {
				return new WP_Error( 'journal', 'Could not save the undo record. Nothing was changed.' );
			}

			$written = 0;
			$created = array();
			$notes   = array();
			$all     = array();
			foreach ( $plan['rows'] as $r ) {
				$all[ $r['id'] ] = $r;
			}
			foreach ( $pick as $i => $row ) {
				if ( 'create' === $row['kind'] ) {
					$done = 'restore' === $mode ? self::restore_page( $row ) : self::create_page( $row, $journal['time'] );
				} elseif ( 'form' === $row['kind'] ) {
					$done = self::write_form( $row );
				} else {
					$done = self::write( $row, $row['raw'], $row['write'] ) ? array() : false;
				}
				if ( false === $done ) {
					$journal['state'] = 'failed';
					self::save_journal( $journal );
					self::summary( true );
					return new WP_Error( 'write', 'Sync stopped at ' . self::label( $row ) . ', because it changed while syncing. Undo the last sync from the Approved copy screen, then review again.' );
				}
				$journal['rows'][ $i ] = array_merge( $journal['rows'][ $i ], $done );
				self::save_journal( $journal );
				if ( 'create' === $row['kind'] ) {
					if ( 'create' === $mode ) {
						$created[] = (int) $done['post_id'];
						// The page now holds the theme's title and copy: record them as synced.
						foreach ( array( 'title', 'content' ) as $field ) {
							$id = $row['post_type'] . '/' . $row['slug'] . '#' . $field;
							if ( isset( $all[ $id ] ) ) {
								$copy          = self::hash( self::normalize( 'title' === $field ? $row['title'] : $row['theme'] ) );
								$bases[ $id ] = array( 't' => $copy, 'd' => $copy );
							}
						}
						if ( ! empty( $row['author_note'] ) ) {
							$notes[] = $row['author_note'];
						}
					}
				} elseif ( 'form' === $row['kind'] ) {
					$bases[ $row['id'] ] = array( 't' => $row['t'], 'd' => $done['written_d'], 'form_id' => $done['form_id'] );
				} else {
					$bases[ $row['id'] ] = array( 't' => $row['t'], 'd' => $row['t'] );
				}
				++$written;
			}
			update_option( self::BASES, $bases, false );

			// The new pages' other fields (SEO, page fields, images) are empty, so they fill now, in the same undo record.
			if ( $created ) {
				foreach ( self::plan()['rows'] as $row ) {
					if ( 'post' !== $row['kind'] || self::UPDATE !== $row['state'] || ! in_array( (int) $row['post_id'], $created, true ) ) {
						continue;
					}
					$row['write'] = $row['theme'];
					if ( ! empty( $row['image'] ) ) {
						$attachment = self::import_image( $row['file'] );
						if ( ! $attachment ) {
							$notes[] = 'The image for ' . self::label( $row ) . ' could not be added; it waits for the next sync.';
							continue;
						}
						$row['write'] = (string) $attachment;
					}
					$journal['rows'][] = self::journal_row( $row );
					self::save_journal( $journal );
					if ( self::write( $row, $row['raw'], $row['write'] ) ) {
						$bases[ $row['id'] ] = array( 't' => $row['t'], 'd' => $row['t'] );
					}
				}
				update_option( self::BASES, $bases, false );
			}

			$journal['state'] = 'applied';
			self::save_journal( $journal );
			self::summary( true );
			do_action( 'optima_sync_applied', $journal['rows'], $who );
			return array( 'written' => $written, 'notes' => $notes );
		} finally {
			delete_option( self::LOCK );
		}
	}

	/** What the undo record keeps of a row. */
	private static function journal_row( array $row ) {
		$keep = array( 'id', 'kind', 'post_type', 'slug', 'leaf', 'parent', 'title', 'status', 'field', 'group', 'key', 'option', 'post_id', 'email', 'user_id', 'image', 'raw', 'write', 'form_id', 'trashed' );
		return array_intersect_key( $row, array_flip( $keep ) );
	}

	/** How deep a new page sits: "services/injury" is 2. */
	private static function depth( array $row ) {
		return 'create' === $row['kind'] ? substr_count( self::path_of( $row ), '/' ) + 1 : 0;
	}

	/** A new page's full path, "services/injury". */
	private static function path_of( array $row ) {
		return ( '' !== $row['parent'] ? $row['parent'] . '/' : '' ) . $row['leaf'];
	}

	/**
	 * Create one page, after checking right before the write that nobody made it
	 * meanwhile. The page carries a marker so an interrupted run can still be undone.
	 *
	 * @return array{post_id:int,write:string}|false
	 */
	private static function create_page( array $row, $stamp ) {
		$type = $row['post_type'];
		if ( self::find_post( $type, self::path_of( $row ) ) || self::find_post( $type, $row['slug'] ) || self::trashed_post( $type, $row['leaf'] ) ) {
			return false;
		}
		$parent = '' !== $row['parent'] ? self::find_post( $type, $row['parent'] ) : 0;
		if ( '' !== $row['parent'] && ! $parent ) {
			return false;
		}
		$args = array(
			'post_type'    => $type,
			'post_name'    => $row['leaf'],
			'post_title'   => wp_slash( $row['title'] ),
			'post_content' => wp_slash( $row['theme'] ),
			'post_status'  => $row['status'],
			'post_parent'  => $parent,
			'menu_order'   => (int) $row['menu_order'],
			'post_author'  => (int) $row['author_id'],
			'meta_input'   => array( self::META_MADE => $stamp ),
		);
		$id = wp_insert_post( $args, true );
		if ( is_wp_error( $id ) || ! $id ) {
			return false;
		}
		// The template was checked against the theme when the plan was made; set after the insert so a bad one never leaves half a page.
		if ( '' !== $row['template'] && 'default' !== $row['template'] ) {
			update_post_meta( $id, '_wp_page_template', $row['template'] );
		}
		$post = get_post( $id );
		if ( ! $post || $post->post_name !== $row['leaf'] || self::normalize( $post->post_content ) !== self::normalize( $row['theme'] ) || $post->post_title !== $row['title'] ) {
			wp_delete_post( $id, true ); // Ours, seconds old, and not what was asked for.
			return false;
		}
		return array( 'post_id' => (int) $id, 'write' => $row['theme'] );
	}

	/**
	 * Bring a trashed page back with the status it had, instead of creating a second one.
	 *
	 * @return array{post_id:int,modified:string}|false
	 */
	private static function restore_page( array $row ) {
		$id   = (int) $row['trashed'];
		$post = get_post( $id );
		if ( ! $post || 'trash' !== $post->post_status || self::find_post( $row['post_type'], $row['slug'] ) ) {
			return false;
		}
		// WordPress brings a page back as a draft unless told to use the status it had.
		$previous = function_exists( 'wp_untrash_post_set_previous_status' );
		if ( $previous ) {
			add_filter( 'wp_untrash_post_status', 'wp_untrash_post_set_previous_status', 10, 3 );
		}
		$done = wp_untrash_post( $id );
		if ( $previous ) {
			remove_filter( 'wp_untrash_post_status', 'wp_untrash_post_set_previous_status', 10 );
		}
		$post = get_post( $id );
		if ( ! $done || ! $post || 'trash' === $post->post_status ) {
			return false;
		}
		return array( 'post_id' => $id, 'modified' => $post->post_modified_gmt );
	}

	/**
	 * Add a missing form or update one nobody edited, keeping its id (and so its entries).
	 *
	 * @return array{form_id:int,written_d:string}|false
	 */
	private static function write_form( array $row ) {
		$form = $row['form'];
		unset( $form['id'] );
		if ( self::MISSING === $row['state'] ) {
			if ( null !== self::find_form( $row['title'], 0 ) ) {
				return false; // Someone added it meanwhile.
			}
			$id = GFAPI::add_form( $form );
		} else {
			$now = self::find_form( $row['title'], (int) $row['form_id'] );
			if ( ! is_array( $now ) || (int) $now['id'] !== (int) $row['form_id'] || self::hash( self::form_string( $now ) ) !== $row['d'] ) {
				return false;
			}
			$id         = (int) $row['form_id'];
			$form['id'] = $id;
			$result     = GFAPI::update_form( $form, $id );
			$id         = is_wp_error( $result ) || false === $result ? 0 : $id;
		}
		if ( is_wp_error( $id ) || ! $id ) {
			return false;
		}
		$saved = self::find_form( $row['title'], (int) $id );
		if ( ! is_array( $saved ) || (int) $saved['id'] !== (int) $id ) {
			return false;
		}
		return array( 'form_id' => (int) $id, 'written_d' => self::hash( self::form_string( $saved ) ) );
	}

	/* ---------- Forms ---------- */

	/** Whether Gravity Forms is there to read and write forms. */
	public static function forms_ready() {
		return (bool) apply_filters( 'optima_sync_forms_ready', class_exists( 'GFAPI' ) );
	}

	/**
	 * The form with this title (or, first, the form id recorded at the last sync), as a plain array.
	 *
	 * @return array<string,mixed>|string|null The form, a message when two forms share the title, or null.
	 */
	private static function find_form( $title, $id ) {
		if ( $id ) {
			$form = self::plain( GFAPI::get_form( $id ) );
			if ( is_array( $form ) && ! empty( $form['id'] ) && empty( $form['is_trash'] ) ) {
				return $form;
			}
		}
		$hits = array();
		foreach ( (array) GFAPI::get_forms( null, false ) as $form ) {
			$form = self::plain( $form );
			if ( is_array( $form ) && empty( $form['is_trash'] ) && (string) ( $form['title'] ?? '' ) === $title ) {
				$hits[] = $form;
			}
		}
		if ( count( $hits ) > 1 ) {
			return 'Two forms on this site are named "' . $title . '". Rename or trash one in Gravity Forms, then come back.';
		}
		return $hits ? $hits[0] : null;
	}

	/** Gravity Forms hands back field objects; work with plain arrays. */
	private static function plain( $data ) {
		return is_array( $data ) || is_object( $data ) ? json_decode( (string) wp_json_encode( $data ), true ) : null;
	}

	/**
	 * The form named $title from a Gravity Forms export file (one form, a list, or
	 * the {"0": form, "version": ...} shape), with the manifest title kept exactly.
	 *
	 * @return array<string,mixed>|null
	 */
	private static function export_form( $json, $title ) {
		$data = json_decode( (string) $json, true );
		if ( ! is_array( $data ) ) {
			return null;
		}
		$forms = isset( $data['fields'] ) ? array( $data ) : array_values(
			array_filter(
				$data,
				static function ( $v ) {
					return is_array( $v ) && isset( $v['fields'] ) && is_array( $v['fields'] );
				}
			)
		);
		$pick = null;
		foreach ( $forms as $form ) {
			if ( (string) ( $form['title'] ?? '' ) === $title ) {
				$pick = $form;
				break;
			}
		}
		if ( null === $pick && 1 === count( $forms ) ) {
			$pick = $forms[0];
		}
		if ( null === $pick ) {
			return null;
		}
		$pick['title'] = $title;
		unset( $pick['id'] );
		return $pick;
	}

	/** One comparable string for a form: volatile keys out, keys sorted, scalars as text. */
	private static function form_string( $form ) {
		return (string) wp_json_encode( self::form_clean( $form, true ) );
	}

	private static function form_clean( $data, $top = false ) {
		if ( is_object( $data ) ) {
			$data = self::plain( $data );
		}
		if ( ! is_array( $data ) ) {
			return is_bool( $data ) ? ( $data ? '1' : '' ) : ( null === $data ? '' : (string) $data );
		}
		$out = array();
		foreach ( $data as $k => $v ) {
			if ( ( $top && in_array( $k, self::FORM_VOLATILE, true ) ) || 'formId' === $k ) {
				continue;
			}
			$out[ $k ] = self::form_clean( $v );
		}
		if ( ! array_is_list( $out ) ) {
			ksort( $out, SORT_STRING );
		}
		return $out;
	}

	/**
	 * The saved form cut down to the keys the export sets. Lists of a different
	 * length are kept whole, so an added or removed field still counts.
	 */
	private static function project( $db, $theme ) {
		if ( ! is_array( $theme ) || ! is_array( $db ) ) {
			return $db;
		}
		if ( array_is_list( $theme ) ) {
			if ( ! array_is_list( $db ) || count( $db ) !== count( $theme ) ) {
				return $db;
			}
		}
		$out = array();
		foreach ( $theme as $k => $v ) {
			$out[ $k ] = array_key_exists( $k, $db ) ? self::project( $db[ $k ], $v ) : null;
		}
		return $out;
	}

	/**
	 * Record a base for every row that already matches the theme but has no
	 * base, or an outdated one. Without it, a site that adopts Site Sync while
	 * already matching would see every later theme change as "needs a
	 * decision" instead of a safe update. A kept copy the repo has since
	 * caught up with stops being kept.
	 *
	 * @param array<int,array<string,mixed>> $rows  Plan rows.
	 * @param array<string,array>            $bases Bases, updated in place.
	 * @return bool True when any base changed.
	 */
	private static function adopt_current( array $rows, array &$bases ) {
		$changed = false;
		foreach ( $rows as $row ) {
			if ( 'form' === $row['kind'] ) {
				if ( self::CURRENT === $row['state'] && ! empty( $row['adopt'] ) ) {
					$bases[ $row['id'] ] = array( 't' => $row['t'], 'd' => $row['d'], 'form_id' => $row['form_id'] );
					$changed             = true;
				}
				continue;
			}
			if ( 'create' === $row['kind'] || self::CURRENT !== $row['state'] || ! isset( $row['t'], $row['d'] ) || $row['t'] !== $row['d'] ) {
				continue; // Only identical copies; a kept WordPress copy keeps its base.
			}
			$base = $bases[ $row['id'] ] ?? null;
			if ( is_array( $base ) && $row['t'] === ( $base['t'] ?? '' ) && $row['d'] === ( $base['d'] ?? '' ) && empty( $base['kept'] ) ) {
				continue;
			}
			$bases[ $row['id'] ] = array( 't' => $row['t'], 'd' => $row['d'] );
			$changed             = true;
		}
		return $changed;
	}

	/**
	 * Put back what the last sync replaced. A field edited since is left alone.
	 * A page the sync created goes to the trash, a page it restored goes back to
	 * the trash, a form it added is deactivated and trashed, and a form it
	 * updated gets its saved earlier version back, each only if nobody changed
	 * it since.
	 *
	 * @return array{restored:int,skipped:string[]}|WP_Error
	 */
	public static function undo( $who = 'person' ) {
		if ( 'person' === $who && ! current_user_can( 'manage_options' ) ) {
			return new WP_Error( 'forbidden', 'Not allowed.' );
		}
		$journal = get_option( self::JOURNAL, array() );
		if ( ! in_array( $journal['state'] ?? '', array( 'applied', 'failed', 'applying' ), true ) ) {
			return new WP_Error( 'nothing', 'There is no sync to undo.' );
		}
		if ( ! self::lock() ) {
			return new WP_Error( 'locked', 'Another sync is running. Try again in a few minutes.' );
		}
		try {
			$journal['state'] = 'restoring';
			self::save_journal( $journal );
			$bases    = get_option( self::BASES, array() );
			$bases    = is_array( $bases ) ? $bases : array();
			$restored = 0;
			$skipped  = array();
			$gone     = array(); // Pages this undo trashed: their fields go with them.
			$kept     = array(); // Pages edited since they were created: left whole.

			foreach ( $journal['rows'] as $row ) {
				if ( 'create' !== $row['kind'] ) {
					continue;
				}
				$result = self::undo_page( $row, (string) $journal['time'] );
				if ( null === $result ) {
					continue;
				}
				$post_id = (int) ( $row['post_id'] ?? 0 );
				if ( $result ) {
					$gone[] = (int) $result;
					foreach ( array( 'title', 'content' ) as $field ) {
						unset( $bases[ $row['post_type'] . '/' . $row['slug'] . '#' . $field ] );
					}
					++$restored;
				} else {
					$kept[]    = $post_id;
					$skipped[] = self::label( $row );
				}
			}

			foreach ( $journal['rows'] as $row ) {
				if ( 'create' === $row['kind'] ) {
					continue;
				}
				if ( 'form' === $row['kind'] ) {
					$result = self::undo_form( $row );
					if ( null === $result ) {
						continue;
					}
					if ( $result ) {
						unset( $bases[ $row['id'] ] );
						++$restored;
					} else {
						$skipped[] = self::label( $row );
					}
					continue;
				}
				if ( 'post' === $row['kind'] && in_array( (int) $row['post_id'], $gone, true ) ) {
					unset( $bases[ $row['id'] ] );
					continue;
				}
				if ( 'post' === $row['kind'] && in_array( (int) $row['post_id'], $kept, true ) ) {
					continue;
				}
				$now = self::read( $row );
				if ( self::normalize( $now ) === self::normalize( $row['raw'] ) ) {
					continue; // Never written, or already back.
				}
				if ( self::normalize( $now ) !== self::normalize( $row['write'] ) || ! self::write( $row, $now, $row['raw'] ) ) {
					$skipped[] = self::label( $row );
					continue;
				}
				unset( $bases[ $row['id'] ] );
				++$restored;
			}
			update_option( self::BASES, $bases, false );
			$journal['state'] = 'restored';
			self::save_journal( $journal );
			self::summary( true );
			return array( 'restored' => $restored, 'skipped' => array_values( array_unique( $skipped ) ) );
		} finally {
			delete_option( self::LOCK );
		}
	}

	/**
	 * Undo one created or restored page.
	 *
	 * @return int|false|null The trashed post id, false when it changed since (left alone), null when there is nothing to do.
	 */
	private static function undo_page( array $row, $stamp ) {
		$id = (int) ( $row['post_id'] ?? 0 );
		if ( ! $id && empty( $row['trashed'] ) ) {
			// Interrupted between the write and the record: find it by its marker.
			$found = get_posts(
				array(
					'post_type'        => $row['post_type'],
					'name'             => $row['leaf'],
					'post_status'      => 'any',
					'meta_key'         => self::META_MADE, // phpcs:ignore WordPress.DB.SlowDBQuery
					'meta_value'       => $stamp,          // phpcs:ignore WordPress.DB.SlowDBQuery
					'numberposts'      => 1,
					'fields'           => 'ids',
					'suppress_filters' => true,
				)
			);
			$id = $found ? (int) $found[0] : 0;
		}
		$post = $id ? get_post( $id ) : null;
		if ( ! $post || 'trash' === $post->post_status ) {
			return null;
		}
		if ( ! empty( $row['trashed'] ) ) {
			if ( ! isset( $row['modified'] ) || $post->post_modified_gmt !== $row['modified'] ) {
				return false;
			}
		} elseif ( self::normalize( $post->post_content ) !== self::normalize( (string) ( $row['write'] ?? '' ) ) || $post->post_title !== $row['title'] ) {
			return false;
		}
		return wp_trash_post( $id ) ? $id : false;
	}

	/**
	 * Undo one form write.
	 *
	 * @return bool|null True when undone, false when the form changed since, null when there is nothing to do.
	 */
	private static function undo_form( array $row ) {
		$id = (int) ( $row['form_id'] ?? 0 );
		if ( ! $id || ! isset( $row['written_d'] ) || ! self::forms_ready() ) {
			return null;
		}
		$now = self::plain( GFAPI::get_form( $id ) );
		if ( ! is_array( $now ) || empty( $now['id'] ) || ! empty( $now['is_trash'] ) ) {
			return null;
		}
		if ( self::hash( self::form_string( $now ) ) !== $row['written_d'] ) {
			return false;
		}
		if ( empty( $row['raw'] ) || ! is_array( $row['raw'] ) ) {
			// Added by the sync: switch it off and move it to the forms trash. Its entries stay.
			GFAPI::update_form_property( $id, 'is_active', 0 );
			GFAPI::update_form_property( $id, 'is_trash', 1 );
			return true;
		}
		$old       = $row['raw'];
		$old['id'] = $id;
		$result    = GFAPI::update_form( $old, $id );
		return ! is_wp_error( $result ) && false !== $result;
	}

	/** Compare and write one field. False when it changed underneath us. */
	private static function write( array $row, $expected, $value ) {
		if ( self::normalize( self::read( $row ) ) !== self::normalize( $expected ) ) {
			return false;
		}
		if ( 'setting' === $row['kind'] ) {
			$name   = Optima_Core_Settings_Groups::option_name( $row['group'] );
			$values = get_option( $name, array() );
			$values = is_array( $values ) ? $values : array();
			$values[ $row['key'] ] = $value;
			update_option( $name, $values );
		} elseif ( 'option' === $row['kind'] && '' !== ( $row['key'] ?? '' ) ) {
			update_option( $row['option'], self::plant( get_option( $row['option'], array() ), $row['key'], $value ) );
		} elseif ( 'option' === $row['kind'] ) {
			update_option( $row['option'], $value );
		} elseif ( 'user' === $row['kind'] ) {
			update_user_meta( (int) $row['user_id'], $row['field'], wp_slash( $value ) );
		} elseif ( in_array( $row['field'], array( 'title', 'content', 'excerpt' ), true ) ) {
			$result = wp_update_post(
				array(
					'ID'                     => (int) $row['post_id'],
					'post_' . $row['field'] => wp_slash( $value ),
				),
				true
			);
			if ( is_wp_error( $result ) ) {
				return false;
			}
		} elseif ( '' !== self::meta_sub( $row['field'] ) ) {
			$key = self::meta_key( $row['field'] );
			update_post_meta( (int) $row['post_id'], $key, wp_slash( self::plant( get_post_meta( (int) $row['post_id'], $key, true ), self::meta_sub( $row['field'] ), $value ) ) );
		} else {
			update_post_meta( (int) $row['post_id'], self::meta_key( $row['field'] ), wp_slash( $value ) );
		}
		return self::normalize( self::read( $row ) ) === self::normalize( $value );
	}

	/** Add a theme image to the media library once, tagged with its file hash. */
	private static function import_image( $file ) {
		$hash     = hash_file( 'sha256', $file );
		$existing = get_posts(
			array(
				'post_type'   => 'attachment',
				'post_status' => 'inherit',
				'meta_key'    => self::META_FILE, // phpcs:ignore WordPress.DB.SlowDBQuery
				'meta_value'  => $hash,           // phpcs:ignore WordPress.DB.SlowDBQuery
				'numberposts' => 1,
				'fields'      => 'ids',
			)
		);
		if ( $existing ) {
			return (int) $existing[0];
		}
		require_once ABSPATH . 'wp-admin/includes/image.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		$tmp = wp_tempnam( wp_basename( $file ) );
		if ( ! $tmp || ! copy( $file, $tmp ) ) {
			return 0;
		}
		$id = media_handle_sideload( array( 'name' => wp_basename( $file ), 'tmp_name' => $tmp ), 0 );
		if ( is_wp_error( $id ) ) {
			wp_delete_file( $tmp );
			return 0;
		}
		update_post_meta( $id, self::META_FILE, $hash );
		return (int) $id;
	}

	private static function save_journal( array $journal ) {
		update_option( self::JOURNAL, $journal, false );
		return get_option( self::JOURNAL ) === $journal;
	}

	/** One sync at a time. A lock older than 15 minutes is treated as abandoned. */
	private static function lock() {
		$old = get_option( self::LOCK );
		if ( is_array( $old ) && ( $old['expires'] ?? 0 ) < time() ) {
			delete_option( self::LOCK );
		}
		return add_option( self::LOCK, array( 'expires' => time() + 900 ), '', false );
	}

	/* ---------- Automatic runs ---------- */

	/**
	 * The active theme, its version, its manifest and this engine's version, as
	 * one key. A Core update that reads more of the manifest runs again too.
	 */
	public static function theme_key() {
		$theme = wp_get_theme();
		$path  = self::manifest_path();
		return get_stylesheet() . '@' . $theme->get( 'Version' ) . '#' . ( is_readable( $path ) ? md5_file( $path ) : 'none' ) . '/core-' . ( defined( 'OPTIMA_CORE_VERSION' ) ? OPTIMA_CORE_VERSION : '0' );
	}

	public static function forget_summary() {
		delete_transient( self::SUMMARY );
	}

	public static function forget_summary_option( $option ) {
		if ( in_array( $option, self::ALLOWED_OPTIONS, true ) || in_array( $option, self::option_keys(), true ) || 0 === strpos( (string) $option, 'optima_group_' ) ) {
			delete_transient( self::SUMMARY );
		}
	}

	public static function forget_seen() {
		delete_option( self::SEEN );
	}

	/**
	 * After a theme update, sync the rows nobody has touched in WordPress and
	 * leave everything else for review. Runs once per theme version.
	 */
	public static function maybe_auto_run() {
		if ( wp_doing_ajax() || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$key = self::theme_key();
		if ( get_option( self::SEEN ) === $key ) {
			return;
		}
		update_option( self::SEEN, $key, false );
		if ( null === self::manifest() || ! apply_filters( 'optima_sync_auto', true ) ) {
			self::summary( true );
			return;
		}
		$result = self::apply( '', 'safe', array(), 'auto' );
		if ( ! is_wp_error( $result ) ) {
			set_transient( 'optima_sync_last_auto', array( 'written' => $result['written'], 'time' => time() ), DAY_IN_SECONDS );
		}
		self::summary( true );
	}
}
