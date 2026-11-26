<?php
namespace MMI_Teach;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * "Find my license": reads DPI's public educator license lookup (ELO datamart) for a visitor
 * and turns their record into plain English and answers for the check.
 *
 * - Public pages only: the name search form and the license details page anyone can open.
 * - Nothing about the search is kept on the server: names aren't stored or logged. The match
 *   list (names DPI returned) and the chosen record (no name) live in the visitor's own
 *   cookies, briefly. The rate limiter keys on a salted hash of the IP, never the name.
 * - DPI is asked at most once per step, with a per-visitor and a site-wide limit, so this
 *   site can't be used to hammer it.
 */
final class Dpi_Lookup {

	public const BASE           = 'https://elo.wieducatorlicensing.org/datamart/';
	public const LOOKUP_URL     = 'https://dpi.wi.gov/licensing/license-lookup';
	public const COOKIE_MATCHES = 'mt_dpi_m';
	public const COOKIE_RECORD  = 'mt_dpi';
	public const MAX_MATCHES    = 30;

	private const TTL_MATCHES   = 30 * MINUTE_IN_SECONDS;
	private const TTL_RECORD    = DAY_IN_SECONDS;
	private const LIMIT_VISITOR = 20; // DPI requests per visitor per 15 minutes (a search and a pick is 4).
	private const LIMIT_SITE    = 300; // DPI requests per hour for the whole site.

	/* ---------- DPI requests ---------- */

	/**
	 * Name search. DPI keeps the session in the form's action URL (;jsessionid=…), then
	 * redirects the POST to the results page.
	 *
	 * @return array<int, array{0: int, 1: string}> [file number, name as DPI lists it]
	 * @throws \RuntimeException When DPI can't be reached or answers unexpectedly.
	 */
	public static function search( string $last, string $first ): array {
		self::spend( 3 );
		$form   = self::request( 'GET', self::BASE . 'searchByNameWIDPI.do' );
		$action = preg_match( '#action="(/datamart/searchByNameWIDPI\.do[^"]*)"#', $form, $m ) ? html_entity_decode( $m[1] ) : '';
		if ( '' === $action ) {
			throw new \RuntimeException( 'DPI search form changed' );
		}
		$jsession = preg_match( '/;jsessionid=([^?"#]+)/', $action, $j ) ? $j[1] : '';
		self::request(
			'POST',
			'https://elo.wieducatorlicensing.org' . $action,
			array(
				'lastName'  => $last,
				'firstName' => $first,
				'pageSize'  => '30',
				'submit'    => 'Submit',
			),
			$jsession
		);
		$html = self::request( 'GET', self::BASE . 'searchResults.do' . ( $jsession ? ';jsessionid=' . $jsession : '' ), null, $jsession );
		if ( ! str_contains( $html, 'Search by Name Results' ) ) {
			throw new \RuntimeException( 'DPI results page changed' );
		}
		return self::parse_matches( $html );
	}

	/** @throws \RuntimeException */
	public static function details( int $id ): array {
		self::spend( 1 );
		$html = self::request( 'GET', self::BASE . 'licenseDetails.do?xentId=' . $id );
		$rec  = self::parse_details( $html );
		if ( ! $rec['licenses'] && ! str_contains( $html, 'License Details' ) ) {
			throw new \RuntimeException( 'DPI details page changed' );
		}
		return $rec;
	}

	/** Public link to a record on DPI's own site. */
	public static function dpi_url( int $id ): string {
		return self::BASE . 'licenseDetails.do?xentId=' . $id;
	}

	/* ---------- parsing (pure; tested on saved pages) ---------- */

	/** @return array<int, array{0: int, 1: string}> */
	public static function parse_matches( string $html ): array {
		$out = array();
		if ( preg_match_all( '#runScriptClick\((\d+)\);?"[^>]*>\s*([^<]+?)\s*</a>#', $html, $m, PREG_SET_ORDER ) ) {
			foreach ( $m as $row ) {
				$out[ (int) $row[1] ] = array( (int) $row[1], trim( html_entity_decode( $row[2], ENT_QUOTES ) ) );
			}
		}
		return array_values( $out );
	}

