<?php
/**
 * Core plugin functions for Quick Search Replace.
 *
 * @package QuickSearchReplace
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * Get a cached value for this plugin (with in-request fallback).
 *
 * @param string $key Cache key.
 * @return mixed Cached value or false if not found.
 */
function qsrdb_cache_get( $key ) {
	static $local = array();
	if ( array_key_exists( $key, $local ) ) {
		return $local[ $key ];
	}
	$val = wp_cache_get( $key, 'qsrdb' );
	if ( false !== $val ) {
		$local[ $key ] = $val;
	}
	return $val;
}

/**
 * Set a cached value for this plugin (with in-request fallback).
 *
 * @param string $key   Cache key.
 * @param mixed  $value Value.
 * @param int    $ttl   Time to live in seconds.
 * @return void
 */
function qsrdb_cache_set( $key, $value, $ttl = 300 ) {
	static $local = array();
	$local[ $key ] = $value;
	wp_cache_set( $key, $value, 'qsrdb', (int) $ttl );
}

/**
 * Get all tables in the database, respecting multisite installations.
 * Results are cached to satisfy WPCS and improve performance.
 *
 * @return array List of table names.
 */
function qsrdb_get_all_tables() {
	global $wpdb;

	$cache_key = 'tables_' . ( is_multisite() ? 'ms_' : 's_' ) . md5( (string) $wpdb->base_prefix );
	$cached    = qsrdb_cache_get( $cache_key );
	if ( false !== $cached && is_array( $cached ) ) {
		return $cached;
	}

	$tables = array();

	if ( is_multisite() ) {
		// Get all blog IDs (WP function; internally cached where persistent object cache exists).
		$blog_ids = get_sites( array( 'fields' => 'ids' ) );

		foreach ( $blog_ids as $blog_id ) {
			$prefix    = $wpdb->get_blog_prefix( $blog_id );
			$key_pref  = 'tables_prefix_' . md5( $prefix );
			$t_from_ca = qsrdb_cache_get( $key_pref );

			if ( false !== $t_from_ca && is_array( $t_from_ca ) ) {
				$tables = array_merge( $tables, $t_from_ca );
			} else {
				// Use prepare + esc_like for the LIKE pattern.
				$like = $wpdb->esc_like( $prefix ) . '%';
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- SHOW TABLES is the only way to enumerate DB tables; we cache the result right after.
				$blog_tables = (array) $wpdb->get_col(
					$wpdb->prepare(
						'SHOW TABLES LIKE %s',
						$like
					)
				);
				qsrdb_cache_set( $key_pref, $blog_tables, 300 );
				$tables = array_merge( $tables, $blog_tables );
			}
		}

		// Add global tables once per base_prefix.
		$key_global = 'tables_global_' . md5( (string) $wpdb->base_prefix );
		$t_from_g   = qsrdb_cache_get( $key_global );
		if ( false !== $t_from_g && is_array( $t_from_g ) ) {
			$tables = array_merge( $tables, $t_from_g );
		} else {
			$global_like = $wpdb->esc_like( $wpdb->base_prefix ) . '%';
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- SHOW TABLES is the only way to enumerate DB tables; we cache the result right after.
			$global = (array) $wpdb->get_col(
				$wpdb->prepare(
					'SHOW TABLES LIKE %s',
					$global_like
				)
			);
			qsrdb_cache_set( $key_global, $global, 300 );
			$tables = array_merge( $tables, $global );
		}

		$tables = array_values( array_unique( $tables ) );
	} else {
		$key_single = 'tables_single_' . md5( (string) $wpdb->prefix );
		$t_from_s   = qsrdb_cache_get( $key_single );
		if ( false !== $t_from_s && is_array( $t_from_s ) ) {
			$tables = $t_from_s;
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- SHOW TABLES is the only way to enumerate DB tables; we cache the result right after.
			$tables = (array) $wpdb->get_col( 'SHOW TABLES' );
			qsrdb_cache_set( $key_single, $tables, 300 );
		}
	}

	// Cache the final list too.
	qsrdb_cache_set( $cache_key, $tables, 300 );
	return $tables;
}

