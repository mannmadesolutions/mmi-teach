<?php
namespace MMI_Teach;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Front-end request handling around the Bricks pages: sample personas, the "jobs I can apply to
 * now" switch, caching headers and old-URL redirects.
 */
final class Front {

	/** Bricks element IDs of job loops the "apply now" switch narrows (set by Build\Site). */
	public const FIT_LOOPS = array( 'mtjobs' );

	public const COOKIE_FIT = 'mt_fit';

	/** Meta key the jobs page's pay sort orders by (daily equivalent, set only when a job posts pay). */
	public const PAY_KEY = '_mt_pay_day';

	/** Once the Bricks site is live, these old URLs redirect (Build\Site sets the switch). */
	public const OPTION_LIVE = 'mmi_teach_bricks_live';
	private const REDIRECTS  = array(
		'vacancies'  => 'jobs',
		'substitute' => 'guides/substitute-teaching',
	);

	public static function register(): void {
		add_action( 'template_redirect', array( self::class, 'early' ), 1 );
		add_filter( 'bricks/posts/query_vars', array( self::class, 'fit_query' ), 10, 3 );
		add_filter( 'bricks/posts/query_vars', array( self::class, 'jobs_order' ), 1000, 3 );
		add_filter( 'posts_clauses', array( self::class, 'sort_clauses' ), 10, 2 );
		add_filter( 'bricks/filter_element/populated_options', array( self::class, 'employer_options' ), 10, 2 );
		add_filter( 'bricks/element/render_attributes', array( self::class, 'employer_search' ), 10, 3 );
	}

