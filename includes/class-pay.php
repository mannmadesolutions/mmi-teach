<?php
namespace MMI_Teach;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Turns a posting's free-text pay ("$185 per day", "$47,000 (0 Years) - $90,467 (25+ Years)",
 * "Teacher Salary Schedule", "Competitive") into comparable numbers.
 *
 * Result keys: min, max (floats or null), unit (hour|day|year|stipend|''), basis (posted =
 * an amount is given, schedule = "see the salary schedule", competitive = depends/negotiable,
 * none = nothing usable), day (a daily equivalent for sorting and comparing, or null).
 */
final class Pay {

	/** Daily equivalents: a sub day is about 7 paid hours; a teaching contract about 180 days. */
	private const HOURS_PER_DAY = 7;
	private const DAYS_PER_YEAR = 180;

	private const MONEY = '/(\$)?\s*(\d{1,3}(?:,\d{3})+|\d+)(?:\.(\d{1,2}))?\s*(k\b)?/i';

	public static function parse( string $text ): array {
		$text   = trim( html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
		$result = array(
			'min'   => null,
			'max'   => null,
			'unit'  => '',
			'basis' => 'none',
			'day'   => null,
		);
		if ( '' === $text ) {
			return $result;
		}

		$amounts = self::first_amount( $text );
		if ( ! $amounts ) {
			$result['basis'] = self::basis_without_amount( $text );
			return $result;
		}

		[ $min, $max, $after ] = $amounts;
		$unit                  = self::unit( $after, $text, $min ?? $max );
		$result                = array_merge(
			$result,
			array(
				'min'   => $min,
				'max'   => $max,
				'unit'  => $unit,
				'basis' => 'posted',
			)
		);
		$result['day'] = self::day_equivalent( $min ?? $max, $unit );
		return $result;
	}

	/** Short display form: "$185/day", "$47,000–$90,467/yr", "Salary schedule". */
	public static function label( array $pay ): string {
		if ( 'posted' !== $pay['basis'] ) {
			return array(
				'schedule'    => 'Salary schedule',
				'competitive' => 'Depends on experience',
			)[ $pay['basis'] ] ?? '';
		}
		$suffix = array(
			'hour'    => '/hr',
			'day'     => '/day',
			'year'    => '/yr',
			'stipend' => ' stipend',
		)[ $pay['unit'] ] ?? '';
		$fmt    = static fn( $n ) => '$' . number_format( (float) $n, fmod( (float) $n, 1.0 ) ? 2 : 0 );
		if ( null === $pay['min'] ) {
			return 'Up to ' . $fmt( $pay['max'] ) . $suffix;
		}
		return $fmt( $pay['min'] ) . ( null !== $pay['max'] && $pay['max'] > $pay['min'] ? '–' . $fmt( $pay['max'] ) : '' ) . $suffix;
	}

	/**
	 * The first amount (or range) in the text: [min, max, text after it]. Later amounts are
	 * usually step increments ("$48,000 - $1,000 each year") or other conditions, so they're ignored.
	 */
	private static function first_amount( string $text ): ?array {
		if ( ! preg_match_all( self::MONEY, $text, $m, PREG_SET_ORDER | PREG_OFFSET_CAPTURE ) ) {
			return null;
		}
		$tokens = array();
		foreach ( $m as $match ) {
			$value = (float) str_replace( ',', '', $match[2][0] ) + ( isset( $match[3][0] ) && '' !== $match[3][0] ? (float) ( '0.' . $match[3][0] ) : 0 );
			$k     = ! empty( $match[4][0] );
			$end   = $match[0][1] + strlen( $match[0][0] );
			$rest  = substr( $text, $end );
			// Counts of years, days or steps aren't money: "(0 Years)", "25+ Years", "days 1-20".
			if ( ! $match[1][0] && ! $k && preg_match( '/^\s*\+?\s*(years?|yrs?|days?\b(?!\s*rate)|steps?|weeks?|months?)/i', $rest ) ) {
				continue;
			}
			if ( ! $match[1][0] && ! $k && preg_match( '/\b(days?|grades?|year)\s*$/i', substr( $text, 0, $match[0][1] ) ) ) {
				continue;
			}
			$tokens[] = array(
				'value'  => $k ? $value * 1000 : $value,
				'dollar' => (bool) $match[1][0],
				'k'      => $k,
				'start'  => $match[0][1],
				'end'    => $end,
			);
		}
		$money = array_values( array_filter( $tokens, static fn( $t ) => $t['dollar'] || $t['k'] || $t['value'] >= 1000 || self::unit_follows( substr( $text, $t['end'] ) ) ) );
		if ( ! $money && $tokens ) {
			// "20-24/hr": the first number's partner carries the unit.
			$money = $tokens;
			if ( ! self::unit_follows( substr( $text, end( $tokens )['end'] ) ) ) {
				return null;
			}
		}
		if ( ! $money ) {
			return null;
		}

		$first = $money[0];
		$index = array_search( $first, $tokens, true );
		$prev  = $tokens[ $index - 1 ] ?? null;
		if ( $prev && self::is_range_gap( substr( $text, $prev['end'], $first['start'] - $prev['end'] ) ) ) {
			// "20-24/hr", "40-70K": only the second number carries the unit or the K.
			$low = $first['k'] && $prev['value'] < 1000 ? $prev['value'] * 1000 : $prev['value'];
			if ( $low <= $first['value'] ) {
				return array( $low, $first['value'], substr( $text, $first['end'] ) );
			}
		}
		$min   = $first['value'];
		$max   = null;
		$end   = $first['end'];
		$next  = $tokens[ $index + 1 ] ?? null;
		if ( $next && self::is_range_gap( substr( $text, $first['end'], $next['start'] - $first['end'] ) ) ) {
			$hi = $next['value'];
			if ( $next['k'] && ! $first['k'] && $min < 1000 ) {
				$min *= 1000; // "40-70K".
			}
			if ( $hi >= $min && $hi <= $min * 4 ) {
				$max = $hi;
				$end = $next['end'];
			}
		}
		if ( preg_match( '/\bup to\s*$/i', substr( $text, 0, $first['start'] ) ) ) {
			return array( null, $min, substr( $text, $end ) );
		}
		return array( $min, $max, substr( $text, $end ) );
	}

	/** Text between two amounts that makes them a range: "-", "–", "to", optionally after "(0 Years)". */
	private static function is_range_gap( string $gap ): bool {
		return (bool) preg_match( '/^\s*(\([^)]*\))?\s*(-|–|—|to)\s*$/iu', $gap );
	}

	private static function unit_follows( string $rest ): bool {
		return (bool) preg_match( '/^\s*(\/|per\b|a\b|an\b)?\s*(hour|hr|day|daily|year|yr|annual)/i', $rest );
	}

	/** The unit stated right after the amount, else anywhere in the text, else guessed from size. */
	private static function unit( string $after, string $text, float $amount ): string {
		$unit = self::unit_word( substr( $after, 0, 40 ) ) ?: self::unit_word( $text );
		if ( ! $unit ) {
			if ( $amount < 75 ) {
				$unit = 'hour';
			} elseif ( $amount < 700 ) {
				$unit = 'day';
			} elseif ( $amount >= 15000 ) {
				$unit = 'year';
			} else {
				$unit = 'stipend';
			}
		}
		// "$23.12 Daily" is an hourly rate mislabeled; no Wisconsin sub day pays under $60.
		if ( 'day' === $unit && $amount < 60 ) {
			$unit = 'hour';
		}
		if ( 'hour' === $unit && $amount >= 100 ) {
			$unit = 'day';
		}
		if ( 'year' !== $unit && $amount >= 15000 ) {
			$unit = 'year';
		}
		return $unit;
	}

	private static function unit_word( string $text ): string {
		$words = array(
			'stipend' => '/stipend|semester|per (season|term)/i',
			'hour'    => '/\b(hour|hourly|hr)s?\b|\/\s*h(ou)?r/i',
			'day'     => '/\b(day|daily|per diem)\b|\/\s*day/i',
			'year'    => '/\b(annual(ly)?|per year|a year|yearly|\/\s*yr)\b|\bk\b/i',
		);
		foreach ( $words as $unit => $pattern ) {
			if ( preg_match( $pattern, $text ) ) {
				return $unit;
			}
		}
		return '';
	}

	private static function basis_without_amount( string $text ): string {
		if ( preg_match( '/schedule|scale|placement|rate of pay|daily rate|handbook|agreement|board polic/i', $text ) ) {
			return 'schedule';
		}
		if ( preg_match( '/competitive|commensurate|\bdoe\b|based (on|upon)|depend|negotiable|\btbd\b|varies/i', $text ) ) {
			return 'competitive';
		}
		return 'none';
	}

	private static function day_equivalent( ?float $amount, string $unit ): ?float {
		if ( null === $amount ) {
			return null;
		}
		$day = array(
			'hour' => $amount * self::HOURS_PER_DAY,
			'day'  => $amount,
			'year' => $amount / self::DAYS_PER_YEAR,
		)[ $unit ] ?? null;
		return null === $day ? null : round( $day, 2 );
	}
}
