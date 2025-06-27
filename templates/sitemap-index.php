<?php
/**
 * Template for the sitemap index
 *
 * @package PRC_Schema_Sitemap
 */

declare( strict_types=1 );

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

header( 'Content-Type: application/xml; charset=UTF-8' );
echo '<?xml version="1.0" encoding="UTF-8"?>';
?>
<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
	<sitemap>
		<loc><?php echo esc_url( home_url( '/sitemap-posts.xml' ) ); ?></loc>
		<lastmod><?php echo esc_xml( gmdate( 'c', strtotime( 'now' ) ) ); ?></lastmod>
	</sitemap>
	<sitemap>
		<loc><?php echo esc_url( home_url( '/sitemap-news.xml' ) ); ?></loc>
		<lastmod><?php echo esc_xml( gmdate( 'c', strtotime( 'now' ) ) ); ?></lastmod>
	</sitemap>
	<sitemap>
		<loc><?php echo esc_url( home_url( '/sitemap-taxonomies.xml' ) ); ?></loc>
		<lastmod><?php echo esc_xml( gmdate( 'c', strtotime( 'now' ) ) ); ?></lastmod>
	</sitemap>
	<sitemap>
		<loc><?php echo esc_url( home_url( '/sitemap-rls.xml' ) ); ?></loc>
		<lastmod><?php echo esc_xml( gmdate( 'c', strtotime( 'now' ) ) ); ?></lastmod>
	</sitemap>
</sitemapindex>