	public static function early(): void {
		if ( is_admin() || wp_doing_ajax() ) {
			return;
		}
		// Every page can be personalised (fit badges, checklist, account), and Cloudflare caches
		// HTML on this zone, so nothing HTML is cached anywhere.
		nocache_headers();
		header( 'Cache-Control: no-store, private, max-age=0' );

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- view switches only; nothing is saved but a display preference.
		if ( isset( $_GET['mt_persona'] ) ) {
			$key = sanitize_key( wp_unslash( $_GET['mt_persona'] ) );
			Profile::set_persona( 'off' === $key ? '' : $key );
			self::reload( array( 'mt_persona' ) );
		}
		if ( isset( $_GET['fit'] ) ) {
			$on = 'now' === sanitize_key( wp_unslash( $_GET['fit'] ) );
			self::set_fit( $on );
		}
		// phpcs:enable

		self::dpi_links();

		if ( get_option( self::OPTION_LIVE ) && is_404() ) {
			$path = trim( (string) wp_parse_url( (string) ( $_SERVER['REQUEST_URI'] ?? '' ), PHP_URL_PATH ), '/' ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			if ( isset( self::REDIRECTS[ $path ] ) ) {
				wp_safe_redirect( home_url( '/' . self::REDIRECTS[ $path ] . '/' ), 301 );
				exit;
			}
		}
	}

	/** Is the "only jobs I can apply to now" switch on (and does it apply: answers given)? */
	public static function fit_on(): bool {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( isset( $_GET['fit'] ) ) {
			return 'now' === sanitize_key( wp_unslash( $_GET['fit'] ) ) && null !== Profile::current();
		}
		return ! empty( $_COOKIE[ self::COOKIE_FIT ] ) && null !== Profile::current();
	}

	private static function set_fit( bool $on ): void {
		if ( ! headers_sent() ) {
			setcookie( self::COOKIE_FIT, $on ? '1' : '', array( 'expires' => $on ? time() + DAY_IN_SECONDS : time() - YEAR_IN_SECONDS, 'path' => COOKIEPATH ?: '/', 'secure' => is_ssl(), 'httponly' => true, 'samesite' => 'Lax' ) );
		}
		if ( $on ) {
			$_COOKIE[ self::COOKIE_FIT ] = '1';
		} else {
			unset( $_COOKIE[ self::COOKIE_FIT ] );
		}
	}

	/**
	 * Narrows the jobs loop to jobs the visitor can apply to now. Runs for the page render and
	 * for Bricks' filter requests (which carry the cookie, not the URL parameter).
	 */
	public static function fit_query( $vars, $settings, $element_id ) {
		if ( ! in_array( (string) $element_id, self::FIT_LOOPS, true ) || ! self::fit_on() ) {
			return $vars;
		}
		$ids              = self::applicable_ids();
		$vars['post__in'] = $ids ?: array( 0 );
		return $vars;
	}

	/**
	 * The jobs page's order. Pay sort: Bricks sorts by meta_key, which drops every job without
	 * posted pay; this swaps it for a flag that sort_clauses() turns into a LEFT JOIN, so those jobs
	 * stay in the list, after the paid ones. Every order ends newest first, then by ID: postings
	 * from the same day share one post_date, and without a tiebreak pages repeat or skip jobs.
	 * Runs after Bricks merges its filter vars (priority 999).
	 */
	public static function jobs_order( $vars, $settings, $element_id ) {
		if ( ! in_array( (string) $element_id, self::FIT_LOOPS, true ) ) {
			return $vars;
		}
		if ( self::PAY_KEY === ( $vars['meta_key'] ?? '' ) ) {
			$vars['mt_pay_sort'] = 'ASC' === strtoupper( (string) ( $vars['orderby']['meta_value_num'] ?? '' ) ) ? 'ASC' : 'DESC';
			unset( $vars['meta_key'], $vars['meta_type'] );
			$vars['orderby'] = 'date';
		}
		if ( 'date' === ( $vars['orderby'] ?? '' ) ) {
			$vars['order']         = 'DESC';
			$vars['mt_jobs_order'] = true;
		}
		return $vars;
	}

	public static function sort_clauses( array $clauses, \WP_Query $q ): array {
		if ( ! $q->get( 'mt_jobs_order' ) ) {
			return $clauses;
		}
		global $wpdb;
		$order = $q->get( 'mt_pay_sort' );
		$by    = "{$wpdb->posts}.post_date DESC, {$wpdb->posts}.ID DESC";
		if ( 'ASC' === $order || 'DESC' === $order ) {
			$clauses['join'] .= $wpdb->prepare( " LEFT JOIN {$wpdb->postmeta} mt_pay ON ( mt_pay.post_id = {$wpdb->posts}.ID AND mt_pay.meta_key = %s )", self::PAY_KEY );
			$by               = "mt_pay.meta_value IS NULL, CAST(mt_pay.meta_value AS DECIMAL(10,2)) $order, $by";
		}
		$clauses['orderby'] = $by;
		return $clauses;
	}

	/**
	 * Employer filter: "School District of New Berlin" is listed as "New Berlin, School District
	 * of" (and "The Prairie School" as "Prairie School, The"), sorted by that, so every employer
	 * sits under its place name. Only the label changes; the value is still the term slug.
	 */
	public static function employer_options( $options, $element ) {
		if ( ! is_array( $options ) || 'mt_employer' !== ( $element->settings['filterTaxonomy'] ?? '' ) ) {
			return $options;
		}
		$top  = array();
		$rest = array();
		foreach ( $options as $o ) {
			if ( ! empty( $o['is_placeholder'] ) || ! empty( $o['is_all'] ) || '' === (string) ( $o['value'] ?? '' ) ) {
				$top[] = $o;
				continue;
			}
			$o['text'] = self::employer_label( (string) ( $o['text'] ?? '' ) );
			$rest[]    = $o;
		}
		usort( $rest, static fn( $a, $b ) => strnatcasecmp( $a['text'], $b['text'] ) );
		return array_merge( $top, $rest );
	}

	/**
	 * Employer search box: Choices.js matches fuzzily by default ("Berlin" also listed Burlington
	 * and Destiny). Bricks passes data-brx-choices-options straight to Choices.js, so a strict Fuse
	 * threshold, matched anywhere in the name, is added here.
	 */
	public static function employer_search( $attributes, $key, $element ) {
		if ( ! is_array( $attributes ) || 'filter-select' !== ( $element->name ?? '' ) || 'mt_employer' !== ( $element->settings['filterTaxonomy'] ?? '' ) ) {
			return $attributes;
		}
		$raw = $attributes[ $key ]['data-brx-choices-options'] ?? null;
		if ( null === $raw ) {
			return $attributes;
		}
		$opts = json_decode( is_array( $raw ) ? (string) end( $raw ) : (string) $raw, true );
		if ( is_array( $opts ) ) {
			$opts['fuseOptions'] = array(
				'threshold'      => 0.1,
				'ignoreLocation' => true,
			);
			$attributes[ $key ]['data-brx-choices-options'] = array( wp_json_encode( $opts ) );
		}
		return $attributes;
	}

	/** "School District of X" → "X, School District of"; "The X" → "X, The". */
	public static function employer_label( string $name ): string {
		return (string) preg_replace( '/^(School District of|The)\s+(.+)$/i', '$2, $1', trim( $name ) );
	}

	/** Open jobs the current profile can apply to now; cached per profile and sync. */
	public static function applicable_ids(): array {
		$p = Profile::current();
		if ( null === $p ) {
			return array();
		}
		$key = 'mt_fit_' . md5( Profile::hash() . '|' . Jobs::synced_at() );
		$ids = get_transient( $key );
		if ( is_array( $ids ) ) {
			return $ids;
		}
		$tasks = Profile::tasks();
		$ids   = array();
		foreach ( Jobs::open_ids() as $id ) {
			$job = Jobs::job( $id );
			if ( $job && Eligibility::for_job( $job, $p, $tasks )['can_apply'] ) {
				$ids[] = $id;
			}
		}
		set_transient( $key, $ids, HOUR_IN_SECONDS );
		return $ids;
	}

	/**
	 * "This is me" and "Search again" links from the DPI lookup. A picked file number must be
	 * one of the visitor's own matches; anything else is ignored.
	 */
	private static function dpi_links(): void {
		if ( ! isset( $_GET['mt_dpi_pick'] ) && ! isset( $_GET['mt_dpi_forget'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- checked below.
			return;
		}
		$back = Pages::url( 'check' );
		if ( ! wp_verify_nonce( sanitize_key( wp_unslash( $_GET['_mtn'] ?? '' ) ), 'mt_dpi' ) ) {
			wp_safe_redirect( $back . '#find-license', 303 );
			exit;
		}
		if ( isset( $_GET['mt_dpi_forget'] ) ) {
			Dpi_Lookup::forget();
			wp_safe_redirect( $back . '#find-license', 303 );
			exit;
		}
		$id      = absint( $_GET['mt_dpi_pick'] );
		$matches = Dpi_Lookup::matches();
		$error   = '';
		if ( $id && $matches && in_array( $id, array_column( $matches['m'], 0 ), true ) ) {
			try {
				Dpi_Lookup::save_record( $id, Dpi_Lookup::interpret( Dpi_Lookup::details( $id ) ) );
			} catch ( Dpi_Limit $e ) {
				$error = 'limit';
			} catch ( \RuntimeException $e ) {
				error_log( 'mmi-teach DPI lookup: ' . $e->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				$error = 'down';
			}
		}
		wp_safe_redirect( ( $error ? add_query_arg( 'dpi_error', $error, $back ) : $back ) . '#find-license', 303 );
		exit;
	}

	/** Redirects to the same URL without the given query keys (after a view switch). */
	private static function reload( array $drop ): void {
		$uri = remove_query_arg( $drop, wp_unslash( (string) ( $_SERVER['REQUEST_URI'] ?? '/' ) ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		wp_safe_redirect( home_url( $uri ), 303 );
		exit;
	}
}
