<?php
namespace MMI_Teach;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Wiring: activation, settings, cron fallback and the pieces that hook into WordPress.
 */
final class Plugin {

	public const OPTION_SETTINGS = 'mmi_teach_settings';
	public const CRON_HOOK       = 'mmi_teach_refresh';

	/** Counties the dashboard covers, keyed by 5-digit county FIPS code. */
	public const COUNTIES = array(
		'55079' => 'Milwaukee',
		'55133' => 'Waukesha',
		'55101' => 'Racine',
	);

	public static function boot(): void {
		Dashboard::register();
		Admin::register();
		add_action( self::CRON_HOOK, array( self::class, 'run_cron_refresh' ) );
		add_action( self::CRON_HOOK . '_now', array( self::class, 'run_cron_refresh' ) ); // "Refresh now" on the settings screen.

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			\WP_CLI::add_command( 'mmi-teach', Cli::class );
		}
	}

	public static function activate(): void {
		Store::install();
		Dashboard::ensure_page();
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			// Fallback only: the system crontab runs `wp mmi-teach refresh` with no time limit.
			wp_schedule_event( time() + 300, 'twicedaily', self::CRON_HOOK );
		}
	}

	public static function deactivate(): void {
		wp_clear_scheduled_hook( self::CRON_HOOK );
		wp_clear_scheduled_hook( self::CRON_HOOK . '_now' );
	}

	/**
	 * WP-Cron fallback. Keeps inside the 45 s cron budget by capping detail fetches;
	 * the CLI run picks up whatever is left.
	 */
	public static function run_cron_refresh(): void {
		( new Refresher() )->run( array( 'detail_limit' => 8 ) );
	}

	public static function settings(): array {
		$saved = get_option( self::OPTION_SETTINGS, array() );
		return array_merge(
			array(
				'visibility'         => 'login', // 'login' | 'public'.
				'employer_overrides' => array(), // normalized employer key => county code | 'out'.
			),
			is_array( $saved ) ? $saved : array()
		);
	}

	public static function update_settings( array $changes ): void {
		update_option( self::OPTION_SETTINGS, array_merge( self::settings(), $changes ), false );
	}

	/** Capability for the settings screen and manual refresh. */
	public static function manage_cap(): string {
		return (string) apply_filters( 'mmi_teach_manage_capability', 'manage_options' );
	}
}
