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
	$search          = '';
	$replace         = '';
	$selected_tables = array();
	$report          = null;
	$is_dry_run      = true;

	// Handle form submission.
	if ( isset( $_POST['qsrdb_nonce'] ) && wp_verify_nonce( sanitize_key( $_POST['qsrdb_nonce'] ), 'qsrdb_action' ) ) {

		// --- FIX APPLIED HERE: Unslash first, then sanitize. ---
		// Unslash the entire POST array recursively.
		$post_data = wp_unslash( $_POST );

		// Sanitize the unslashed data.
		$search          = isset( $post_data['qsrdb_search'] ) ? sanitize_text_field( $post_data['qsrdb_search'] ) : '';
		$replace         = isset( $post_data['qsrdb_replace'] ) ? sanitize_text_field( $post_data['qsrdb_replace'] ) : '';
		$selected_tables = isset( $post_data['qsrdb_tables'] ) ? array_map( 'sanitize_text_field', (array) $post_data['qsrdb_tables'] ) : array();
		// --- END FIX ---

		if ( ! empty( $search ) && ! empty( $selected_tables ) ) {
			$is_dry_run = ! isset( $post_data['qsrdb_live_run'] );
			$report     = qsrdb_run_search_replace( $search, $replace, $selected_tables, $is_dry_run );

			if ( ! $is_dry_run && $report ) {
				// Best-effort update of core URLs (home / siteurl) if this is a domain move.
				$core_url_result = qsrdb_maybe_update_core_urls( $search, $replace );

				// Hard flush rewrite rules (attempt to write .htaccess if possible).
				global $wp_rewrite;
				if ( isset( $wp_rewrite ) ) {
					$wp_rewrite->init();
					$wp_rewrite->flush_rules( true );
				} else {
					flush_rewrite_rules( true );
				}

				// Show a more informative success message with counts.
				$msg = sprintf(
					/* translators: 1: fields updated, 2: rows updated */
					__( 'Live run complete. %1$d fields updated across %2$d rows. Permalink rules flushed.', 'quick-search-replace' ),
					absint( $report['fields_updated'] ),
					absint( $report['rows_updated'] )
				);

				if ( $core_url_result['home_updated'] || $core_url_result['siteurl_updated'] ) {
					$msg .= ' ' . __( 'Site URL options updated (home/siteurl).', 'quick-search-replace' );
				}

				add_settings_error( 'qsrdb-notices', 'qsrdb-flushed', $msg, 'success' );
			}
		} else {
			add_settings_error( 'qsrdb-notices', 'qsrdb-error', __( 'Please fill in the "Search for" field and select at least one table.', 'quick-search-replace' ), 'error' );
		}
	}

	$all_tables = qsrdb_get_all_tables();
	?>
	<div class="wrap qsrdb-wrap">
		<h1><?php esc_html_e( 'Quick Search Replace', 'quick-search-replace' ); ?></h1>
		<p><?php esc_html_e( 'Run a search and replace on your WordPress database. Use with caution!', 'quick-search-replace' ); ?></p>

		<?php
		// Strong warning if URLs are hard-coded in wp-config.php (these override DB values).
		if ( defined( 'WP_HOME' ) || defined( 'WP_SITEURL' ) ) :
			?>
			<div class="notice notice-error">
				<h2><?php esc_html_e( 'Important Warning: Hard-coded URLs Detected', 'quick-search-replace' ); ?></h2>
				<p>
					<?php esc_html_e( 'Your wp-config.php defines WP_HOME or WP_SITEURL. These override database values. Update or remove them to complete a domain change.', 'quick-search-replace' ); ?>
				</p>
			</div>
			<?php
		endif;
		?>

		<?php settings_errors( 'qsrdb-notices' ); ?>

		<?php if ( $report ) : ?>
			<div id="qsrdb-report" class="notice <?php echo ! empty( $report['errors'] ) ? 'notice-warning' : 'notice-success'; ?> is-dismissible">
				<h2><?php echo $is_dry_run ? esc_html__( 'Dry Run Report', 'quick-search-replace' ) : esc_html__( 'Live Run Report', 'quick-search-replace' ); ?></h2>

				<?php if ( ! empty( $report['errors'] ) ) : ?>
					<div class="notice notice-error" style="margin: 10px 0;">
						<p><strong><?php esc_html_e( 'Errors occurred during the operation:', 'quick-search-replace' ); ?></strong></p>
						<ul>
							<?php foreach ( $report['errors'] as $error ) : ?>
								<li><?php echo esc_html( $error ); ?></li>
							<?php endforeach; ?>
						</ul>
					</div>
				<?php endif; ?>

				<p>
					<?php
					/* translators: 1: number of tables, 2: number of rows scanned, 3: number of rows updated, 4: number of fields updated */
					printf(
						esc_html__( 'Scanned %1$d tables and %2$d rows. Updated %3$d rows and %4$d fields.', 'quick-search-replace' ),
						absint( $report['tables_scanned'] ),
						absint( $report['rows_scanned'] ),
						absint( $report['rows_updated'] ),
						absint( $report['fields_updated'] )
					);
					?>
				</p>

				<h3><?php esc_html_e( 'Affected Tables:', 'quick-search-replace' ); ?></h3>
				<ul>
					<?php
					$found_changes = false;
					foreach ( $report['details'] as $table => $count ) {
						if ( $count > 0 ) {
							$found_changes = true;
							printf(
								'<li>%1$s (%2$d rows affected)</li>',
								'<code>' . esc_html( $table ) . '</code>',
								absint( $count )
							);
						}
					}
					if ( ! $found_changes ) {
						echo '<li>' . esc_html__( 'No changes were made in the selected tables.', 'quick-search-replace' ) . '</li>';
					}
					?>
				</ul>
			</div>
		<?php endif; ?>

		<div class="qsrdb-card">
			<div class="qsrdb-card-header">
				<h2><?php esc_html_e( 'Search & Replace', 'quick-search-replace' ); ?></h2>
			</div>
			<div class="qsrdb-card-body">
				<form method="post" action="">
					<?php wp_nonce_field( 'qsrdb_action', 'qsrdb_nonce' ); ?>
					<table class="form-table">
						<tbody>
							<tr>
								<th scope="row">
									<label for="qsrdb-search"><?php esc_html_e( 'Search for', 'quick-search-replace' ); ?></label>
								</th>
								<td>
									<input type="text" id="qsrdb-search" name="qsrdb_search" class="regular-text" value="<?php echo esc_attr( $search ); ?>" required>
									<p class="description"><?php esc_html_e( 'For domain moves, enter the old site URL (e.g., http://old-domain.com).', 'quick-search-replace' ); ?></p>
								</td>
							</tr>
							<tr>
								<th scope="row">
									<label for="qsrdb-replace"><?php esc_html_e( 'Replace with', 'quick-search-replace' ); ?></label>
								</th>
								<td>
									<input type="text" id="qsrdb-replace" name="qsrdb_replace" class="regular-text" value="<?php echo esc_attr( $replace ); ?>">
									<p class="description"><?php esc_html_e( 'For domain moves, enter the new site URL (e.g., https://new-domain.com).', 'quick-search-replace' ); ?></p>
								</td>
							</tr>
							<tr>
								<th scope="row">
									<label for="qsrdb-tables"><?php esc_html_e( 'Select Tables', 'quick-search-replace' ); ?></label>
								</th>
								<td>
									<fieldset id="qsrdb-tables">
										<p>
											<a href="#" id="qsrdb-select-all"><?php esc_html_e( 'Select All', 'quick-search-replace' ); ?></a> |
											<a href="#" id="qsrdb-deselect-all"><?php esc_html_e( 'Deselect All', 'quick-search-replace' ); ?></a>
										</p>
										<div class="qsrdb-tables-list">
											<?php foreach ( $all_tables as $table ) : ?>
												<label>
													<input type="checkbox" name="qsrdb_tables[]" value="<?php echo esc_attr( $table ); ?>" <?php checked( in_array( $table, $selected_tables, true ) ); ?>>
													<?php echo esc_html( $table ); ?>
												</label><br>
											<?php endforeach; ?>
										</div>
									</fieldset>
								</td>
							</tr>
						</tbody>
					</table>

					<div class="qsrdb-actions">
						<p class="submit">
							<input type="submit" name="qsrdb_dry_run" class="button button-secondary" value="<?php esc_attr_e( 'Run Dry Run', 'quick-search-replace' ); ?>">
							<input type="submit" name="qsrdb_live_run" class="button button-primary" value="<?php esc_attr_e( 'Run Search/Replace', 'quick-search-replace' ); ?>" onclick="return confirm('<?php esc_attr_e( 'WARNING: You are about to run a LIVE search/replace on your database. This action cannot be undone. It is highly recommended that you create a database backup first. Are you sure you want to proceed?', 'quick-search-replace' ); ?>');">
						</p>
					</div>
				</form>
			</div>
		</div>

		<div class="qsrdb-card qsrdb-warning">
			<h3><?php esc_html_e( 'Important Notice', 'quick-search-replace' ); ?></h3>
			<p><strong><?php esc_html_e( 'ALWAYS back up your database before using this tool.', 'quick-search-replace' ); ?></strong></p>
			<p><?php esc_html_e( 'A "Dry Run" shows what would change; a "Live Run" performs those changes permanently.', 'quick-search-replace' ); ?></p>
		</div>

		<script>
			document.addEventListener('DOMContentLoaded', function() {
				var selAll = document.getElementById('qsrdb-select-all');
				var desAll = document.getElementById('qsrdb-deselect-all');
				if (selAll) {
					selAll.addEventListener('click', function(e) {
						e.preventDefault();
						document.querySelectorAll('#qsrdb-tables input[type="checkbox"]').forEach(function(el) {
							el.checked = true;
						});
					});
				}
				if (desAll) {
					desAll.addEventListener('click', function(e) {
						e.preventDefault();
						document.querySelectorAll('#qsrdb-tables input[type="checkbox"]').forEach(function(el) {
							el.checked = false;
						});
					});
				}
			});
		</script>
	</div>
	<?php
}