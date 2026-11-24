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

	/** Once the Bricks site is live, these old URLs redirect (Build\Site sets the switch). */
	public const OPTION_LIVE = 'mmi_teach_bricks_live';
	private const REDIRECTS  = array(
		'vacancies'  => 'jobs',
		'substitute' => 'guides/substitute-teaching',
	);

	public static function register(): void {
		add_action( 'template_redirect', array( self::class, 'early' ), 1 );
		add_filter( 'bricks/posts/query_vars', array( self::class, 'fit_query' ), 10, 3 );
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

	/** Redirects to the same URL without the given query keys (after a view switch). */
	private static function reload( array $drop ): void {
		$uri = remove_query_arg( $drop, wp_unslash( (string) ( $_SERVER['REQUEST_URI'] ?? '/' ) ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		wp_safe_redirect( home_url( $uri ), 303 );
		exit;
	}
}
