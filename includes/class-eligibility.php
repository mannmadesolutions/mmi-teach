<?php
namespace MMI_Teach;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Whether a person can take a job, and what stands in the way.
 *
 * For one job: now (meets the job's credential rules), check (probably; one condition to
 * confirm with the employer), steps (reachable in weeks; usually you can apply meanwhile) or
 * later (needs a degree or a program). Each answer carries plain reasons, the missing
 * data/rules.json items ("gaps") and their combined time and cost. Never a bare "no": a later
 * answer always names the route and, where one exists, a role open today.
 */
final class Eligibility {

	public const NOW   = 'now';
	public const CHECK = 'check';
	public const STEPS = 'steps';
	public const LATER = 'later';

	public const LABELS = array(
		self::NOW   => 'You qualify',
		self::CHECK => 'You probably qualify',
		self::STEPS => 'You can qualify in weeks',
		self::LATER => 'Not this one yet',
	);

	/** Education ranks (Profile::EDU order) and the rank each degree requirement needs. */
	private const EDU_RANK = array(
		'none'         => 0,
		'high_school'  => 1,
		'some_college' => 2,
		'credits48'    => 3,
		'associate'    => 4,
		'bachelor'     => 5,
		'master'       => 6,
	);
	private const NEED_RANK = array(
		'none'        => 0,
		'high_school' => 1,
		'associate'   => 4,
		'bachelor'    => 5,
		'master'      => 6,
		'doctorate'   => 6,
	);

	/**
	 * A stored posting as the job array this class reads.
	 *
	 * @param array $row Row from the vacancies table (or a mt_job post's meta in the same shape).
	 */
	public static function job_from_row( array $row ): array {
		$req = is_array( $row['requirements'] ?? null ) ? $row['requirements'] : json_decode( (string) ( $row['requirements'] ?? '' ), true );
		$req = is_array( $req ) ? $req : array();
		return array(
			'role'          => (string) ( $row['role'] ?? '' ) ?: ( '' === (string) ( $row['sub_kind'] ?? '' ) ? 'teacher' : 'substitute' ),
			'sub_kind'      => (string) ( $row['sub_kind'] ?? '' ),
			'employer'      => (string) ( $row['employer'] ?? '' ),
			'title'         => (string) ( $row['title'] ?? '' ),
			'sector'        => (string) ( $row['sector'] ?? '' ) ?: Places::instance()->sector( (string) ( $row['employer'] ?? '' ) ),
			'degree'        => (string) ( $req['degree'] ?? 'bachelor' ),
			'degree_basis'  => (string) ( $req['degreeBasis'] ?? 'rule' ),
			'degree_note'   => (string) ( $req['degreeNote'] ?? '' ),
			'licenses'      => array_values( (array) ( $req['licenses'] ?? array() ) ),
			'license_basis' => (string) ( $req['licenseBasis'] ?? 'none' ),
		);
	}

	/**
	 * @param array      $job   See job_from_row().
	 * @param array|null $p     Profile::sanitize() output; null = not answered yet.
	 * @param array|null $tasks Profile::tasks(); done credential items count as held.
	 * @return array{status: string, label: string, can_apply: bool, reasons: string[], gaps: string[], maybe: string[], days: int[], cost: int[], summary: string}
	 */
	public static function for_job( array $job, ?array $p, ?array $tasks = null ): array {
		if ( null === $p ) {
			return self::result( '', array( __( 'Answer six questions to see whether you qualify.', 'mmi-teach' ) ), array() );
		}
		$tasks = $tasks ?? array();
		if ( 'u18' === $p['age'] ) {
			return self::result( self::LATER, array( __( 'Schools hire staff who are 18 or older.', 'mmi-teach' ) ), array() );
		}
		switch ( $job['role'] ) {
			case 'aide':
				$r = self::aide( $job, $p );
				break;
			case 'substitute':
				$r = 'long_term' === $job['sub_kind'] ? self::long_term_sub( $job, $p ) : self::sub( $job, $p );
				break;
			default:
				$r = self::teacher( $job, $p );
		}
		[ $status, $reasons, $gaps, $maybe ] = $r + array( 3 => array() );

		// Credential items the person has ticked as done (and not expired) no longer stand in the way.
		$gaps = array_values( array_filter( $gaps, static fn( $id ) => 'done' !== Profile::task( $id, $tasks )[0] ) );
		if ( self::STEPS === $status && ! $gaps ) {
			$status    = self::NOW;
			$reasons[] = __( 'Your checklist shows the missing steps done.', 'mmi-teach' );
		}
		// Fingerprints go with any new DPI application by someone who's lived out of state.
		if ( $p['outside_wi'] && array_intersect( $gaps, array( 'sub_permit', 'long_term_license', 'teaching_license', 'sepa_license', 'stipulations' ) ) && ! in_array( 'fingerprints', $gaps, true ) && 'done' !== Profile::task( 'fingerprints', $tasks )[0] ) {
			$gaps[] = 'fingerprints';
		}
		return self::result( $status, $reasons, $gaps, $maybe );
	}

