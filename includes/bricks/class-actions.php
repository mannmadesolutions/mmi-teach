<?php
namespace MMI_Teach\Bricks;

use MMI_Teach\Accounts;
use MMI_Teach\Applications;
use MMI_Teach\Jobs;
use MMI_Teach\Mail;
use MMI_Teach\Pages;
use MMI_Teach\Profile;
use MMI_Teach\Rules;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Bricks form actions. Each is a named action (bricks/form/action/{name}) picked in the form's
 * Actions setting. Every value is checked against a fixed list; IDs that arrive from the browser
 * (checklist item, application) are validated against the current visitor before use.
 */
final class Actions {

	/** action => label shown in the form's Actions setting. */
	public const ACTIONS = array(
		'mt_profile'    => 'MMI Teach: save eligibility answers',
		'mt_task'       => 'MMI Teach: update a checklist item',
		'mt_apply'      => 'MMI Teach: save an application',
		'mt_app_status' => 'MMI Teach: update my application status',
		'mt_delete_me'  => 'MMI Teach: delete my account and data',
		'mt_redirect'   => 'MMI Teach: return to the job after joining or signing in',
	);

	/** Field IDs the builder gives each form (Build\Site uses the same). */
	public const F = array(
		'edu'      => 'mtedu0',
		'cred'     => 'mtcred',
		'areas'    => 'mtarea',
		'epp'      => 'mtepp0',
		'age'      => 'mtage0',
		'outside'  => 'mtoutw',
		'roles'    => 'mtrole',
		'item'     => 'mttask',
		'state'    => 'mtstat',
		'date'     => 'mtdate',
		'expiry'   => 'mtexpy',
		'where'    => 'mtwher',
		'agree'    => 'mtagre',
		'avail'    => 'mtavai',
		'start'    => 'mtstrt',
		'app'      => 'mtapid',
		'status'   => 'mtasta',
		'confirm'  => 'mtdcfm',
		'password' => 'mtdpwd',
		'to'       => 'mtrdto',
		'job'      => 'mtjobi',
	);

	public static function register(): void {
		foreach ( array_keys( self::ACTIONS ) as $action ) {
			add_action( 'bricks/form/action/' . $action, array( self::class, $action ) );
		}
		add_filter( 'bricks/elements/form/controls', array( self::class, 'controls' ) );
	}

	/** Lists the actions in the form element's Actions dropdown, so they can be picked in the builder. */
	public static function controls( array $controls ): array {
		if ( isset( $controls['actions']['options'] ) && is_array( $controls['actions']['options'] ) ) {
			$controls['actions']['options'] += self::ACTIONS;
		}
		return $controls;
	}

	/* ---------- actions ---------- */

	public static function mt_profile( $form ): void {
		$f       = $form->get_fields();
		$profile = Profile::save(
			array(
				'edu'        => self::one( $f, 'edu' ),
				'cred'       => self::one( $f, 'cred' ),
				'areas'      => self::many( $f, 'areas' ),
				'epp'        => 'yes' === self::one( $f, 'epp' ),
				'age'        => self::one( $f, 'age' ),
				'outside_wi' => 'yes' === self::one( $f, 'outside' ),
				'roles'      => self::many( $f, 'roles' ),
			)
		);
		$form->set_result(
			array(
				'action'     => 'mt_profile',
				'type'       => 'redirect',
				// A fresh query string forces a reload: a redirect to the same page plus only a #hash
				// just scrolls, and "Your options" (drawn server-side) would never appear.
				'redirectTo' => add_query_arg( 'answers', base_convert( (string) time(), 10, 36 ), Pages::url( 'check' ) ) . '#your-options',
				'message'    => __( 'Saved.', 'mmi-teach' ),
			)
		);
		unset( $profile );
	}

	public static function mt_task( $form ): void {
		if ( Profile::persona() ) {
			self::error( $form, 'mt_task', __( 'You’re viewing a sample person. Choose “Use my own answers” at the top to keep your own checklist.', 'mmi-teach' ) );
			return;
		}
		$f     = $form->get_fields();
		$item  = self::one( $f, 'item' );
		$state = self::one( $f, 'state' );
		if ( ! Rules::item( $item ) || ! in_array( $state, array( 'done', 'na', 'todo' ), true ) ) {
			self::error( $form, 'mt_task', __( 'That checklist item couldn’t be updated. Reload the page and try again.', 'mmi-teach' ) );
			return;
		}
		$date   = self::date( self::one( $f, 'date' ) );
		$expiry = self::date( self::one( $f, 'expiry' ) );
		if ( 'done' === $state && '' === $date ) {
			$date = wp_date( 'Y-m-d' );
		}
		Profile::set_task( $item, $state, 'done' === $state ? $date : '', 'done' === $state ? $expiry : '', 'done' === $state ? self::one( $f, 'where' ) : '' );
		$form->set_result(
			array(
				'action'      => 'mt_task',
				'type'        => 'success',
				'message'     => __( 'Saved.', 'mmi-teach' ),
				'refreshPage' => true,
			)
		);
	}