	/**
	 * The details page as data.
	 *
	 * @return array{licenses: array[]} each license: code, type, stage, number, from, expires
	 *         (Y-m-d or ''), status, renewal, pending (bool), subjects [code, name, low, high]
	 */
	public static function parse_details( string $html ): array {
		$html   = preg_replace( '#<script.*?</script>|<style.*?</style>|<!--.*?-->#s', ' ', $html );
		$text   = preg_replace( '/\s+/', ' ', html_entity_decode( wp_strip_all_tags( str_replace( '<', ' <', (string) $html ) ), ENT_QUOTES ) );
		$labels = array( 'Entity Number', 'Last Background Check Submitted', 'License Type', 'Stage', 'License Number', 'Original License Date', 'Most Recent Application/Payment Received', 'Valid From', 'Expires On', 'License Status', 'Renewal Guidelines', 'Pending Renewal/Extension', 'Position/Subject', 'Developmental Level', 'Low Grade', 'High Grade' );
		$parts  = preg_split( '#(' . implode( '|', array_map( static fn( $l ) => preg_quote( $l, '#' ), $labels ) ) . '):#', (string) $text, -1, PREG_SPLIT_DELIM_CAPTURE );
		$lic    = array();
		$cur    = null;
		$sub    = null;
		for ( $i = 1; $i < count( $parts ); $i += 2 ) {
			$label = $parts[ $i ];
			$value = trim( (string) ( $parts[ $i + 1 ] ?? '' ) );
			if ( 'License Type' === $label ) {
				if ( $cur ) {
					$lic[] = $cur;
				}
				$code = preg_match( '/^([A-Z]\d{3})\s*-\s*(.*)$/', $value, $m ) ? $m : array( '', '', $value );
				$cur  = array( 'code' => $code[1], 'type' => trim( $code[2] ), 'stage' => '', 'number' => '', 'from' => '', 'expires' => '', 'status' => '', 'renewal' => '', 'pending' => false, 'subjects' => array() );
				$sub  = null;
				continue;
			}
			if ( ! $cur ) {
				continue;
			}
			switch ( $label ) {
				case 'Stage':
					$cur['stage'] = $value;
					break;
				case 'License Number':
					$cur['number'] = $value;
					break;
				case 'Valid From':
					$cur['from'] = self::ymd( $value );
					break;
				case 'Expires On':
					$cur['expires'] = self::ymd( $value );
					break;
				case 'License Status':
					$cur['status'] = $value;
					break;
				case 'Renewal Guidelines':
					$cur['renewal'] = $value;
					break;
				case 'Pending Renewal/Extension':
					$cur['pending'] = 0 === stripos( $value, 'yes' );
					break;
				case 'Position/Subject':
					$code              = preg_match( '/^(\d{3,4})\s*-\s*(.*)$/', $value, $m ) ? $m : array( '', '', $value );
					$cur['subjects'][] = array( 'code' => $code[1], 'name' => trim( $code[2] ), 'low' => '', 'high' => '' );
					$sub               = count( $cur['subjects'] ) - 1;
					break;
				case 'Low Grade':
				case 'High Grade':
					if ( null !== $sub ) {
						$cur['subjects'][ $sub ][ 'Low Grade' === $label ? 'low' : 'high' ] = preg_replace( '/^Grade\s+/i', '', $value );
					}
					break;
			}
		}
		if ( $cur ) {
			$lic[] = $cur;
		}
		return array( 'licenses' => $lic );
	}