	/* ---------- by role ---------- */

	/** Daily and building substitute jobs. */
	private static function sub( array $job, array $p ): array {
		$need = self::NEED_RANK[ $job['degree'] ] ?? 4;
		$have = self::EDU_RANK[ $p['edu'] ];
		// An educator-program enrollee qualifies for the permit without an associate degree (PI 34.032(2)(b)).
		$epp_route = $p['epp'] && '20' === $p['age'];
		$degree_ok = $have >= $need || ( $epp_route && 'associate' === $job['degree'] );

		if ( ! $degree_ok ) {
			if ( 'bachelor' === $job['degree'] ) {
				$why = $job['degree_note'] ?: sprintf( __( '%s asks substitutes for a bachelor’s degree.', 'mmi-teach' ), $job['employer'] );
				return array( self::LATER, array( $why, self::aide_alternative( $p ) ), array( 'bachelor_degree' ) );
			}
			return array(
				self::LATER,
				array(
					__( 'DPI’s substitute permit needs an associate degree, or enrollment in a teacher-prep program at age 20 or older.', 'mmi-teach' ),
					self::aide_alternative( $p ),
				),
				array( 'associate_degree' ),
			);
		}
		if ( in_array( $p['cred'], array( 'permit', 'long_term', 'wi_license' ), true ) ) {
			return array( self::NOW, array( sprintf( __( 'Your %s covers substitute teaching.', 'mmi-teach' ), self::cred_name( $p['cred'] ) ) ), array() );
		}
		if ( in_array( $p['cred'], array( 'expired', 'oos' ), true ) ) {
			return array(
				self::STEPS,
				array(
					'expired' === $p['cred']
						? __( 'With a lapsed Wisconsin license, the long-term substitute license is your quickest route; no training course needed.', 'mmi-teach' )
						: __( 'With a license from another state, the long-term substitute license is your quickest route; no training course needed.', 'mmi-teach' ),
					__( 'Apply to employers now; only your first day waits on DPI.', 'mmi-teach' ),
				),
				array( 'long_term_license' ),
			);
		}
		$gaps = $epp_route && $have < 4 ? array( 'sub_permit' ) : array( 'sub_training', 'sub_permit' );
		return array(
			self::STEPS,
			array(
				$epp_route && $have < 4
					? __( 'As a teacher-prep student you can get the substitute permit without a training course; your program signs form PI-1975.', 'mmi-teach' )
					: __( 'You qualify for DPI’s substitute permit after a short training course.', 'mmi-teach' ),
				__( 'Apply to employers now; only your first day waits on the permit.', 'mmi-teach' ),
			),
			$gaps,
		);
	}

