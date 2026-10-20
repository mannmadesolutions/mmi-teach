<?php
namespace MMI_Teach\Sources;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Shared pieces for single-employer job boards (an ATS that lists one school network's
 * openings, all job types mixed). WECAN doesn't need this: it already sorts by position type.
 */
abstract class Board implements Source {

	/** Titles that are teaching positions… */
	private const TEACHING = '/\b(teachers?|teaching position|educators?|instructors?|interventionist|reading specialist|special education|literacy coach|instructional coach|math coach)\b/i';

	/** …unless they're one of these. */
	private const NOT_TEACHING = '/\b(assistants?|aides?|aid|para|paraprofessionals?|supervisor|extended care|after[- ]?school|director|principal|dean|coordinator|manager|clc|group leader|student teach\w*|intern(ship)?|parent educator|associate teacher|recess|lunch|athletic|(basketball|football|soccer|volleyball|baseball|softball|track|lacrosse|swim\w*|tennis|golf|wrestling|cross country|dance|cheer\w*) coach)\b/i';

	public function fetch_details( string $source_id ): array {
		return array(); // These boards' list data is all the dashboard uses.
	}

	public static function is_teaching( string $title ): bool {
		return (bool) preg_match( self::TEACHING, $title ) && ! self::is_excluded( $title );
	}

	/** Support, leadership and athletics roles, even when "teacher" is in the title. */
	public static function is_excluded( string $title ): bool {
		return (bool) preg_match( self::NOT_TEACHING, $title );
	}

	/** WECAN's position-type names, so filters and the classifier treat every source alike. */
	protected static function position_type( string $title ): string {
		return preg_match( '/\bsub(stitute)?s?\b/i', $title ) ? 'Substitute Teachers' : 'Teacher';
	}

	/** Builds a normalized row; only teaching titles should reach here. */
	protected function row( string $id, string $title, string $employer, string $location, array $extra = array() ): array {
		return array_merge(
			array(
				'source'        => $this->id(),
				'source_id'     => substr( $id, 0, 64 ),
				'title'         => self::clean( $title ),
				'employer'      => $employer,
				'location'      => self::clean( $location ),
				'position_type' => self::position_type( $title ),
				'appointment'   => '',
				'posted_date'   => null,
				'updated_date'  => null,
				'start_text'    => '',
				'close_text'    => '',
				'close_date'    => null,
				'url'           => '',
				'apply_url'     => '',
			),
			$extra
		);
	}

	/** Any date string or Unix-ms timestamp => Y-m-d (site time zone), or null. */
	protected static function date( string|int|float|null $value ): ?string {
		if ( null === $value || '' === $value ) {
			return null;
		}
		$ts = is_numeric( $value ) ? (int) ( $value > 1e11 ? $value / 1000 : $value ) : strtotime( (string) $value );
		return $ts ? wp_date( 'Y-m-d', $ts ) : null;
	}

	protected static function clean( string $html ): string {
		$text = html_entity_decode( wp_strip_all_tags( $html ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		return trim( preg_replace( '/\s+/u', ' ', $text ) );
	}

	protected static function appointment( string $text ): string {
		if ( preg_match( '/part[- ]?time/i', $text ) ) {
			return 'Part Time';
		}
		return preg_match( '/full[- ]?time/i', $text ) ? 'Full Time' : '';
	}
}