	/**
	 * What a record means for the check: the answer to "which license do you hold", the
	 * subjects, the least education it implies, display rows and notes.
	 *
	 * @return array{cred: string, areas: string[], edu_min: string, rows: array[], notes: string[]}
	 */
	public static function interpret( array $record, ?string $today = null ): array {
		$today = $today ?? wp_date( 'Y-m-d' );
		$lic   = array();
		foreach ( $record['licenses'] ?? array() as $l ) {
			$l['kind']  = self::kind( $l );
			$l['state'] = self::state( $l, $today );
			$lic[]      = $l;
		}
		$valid   = array_filter( $lic, static fn( $l ) => 'valid' === $l['state'] );
		$expired = array_filter( $lic, static fn( $l ) => 'expired' === $l['state'] );
		$kinds   = static fn( array $set ) => array_unique( array_column( $set, 'kind' ) );

		$cred = 'none';
		foreach ( array( 'teach' => 'wi_license', 'sub5' => 'long_term', 'permit' => 'permit', 'aide' => 'sepa' ) as $kind => $answer ) {
			if ( in_array( $kind, $kinds( $valid ), true ) ) {
				$cred = $answer;
				break;
			}
		}
		if ( 'none' === $cred ) {
			$ex = $kinds( $expired );
			if ( array_intersect( array( 'teach', 'sub5' ), $ex ) ) {
				$cred = 'expired';
			} elseif ( in_array( 'permit', $ex, true ) ) {
				$cred = 'permit_expired';
			}
		}

		// Subjects come from the teaching licenses that decided the answer.
		$source = in_array( $cred, array( 'wi_license', 'long_term' ), true ) ? $valid : $expired;
		$codes  = array();
		foreach ( $source as $l ) {
			if ( in_array( $l['kind'], array( 'teach', 'sub5' ), true ) ) {
				$codes = array_merge( $codes, array_column( $l['subjects'], 'code' ) );
			}
		}
		$areas = array();
		$tax   = Requirements::taxonomy()['licenses'] ?? array();
		foreach ( array_keys( Profile::areas() ) as $key ) {
			if ( array_intersect( (array) ( $tax[ $key ]['codes'] ?? array() ), $codes ) ) {
				$areas[] = $key;
			}
		}

		$notes = array();
		if ( array_filter( $lic, static fn( $l ) => $l['pending'] ) ) {
			$notes[] = __( 'DPI shows a renewal or extension in progress.', 'mmi-teach' );
		}
		foreach ( $lic as $l ) {
			if ( 'other' === $l['state'] ) {
				/* translators: %s: status as DPI shows it */
				$notes[] = sprintf( __( 'One license shows “%s”. Ask DPI about it before you apply.', 'mmi-teach' ), $l['status'] );
			}
		}
		if ( 'expired' === $cred && ! in_array( 'teach', $kinds( $expired ), true ) ) {
			$notes[] = __( 'Your five-year substitute license expired: renew it with the same application, no coursework.', 'mmi-teach' );
		}
		if ( 'none' === $cred && $kinds( $lic ) && ! array_diff( $kinds( $lic ), array( 'other', 'aide' ) ) && in_array( 'other', $kinds( $lic ), true ) ) {
			$notes[] = __( 'Pupil-services and administrator licenses aren’t teaching licenses; this check covers teacher, substitute and aide jobs.', 'mmi-teach' );
		}
		if ( array_filter( $lic, static fn( $l ) => '' !== $l['expires'] && substr( $l['expires'], 0, 4 ) === substr( $today, 0, 4 ) ) ) {
			$notes[] = __( 'DPI notes that the stage shown on a license expiring this year may not be up to date.', 'mmi-teach' );
		}

		if ( ! $lic ) {
			$notes[] = __( 'DPI has no licenses under this file number.', 'mmi-teach' );
		}
		// Current licenses first, teaching-related before others, then the most recent; six at most.
		usort( $lic, static fn( $a, $b ) => ( ( 'valid' === $b['state'] ) <=> ( 'valid' === $a['state'] ) ) ?: ( ( 'other' !== $a['kind'] ) <=> ( 'other' !== $b['kind'] ) ) * -1 ?: strcmp( $b['expires'], $a['expires'] ) );
		$rows = array();
		foreach ( array_slice( $lic, 0, 6 ) as $l ) {
			$rows[] = self::row( $l );
		}

		return array(
			'cred'    => $cred,
			'areas'   => $areas,
			'edu_min' => in_array( $cred, array( 'wi_license', 'expired', 'long_term' ), true ) ? 'bachelor' : '',
			'rows'    => $rows,
			'notes'   => array_values( array_unique( $notes ) ),
			'more'    => max( 0, count( $lic ) - 6 ),
		);
	}

	/** teach, sub5, permit, aide or other (pupil services, administrator, unknown). */
	private static function kind( array $l ): string {
		if ( 'T910' === $l['code'] || false !== stripos( $l['type'], 'Short Term Substitute' ) ) {
			return 'permit';
		}
		if ( false !== stripos( $l['type'] . ' ' . $l['stage'], 'Aide' ) ) {
			return 'aide';
		}
		if ( false !== stripos( $l['stage'], 'Substitute' ) ) {
			return 'sub5';
		}
		return str_starts_with( $l['code'], 'T' ) ? 'teach' : 'other';
	}

	/**
	 * valid, expired (also DPI's "License Invalid", common on old superseded licenses) or other:
	 * revoked, suspended, denied or surrendered, which the visitor should take up with DPI.
	 */
	private static function state( array $l, string $today ): string {
		if ( 0 === stripos( $l['status'], 'Valid' ) ) {
			return ( '' === $l['expires'] || $l['expires'] >= $today ) ? 'valid' : 'expired';
		}
		return preg_match( '/revok|suspend|denied|surrender/i', $l['status'] ) ? 'other' : 'expired';
	}

