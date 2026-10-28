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
 * Get a cached value for this plugin.
 *
 * @param string $key Cache key.
 * @return mixed Cached value or false if not found.
 */
function qsrdb_cache_get( $key ) {
	return wp_cache_get( $key, 'qsrdb' );
}

/**
 * Set a cached value for this plugin.
 *
 * @param string $key   Cache key.
 * @param mixed  $value Value.
 * @param int    $ttl   Time to live in seconds.
 * @return void
 */
function qsrdb_cache_set( $key, $value, $ttl = 300 ) {
	wp_cache_set( $key, $value, 'qsrdb', (int) $ttl );
}

/**
 * Get all tables in the database, respecting multisite installations.
 * Results are cached to improve performance.
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
		// Get all blog IDs (number => 0 ensures all sites are returned).
		$blog_ids = get_sites(
			array(
				'fields' => 'ids',
				'number' => 0,
			)
		);

		foreach ( $blog_ids as $blog_id ) {
			$prefix    = $wpdb->get_blog_prefix( $blog_id );
			$key_pref  = 'tables_prefix_' . md5( $prefix );
			$t_from_ca = qsrdb_cache_get( $key_pref );

			if ( false !== $t_from_ca && is_array( $t_from_ca ) ) {
				$tables = array_merge( $tables, $t_from_ca );
			} else {
				$like = $wpdb->esc_like( $prefix ) . '%';
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
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
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
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
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$tables = (array) $wpdb->get_col( 'SHOW TABLES' );
			qsrdb_cache_set( $key_single, $tables, 300 );
		}
	}

	// Cache the final list.
	qsrdb_cache_set( $cache_key, $tables, 300 );
	return $tables;
}

/**
 * Get detailed table info (row count, size, and core flag).
 *
 * @return array Associative array of tables with metadata.
 */
function qsrdb_get_tables_with_details() {
	global $wpdb;

	$cache_key = 'tables_details_' . md5( (string) $wpdb->prefix );
	$cached    = qsrdb_cache_get( $cache_key );
	if ( false !== $cached && is_array( $cached ) ) {
		return $cached;
	}

	$all_tables = qsrdb_get_all_tables();
	if ( empty( $all_tables ) ) {
		return array();
	}

	// Fetch table rows and size from information_schema.
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$schema_info = $wpdb->get_results(
		'SELECT TABLE_NAME, TABLE_ROWS, (DATA_LENGTH + INDEX_LENGTH) AS total_bytes
		 FROM INFORMATION_SCHEMA.TABLES
		 WHERE TABLE_SCHEMA = DATABASE()',
		OBJECT_K
	);

	$core_suffixes = array(
		'commentmeta',
		'comments',
		'links',
		'options',
		'postmeta',
		'posts',
		'term_relationships',
		'term_taxonomy',
		'termmeta',
		'terms',
		'usermeta',
		'users',
		'blogs',
		'blogmeta',
		'site',
		'sitemeta',
		'signups',
		'registration_log',
	);

	$details = array();
	foreach ( $all_tables as $table ) {
		$rows = isset( $schema_info[ $table ] ) ? (int) $schema_info[ $table ]->TABLE_ROWS : 0;
		$size = isset( $schema_info[ $table ] ) ? (int) $schema_info[ $table ]->total_bytes : 0;

		$is_core = false;
		foreach ( $core_suffixes as $suffix ) {
			if ( preg_match( '/(^|_)(' . preg_quote( $suffix, '/' ) . ')$/i', $table ) ) {
				$is_core = true;
				break;
			}
		}

		$details[ $table ] = array(
			'name'    => $table,
			'rows'    => $rows,
			'size'    => $size,
			'is_core' => $is_core,
		);
	}

	qsrdb_cache_set( $cache_key, $details, 300 );
	return $details;
}

/**
 * Get database summary statistics.
 *
 * @return array
 */
function qsrdb_get_db_stats() {
	global $wpdb;

	$cache_key = 'db_stats_' . md5( (string) $wpdb->prefix );
	$cached    = qsrdb_cache_get( $cache_key );
	if ( false !== $cached && is_array( $cached ) ) {
		return $cached;
	}

	$all_tables = qsrdb_get_all_tables();
	$total_size = 0;

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$sizes = $wpdb->get_results(
		'SELECT (DATA_LENGTH + INDEX_LENGTH) AS total_bytes
		 FROM INFORMATION_SCHEMA.TABLES
		 WHERE TABLE_SCHEMA = DATABASE()'
	);

	if ( ! empty( $sizes ) ) {
		foreach ( $sizes as $row ) {
			$total_size += (int) $row->total_bytes;
		}
	}

	$stats = array(
		'total_tables' => count( $all_tables ),
		'total_size'   => $total_size,
		'prefix'       => $wpdb->prefix,
		'db_version'   => $wpdb->db_version(),
		'is_multisite' => is_multisite(),
	);

	qsrdb_cache_set( $cache_key, $stats, 300 );
	return $stats;
}

