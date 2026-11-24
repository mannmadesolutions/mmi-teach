<?php
namespace MMI_Teach;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The site's emails, sent with wp_mail() (the server's own mail; no paid service). Mail to a
 * ".invalid" address (test accounts) is recorded in an option instead of being sent.
 */
final class Mail {

	public const OPTION_SINK = 'mmi_teach_mail_sink';

	public static function register(): void {
		add_filter( 'pre_wp_mail', array( self::class, 'sink' ), 10, 2 );
	}

	/** Short-circuits wp_mail() for test addresses; keeps the last 50 for checks. */
	public static function sink( $short, array $atts ) {
		$to = array_map( 'strval', (array) $atts['to'] );
		if ( ! $to || array_filter( $to, static fn( $a ) => ! preg_match( '/\.invalid>?$/i', trim( $a ) ) ) ) {
			return $short;
		}
		$log   = get_option( self::OPTION_SINK, array() );
		$log   = is_array( $log ) ? $log : array();
		$log[] = array(
			'at'      => gmdate( 'c' ),
			'to'      => $to,
			'subject' => (string) $atts['subject'],
			'message' => mb_substr( (string) $atts['message'], 0, 4000 ),
		);
		update_option( self::OPTION_SINK, array_slice( $log, -50 ), false );
		return true;
	}

	/** To the applicant and the site's staff address, once, when an application is saved. */
	public static function application_saved( int $app_id ): void {
		$app = Applications::get( $app_id );
		if ( ! $app ) {
			return;
		}
		$user = get_userdata( $app['user'] );
		if ( ! $user ) {
			return;
		}
		$job  = $app['job_title'] . ' — ' . $app['employer'];
		$left = count( $app['readiness'] );

		$lines = array(
			sprintf( __( 'Hi %s,', 'mmi-teach' ), $user->first_name ?: $user->display_name ),
			'',
			sprintf( __( 'Your application for %s is saved on MannMade Teaching.', 'mmi-teach' ), $job ),
			'',
			__( 'Next: finish it on the employer’s own site. This demo does not send applications to employers.', 'mmi-teach' ),
			$app['apply_url'],
			'',
			$left
				? sprintf( _n( 'Your checklist for this job has %d step left before your first day:', 'Your checklist for this job has %d steps left before your first day:', $left, 'mmi-teach' ), $left )
				: __( 'Your checklist shows nothing missing for this job.', 'mmi-teach' ),
			add_query_arg( 'job', $app['job'], Pages::url( 'checklist' ) ),
			'',
			__( 'Track every application here:', 'mmi-teach' ),
			Pages::url( 'my-applications' ),
			'',
			__( 'Never email ID documents, your Social Security number, bank details or medical records to anyone who asks for them by email. Show ID in person and enter the rest only in an employer’s own secure system.', 'mmi-teach' ),
		);
		wp_mail( $user->user_email, sprintf( __( 'Saved: %s', 'mmi-teach' ), $job ), implode( "\n", $lines ) );

		$staff = (string) apply_filters( 'mmi_teach_staff_email', get_option( 'admin_email' ) );
		if ( $staff && ! preg_match( '/\.invalid$/i', $user->user_email ) ) {
			wp_mail(
				$staff,
				sprintf( __( '[Teaching demo] New application #%1$d: %2$s', 'mmi-teach' ), $app_id, $job ),
				implode(
					"\n",
					array(
						sprintf( __( 'Applicant: %1$s (user %2$d)', 'mmi-teach' ), $user->display_name, $user->ID ),
						sprintf( __( 'Job: %s', 'mmi-teach' ), $job ),
						sprintf( __( 'Steps left on their checklist: %d', 'mmi-teach' ), $left ),
						admin_url( 'post.php?post=' . $app_id . '&action=edit' ),
					)
				)
			);
		}
	}
}