	/** Long-term substitute jobs: more than 45 consecutive days needs a license for the assignment. */
	private static function long_term_sub( array $job, array $p ): array {
		$areas   = self::job_areas( $job );
		$special = $areas && ! array_diff( array_column( $areas, 'category' ), array( 'special_education' ) );
		if ( in_array( $p['cred'], array( 'wi_license', 'long_term' ), true ) ) {
			return self::area_match( $job, $p, $areas );
		}
		if ( 'permit' === $p['cred'] ) {
			return array(
				self::CHECK,
				array(
					$special
						? __( 'Your permit covers up to 45 consecutive days; special-education assignments longer than that need a special-education license.', 'mmi-teach' )
						: __( 'Your permit covers up to 45 consecutive days in one assignment. Ask how long this one runs; longer needs a license in the subject.', 'mmi-teach' ),
				),
				array(),
				array( 'long_term_license' ),
			);
		}
		if ( in_array( $p['cred'], array( 'expired', 'oos' ), true ) ) {
			return array( self::STEPS, array( __( 'The long-term substitute license covers assignments in your license area; apply for it alongside the job.', 'mmi-teach' ) ), array( 'long_term_license' ) );
		}
		if ( self::EDU_RANK[ $p['edu'] ] >= 5 ) {
			return array(
				self::STEPS,
				array(
					__( 'With a bachelor’s degree, get the substitute permit (up to 45 days in one assignment); for longer, the district can request a license with stipulations.', 'mmi-teach' ),
				),
				array( 'sub_training', 'sub_permit' ),
				array( 'stipulations' ),
			);
		}
		$daily = self::sub( array_merge( $job, array( 'sub_kind' => 'daily', 'degree' => 'associate' ) ), $p );
		if ( self::LATER === $daily[0] ) {
			return $daily;
		}
		return array(
			self::LATER,
			array( __( 'Long-term assignments need a teaching license, which needs a bachelor’s degree. Daily substitute jobs are open to you sooner.', 'mmi-teach' ) ),
			array( 'bachelor_degree' ),
		);
	}

	private static function teacher( array $job, array $p ): array {
		$areas  = self::job_areas( $job );
		$need   = self::NEED_RANK[ $job['degree'] ] ?? 5;
		$have   = self::EDU_RANK[ $p['edu'] ];
		$pupil  = in_array( 'pupil_services', array_column( $areas, 'category' ), true );
		if ( $pupil && ! ( 'wi_license' === $p['cred'] && self::areas_overlap( $areas, $p ) ) ) {
			return array( self::LATER, array( $job['degree_note'] ?: __( 'This is a pupil-services job (counselor, psychologist, speech or social work), which needs a master’s-level license.', 'mmi-teach' ) ), array() );
		}
		if ( $have < max( 5, $need ) && 'private' !== $job['sector'] ) {
			return array(
				self::LATER,
				array(
					__( 'Teaching jobs need a Wisconsin teaching license, and every license needs a bachelor’s degree.', 'mmi-teach' ),
					self::sub_alternative( $p ),
				),
				array( 'bachelor_degree', 'teaching_license' ),
			);
		}
		if ( 'wi_license' === $p['cred'] ) {
			return self::area_match( $job, $p, $areas );
		}
		if ( 'private' === $job['sector'] ) {
			if ( $have >= 5 ) {
				return array(
					self::CHECK,
					array( __( 'Private schools don’t need a DPI license; those in the Parental Choice Program accept a bachelor’s degree instead. Confirm with the school.', 'mmi-teach' ) ),
					array(),
				);
			}
			return array(
				self::LATER,
				array( __( 'Private Choice schools need a DPI license or a bachelor’s degree.', 'mmi-teach' ), self::sub_alternative( $p ) ),
				array( 'bachelor_degree' ),
			);
		}
		if ( in_array( $p['cred'], array( 'oos', 'expired' ), true ) ) {
			return array(
				self::STEPS,
				array(
					'oos' === $p['cred']
						? __( 'Your out-of-state license transfers: apply in ELO for the matching Wisconsin license. Many districts interview while it’s processed.', 'mmi-teach' )
						: __( 'Renew your Wisconsin license in ELO; districts can interview you meanwhile.', 'mmi-teach' ),
				),
				array( 'teaching_license' ),
			);
		}
		return array(
			self::CHECK,
			array(
				__( 'You have the degree but no license. A district that wants to hire you can request a One-Year License with Stipulations while you enroll in a teacher-prep program.', 'mmi-teach' ),
			),
			array(),
			array( 'stipulations' ),
		);
	}