/**
 * Get primary key column names for a table (cached).
 * Supports both single and composite primary keys.
 *
 * @param string $table Table name (validated upstream).
 * @return array Array of primary key column names.
 */
function qsrdb_get_primary_key_columns( $table ) {
	global $wpdb;

	$cache_key = 'pk_cols_' . md5( $table );
	$cached    = qsrdb_cache_get( $cache_key );
	if ( false !== $cached && is_array( $cached ) ) {
		return $cached;
	}

	$allowed_tables = qsrdb_get_all_tables();
	if ( ! in_array( $table, $allowed_tables, true ) ) {
		return array();
	}

	// 1. Primary key from STATISTICS.
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$cols = $wpdb->get_col(
		$wpdb->prepare(
			"SELECT COLUMN_NAME
			 FROM INFORMATION_SCHEMA.STATISTICS
			 WHERE TABLE_SCHEMA = DATABASE()
			   AND TABLE_NAME   = %s
			   AND INDEX_NAME   = 'PRIMARY'
			 ORDER BY SEQ_IN_INDEX ASC",
			$table
		)
	);

	// 2. Fallback to KEY_COLUMN_USAGE.
	if ( empty( $cols ) ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$cols = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT COLUMN_NAME
				 FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE
				 WHERE TABLE_SCHEMA    = DATABASE()
				   AND TABLE_NAME      = %s
				   AND CONSTRAINT_NAME = 'PRIMARY'
				 ORDER BY ORDINAL_POSITION ASC",
				$table
			)
		);
	}

	// 3. Fallback: Check for any UNIQUE index.
	if ( empty( $cols ) ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$unique_idx = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT INDEX_NAME
				 FROM INFORMATION_SCHEMA.STATISTICS
				 WHERE TABLE_SCHEMA = DATABASE()
				   AND TABLE_NAME   = %s
				   AND NON_UNIQUE   = 0
				 LIMIT 1',
				$table
			)
		);

		if ( ! empty( $unique_idx ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$cols = $wpdb->get_col(
				$wpdb->prepare(
					'SELECT COLUMN_NAME
					 FROM INFORMATION_SCHEMA.STATISTICS
					 WHERE TABLE_SCHEMA = DATABASE()
					   AND TABLE_NAME   = %s
					   AND INDEX_NAME   = %s
					 ORDER BY SEQ_IN_INDEX ASC',
					$table,
					$unique_idx
				)
			);
		}
	}

	$cols = is_array( $cols ) ? array_values( array_unique( $cols ) ) : array();
	qsrdb_cache_set( $cache_key, $cols, 3600 );

	return $cols;
}

/**
 * Helper: Get first primary key column name for a table (cached).
 * Maintained for backward compatibility.
 *
 * @param string $table Table name.
 * @return string|null Column name or null if not found.
 */
