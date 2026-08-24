<?php
/**
 * Theme setup.
 *
 * @package woptimize-theme
 */

defined( 'ABSPATH' ) || exit;

/**
 * Register theme support.
 *
 * @return void
 */
function woptimize_theme_setup() {
	add_theme_support( 'title-tag' );
	add_theme_support( 'post-thumbnails' );
	add_theme_support( 'automatic-feed-links' );
	add_theme_support(
		'html5',
		array( 'search-form', 'comment-form', 'comment-list', 'gallery', 'caption', 'style', 'script' )
	);
}
add_action( 'after_setup_theme', 'woptimize_theme_setup' );

/**
 * Enqueue the theme stylesheet.
 *
 * @return void
 */
function woptimize_theme_enqueue_assets() {
	wp_enqueue_style(
		'woptimize-theme',
		get_stylesheet_uri(),
		array(),
		wp_get_theme()->get( 'Version' )
	);
}
add_action( 'wp_enqueue_scripts', 'woptimize_theme_enqueue_assets' );
