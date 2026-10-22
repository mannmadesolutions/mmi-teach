<?php
/**
 * Top navigation shared by the substitute pathway and the job board. The job board's two tabs are
 * one page with different filters (in the URL hash), so dashboard.js moves aria-current between
 * them as the view changes; the server marks the default view.
 *
 * @package MMI_Teach
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$mmi_teach_on_pathway = MMI_Teach\Pathway::is_pathway();
$mmi_teach_board      = MMI_Teach\Dashboard::url();
?>
<nav class="mt-nav" aria-label="<?php esc_attr_e( 'Site', 'mmi-teach' ); ?>">
	<?php if ( MMI_Teach\Pathway::url() ) : ?>
		<a href="<?php echo esc_url( MMI_Teach\Pathway::url() ); ?>" <?php echo $mmi_teach_on_pathway ? 'aria-current="page"' : ''; ?>><?php esc_html_e( 'Become a substitute', 'mmi-teach' ); ?></a>
	<?php endif; ?>
	<a href="<?php echo esc_url( $mmi_teach_board . '#kind=subs' ); ?>" data-view="subs"><?php esc_html_e( 'Substitute jobs', 'mmi-teach' ); ?></a>
	<a href="<?php echo esc_url( $mmi_teach_board ); ?>" data-view="jobs" <?php echo $mmi_teach_on_pathway ? '' : 'aria-current="page"'; ?>><?php esc_html_e( 'Teaching jobs', 'mmi-teach' ); ?></a>
</nav>
