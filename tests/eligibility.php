<?php
/**
 * Eligibility and checklist checks over every open posting × every sample persona.
 * Run: wp eval-file wp-content/plugins/mmi-teach/tests/eligibility.php
 * Exits non-zero on any failure.
 */

use MMI_Teach\Checklist;
use MMI_Teach\Eligibility;
use MMI_Teach\Plugin;
use MMI_Teach\Profile;
use MMI_Teach\Rules;
use MMI_Teach\Store;

$fail = 0;
$ok   = static function ( bool $cond, string $what ) use ( &$fail ) {
	if ( ! $cond ) {
		++$fail;
		WP_CLI::warning( 'FAIL: ' . $what );
	}
};

/* ---------- data files ---------- */
$items = Rules::items();
$ok( count( $items ) >= 20, 'rules.json has its items' );
foreach ( $items as $id => $item ) {
	foreach ( array( 'kind', 'group', 'title', 'why', 'roles', 'blocks', 'time', 'cost', 'proof', 'collect', 'source', 'verified_on', 'confidence' ) as $key ) {
		$ok( isset( $item[ $key ] ), "rules.json $id has $key" );
	}
	$ok( isset( Rules::groups()[ $item['group'] ?? '' ] ), "rules.json $id group is known" );
	$ok( str_starts_with( (string) ( $item['source'] ?? '' ), 'https://' ), "rules.json $id has a public https source" );
	foreach ( (array) ( $item['after'] ?? array() ) as $dep ) {
		$ok( isset( $items[ $dep ] ), "rules.json $id depends on known item $dep" );
	}
}

/* ---------- fixed cases ---------- */
$base = array( 'edu' => 'high_school', 'cred' => 'none', 'areas' => array(), 'epp' => 0, 'age' => '20', 'outside_wi' => 0, 'roles' => array( 'teacher', 'substitute', 'aide' ) );
$sub  = array( 'role' => 'substitute', 'sub_kind' => 'daily', 'employer' => 'Test District', 'title' => 'Substitute Teacher', 'sector' => 'public', 'degree' => 'associate', 'degree_basis' => 'rule', 'degree_note' => '', 'licenses' => array(), 'license_basis' => 'inferred' );
$case = static function ( array $p, array $job ) {
	return Eligibility::for_job( $job, Profile::sanitize( $p ), array() );
};

$r = $case( $base, $sub );
$ok( 'later' === $r['status'] && in_array( 'associate_degree', $r['gaps'], true ), 'HS diploma → daily sub is later, needs associate' );
$ok( (bool) preg_grep( '/aide/i', $r['reasons'] ), 'HS diploma → daily sub names the aide alternative' );

$r = $case( array( 'edu' => 'associate' ) + $base, $sub );
$ok( 'steps' === $r['status'] && $r['can_apply'] && array( 'sub_training', 'sub_permit' ) === $r['gaps'], 'associate, no permit → steps: training then permit, can apply' );
$ok( $r['days'][0] >= 42 && $r['days'][1] <= 80 && 135 <= $r['cost'][0], 'training + permit time and cost add up' );

$r = $case( array( 'edu' => 'associate', 'outside_wi' => 1 ) + $base, $sub );
$ok( in_array( 'fingerprints', $r['gaps'], true ) && $r['days'][1] >= 84, 'lived outside WI → fingerprints added and time grows' );

$r = $case( array( 'edu' => 'some_college', 'epp' => 1 ) + $base, $sub );
$ok( 'steps' === $r['status'] && array( 'sub_permit' ) === $r['gaps'], 'teacher-prep student → permit without training' );

$r = $case( array( 'edu' => 'some_college', 'epp' => 1, 'age' => '18' ) + $base, $sub );
$ok( 'later' === $r['status'], 'teacher-prep student under 20 → later' );

$r = $case( array( 'edu' => 'associate', 'cred' => 'permit' ) + $base, $sub );
$ok( 'now' === $r['status'] && ! $r['gaps'], 'permit holder → daily sub now' );

