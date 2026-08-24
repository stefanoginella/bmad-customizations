<?php
/**
 * The only template. Self-contained on purpose: this theme is a minimal,
 * activatable shell. Templates and parts arrive with the real build.
 *
 * @package woptimize-theme
 */

defined( 'ABSPATH' ) || exit;
?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<?php wp_head(); ?>
</head>
<body <?php body_class(); ?>>
<?php wp_body_open(); ?>

<header class="site-header">
	<a href="<?php echo esc_url( home_url( '/' ) ); ?>"><?php bloginfo( 'name' ); ?></a>
</header>

<main id="main" class="site-main">
	<?php
	if ( have_posts() ) {
		while ( have_posts() ) {
			the_post();
			?>
			<article <?php post_class(); ?>>
				<h1><?php the_title(); ?></h1>
				<?php the_content(); ?>
			</article>
			<?php
		}
	} else {
		?>
		<p><?php esc_html_e( 'Nothing found.', 'woptimize-theme' ); ?></p>
		<?php
	}
	?>
</main>

<footer class="site-footer">
	<p><?php echo esc_html( gmdate( 'Y' ) ); ?> <?php bloginfo( 'name' ); ?></p>
</footer>

<?php wp_footer(); ?>
</body>
</html>