/**
 * Recursively search and replace a string (or array of strings) in a subject.
 * Handles serialized data safely by unserializing and reserializing.
 *
 * @param string|array $search            Search string or array of strings.
 * @param string|array $replace           Replacement string or array of strings.
 * @param mixed        $subject           String, array, or object.
 * @param bool         $case_insensitive  If true, case-insensitive replace.
 *
 * @return mixed
 */
function qsrdb_recursive_unserialize_replace( $search, $replace, $subject, $case_insensitive = false ) {
	if ( is_string( $subject ) ) {
		if ( is_serialized( $subject ) ) {
			$unserialized = @unserialize( $subject );
			$unserialized = qsrdb_recursive_unserialize_replace( $search, $replace, $unserialized, $case_insensitive );
			return serialize( $unserialized );
		}
		return $case_insensitive ? str_ireplace( $search, $replace, $subject ) : str_replace( $search, $replace, $subject );
	}

	if ( is_array( $subject ) ) {
		$new_array = array();
		foreach ( $subject as $key => $value ) {
			$new_array[ $key ] = qsrdb_recursive_unserialize_replace( $search, $replace, $value, $case_insensitive );
		}
		return $new_array;
	}

	if ( is_object( $subject ) ) {
		$new_object = clone $subject;
		foreach ( $subject as $key => $value ) {
			$new_object->$key = qsrdb_recursive_unserialize_replace( $search, $replace, $value, $case_insensitive );
		}
		return $new_object;
	}

	return $subject;
}

/**
 * Helper: Get primary key column name for a table (cached).
 *
 * @param string $table Table name (validated upstream).
 * @return string|null Column name or null if not found.
 */
function qsrdb_get_primary_key_column( $table ) {
	global $wpdb;

	$cache_key = 'pk_' . md5( $table );
	$cached    = qsrdb_cache_get( $cache_key );
	if ( false !== $cached ) {
		return $cached ? (string) $cached : null;
	}

	// Ensure the table name is whitelisted to avoid identifier injection.
	$allowed_tables = qsrdb_get_all_tables();
	if ( ! in_array( $table, $allowed_tables, true ) ) {
		return null;
	}

	// Preferred method: INFORMATION_SCHEMA with placeholders (no interpolated identifiers).
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Reading schema metadata; result is cached immediately after.
	$primary_col = $wpdb->get_var(
		$wpdb->prepare(
			"SELECT COLUMN_NAME
			 FROM INFORMATION_SCHEMA.STATISTICS
			 WHERE TABLE_SCHEMA = DATABASE()
			   AND TABLE_NAME   = %s
			   AND INDEX_NAME   = 'PRIMARY'
			 ORDER BY SEQ_IN_INDEX
			 LIMIT 1",
			$table
		)
	);

	// Fallback: some hosts restrict INFORMATION_SCHEMA.STATISTICS; try KEY_COLUMN_USAGE (placeholders only).
	if ( empty( $primary_col ) ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Reading schema metadata; result is cached immediately after.
		$primary_col = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COLUMN_NAME
				 FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE
				 WHERE TABLE_SCHEMA    = DATABASE()
				   AND TABLE_NAME      = %s
				   AND CONSTRAINT_NAME = 'PRIMARY'
				 LIMIT 1",
				$table
			)
		);
	}

	$pk = $primary_col ? (string) $primary_col : null;
	qsrdb_cache_set( $cache_key, $pk, 3600 ); // Primary keys rarely change.

	return $pk;
}

/**
 * Build a path+query+fragment string from wp_parse_url parts.
 *
 * @param array $parts Parsed URL parts.
 * @return string
 */
function qsrdb_url_build_pathqf( $parts ) {
	$path = isset( $parts['path'] ) ? $parts['path'] : '';
	if ( '' !== $path && '/' !== $path[0] ) {
		$path = '/' . $path;
	}
	if ( '' === $path ) {
		$path = '/';
	}
	$qf = '';
	if ( isset( $parts['query'] ) && '' !== $parts['query'] ) {
		$qf .= '?' . $parts['query'];
	}
	if ( isset( $parts['fragment'] ) && '' !== $parts['fragment'] ) {
		$qf .= '#' . $parts['fragment'];
	}
	return $path . $qf;
}

