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

		$snapshot = array(
			'generated' => gmdate( 'c' ),
			'since'     => $since, // Undated postings first seen on this day were already open before tracking began.
			'counties'  => array_values( Plugin::COUNTIES ),
			'subjects'  => Classifier::subjects(),
			'subKinds'  => Classifier::SUB_KINDS,
			'sources'   => $sources,
			'unplaced'  => count( Store::unmapped_employers() ),
			'vacancies' => $vacancies,
		);
		update_option( self::OPTION, $snapshot, false );
		return $snapshot;
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
