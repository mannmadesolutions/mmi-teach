<?php
namespace MMI_Teach;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The public-facing dashboard page. It renders its own full-width template (the app needs the
 * whole viewport, not a theme's content column) and loads its data from one REST route.
 */
final class Dashboard {

	public const OPTION_PAGE = 'mmi_teach_page_id';
	private const REST_NS    = 'mmi-teach/v1';

	public static function register(): void {
		add_action( 'rest_api_init', array( self::class, 'routes' ) );
		add_filter( 'template_include', array( self::class, 'template' ), 99 );
		add_filter( 'wp_robots', array( self::class, 'robots' ) );
		add_action( 'wp_enqueue_scripts', array( self::class, 'assets' ) );
		add_filter( 'display_post_states', array( self::class, 'post_state' ), 10, 2 );
	}

	/** Creates the dashboard page on activation and makes it the front page of an empty site. */
	public static function ensure_page(): void {
		$page_id = (int) get_option( self::OPTION_PAGE );
		if ( $page_id && get_post( $page_id ) ) {
			return;
		}
		$page_id = wp_insert_post(
			array(
				'post_type'    => 'page',
				'post_status'  => 'publish',
				'post_title'   => __( 'Teaching Vacancies', 'mmi-teach' ),
				'post_name'    => 'vacancies',
				'post_content' => '',
			)
		);
		if ( ! $page_id || is_wp_error( $page_id ) ) {
			return;
		}
		update_option( self::OPTION_PAGE, $page_id, false );
		if ( 'posts' === get_option( 'show_on_front' ) ) {
			update_option( 'show_on_front', 'page' );
			update_option( 'page_on_front', $page_id );
		}
	}

	public static function is_dashboard(): bool {
		$page_id = (int) get_option( self::OPTION_PAGE );
		return $page_id && is_page( $page_id );
	}

	public static function can_view(): bool {
		return 'public' === Plugin::settings()['visibility'] || is_user_logged_in();
	}

	public static function routes(): void {
		register_rest_route(
			self::REST_NS,
			'/snapshot',
			array(
				'methods'             => 'GET',
				'permission_callback' => array( self::class, 'can_view' ),
				'callback'            => static function () {
					$response = rest_ensure_response( Snapshot::get() );
					$response->header( 'Cache-Control', 'private, no-store' );
					return $response;
				},
			)
		);
	}

	public static function template( string $template ): string {
		if ( ! self::is_dashboard() ) {
			return $template;
		}
		nocache_headers();
		return MMI_TEACH_DIR . 'templates/dashboard.php';
	}

	public static function robots( array $robots ): array {
		if ( self::is_dashboard() && 'public' !== Plugin::settings()['visibility'] ) {
			$robots['noindex']  = true;
			$robots['nofollow'] = true;
		}
		return $robots;
	}

	public static function assets(): void {
		if ( ! self::is_dashboard() ) {
			return;
		}
		wp_enqueue_style( 'mmi-teach-dashboard', MMI_TEACH_URL . 'assets/dashboard.css', array(), MMI_TEACH_VERSION );
		if ( ! self::can_view() ) {
			return;
		}
		wp_enqueue_script( 'mmi-teach-dashboard', MMI_TEACH_URL . 'assets/dashboard.js', array(), MMI_TEACH_VERSION, array( 'in_footer' => true ) );
		wp_localize_script(
			'mmi-teach-dashboard',
			'mmiTeach',
			array(
				'endpoint' => esc_url_raw( rest_url( self::REST_NS . '/snapshot' ) ),
				'nonce'    => wp_create_nonce( 'wp_rest' ),
				'settings' => current_user_can( Plugin::manage_cap() ) ? esc_url_raw( admin_url( 'options-general.php?page=mmi-teach' ) ) : '',
			)
		);
	}

	public static function post_state( array $states, \WP_Post $post ): array {
		if ( (int) get_option( self::OPTION_PAGE ) === $post->ID ) {
			$states['mmi_teach'] = __( 'Vacancy dashboard', 'mmi-teach' );
		}
		return $states;
	}
}
