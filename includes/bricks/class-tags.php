<?php
namespace MMI_Teach\Bricks;

use MMI_Teach\Applications;
use MMI_Teach\Checklist;
use MMI_Teach\Classifier;
use MMI_Teach\Dpi_Lookup;
use MMI_Teach\Eligibility;
use MMI_Teach\Front;
use MMI_Teach\Jobs;
use MMI_Teach\Pages;
use MMI_Teach\Profile;
use MMI_Teach\Refresher;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Bricks dynamic data tags: the visitor's answers and progress ({mt_profile_*}, {mt_progress}),
 * the current job ({mt_job_*}, in the job template and in job loops) and the current row of an
 * MMI Teach query loop ({mt_row_<field>}). Text values are escaped; *_html values are built
 * escaped; *_url values are URLs.
 */
final class Tags {

	public const GROUP = 'MMI Teach';

	/** Global and job tags: name => label. Row tags are any {mt_row_<field>}. */
	public const TAGS = array(
		'mt_has_profile'        => 'Has answered the check (1 or empty)',
		'mt_profile_edu'        => 'Answer: education (key)',
		'mt_profile_cred'       => 'Answer: credential (key)',
		'mt_profile_areas'      => 'Answer: license areas (keys)',
		'mt_profile_epp'        => 'Answer: in a teacher-prep program (yes/no)',
		'mt_profile_age'        => 'Answer: age band (key)',
		'mt_profile_outside_wi' => 'Answer: lived outside Wisconsin (yes/no)',
		'mt_profile_roles'      => 'Answer: roles wanted (keys)',
		'mt_profile_summary'    => 'Answers in one line',
		'mt_dpi_state'          => 'DPI lookup: record, matches or empty',
		'mt_dpi_summary'        => 'DPI lookup: what the record means, in one line',
		'mt_dpi_notes_html'     => 'DPI lookup: notes (list)',
		'mt_dpi_more'           => 'DPI lookup: "and N older licenses"',
		'mt_dpi_url'            => 'DPI lookup: the record on DPI’s site',
		'mt_dpi_match_note'     => 'DPI lookup: how many people matched',
		'mt_dpi_forget_url'     => 'DPI lookup: search again',
		'mt_dpi_error'          => 'DPI lookup: error after picking a match',
		'mt_dpi_prefill'        => 'DPI lookup: record is filling in the form (1 or empty)',
		'mt_dpi_lookup_url'     => 'DPI’s own license lookup page',
		'mt_persona'            => 'Sample person in use (key or empty)',
		'mt_persona_name'       => 'Sample person in use (name)',
		'mt_progress'           => 'Checklist progress ("4 of 17 done")',
		'mt_progress_pct'       => 'Checklist progress (percent)',
		'mt_fit_on'             => '"Jobs I can apply to now" is on (1 or empty)',
		'mt_apply_now_count'    => 'Open jobs I can apply to now (count)',
		'mt_open_count'         => 'Open jobs (count)',
		'mt_aide_count'         => 'Open aide jobs (count)',
		'mt_sub_count'          => 'Open substitute jobs (count)',
		'mt_updated'            => 'When the job list was last refreshed',
		'mt_user_name'          => 'Signed-in applicant’s first name',
		'mt_logged_in'          => 'Signed in (1 or empty)',
		'mt_logout_url'         => 'Sign-out link',
		'mt_is_applicant'       => 'Signed in as an applicant (1 or empty)',
		'mt_redirect_to'        => 'Safe redirect target from ?redirect_to',
		'mt_job_param'          => 'Job ID from ?job',
		'mt_checklist_job'      => 'Checklist scope: job title (or empty)',
		'mt_checklist_job_url'  => 'Checklist scope: job URL',
		'mt_jobs_url'           => 'Jobs page URL',
		'mt_check_url'          => 'Check page URL',
		'mt_checklist_url'      => 'Checklist page URL',
		'mt_job_id'             => 'Job: post ID',
		'mt_job_pay'            => 'Job: pay with unit, or "Pay not posted"',
		'mt_job_employer'       => 'Job: employer',
		'mt_job_school'         => 'Job: school or location',
		'mt_job_county'         => 'Job: county',
		'mt_job_type'           => 'Job: job type',
		'mt_job_role'           => 'Job: role',
		'mt_job_posted'         => 'Job: posted date',
		'mt_job_closes'         => 'Job: closing date (or empty)',
		'mt_job_start'          => 'Job: start (or empty)',
		'mt_job_credential'     => 'Job: what it asks for, in a few words',
		'mt_job_fit'            => 'Job: your fit (label)',
		'mt_job_fit_state'      => 'Job: your fit (now/check/steps/later/empty)',
		'mt_job_fit_summary'    => 'Job: your fit (summary)',
		'mt_job_fit_reasons_html' => 'Job: why (list)',
		'mt_job_apply_url'      => 'Job: employer’s application URL',
		'mt_job_url'            => 'Job: original posting URL',
		'mt_job_source'         => 'Job: board it was listed on',
		'mt_job_also_html'      => 'Job: also listed on (links)',
		'mt_job_agency'         => 'Job: who employs the sub (sentence)',
		'mt_job_open'           => 'Job: open (1 or empty)',
		'mt_job_closed_on'      => 'Job: closed on (date)',
		'mt_job_checklist_url'  => 'Job: checklist for this job',
		'mt_job_join_url'       => 'Job: create account, then back here',
		'mt_job_signin_url'     => 'Job: sign in, then back here',
		'mt_job_applied'        => 'Job: your application status (or empty)',
		'mt_job_applied_on'     => 'Job: when you saved it',
		'mt_job_can_apply'      => 'Job: you can apply now (1 or empty)',
		'mt_job_agency_steps'   => 'Job: what the agency’s process involves',
		'mt_job_display_id'     => 'Job: number on the board',
	);

