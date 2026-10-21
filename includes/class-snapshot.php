<?php
namespace MMI_Teach;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The dashboard's data, built once per refresh (or settings change) and stored, so a page
 * view only reads one option and never queries or classifies anything.
 */
final class Snapshot {

	private const OPTION = 'mmi_teach_snapshot';

	public static function get(): array {
		$snapshot = get_option( self::OPTION );
		return is_array( $snapshot ) ? $snapshot : self::rebuild();
	}

	public static function rebuild(): array {
		$status   = get_option( Refresher::OPTION_STATUS, array() );
		$sources  = array();
		$priority = array();
		foreach ( Refresher::sources() as $i => $source ) {
			$priority[ $source->id() ] = $i;
			$s         = $status['sources'][ $source->id() ] ?? array();
			$sources[] = array(
				'id'        => $source->id(),
				'label'     => $source->label(),
				'url'       => $source->home_url(),
				'lastOk'    => self::iso( $s['last_ok'] ?? '' ),
				'lastError' => (string) ( $s['last_error'] ?? '' ),
			);
		}

		$rows = Store::open_rows( array_keys( Plugin::COUNTIES ) );
		// Boards earlier in the source list win when the same job is listed twice.
		usort( $rows, static fn( $a, $b ) => ( $priority[ $a['source'] ] ?? 99 ) <=> ( $priority[ $b['source'] ] ?? 99 ) );

		$vacancies = array();
		$by_job    = array();
		$since     = get_date_from_gmt( (string) get_option( Refresher::OPTION_FIRST_RUN, current_time( 'mysql', true ) ), 'Y-m-d' );
		foreach ( $rows as $row ) {
			$job  = self::job_key( $row['employer'], $row['title'] );
			$twin = self::twin( $vacancies, $by_job[ $job ] ?? array(), $row['source'] );
			if ( null !== $twin ) {
				$vacancies[ $twin ]['alsoAt'][] = array(
					'source' => $row['source'],
					'url'    => $row['url'],
				);
				continue;
			}
			$by_job[ $job ][] = count( $vacancies );
			$details        = $row['details'] ? (array) json_decode( $row['details'], true ) : array();
			$vacancies[]    = array(
				'key'       => $row['source'] . ':' . $row['source_id'],
				'id'        => self::display_id( $row['source_id'] ),
				'source'    => $row['source'],
				'title'     => $row['title'],
				'employer'  => $row['employer'],
				'school'    => $row['location'],
				'county'    => Plugin::COUNTIES[ $row['county_code'] ] ?? '',
				'subject'   => $row['subject'],
				'band'      => $row['grade_band'],
				'type'      => $row['position_type'],
				'appt'      => $row['appointment'],
				// Boards without a posting date fall back to the day this plugin first saw the job.
				'posted'    => $row['posted_date'] ?: get_date_from_gmt( $row['first_seen'], 'Y-m-d' ),
				'postedSeen' => ! $row['posted_date'],
				'updated'   => $row['updated_date'],
				'start'     => $row['start_text'],
				'close'     => $row['close_text'],
				'closeDate' => $row['close_date'],
				'firstSeen' => self::iso( $row['first_seen'] ),
				'url'       => $row['url'],
				'apply'     => $row['apply_url'],
				'subKind'   => $row['sub_kind'],
				'agency'    => $row['agency'] ?: Agencies::DIRECT,
				'agencyBasis' => $row['agency_basis'],
				'agencyNote'  => self::agency_note( $row, $details ),
				'payText'   => $row['pay_text'] ?: ( $details['salary'] ?? '' ),
				'pay'       => Pay::label(
					array(
						'min'   => null === $row['pay_min'] ? null : (float) $row['pay_min'],
						'max'   => null === $row['pay_max'] ? null : (float) $row['pay_max'],
						'unit'  => $row['pay_unit'],
						'basis' => $row['pay_basis'],
					)
				),
				'payUnit'   => $row['pay_unit'],
				'payBasis'  => $row['pay_basis'],
				'payDay'    => null === $row['pay_day'] ? null : (float) $row['pay_day'],
				'licenses'  => $details['licenses'] ?? array(),
				'openings'  => $details['openings'] ?? '',
				'degree'    => $details['degree'] ?? '',
				'contact'   => $details['contact'] ?? (object) array(),
				'summary'   => $details['summary'] ?? '',
				'alsoAt'    => array(),
			);
		}
		usort( $vacancies, static fn( $a, $b ) => strcmp( (string) $b['posted'], (string) $a['posted'] ) );

		// Per-employer substitute summary for the "Become a substitute" page.
		$sub_employers = array();
		foreach ( $vacancies as $v ) {
			if ( '' === $v['subKind'] ) {
				continue;
			}
			$e = &$sub_employers[ $v['employer'] ];
			$e ??= array(
				'employer' => $v['employer'],
				'county'   => $v['county'],
				'count'    => 0,
				'kinds'    => array(),
				'payMin'   => null,
				'payMax'   => null,
				'agencies' => array(),
			);
			++$e['count'];
			$e['agencies'][ $v['agency'] ] = ( $e['agencies'][ $v['agency'] ] ?? 0 ) + 1;
			$e['kinds'][ $v['subKind'] ] = ( $e['kinds'][ $v['subKind'] ] ?? 0 ) + 1;
			if ( null !== $v['payDay'] ) {
				$e['payMin'] = null === $e['payMin'] ? $v['payDay'] : min( $e['payMin'], $v['payDay'] );
				$e['payMax'] = null === $e['payMax'] ? $v['payDay'] : max( $e['payMax'], $v['payDay'] );
			}
			unset( $e );
		}
		usort( $sub_employers, static fn( $a, $b ) => $b['count'] <=> $a['count'] ?: strcmp( $a['employer'], $b['employer'] ) );

		$snapshot = array(
			'generated' => gmdate( 'c' ),
			'since'     => $since, // Undated postings first seen on this day were already open before tracking began.
			'counties'  => array_values( Plugin::COUNTIES ),
			'subjects'  => Classifier::subjects(),
			'subKinds'  => Classifier::SUB_KINDS,
			'agencies'  => Agencies::labels(),
			'sources'   => $sources,
			'unplaced'  => count( Store::unmapped_employers() ),
			'subEmployers' => $sub_employers,
			'vacancies' => $vacancies,
		);
		update_option( self::OPTION, $snapshot, false );
		return $snapshot;
	}