	private static function aide( array $job, array $p ): array {
		$have = self::EDU_RANK[ $p['edu'] ];
		if ( $have < 1 ) {
			return array( self::LATER, array( __( 'Aide jobs need a high-school diploma or GED/HSED.', 'mmi-teach' ) ), array( 'high_school' ) );
		}
		$sepa    = in_array( 'sepa', array_column( $job['licenses'], 'key' ), true );
		$reasons = array();
		$gaps    = array();
		$maybe   = array();
		$status  = self::NOW;
		if ( $sepa && ! in_array( $p['cred'], array( 'sepa', 'wi_license', 'long_term' ), true ) ) {
			$status    = self::STEPS;
			$gaps[]    = 'sepa_license';
			$reasons[] = __( 'Public special-education aides need DPI’s Special Education Program Aide license ($110). Apply now; you need it by your first day.', 'mmi-teach' );
		}
		if ( 'private' !== $job['sector'] && $have < 3 ) {
			$maybe[]   = 'title1_para';
			$reasons[] = __( 'If the school is a Title I school, you’ll also need 48 college credits, an associate degree or a passing ParaPro test. Ask when you apply.', 'mmi-teach' );
			if ( self::NOW === $status ) {
				$status = self::CHECK;
			}
		}
		if ( ! $reasons ) {
			$reasons[] = __( 'You meet the usual requirements for aide jobs.', 'mmi-teach' );
		}
		return array( $status, $reasons, $gaps, $maybe );
	}

	/* ---------- per person: one line per role ---------- */

	/**
	 * The fastest route into each role the person wants, judged on a typical job of that role.
	 *
	 * @return array<string, array> role => for_job() result + 'role_label'
	 */
	public static function paths( array $p, ?array $tasks = null ): array {
		$typical = array(
			'substitute' => array( 'role' => 'substitute', 'sub_kind' => 'daily', 'degree' => 'associate', 'degree_basis' => 'rule', 'licenses' => array(), 'sector' => 'public' ),
			'teacher'    => array( 'role' => 'teacher', 'sub_kind' => '', 'degree' => 'bachelor', 'degree_basis' => 'rule', 'licenses' => array(), 'sector' => 'public' ),
			'aide'       => array( 'role' => 'aide', 'sub_kind' => '', 'degree' => 'high_school', 'degree_basis' => 'rule', 'licenses' => array(), 'sector' => 'public' ),
		);
		$out = array();
		foreach ( Classifier::ROLES as $role => $label ) {
			$job = $typical[ $role ] + array( 'employer' => '', 'title' => '', 'degree_note' => '', 'license_basis' => 'none' );
			$r   = self::for_job( $job, $p, $tasks );
			// For the role overview, a teaching license someone already holds counts in any area.
			if ( 'teacher' === $role && 'wi_license' === $p['cred'] ) {
				$r = self::result( self::NOW, array( __( 'Your Wisconsin license qualifies you for jobs in its subject and grade levels.', 'mmi-teach' ) ), array() );
			}
			$out[ $role ] = $r + array(
				'role'       => $role,
				'role_label' => $label,
				'wanted'     => in_array( $role, $p['roles'], true ),
			);
		}
		// Wanted roles first, then the quickest.
		$order = array_flip( array( self::NOW, self::CHECK, self::STEPS, self::LATER ) );
		uasort( $out, static fn( $a, $b ) => ( $b['wanted'] <=> $a['wanted'] ) ?: ( $order[ $a['status'] ] <=> $order[ $b['status'] ] ) );
		return $out;
	}

	/* ---------- helpers ---------- */

	/** Subject-specific license areas the job lists (generic substitute entries left out). */
	private static function job_areas( array $job ): array {
		return array_values( array_filter( $job['licenses'], static fn( $l ) => is_array( $l ) && '' !== ( $l['key'] ?? '' ) && ! in_array( $l['category'] ?? '', array( 'substitute', 'aide' ), true ) && ! in_array( $l['key'], array( 'assignment', 'any_license' ), true ) ) );
	}

	private static function areas_overlap( array $areas, array $p ): bool {
		return (bool) array_intersect( array_column( $areas, 'key' ), $p['areas'] );
	}