/**
 * Get the last non-empty path segment from a URL path.
 *
 * @param string $path URL path.
 * @return string Last segment or empty string.
 */
function qsrdb_url_last_segment( $path ) {
	if ( ! is_string( $path ) || '' === $path ) {
		return '';
	}
	$path  = rtrim( $path, '/' );
	$parts = explode( '/', $path );
	$parts = array_values( array_filter( $parts, 'strlen' ) );
	if ( empty( $parts ) ) {
		return '';
	}
	return end( $parts );
}

/**
 * Build smart URL variants for robust matching:
 * - http/https
 * - with/without www
 * - protocol-relative (//host/path)
 * - path-only (absolute "/path" and relative "path")
 * - last path segment only (slug)
 * - trailing slash and no trailing slash (when safe)
 * - JSON-escaped slashes
 * - URL-encoded
 *
 * @param string $search_url  Full search URL.
 * @param string $replace_url Full replace URL.
 * @return array{0: array, 1: array} Arrays of search and replace variants.
 */
function qsrdb_build_url_variants( $search_url, $replace_url ) {
	$s_parts = wp_parse_url( $search_url );
	$r_parts = wp_parse_url( $replace_url );

	if ( empty( $s_parts['host'] ) ) {
		return array( array( $search_url ), array( $replace_url ) );
	}

	$search_host      = $s_parts['host'];
	$search_port      = isset( $s_parts['port'] ) ? ':' . $s_parts['port'] : '';
	$search_host_core = preg_replace( '~^www\.~i', '', $search_host );

	$replace_host = isset( $r_parts['host'] ) ? $r_parts['host'] : $search_host_core;
	$replace_port = isset( $r_parts['port'] ) ? ':' . $r_parts['port'] : '';

	$schemes_to_match = array( 'http', 'https' );
	$host_variants    = array(
		$search_host_core,
		'www.' . $search_host_core,
	);

	$s_pathqf = qsrdb_url_build_pathqf( $s_parts );
	$r_pathqf = qsrdb_url_build_pathqf( $r_parts );

	$has_qf  = ( isset( $s_parts['query'] ) && '' !== $s_parts['query'] ) || ( isset( $s_parts['fragment'] ) && '' !== $s_parts['fragment'] );
	$s_paths = array();
	$r_paths = array();

	if ( $has_qf ) {
		$s_paths[] = $s_pathqf;
		$r_paths[] = $r_pathqf;
	} else {
		$s_trim = rtrim( $s_pathqf, '/' );
		if ( '' === $s_trim ) {
			$s_trim = '/';
		}
		$r_trim = rtrim( $r_pathqf, '/' );
		if ( '' === $r_trim ) {
			$r_trim = '/';
		}
		$s_trl = trailingslashit( $s_trim );
		$r_trl = trailingslashit( $r_trim );

		$s_paths = array_unique( array( $s_trim, $s_trl ) );
		$r_paths = array_unique( array( $r_trim, $r_trl ) );
		if ( count( $s_paths ) !== count( $r_paths ) ) {
			$r_paths = array_fill( 0, count( $s_paths ), $r_trim );
		}
	}

	$s_slug = qsrdb_url_last_segment( isset( $s_parts['path'] ) ? $s_parts['path'] : '' );
	$r_slug = qsrdb_url_last_segment( isset( $r_parts['path'] ) ? $r_parts['path'] : '' );

	$pairs      = array();
	$seen_pairs = array();

	$add_pair = function( $s, $r ) use ( &$pairs, &$seen_pairs ) {
		if ( '' === (string) $s ) {
			return;
		}
		$key = $s . '|' . $r;
		if ( isset( $seen_pairs[ $key ] ) ) {
			return;
		}
		$seen_pairs[ $key ] = true;
		$pairs[]            = array( 's' => $s, 'r' => $r );
	};

	$compose_host = function( $host, $port ) {
		return $host . $port;
	};

	$r_scheme = ! empty( $r_parts['scheme'] ) ? $r_parts['scheme'] : ( ! empty( $s_parts['scheme'] ) ? $s_parts['scheme'] : 'https' );
	$r_host   = $compose_host( $replace_host, $replace_port );

	// Full URLs.
	foreach ( $host_variants as $h ) {
		$h_with_port    = $compose_host( $h, $search_port );
		$h_without_port = $h;

		foreach ( $schemes_to_match as $sch ) {
			foreach ( $s_paths as $idx => $sp ) {
				$rp = isset( $r_paths[ $idx ] ) ? $r_paths[ $idx ] : $r_paths[0];

				$add_pair( $sch . '://' . $h_with_port . $sp, $r_scheme . '://' . $r_host . $rp );
				$add_pair( $sch . '://' . $h_without_port . $sp, $r_scheme . '://' . $r_host . $rp );
			}
		}
	}

	// Protocol-relative.
	foreach ( $host_variants as $h ) {
		$h_with_port    = $compose_host( $h, $search_port );
		$h_without_port = $h;

		foreach ( $s_paths as $idx => $sp ) {
			$rp = isset( $r_paths[ $idx ] ) ? $r_paths[ $idx ] : $r_paths[0];

			$add_pair( '//' . $h_with_port . $sp, '//' . $r_host . $rp );
			$add_pair( '//' . $h_without_port . $sp, '//' . $r_host . $rp );
		}
	}

	// Path-only: absolute and relative.
	foreach ( $s_paths as $idx => $sp ) {
		$rp = isset( $r_paths[ $idx ] ) ? $r_paths[ $idx ] : $r_paths[0];

		$add_pair( $sp, $rp );
		$add_pair( ltrim( $sp, '/' ), ltrim( $rp, '/' ) );
	}

	// Slug-only.
	if ( '' !== $s_slug && '' !== $r_slug ) {
		$add_pair( $s_slug, $r_slug );
	}

	// JSON-escaped variants.
	$base_pairs = $pairs;
	foreach ( $base_pairs as $p ) {
		$js_s = str_replace( '/', '\/', $p['s'] );
		$js_r = str_replace( '/', '\/', $p['r'] );
		$add_pair( $js_s, $js_r );
	}
	// URL-encoded variants.
	foreach ( $base_pairs as $p ) {
		$en_s = rawurlencode( $p['s'] );
		$en_r = rawurlencode( $p['r'] );
		$add_pair( $en_s, $en_r );
	}

	// Longest-first to avoid partial matches grabbing before full URLs.
	usort(
		$pairs,
		function( $a, $b ) {
			$la = strlen( $a['s'] );
			$lb = strlen( $b['s'] );
			if ( $la === $lb ) {
				return 0;
			}
			return ( $la > $lb ) ? -1 : 1;
		}
	);

	$search_variants  = array();
	$replace_variants = array();
	foreach ( $pairs as $p ) {
		$search_variants[]  = $p['s'];
		$replace_variants[] = $p['r'];
	}

	return array( $search_variants, $replace_variants );
}

