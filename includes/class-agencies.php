<?php
namespace MMI_Teach;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Staffing agencies (EDUStaff, Kelly Education, …) and which one, if any, fills each posting.
 *
 * A posting's agency is decided by, in order:
 *  1. posting  – the posting itself names the agency ("Employer: Edustaff", "employed through
 *                Kelly Education"), in its title, text or links;
 *  2. employer – it's a substitute job and data/agencies.json records how that employer fills
 *                that kind of sub job (an agency, or 'direct'); 'likely' when the record rests on
 *                an agency ad or board discussion rather than the employer's own words. A route
 *                is for daily subs unless by_kind says more: agency districts often hire building
 *                and long-term subs themselves, so those stay unknown;
 *  3. direct   – a regular teaching job, which the employer hires itself;
 *  4. unknown  – a substitute job at an employer whose route isn't confirmed yet.
 */
final class Agencies {

	public const DIRECT  = 'direct';
	public const UNKNOWN = 'unknown';

	private static ?array $data = null;

	/** @return array<string, array> agency key => agency facts */
	public static function all(): array {
		return self::data()['agencies'] ?? array();
	}

	public static function name( string $key ): string {
		return self::all()[ $key ]['name'] ?? '';
	}

	/** Employer notes keyed by normalized employer name. */
	public static function employer_notes(): array {
		static $notes = null;
		if ( null === $notes ) {
			$notes = array();
			foreach ( self::data()['employers'] ?? array() as $name => $note ) {
				$notes[ Places::normalize( $name ) ] = $note + array( 'name' => $name );
			}
		}
		return $notes;
	}

	/** Every agency key named in the text (an agency's aliases are case-insensitive regexes). */
	public static function mentioned( string $text ): array {
		$found = array();
		foreach ( self::all() as $key => $agency ) {
			foreach ( $agency['aliases'] ?? array() as $alias ) {
				if ( preg_match( '/\b(?:' . $alias . ')\b/i', $text ) ) {
					$found[] = $key;
					break;
				}
			}
		}
		return $found;
	}

	/**
	 * Short quotes around each place the text names an agency, for showing why a posting got
	 * its agency: about 90 characters either side, cut at word boundaries, at most three.
	 */
	public static function quotes( string $text ): array {
		$quotes = array();
		$last   = -1;
		foreach ( self::all() as $agency ) {
			foreach ( $agency['aliases'] ?? array() as $alias ) {
				if ( ! preg_match_all( '/\b(?:' . $alias . ')\b/i', $text, $m, PREG_OFFSET_CAPTURE ) ) {
					continue;
				}
				foreach ( $m[0] as [ $hit, $at ] ) {
					if ( $at < $last ) {
						continue; // Already inside an earlier quote.
					}
					$start    = max( 0, $at - 90 );
					$end      = min( strlen( $text ), $at + strlen( $hit ) + 90 );
					$snip     = substr( $text, $start, $end - $start );
					$snip     = preg_replace( array( '/^\S*\s/', '/\s\S*$/' ), '', $snip );
					$quotes[] = ( $start > 0 ? '…' : '' ) . trim( $snip ) . ( $end < strlen( $text ) ? '…' : '' );
					$last     = $end;
				}
			}
		}
		return array_slice( array_unique( $quotes ), 0, 3 );
	}

	/**
	 * @param string $text Everything the posting says: title, links, detail text and the agency
	 *                     mentions the detail pass recorded from the full description.
	 * @return array{agency: string, basis: string} agency is an agency key, 'direct' or 'unknown'.
	 */
	public static function for_posting( string $employer, string $sub_kind, string $text ): array {
		$named = self::mentioned( $text );
		if ( count( $named ) === 1 ) {
			return array(
				'agency' => $named[0],
				'basis'  => 'posting',
			);
		}
		if ( '' === $sub_kind ) {
			return array(
				'agency' => self::DIRECT,
				'basis'  => 'direct',
			);
		}
		$route = self::route( $employer, $sub_kind );
		if ( null !== $route ) {
			return array(
				'agency' => $route['agency'],
				'basis'  => 'likely' === $route['confidence'] ? 'likely' : 'employer',
			);
		}
		return array(
			'agency' => self::UNKNOWN,
			'basis'  => 'unknown',
		);
	}

	/**
	 * How an employer fills one kind of sub job, if recorded.
	 *
	 * @return array{agency: string, confidence: string}|null
	 */
	public static function route( string $employer, string $sub_kind ): ?array {
		$note = self::employer_notes()[ Places::normalize( $employer ) ] ?? null;
		if ( ! $note ) {
			return null;
		}
		$agency = $note['by_kind'][ $sub_kind ] ?? ( self::DIRECT === $note['agency'] || 'daily' === $sub_kind ? $note['agency'] : '' );
		return '' === $agency ? null : array(
			'agency'     => $agency,
			'confidence' => $note['confidence'] ?? 'confirmed',
		);
	}

	/** Labels for every value a posting's agency can take, for filters and tables. */
	public static function labels(): array {
		$labels = array();
		foreach ( self::all() as $key => $agency ) {
			$labels[ $key ] = $agency['name'];
		}
		$labels[ self::DIRECT ]  = __( 'None — employer hires directly', 'mmi-teach' );
		$labels[ self::UNKNOWN ] = __( 'Not confirmed', 'mmi-teach' );
		return $labels;
	}

	/** Entries whose verified_on is older than the cutoff (Y-m-d), for the settings screen. */
	public static function stale( string $cutoff ): array {
		$items = array();
		foreach ( self::all() as $agency ) {
			$items[ 'Agency: ' . $agency['name'] ] = $agency['verified_on'] ?? '';
		}
		foreach ( self::data()['employers'] ?? array() as $name => $note ) {
			$items[ 'Employer: ' . $name ] = $note['verified_on'] ?? '';
		}
		return array_filter( $items, static fn( $date ) => '' === $date || $date < $cutoff );
	}

	private static function data(): array {
		if ( null === self::$data ) {
			$raw        = json_decode( (string) file_get_contents( MMI_TEACH_DIR . 'data/agencies.json' ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local plugin file.
			self::$data = is_array( $raw ) ? $raw : array();
		}
		return self::$data;
	}
}
