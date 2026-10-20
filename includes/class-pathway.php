<?php
namespace MMI_Teach;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The public "Become a substitute teacher" page: which DPI license fits, which training,
 * the exact DPI application steps, where to apply (live substitute postings plus each
 * employer's hiring route) and a cost-and-timeline plan. Facts come from data/pathway.json;
 * live numbers come from the stored snapshot, so a page view does no work.
 */
final class Pathway {

	public const OPTION_PAGE = 'mmi_teach_pathway_page_id';

	/** Curated facts older than this are listed on the settings screen for re-checking. */
	public const STALE_DAYS = 120;

	private static ?array $data = null;

	public static function register(): void {
		add_filter( 'template_include', array( self::class, 'template' ), 99 );
		add_filter( 'wp_robots', array( self::class, 'robots' ) );
		add_action( 'wp_enqueue_scripts', array( self::class, 'assets' ) );
		add_filter( 'display_post_states', array( self::class, 'post_state' ), 10, 2 );
		if ( ! get_option( self::OPTION_PAGE ) ) {
			add_action( 'init', array( self::class, 'ensure_page' ) );
		}
	}

	public static function ensure_page(): void {
		$page_id = (int) get_option( self::OPTION_PAGE );
		if ( $page_id && get_post( $page_id ) ) {
			return;
		}
		$page_id = wp_insert_post(
			array(
				'post_type'   => 'page',
				'post_status' => 'publish',
				'post_title'  => __( 'Become a Substitute Teacher', 'mmi-teach' ),
				'post_name'   => 'substitute',
			)
		);
		if ( $page_id && ! is_wp_error( $page_id ) ) {
			update_option( self::OPTION_PAGE, $page_id, false );
		}
	}

	public static function is_pathway(): bool {
		$page_id = (int) get_option( self::OPTION_PAGE );
		return $page_id && is_page( $page_id );
	}

	public static function url(): string {
		$page_id = (int) get_option( self::OPTION_PAGE );
		return $page_id ? (string) get_permalink( $page_id ) : '';
	}

	public static function template( string $template ): string {
		if ( ! self::is_pathway() ) {
			return $template;
		}
		nocache_headers();
		return MMI_TEACH_DIR . 'templates/pathway.php';
	}

	public static function robots( array $robots ): array {
		if ( self::is_pathway() && 'public' !== Plugin::settings()['visibility'] ) {
			$robots['noindex']  = true;
			$robots['nofollow'] = true;
		}
		return $robots;
	}

	public static function assets(): void {
		if ( ! self::is_pathway() ) {
			return;
		}
		wp_enqueue_style( 'mmi-teach-dashboard', MMI_TEACH_URL . 'assets/dashboard.css', array(), MMI_TEACH_VERSION );
		if ( ! Dashboard::can_view() ) {
			return;
		}
		wp_enqueue_script( 'mmi-teach-pathway', MMI_TEACH_URL . 'assets/pathway.js', array(), MMI_TEACH_VERSION, array( 'in_footer' => true ) );
		$data = self::data();
		wp_add_inline_script(
			'mmi-teach-pathway',
			'window.mmiTeachPathway = ' . wp_json_encode(
				array(
					'dpi'        => $data['dpi'],
					'licenses'   => $data['licenses'],
					'longTerm'   => $data['long_term_note'],
					'training'   => $data['training'],
					'jobsUrl'    => home_url( '/' ),
					'districts'  => self::district_notes(),
				),
				JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
			) . ';',
			'before'
		);
	}

	public static function post_state( array $states, \WP_Post $post ): array {
		if ( (int) get_option( self::OPTION_PAGE ) === $post->ID ) {
			$states['mmi_teach_pathway'] = __( 'Substitute pathway', 'mmi-teach' );
		}
		return $states;
	}

	/** data/pathway.json, read once per request. */
	public static function data(): array {
		if ( null === self::$data ) {
			$raw        = json_decode( (string) file_get_contents( MMI_TEACH_DIR . 'data/pathway.json' ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local plugin file.
			self::$data = is_array( $raw ) ? $raw : array();
		}
		return self::$data;
	}

	/**
	 * Employers with open substitute postings, joined to what we know about how each hires:
	 * directly, or through EDUStaff or Kelly Education.
	 *
	 * @return array<int, array> employer, county, count, kinds, payMin, payMax, via, degree, extras, apply, source, verified
	 */
	public static function hiring_now(): array {
		$snapshot = Snapshot::get();
		$notes    = self::district_notes();
		$rows     = array();
		foreach ( $snapshot['subEmployers'] ?? array() as $e ) {
			$note   = $notes[ Places::normalize( $e['employer'] ) ] ?? array();
			$rows[] = $e + array(
				'via'      => $note['via'] ?? '',
				'degree'   => $note['degree'] ?? '',
				'extras'   => $note['extras'] ?? '',
				'apply'    => $note['apply_url'] ?? '',
				'source'   => $note['source'] ?? '',
				'verified' => $note['verified_on'] ?? '',
			);
		}
		return $rows;
	}

	/** Curated district notes keyed by normalized employer name. */
	public static function district_notes(): array {
		$out = array();
		foreach ( self::data()['districts'] ?? array() as $name => $note ) {
			$out[ Places::normalize( $name ) ] = $note + array( 'name' => $name );
		}
		return $out;
	}

	/** Every curated entry whose verified_on date is older than STALE_DAYS, for the settings screen. */
	public static function stale_entries(): array {
		$data   = self::data();
		$cutoff = gmdate( 'Y-m-d', time() - self::STALE_DAYS * DAY_IN_SECONDS );
		$items  = array( 'DPI application steps and fees' => $data['dpi']['verified_on'] ?? '' );
		foreach ( $data['licenses'] ?? array() as $l ) {
			$items[ $l['name'] ] = $l['verified_on'] ?? '';
		}
		foreach ( $data['training'] ?? array() as $t ) {
			$items[ 'Training: ' . $t['provider'] ] = $t['verified_on'] ?? '';
		}
		foreach ( $data['agencies'] ?? array() as $a ) {
			$items[ 'Agency: ' . $a['name'] ] = $a['verified_on'] ?? '';
		}
		foreach ( $data['districts'] ?? array() as $name => $d ) {
			$items[ 'District: ' . $name ] = $d['verified_on'] ?? '';
		}
		return array_filter( $items, static fn( $date ) => '' === $date || $date < $cutoff );
	}
}
