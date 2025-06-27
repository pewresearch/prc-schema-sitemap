<?php
/**
 * Template for the RLS sitemap
 *
 * @package prc-sitemap
 */

if ( ! PRC_Sitemap::is_blog_public() ) {
	wp_die(
		esc_html__( 'Sorry, this site is not public so sitemaps are not available.', 'prc-sitemap' ),
		esc_html__( 'RLS Sitemap Not Available', 'prc-sitemap' ),
		array( 'response' => 404 )
	);
}

// Check if the RLS plugin is available.
if ( ! class_exists( 'PRC\Platform\RLS\Sitemap_Provider' ) ) {
	wp_die(
		esc_html__( 'RLS Sitemap Provider not available.', 'prc-sitemap' ),
		esc_html__( 'RLS Sitemap Not Available', 'prc-sitemap' ),
		array( 'response' => 404 )
	);
}

// Get data from the RLS sitemap provider
$rls_sitemap = new PRC\Platform\RLS\Sitemap_Provider();
$rls_sitemap_data = $rls_sitemap->get_entries();

if ( empty( $rls_sitemap_data ) ) {
	wp_die(
		esc_html__( 'No RLS sitemap data available.', 'prc-sitemap' ),
		esc_html__( 'RLS Sitemap Not Available', 'prc-sitemap' ),
		array( 'response' => 404 )
	);
}

header( 'Content-Type: application/xml; charset=UTF-8' );

echo '<?xml version="1.0" encoding="UTF-8"?>' . PHP_EOL;
echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xsi:schemaLocation="http://www.sitemaps.org/schemas/sitemap/0.9 http://www.sitemaps.org/schemas/sitemap/0.9/sitemap.xsd">' . PHP_EOL;

// Build the sitemap items using the RLS provider's build_items method
$items_output = $rls_sitemap->build_items( $rls_sitemap_data );

echo $items_output;
echo '</urlset>';

