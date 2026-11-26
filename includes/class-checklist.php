<?php
namespace MMI_Teach;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * One list of everything between a person and their first day, with one state per item.
 *
 * Without a job it covers the roles the person wants (their fastest credential route plus the
 * onboarding every school job has); with a job it covers that job: its missing credentials,
 * the steps for its role, the employer's extra steps and its agency's process.
 */
final class Checklist {

	/** Display order of groups. */
	private const GROUP_ORDER = array( 'credential', 'application', 'screening', 'training', 'paperwork', 'after' );

	/**
	 * @param array|null $p   Profile; null shows the general list for every role.
	 * @param array|null $job Eligibility job array plus optional 'agency'.
	 * @return array[] Display rows (see row()), ordered by group, then credential chain order.
	 */
	public static function rows( ?array $p, ?array $job = null, ?array $tasks = null ): array {
		$tasks = $tasks ?? Profile::tasks();
		$roles = $job ? array( $job['role'] ) : ( $p['roles'] ?? array_keys( Classifier::ROLES ) );
		$ids   = array(); // id => why it's on the list (only for credentials).
		$maybe = array();

		if ( $p ) {
			if ( $job ) {
				$fit = Eligibility::for_job( $job, $p, $tasks );
				$ids = array_fill_keys( $fit['gaps'], '' );
				$maybe = $fit['maybe'];
			} else {
				foreach ( Eligibility::paths( $p, $tasks ) as $role => $path ) {
					if ( $path['wanted'] && Eligibility::LATER !== $path['status'] ) {
						$ids += array_fill_keys( $path['gaps'], '' );
						$maybe = array_merge( $maybe, $path['maybe'] );
					}
				}
			}
		}
		// Credentials already held through the profile show as done, so progress counts them.
		foreach ( self::held( $p, $roles ) as $id ) {
			$ids[ $id ] ??= '';
		}

		foreach ( Rules::items() as $id => $item ) {
			if ( 'step' !== $item['kind'] || ! array_intersect( $roles, $item['roles'] ) ) {
				continue;
			}
			if ( ! empty( $item['only_employers'] ) && ! ( $job && in_array( $job['employer'], $item['only_employers'], true ) ) ) {
				continue;
			}
			if ( 'has_dpi_credential' === ( $item['if'] ?? '' ) && ! ( $p && ( 'none' !== $p['cred'] || array_intersect( array_keys( $ids ), array( 'sub_permit', 'long_term_license', 'teaching_license', 'oos_license', 'license_reissue', 'sepa_license' ) ) ) ) ) {
				continue;
			}
			$ids[ $id ] ??= '';
		}

		$rows = array();
		foreach ( array_keys( $ids ) as $id ) {
			$row = self::row( $id, $tasks, in_array( $id, self::held( $p, $roles ), true ) );
			if ( $row ) {
				$rows[] = $row;
			}
		}
		foreach ( array_unique( $maybe ) as $id ) {
			if ( ! isset( $ids[ $id ] ) ) {
				$row = self::row( $id, $tasks );
				if ( $row ) {
					$row['maybe'] = true;
					$rows[]       = $row;
				}
			}
		}
		$items = array_keys( Rules::items() );
		usort(
			$rows,
			static fn( $a, $b ) => ( array_search( $a['group'], self::GROUP_ORDER, true ) <=> array_search( $b['group'], self::GROUP_ORDER, true ) )
				?: ( array_search( $a['id'], $items, true ) <=> array_search( $b['id'], $items, true ) )
		);
		return $rows;
	}

	/** Agency process note for a job, from a public source, or ''. */
	public static function agency_note( ?array $job ): string {
		$a = $job && ! empty( $job['agency'] ) ? Rules::agency( (string) $job['agency'] ) : null;
		return $a['note'] ?? '';
	}

	/** [done, total] counting done and not-needed as finished; "maybe" items don't count. */
	public static function progress( array $rows ): array {
		$counted = array_filter( $rows, static fn( $r ) => empty( $r['maybe'] ) );
		$done    = count( array_filter( $counted, static fn( $r ) => in_array( $r['state'], array( 'done', 'na', 'held' ), true ) ) );
		return array( $done, count( $counted ) );
	}

	/** Credentials the profile already holds, shown as done. */
	private static function held( ?array $p, array $roles ): array {
		if ( ! $p ) {
			return array();
		}
		$held = array();
		if ( in_array( 'substitute', $roles, true ) && 'permit' === $p['cred'] ) {
			$held[] = 'sub_permit';
		}
		if ( in_array( 'substitute', $roles, true ) && 'long_term' === $p['cred'] ) {
			$held[] = 'long_term_license';
		}
		if ( array_intersect( array( 'teacher', 'substitute' ), $roles ) && 'wi_license' === $p['cred'] ) {
			$held[] = 'teaching_license';
		}
		if ( in_array( 'aide', $roles, true ) && 'sepa' === $p['cred'] ) {
			$held[] = 'sepa_license';
		}
		return $held;
	}

	/** One item ready for display, with its state for this person. */
	public static function row( string $id, array $tasks, bool $held = false ): ?array {
		$item = Rules::item( $id );
		if ( ! $item ) {
			return null;
		}
		[ $state, $date, $expiry, $where ] = Profile::task( $id, $tasks );
		if ( $held && 'todo' === $state ) {
			$state = 'held';
		}
		$cost  = $item['cost'] ?? array();
		$valid = $item['valid'] ?? array();
		return array(
			'id'          => $id,
			'title'       => $item['title'],
			'group'       => $item['group'],
			'group_label' => Rules::groups()[ $item['group'] ] ?? '',
			'kind'        => $item['kind'],
			'why'         => $item['why'] ?? '',
			'blocks'      => $item['blocks'] ?? 'none',
			'blocks_label' => Rules::BLOCKS[ $item['blocks'] ?? 'none' ] ?? '',
			'time_label'  => $item['time']['label'] ?? '',
			'cost_label'  => $cost['label'] ?? '',
			'payer'       => $cost['payer'] ?? 'none',
			'payer_label' => Rules::PAYERS[ $cost['payer'] ?? 'none' ] ?? '',
			'where'       => $item['where'] ?? array(),
			'how'         => $item['how'] ?? array(),
			'proof'       => $item['proof'] ?? '',
			'valid_label' => trim( ( ! empty( $valid['years'] ) ? sprintf( _n( 'Valid %d year.', 'Valid %d years.', (int) $valid['years'], 'mmi-teach' ), (int) $valid['years'] ) . ' ' : '' ) . ( $valid['note'] ?? '' ) ),
			'collect'     => $item['collect'] ?? 'done',
			'never'       => $item['never'] ?? '',
			'conflict'    => $item['conflict'] ?? '',
			'source'      => $item['source'] ?? '',
			'verified_on' => $item['verified_on'] ?? '',
			'confidence'  => $item['confidence'] ?? '',
			'state'       => $state,
			'date'        => $date,
			'expiry'      => $expiry,
			'where_done'  => $where,
			'maybe'       => false,
		);
	}
}