	public static function register(): void {
		add_filter( 'bricks/dynamic_tags_list', array( self::class, 'tags_list' ) );
		add_filter( 'bricks/dynamic_data/render_tag', array( self::class, 'render_tag' ), 20, 3 );
		add_filter( 'bricks/dynamic_data/render_content', array( self::class, 'render_content' ), 20, 3 );
		add_filter( 'bricks/frontend/render_data', array( self::class, 'render_content' ), 20, 2 );
	}

	public static function tags_list( array $tags ): array {
		foreach ( self::TAGS as $name => $label ) {
			$tags[] = array(
				'name'  => '{' . $name . '}',
				'label' => $label,
				'group' => self::GROUP,
			);
		}
		$tags[] = array(
			'name'  => '{mt_row_title}',
			'label' => 'Loop row: any field ({mt_row_<field>})',
			'group' => self::GROUP,
		);
		return $tags;
	}

	public static function render_tag( $tag, $post, $context = 'text' ) {
		if ( ! is_string( $tag ) ) {
			return $tag;
		}
		$name = trim( $tag, '{} ' );
		if ( ! str_starts_with( $name, 'mt_' ) ) {
			return $tag;
		}
		$value = self::value( $name, $post );
		return null === $value ? $tag : $value;
	}

	public static function render_content( $content, $post, $context = 'text' ) {
		if ( ! is_string( $content ) || ! str_contains( $content, '{mt_' ) ) {
			return $content;
		}
		return preg_replace_callback(
			'/\{(mt_[a-z0-9_]+)\}/',
			static function ( $m ) use ( $post ) {
				$value = self::value( $m[1], $post );
				return null === $value ? $m[0] : $value;
			},
			$content
		);
	}

