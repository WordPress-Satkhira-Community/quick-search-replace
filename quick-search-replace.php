<?php
/**
 * Plugin Name:       Quick Search Replace
 * Plugin URI:        https://delowerhossain.com/plugins/quick-search-replace
 * Description:       A simple and powerful tool to run search and replace queries on your WordPress database. Supports serialized data and multisite.
 * Version:           1.0.0
 * Author:            Delower Hossain
 * Author URI:        https://delowerhossain.com/
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       quick-search-replace
 * Domain Path:       /languages
 */

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

define( 'QSRDB_VERSION', '1.2.0' );
define( 'QSRDB_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );

/**
 * The core plugin class that is used to define internationalization,
 * admin-specific hooks, and public-facing site hooks.
 */
require QSRDB_PLUGIN_DIR . 'includes/functions.php';
require QSRDB_PLUGIN_DIR . 'includes/admin-page.php';

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
 * @param string $hook The current admin page.
 */
function qsrdb_enqueue_admin_assets( $hook ) {
	// Only load on our plugin's page.
	if ( 'tools_page_quick-search-replace' !== $hook ) {
		return;
	}

	wp_enqueue_style(
		'qsrdb-admin-style',
		plugin_dir_url( __FILE__ ) . 'assets/css/admin-style.css',
		array(),
		QSRDB_VERSION,
		'all'
	);

	// --- Enqueue admin script ---
	wp_enqueue_script(
		'qsrdb-admin-script',
		plugin_dir_url( __FILE__ ) . 'assets/js/admin-script.js',
		array(), // No dependencies.
		QSRDB_VERSION,
		true // Load in the footer.
	);
	
}
add_action( 'admin_enqueue_scripts', 'qsrdb_enqueue_admin_assets' );