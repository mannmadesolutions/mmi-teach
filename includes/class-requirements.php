<?php
namespace MMI_Teach;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * What it takes to qualify for a posting: the minimum degree, the Wisconsin DPI license(s)
 * that qualify, years of experience, specializations and what to submit.
 *
 * Each part says where it came from:
 *  - posted   – the posting's own requirement fields (WECAN's Degree and Licenses lists);
 *  - text     – the posting's description states it ("Bachelor's degree required", "DPI #1801");
 *  - rule     – not stated, so the Wisconsin rule for this kind of job applies (a DPI teaching
 *               license needs a bachelor's degree; the substitute permit an associate degree);
 *  - inferred – licenses matched from the title, subject and grade level against
 *               data/licenses.json.
 */
final class Requirements {

	/** Degree levels, lowest first, with display labels. */
	public const DEGREES = array(
		'none'        => 'No degree',
		'high_school' => 'High school diploma',
		'associate'   => 'Associate degree',
		'bachelor'    => 'Bachelor’s degree',
		'master'      => 'Master’s degree',
		'doctorate'   => 'Doctorate',
	);

	/** Phrases worth quoting from a description: degree, license, certification, experience. */
	private const QUOTE_PATTERN = '/\b(bachelor\'?s|master\'?s|associate\'?s|degree|licen[cs](e|ure|ed)|certifi(ed|cation)|endorse(d|ment)|dpi\b|#\s?\d{4}\b|years? (of )?(teaching|classroom|experience)|bilingual|fluent|praxis|foundations of reading)/i';

	private static ?array $taxonomy = null;

	/** Short quotes around requirement statements (like Agencies::quotes), at most four. */
	public static function quotes( string $text ): array {
		if ( ! preg_match_all( self::QUOTE_PATTERN, $text, $m, PREG_OFFSET_CAPTURE ) ) {
			return array();
		}
		$quotes = array();
		$last   = -1;
		foreach ( $m[0] as [ $hit, $at ] ) {
			if ( $at < $last ) {
				continue;
			}
			$start    = max( 0, $at - 70 );
			$end      = min( strlen( $text ), $at + strlen( $hit ) + 110 );
			$snip     = preg_replace( array( '/^\S*\s/', '/\s\S*$/' ), '', substr( $text, $start, $end - $start ) );
			$quotes[] = ( $start > 0 ? '…' : '' ) . trim( $snip ) . ( $end < strlen( $text ) ? '…' : '' );
			$last     = $end;
			if ( count( $quotes ) >= 4 ) {
				break;
			}
		}
		return $quotes;
	}

	/**
	 * The full requirement picture for one posting.
	 *
	 * @param array $row     Posting row (title, employer, position_type, sub_kind, subject,
	 *                       grade_band, req_text).
	 * @param array $details Decoded detail-page data (degree, licenses, experience, materials, req_text).
	 */
	public static function for_posting( array $row, array $details ): array {
		$text     = trim( ( $details['req_text'] ?? '' ) . ' ' . ( $row['req_text'] ?? '' ) . ' ' . $row['title'] );
		$sub_kind = (string) ( $row['sub_kind'] ?? '' );

		// Degree: the posted field, else the description, else the Wisconsin rule.
		$degree = self::posted_degree( (string) ( $details['degree'] ?? '' ) );
		$basis  = $degree ? 'posted' : '';
		if ( ! $degree ) {
			$degree = self::degree_in_text( $text );
			$basis  = $degree ? 'text' : '';
		}
		$rule = self::degree_rule( $row, $sub_kind );
		if ( ! $degree ) {
			[ $degree, $basis ] = array( $rule['degree'], 'rule' );
		}

		// Licenses: the posted list, else license areas named in the text, else inferred.
		// Several WECAN names can mean one DPI license area (both "Speech/Language" names are 1820).
		$posted = array();
		foreach ( (array) ( $details['licenses'] ?? array() ) as $name ) {
			$l = self::license( $name );
			$posted[ '' !== $l['key'] ? $l['key'] : 'name:' . $l['title'] ] ??= $l;
		}
		$posted = array_values( $posted );
		$licenses = $posted;
		$lbasis   = $posted ? 'posted' : '';
		if ( ! $licenses ) {
			// Only the posting's own requirement wording counts as "stated"; title words are inference.
			$licenses = self::licenses_in_text( trim( ( $details['req_text'] ?? '' ) . ' ' . ( $row['req_text'] ?? '' ) ) );
			$lbasis   = $licenses ? 'text' : '';
		}
		if ( ! $licenses ) {
			$licenses = self::inferred_licenses( $row, $sub_kind );
			$lbasis   = $licenses ? 'inferred' : 'none';
		}

		return array(
			'degree'          => $degree,
			'degreeBasis'     => $basis,
			'degreeNote'      => 'rule' === $basis ? $rule['note'] : '',
			'licenses'        => array_values( $licenses ),
			'licenseBasis'    => $lbasis,
			'experience'      => isset( $details['experience'] ) ? (int) $details['experience'] : null,
			'specializations' => self::specializations( $row, $licenses, $text ),
			'materials'       => array_values( (array) ( $details['materials'] ?? array() ) ),
			'routes'          => self::routes( $row, $sub_kind, $licenses ),
			'quote'           => $details['req_text'] ?? ( $row['req_text'] ?? '' ),
		);
	}

	/** Compact one-line form for tables and CSV: "Bachelor’s · Cross-Categorical SpEd +3". */
	public static function summary( array $req ): string {
		$parts = array( self::DEGREES[ $req['degree'] ] ?? '' );
		if ( $req['licenses'] ) {
			$first   = $req['licenses'][0]['short'] ?? $req['licenses'][0]['title'];
			$parts[] = $first . ( count( $req['licenses'] ) > 1 ? ' +' . ( count( $req['licenses'] ) - 1 ) : '' );
		}
		return implode( ' · ', array_filter( $parts ) );
	}

	/** WECAN's Degree field: Associate, Bachelor, Master, Doctorate; "Other" says nothing. */
	private static function posted_degree( string $value ): string {
		$map = array(
			'associate' => 'associate',
			'bachelor'  => 'bachelor',
			'master'    => 'master',
			'doctor'    => 'doctorate',
			'high'      => 'high_school',
		);
		foreach ( $map as $needle => $level ) {
			if ( '' !== $value && stripos( $value, $needle ) === 0 ) {
				return $level;
			}
		}
		return '';
	}

	/** The minimum degree a description states as required (preferred degrees don't count). */
	private static function degree_in_text( string $text ): string {
		if ( preg_match( '/master\'?s(\s+degree)?\s+(is\s+)?(required|needed)|requires?\s+a\s+master/i', $text ) ) {
			return 'master';
		}
		if ( preg_match( '/\b(bachelor\'?s|baccalaureate|b\.?a\.?\/b\.?s\.?|4-year degree)\b/i', $text ) ) {
			return 'bachelor';
		}
		if ( preg_match( '/\bassociate\'?s(\s+degree)?\b|\b2-year degree\b/i', $text ) ) {
			return 'associate';
		}
		if ( preg_match( '/high school (diploma|graduate)|\bged\b/i', $text ) ) {
			return 'high_school';
		}
		return '';
	}

	/**
	 * When a posting doesn't say: substitute permits need an associate degree (some employers ask
	 * for more), long-term subs and licensed teachers need a bachelor's, pupil-services licenses a
	 * master's; data/licenses.json carries the sector exceptions.
	 *
	 * @return array{degree: string, note: string}
	 */
	private static function degree_rule( array $row, string $sub_kind ): array {
		$tax      = self::taxonomy();
		$employer = Agencies::employer_notes()[ Places::normalize( (string) $row['employer'] ) ] ?? array();
		if ( 'daily' === $sub_kind || 'building' === $sub_kind ) {
			if ( preg_match( '/bachelor/i', $employer['degree'] ?? '' ) ) {
				return array(
					'degree' => 'bachelor',
					'note'   => $employer['name'] . ' requires a bachelor’s degree for substitutes, though DPI’s permit needs only an associate degree.',
				);
			}
			return array(
				'degree' => 'associate',
				'note'   => $tax['rules']['substitute'] ?? 'DPI’s short-term substitute permit needs at least an associate degree plus approved training.',
			);
		}
		if ( 'long_term' === $sub_kind ) {
			return array(
				'degree' => 'bachelor',
				'note'   => $tax['rules']['long_term'] ?? 'Long-term assignments over 45 days need a teaching license in the subject, which requires a bachelor’s degree.',
			);
		}
		// Otherwise the highest degree the job's usual licenses need (pupil services: master's;
		// Head Start: associate), defaulting to a teaching license's bachelor's.
		$order = array_keys( self::DEGREES );
		$need  = '';
		foreach ( self::inferred_licenses( $row, '' ) as $license ) {
			$deg = $tax['licenses'][ $license['key'] ]['degree'] ?? '';
			if ( in_array( $deg, $order, true ) && ( '' === $need || array_search( $deg, $order, true ) > array_search( $need, $order, true ) ) ) {
				$need = $deg;
			}
		}
		if ( 'master' === $need || 'doctorate' === $need ) {
			return array(
				'degree' => $need,
				'note'   => $tax['rules']['pupil_services'] ?? 'Pupil-services licenses require a master’s degree.',
			);
		}
		if ( 'associate' === $need ) {
			return array(
				'degree' => 'associate',
				'note'   => $tax['licenses']['head_start']['notes'] ?? '',
			);
		}
		return array(
			'degree' => 'bachelor',
			'note'   => $tax['rules']['teacher'] ?? 'A Wisconsin teaching license requires a bachelor’s degree.',
		);
	}

	/** A posted license name normalized against the taxonomy (unknown names are kept as-is). */
	private static function license( string $name ): array {
		foreach ( self::taxonomy()['licenses'] ?? array() as $key => $l ) {
			foreach ( $l['wecan'] ?? array() as $alias ) {
				if ( 0 === strcasecmp( $alias, $name ) ) {
					return self::entry( $key, $l, $name );
				}
			}
		}
		foreach ( self::taxonomy()['licenses'] ?? array() as $key => $l ) {
			if ( ! empty( $l['aliases'] ) && preg_match( '/\b(?:' . implode( '|', $l['aliases'] ) . ')\b/i', $name ) ) {
				return self::entry( $key, $l, $name );
			}
		}
		return array(
			'key'      => '',
			'title'    => $name,
			'short'    => $name,
			'category' => '',
			'codes'    => array(),
			'levels'   => '',
		);
	}

	private static function entry( string $key, array $l, string $as_posted = '' ): array {
		return array(
			'key'      => $key,
			'title'    => $l['title'],
			'short'    => $l['short'] ?? $l['title'],
			'category' => $l['category'] ?? '',
			'codes'    => $l['codes'] ?? array(),
			'levels'   => $l['levels'] ?? '',
			'posted'   => $as_posted,
		);
	}

	/** License areas a description names ("license in Cross-Categorical", "#1801"). */
	private static function licenses_in_text( string $text ): array {
		$found = array();
		if ( ! preg_match( '/licen[cs]|certifi|endorse|dpi|#\s?\d{4}/i', $text ) ) {
			return $found; // Subject words alone ("math teacher") aren't a stated requirement.
		}
		foreach ( self::taxonomy()['licenses'] ?? array() as $key => $l ) {
			$hit = false;
			foreach ( $l['codes'] ?? array() as $code ) {
				$hit = $hit || preg_match( '/#?\s?\b' . preg_quote( (string) $code, '/' ) . '\b/', $text );
			}
			if ( $hit || ( ! empty( $l['aliases'] ) && preg_match( '/\b(?:' . implode( '|', $l['aliases'] ) . ')\b/i', $text ) && preg_match( '/licen[cs]|certifi|endorse/i', $text ) ) ) {
				$found[ $key ] = self::entry( $key, $l );
			}
		}
		return array_values( $found );
	}

	/**
	 * The license areas a job like this normally needs, from the taxonomy's inference rules:
	 * each rule matches the title and/or subject and grade band and names license keys.
	 */
	private static function inferred_licenses( array $row, string $sub_kind ): array {
		$tax = self::taxonomy();
		if ( 'daily' === $sub_kind || 'building' === $sub_kind ) {
			$keys = $tax['infer']['substitute'] ?? array();
		} else {
			$keys = array();
			foreach ( $tax['infer']['rules'] ?? array() as $rule ) {
				$title_ok   = empty( $rule['title'] ) || preg_match( '/' . $rule['title'] . '/i', (string) $row['title'] );
				$subject_ok = empty( $rule['subject'] ) || in_array( $row['subject'] ?? '', (array) $rule['subject'], true );
				$band_ok    = empty( $rule['band'] ) || in_array( $row['grade_band'] ?? '', (array) $rule['band'], true );
				if ( $title_ok && $subject_ok && $band_ok ) {
					$keys = $rule['licenses'];
					break;
				}
			}
			if ( 'long_term' === $sub_kind ) {
				$keys = array_merge( $keys, $tax['infer']['long_term_extra'] ?? array() );
			}
		}
		$out = array();
		foreach ( array_unique( $keys ) as $key ) {
			if ( isset( $tax['licenses'][ $key ] ) ) {
				$out[] = self::entry( $key, $tax['licenses'][ $key ] );
			}
		}
		return $out;
	}

	/** Specializations worth surfacing on their own: special-education area, language, subject. */
	private static function specializations( array $row, array $licenses, string $text ): array {
		$out = array();
		foreach ( $licenses as $l ) {
			if ( in_array( $l['category'] ?? '', array( 'special_education', 'bilingual' ), true ) ) {
				$out[] = $l['short'] ?? $l['title'];
			}
		}
		if ( preg_match( '/\b(bilingual|fluent in|fluency in)\s+(\w+)/i', $text . ' ' . $row['title'], $m ) && ! preg_match( '/^(and|or|the|a)$/i', $m[2] ) ) {
			$out[] = 'Bilingual ' . ucfirst( strtolower( $m[2] ) );
		}
		return array_values( array_unique( $out ) );
	}

	/** Other ways in, when the license isn't held yet (teaching jobs only; pupil services have none). */
	private static function routes( array $row, string $sub_kind, array $licenses ): array {
		$tax = self::taxonomy();
		$cats = array_unique( array_column( $licenses, 'category' ) );
		if ( $cats && ! array_diff( $cats, array( 'pupil_services', 'other' ) ) && 'daily' !== $sub_kind && 'building' !== $sub_kind ) {
			return array();
		}
		if ( 'daily' === $sub_kind || 'building' === $sub_kind ) {
			return $tax['routes']['substitute'] ?? array();
		}
		if ( 'long_term' === $sub_kind ) {
			return $tax['routes']['long_term'] ?? array();
		}
		$sector = self::sector( (string) $row['employer'] );
		return array_merge( $tax['routes']['sector'][ $sector ] ?? array(), $tax['routes']['teacher'] ?? array() );
	}

	/** public, private or other, from the NCES directories (see Places::sector). */
	private static function sector( string $employer ): string {
		return Places::instance()->sector( $employer );
	}

	/**
	 * License names on open postings that data/licenses.json doesn't map yet, with counts.
	 * Admin-screen use only: it reads the open rows.
	 */
	public static function unmapped_names(): array {
		global $wpdb;
		$table = Store::table();
		$known = array();
		foreach ( self::taxonomy()['licenses'] ?? array() as $l ) {
			foreach ( $l['wecan'] ?? array() as $name ) {
				$known[ strtolower( $name ) ] = true;
			}
		}
		$out = array();
		foreach ( $wpdb->get_col( "SELECT details FROM {$table} WHERE removed_at IS NULL AND details LIKE '%\"licenses\"%'" ) as $json ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			foreach ( (array) ( json_decode( $json, true )['licenses'] ?? array() ) as $name ) {
				if ( ! isset( $known[ strtolower( $name ) ] ) ) {
					$out[ $name ] = ( $out[ $name ] ?? 0 ) + 1;
				}
			}
		}
		arsort( $out );
		return $out;
	}

	public static function taxonomy(): array {
		if ( null === self::$taxonomy ) {
			$raw            = json_decode( (string) file_get_contents( MMI_TEACH_DIR . 'data/licenses.json' ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local plugin file.
			self::$taxonomy = is_array( $raw ) ? $raw : array();
		}
		return self::$taxonomy;
	}
}