	/** A licensed person against a job's license areas. */
	private static function area_match( array $job, array $p, array $areas ): array {
		if ( ! $areas || self::areas_overlap( $areas, $p ) ) {
			return array( self::NOW, array( $areas ? __( 'Your license area matches this job.', 'mmi-teach' ) : __( 'Your license covers this kind of job.', 'mmi-teach' ) ), array() );
		}
		$names = implode( ', ', array_slice( array_map( static fn( $l ) => $l['short'] ?? $l['title'], $areas ), 0, 3 ) );
		if ( ! $p['areas'] || 'inferred' === $job['license_basis'] ) {
			return array( self::CHECK, array( sprintf( __( 'Jobs like this usually need a license in %s. Check that yours covers it.', 'mmi-teach' ), $names ) ), array() );
		}
		return array(
			self::LATER,
			array( sprintf( __( 'This job asks for a license in %s; yours is in a different area. Adding an area means a content test or coursework.', 'mmi-teach' ), $names ) ),
			array(),
		);
	}

	private static function aide_alternative( array $p ): string {
		return self::EDU_RANK[ $p['edu'] ] >= 1
			? __( 'Aide and paraprofessional jobs need only a high-school diploma; you can apply to those today.', 'mmi-teach' )
			: __( 'Aide jobs open up with a high-school diploma or GED/HSED.', 'mmi-teach' );
	}

	private static function sub_alternative( array $p ): string {
		return self::EDU_RANK[ $p['edu'] ] >= 4 || ( $p['epp'] && '20' === $p['age'] )
			? __( 'Meanwhile, substitute teaching is open to you after a short course and DPI’s permit.', 'mmi-teach' )
			: self::aide_alternative( $p );
	}

	private static function cred_name( string $cred ): string {
		return strtolower( Profile::CRED[ $cred ] ?? $cred );
	}

	/**
	 * Packs an answer with the combined time (critical path through 'after') and cost of its gaps.
	 */
	private static function result( string $status, array $reasons, array $gaps, array $maybe = array() ): array {
		$finish = array();
		$cost   = array( 0, 0 );
		$calc   = static function ( string $id ) use ( &$calc, &$finish, $gaps ): array {
			if ( isset( $finish[ $id ] ) ) {
				return $finish[ $id ];
			}
			$item  = Rules::item( $id ) ?? array();
			$start = array( 0, 0 );
			foreach ( array_intersect( (array) ( $item['after'] ?? array() ), $gaps ) as $dep ) {
				$f     = $calc( $dep );
				$start = array( max( $start[0], $f[0] ), max( $start[1], $f[1] ) );
			}
			return $finish[ $id ] = array( $start[0] + (int) ( $item['time']['min'] ?? 0 ), $start[1] + (int) ( $item['time']['max'] ?? 0 ) );
		};
		$days = array( 0, 0 );
		foreach ( $gaps as $id ) {
			$f    = $calc( $id );
			$days = array( max( $days[0], $f[0] ), max( $days[1], $f[1] ) );
			$item = Rules::item( $id ) ?? array();
			if ( 'you' === ( $item['cost']['payer'] ?? '' ) || 'varies' === ( $item['cost']['payer'] ?? '' ) ) {
				$cost[0] += (int) ( $item['cost']['min'] ?? 0 );
				$cost[1] += (int) ( $item['cost']['max'] ?? 0 );
			}
		}
		$blocks_apply = array_filter( $gaps, static fn( $id ) => 'apply' === ( Rules::item( $id )['blocks'] ?? '' ) );
		$can_apply    = in_array( $status, array( self::NOW, self::CHECK ), true ) || ( self::STEPS === $status && ! $blocks_apply );

		$summary = self::LABELS[ $status ] ?? '';
		if ( $gaps && self::NOW !== $status ) {
			$summary .= ': ' . sprintf(
				/* translators: 1: number of steps, 2: time span, 3: cost */
				_n( '%1$d step, %2$s, %3$s', '%1$d steps, %2$s, %3$s', count( $gaps ), 'mmi-teach' ),
				count( $gaps ),
				Rules::span_label( $days[0], $days[1] ),
				Rules::cost_label( $cost[0], $cost[1] )
			);
		}
		return array(
			'status'    => $status,
			'label'     => self::LABELS[ $status ] ?? '',
			'can_apply' => $can_apply,
			'reasons'   => array_values( array_filter( $reasons ) ),
			'gaps'      => array_values( array_unique( $gaps ) ),
			'maybe'     => array_values( array_unique( $maybe ) ),
			'days'      => $days,
			'cost'      => $cost,
			'summary'   => $summary,
		);
	}
}
