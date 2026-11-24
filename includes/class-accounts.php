<?php
namespace MMI_Teach;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Applicant accounts. Created by the Bricks registration form with the mt_applicant role, which
 * has no capabilities: applicants never see wp-admin or the admin bar. A guest's saved answers
 * and checklist move into the account on sign-up and sign-in.
 */
final class Accounts {

	public const ROLE = 'mt_applicant';

	public static function register(): void {
		add_action( 'init', array( self::class, 'ensure_role' ), 5 );
		add_action( 'user_register', array( self::class, 'on_register' ) );
		add_action( 'wp_login', array( self::class, 'on_login' ), 10, 2 );
		add_action( 'admin_init', array( self::class, 'keep_out_of_admin' ) );
		add_filter( 'show_admin_bar', array( self::class, 'admin_bar' ) );
		add_filter( 'login_redirect', array( self::class, 'login_redirect' ), 10, 3 );
	}

	public static function ensure_role(): void {
		if ( ! get_role( self::ROLE ) ) {
			add_role( self::ROLE, __( 'Applicant', 'mmi-teach' ), array( 'read' => false ) );
		}
	}

	public static function is_applicant( ?\WP_User $user = null ): bool {
		$user = $user ?? wp_get_current_user();
		return $user && $user->exists() && in_array( self::ROLE, (array) $user->roles, true ) && ! $user->has_cap( 'edit_posts' );
	}

	public static function on_register( int $user_id ): void {
		$user = get_userdata( $user_id );
		if ( $user && self::is_applicant( $user ) ) {
			Profile::merge_into_user( $user_id );
		}
	}

	public static function on_login( string $login, \WP_User $user ): void {
		if ( self::is_applicant( $user ) ) {
			Profile::merge_into_user( $user->ID );
		}
	}

	public static function keep_out_of_admin(): void {
		if ( wp_doing_ajax() || ! self::is_applicant() || ( defined( 'DOING_CRON' ) && DOING_CRON ) ) {
			return;
		}
		wp_safe_redirect( Pages::url( 'my-applications' ) );
		exit;
	}

	public static function admin_bar( bool $show ): bool {
		return self::is_applicant() ? false : $show;
	}

	/** Applicants signing in through wp-login.php land on their applications, not wp-admin. */
	public static function login_redirect( $redirect_to, $requested, $user ) {
		if ( $user instanceof \WP_User && self::is_applicant( $user ) && ( '' === $requested || str_contains( (string) $redirect_to, '/wp-admin' ) ) ) {
			return Pages::url( 'my-applications' );
		}
		return $redirect_to;
	}

	/**
	 * Deletes an applicant and everything stored about them: applications, answers, checklist.
	 * Refuses any account that can do more than apply.
	 */
	public static function delete( int $user_id ): bool {
		$user = get_userdata( $user_id );
		if ( ! $user || ! self::is_applicant( $user ) ) {
			return false;
		}
		foreach ( Applications::ids_for_user( $user_id ) as $app_id ) {
			wp_delete_post( $app_id, true );
		}
		require_once ABSPATH . 'wp-admin/includes/user.php';
		return (bool) wp_delete_user( $user_id );
	}
}
