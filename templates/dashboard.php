<?php
/**
 * Full-page template for the vacancy dashboard.
 *
 * @package MMI_Teach
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$mmi_teach_names    = array_values( MMI_Teach\Plugin::COUNTIES );
$mmi_teach_last     = array_pop( $mmi_teach_names );
$mmi_teach_counties = implode( ', ', $mmi_teach_names ) . ' & ' . $mmi_teach_last;
?><!doctype html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<?php wp_head(); ?>
</head>
<body <?php body_class( 'mmi-teach-body' ); ?>>
<?php wp_body_open(); ?>
<main class="mt-app" id="mt-app">
	<?php require MMI_TEACH_DIR . 'templates/partials/nav.php'; ?>
	<header class="mt-head">
		<div>
			<h1><?php esc_html_e( 'Teaching vacancies', 'mmi-teach' ); ?></h1>
			<p class="mt-sub">
				<?php
				/* translators: %s: county names */
				echo esc_html( sprintf( __( 'Open K-12 teaching positions in %s counties, Wisconsin.', 'mmi-teach' ), $mmi_teach_counties ) );
				?>
				<span id="mt-freshness"></span>
			</p>
		</div>
		<div class="mt-head-actions" id="mt-head-actions"></div>
	</header>

	<?php if ( MMI_Teach\Dashboard::can_view() ) : ?>
		<div id="mt-root" aria-live="polite">
			<p class="mt-loading"><?php esc_html_e( 'Loading vacancies…', 'mmi-teach' ); ?></p>
		</div>
		<noscript><p class="mt-card"><?php esc_html_e( 'The dashboard needs JavaScript.', 'mmi-teach' ); ?></p></noscript>
	<?php else : ?>
		<section class="mt-card mt-gate">
			<h2><?php esc_html_e( 'Sign in to see the dashboard', 'mmi-teach' ); ?></h2>
			<p><?php esc_html_e( 'This dashboard is private for now.', 'mmi-teach' ); ?></p>
			<a class="mt-btn mt-btn-primary" href="<?php echo esc_url( wp_login_url( get_permalink() ) ); ?>"><?php esc_html_e( 'Sign in', 'mmi-teach' ); ?></a>
		</section>
	<?php endif; ?>
</main>
<?php wp_footer(); ?>
</body>
</html>