	public static function mt_apply( $form ): void {
		$user = get_current_user_id();
		$job  = (int) $form->get_post_id();
		if ( ! $user ) {
			self::error( $form, 'mt_apply', __( 'Sign in or create an account to save an application.', 'mmi-teach' ) );
			return;
		}
		if ( ! Accounts::is_applicant() ) {
			self::error( $form, 'mt_apply', __( 'Staff accounts can’t apply. Sign in with an applicant account to try this.', 'mmi-teach' ) );
			return;
		}
		if ( ! Jobs::job( $job ) ) {
			self::error( $form, 'mt_apply', __( 'This job couldn’t be found. Go back to the job list and try again.', 'mmi-teach' ) );
			return;
		}
		$f = $form->get_fields();
		if ( ! self::many( $f, 'agree' ) ) {
			self::error( $form, 'mt_apply', __( 'Tick the box to confirm you understand this demo doesn’t send your application to the employer.', 'mmi-teach' ) );
			return;
		}
		$new = ! Applications::for_job( $user, $job ) || 'started' === Applications::status( Applications::for_job( $user, $job ) );
		$id  = Applications::save( $user, $job, self::many( $f, 'avail' ), self::one( $f, 'start' ) );
		if ( is_wp_error( $id ) ) {
			self::error( $form, 'mt_apply', $id->get_error_message() );
			return;
		}
		if ( $new ) {
			Mail::application_saved( $id );
		}
		$form->set_result(
			array(
				'action'     => 'mt_apply',
				'type'       => 'redirect',
				'redirectTo' => get_permalink( $job ) . '#apply',
				'message'    => __( 'Saved.', 'mmi-teach' ),
			)
		);
	}

	public static function mt_app_status( $form ): void {
		$f      = $form->get_fields();
		$id     = absint( self::one( $f, 'app' ) );
		$status = self::one( $f, 'status' );
		$app    = $id ? Applications::get( $id ) : null;
		if ( ! $app || $app['user'] !== get_current_user_id() || ! in_array( $status, Applications::APPLICANT_STATUSES, true ) ) {
			self::error( $form, 'mt_app_status', __( 'That application couldn’t be updated.', 'mmi-teach' ) );
			return;
		}
		Applications::set_status( $id, $status, 'applicant' );
		$form->set_result(
			array(
				'action'      => 'mt_app_status',
				'type'        => 'success',
				'message'     => __( 'Updated.', 'mmi-teach' ),
				'refreshPage' => true,
			)
		);
	}

	public static function mt_delete_me( $form ): void {
		$user = wp_get_current_user();
		$f    = $form->get_fields();
		if ( ! $user->exists() || ! Accounts::is_applicant( $user ) ) {
			self::error( $form, 'mt_delete_me', __( 'Only applicant accounts can be deleted here.', 'mmi-teach' ) );
			return;
		}
		$password = (string) ( $f[ 'form-field-' . self::F['password'] ] ?? '' );
		if ( ! self::many( $f, 'confirm' ) || ! wp_check_password( $password, $user->user_pass, $user->ID ) ) {
			self::error( $form, 'mt_delete_me', __( 'Tick the box and enter your password to delete your account.', 'mmi-teach' ) );
			return;
		}
		$id = $user->ID;
		wp_logout();
		Accounts::delete( $id );
		$form->set_result(
			array(
				'action'     => 'mt_delete_me',
				'type'       => 'redirect',
				'redirectTo' => add_query_arg( 'deleted', '1', home_url( '/' ) ),
				'message'    => __( 'Your account and everything stored about it are deleted.', 'mmi-teach' ),
			)
		);
	}

	/** After the registration or login action: note a started application, then go back. */
	public static function mt_redirect( $form ): void {
		$f    = $form->get_fields();
		$to   = wp_validate_redirect( esc_url_raw( self::one( $f, 'to' ) ), Pages::url( 'my-applications' ) );
		$job  = absint( self::one( $f, 'job' ) );
		$user = get_current_user_id();
		if ( $user && $job && Accounts::is_applicant() ) {
			Applications::start( $user, $job );
		}
		$form->set_result(
			array(
				'action'     => 'mt_redirect',
				'type'       => 'redirect',
				'redirectTo' => $to,
			)
		);
	}

	/* ---------- helpers ---------- */

	private static function error( $form, string $action, string $message ): void {
		$form->set_result(
			array(
				'action'  => $action,
				'type'    => 'error',
				'message' => $message,
			)
		);
	}

	/** First submitted value of a field, as a short key-like string. */
	private static function one( array $fields, string $key ): string {
		$v = $fields[ 'form-field-' . self::F[ $key ] ] ?? '';
		$v = is_array( $v ) ? ( reset( $v ) ?: '' ) : $v;
		return 'to' === $key ? trim( (string) $v ) : sanitize_text_field( (string) $v );
	}

	private static function many( array $fields, string $key ): array {
		$v = $fields[ 'form-field-' . self::F[ $key ] ] ?? array();
		return array_values( array_filter( array_map( 'sanitize_key', is_array( $v ) ? $v : explode( ',', (string) $v ) ) ) );
	}

	/** A date from the date picker (site date format) or Y-m-d, as Y-m-d; '' if unreadable. */
	private static function date( string $value ): string {
		$value = trim( $value );
		if ( '' === $value ) {
			return '';
		}
		foreach ( array( 'Y-m-d', (string) get_option( 'date_format' ) ) as $format ) {
			$d = \DateTimeImmutable::createFromFormat( '!' . $format, $value, wp_timezone() );
			if ( $d ) {
				return $d->format( 'Y-m-d' );
			}
		}
		$ts = strtotime( $value );
		return $ts ? gmdate( 'Y-m-d', $ts ) : '';
	}
}