function qsrdb_get_primary_key_column( $table ) {
	$cols = qsrdb_get_primary_key_columns( $table );
	return ! empty( $cols ) ? $cols[0] : null;
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
 * Build smart URL variants for robust domain and URL migration:
 * - http / https schemes
 * - with and without www.
 * - protocol-relative (//domain.com)
 * - JSON-escaped slashes (https:\/\/domain.com)
 * - URL-encoded variants (https%3A%2F%2Fdomain.com)
 *
 * Crucially, does NOT generate bare relative slugs or isolated slashes,
 * preventing accidental replacement of common words across the database.
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
		$r_trim = rtrim( $r_pathqf, '/' );

		// Only add trailing slash and non-trailing slash if path is non-empty or root.
		$s_paths = array_unique( array( $s_trim, trailingslashit( $s_trim ) ) );
		$r_paths = array_unique( array( $r_trim, trailingslashit( $r_trim ) ) );

		if ( count( $s_paths ) !== count( $r_paths ) ) {
			$r_paths = array_fill( 0, count( $s_paths ), $r_trim );
		}
	}

	$pairs      = array();
	$seen_pairs = array();

	$add_pair = function( $s, $r ) use ( &$pairs, &$seen_pairs ) {
		$s = (string) $s;
		$r = (string) $r;
		if ( '' === $s || '/' === $s ) {
			return;
		}
		$key = $s . '|' . $r;
		if ( isset( $seen_pairs[ $key ] ) ) {
			return;
		}
		$seen_pairs[ $key ] = true;
		$pairs[]            = array(
			's' => $s,
			'r' => $r,
		);
	};

	$compose_host = function( $host, $port ) {
		return $host . $port;
	};

	$r_scheme = ! empty( $r_parts['scheme'] ) ? $r_parts['scheme'] : ( ! empty( $s_parts['scheme'] ) ? $s_parts['scheme'] : 'https' );
	$r_host   = $compose_host( $replace_host, $replace_port );

	// 1. Full URLs with http:// and https://.
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

	// 2. Protocol-relative URLs (//example.com/path).
	foreach ( $host_variants as $h ) {
		$h_with_port    = $compose_host( $h, $search_port );
		$h_without_port = $h;

		foreach ( $s_paths as $idx => $sp ) {
			$rp = isset( $r_paths[ $idx ] ) ? $r_paths[ $idx ] : $r_paths[0];
			$add_pair( '//' . $h_with_port . $sp, '//' . $r_host . $rp );
			$add_pair( '//' . $h_without_port . $sp, '//' . $r_host . $rp );
		}
	}

	// 3. JSON-escaped slashes (e.g., https:\/\/example.com).
	$base_pairs = $pairs;
	foreach ( $base_pairs as $p ) {
		$js_s = str_replace( '/', '\/', $p['s'] );
		$js_r = str_replace( '/', '\/', $p['r'] );
		$add_pair( $js_s, $js_r );
	}

	// 4. URL-encoded variants (e.g., https%3A%2F%2Fexample.com).
	foreach ( $base_pairs as $p ) {
		$en_s = rawurlencode( $p['s'] );
		$en_r = rawurlencode( $p['r'] );
		$add_pair( $en_s, $en_r );
	}

	// Sort longest-first to prevent short matches from preempting longer URLs.
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
 * If search looks like a full URL, expand to robust URL variants.
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
 * Recursively search and replace a string (or array of strings) in a subject.
 * Safely handles serialized PHP data and JSON strings without data corruption.
 *
 * @param string|array $search           Search string or array of strings.
 * @param string|array $replace          Replacement string or array of strings.
 * @param mixed        $subject          String, array, or object.
 * @param bool         $case_insensitive If true, case-insensitive replace.
 *
 * @return mixed
 */
function qsrdb_recursive_unserialize_replace( $search, $replace, $subject, $case_insensitive = false ) {
	if ( is_string( $subject ) ) {
		// Handle PHP serialized strings.
		if ( is_serialized( $subject ) ) {
			$unserialized = @unserialize( $subject );

			// Crucial safety check: only proceed if unserialize succeeded or original is serialized false.
			if ( false !== $unserialized || 'b:0;' === $subject ) {
				$processed = qsrdb_recursive_unserialize_replace( $search, $replace, $unserialized, $case_insensitive );
				if ( $processed !== $unserialized ) {
					return serialize( $processed );
				}
			}
			return $subject;
		}

		// Perform string replacement.
		return $case_insensitive
			? str_ireplace( $search, $replace, $subject )
			: str_replace( $search, $replace, $subject );
	}

	if ( is_array( $subject ) ) {
		$new_array = array();
		foreach ( $subject as $key => $value ) {
			$new_key = is_string( $key )
				? ( $case_insensitive ? str_ireplace( $search, $replace, $key ) : str_replace( $search, $replace, $key ) )
				: $key;
			$new_array[ $new_key ] = qsrdb_recursive_unserialize_replace( $search, $replace, $value, $case_insensitive );
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
 * Format a snippet for the preview report with highlighted differences.
 *
 * @param mixed        $value            Database value.
 * @param string|array $terms            Search or replace terms.
 * @param bool         $case_insensitive Whether search is case-insensitive.
 * @param bool         $is_replacement   Whether this is the replacement value.
 * @return string Safe HTML snippet.
 */
function qsrdb_format_preview_snippet( $value, $terms, $case_insensitive = false, $is_replacement = false ) {
	$is_serialized = is_string( $value ) && is_serialized( $value );
	$display       = is_string( $value ) ? $value : wp_json_encode( $value );

	$badge = $is_serialized ? '<span class="qsrdb-badge-serialized">' . esc_html__( 'Serialized', 'quick-search-replace' ) . '</span> ' : '';

	$term = is_array( $terms ) ? ( ! empty( $terms[0] ) ? $terms[0] : '' ) : (string) $terms;

	if ( '' === $term ) {
		return $badge . esc_html( wp_html_excerpt( $display, 120, '...' ) );
	}

	$pos = $case_insensitive ? mb_stripos( $display, $term ) : mb_strpos( $display, $term );
	if ( false === $pos ) {
		return $badge . esc_html( wp_html_excerpt( $display, 120, '...' ) );
	}

	$start  = max( 0, $pos - 35 );
	$length = mb_strlen( $term ) + 70;
	$sub    = mb_substr( $display, $start, $length );

	$lead = $start > 0 ? '...' : '';
	$tail = ( $start + $length < mb_strlen( $display ) ) ? '...' : '';

	$escaped      = esc_html( $sub );
	$escaped_term = esc_html( $term );
	$class        = $is_replacement ? 'qsrdb-diff-ins' : 'qsrdb-diff-del';

	if ( $case_insensitive ) {
		$highlighted = preg_replace( '/' . preg_quote( $escaped_term, '/' ) . '/i', '<span class="' . $class . '">$0</span>', $escaped );
	} else {
		$highlighted = str_replace( $escaped_term, '<span class="' . $class . '">' . $escaped_term . '</span>', $escaped );
	}

	return $badge . $lead . $highlighted . $tail;
}

/**
 * Runs the search and replace operation with chunked processing and safety controls.
 *
 * @param string     $search  The string to search for.
 * @param string     $replace The string to replace with.
 * @param array      $tables  The tables to run the operation on.
 * @param array|bool $options Array of options, or boolean for backward compatibility ($is_dry_run).
 *
 * @return array A report of the operation.
 */
function qsrdb_run_search_replace( $search, $replace, $tables, $options = array() ) {
	global $wpdb;

	// Backward compatibility: support boolean parameter for $is_dry_run.
	if ( is_bool( $options ) ) {
		$options = array( 'is_dry_run' => $options );
	}

	$defaults = array(
		'is_dry_run'       => true,
		'case_insensitive' => false,
		'smart_url'        => true,
		'skip_guid'        => true,
		'batch_size'       => 1000,
		'max_previews'     => 30,
	);
	$args     = wp_parse_args( $options, $defaults );

	$start_time = microtime( true );

	// Prepare terms.
	if ( $args['smart_url'] ) {
		list( $search_terms, $replace_terms ) = qsrdb_prepare_search_replace_arrays( $search, $replace );
	} else {
		$search_terms  = $search;
		$replace_terms = $replace;
	}

	$report = array(
		'tables_scanned' => 0,
		'rows_scanned'   => 0,
		'rows_updated'   => 0,
		'fields_updated' => 0,
		'details'        => array(),
		'table_fields'   => array(),
		'errors'         => array(),
		'preview'        => array(),
		'time_elapsed'   => 0,
		'is_dry_run'     => (bool) $args['is_dry_run'],
	);

	$allowed_tables = qsrdb_get_all_tables();

	foreach ( $tables as $table ) {
		if ( ! in_array( $table, $allowed_tables, true ) ) {
			$report['errors'][] = sprintf(
				/* translators: %s: table name */
				__( 'Table %s is not permitted. Skipping.', 'quick-search-replace' ),
				$table
			);
			continue;
		}

		$report['tables_scanned']++;
		$report['details'][ $table ]      = 0;
		$report['table_fields'][ $table ] = 0;

		$pk_cols = qsrdb_get_primary_key_columns( $table );
		if ( empty( $pk_cols ) ) {
			$report['errors'][] = sprintf(
				/* translators: %s: table name */
				__( 'Table %s has no primary key or unique index. Skipping for safety.', 'quick-search-replace' ),
				$table
			);
			continue;
		}

		// Count total rows in the table.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$total_rows = (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM %i',
				$table
			)
		);

		if ( 0 === $total_rows ) {
			continue;
		}

		$batch_size = max( 100, (int) $args['batch_size'] );

		// Process rows in memory-safe batches.
		for ( $offset = 0; $offset < $total_rows; $offset += $batch_size ) {
			if ( function_exists( 'wp_raise_memory_limit' ) ) {
				wp_raise_memory_limit( 'admin' );
			}
			if ( function_exists( 'set_time_limit' ) ) {
				// phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged -- Setting execution time limit is necessary for database batch operations.
				@set_time_limit( 60 );
			}

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					'SELECT * FROM %i LIMIT %d OFFSET %d',
					$table,
					$batch_size,
					$offset
				),
				ARRAY_A
			);

			if ( $wpdb->last_error ) {
				$report['errors'][] = sprintf(
					/* translators: 1: table name, 2: error message */
					__( 'Database error while querying table %1$s: %2$s', 'quick-search-replace' ),
					$table,
					$wpdb->last_error
				);
				break;
			}

			if ( empty( $rows ) ) {
				break;
			}

			foreach ( $rows as $row ) {
				$report['rows_scanned']++;
				$update_data = array();
				$where_data  = array();

				// Build composite WHERE clause using all primary key columns.
				foreach ( $pk_cols as $pk_col ) {
					if ( isset( $row[ $pk_col ] ) ) {
						$where_data[ $pk_col ] = $row[ $pk_col ];
					}
				}

				if ( count( $where_data ) !== count( $pk_cols ) ) {
					continue;
				}

				foreach ( $row as $column => $value ) {
					if ( is_null( $value ) ) {
						continue;
					}

					// Skip GUID column in posts tables if skip_guid option is enabled.
					if ( $args['skip_guid'] && 'guid' === strtolower( $column ) && preg_match( '/(^|_)posts$/i', $table ) ) {
						continue;
					}

					$updated_value = qsrdb_recursive_unserialize_replace(
						$search_terms,
						$replace_terms,
						$value,
						$args['case_insensitive']
					);

					if ( $updated_value !== $value ) {
						$update_data[ $column ] = $updated_value;

						// Record sample previews for the user report.
						if ( count( $report['preview'] ) < (int) $args['max_previews'] ) {
							$pk_display = array();
							foreach ( $where_data as $k => $v ) {
								$pk_display[] = $k . '=' . $v;
							}

							$report['preview'][] = array(
								'table'  => $table,
								'pk'     => implode( ', ', $pk_display ),
								'column' => $column,
								'before' => qsrdb_format_preview_snippet( $value, $search_terms, $args['case_insensitive'], false ),
								'after'  => qsrdb_format_preview_snippet( $updated_value, $replace_terms, $args['case_insensitive'], true ),
							);
						}
					}
				}

				if ( ! empty( $update_data ) ) {
					$fields_count                      = count( $update_data );
					$report['details'][ $table ]      += 1;
					$report['table_fields'][ $table ] += $fields_count;
					$report['fields_updated']         += $fields_count;

					if ( ! $args['is_dry_run'] ) {
						// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
						$result = $wpdb->update( $table, $update_data, $where_data );
						if ( false === $result ) {
							$report['errors'][] = sprintf(
								/* translators: 1: table name, 2: error message */
								__( 'Failed to update row in table %1$s. Error: %2$s', 'quick-search-replace' ),
								$table,
								$wpdb->last_error
							);
						} else {
							$report['rows_updated']++;
						}
					} else {
						$report['rows_updated']++;
					}
				}
			}
		}
	}

	$report['time_elapsed'] = round( microtime( true ) - $start_time, 3 );
	return $report;
}

