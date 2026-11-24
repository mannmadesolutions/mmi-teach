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

	/** Classroom aides: paraprofessionals and instructional, educational or special-education assistants… */
	private const AIDE = '/\b(para[- ]?(professional|educator)s?|paras?|(teacher|teaching|instructional|educational|education|classroom|special education|sped|program|student|bilingual|learning|resource|4k|5k|preschool|kindergarten|title i)\s*(assistants?|aides?)|aides?|educational support (professional|personnel)s?|ESP)\b/i';

	/** …but not health, transport, supervision, clerical, interpreting or care roles that WECAN files as aides too. */
	private const NOT_AIDE = '/\b(health|residential|aoda|attendance|cna|nurs\w*|medical|bus|transport\w*|driver|recess|lunch|cafeteria|playground|supervis\w*|monitor|clerk|secretar\w*|office|custod\w*|food|kitchen|after[- ]?school|kids club|extended (day|care)|before[- ]?(and[- ]?after[- ]?)?school|child ?care|daycare|interpreter|coach|library|media|family engagement|technology|security|behavior technician|rbt)\b/i';

	public function fetch_details( string $source_id ): array {
		return array(); // These boards' list data is all the site uses.
	}

	/** 'teacher' (including substitute teachers), 'aide', or '' for a posting this site doesn't list. */
	public static function role( string $title ): string {
		if ( self::is_aide( $title ) ) {
			return 'aide';
		}
		return self::is_teaching( $title ) ? 'teacher' : '';
	}

	public static function is_teaching( string $title ): bool {
		return (bool) preg_match( self::TEACHING, $title ) && ! self::is_excluded( $title );
	}

	/** A classroom aide or paraprofessional posting (including aide substitutes). */
	public static function is_aide( string $title ): bool {
		return (bool) preg_match( self::AIDE, $title ) && ! preg_match( self::NOT_AIDE, $title );
	}

	/** Support, leadership and athletics roles, even when "teacher" is in the title. */
	public static function is_excluded( string $title ): bool {
		return (bool) preg_match( self::NOT_TEACHING, $title );
	}

	/** WECAN's position-type names, so filters and the classifier treat every source alike. */
	protected static function position_type( string $title ): string {
		if ( self::is_aide( $title ) ) {
			return 'Assistant/Aide';
		}
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
				'pay_text'      => '', // The board's own pay field, if it has one; Pay parses it.
				'req_text'      => '', // Quotes where the board's text states requirements (Requirements::quotes).
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
