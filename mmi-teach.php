<?php
/**
 * Plugin Name:       MMI Teach — School Jobs, Eligibility and Applications
 * Description:       Open K-12 teacher, substitute and aide jobs in Milwaukee, Waukesha and Racine counties (Wisconsin), with an eligibility check, a sourced checklist of Wisconsin requirements and an apply-here-then-hand-off application flow, built on Bricks.
 * Version:           2.1.0
 * Requires at least: 6.6
 * Requires PHP:      8.1
 * Author:            MannMade Solutions
 * License:           GPL-2.0-or-later
 * Text Domain:       mmi-teach
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'MMI_TEACH_VERSION', '2.1.0' );
define( 'MMI_TEACH_FILE', __FILE__ );
define( 'MMI_TEACH_DIR', plugin_dir_path( __FILE__ ) );
define( 'MMI_TEACH_URL', plugin_dir_url( __FILE__ ) );

spl_autoload_register(
	static function ( string $class ): void {
		if ( ! str_starts_with( $class, 'MMI_Teach\\' ) ) {
			return;
		}
		$relative = strtolower( str_replace( '_', '-', substr( $class, strlen( 'MMI_Teach\\' ) ) ) );
		$parts    = explode( '\\', $relative );
		$name     = array_pop( $parts );
		$path     = MMI_TEACH_DIR . 'includes/' . ( $parts ? implode( '/', $parts ) . '/' : '' ) . 'class-' . $name . '.php';
		if ( is_readable( $path ) ) {
			require_once $path;
		}
	}
);

register_activation_hook( __FILE__, array( 'MMI_Teach\\Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'MMI_Teach\\Plugin', 'deactivate' ) );

add_action( 'plugins_loaded', array( 'MMI_Teach\\Plugin', 'boot' ) );
