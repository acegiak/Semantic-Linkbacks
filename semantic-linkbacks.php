<?php
/**
 * Plugin Name: Semantic-Linkbacks
 * Plugin URI: https://github.com/pfefferle/wordpress-semantic-linkbacks
 * Description: Semantic Linkbacks for WebMentions, Trackbacks and Pingbacks
 * Author: pfefferle
 * Author URI: http://notizblog.org/
 * Version: 3.2.1
 * Text Domain: semantic_linkbacks
 */

// check if php version is >= 5.3
// version is required by the mf2 parser
// FIXME: Technically it can run just not without the MF2 functionality
// But what does it do if not MF2 parsing?
function semantic_linkbacks_activation() {
	if ( version_compare( phpversion(), 5.3, '<' ) ) {
		die( 'The minimum PHP version required for this plugin is 5.3' );
	}
}
register_activation_hook( __FILE__, 'semantic_linkbacks_activation' );

add_action( 'plugins_loaded', array( 'Semantic_Linkbacks_Plugin', 'init' ) );

/**
 * Semantic linkbacks class
 *
 * @author Matthias Pfefferle
 */
class Semantic_Linkbacks_Plugin {
	/**
	 * Initialize the plugin, registering WordPress hooks.
	 */
	public static function init() {
		require_once( dirname( __FILE__ ) . '/includes/functions.php' );

		require_once( dirname( __FILE__ ) . '/includes/class-linkbacks-handler.php' );
		add_action( 'init', array( 'Linkbacks_Handler', 'init' ) );

		// run plugin only if php version is >= 5.3
		if ( version_compare( phpversion(), 5.3, '>=' ) ) {
			require_once 'includes/class-linkbacks-mf2-handler.php';
			add_action( 'init', array( 'Linkbacks_MF2_Handler', 'init' ) );
		}

		self::plugin_textdomain();
	}

	/**
	 * Load language files
	 */
	public static function plugin_textdomain() {
		// Note to self, the third argument must not be hardcoded, to account for relocated folders.
		load_plugin_textdomain( 'semantic_linkbacks', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
	}

}
