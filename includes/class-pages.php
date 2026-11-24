<?php
namespace MMI_Teach;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The site's pages by slug. The Bricks builder (Build\Site) records each page's ID here, so
 * links survive a page being renamed in WordPress.
 */
final class Pages {

	public const OPTION = 'mmi_teach_pages';

	/** slug => [title, SEO title, meta description]. 'home' is the front page. */
	public const PAGES = array(
		'home'            => array( 'Home', 'School jobs in Milwaukee, Waukesha and Racine counties', 'Open teacher, substitute and aide jobs in southeast Wisconsin, a two-minute check of what you qualify for, and a checklist of every Wisconsin requirement.' ),
		'jobs'            => array( 'Jobs', 'Open school jobs', 'Every open teacher, substitute teacher and aide job in Milwaukee, Waukesha and Racine counties, refreshed every three hours.' ),
		'check'           => array( 'Check what I qualify for', 'What school jobs can I apply for?', 'Six questions show which Wisconsin school jobs you can apply for today, and the quickest steps to the rest.' ),
		'checklist'       => array( 'My checklist', 'Your checklist to start working in a Wisconsin school', 'Every requirement between you and your first day: licenses, background checks, TB screening, trainings and paperwork, with cost, time and sources.' ),
		'join'            => array( 'Create an account', 'Create an account', 'Create a free account to save applications and keep your checklist on any device.' ),
		'sign-in'         => array( 'Sign in', 'Sign in', 'Sign in to your applications and checklist.' ),
		'reset-password'  => array( 'Reset password', 'Reset your password', 'Get a link to set a new password.' ),
		'my-applications' => array( 'My applications', 'My applications', 'Applications you saved, and where each one stands.' ),
		'account'         => array( 'My account', 'My account', 'Your answers, your account and your data.' ),
		'guides'          => array( 'Guides', 'Guides to working in Wisconsin schools', 'Plain-language guides to substitute permits, teaching licenses and aide jobs in Wisconsin.' ),
		'privacy'         => array( 'Privacy', 'Privacy notice', 'What this site stores about you, and what it never asks for.' ),
	);

	public static function id( string $slug ): int {
		$ids = get_option( self::OPTION, array() );
		return (int) ( is_array( $ids ) ? ( $ids[ $slug ] ?? 0 ) : 0 );
	}

	public static function url( string $slug ): string {
		if ( 'home' === $slug ) {
			return home_url( '/' );
		}
		$id = self::id( $slug );
		return $id && 'publish' === get_post_status( $id ) ? (string) get_permalink( $id ) : home_url( '/' . $slug . '/' );
	}

	public static function remember( string $slug, int $id ): void {
		$ids          = get_option( self::OPTION, array() );
		$ids          = is_array( $ids ) ? $ids : array();
		$ids[ $slug ] = $id;
		update_option( self::OPTION, $ids, false );
	}

	/** Is the current request one of the site's pages (by slug)? */
	public static function is( string $slug ): bool {
		$id = self::id( $slug );
		return $id && is_page( $id );
	}
}