$r = $case( array( 'edu' => 'associate', 'cred' => 'permit' ) + $base, array( 'degree' => 'bachelor', 'employer' => 'Milwaukee Public Schools' ) + $sub );
$ok( 'later' === $r['status'] && in_array( 'bachelor_degree', $r['gaps'], true ), 'associate + permit → MPS (bachelor) is later' );

$r = $case( array( 'edu' => 'associate', 'cred' => 'permit' ) + $base, array( 'sub_kind' => 'long_term' ) + $sub );
$ok( 'check' === $r['status'] && $r['can_apply'], 'permit holder → long-term sub is check (45 days)' );

$r = $case( array( 'edu' => 'master', 'cred' => 'expired', 'areas' => array( 'math' ) ) + $base, $sub );
$ok( 'steps' === $r['status'] && array( 'long_term_license' ) === $r['gaps'], 'retired teacher → long-term sub license' );

$teach = array( 'role' => 'teacher', 'sub_kind' => '', 'degree' => 'bachelor', 'licenses' => array( array( 'key' => 'math', 'category' => 'subject', 'short' => 'Mathematics', 'title' => 'Mathematics' ) ), 'license_basis' => 'posted' ) + $sub;
$r = $case( array( 'edu' => 'bachelor', 'cred' => 'wi_license', 'areas' => array( 'math' ) ) + $base, $teach );
$ok( 'now' === $r['status'], 'math license → math job now' );
$r = $case( array( 'edu' => 'bachelor', 'cred' => 'wi_license', 'areas' => array( 'art' ) ) + $base, $teach );
$ok( 'later' === $r['status'], 'art license → posted math job is later' );
$r = $case( array( 'edu' => 'bachelor', 'cred' => 'wi_license', 'areas' => array( 'art' ) ) + $base, array( 'license_basis' => 'inferred' ) + $teach );
$ok( 'check' === $r['status'], 'art license → inferred math job is check' );
$r = $case( array( 'edu' => 'bachelor' ) + $base, $teach );
$ok( 'check' === $r['status'] && in_array( 'stipulations', $r['maybe'], true ), 'bachelor, no license → teacher job check with stipulations' );
$r = $case( array( 'edu' => 'bachelor' ) + $base, array( 'sector' => 'private' ) + $teach );
$ok( 'check' === $r['status'], 'bachelor → private school check' );
$r = $case( array( 'edu' => 'associate' ) + $base, $teach );
$ok( 'later' === $r['status'] && (bool) preg_grep( '/substitute/i', $r['reasons'] ), 'associate → teacher later, suggests subbing' );
$r = $case( array( 'edu' => 'bachelor', 'cred' => 'oos', 'areas' => array( 'math' ), 'outside_wi' => 1 ) + $base, $teach );
$ok( 'steps' === $r['status'] && array( 'teaching_license', 'fingerprints' ) === $r['gaps'], 'out-of-state → license transfer + fingerprints' );

$aide = array( 'role' => 'aide', 'sub_kind' => '', 'degree' => 'high_school', 'licenses' => array() ) + $sub;
$r = $case( array( 'edu' => 'none' ) + $base, $aide );
$ok( 'later' === $r['status'] && array( 'high_school' ) === $r['gaps'], 'no diploma → aide later' );
$r = $case( $base, $aide );
$ok( 'check' === $r['status'] && in_array( 'title1_para', $r['maybe'], true ), 'HS diploma → public aide check (Title I)' );
$r = $case( array( 'edu' => 'associate' ) + $base, $aide );
$ok( 'now' === $r['status'], 'associate → public aide now' );
$r = $case( array( 'edu' => 'associate' ) + $base, array( 'licenses' => array( array( 'key' => 'sepa', 'category' => 'aide' ) ) ) + $aide );
$ok( 'steps' === $r['status'] && array( 'sepa_license' ) === $r['gaps'] && $r['can_apply'], 'special-ed aide → program aide license, can apply' );
$r = $case( array( 'age' => 'u18' ) + $base, $aide );
$ok( 'later' === $r['status'], 'under 18 → later' );

