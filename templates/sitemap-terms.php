<?php
/**
 * Template for generating taxonomy sitemap
 *
 * @package prc-sitemap
 */

if ( ! PRC_Sitemap::is_blog_public() ) {
	wp_die(
		esc_html__( 'Sorry, this site is not public so sitemaps are not available.', 'prc-sitemap' ),
		esc_html__( 'Taxonomy Sitemap Not Available', 'prc-sitemap' ),
		array( 'response' => 404 )
	);
}

if ( ! class_exists( 'WP_Sitemaps_Taxonomies' ) ) {
	wp_die(
		esc_html__( 'WordPress Sitemap functionality is not available.', 'prc-sitemap' ),
		esc_html__( 'Sitemap Error', 'prc-sitemap' ),
		array( 'response' => 500 )
	);
}

$requested_taxonomy = get_query_var( 'sitemap-taxonomy' );
$current_page       = max( 1, absint( get_query_var( 'paged', 1 ) ) );

if ( empty( $requested_taxonomy ) ) {
	wp_die(
		esc_html__( 'No taxonomy requested.', 'prc-sitemap' ),
		esc_html__( 'Taxonomy Sitemap Not Available', 'prc-sitemap' ),
		array( 'response' => 404 )
	);
}

$cache_key = "prc_sitemap_terms_xml_{$requested_taxonomy}_{$current_page}";
$xml       = PRC_Sitemap_Cache::get_cached_data(
	$cache_key,
	function () use ( $requested_taxonomy, $current_page ) {
		$taxonomy_provider = new WP_Sitemaps_Taxonomies();
		$url_list          = $taxonomy_provider->get_url_list( $current_page, $requested_taxonomy );

		if ( empty( $url_list ) ) {
			return false;
		}

		ob_start();
		echo '<?xml version="1.0" encoding="UTF-8"?>' . PHP_EOL;
		echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . PHP_EOL;

		foreach ( $url_list as $url_entry ) {
			echo "\t<url>\n";
			echo "\t\t<loc>" . esc_url( $url_entry['loc'] ) . "</loc>\n";

			$url_parts  = wp_parse_url( $url_entry['loc'] );
			$path_parts = explode( '/', trim( $url_parts['path'], '/' ) );
			$slug       = end( $path_parts );

			if ( $slug ) {
				$term = get_term_by( 'slug', $slug, $requested_taxonomy );
				if ( $term && ! is_wp_error( $term ) ) {
					$last_modified = PRC_Sitemap::get_taxonomy_last_modified_max( $term->term_id, $requested_taxonomy );
					if ( $last_modified ) {
						echo "\t\t<lastmod>" . esc_xml( mysql2date( 'c', $last_modified ) ) . "</lastmod>\n";
					}
				}
			}

			echo "\t</url>\n";
		}

		echo '</urlset>';

		return ob_get_clean();
	}
);

if ( false === $xml ) {
	wp_die(
		esc_html__( 'No terms found to include in sitemap.', 'prc-sitemap' ),
		esc_html__( 'Empty Sitemap', 'prc-sitemap' ),
		array( 'response' => 404 )
	);
}

header( 'Content-Type: application/xml; charset=UTF-8' );
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- XML is pre-escaped during generation.
echo $xml;