/**
 * Prepare search/replace arrays.
 * If the search looks like a full URL, expand to robust variants.
 *
 * @param string $search  Search term.
 * @param string $replace Replacement term.
 * @return array{0: string|array, 1: string|array}
 */
function qsrdb_prepare_search_replace_arrays( $search, $replace ) {
	$parsed = wp_parse_url( $search );
	if ( ! empty( $parsed['host'] ) ) {
		return qsrdb_build_url_variants( $search, $replace );
	}
	return array( $search, $replace );
}

/**
 * Runs the main search and replace operation (UNRESTRICTED: all columns).
 *
 * @param string $search     The string to search for (or a full URL).
 * @param string $replace    The string to replace with (or full URL).
 * @param array  $tables     The tables to run the operation on.
 * @param bool   $is_dry_run If true, no changes will be made to the database.
 *
 * @return array A report of the operation.
 */
function qsrdb_run_search_replace( $search, $replace, $tables, $is_dry_run = true ) {
	global $wpdb;

	list( $search_terms, $replace_terms ) = qsrdb_prepare_search_replace_arrays( $search, $replace );

	$report = array(
		'tables_scanned' => 0,
		'rows_scanned'   => 0,
		'rows_updated'   => 0,
		'fields_updated' => 0,
		'details'        => array(),
		'errors'         => array(),
	);

	// Whitelist of valid tables to prevent SQL injection on identifiers.
	$allowed_tables = qsrdb_get_all_tables();

	foreach ( $tables as $table ) {
		if ( ! in_array( $table, $allowed_tables, true ) ) {
			$report['errors'][] = sprintf( 'Table %s is not allowed. Skipping.', $table );
			continue;
		}

		$report['tables_scanned']++;
		$report['details'][ $table ] = 0;

		$primary_key_col = qsrdb_get_primary_key_column( $table );
		if ( ! $primary_key_col ) {
			$report['errors'][] = "Table {$table} has no primary key. Skipping.";
			continue;
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- SELECT * needs identifier; table is whitelisted; cannot cache here because we are mutating row values.
		$rows = $wpdb->get_results( "SELECT * FROM `$table`", ARRAY_A );

		if ( $wpdb->last_error ) {
			$report['errors'][] = "Database error while querying table {$table}: " . $wpdb->last_error;
			continue;
		}

		if ( empty( $rows ) ) {
			continue;
		}

		foreach ( $rows as $row ) {
			$report['rows_scanned']++;
			$update_data = array();
			$where_data  = array( $primary_key_col => $row[ $primary_key_col ] );

			foreach ( $row as $column => $value ) {
				if ( is_null( $value ) ) {
					continue;
				}

				$updated_value = qsrdb_recursive_unserialize_replace( $search_terms, $replace_terms, $value, false );

				if ( $updated_value !== $value ) {
					$update_data[ $column ] = $updated_value;
				}
			}

			if ( ! empty( $update_data ) ) {
				$report['details'][ $table ]++;
				$report['fields_updated'] += count( $update_data );

				if ( ! $is_dry_run ) {
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- $wpdb->update() is the WordPress API for updates; no caching for writes.
					$result = $wpdb->update( $table, $update_data, $where_data );
					if ( false === $result ) {
						$report['errors'][] = "Failed to update row with primary key {$where_data[$primary_key_col]} in {$table}. Error: " . $wpdb->last_error;
					} else {
						$report['rows_updated']++;
					}
				} else {
					$report['rows_updated']++;
				}
			}
		}
	}

	return $report;
}

/**
 * Best-effort fix for core site URLs (home & siteurl) when doing a domain move.
 *
 * @param string $search  Old URL.
 * @param string $replace New URL.
 * @return array { 'home_updated' => bool, 'siteurl_updated' => bool }
 */
function qsrdb_maybe_update_core_urls( $search, $replace ) {
	$out = array(
		'home_updated'    => false,
		'siteurl_updated' => false,
	);

	$old = wp_parse_url( $search );
	$new = wp_parse_url( $replace );

	if ( empty( $old['host'] ) || empty( $new['host'] ) ) {
		return $out;
	}

	$new_scheme = ! empty( $new['scheme'] ) ? $new['scheme'] : 'https';
	$new_host   = $new['host'];

	$old_host_core = preg_replace( '~^www\.~i', '', $old['host'] );
	$host_regex    = '(?:www\.)?' . preg_quote( $old_host_core, '~' );
	$prefix_regex  = "~^https?://{$host_regex}~i";
	$prefix_target = $new_scheme . '://' . $new_host;

	$home    = get_option( 'home' );
	$siteurl = get_option( 'siteurl' );

	$new_home    = preg_replace( $prefix_regex, $prefix_target, $home );
	$new_siteurl = preg_replace( $prefix_regex, $prefix_target, $siteurl );

	if ( is_string( $new_home ) && $new_home !== $home ) {
		update_option( 'home', untrailingslashit( $new_home ) );
		$out['home_updated'] = true;
	}
	if ( is_string( $new_siteurl ) && $new_siteurl !== $siteurl ) {
		update_option( 'siteurl', untrailingslashit( $new_siteurl ) );
		$out['siteurl_updated'] = true;
	}

	return $out;
}