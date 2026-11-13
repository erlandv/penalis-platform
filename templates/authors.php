<?php
/**
 * Public authors directory template.
 *
 * Uses the active theme's header and footer so the directory keeps the site's
 * navigation and branding, while avoiding sidebars that would conflict with the
 * React grid layout.
 *
 * A theme may override this by adding penalis-authors.php.
 *
 * @package Penalis_Platform
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

get_header();
?>
<div class="penalis-authors-shell">
	<?php
	while ( have_posts() ) :
		the_post();
		the_content();
	endwhile;
	?>
</div>
<?php
get_footer();
