<?php
/**
 * Storage for redirects and the missing-page log.
 *
 * Two small custom tables rather than one option. A redirect is looked up on
 * every front-end request. In an option, every change would rewrite (and
 * autoload) the whole list, which gets slow and racy once a rebuild imports a
 * few hundred old addresses. A table gives a single indexed lookup by a hash
 * of the old path.
 *
 * Writes are kept off the visitor's request where they can be. On a site with
 * a persistent object cache, redirect uses are counted in the cache and
 * written to the table in one pass (every OPTIMA_REDIRECTS_HIT_BATCH uses, on
 * the hourly tidy-up, and when the Redirects screen opens); without one, each
 * use is a single update by primary key, as before. A missing page is one
 * insert-or-update. The missing-page log is held to its cap by the hourly
 * tidy-up rather than by a count on every new address.
 *
 * @package Optima_Redirects
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Optima_Redirects_Store {

	const DB_VERSION   = '1';
	const DB_OPTION    = 'optima_redirects_db';
	const COUNT_OPTION = 'optima_redirects_count';
	const CACHE_GROUP  = 'optima_redirects';
	const HITS_GROUP   = 'optima_redirects_hits';

	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'optima_redirects';
	}

	public static function log_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'optima_404_log';
	}

	/** Create or update both tables. Runs on activation and after an update. */
	public static function install(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$charset = $wpdb->get_charset_collate();
		$table   = self::table();
		$log     = self::log_table();

		dbDelta(
			"CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			source varchar(255) NOT NULL,
			source_hash char(32) NOT NULL,
			target text NOT NULL,
			type smallint(3) unsigned NOT NULL DEFAULT 301,
			hits bigint(20) unsigned NOT NULL DEFAULT 0,
			last_hit datetime DEFAULT NULL,
			origin varchar(20) NOT NULL DEFAULT 'manual',
			created datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY source_hash (source_hash)
			) {$charset};"
		);
		dbDelta(
			"CREATE TABLE {$log} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			path varchar(255) NOT NULL,
			path_hash char(32) NOT NULL,
			hits bigint(20) unsigned NOT NULL DEFAULT 1,
			referrer varchar(255) NOT NULL DEFAULT '',
			internal tinyint(1) unsigned NOT NULL DEFAULT 0,
			first_seen datetime NOT NULL,
			last_seen datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY path_hash (path_hash),
			KEY last_seen (last_seen)
			) {$charset};"
		);
		update_option( self::DB_OPTION, self::DB_VERSION, false );
		self::refresh_count();
	}

	/** Install after an update that skipped the activation hook. */
	public static function maybe_install(): void {
		if ( self::DB_VERSION !== get_option( self::DB_OPTION ) ) {
			self::install();
		}
	}

	private static function now(): string {
		return current_time( 'mysql', true );
	}

	private static function hash( string $path ): string {
		return md5( $path );
	}

	/* ---------------------------------------------------------------- */
	/* Redirects                                                         */
	/* ---------------------------------------------------------------- */

	/** How many redirects exist. Autoloaded so a site with none skips every lookup. */
	public static function count(): int {
		return (int) get_option( self::COUNT_OPTION, 0 );
	}

	public static function refresh_count(): void {
		global $wpdb;
		$table = self::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$n = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
		update_option( self::COUNT_OPTION, $n, true );
		self::flush_cache();
	}

	/**
	 * Lookup cache keys carry a generation number, and clearing the cache
	 * starts a new generation. Many hosts' object caches (WP Engine's among
	 * them) cannot flush a single group, so a group flush left "no redirect
	 * here" answers in place for an hour: a redirect added on those sites did
	 * nothing until the hour ran out.
	 */
	private static function cache_key( string $hash ): string {
		$gen = wp_cache_get( 'gen', self::CACHE_GROUP );
		if ( false === $gen ) {
			$gen = self::flush_cache();
		}
		return $gen . ':' . $hash;
	}

	/** Forget every cached lookup. Returns the new generation. */
	private static function flush_cache(): string {
		$gen = str_replace( '.', '', uniqid( '', true ) );
		wp_cache_set( 'gen', $gen, self::CACHE_GROUP );
		return $gen;
	}

	/** The redirect for a normalized old path, or null. */
	public static function find( string $source ): ?object {
		global $wpdb;
		$hash  = self::hash( $source );
		$key   = self::cache_key( $hash );
		$found = wp_cache_get( $key, self::CACHE_GROUP, false, $hit );
		if ( $hit ) {
			return $found ? $found : null;
		}
		$table = self::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE source_hash = %s", $hash ) );
		wp_cache_set( $key, $row ? $row : 0, self::CACHE_GROUP, HOUR_IN_SECONDS );
		return $row ? $row : null;
	}

	public static function get( int $id ): ?object {
		global $wpdb;
		$table = self::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ) );
		return $row ? $row : null;
	}

	/**
	 * A page of redirects, most used first.
	 *
	 * @param string $search Part of an old or new address.
	 */
	public static function page( int $per_page, int $offset, string $search = '' ): array {
		global $wpdb;
		$table = self::table();
		if ( '' !== $search ) {
			$like = '%' . $wpdb->esc_like( $search ) . '%';
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			return (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE source LIKE %s OR target LIKE %s ORDER BY hits DESC, id DESC LIMIT %d OFFSET %d", $like, $like, $per_page, $offset ) );
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} ORDER BY hits DESC, id DESC LIMIT %d OFFSET %d", $per_page, $offset ) );
	}

	public static function search_count( string $search ): int {
		global $wpdb;
		$table = self::table();
		$like  = '%' . $wpdb->esc_like( $search ) . '%';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE source LIKE %s OR target LIKE %s", $like, $like ) );
	}

	/** Every redirect, for the CSV export. */
	public static function all(): array {
		global $wpdb;
		$table = self::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (array) $wpdb->get_results( "SELECT * FROM {$table} ORDER BY source ASC" );
	}

	/**
	 * Add a redirect, or update the one with the same old path (or this id).
	 *
	 * Inputs are already normalized by Optima_Redirects.
	 *
	 * @return int The redirect's id.
	 */
	public static function save( string $source, string $target, int $type, string $origin = 'manual', int $id = 0 ): int {
		global $wpdb;
		$table = self::table();
		$data  = array(
			'source'      => $source,
			'source_hash' => self::hash( $source ),
			'target'      => $target,
			'type'        => $type,
		);
		$existing = self::find( $source );
		if ( $existing && (int) $existing->id !== $id && $id ) {
			// Editing one row onto another row's old path: the edited row wins.
			self::delete( (int) $existing->id );
			$existing = null;
		}
		if ( ! $id && $existing ) {
			$id = (int) $existing->id;
		}
		if ( $id ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->update( $table, $data, array( 'id' => $id ) );
		} else {
			$data['origin']  = $origin;
			$data['created'] = self::now();
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->insert( $table, $data );
			$id = (int) $wpdb->insert_id;
		}
		self::refresh_count();
		self::log_delete_path( $source );
		return $id;
	}

	public static function delete( int $id ): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->delete( self::table(), array( 'id' => $id ) );
		self::refresh_count();
	}

	public static function delete_source( string $source ): void {
		$row = self::find( $source );
		if ( $row ) {
			self::delete( (int) $row->id );
		}
	}

	/** Point every redirect aimed at one internal path at another, so chains stay one hop. */
	public static function retarget( string $from_path, string $to_path ): void {
		global $wpdb;
		$table = self::table();
		foreach ( array( $from_path, trailingslashit( $from_path ) ) as $old ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->update( $table, array( 'target' => $to_path ), array( 'target' => $old ) );
		}
		self::flush_cache();
	}

	/** Uses counted in the cache before they are written: OPTIMA_REDIRECTS_HIT_BATCH, or the optima_redirects_hit_batch filter. */
	private static function hit_batch(): int {
		$n = defined( 'OPTIMA_REDIRECTS_HIT_BATCH' ) ? (int) OPTIMA_REDIRECTS_HIT_BATCH : 100;
		return max( 1, (int) apply_filters( 'optima_redirects_hit_batch', $n ) );
	}

	/**
	 * Count one use of a redirect.
	 *
	 * With a persistent object cache the use is added to a counter in the cache
	 * and written later by flush_hits(); a cache eviction can lose a few counts,
	 * which only affects the Hits column. Without one, it is one update by
	 * primary key. The cached lookup is left alone either way: it holds the
	 * target and type, which a use does not change.
	 */
	public static function hit( object $row ): void {
		global $wpdb;
		$id = (int) $row->id;
		if ( $id < 1 ) {
			return;
		}
		if ( wp_using_ext_object_cache() ) {
			wp_cache_add( 'n:' . $id, 0, self::HITS_GROUP );
			if ( false !== wp_cache_incr( 'n:' . $id, 1, self::HITS_GROUP ) ) {
				wp_cache_set( 't:' . $id, self::now(), self::HITS_GROUP );
				wp_cache_add( 'pending', 0, self::HITS_GROUP );
				if ( (int) wp_cache_incr( 'pending', 1, self::HITS_GROUP ) >= self::hit_batch() ) {
					self::flush_hits();
				}
				return;
			}
		}
		$table = self::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET hits = hits + 1, last_hit = %s WHERE id = %d", self::now(), $id ) );
	}

	/**
	 * Write the uses counted in the cache to the table: one update per redirect
	 * that was used, none for the rest. Safe to call any time; a lock in the
	 * cache keeps two runs from writing the same counts twice.
	 */
	public static function flush_hits(): void {
		global $wpdb;
		if ( ! wp_using_ext_object_cache() || ! wp_cache_add( 'lock', 1, self::HITS_GROUP, MINUTE_IN_SECONDS ) ) {
			return;
		}
		wp_cache_set( 'pending', 0, self::HITS_GROUP );
		$table = self::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$ids = array_map( 'intval', (array) $wpdb->get_col( "SELECT id FROM {$table}" ) );
		foreach ( array_chunk( $ids, 200 ) as $chunk ) {
			$keys = array();
			foreach ( $chunk as $id ) {
				$keys[] = 'n:' . $id;
			}
			$counts = wp_cache_get_multiple( $keys, self::HITS_GROUP );
			foreach ( $chunk as $id ) {
				$n = (int) ( $counts[ 'n:' . $id ] ?? 0 );
				if ( $n < 1 ) {
					continue;
				}
				// Take away only what is written, so uses counted meanwhile are kept.
				wp_cache_decr( 'n:' . $id, $n, self::HITS_GROUP );
				$last = wp_cache_get( 't:' . $id, self::HITS_GROUP );
				$last = is_string( $last ) && '' !== $last ? $last : self::now();
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET hits = hits + %d, last_hit = %s WHERE id = %d", $n, $last, $id ) );
			}
		}
		wp_cache_delete( 'lock', self::HITS_GROUP );
	}

	/* ---------------------------------------------------------------- */
	/* Missing pages                                                     */
	/* ---------------------------------------------------------------- */

	/**
	 * Record one request for a missing page: a single insert, or an update when
	 * the address is already listed. The log is held to its cap by the hourly
	 * tidy-up (log_trim), not on every new address.
	 *
	 * Once a missing page is linked from the site, it stays flagged and keeps
	 * that page as its referrer, so the broken link can be found.
	 *
	 * @param int $cap Unused; kept so older callers still work.
	 */
	public static function log_404( string $path, string $referrer, bool $internal, int $cap = 0 ): void {
		global $wpdb;
		unset( $cap );
		$log = self::log_table();
		$now = self::now();
		// Columns update left to right, so the referrer CASE still reads the stored internal flag.
		$sql = "INSERT INTO {$log} (path, path_hash, hits, referrer, internal, first_seen, last_seen) VALUES (%s, %s, 1, %s, %d, %s, %s)
			ON DUPLICATE KEY UPDATE hits = hits + 1, last_seen = VALUES(last_seen),
			referrer = CASE WHEN VALUES(referrer) = '' THEN referrer WHEN VALUES(internal) = 1 THEN VALUES(referrer) WHEN internal = 1 THEN referrer ELSE VALUES(referrer) END,
			internal = GREATEST(internal, VALUES(internal))";
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( $wpdb->prepare( $sql, $path, self::hash( $path ), $referrer, $internal ? 1 : 0, $now, $now ) );
	}

	/** Drop the oldest, least visited rows once the log passes its cap. */
	public static function log_trim( int $cap ): void {
		global $wpdb;
		$log = self::log_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$log}" );
		if ( $total <= $cap ) {
			return;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$ids = array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$log} ORDER BY last_seen ASC, hits ASC LIMIT %d", $total - $cap ) ) );
		if ( $ids ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( "DELETE FROM {$log} WHERE id IN (" . implode( ',', $ids ) . ')' );
		}
	}

	/**
	 * Missing pages, most visited first.
	 *
	 * @param bool $internal_only Only the ones a link on the site's own pages leads to.
	 */
	public static function log_list( int $limit, bool $internal_only = false ): array {
		global $wpdb;
		$log   = self::log_table();
		$where = $internal_only ? 'WHERE internal = 1' : '';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$log} {$where} ORDER BY hits DESC, last_seen DESC LIMIT %d", $limit ) );
	}

	public static function log_get( int $id ): ?object {
		global $wpdb;
		$log = self::log_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$log} WHERE id = %d", $id ) );
		return $row ? $row : null;
	}

	/** Distinct missing pages seen since a time (GMT, Y-m-d H:i:s). */
	public static function log_count_since( string $since, bool $internal_only = false ): int {
		global $wpdb;
		$log = self::log_table();
		$and = $internal_only ? ' AND internal = 1' : '';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$log} WHERE last_seen >= %s{$and}", $since ) );
	}

	public static function log_delete( int $id ): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->delete( self::log_table(), array( 'id' => $id ) );
	}

	public static function log_delete_path( string $path ): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->delete( self::log_table(), array( 'path_hash' => self::hash( $path ) ) );
	}

	/** True when a path is on the missing-page list. */
	public static function log_has( string $path ): bool {
		global $wpdb;
		$log = self::log_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (bool) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$log} WHERE path_hash = %s", self::hash( $path ) ) );
	}

	/** Keep a missing page on the list but stop calling it a broken link on the site. */
	public static function log_unflag( int $id ): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->update( self::log_table(), array( 'internal' => 0 ), array( 'id' => $id ) );
	}

	public static function log_clear(): void {
		global $wpdb;
		$log = self::log_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "DELETE FROM {$log}" );
	}
}