// A done credential on the checklist counts.
$r = Eligibility::for_job( $sub, Profile::sanitize( array( 'edu' => 'associate' ) + $base ), array( 'sub_training' => array( 'done', '2026-11-01', '', '' ), 'sub_permit' => array( 'done', '2026-11-20', '2029-11-20', 'dpi' ) ) );
$ok( 'now' === $r['status'], 'permit ticked done → now' );
// An expired one doesn't.
$r = Eligibility::for_job( $sub, Profile::sanitize( array( 'edu' => 'associate' ) + $base ), array( 'sub_training' => array( 'done', '2020-01-01', '', '' ), 'sub_permit' => array( 'done', '2020-01-01', '2023-01-01', 'dpi' ) ) );
$ok( 'steps' === $r['status'] && array( 'sub_permit' ) === $r['gaps'], 'expired permit → steps again' );

/* ---------- checklist ---------- */
$rows = Checklist::rows( Profile::sanitize( array( 'edu' => 'associate' ) + $base ), $sub, array() );
$ids  = array_column( $rows, 'id' );
$ok( in_array( 'sub_permit', $ids, true ) && in_array( 'tb_screen', $ids, true ) && in_array( 'i9', $ids, true ), 'sub checklist has permit, TB and I-9' );
$ok( array_search( 'sub_permit', $ids, true ) < array_search( 'tb_screen', $ids, true ), 'credentials come first' );
$ok( ! in_array( 'drug_test', $ids, true ), 'MPS-only step stays off other employers' );
$mps = array_column( Checklist::rows( Profile::sanitize( array( 'edu' => 'bachelor' ) + $base ), array( 'employer' => 'Milwaukee Public Schools', 'degree' => 'bachelor' ) + $sub, array() ), 'id' );
$ok( in_array( 'drug_test', $mps, true ), 'MPS checklist adds the drug test' );
$i9 = Checklist::row( 'i9', array() );
$ok( str_contains( $i9['never'], 'Never email or upload' ), 'I-9 row warns against sending ID' );
$held = Checklist::rows( Profile::sanitize( array( 'edu' => 'associate', 'cred' => 'permit', 'roles' => array( 'substitute' ) ) + $base ), null, array() );
$ok( 'held' === ( array_column( $held, 'state', 'id' )['sub_permit'] ?? '' ), 'held permit shows as held' );

/* ---------- every open posting × every persona ---------- */
$rows   = Store::open_rows( array_keys( Plugin::COUNTIES ) );
$matrix = array();
foreach ( Profile::personas() as $key => $persona ) {
	$p     = Profile::sanitize( $persona['profile'] );
	$count = array_fill_keys( array( 'now', 'check', 'steps', 'later' ), 0 );
	$apply = 0;
	foreach ( $rows as $row ) {
		$r = Eligibility::for_job( Eligibility::job_from_row( $row ), $p, array() );
		$ok( isset( $count[ $r['status'] ] ), "$key / {$row['id']}: known status" );
		$ok( '' !== $r['summary'] && $r['reasons'], "$key / {$row['id']}: has a summary and reasons" );
		foreach ( $r['gaps'] as $gap ) {
			$ok( null !== Rules::item( $gap ), "$key / {$row['id']}: gap $gap exists in rules.json" );
		}
		++$count[ $r['status'] ];
		$apply += $r['can_apply'] ? 1 : 0;
	}
	$matrix[ $key ] = $count + array( 'can apply' => $apply );
	$paths = Eligibility::paths( $p, array() );
	$ok( (bool) array_filter( $paths, static fn( $x ) => 'later' !== $x['status'] ) || $apply > 0, "$key has a role open today or a path in weeks" );
}
WP_CLI\Utils\format_items( 'table', array_map( static fn( $k, $v ) => array( 'persona' => $k ) + $v, array_keys( $matrix ), $matrix ), array( 'persona', 'now', 'check', 'steps', 'later', 'can apply' ) );
WP_CLI::log( count( $rows ) . ' open postings checked.' );

if ( $fail ) {
	WP_CLI::error( "$fail check(s) failed." );
}
WP_CLI::success( 'All eligibility and checklist checks passed.' );
