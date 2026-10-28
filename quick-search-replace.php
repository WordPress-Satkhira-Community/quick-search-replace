<?php
/**
 * Plugin Name:       Quick Search Replace
 * Plugin URI:        https://delowerhossain.com/plugins/quick-search-replace
 * Description:       A simple and powerful tool to run search and replace queries on your WordPress database. Supports serialized data and multisite.
 * Version:           1.0.1
 * Author:            Delower Hossain
 * Author URI:        https://delowerhossain.com/
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       quick-search-replace
 * Domain Path:       /languages
 * Requires at least: 6.2
 * Requires PHP:      8.0
 */

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

define( 'QSRDB_VERSION', '1.0.1' );
define( 'QSRDB_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'QSRDB_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'QSRDB_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

/**
 * The core plugin class that is used to define internationalization,
 * admin-specific hooks, and public-facing site hooks.
 */
require_once QSRDB_PLUGIN_DIR . 'includes/functions.php';
require_once QSRDB_PLUGIN_DIR . 'includes/admin-page.php';

/**
 * Add the plugin's administration page to the Tools menu.
 */
function qsrdb_add_admin_menu() {
	add_management_page(
		__( 'Quick Search Replace', 'quick-search-replace' ),
		__( 'Quick Search Replace', 'quick-search-replace' ),
		'manage_options',
		'quick-search-replace',
		'qsrdb_render_admin_page'
	);
}
add_action( 'admin_menu', 'qsrdb_add_admin_menu' );
add_action( 'network_admin_menu', 'qsrdb_add_admin_menu' );

/**
 * Enqueue admin-specific stylesheets and scripts.
 *
 * @param string $hook The current admin page hook suffix.
 */
function qsrdb_enqueue_admin_assets( $hook ) {
	// Only load on our plugin's page in both single-site and network admin.
	if ( false === strpos( $hook, 'quick-search-replace' ) ) {
		return;
	}

	wp_enqueue_style(
		'qsrdb-admin-style',
		QSRDB_PLUGIN_URL . 'assets/css/admin-style.css',
		array(),
		QSRDB_VERSION,
		'all'
	);

	wp_enqueue_script(
		'qsrdb-admin-script',
		QSRDB_PLUGIN_URL . 'assets/js/admin-script.js',
		array(),
		QSRDB_VERSION,
		true
	);

	// Pass localized strings to script.
	wp_localize_script(
		'qsrdb-admin-script',
		'qsrdbL10n',
		array(
			'confirmLiveRun' => __( 'WARNING: You are about to run a LIVE search and replace on your database. This action modifies your database permanently and cannot be undone.\n\nAre you sure you have created a backup and want to proceed?', 'quick-search-replace' ),
			/* translators: 1: number of selected tables, 2: total number of tables */
			'selectedCount'  => __( '%1$d of %2$d tables selected', 'quick-search-replace' ),
			'noTablesMatch'  => __( 'No tables match your filter.', 'quick-search-replace' ),
		)
	);
}
add_action( 'admin_enqueue_scripts', 'qsrdb_enqueue_admin_assets' );

/**
 * Add quick action links on the Plugins list page.
 *
 * @param array $links Array of plugin action links.
 * @return array
 */
function qsrdb_plugin_action_links( $links ) {
	$target_url = is_network_admin()
		? network_admin_url( 'tools.php?page=quick-search-replace' )
		: admin_url( 'tools.php?page=quick-search-replace' );

	$settings_link = sprintf(
		'<a href="%1$s">%2$s</a>',
		esc_url( $target_url ),
		esc_html__( 'Search & Replace', 'quick-search-replace' )
	);

	array_unshift( $links, $settings_link );
	return $links;
}
add_filter( 'plugin_action_links_' . QSRDB_PLUGIN_BASENAME, 'qsrdb_plugin_action_links' );
add_filter( 'network_admin_plugin_action_links_' . QSRDB_PLUGIN_BASENAME, 'qsrdb_plugin_action_links' );