	/** Why the posting got its agency: the posting's own words, or the employer's confirmed route. */
	private static function agency_note( array $row, array $details ): string {
		if ( 'posting' === $row['agency_basis'] ) {
			$quote = $details['agency_text'] ?? '';
			return $quote ? 'The posting says: “' . $quote . '”' : 'The posting names this agency.';
		}
		$note = Agencies::employer_notes()[ Places::normalize( $row['employer'] ) ] ?? array();
		if ( 'employer' === $row['agency_basis'] || 'likely' === $row['agency_basis'] ) {
			$how = 'direct' === $row['agency'] ? 'hires its substitutes directly' : 'fills substitute jobs through ' . Agencies::name( $row['agency'] );
			return trim( ( 'likely' === $row['agency_basis'] ? 'Likely: ' : '' ) . $row['employer'] . ' ' . $how . '. ' . ( $note['extras'] ?? '' ) );
		}
		if ( 'unknown' === $row['agency_basis'] ) {
			$daily = Agencies::route( $row['employer'], 'daily' );
			if ( $daily && 'direct' !== $daily['agency'] ) {
				return $row['employer'] . ' uses ' . Agencies::name( $daily['agency'] ) . ' for daily subs, but this posting doesn’t say who employs this ' . str_replace( '_', '-', $row['sub_kind'] ) . ' sub.';
			}
			return 'We haven’t confirmed whether this employer fills substitute jobs through an agency.';
		}
		return '';
	}

	/** Employer + title, ignoring school years, bracketed notes, punctuation and case. */
	private static function job_key( string $employer, string $title ): string {
		$title = strtolower( preg_replace( '/\b(20)?\d{2}\s*[-–\/]\s*(20)?\d{2}\b|\(.*?\)/', ' ', $title ) );
		return Places::normalize( $employer ) . '|' . preg_replace( '/[^a-z0-9]+/', '', $title );
	}

	/**
	 * A kept posting with the same key from a different board that hasn't already absorbed one
	 * from this board. Same-board matches stay separate: a district listing "Special Education
	 * Teacher" at five schools has five openings.
	 *
	 * @param int[] $candidates Indexes into $vacancies sharing the key.
	 */
	private static function twin( array $vacancies, array $candidates, string $source ): ?int {
		foreach ( $candidates as $i ) {
			$v = $vacancies[ $i ];
			if ( $v['source'] !== $source && ! in_array( $source, array_column( $v['alsoAt'], 'source' ), true ) ) {
				return $i;
			}
		}
		return null;
	}

	/**
	 * The job number people can quote back to the board: WECAN's and MPS's numeric ids, the
	 * Paycom job number after its client key. Hash-style ids show blank.
	 */
	private static function display_id( string $source_id ): string {
		if ( ctype_digit( $source_id ) ) {
			return $source_id;
		}
		return preg_match( '/^[0-9A-F]{32}-(\d+)$/', $source_id, $m ) ? $m[1] : '';
	}

	/** MySQL UTC datetime => ISO 8601, '' stays ''. */
	private static function iso( string $mysql_utc ): string {
		return '' === $mysql_utc ? '' : gmdate( 'c', (int) strtotime( $mysql_utc . ' UTC' ) );
	}
}
