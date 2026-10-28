<?php
/**
 * Renders the admin page for Quick Search Replace.
 *
 * @package QuickSearchReplace
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * Main render function for the admin page.
 */
function qsrdb_render_admin_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'quick-search-replace' ) );
	}

	$search           = '';
	$replace          = '';
	$selected_tables  = array();
	$report           = null;
	$is_dry_run       = true;
	$smart_url        = true;
	$case_insensitive = false;
	$skip_guid        = true;

	// Handle form submission.
	if ( isset( $_POST['qsrdb_nonce'] ) && wp_verify_nonce( sanitize_key( $_POST['qsrdb_nonce'] ), 'qsrdb_action' ) ) {
		// Capture inputs safely without stripping HTML code snippets.
		$search          = isset( $_POST['qsrdb_search'] ) ? wp_unslash( $_POST['qsrdb_search'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$replace         = isset( $_POST['qsrdb_replace'] ) ? wp_unslash( $_POST['qsrdb_replace'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$selected_tables = isset( $_POST['qsrdb_tables'] ) && is_array( $_POST['qsrdb_tables'] ) ? array_map( 'sanitize_text_field', wp_unslash( $_POST['qsrdb_tables'] ) ) : array();

		// Determine operation mode: Dry Run if dry run button clicked OR dry run toggle checked.
		if ( isset( $_POST['qsrdb_run_live'] ) ) {
			$is_dry_run = false;
		} else {
			$is_dry_run = isset( $_POST['qsrdb_dry_run_btn'] ) || ! empty( $_POST['qsrdb_dry_run_toggle'] );
		}

		$smart_url        = ! empty( $_POST['qsrdb_smart_url'] );
		$case_insensitive = ! empty( $_POST['qsrdb_case_insensitive'] );
		$skip_guid        = ! empty( $_POST['qsrdb_skip_guid'] );

		if ( '' !== $search && ! empty( $selected_tables ) ) {
			$options = array(
				'is_dry_run'       => $is_dry_run,
				'smart_url'        => $smart_url,
				'case_insensitive' => $case_insensitive,
				'skip_guid'        => $skip_guid,
				'batch_size'       => 1000,
				'max_previews'     => 30,
			);

			$report = qsrdb_run_search_replace( $search, $replace, $selected_tables, $options );

			if ( ! $is_dry_run && $report ) {
				// Best-effort update of core URLs (home / siteurl) if this is a domain move.
				$core_url_result = qsrdb_maybe_update_core_urls( $search, $replace );

				// Flush rewrite rules.
				global $wp_rewrite;
				if ( isset( $wp_rewrite ) ) {
					$wp_rewrite->init();
					$wp_rewrite->flush_rules( true );
				} else {
					flush_rewrite_rules( true );
				}

				$msg = sprintf(
					/* translators: 1: fields updated, 2: rows updated, 3: seconds */
					__( 'Live run complete! %1$d fields updated across %2$d rows in %3$s seconds. Rewrite rules flushed.', 'quick-search-replace' ),
					absint( $report['fields_updated'] ),
					absint( $report['rows_updated'] ),
					esc_html( $report['time_elapsed'] )
				);

				if ( $core_url_result['home_updated'] || $core_url_result['siteurl_updated'] ) {
					$msg .= ' ' . __( 'WordPress core URL options (home / siteurl) were automatically updated.', 'quick-search-replace' );
				}

				add_settings_error( 'qsrdb-notices', 'qsrdb-success', $msg, 'success' );
			}
		} else {
			add_settings_error(
				'qsrdb-notices',
				'qsrdb-error',
				__( 'Please fill in the "Search for" field and select at least one database table.', 'quick-search-replace' ),
				'error'
			);
		}
	}

	$table_details = qsrdb_get_tables_with_details();
	$db_stats      = qsrdb_get_db_stats();
	global $wp_version;
	?>
	<div class="wrap qsrdb-wrap">
		<!-- Plugin Header -->
		<div class="qsrdb-header">
			<div class="qsrdb-header-left">
				<div class="qsrdb-logo-icon">
					<span class="dashicons dashicons-database"></span>
				</div>
				<div>
					<h1 class="qsrdb-title">
						<?php esc_html_e( 'Quick Search Replace', 'quick-search-replace' ); ?>
						<span class="qsrdb-version-badge">v<?php echo esc_html( QSRDB_VERSION ); ?></span>
					</h1>
					<p class="qsrdb-subtitle">
						<?php esc_html_e( 'A fast, reliable search and replace utility for your WordPress database with serialization and multisite support.', 'quick-search-replace' ); ?>
					</p>
				</div>
			</div>
			<div class="qsrdb-header-meta">
				<div class="qsrdb-meta-item">
					<span class="qsrdb-meta-label"><?php esc_html_e( 'WordPress', 'quick-search-replace' ); ?></span>
					<span class="qsrdb-meta-val"><?php echo esc_html( $wp_version ); ?></span>
				</div>
				<div class="qsrdb-meta-item">
					<span class="qsrdb-meta-label"><?php esc_html_e( 'Database Tables', 'quick-search-replace' ); ?></span>
					<span class="qsrdb-meta-val"><?php echo esc_html( $db_stats['total_tables'] ); ?></span>
				</div>
				<div class="qsrdb-meta-item">
					<span class="qsrdb-meta-label"><?php esc_html_e( 'DB Size', 'quick-search-replace' ); ?></span>
					<span class="qsrdb-meta-val"><?php echo esc_html( size_format( $db_stats['total_size'] ) ); ?></span>
				</div>
				<div class="qsrdb-meta-item">
					<span class="qsrdb-meta-label"><?php esc_html_e( 'Prefix', 'quick-search-replace' ); ?></span>
					<span class="qsrdb-meta-val"><code><?php echo esc_html( $db_stats['prefix'] ); ?></code></span>
				</div>
				<?php if ( ! empty( $db_stats['is_multisite'] ) ) : ?>
					<div class="qsrdb-meta-item">
						<span class="qsrdb-meta-label"><?php esc_html_e( 'Mode', 'quick-search-replace' ); ?></span>
						<span class="qsrdb-meta-val qsrdb-pill-network"><?php esc_html_e( 'Multisite', 'quick-search-replace' ); ?></span>
					</div>
				<?php endif; ?>
			</div>
		</div>

		<?php
		// Warning if hard-coded URLs exist in wp-config.php.
		if ( defined( 'WP_HOME' ) || defined( 'WP_SITEURL' ) ) :
			?>
			<div class="notice notice-warning qsrdb-notice-box">
				<p>
					<strong><?php esc_html_e( 'Important Notice: Hard-coded URLs Detected in wp-config.php', 'quick-search-replace' ); ?></strong><br>
					<?php esc_html_e( 'Your wp-config.php explicitly defines WP_HOME or WP_SITEURL. While this tool can update URLs in your database tables, values in wp-config.php take precedence until you edit or remove them.', 'quick-search-replace' ); ?>
				</p>
			</div>
		<?php endif; ?>

		<?php settings_errors( 'qsrdb-notices' ); ?>

		<!-- Report Section -->
		<?php if ( $report ) : ?>
			<div class="qsrdb-report-box <?php echo $report['is_dry_run'] ? 'qsrdb-report-dry' : 'qsrdb-report-live'; ?>">
				<div class="qsrdb-report-header">
					<div class="qsrdb-report-badge">
						<?php echo $report['is_dry_run'] ? esc_html__( 'Dry Run Report', 'quick-search-replace' ) : esc_html__( 'Live Run Report', 'quick-search-replace' ); ?>
					</div>
					<div class="qsrdb-report-timing">
						<?php
						printf(
							/* translators: %s: seconds */
							esc_html__( 'Completed in %s seconds', 'quick-search-replace' ),
							esc_html( $report['time_elapsed'] )
						);
						?>
					</div>
				</div>

				<?php if ( $report['is_dry_run'] ) : ?>
					<div class="qsrdb-dry-notice">
						<span class="dashicons dashicons-info"></span>
						<span>
							<?php esc_html_e( 'This was a dry run. No changes were written to the database. Review the summary and preview below before running a live replacement.', 'quick-search-replace' ); ?>
						</span>
					</div>
				<?php endif; ?>

				<!-- Error Output -->
				<?php if ( ! empty( $report['errors'] ) ) : ?>
					<div class="qsrdb-error-list">
						<strong><?php esc_html_e( 'Warnings / Errors:', 'quick-search-replace' ); ?></strong>
						<ul>
							<?php foreach ( $report['errors'] as $err ) : ?>
								<li><?php echo esc_html( $err ); ?></li>
							<?php endforeach; ?>
						</ul>
					</div>
				<?php endif; ?>

				<!-- Stats Grid -->
				<div class="qsrdb-stats-grid">
					<div class="qsrdb-stat-card">
						<span class="qsrdb-stat-num"><?php echo esc_html( number_format_i18n( $report['tables_scanned'] ) ); ?></span>
						<span class="qsrdb-stat-label"><?php esc_html_e( 'Tables Scanned', 'quick-search-replace' ); ?></span>
					</div>
					<div class="qsrdb-stat-card">
						<span class="qsrdb-stat-num"><?php echo esc_html( number_format_i18n( $report['rows_scanned'] ) ); ?></span>
						<span class="qsrdb-stat-label"><?php esc_html_e( 'Rows Scanned', 'quick-search-replace' ); ?></span>
					</div>
					<div class="qsrdb-stat-card <?php echo $report['rows_updated'] > 0 ? 'qsrdb-stat-highlight' : ''; ?>">
						<span class="qsrdb-stat-num"><?php echo esc_html( number_format_i18n( $report['rows_updated'] ) ); ?></span>
						<span class="qsrdb-stat-label">
							<?php echo $report['is_dry_run'] ? esc_html__( 'Rows Matching', 'quick-search-replace' ) : esc_html__( 'Rows Updated', 'quick-search-replace' ); ?>
						</span>
					</div>
					<div class="qsrdb-stat-card <?php echo $report['fields_updated'] > 0 ? 'qsrdb-stat-highlight' : ''; ?>">
						<span class="qsrdb-stat-num"><?php echo esc_html( number_format_i18n( $report['fields_updated'] ) ); ?></span>
						<span class="qsrdb-stat-label">
							<?php echo $report['is_dry_run'] ? esc_html__( 'Fields Matching', 'quick-search-replace' ) : esc_html__( 'Fields Updated', 'quick-search-replace' ); ?>
						</span>
					</div>
				</div>

				<!-- Table Changes Breakdown -->
				<div class="qsrdb-breakdown">
					<h3><?php esc_html_e( 'Table Breakdown', 'quick-search-replace' ); ?></h3>
					<?php
					$has_changes = false;
					foreach ( $report['details'] as $t_name => $count ) {
						if ( $count > 0 ) {
							$has_changes = true;
							break;
						}
					}
					?>
					<?php if ( $has_changes ) : ?>
						<table class="qsrdb-table-report widefat striped">
							<thead>
								<tr>
									<th><?php esc_html_e( 'Table Name', 'quick-search-replace' ); ?></th>
									<th><?php esc_html_e( 'Rows Affected', 'quick-search-replace' ); ?></th>
									<th><?php esc_html_e( 'Fields Modified', 'quick-search-replace' ); ?></th>
								</tr>
							</thead>
							<tbody>
								<?php foreach ( $report['details'] as $t_name => $count ) : ?>
									<?php if ( $count > 0 ) : ?>
										<tr>
											<td><code><?php echo esc_html( $t_name ); ?></code></td>
											<td><span class="qsrdb-pill-count"><?php echo esc_html( number_format_i18n( $count ) ); ?></span></td>
											<td><?php echo esc_html( number_format_i18n( $report['table_fields'][ $t_name ] ) ); ?></td>
										</tr>
									<?php endif; ?>
								<?php endforeach; ?>
							</tbody>
						</table>
					<?php else : ?>
						<p class="qsrdb-no-changes">
							<span class="dashicons dashicons-yes-alt"></span>
							<?php esc_html_e( 'No matching records were found in the selected tables.', 'quick-search-replace' ); ?>
						</p>
					<?php endif; ?>
				</div>

				<!-- Sample Preview Diffs -->
				<?php if ( ! empty( $report['preview'] ) ) : ?>
					<div class="qsrdb-preview-section">
						<h3>
							<?php esc_html_e( 'Sample Changes Preview', 'quick-search-replace' ); ?>
							<span class="qsrdb-preview-count">
								<?php
								printf(
									/* translators: %d: count */
									esc_html__( '(Showing up to %d sample modifications)', 'quick-search-replace' ),
									count( $report['preview'] )
								);
								?>
							</span>
						</h3>
						<div class="qsrdb-diff-table-wrap">
							<table class="qsrdb-diff-table widefat striped">
								<thead>
									<tr>
										<th style="width: 20%;"><?php esc_html_e( 'Table & Location', 'quick-search-replace' ); ?></th>
										<th style="width: 40%;"><?php esc_html_e( 'Current Value (Before)', 'quick-search-replace' ); ?></th>
										<th style="width: 40%;"><?php esc_html_e( 'Replacement (After)', 'quick-search-replace' ); ?></th>
									</tr>
								</thead>
								<tbody>
									<?php foreach ( $report['preview'] as $diff ) : ?>
										<tr>
											<td>
												<strong><code><?php echo esc_html( $diff['table'] ); ?></code></strong><br>
												<span class="qsrdb-diff-meta">
													<?php esc_html_e( 'Col:', 'quick-search-replace' ); ?> <code><?php echo esc_html( $diff['column'] ); ?></code><br>
													<?php esc_html_e( 'PK:', 'quick-search-replace' ); ?> <code><?php echo esc_html( $diff['pk'] ); ?></code>
												</span>
											</td>
											<td class="qsrdb-diff-cell">
												<div class="qsrdb-diff-content"><?php echo $diff['before']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></div>
											</td>
											<td class="qsrdb-diff-cell">
												<div class="qsrdb-diff-content"><?php echo $diff['after']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></div>
											</td>
										</tr>
									<?php endforeach; ?>
								</tbody>
							</table>
						</div>
					</div>
				<?php endif; ?>
			</div>
		<?php endif; ?>

		<!-- Main Form Layout -->
		<div class="qsrdb-grid-container">
			<div class="qsrdb-main-col">
				<form method="post" action="" id="qsrdb-form">
					<?php wp_nonce_field( 'qsrdb_action', 'qsrdb_nonce' ); ?>

					<!-- Card: Search & Replace Inputs -->
					<div class="qsrdb-card">
						<div class="qsrdb-card-header">
							<h2>
								<span class="dashicons dashicons-search"></span>
								<?php esc_html_e( 'Search & Replace Query', 'quick-search-replace' ); ?>
							</h2>
						</div>
						<div class="qsrdb-card-body">
							<div class="qsrdb-form-row">
								<label for="qsrdb-search" class="qsrdb-label">
									<?php esc_html_e( 'Search for', 'quick-search-replace' ); ?>
									<span class="qsrdb-required">*</span>
								</label>
								<div class="qsrdb-input-wrap">
									<input
										type="text"
										id="qsrdb-search"
										name="qsrdb_search"
										class="regular-text qsrdb-input-full"
										value="<?php echo esc_attr( $search ); ?>"
										placeholder="<?php esc_attr_e( 'e.g. http://olddomain.com or old text', 'quick-search-replace' ); ?>"
										required
									>
									<p class="description">
										<?php esc_html_e( 'Enter the exact string or URL you want to locate across the selected tables.', 'quick-search-replace' ); ?>
									</p>
								</div>
							</div>

							<div class="qsrdb-form-row">
								<label for="qsrdb-replace" class="qsrdb-label">
									<?php esc_html_e( 'Replace with', 'quick-search-replace' ); ?>
								</label>
								<div class="qsrdb-input-wrap">
									<input
										type="text"
										id="qsrdb-replace"
										name="qsrdb_replace"
										class="regular-text qsrdb-input-full"
										value="<?php echo esc_attr( $replace ); ?>"
										placeholder="<?php esc_attr_e( 'e.g. https://newdomain.com or new text', 'quick-search-replace' ); ?>"
									>
									<p class="description">
										<?php esc_html_e( 'Enter the replacement text. Leave empty if you wish to remove the search string entirely.', 'quick-search-replace' ); ?>
									</p>
								</div>
							</div>
						</div>
					</div>

					<!-- Card: Configuration Options -->
					<div class="qsrdb-card">
						<div class="qsrdb-card-header">
							<h2>
								<span class="dashicons dashicons-admin-settings"></span>
								<?php esc_html_e( 'Execution Options', 'quick-search-replace' ); ?>
							</h2>
						</div>
						<div class="qsrdb-card-body">
							<div class="qsrdb-options-grid">
								<!-- Option: Dry Run -->
								<label class="qsrdb-option-card">
									<input
										type="checkbox"
										id="qsrdb-dry-run-toggle"
										name="qsrdb_dry_run_toggle"
										value="1"
										<?php checked( $is_dry_run ); ?>
									>
									<div class="qsrdb-option-info">
										<strong><?php esc_html_e( 'Run as Dry Run (Recommended first)', 'quick-search-replace' ); ?></strong>
										<span><?php esc_html_e( 'Simulate the process and preview matched rows without modifying the database.', 'quick-search-replace' ); ?></span>
									</div>
								</label>

								<!-- Option: Smart URL Matching -->
								<label class="qsrdb-option-card">
									<input
										type="checkbox"
										name="qsrdb_smart_url"
										value="1"
										<?php checked( $smart_url ); ?>
									>
									<div class="qsrdb-option-info">
										<strong><?php esc_html_e( 'Smart URL & Domain Matching', 'quick-search-replace' ); ?></strong>
										<span><?php esc_html_e( 'Automatically matches http/https, www, protocol-relative, and JSON-escaped URLs safely.', 'quick-search-replace' ); ?></span>
									</div>
								</label>

								<!-- Option: Skip GUIDs -->
								<label class="qsrdb-option-card">
									<input
										type="checkbox"
										name="qsrdb_skip_guid"
										value="1"
										<?php checked( $skip_guid ); ?>
									>
									<div class="qsrdb-option-info">
										<strong><?php esc_html_e( 'Skip GUID Column in Posts', 'quick-search-replace' ); ?></strong>
										<span><?php esc_html_e( 'Recommended by WordPress to avoid feed readers interpreting old posts as new entries.', 'quick-search-replace' ); ?></span>
									</div>
								</label>

								<!-- Option: Case-Insensitive -->
								<label class="qsrdb-option-card">
									<input
										type="checkbox"
										name="qsrdb_case_insensitive"
										value="1"
										<?php checked( $case_insensitive ); ?>
									>
									<div class="qsrdb-option-info">
										<strong><?php esc_html_e( 'Case-Insensitive Search', 'quick-search-replace' ); ?></strong>
										<span><?php esc_html_e( 'Match letters regardless of uppercase or lowercase distinctions.', 'quick-search-replace' ); ?></span>
									</div>
								</label>
							</div>
						</div>
					</div>

					<!-- Card: Select Tables -->
					<div class="qsrdb-card">
						<div class="qsrdb-card-header qsrdb-flex-between">
							<h2>
								<span class="dashicons dashicons-editor-table"></span>
								<?php esc_html_e( 'Select Database Tables', 'quick-search-replace' ); ?>
							</h2>
							<span id="qsrdb-selection-badge" class="qsrdb-selection-badge">
								<?php
								printf(
									/* translators: 1: selected count, 2: total count */
									esc_html__( '%1$d of %2$d selected', 'quick-search-replace' ),
									count( $selected_tables ),
									count( $table_details )
								);
								?>
							</span>
						</div>
						<div class="qsrdb-card-body">
							<!-- Tables Toolbar -->
							<div class="qsrdb-table-toolbar">
								<div class="qsrdb-search-box">
									<span class="dashicons dashicons-search"></span>
									<input
										type="search"
										id="qsrdb-table-filter"
										placeholder="<?php esc_attr_e( 'Filter tables by name (e.g. posts, options)...', 'quick-search-replace' ); ?>"
									>
								</div>
								<div class="qsrdb-btn-group">
									<button type="button" class="button button-small" id="qsrdb-select-all">
										<?php esc_html_e( 'Select All', 'quick-search-replace' ); ?>
									</button>
									<button type="button" class="button button-small" id="qsrdb-deselect-all">
										<?php esc_html_e( 'Deselect All', 'quick-search-replace' ); ?>
									</button>
									<button type="button" class="button button-small" id="qsrdb-select-core">
										<?php esc_html_e( 'Core Tables Only', 'quick-search-replace' ); ?>
									</button>
								</div>
							</div>

							<!-- Tables List -->
							<div class="qsrdb-tables-container" id="qsrdb-tables-list">
								<?php foreach ( $table_details as $t_info ) : ?>
									<?php
									$t_name   = $t_info['name'];
									$is_sel   = in_array( $t_name, $selected_tables, true );
									$is_core  = ! empty( $t_info['is_core'] );
									$row_cnt  = $t_info['rows'];
									$t_size   = $t_info['size'];
									?>
									<label class="qsrdb-table-row" data-table="<?php echo esc_attr( strtolower( $t_name ) ); ?>" data-core="<?php echo $is_core ? '1' : '0'; ?>">
										<div class="qsrdb-table-col-check">
											<input
												type="checkbox"
												name="qsrdb_tables[]"
												value="<?php echo esc_attr( $t_name ); ?>"
												<?php checked( $is_sel ); ?>
											>
											<code><?php echo esc_html( $t_name ); ?></code>
											<?php if ( $is_core ) : ?>
												<span class="qsrdb-tag-core"><?php esc_html_e( 'Core', 'quick-search-replace' ); ?></span>
											<?php endif; ?>
										</div>
										<div class="qsrdb-table-col-meta">
											<span class="qsrdb-meta-pill" title="<?php esc_attr_e( 'Approximate row count', 'quick-search-replace' ); ?>">
												<?php
												printf(
													/* translators: %s: number of rows */
													esc_html__( '%s rows', 'quick-search-replace' ),
													esc_html( number_format_i18n( $row_cnt ) )
												);
												?>
											</span>
											<span class="qsrdb-meta-pill" title="<?php esc_attr_e( 'Table data + index size', 'quick-search-replace' ); ?>">
												<?php echo esc_html( size_format( $t_size ) ); ?>
											</span>
										</div>
									</label>
								<?php endforeach; ?>
								<div id="qsrdb-no-tables" class="qsrdb-empty-state" style="display: none;">
									<?php esc_html_e( 'No tables match your filter.', 'quick-search-replace' ); ?>
								</div>
							</div>
						</div>
					</div>

					<!-- Action Buttons -->
					<div class="qsrdb-submit-bar">
						<div class="qsrdb-submit-buttons">
							<button
								type="submit"
								name="qsrdb_dry_run_btn"
								id="qsrdb-btn-dry"
								class="button button-primary button-hero qsrdb-btn-dry-style"
							>
								<span class="dashicons dashicons-search"></span>
								<?php esc_html_e( 'Run Dry Run', 'quick-search-replace' ); ?>
							</button>

							<button
								type="submit"
								name="qsrdb_run_live"
								id="qsrdb-btn-live"
								class="button button-secondary button-hero qsrdb-btn-live-style"
							>
								<span class="dashicons dashicons-warning"></span>
								<?php esc_html_e( 'Run Live Search/Replace', 'quick-search-replace' ); ?>
							</button>
						</div>
						<p class="qsrdb-submit-hint">
							<span class="dashicons dashicons-shield"></span>
							<?php esc_html_e( 'Always generate a complete database backup prior to performing a live search and replace.', 'quick-search-replace' ); ?>
						</p>
					</div>
				</form>
			</div>

			<!-- Sidebar / Information Panel -->
			<div class="qsrdb-side-col">
				<!-- Safety & Backup Card -->
				<div class="qsrdb-card qsrdb-side-card">
					<div class="qsrdb-card-header">
						<h3>
							<span class="dashicons dashicons-shield-alt"></span>
							<?php esc_html_e( 'Database Safety', 'quick-search-replace' ); ?>
						</h3>
					</div>
					<div class="qsrdb-card-body">
						<p>
							<?php esc_html_e( 'Direct database modifications cannot be undone. We strongly advise taking a full SQL backup using your hosting panel, phpMyAdmin, or a backup plugin before executing a live run.', 'quick-search-replace' ); ?>
						</p>
						<ul class="qsrdb-checklist">
							<li><span class="dashicons dashicons-yes"></span> <?php esc_html_e( 'Full database backup taken', 'quick-search-replace' ); ?></li>
							<li><span class="dashicons dashicons-yes"></span> <?php esc_html_e( 'Dry run verified first', 'quick-search-replace' ); ?></li>
							<li><span class="dashicons dashicons-yes"></span> <?php esc_html_e( 'Target URLs checked for typos', 'quick-search-replace' ); ?></li>
						</ul>
					</div>
				</div>

				<!-- Serialized Data Notice Card -->
				<div class="qsrdb-card qsrdb-side-card">
					<div class="qsrdb-card-header">
						<h3>
							<span class="dashicons dashicons-randomize"></span>
							<?php esc_html_e( 'Serialized Data Support', 'quick-search-replace' ); ?>
						</h3>
					</div>
					<div class="qsrdb-card-body">
						<p>
							<?php esc_html_e( 'WordPress and modern plugins frequently store arrays and objects as PHP serialized strings. Simple SQL queries break these strings by causing string-length mismatches.', 'quick-search-replace' ); ?>
						</p>
						<p>
							<?php esc_html_e( 'Quick Search Replace safely deserializes, substitutes data recursively, and accurately recalculates byte lengths upon reserialization.', 'quick-search-replace' ); ?>
						</p>
					</div>
				</div>

				<!-- Site Migration Best Practices -->
				<div class="qsrdb-card qsrdb-side-card">
					<div class="qsrdb-card-header">
						<h3>
							<span class="dashicons dashicons-admin-links"></span>
							<?php esc_html_e( 'Domain Migration Tips', 'quick-search-replace' ); ?>
						</h3>
					</div>
					<div class="qsrdb-card-body">
						<p>
							<?php esc_html_e( 'When moving to a new domain or switching from HTTP to HTTPS:', 'quick-search-replace' ); ?>
						</p>
						<ol class="qsrdb-steps-list">
							<li><?php esc_html_e( 'Enter full URLs without trailing slashes in both fields.', 'quick-search-replace' ); ?></li>
							<li><?php esc_html_e( 'Keep Smart URL matching enabled to catch protocol and JSON variants.', 'quick-search-replace' ); ?></li>
							<li><?php esc_html_e( 'Permalinks are automatically flushed following a live run.', 'quick-search-replace' ); ?></li>
						</ol>
					</div>
				</div>
			</div>
		</div>
	</div>
	<?php
}
