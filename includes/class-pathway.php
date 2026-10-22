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
		add_action( 'template_redirect', array( self::class, 'no_cache' ) );
		add_filter( 'wp_robots', array( self::class, 'robots' ) );
		add_action( 'wp_enqueue_scripts', array( self::class, 'assets' ) );
		add_filter( 'display_post_states', array( self::class, 'post_state' ), 10, 2 );
		if ( ! get_option( self::OPTION_PAGE ) ) {
			add_action( 'init', array( self::class, 'ensure_page' ) );
		}
	}

	/** Creates the page and makes it the front page of a site that has none (applicants come first). */
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
			if ( 'posts' === get_option( 'show_on_front' ) ) {
				update_option( 'show_on_front', 'page' );
				update_option( 'page_on_front', $page_id );
			}
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

	/**
	 * Sent on template_redirect, which runs before WordPress ends HEAD requests: a HEAD response
	 * without no-store would otherwise let Cloudflare's "Cache Everything" keep this page.
	 */
	public static function no_cache(): void {
		if ( self::is_pathway() ) {
			nocache_headers();
		}
	}

	public static function template( string $template ): string {
		if ( ! self::is_pathway() ) {
			return $template;
		}
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
					'jobsUrl'    => Dashboard::url(),
					'districts'  => Agencies::employer_notes(),
					'planner'    => self::planner(),
					'agencies'   => self::route_agencies(),
					'checklist'  => $data['checklist'],
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
	 * Employers with open substitute postings, with the staffing agencies their postings use
	 * and what we've confirmed about how each hires subs.
	 *
	 * @return array<int, array> employer, county, count, kinds, payMin, payMax, agencies, degree, extras, source, verified
	 */
	public static function hiring_now(): array {
		$notes = Agencies::employer_notes();
		$rows  = array();
		foreach ( Snapshot::get()['subEmployers'] ?? array() as $e ) {
			$note   = $notes[ Places::normalize( $e['employer'] ) ] ?? array();
			$rows[] = $e + array(
				'agencies' => array(),
				'degree'   => $note['degree'] ?? '',
				'extras'   => $note['extras'] ?? '',
				'source'   => $note['source'] ?? '',
				'verified' => $note['verified_on'] ?? '',
			);
		}
		return $rows;
	}

	/**
	 * Every employer a sub in the three counties could work for, with how it hires daily subs:
	 * the researched routes in data/agencies.json, every other public school district in NCES's
	 * directory (route not yet confirmed), and any other employer with open sub postings. Live
	 * postings add the count and posted day pay.
	 *
	 * @return array<int, array> name, county, public, route (agency key, 'direct' or 'unknown'),
	 *                           likely, apply, degree, extras, source, open, payMin, payMax, board
	 */
	public static function planner(): array {
		$places = Places::instance();
		$live   = array();
		foreach ( Snapshot::get()['subEmployers'] ?? array() as $e ) {
			$live[ Places::normalize( $e['employer'] ) ] = $e;
		}
		$rows = array();
		$add  = static function ( string $name, string $county, array $extra ) use ( &$rows, &$live ) {
			$key = Places::normalize( $name );
			if ( isset( $rows[ $key ] ) || '' === $county ) {
				return;
			}
			$e            = $live[ $key ] ?? null;
			$rows[ $key ] = $extra + array(
				'name'   => $name,
				'county' => $county,
				'public' => 'public' === Places::instance()->sector( $name ),
				'open'   => $e['count'] ?? 0,
				'payMin' => $e['payMin'] ?? null,
				'payMax' => $e['payMax'] ?? null,
				'board'  => $e['employer'] ?? '',
			);
		};

		foreach ( Agencies::employer_notes() as $note ) {
			$route = Agencies::route( $note['name'], 'daily' );
			$add(
				$note['name'],
				Plugin::COUNTIES[ $places->resolve( $note['name'], '' )['code'] ] ?? '',
				array(
					'route'  => $route['agency'] ?? Agencies::UNKNOWN,
					'likely' => 'likely' === ( $route['confidence'] ?? '' ),
					'apply'  => $note['apply_url'] ?? '',
					'degree' => $note['degree'] ?? '',
					'extras' => $note['extras'] ?? '',
					'source' => $note['source'] ?? '',
				)
			);
		}
		$unconfirmed = array(
			'route'  => Agencies::UNKNOWN,
			'likely' => false,
			'apply'  => '',
			'degree' => '',
			'extras' => '',
			'source' => '',
		);
		foreach ( $places->districts_in( array_keys( Plugin::COUNTIES ) ) as $name => $code ) {
			// Traditional districts only: independent charters ("… Inc") and the CESA aren't sub employers we can route.
			if ( preg_match( '/\binc\b|cooperative ed/i', $name ) ) {
				continue;
			}
			$add( $name, Plugin::COUNTIES[ $code ], $unconfirmed );
		}
		foreach ( $live as $e ) {
			$named = array_values( array_diff( array_keys( $e['agencies'] ), array( Agencies::DIRECT, Agencies::UNKNOWN ) ) );
			$add( $e['employer'], $e['county'], array( 'route' => 1 === count( $named ) ? $named[0] : Agencies::UNKNOWN ) + $unconfirmed );
		}
		$rows = array_values( $rows );
		usort( $rows, static fn( $a, $b ) => strcmp( $a['county'], $b['county'] ) ?: strcasecmp( $a['name'], $b['name'] ) );
		return $rows;
	}

	/** The facts the planner shows for each agency that some employer routes daily subs through. */
	public static function route_agencies(): array {
		$keys = array_unique( array_column( self::planner(), 'route' ) );
		$out  = array();
		foreach ( Agencies::all() as $key => $agency ) {
			if ( in_array( $key, $keys, true ) ) {
				$out[ $key ] = array_intersect_key( $agency, array_flip( array( 'name', 'apply_url', 'info_url', 'degree', 'retirement', 'process', 'sources', 'verified_on' ) ) );
			}
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
		foreach ( $data['delays'] ?? array() as $d ) {
			$items[ 'Delay: ' . $d['title'] ] = $d['verified_on'] ?? '';
		}
		return array_merge( array_filter( $items, static fn( $date ) => '' === $date || $date < $cutoff ), Agencies::stale( $cutoff ) );
	}
}
