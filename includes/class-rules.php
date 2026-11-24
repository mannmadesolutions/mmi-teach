<?php
namespace MMI_Teach;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * data/rules.json: every requirement between a person and a Wisconsin school job, each with
 * its source, the date it was checked and how sure we are. Read-only at runtime.
 */
final class Rules {

	/** Labels for what an item holds up. */
	public const BLOCKS = array(
		'apply'     => 'Needed before you apply',
		'first_day' => 'Needed before your first day; apply meanwhile',
		'none'      => 'Do any time',
	);

	public const PAYERS = array(
		'you'      => 'You pay',
		'employer' => 'The employer pays',
		'varies'   => 'Depends on the employer',
		'none'     => 'Free',
	);

	private static ?array $data = null;

	/** @return array<string, array> id => item */
	public static function items(): array {
		return self::data()['items'] ?? array();
	}

	public static function item( string $id ): ?array {
		return self::items()[ $id ] ?? null;
	}

	public static function groups(): array {
		return self::data()['groups'] ?? array();
	}

	public static function where_options(): array {
		return self::data()['where_options'] ?? array();
	}

	/** What an agency adds to the usual steps (public source only), or null. */
	public static function agency( string $key ): ?array {
		return self::data()['agencies'][ $key ] ?? null;
	}

	/** Weeks for a day range: [min, max], at least 1 when anything is needed. */
	public static function weeks( int $min_days, int $max_days ): array {
		return array( (int) max( 0, ceil( $min_days / 7 ) ), (int) max( 0, ceil( $max_days / 7 ) ) );
	}

	/** "2–4 weeks", "about 1 week", "1–2 years". */
	public static function span_label( int $min_days, int $max_days ): string {
		if ( $max_days <= 0 ) {
			return '';
		}
		if ( $min_days >= 300 ) {
			$a = (int) round( $min_days / 365 );
			$b = (int) round( $max_days / 365 );
			return $a === $b ? sprintf( _n( 'about %d year', 'about %d years', $a, 'mmi-teach' ), $a ) : sprintf( '%d–%d years', $a, $b );
		}
		[ $a, $b ] = self::weeks( $min_days, $max_days );
		if ( $b <= 1 ) {
			return $max_days <= 1 ? __( 'a day', 'mmi-teach' ) : __( 'under a week', 'mmi-teach' );
		}
		return $a === $b ? sprintf( 'about %d weeks', $a ) : sprintf( '%d–%d weeks', max( 1, $a ), $b );
	}

	/** "$135", "$0–$80", "Free". */
	public static function cost_label( int $min, int $max ): string {
		if ( $max <= 0 ) {
			return __( 'Free', 'mmi-teach' );
		}
		return $min === $max ? '$' . number_format( $max ) : '$' . number_format( $min ) . '–$' . number_format( $max );
	}

	/** Items whose verified_on is older than the cutoff (Y-m-d), for the settings screen. */
	public static function stale( string $cutoff ): array {
		$out = array();
		foreach ( self::items() as $id => $item ) {
			if ( ( $item['verified_on'] ?? '' ) < $cutoff ) {
				$out[ 'Rule: ' . $item['title'] ] = $item['verified_on'] ?? '';
			}
		}
		return $out;
	}

	private static function data(): array {
		if ( null === self::$data ) {
			$raw        = json_decode( (string) file_get_contents( MMI_TEACH_DIR . 'data/rules.json' ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local plugin file.
			self::$data = is_array( $raw ) ? $raw : array();
		}
		return self::$data;
	}
}
