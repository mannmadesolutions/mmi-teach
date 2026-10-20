<?php
namespace MMI_Teach;

use MMI_Teach\Sources\Source;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * One refresh: read every source, store what changed, read detail pages for new postings in
 * the covered counties, then rebuild the dashboard snapshot.
 */
final class Refresher {

	public const OPTION_STATUS    = 'mmi_teach_status';
	public const OPTION_FIRST_RUN = 'mmi_teach_first_run';
	private const OPTION_LOCK  = 'mmi_teach_refresh_lock';
	private const LOCK_TTL     = 1800;

	/** A source whose count drops below this share of its last count is treated as broken. */
	private const MIN_SHARE_OF_LAST = 0.5;

	/** Stop the detail pass after this many failures in a row. */
	private const MAX_DETAIL_FAILURES = 3;

	/** @var callable|null */
	private $log;

	public function __construct( ?callable $log = null ) {
		$this->log = $log;
	}

	/** @return Source[] */
	public static function sources(): array {
		return apply_filters(
			'mmi_teach_sources',
			array(
				new Sources\Wecan(), // First on purpose: when boards list the same job, WECAN's copy is kept.
				new Sources\Mps(),
				new Sources\Archdiocese(),
				new Sources\Paycom(),
				new Sources\Paycor(),
				new Sources\Lever(),
				new Sources\Breezy(),
				new Sources\Isolved(),
			)
		);
	}

	/**
	 * @param array{detail_limit?: int} $opts detail_limit caps detail-page reads this run.
	 * @return array Run summary (also kept in the status option).
	 */
	public function run( array $opts = array() ): array {
		if ( ! $this->lock() ) {
			$this->say( 'Another refresh is running; skipped.' );
			return array( 'skipped' => true );
		}
		try {
			Store::maybe_upgrade();
			$started = current_time( 'mysql', true );
			add_option( self::OPTION_FIRST_RUN, $started, '', false );
			$summary = array(
				'started' => $started,
				'sources' => array(),
			);
			foreach ( self::sources() as $source ) {
				$summary['sources'][ $source->id() ] = $this->refresh_source( $source, $started, (int) ( $opts['detail_limit'] ?? 200 ) );
			}
			$summary['finished'] = current_time( 'mysql', true );
			$this->save_status( $summary );
			Snapshot::rebuild();
			return $summary;
		} finally {
			delete_option( self::OPTION_LOCK );
		}
	}

	private function refresh_source( Source $source, string $started, int $detail_limit ): array {
		$id     = $source->id();
		$result = array(
			'label'  => $source->label(),
			'ok'     => false,
			'error'  => '',
			'listed' => 0,
		);
		try {
			$rows = $source->fetch();
		} catch ( \Throwable $e ) {
			$result['error'] = $e->getMessage();
			$this->say( "{$id}: {$e->getMessage()}" );
			return $result;
		}

		$last_open = Store::count_open( $id );
		if ( $last_open > 50 && count( $rows ) < $last_open * self::MIN_SHARE_OF_LAST ) {
			$result['error'] = sprintf( 'Listed %d postings, under half of the %d open last time; kept the old list.', count( $rows ), $last_open );
			$this->say( "{$id}: {$result['error']}" );
			return $result;
		}

		$places = Places::instance();
		$index  = Store::index( $id );
		$touch  = array();
		$counts = array(
			'new'     => 0,
			'changed' => 0,
			'same'    => 0,
		);
		foreach ( $rows as $row ) {
			$existing = $index[ $row['source_id'] ] ?? null;
			$details  = ! empty( $existing['details'] ) ? (array) json_decode( $existing['details'], true ) : array();
			$row     += Classifier::derive( $row, $details, $places );
			++$counts[ Store::upsert( $row, $existing, $started, $touch ) ];
		}
		Store::touch( $touch, $started );
		$removed = Store::mark_unseen_removed( $id, $started );

		$result = array_merge(
			$result,
			$counts,
			array(
				'ok'      => true,
				'listed'  => count( $rows ),
				'removed' => $removed,
				'details' => $this->read_details( $source, $detail_limit ),
			)
		);
		$this->say( sprintf( '%s: %d listed — %d new, %d changed, %d gone; %d detail pages read.', $id, count( $rows ), $counts['new'], $counts['changed'], $removed, $result['details'] ) );
		return $result;
	}

	private function read_details( Source $source, int $limit ): int {
		if ( $limit <= 0 || $source instanceof Sources\Board ) {
			return 0; // Single-employer boards have no detail pages worth reading.
		}
		$read     = 0;
		$failures = 0;
		foreach ( Store::rows_needing_details( $source->id(), $limit ) as $row ) {
			try {
				$details = $source->fetch_details( (string) $row['source_id'] );
				$failures = 0;
			} catch ( \Throwable $e ) {
				$this->say( "{$source->id()} {$row['source_id']}: {$e->getMessage()}" );
				if ( ++$failures >= self::MAX_DETAIL_FAILURES ) {
					break;
				}
				continue;
			}
			Store::save_details( (int) $row['id'], $details );
			++$read;
		}
		if ( $read ) {
			Store::reclassify_open(); // Licenses can settle a subject the title left open.
		}
		return $read;
	}

	private function lock(): bool {
		if ( add_option( self::OPTION_LOCK, time(), '', false ) ) {
			return true;
		}
		$held_since = (int) get_option( self::OPTION_LOCK );
		if ( $held_since && time() - $held_since > self::LOCK_TTL ) {
			update_option( self::OPTION_LOCK, time(), false ); // Stale lock from a killed run.
			return true;
		}
		return false;
	}

	private function save_status( array $summary ): void {
		$status = get_option( self::OPTION_STATUS, array() );
		$status = is_array( $status ) ? $status : array();
		foreach ( $summary['sources'] as $id => $result ) {
			$prev                         = $status['sources'][ $id ] ?? array();
			$status['sources'][ $id ]     = array_merge(
				$prev,
				array(
					'label'      => $result['label'],
					'last_run'   => $summary['finished'],
					'last_error' => $result['error'],
				),
				$result['ok'] ? array(
					'last_ok' => $summary['finished'],
					'listed'  => $result['listed'],
				) : array()
			);
		}
		$status['last_run'] = $summary['finished'];
		$status['runs']     = array_slice( array_merge( array( $summary ), $status['runs'] ?? array() ), 0, 20 );
		update_option( self::OPTION_STATUS, $status, false );
	}

	private function say( string $message ): void {
		if ( $this->log ) {
			( $this->log )( $message );
		}
	}
}