	/** One license as short display strings (no name, no license number). */
	private static function row( array $l ): array {
		$titles = array(
			'teach'  => __( 'Teaching license', 'mmi-teach' ),
			'sub5'   => __( 'Five-year substitute license', 'mmi-teach' ),
			'permit' => __( 'Substitute permit (3-year)', 'mmi-teach' ),
			'aide'   => __( 'Special Education Program Aide license', 'mmi-teach' ),
		);
		$title    = $titles[ $l['kind'] ] ?? $l['type'];
		$stage    = 'permit' === $l['kind'] || 'sub5' === $l['kind'] ? '' : $l['stage'];
		$subjects = array_values( array_unique( array_filter( array_column( $l['subjects'], 'name' ) ) ) );
		$low      = $l['subjects'][0]['low'] ?? '';
		$high     = $l['subjects'][0]['high'] ?? '';
		$date     = '' !== $l['expires'] ? wp_date( 'F j, Y', (int) strtotime( $l['expires'] . ' 12:00:00' ) ) : '';
		switch ( $l['state'] ) {
			case 'valid':
				/* translators: %s: date */
				$status = $date ? sprintf( __( 'Valid until %s', 'mmi-teach' ), $date ) : __( 'Valid', 'mmi-teach' );
				break;
			case 'expired':
				/* translators: %s: date */
				$status = $date ? sprintf( __( 'Expired %s', 'mmi-teach' ), $date ) : __( 'Expired', 'mmi-teach' );
				if ( 0 !== stripos( $l['status'], 'Expired' ) && '' !== $l['status'] ) {
					$status .= ' (' . $l['status'] . ')';
				}
				break;
			default:
				$status = $l['status'];
		}
		return array(
			'title'    => $title . ( '' !== $stage ? ' · ' . $stage : '' ),
			'subjects' => implode( ', ', array_slice( $subjects, 0, 6 ) ) . ( count( $subjects ) > 6 ? ' …' : '' ),
			'grades'   => '' !== $low && '' !== $high && 'N/A' !== $low ? ( $low === $high ? $low : $low . '–' . $high ) : '',
			'state'    => $l['state'],
			'status'   => $status,
		);
	}

	private static function ymd( string $v ): string {
		return preg_match( '#^(\d{2})/(\d{2})/(\d{4})#', $v, $m ) ? "$m[3]-$m[1]-$m[2]" : '';
	}

	/* ---------- the visitor's copy (cookies) ---------- */

	/** @return array{q: string, m: array}|null */
	public static function matches(): ?array {
		$raw = Profile::cookie( self::COOKIE_MATCHES );
		if ( ! $raw || ! is_array( $raw['m'] ?? null ) ) {
			return null;
		}
		$m = array();
		foreach ( array_slice( $raw['m'], 0, self::MAX_MATCHES ) as $row ) {
			if ( is_array( $row ) && absint( $row[0] ?? 0 ) && is_string( $row[1] ?? null ) ) {
				$m[] = array( absint( $row[0] ), sanitize_text_field( $row[1] ) );
			}
		}
		return array( 'q' => sanitize_text_field( (string) ( $raw['q'] ?? '' ) ), 'm' => $m );
	}

	public static function save_matches( string $query, array $matches ): void {
		Profile::store_cookie( self::COOKIE_MATCHES, array( 'q' => $query, 'm' => array_slice( $matches, 0, self::MAX_MATCHES ) ), self::TTL_MATCHES );
	}

	/** The chosen record as interpret() output plus its file number, or null. */
	public static function record(): ?array {
		$raw = Profile::cookie( self::COOKIE_RECORD );
		if ( ! $raw || ! absint( $raw['id'] ?? 0 ) ) {
			return null;
		}
		$rows = array();
		foreach ( array_slice( (array) ( $raw['rows'] ?? array() ), 0, 6 ) as $r ) {
			$r      = (array) $r;
			$rows[] = array(
				'title'    => sanitize_text_field( (string) ( $r['title'] ?? '' ) ),
				'subjects' => sanitize_text_field( (string) ( $r['subjects'] ?? '' ) ),
				'grades'   => sanitize_text_field( (string) ( $r['grades'] ?? '' ) ),
				'state'    => in_array( $r['state'] ?? '', array( 'valid', 'expired', 'other' ), true ) ? $r['state'] : 'other',
				'status'   => sanitize_text_field( (string) ( $r['status'] ?? '' ) ),
			);
		}
		return array(
			'id'      => absint( $raw['id'] ),
			'cred'    => isset( Profile::CRED[ $raw['cred'] ?? '' ] ) ? $raw['cred'] : 'none',
			'areas'   => array_values( array_intersect( (array) ( $raw['areas'] ?? array() ), array_keys( Profile::areas() ) ) ),
			'edu_min' => 'bachelor' === ( $raw['edu_min'] ?? '' ) ? 'bachelor' : '',
			'rows'    => $rows,
			'notes'   => array_map( 'sanitize_text_field', array_slice( (array) ( $raw['notes'] ?? array() ), 0, 5 ) ),
			'more'    => absint( $raw['more'] ?? 0 ),
			'used'    => ! empty( $raw['used'] ),
		);
	}

