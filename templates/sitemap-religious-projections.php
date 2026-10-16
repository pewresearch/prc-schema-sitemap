<?php
/**
 * Template for the Religious Projections sitemap
 *
 * @package prc-sitemap
 */

if ( ! PRC_Sitemap::is_blog_public() ) {
	wp_die(
		esc_html__( 'Sorry, this site is not public so sitemaps are not available.', 'prc-sitemap' ),
		esc_html__( 'Religious Projections Sitemap Not Available', 'prc-sitemap' ),
		array( 'response' => 404 )
	);
}

// Check if the Religious Projections plugin is available.
if ( ! class_exists( 'PRC\Platform\Religious_Projections\Sitemap_Provider' ) ) {
	wp_die(
		esc_html__( 'Religious Projections Sitemap Provider not available.', 'prc-sitemap' ),
		esc_html__( 'Religious Projections Sitemap Not Available', 'prc-sitemap' ),
		array( 'response' => 404 )
	);
}

// Get data from the Religious Projections sitemap provider.
$projections_sitemap      = new PRC\Platform\Religious_Projections\Sitemap_Provider();
$projections_sitemap_data = $projections_sitemap->get_entries();

if ( empty( $projections_sitemap_data ) ) {
	wp_die(
		esc_html__( 'No Religious Projections sitemap data available.', 'prc-sitemap' ),
		esc_html__( 'Religious Projections Sitemap Not Available', 'prc-sitemap' ),
		array( 'response' => 404 )
	);
}

header( 'Content-Type: application/xml; charset=UTF-8' );

echo '<?xml version="1.0" encoding="UTF-8"?>' . PHP_EOL;
echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xsi:schemaLocation="http://www.sitemaps.org/schemas/sitemap/0.9 http://www.sitemaps.org/schemas/sitemap/0.9/sitemap.xsd">' . PHP_EOL;

// Build the sitemap items using the Religious Projections provider's build_items method.
$items_output = $projections_sitemap->build_items( $projections_sitemap_data );

echo $items_output; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in build_items().
echo '</urlset>';
