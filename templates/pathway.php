<?php
/**
 * Full-page template for "Become a substitute teacher". Everything a reader needs is in the
 * HTML; pathway.js adds the license finder, the plan calculator, the saved checklist and sorting.
 *
 * @package MMI_Teach
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use MMI_Teach\Classifier;
use MMI_Teach\Pathway;
use MMI_Teach\Snapshot;

$mmi_teach_data     = Pathway::data();
$mmi_teach_dpi      = $mmi_teach_data['dpi'];
$mmi_teach_hiring   = Pathway::hiring_now();
$mmi_teach_snapshot = Snapshot::get();
$mmi_teach_subs     = array_filter( $mmi_teach_snapshot['vacancies'], static fn( $v ) => '' !== $v['subKind'] );
$mmi_teach_paid     = array_values( array_filter( array_map( static fn( $v ) => $v['payDay'], $mmi_teach_subs ), static fn( $d ) => null !== $d ) );
sort( $mmi_teach_paid );
$mmi_teach_n        = count( $mmi_teach_paid );
$mmi_teach_median   = $mmi_teach_n ? ( $mmi_teach_n % 2 ? $mmi_teach_paid[ intdiv( $mmi_teach_n, 2 ) ] : ( $mmi_teach_paid[ $mmi_teach_n / 2 - 1 ] + $mmi_teach_paid[ $mmi_teach_n / 2 ] ) / 2 ) : null;
$mmi_teach_agencies = $mmi_teach_data['agencies'];
$mmi_teach_via      = array(
	'direct'   => __( 'The district directly', 'mmi-teach' ),
	'edustaff' => 'EDUStaff',
	'kelly'    => 'Kelly Education',
);
$mmi_teach_money    = static fn( $n ) => '$' . number_format( (float) $n );
$mmi_teach_checked  = static fn( $date ) => $date ? sprintf( /* translators: %s: date */ __( 'checked %s', 'mmi-teach' ), wp_date( 'M j, Y', strtotime( $date ) ) ) : '';
?><!doctype html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<?php wp_head(); ?>
</head>
<body <?php body_class( 'mmi-teach-body' ); ?>>
<?php wp_body_open(); ?>
<main class="mt-app mt-pathway" id="mt-app">
	<?php require MMI_TEACH_DIR . 'templates/partials/nav.php'; ?>
	<header class="mt-head">
		<div>
			<h1><?php esc_html_e( 'Become a substitute teacher', 'mmi-teach' ); ?></h1>
			<p class="mt-sub"><?php esc_html_e( 'Milwaukee, Waukesha and Racine counties. The whole path in one place: which license, which training, the exact DPI steps, where to apply and what it pays. Do the employer steps while DPI processes your permit, not after.', 'mmi-teach' ); ?></p>
		</div>
	</header>

	<?php if ( ! MMI_Teach\Dashboard::can_view() ) : ?>
		<section class="mt-card mt-gate">
			<h2><?php esc_html_e( 'Sign in to see this page', 'mmi-teach' ); ?></h2>
			<a class="mt-btn mt-btn-primary" href="<?php echo esc_url( wp_login_url( get_permalink() ) ); ?>"><?php esc_html_e( 'Sign in', 'mmi-teach' ); ?></a>
		</section>
	<?php else : ?>

	<section class="mt-kpis" aria-label="<?php esc_attr_e( 'Substitute jobs right now', 'mmi-teach' ); ?>">
		<a class="mt-kpi" href="<?php echo esc_url( home_url( '/#kind=subs' ) ); ?>">
			<span class="mt-kpi-label"><?php esc_html_e( 'Open substitute postings', 'mmi-teach' ); ?></span>
			<span class="mt-kpi-value"><?php echo esc_html( number_format_i18n( count( $mmi_teach_subs ) ) ); ?></span>
			<span class="mt-kpi-note"><?php esc_html_e( 'See them on the job board →', 'mmi-teach' ); ?></span>
		</a>
		<div class="mt-kpi">
			<span class="mt-kpi-label"><?php esc_html_e( 'Median sub pay per day', 'mmi-teach' ); ?></span>
			<span class="mt-kpi-value"><?php echo esc_html( null === $mmi_teach_median ? '—' : $mmi_teach_money( $mmi_teach_median ) ); ?></span>
			<span class="mt-kpi-note">
				<?php
				/* translators: %d: number of postings */
				echo esc_html( sprintf( _n( 'from %d posting that lists pay', 'from %d postings that list pay', $mmi_teach_n, 'mmi-teach' ), $mmi_teach_n ) );
				?>
			</span>
		</div>
		<div class="mt-kpi">
			<span class="mt-kpi-label"><?php esc_html_e( 'Employers hiring subs now', 'mmi-teach' ); ?></span>
			<span class="mt-kpi-value"><?php echo esc_html( number_format_i18n( count( $mmi_teach_hiring ) ) ); ?></span>
		</div>
		<div class="mt-kpi">
			<span class="mt-kpi-label"><?php esc_html_e( 'DPI permit processing', 'mmi-teach' ); ?></span>
			<span class="mt-kpi-value"><?php echo esc_html( sprintf( /* translators: 1: min weeks 2: max weeks */ __( '%1$d–%2$d wks', 'mmi-teach' ), $mmi_teach_dpi['processing_weeks'][0], $mmi_teach_dpi['processing_weeks'][1] ) ); ?></span>
			<span class="mt-kpi-note"><?php esc_html_e( 'Start employer applications during the wait', 'mmi-teach' ); ?></span>
		</div>
	</section>

	<ol class="mt-steps-nav">
		<li><a href="#license"><?php esc_html_e( 'Find your license', 'mmi-teach' ); ?></a></li>
		<li><a href="#training"><?php esc_html_e( 'Get trained', 'mmi-teach' ); ?></a></li>
		<li><a href="#dpi"><?php esc_html_e( 'Apply to DPI', 'mmi-teach' ); ?></a></li>
		<li><a href="#employers"><?php esc_html_e( 'Choose where to sub', 'mmi-teach' ); ?></a></li>
		<li><a href="#plan"><?php esc_html_e( 'Your plan', 'mmi-teach' ); ?></a></li>
	</ol>

	<section class="mt-card mt-section" id="license" aria-labelledby="license-h">
		<h2 id="license-h"><span class="mt-step-no">1</span> <?php esc_html_e( 'Find your license', 'mmi-teach' ); ?></h2>
		<p><?php esc_html_e( 'Answer a few questions and we’ll name the Wisconsin DPI license that fits, whether you need training and fingerprints, and what it lets you do.', 'mmi-teach' ); ?></p>
		<form class="mt-wizard" id="mt-wizard" hidden>
			<fieldset data-q="licensed">
				<legend><?php esc_html_e( 'Have you completed an educator preparation program, or do you hold (or qualify for) a Wisconsin teaching license?', 'mmi-teach' ); ?></legend>
				<label><input type="radio" name="licensed" value="yes"> <?php esc_html_e( 'Yes', 'mmi-teach' ); ?></label>
				<label><input type="radio" name="licensed" value="no"> <?php esc_html_e( 'No', 'mmi-teach' ); ?></label>
			</fieldset>
			<fieldset data-q="epp" hidden>
				<legend><?php esc_html_e( 'Are you enrolled in a Wisconsin educator preparation program, at junior standing or later, and at least 20 years old?', 'mmi-teach' ); ?></legend>
				<label><input type="radio" name="epp" value="yes"> <?php esc_html_e( 'Yes', 'mmi-teach' ); ?></label>
				<label><input type="radio" name="epp" value="no"> <?php esc_html_e( 'No', 'mmi-teach' ); ?></label>
			</fieldset>
			<fieldset data-q="degree" hidden>
				<legend><?php esc_html_e( 'What is your highest completed degree?', 'mmi-teach' ); ?></legend>
				<label><input type="radio" name="degree" value="none"> <?php esc_html_e( 'High school or some college', 'mmi-teach' ); ?></label>
				<label><input type="radio" name="degree" value="associate"> <?php esc_html_e( 'Associate degree', 'mmi-teach' ); ?></label>
				<label><input type="radio" name="degree" value="bachelor"> <?php esc_html_e( 'Bachelor’s degree or higher', 'mmi-teach' ); ?></label>
			</fieldset>
			<fieldset data-q="outside">
				<legend><?php esc_html_e( 'In the last 20 years (since age 17), have you lived, worked or studied outside Wisconsin — another state, a US territory, Canada or Great Britain?', 'mmi-teach' ); ?></legend>
				<label><input type="radio" name="outside" value="yes"> <?php esc_html_e( 'Yes', 'mmi-teach' ); ?></label>
				<label><input type="radio" name="outside" value="no"> <?php esc_html_e( 'No', 'mmi-teach' ); ?></label>
			</fieldset>
			<fieldset data-q="longterm">
				<legend><?php esc_html_e( 'Do you want long-term assignments (more than 45 days in one classroom)?', 'mmi-teach' ); ?></legend>
				<label><input type="radio" name="longterm" value="yes"> <?php esc_html_e( 'Yes', 'mmi-teach' ); ?></label>
				<label><input type="radio" name="longterm" value="no"> <?php esc_html_e( 'No, day-to-day is fine', 'mmi-teach' ); ?></label>
			</fieldset>
		</form>
		<div class="mt-result" id="mt-result" aria-live="polite"></div>
		<details class="mt-all-licenses">
			<summary><?php esc_html_e( 'All substitute license types', 'mmi-teach' ); ?></summary>
			<?php foreach ( $mmi_teach_data['licenses'] as $mmi_teach_license ) : ?>
				<div class="mt-license">
					<h3><?php echo esc_html( $mmi_teach_license['name'] ); ?></h3>
					<p><strong><?php esc_html_e( 'Who:', 'mmi-teach' ); ?></strong> <?php echo esc_html( $mmi_teach_license['who'] ); ?></p>
					<p><strong><?php esc_html_e( 'Lets you:', 'mmi-teach' ); ?></strong> <?php echo esc_html( $mmi_teach_license['allows'] ); ?></p>
					<p class="mt-src"><a href="<?php echo esc_url( $mmi_teach_license['source'] ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'DPI source', 'mmi-teach' ); ?></a> · <?php echo esc_html( $mmi_teach_checked( $mmi_teach_license['verified_on'] ) ); ?></p>
				</div>
			<?php endforeach; ?>
			<p><?php echo esc_html( $mmi_teach_data['long_term_note'] ); ?></p>
		</details>
	</section>

	<section class="mt-card mt-section" id="training" aria-labelledby="training-h">
		<h2 id="training-h"><span class="mt-step-no">2</span> <?php esc_html_e( 'Get trained', 'mmi-teach' ); ?></h2>
		<p><?php esc_html_e( 'The degree path needs one DPI-approved substitute training course. The educator-program path and the long-term license don’t. Prices differ a lot for the same certificate:', 'mmi-teach' ); ?></p>
		<div class="mt-table-wrap">
			<table class="mt-table mt-sortable">
				<thead><tr>
					<th scope="col" data-sort="num"><?php esc_html_e( 'ID', 'mmi-teach' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Training', 'mmi-teach' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Format', 'mmi-teach' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Length', 'mmi-teach' ); ?></th>
					<th scope="col" data-sort="num"><?php esc_html_e( 'Cost', 'mmi-teach' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Next start', 'mmi-teach' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Who can take it', 'mmi-teach' ); ?></th>
				</tr></thead>
				<tbody>
				<?php foreach ( $mmi_teach_data['training'] as $mmi_teach_t ) : ?>
					<tr>
						<td class="mt-num" data-label="ID"><?php echo esc_html( $mmi_teach_t['id'] ); ?></td>
						<td data-label="<?php esc_attr_e( 'Training', 'mmi-teach' ); ?>"><a href="<?php echo esc_url( $mmi_teach_t['url'] ); ?>" target="_blank" rel="noopener"><?php echo esc_html( $mmi_teach_t['provider'] ); ?></a></td>
						<td data-label="<?php esc_attr_e( 'Format', 'mmi-teach' ); ?>"><?php echo esc_html( $mmi_teach_t['format'] ); ?></td>
						<td data-label="<?php esc_attr_e( 'Length', 'mmi-teach' ); ?>"><?php echo esc_html( $mmi_teach_t['hours'] ); ?></td>
						<td class="mt-num" data-label="<?php esc_attr_e( 'Cost', 'mmi-teach' ); ?>" data-value="<?php echo esc_attr( null === $mmi_teach_t['cost'] ? '' : $mmi_teach_t['cost'] ); ?>"><?php echo esc_html( $mmi_teach_t['cost_label'] ); ?></td>
						<td data-label="<?php esc_attr_e( 'Next start', 'mmi-teach' ); ?>"><?php echo esc_html( $mmi_teach_t['next'] ); ?></td>
						<td data-label="<?php esc_attr_e( 'Who can take it', 'mmi-teach' ); ?>"><?php echo esc_html( $mmi_teach_t['tied'] ); ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<p class="mt-src"><?php echo esc_html( $mmi_teach_checked( $mmi_teach_data['training'][0]['verified_on'] ) ); ?>. <?php esc_html_e( 'Keep your completion certificate: DPI and every employer ask for it.', 'mmi-teach' ); ?></p>
	</section>

	<section class="mt-card mt-section" id="dpi" aria-labelledby="dpi-h">
		<h2 id="dpi-h"><span class="mt-step-no">3</span> <?php esc_html_e( 'Apply to DPI', 'mmi-teach' ); ?></h2>
		<ol class="mt-dpi-steps">
			<?php foreach ( $mmi_teach_dpi['steps'] as $mmi_teach_step ) : ?>
				<li><?php echo esc_html( $mmi_teach_step ); ?></li>
			<?php endforeach; ?>
		</ol>
		<ul class="mt-tips">
			<?php foreach ( $mmi_teach_dpi['tips'] as $mmi_teach_tip ) : ?>
				<li><?php echo esc_html( $mmi_teach_tip ); ?></li>
			<?php endforeach; ?>
		</ul>
		<p class="mt-links">
			<a class="mt-btn mt-btn-primary" href="<?php echo esc_url( $mmi_teach_dpi['elo_url'] ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Open ELO', 'mmi-teach' ); ?></a>
			<a class="mt-btn" href="<?php echo esc_url( $mmi_teach_dpi['status_url'] ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Check application status', 'mmi-teach' ); ?></a>
			<a class="mt-btn" href="<?php echo esc_url( $mmi_teach_dpi['lookup_url'] ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Look up a license', 'mmi-teach' ); ?></a>
		</p>
		<p class="mt-src">
			<?php esc_html_e( 'Sources:', 'mmi-teach' ); ?>
			<?php foreach ( $mmi_teach_dpi['sources'] as $mmi_teach_i => $mmi_teach_src ) : ?>
				<a href="<?php echo esc_url( $mmi_teach_src ); ?>" target="_blank" rel="noopener">DPI <?php echo esc_html( $mmi_teach_i + 1 ); ?></a>
			<?php endforeach; ?>
			· <?php echo esc_html( $mmi_teach_checked( $mmi_teach_dpi['verified_on'] ) ); ?>
		</p>
	</section>

	<section class="mt-card mt-section" id="employers" aria-labelledby="employers-h">
		<h2 id="employers-h"><span class="mt-step-no">4</span> <?php esc_html_e( 'Choose where to sub', 'mmi-teach' ); ?></h2>
		<p><?php esc_html_e( 'Districts hire subs one of three ways. Who employs you decides your paperwork, how you’re paid and your retirement plan; the district sets the day rate either way.', 'mmi-teach' ); ?></p>
		<div class="mt-models">
			<div class="mt-model">
				<h3><?php esc_html_e( 'Directly by the district', 'mmi-teach' ); ?></h3>
				<p><?php esc_html_e( 'Apply to each district (most post on WECAN). You’re a district employee: if you’re expected to work 880+ hours a year you join WRS, Wisconsin’s public pension.', 'mmi-teach' ); ?></p>
				<p class="mt-src"><a href="https://etf.wi.gov/" target="_blank" rel="noopener"><?php esc_html_e( 'WRS rules (ETF)', 'mmi-teach' ); ?></a></p>
			</div>
			<?php foreach ( $mmi_teach_agencies as $mmi_teach_agency ) : ?>
				<div class="mt-model">
					<h3><?php echo esc_html( sprintf( /* translators: %s: agency name */ __( 'Through %s', 'mmi-teach' ), $mmi_teach_agency['name'] ) ); ?></h3>
					<p><?php echo esc_html( $mmi_teach_agency['process'] ); ?></p>
					<p><strong><?php esc_html_e( 'Degree:', 'mmi-teach' ); ?></strong> <?php echo esc_html( $mmi_teach_agency['degree'] ); ?>. <strong><?php esc_html_e( 'Retirement:', 'mmi-teach' ); ?></strong> <?php echo esc_html( $mmi_teach_agency['retirement'] ); ?>.</p>
					<p class="mt-links"><a class="mt-btn" href="<?php echo esc_url( $mmi_teach_agency['apply_url'] ); ?>" target="_blank" rel="noopener"><?php echo esc_html( sprintf( /* translators: %s: agency name */ __( 'Apply with %s', 'mmi-teach' ), $mmi_teach_agency['name'] ) ); ?></a></p>
					<p class="mt-src">
						<?php foreach ( $mmi_teach_agency['sources'] as $mmi_teach_i => $mmi_teach_src ) : ?>
							<a href="<?php echo esc_url( $mmi_teach_src ); ?>" target="_blank" rel="noopener"><?php echo esc_html( sprintf( /* translators: %d: number */ __( 'Source %d', 'mmi-teach' ), $mmi_teach_i + 1 ) ); ?></a>
						<?php endforeach; ?>
						· <?php echo esc_html( $mmi_teach_checked( $mmi_teach_agency['verified_on'] ) ); ?>
					</p>
				</div>
			<?php endforeach; ?>
		</div>

		<h3><?php esc_html_e( 'Who’s hiring subs right now', 'mmi-teach' ); ?></h3>
		<p><?php esc_html_e( 'From live postings on the job board, refreshed every 3 hours. Pay is shown per day (hourly rates × 7 hours).', 'mmi-teach' ); ?></p>
		<div class="mt-table-wrap">
			<table class="mt-table mt-sortable">
				<thead><tr>
					<th scope="col" data-sort="num"><?php esc_html_e( 'ID', 'mmi-teach' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Employer', 'mmi-teach' ); ?></th>
					<th scope="col"><?php esc_html_e( 'County', 'mmi-teach' ); ?></th>
					<th scope="col" data-sort="num"><?php esc_html_e( 'Open sub postings', 'mmi-teach' ); ?></th>
					<th scope="col" data-sort="num"><?php esc_html_e( 'Posted pay per day', 'mmi-teach' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Daily subs hired by', 'mmi-teach' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Good to know', 'mmi-teach' ); ?></th>
				</tr></thead>
				<tbody>
				<?php foreach ( $mmi_teach_hiring as $mmi_teach_i => $mmi_teach_e ) : ?>
					<?php
					$mmi_teach_kinds = array();
					foreach ( $mmi_teach_e['kinds'] as $mmi_teach_k => $mmi_teach_c ) {
						$mmi_teach_kinds[] = $mmi_teach_c . ' ' . strtolower( Classifier::SUB_KINDS[ $mmi_teach_k ] ?? $mmi_teach_k );
					}
					$mmi_teach_pay = null === $mmi_teach_e['payMin'] ? '' : $mmi_teach_money( $mmi_teach_e['payMin'] ) . ( $mmi_teach_e['payMax'] > $mmi_teach_e['payMin'] ? '–' . $mmi_teach_money( $mmi_teach_e['payMax'] ) : '' );
					?>
					<tr>
						<td class="mt-num" data-label="ID"><?php echo esc_html( $mmi_teach_i + 1 ); ?></td>
						<td data-label="<?php esc_attr_e( 'Employer', 'mmi-teach' ); ?>"><a href="<?php echo esc_url( home_url( '/#kind=subs&employer=' . rawurlencode( $mmi_teach_e['employer'] ) ) ); ?>"><?php echo esc_html( $mmi_teach_e['employer'] ); ?></a></td>
						<td data-label="<?php esc_attr_e( 'County', 'mmi-teach' ); ?>"><?php echo esc_html( $mmi_teach_e['county'] ); ?></td>
						<td class="mt-num" data-label="<?php esc_attr_e( 'Open sub postings', 'mmi-teach' ); ?>" data-value="<?php echo esc_attr( $mmi_teach_e['count'] ); ?>"><span><?php echo esc_html( $mmi_teach_e['count'] ); ?> <span class="mt-muted">(<?php echo esc_html( implode( ', ', $mmi_teach_kinds ) ); ?>)</span></span></td>
						<td class="mt-num" data-label="<?php esc_attr_e( 'Posted pay per day', 'mmi-teach' ); ?>" data-value="<?php echo esc_attr( (string) ( $mmi_teach_e['payMin'] ?? '' ) ); ?>"><?php echo esc_html( $mmi_teach_pay ?: '—' ); ?></td>
						<td data-label="<?php esc_attr_e( 'Daily subs hired by', 'mmi-teach' ); ?>"><?php echo esc_html( $mmi_teach_via[ $mmi_teach_e['via'] ] ?? __( 'Not confirmed — apply through the posting', 'mmi-teach' ) ); ?></td>
						<td data-label="<?php esc_attr_e( 'Good to know', 'mmi-teach' ); ?>"><span>
							<?php echo esc_html( trim( ( $mmi_teach_e['degree'] ? $mmi_teach_e['degree'] . '. ' : '' ) . $mmi_teach_e['extras'] ) ); ?>
							<?php if ( $mmi_teach_e['source'] ) : ?>
								<a class="mt-src" href="<?php echo esc_url( $mmi_teach_e['source'] ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'source', 'mmi-teach' ); ?></a>
							<?php endif; ?>
						</span></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		</div>
	</section>

	<section class="mt-card mt-section" id="plan" aria-labelledby="plan-h">
		<h2 id="plan-h"><span class="mt-step-no">5</span> <?php esc_html_e( 'Your plan: cost, timeline and checklist', 'mmi-teach' ); ?></h2>
		<div id="mt-plan">
			<p><?php esc_html_e( 'Typical out-of-pocket cost on the degree path: the $135 DPI fee, a training course ($0–$80), a TB test if the employer asks, and about $40 for fingerprints only if you’ve lived outside Wisconsin.', 'mmi-teach' ); ?></p>
		</div>
	</section>

	<footer class="mt-foot">
		<p><?php esc_html_e( 'This site isn’t affiliated with Wisconsin DPI, EDUStaff, Kelly Education or any school district, and none of this is legal advice. Facts show the date they were last checked; confirm details with the source before you pay or apply.', 'mmi-teach' ); ?></p>
	</footer>
	<?php endif; ?>
</main>
<?php wp_footer(); ?>
</body>
</html>
