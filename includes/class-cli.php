<?php
namespace MMI_Teach;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * MMI Teach commands: refresh the job boards, sync job posts, build the Bricks site.
 */
final class Cli {

	/**
	 * Reads every source, stores changes and updates the job posts.
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
		\WP_CLI::success( sprintf( '%d open jobs on the site.', count( Jobs::open_ids() ) ) );
	}

	/**
	 * Shows counts per county and source health.
	 *
	 * @subcommand status
	 */
	public function status(): void {
		$by = array();
		foreach ( Jobs::open_ids() as $id ) {
			$key        = get_post_meta( $id, '_mt_county', true ) . ' / ' . get_post_meta( $id, '_mt_role', true );
			$by[ $key ] = ( $by[ $key ] ?? 0 ) + 1;
		}
		ksort( $by );
		foreach ( $by as $key => $n ) {
			\WP_CLI::log( sprintf( '%-28s %d', $key, $n ) );
		}
		$status = get_option( Refresher::OPTION_STATUS, array() );
		foreach ( Refresher::sources() as $source ) {
			$s = $status['sources'][ $source->id() ] ?? array();
			\WP_CLI::log( sprintf( '%s: last good read %s%s', $source->label(), $s['last_ok'] ?? 'never', empty( $s['last_error'] ) ? '' : ' — last error: ' . $s['last_error'] ) );
		}
		\WP_CLI::log( sprintf( 'Employers not placed in any county: %d', count( Store::unmapped_employers() ) ) );
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
		Jobs::sync();
		\WP_CLI::success( "{$changed} postings changed." );
	}

	/**
	 * Builds the Bricks site: settings, classes, templates, pages, menu. Run as an admin user.
	 *
	 * Run `--step=settings` first, once, in its own command: Bricks attaches its filter hooks
	 * at load time only when query filters were already on. Pages edited in the builder since
	 * the last build are skipped unless named in --force.
	 *
	 * ## OPTIONS
	 *
	 * [--step=<step>]
	 * : settings | pages (default) | live (make the Bricks site the front page; old URLs redirect).
	 *
	 * [--force=<slugs>]
	 * : Comma-separated page slugs or template names (header, footer, job, 404) to overwrite; "all" for every one.
	 *
	 * ## EXAMPLES
	 *
	 *     wp --user=1 mmi-teach build-site --step=settings
	 *     wp --user=1 mmi-teach build-site
	 *     wp --user=1 mmi-teach build-site --step=live
	 *
	 * @subcommand build-site
	 *
	 * @param array $args       Unused.
	 * @param array $assoc_args Options.
	 */
	public function build_site( array $args, array $assoc_args ): void {
		if ( ! defined( 'BRICKS_VERSION' ) ) {
			\WP_CLI::error( 'Bricks isn’t the active theme.' );
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			\WP_CLI::error( 'Run as an administrator: wp --user=1 mmi-teach build-site' );
		}
		$force = array_filter( array_map( 'trim', explode( ',', (string) ( $assoc_args['force'] ?? '' ) ) ) );
		$site  = new Build\Site( $force );
		try {
			switch ( $assoc_args['step'] ?? 'pages' ) {
				case 'settings':
					$site->settings();
					break;
				case 'live':
					$site->go_live();
					break;
				default:
					$site->build();
			}
		} catch ( \RuntimeException $e ) {
			\WP_CLI::error( $e->getMessage() );
		}
		foreach ( Build\Kit::$log as $line ) {
			\WP_CLI::log( $line );
		}
		\WP_CLI::success( 'Done.' );
	}

	/**
	 * Mirrors open postings into job posts (mt_job); only changed posts are written.
	 *
	 * ## OPTIONS
	 *
	 * [--limit=<n>]
	 * : Most posts to write this run. Default: no cap.
	 *
	 * [--dry-run]
	 * : Count what would change without writing.
	 *
	 * @subcommand sync-jobs
	 *
	 * @param array $args       Unused.
	 * @param array $assoc_args Options.
	 */
	public function sync_jobs( array $args, array $assoc_args ): void {
		$dry   = ! empty( $assoc_args['dry-run'] );
		$stats = Jobs::sync( (int) ( $assoc_args['limit'] ?? 0 ), $dry );
		\WP_CLI::success( vsprintf( '%s%d open: %d created, %d updated, %d closed, %d reopened, %d unchanged, %d deferred.', array( $dry ? 'Dry run. ' : '', $stats['open'], $stats['created'], $stats['updated'], $stats['closed'], $stats['reopened'], $stats['same'], $stats['deferred'] ) ) );
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