	/** A tag's value, or null if the name isn't one of ours. */
	public static function value( string $name, $post = null ): ?string {
		if ( str_starts_with( $name, 'mt_row_' ) ) {
			$row   = Queries::row();
			$field = substr( $name, 7 );
			if ( ! $row || ! array_key_exists( $field, $row ) ) {
				return '';
			}
			$v = (string) $row[ $field ];
			if ( str_ends_with( $field, '_html' ) ) {
				return $v;
			}
			return str_ends_with( $field, '_url' ) ? esc_url_raw( $v ) : esc_html( $v );
		}
		if ( str_starts_with( $name, 'mt_job_' ) ) {
			return self::job_value( substr( $name, 7 ), $post );
		}
		if ( ! isset( self::TAGS[ $name ] ) ) {
			return null;
		}
		$p   = Profile::current();
		$dpi = str_starts_with( $name, 'mt_profile_' ) ? Dpi_Lookup::prefill() : null;
		switch ( $name ) {
			case 'mt_has_profile':
				return $p ? '1' : '';
			case 'mt_profile_edu':
				// A teaching license implies a bachelor's degree; never lower an answer already given.
				$edu = $p ? $p['edu'] : '';
				if ( $dpi && $dpi['edu_min'] && array_search( $edu, array_keys( Profile::EDU ), true ) < array_search( $dpi['edu_min'], array_keys( Profile::EDU ), true ) ) {
					$edu = $dpi['edu_min'];
				}
				return $edu;
			case 'mt_profile_cred':
				return $dpi ? $dpi['cred'] : ( $p ? $p['cred'] : '' );
			case 'mt_profile_areas':
				return $dpi ? implode( ',', $dpi['areas'] ) : ( $p ? implode( ',', $p['areas'] ) : '' );
			case 'mt_dpi_state':
				return Dpi_Lookup::record() ? 'record' : ( Dpi_Lookup::matches() ? 'matches' : '' );
			case 'mt_dpi_summary':
				$rec = Dpi_Lookup::record();
				if ( ! $rec ) {
					return '';
				}
				$areas = array_intersect_key( Profile::areas(), array_flip( $rec['areas'] ) );
				/* translators: 1: file number, 2: credential answer */
				$line = sprintf( __( 'File number %1$d. For the check this means: %2$s', 'mmi-teach' ), $rec['id'], 'none' === $rec['cred'] ? __( 'no current teaching license or permit', 'mmi-teach' ) : Profile::CRED[ $rec['cred'] ] );
				return esc_html( $line . ( $areas ? ' · ' . implode( ', ', $areas ) : '' ) . '.' );
			case 'mt_dpi_notes_html':
				$rec = Dpi_Lookup::record();
				return $rec && $rec['notes'] ? '<ul>' . implode( '', array_map( static fn( $n ) => '<li>' . esc_html( $n ) . '</li>', $rec['notes'] ) ) . '</ul>' : '';
			case 'mt_dpi_more':
				$rec = Dpi_Lookup::record();
				/* translators: %d: number of licenses */
				return $rec && $rec['more'] ? esc_html( sprintf( _n( 'And %d older license on DPI’s page.', 'And %d older licenses on DPI’s page.', $rec['more'], 'mmi-teach' ), $rec['more'] ) ) : '';
			case 'mt_dpi_url':
				$rec = Dpi_Lookup::record();
				return $rec ? esc_url_raw( Dpi_Lookup::dpi_url( $rec['id'] ) ) : esc_url_raw( Dpi_Lookup::LOOKUP_URL );
			case 'mt_dpi_match_note':
				$m = Dpi_Lookup::matches();
				if ( ! $m ) {
					return '';
				}
				$n = count( $m['m'] );
				/* translators: 1: number, 2: the name searched */
				$line = sprintf( _n( '%1$d person matches “%2$s”.', '%1$d people match “%2$s”.', $n, 'mmi-teach' ), $n, $m['q'] );
				return esc_html( $line . ( $n >= Dpi_Lookup::MAX_MATCHES ? ' ' . __( 'That’s the most DPI shows at once: add your full first name to narrow it.', 'mmi-teach' ) : '' ) );
			case 'mt_dpi_forget_url':
				return esc_url_raw( add_query_arg( array( 'mt_dpi_forget' => 1, '_mtn' => wp_create_nonce( 'mt_dpi' ) ), Pages::url( 'check' ) ) );
			case 'mt_dpi_error':
				$code = isset( $_GET['dpi_error'] ) ? sanitize_key( wp_unslash( $_GET['dpi_error'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only.
				return array(
					'limit' => esc_html__( 'That’s a lot of lookups in a short time. Try again in 15 minutes, or search on DPI’s own site.', 'mmi-teach' ),
					'down'  => esc_html__( 'DPI’s lookup isn’t answering right now. Try again in a few minutes, or search on DPI’s own site.', 'mmi-teach' ),
				)[ $code ] ?? '';
			case 'mt_dpi_prefill':
				return Dpi_Lookup::prefill() ? '1' : '';
			case 'mt_dpi_lookup_url':
				return esc_url_raw( Dpi_Lookup::LOOKUP_URL );
			case 'mt_profile_epp':
				return $p ? ( $p['epp'] ? 'yes' : 'no' ) : '';
			case 'mt_profile_age':
				return $p ? $p['age'] : '';
			case 'mt_profile_outside_wi':
				return $p ? ( $p['outside_wi'] ? 'yes' : 'no' ) : '';
			case 'mt_profile_roles':
				return $p ? implode( ',', $p['roles'] ) : 'teacher,substitute,aide';
			case 'mt_profile_summary':
				if ( ! $p ) {
					return '';
				}
				$parts = array( Profile::EDU[ $p['edu'] ], 'none' === $p['cred'] ? __( 'no DPI license', 'mmi-teach' ) : Profile::CRED[ $p['cred'] ] );
				if ( $p['epp'] ) {
					$parts[] = __( 'in a teacher-prep program', 'mmi-teach' );
				}
				return esc_html( implode( ' · ', $parts ) );
			case 'mt_persona':
				return Profile::persona();
			case 'mt_persona_name':
				$key = Profile::persona();
				return $key ? esc_html( Profile::personas()[ $key ]['name'] ?? '' ) : '';
			case 'mt_progress':
			case 'mt_progress_pct':
				[ $done, $total ] = Checklist::progress( Checklist::rows( $p, Queries::context_job() ) );
				return 'mt_progress' === $name
					? esc_html( sprintf( __( '%1$d of %2$d done', 'mmi-teach' ), $done, $total ) )
					: (string) ( $total ? (int) round( 100 * $done / $total ) : 0 );
			case 'mt_fit_on':
				return Front::fit_on() ? '1' : '';
			case 'mt_apply_now_count':
				return $p ? (string) count( Front::applicable_ids() ) : '';
			case 'mt_open_count':
				return (string) count( Jobs::open_ids() );
			case 'mt_aide_count':
			case 'mt_sub_count':
				return (string) self::count_role( 'mt_aide_count' === $name ? 'aide' : 'substitute' );
			case 'mt_updated':
				$status = get_option( Refresher::OPTION_STATUS, array() );
				$last   = (string) ( $status['last_run'] ?? '' );
				return $last ? esc_html( sprintf( __( 'Updated %s', 'mmi-teach' ), human_time_diff( (int) strtotime( $last . ' UTC' ) ) . ' ' . __( 'ago', 'mmi-teach' ) ) ) : '';
			case 'mt_logout_url':
				return esc_url_raw( wp_logout_url( home_url( '/' ) ) );
			case 'mt_logged_in':
				return is_user_logged_in() ? '1' : '';
			case 'mt_is_applicant':
				return \MMI_Teach\Accounts::is_applicant() ? '1' : '';
			case 'mt_user_name':
				$u = wp_get_current_user();
				return $u->exists() ? esc_html( $u->first_name ?: $u->display_name ) : '';
			case 'mt_redirect_to':
				// phpcs:ignore WordPress.Security.NonceVerification.Recommended
				$to = isset( $_GET['redirect_to'] ) ? wp_validate_redirect( esc_url_raw( wp_unslash( $_GET['redirect_to'] ) ), '' ) : '';
				return esc_url_raw( $to ?: Pages::url( 'my-applications' ) );
			case 'mt_job_param':
				// phpcs:ignore WordPress.Security.NonceVerification.Recommended
				$id = isset( $_GET['job'] ) ? absint( $_GET['job'] ) : 0;
				return $id && Jobs::job( $id ) ? (string) $id : '';
			case 'mt_checklist_job':
				$job = Queries::context_job();
				return $job ? esc_html( get_the_title( $job['id'] ) . ' — ' . $job['employer'] ) : '';
			case 'mt_checklist_job_url':
				$job = Queries::context_job();
				return $job ? esc_url_raw( (string) get_permalink( $job['id'] ) ) : '';
			case 'mt_jobs_url':
				return esc_url_raw( Pages::url( 'jobs' ) );
			case 'mt_check_url':
				return esc_url_raw( Pages::url( 'check' ) );
			case 'mt_checklist_url':
				return esc_url_raw( Pages::url( 'checklist' ) );
		}
		return '';
	}

	private static function job_value( string $field, $post ): string {
		$id = $post instanceof \WP_Post ? (int) $post->ID : (int) get_the_ID();
		if ( Jobs::POST_TYPE !== get_post_type( $id ) ) {
			$ctx = Queries::context_job();
			$id  = $ctx ? (int) $ctx['id'] : 0;
		}
		if ( ! $id ) {
			return '';
		}
		$meta = static fn( string $k ) => (string) get_post_meta( $id, $k, true );
		$date = static fn( string $d ) => $d ? wp_date( 'M j, Y', (int) strtotime( $d . ' 12:00' ) ) : '';
		switch ( $field ) {
			case 'id':
				return (string) $id;
			case 'pay':
				return esc_html( $meta( '_mt_pay_label' ) ?: __( 'Pay not posted', 'mmi-teach' ) );
			case 'employer':
				return esc_html( $meta( '_mt_employer' ) );
			case 'school':
				$school = $meta( '_mt_school' );
				return $school && $school !== $meta( '_mt_employer' ) ? esc_html( $school ) : '';
			case 'county':
				return esc_html( $meta( '_mt_county' ) ? $meta( '_mt_county' ) . ' County' : '' );
			case 'type':
				return esc_html( Jobs::JOB_TYPES[ $meta( '_mt_sub_kind' ) ] ?? '' );
			case 'role':
				return esc_html( Classifier::ROLES[ $meta( '_mt_role' ) ] ?? '' );
			case 'posted':
				return esc_html( sprintf( __( 'Posted %s', 'mmi-teach' ), $date( $meta( '_mt_posted' ) ) ) );
			case 'closes':
				$c = $meta( '_mt_closes' );
				return $c ? esc_html( sprintf( __( 'Closes %s', 'mmi-teach' ), $date( $c ) ) ) : '';
			case 'start':
				$s = $meta( '_mt_start' );
				return $s ? esc_html( sprintf( __( 'Starts %s', 'mmi-teach' ), $s ) ) : '';
			case 'credential':
				return esc_html( $meta( '_mt_credential' ) );
			case 'fit':
			case 'fit_state':
			case 'fit_summary':
			case 'fit_reasons_html':
			case 'can_apply':
				$p = Profile::current();
				if ( ! $p ) {
					return 'fit_reasons_html' === $field ? '' : ( 'fit_summary' === $field ? esc_html__( 'Answer six questions to see whether you qualify.', 'mmi-teach' ) : '' );
				}
				$fit = self::fit( $id );
				switch ( $field ) {
					case 'fit':
						return esc_html( $fit['label'] );
					case 'fit_state':
						return $fit['status'];
					case 'fit_summary':
						return esc_html( $fit['summary'] );
					case 'can_apply':
						return $fit['can_apply'] ? '1' : '';
				}
				return '<ul>' . implode( '', array_map( static fn( $r ) => '<li>' . esc_html( $r ) . '</li>', $fit['reasons'] ) ) . '</ul>';
			case 'apply_url':
				return esc_url_raw( $meta( '_mt_apply_url' ) );
			case 'url':
				return esc_url_raw( $meta( '_mt_url' ) );
			case 'source':
				return esc_html( $meta( '_mt_source' ) );
			case 'also_html':
				$also = (array) get_post_meta( $id, '_mt_also', true );
				return $also ? esc_html__( 'Also listed on', 'mmi-teach' ) . ' ' . implode( ', ', array_map( static fn( $a ) => '<a href="' . esc_url( $a['url'] ) . '" target="_blank" rel="noopener">' . esc_html( $a['label'] ) . '</a>', $also ) ) : '';
			case 'agency':
				return esc_html( self::agency_sentence( $id ) );
			case 'agency_steps':
				return esc_html( Checklist::agency_note( Jobs::job( $id ) ) );
			case 'open':
				return '1' === $meta( '_mt_open' ) ? '1' : '';
			case 'closed_on':
				$c = $meta( '_mt_closed_at' );
				return $c ? esc_html( wp_date( 'M j, Y', (int) strtotime( $c . ' UTC' ) ) ) : '';
			case 'checklist_url':
				return esc_url_raw( add_query_arg( 'job', $id, Pages::url( 'checklist' ) ) );
			case 'join_url':
				return esc_url_raw( add_query_arg( array( 'redirect_to' => rawurlencode( (string) get_permalink( $id ) . '#apply' ), 'job' => $id ), Pages::url( 'join' ) ) );
			case 'signin_url':
				return esc_url_raw( add_query_arg( array( 'redirect_to' => rawurlencode( (string) get_permalink( $id ) . '#apply' ), 'job' => $id ), Pages::url( 'sign-in' ) ) );
			case 'applied':
			case 'applied_on':
				$app = Applications::for_job( get_current_user_id(), $id );
				$a   = $app ? Applications::get( $app ) : null;
				if ( ! $a || 'started' === $a['status'] ) {
					return '';
				}
				return 'applied' === $field ? esc_html( $a['status_label'] ) : esc_html( wp_date( get_option( 'date_format' ), $a['saved'] ) );
			case 'display_id':
				return esc_html( $meta( '_mt_display_id' ) );
		}
		return '';
	}

	/** Eligibility for a job, computed once per request. */
	private static function fit( int $id ): array {
		static $cache = array();
		if ( ! isset( $cache[ $id ] ) ) {
			$job          = Jobs::job( $id );
			$cache[ $id ] = $job ? Eligibility::for_job( $job, Profile::current(), Profile::tasks() ) : array( 'status' => '', 'label' => '', 'summary' => '', 'reasons' => array(), 'can_apply' => false );
		}
		return $cache[ $id ];
	}

	private static function count_role( string $role ): int {
		$n = 0;
		foreach ( Jobs::open_ids() as $id ) {
			$n += $role === get_post_meta( $id, '_mt_role', true ) ? 1 : 0;
		}
		return $n;
	}

	/** Who employs the person in this job, with how we know. */
	private static function agency_sentence( int $id ): string {
		$agency = (string) get_post_meta( $id, '_mt_agency', true );
		$basis  = (string) get_post_meta( $id, '_mt_agency_basis', true );
		$role   = (string) get_post_meta( $id, '_mt_role', true );
		if ( '' === $agency || 'unknown' === $agency ) {
			return 'substitute' === $role ? __( 'We haven’t confirmed whether this employer hires substitutes through an agency.', 'mmi-teach' ) : '';
		}
		if ( 'direct' === $agency ) {
			return 'substitute' === $role ? __( 'The employer hires its own substitutes for this job.', 'mmi-teach' ) : '';
		}
		$name = \MMI_Teach\Agencies::name( $agency );
		$how  = array(
			'posting'  => __( 'The posting says', 'mmi-teach' ),
			'employer' => __( 'The employer confirms', 'mmi-teach' ),
			'likely'   => __( 'Most likely', 'mmi-teach' ),
		)[ $basis ] ?? '';
		return trim( sprintf( __( '%1$s: you’d be employed through %2$s, which runs its own application and onboarding.', 'mmi-teach' ), $how, $name ) );
	}
}