/**
 * Best-effort fix for core site URLs (home & siteurl) when performing a domain migration.
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
	$new_port   = ! empty( $new['port'] ) ? ':' . $new['port'] : '';

	$old_host_core = preg_replace( '~^www\.~i', '', $old['host'] );
	$host_regex    = '(?:www\.)?' . preg_quote( $old_host_core, '~' );
	$prefix_regex  = "~^https?://{$host_regex}(?::\d+)?~i";
	$prefix_target = $new_scheme . '://' . $new_host . $new_port;

	$home    = get_option( 'home' );
	$siteurl = get_option( 'siteurl' );

	if ( is_string( $home ) ) {
		$new_home = preg_replace( $prefix_regex, $prefix_target, $home );
		if ( is_string( $new_home ) && $new_home !== $home ) {
			update_option( 'home', untrailingslashit( $new_home ) );
			$out['home_updated'] = true;
		}
	}

	if ( is_string( $siteurl ) ) {
		$new_siteurl = preg_replace( $prefix_regex, $prefix_target, $siteurl );
		if ( is_string( $new_siteurl ) && $new_siteurl !== $siteurl ) {
			update_option( 'siteurl', untrailingslashit( $new_siteurl ) );
			$out['siteurl_updated'] = true;
		}
	}

	return $out;
}