	public static function save_record( int $id, array $meaning ): void {
		Profile::store_cookie( self::COOKIE_RECORD, array( 'id' => $id ) + $meaning + array( 'used' => 0 ), self::TTL_RECORD );
		Profile::store_cookie( self::COOKIE_MATCHES, null, 0 );
	}

	/** After the visitor saves their answers, the record no longer pre-fills the form. */
	public static function mark_used(): void {
		$rec = Profile::cookie( self::COOKIE_RECORD );
		if ( $rec && empty( $rec['used'] ) ) {
			$rec['used'] = 1;
			Profile::store_cookie( self::COOKIE_RECORD, $rec, self::TTL_RECORD );
		}
	}

	public static function forget(): void {
		Profile::store_cookie( self::COOKIE_MATCHES, null, 0 );
		Profile::store_cookie( self::COOKIE_RECORD, null, 0 );
	}

	/** Pre-fill for the check form while a fresh record hasn't been saved as answers yet. */
	public static function prefill(): ?array {
		if ( Profile::persona() ) {
			return null;
		}
		$rec = self::record();
		return $rec && ! $rec['used'] ? $rec : null;
	}

	/* ---------- limits and transport ---------- */

	/** Counts DPI requests; throws when this visitor or the site is over its limit. */
	private static function spend( int $requests ): void {
		$ip      = (string) ( $_SERVER['HTTP_CF_CONNECTING_IP'] ?? $_SERVER['REMOTE_ADDR'] ?? '' ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- hashed, never stored raw.
		$visitor = 'mt_dpi_v_' . substr( hash_hmac( 'sha256', $ip, wp_salt( 'nonce' ) ), 0, 20 );
		$site    = 'mt_dpi_site_' . gmdate( 'YmdH' );
		$v       = (int) get_transient( $visitor );
		$s       = (int) get_transient( $site );
		if ( $v + $requests > self::LIMIT_VISITOR || $s + $requests > self::LIMIT_SITE ) {
			throw new Dpi_Limit( 'limit' );
		}
		set_transient( $visitor, $v + $requests, 15 * MINUTE_IN_SECONDS );
		set_transient( $site, $s + $requests, HOUR_IN_SECONDS );
	}

	/**
	 * One request to ELO. Not through Http: that class spaces requests 2 s apart for crawls,
	 * and this is one person's three-step search, like their own browser would make.
	 */
	private static function request( string $method, string $url, ?array $body = null, string $jsession = '' ): string {
		$args = array(
			'method'      => $method,
			'timeout'     => 20,
			'redirection' => 0,
			'user-agent'  => 'MMI-Teach/' . MMI_TEACH_VERSION . ' (license lookup for a visitor; +' . home_url( '/' ) . ')',
			'headers'     => array( 'Accept' => 'text/html' ),
		);
		if ( '' !== $jsession ) {
			$args['cookies'] = array( new \WP_Http_Cookie( array( 'name' => 'JSESSIONID', 'value' => $jsession ) ) );
		}
		if ( null !== $body ) {
			$args['body'] = $body;
		}
		$response = wp_remote_request( $url, $args );
		if ( is_wp_error( $response ) ) {
			throw new \RuntimeException( 'DPI lookup: ' . $response->get_error_message() );
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( 'POST' === $method && in_array( $code, array( 301, 302, 303 ), true ) ) {
			return '';
		}
		if ( $code < 200 || $code >= 300 ) {
			throw new \RuntimeException( 'DPI lookup: HTTP ' . $code );
		}
		return (string) wp_remote_retrieve_body( $response );
	}
}

/** Thrown when the lookup limit is reached (shown to the visitor as "try again later"). */
final class Dpi_Limit extends \RuntimeException {}
