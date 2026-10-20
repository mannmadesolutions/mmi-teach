<?php
namespace MMI_Teach;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Teaching-vacancy dashboard commands.
 */
final class Cli {

	/**
	 * Reads every source, stores changes and rebuilds the dashboard.
	 *
	 * ## OPTIONS
	 *
	 * [--details=<n>]
	 * : Most detail pages to read this run (new postings in the covered counties). Default 200.
	 *
	 * ## EXAMPLES
	 *
	 *     wp mmi-teach refresh
	 *
	 * @param array $args       Unused.
	 * @param array $assoc_args Options.
	 */
	public function refresh( array $args, array $assoc_args ): void {
		$summary = ( new Refresher( static fn( string $m ) => \WP_CLI::log( $m ) ) )->run(
			array( 'detail_limit' => (int) ( $assoc_args['details'] ?? 200 ) )
		);
		if ( ! empty( $summary['skipped'] ) ) {
			return;
		}
		$failed = array_filter( $summary['sources'], static fn( $s ) => ! $s['ok'] );
		if ( $failed ) {
			\WP_CLI::error( 'Failed: ' . implode( ', ', array_keys( $failed ) ) );
		}
		\WP_CLI::success( sprintf( '%d vacancies on the dashboard.', count( Snapshot::get()['vacancies'] ) ) );
	}

	/**
	 * Shows counts per county and source health.
	 *
	 * @subcommand status
	 */
	public function status(): void {
		$snapshot = Snapshot::get();
		$by       = array();
		foreach ( $snapshot['vacancies'] as $v ) {
			$by[ $v['county'] ] = ( $by[ $v['county'] ] ?? 0 ) + 1;
		}
		foreach ( Plugin::COUNTIES as $name ) {
			\WP_CLI::log( sprintf( '%-10s %d', $name, $by[ $name ] ?? 0 ) );
		}
		foreach ( $snapshot['sources'] as $s ) {
			\WP_CLI::log( sprintf( '%s: last good read %s%s', $s['label'], $s['lastOk'] ?: 'never', $s['lastError'] ? ' — last error: ' . $s['lastError'] : '' ) );
		}
		\WP_CLI::log( sprintf( 'Employers not placed in any county: %d', $snapshot['unplaced'] ) );
	}

	/**
	 * Lists employers with open postings that couldn't be placed in a county.
	 *
	 * @subcommand unplaced
	 */
	public function unplaced(): void {
		\WP_CLI\Utils\format_items( 'table', Store::unmapped_employers(), array( 'employer', 'postings', 'sample_location' ) );
	}

	/**
	 * Re-runs county and subject matching on open postings (after editing data/*.json or the classifier).
	 */
	public function reclassify(): void {
		Places::reset();
		$changed = Store::reclassify_open();
		Snapshot::rebuild();
		\WP_CLI::success( "{$changed} postings changed." );
	}

	/**
	 * Rebuilds data/places.json from NCES public data (district, public and private school counties).
	 *
	 * @subcommand build-places
	 */
	public function build_places(): void {
		try {
			$counts = ( new Places_Builder() )->build( MMI_TEACH_DIR . 'data/places.json' );
		} catch ( \RuntimeException $e ) {
			\WP_CLI::error( $e->getMessage() . ' places.json was left unchanged; try again later.' );
		}
		\WP_CLI::success( sprintf( '%d districts, %d public and %d private schools, %d cities.', $counts['districts'], $counts['schools'], $counts['private'], $counts['cities'] ) );
	}
}
