<?php
/**
 * Dashboard page template.
 *
 * Uses the active theme's header and footer so the dashboard keeps the site's
 * navigation and branding, while avoiding sidebars and content wrappers that
 * would conflict with the React application layout.
 *
 * A theme may override this by adding penalis-dashboard.php.
 *
 * @package Penalis_Platform
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

get_header();
?>
<div class="penalis-dashboard-shell">
	<?php
	while ( have_posts() ) :
		the_post();
		the_content();
	endwhile;
	?>
</div>
<?php
get_footer();
