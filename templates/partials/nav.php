<?php
/**
 * Top navigation shared by the dashboard and the substitute pathway.
 *
 * @package MMI_Teach
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$mmi_teach_on_pathway = MMI_Teach\Pathway::is_pathway();
?>
<nav class="mt-nav" aria-label="<?php esc_attr_e( 'Site', 'mmi-teach' ); ?>">
	<a href="<?php echo esc_url( home_url( '/' ) ); ?>" <?php echo $mmi_teach_on_pathway ? '' : 'aria-current="page"'; ?>><?php esc_html_e( 'Job board', 'mmi-teach' ); ?></a>
	<a href="<?php echo esc_url( home_url( '/#kind=subs' ) ); ?>"><?php esc_html_e( 'Substitute jobs', 'mmi-teach' ); ?></a>
	<?php if ( MMI_Teach\Pathway::url() ) : ?>
		<a href="<?php echo esc_url( MMI_Teach\Pathway::url() ); ?>" <?php echo $mmi_teach_on_pathway ? 'aria-current="page"' : ''; ?>><?php esc_html_e( 'Become a substitute', 'mmi-teach' ); ?></a>
	<?php endif; ?>
</nav>
