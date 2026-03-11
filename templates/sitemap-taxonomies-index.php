<?php
/**
 * Template for generating taxonomy sitemap index
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

$cache_key = 'prc_sitemap_taxonomy_index_xml';
$xml       = PRC_Sitemap_Cache::get_cached_data(
	$cache_key,
	function () {
		$supported_taxonomies = apply_filters( 'prc_sitemap_supported_taxonomies', array( 'category' ) );
		$max_entries          = 1000;

		ob_start();
		echo '<?xml version="1.0" encoding="UTF-8"?>' . PHP_EOL;
		echo '<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . PHP_EOL;

		foreach ( $supported_taxonomies as $taxonomy ) {
			$term_count_key = "prc_sitemap_term_count_{$taxonomy}";
			$term_count     = PRC_Sitemap_Cache::get_cached_data(
				$term_count_key,
				function () use ( $taxonomy ) {
					$count = wp_count_terms(
						array(
							'taxonomy'   => $taxonomy,
							'hide_empty' => true,
						)
					);
					return is_wp_error( $count ) ? 0 : $count;
				}
			);

			if ( empty( $term_count ) ) {
				continue;
			}

			$max_pages = ceil( $term_count / $max_entries );

			for ( $page = 1; $page <= $max_pages; $page++ ) {
				$url = home_url( '/sitemap-terms-' . $taxonomy . '.xml' );
				if ( $page > 1 ) {
					$url = add_query_arg(
						array(
							'paged' => $page,
						),
						$url,
					);
				}

				echo "\t<sitemap>\n";
				echo "\t\t<loc>" . esc_url( $url ) . "</loc>\n";
				$last_mod = PRC_Sitemap::get_taxonomy_last_modified_max( $taxonomy );
				if ( $last_mod ) {
					echo "\t\t<lastmod>" . esc_xml( mysql2date( 'c', $last_mod ) ) . "</lastmod>\n";
				}
				echo "\t</sitemap>\n";
			}
		}

		echo '</sitemapindex>';

		return ob_get_clean();
	}
);

header( 'Content-Type: application/xml; charset=UTF-8' );
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- XML is pre-escaped during generation.
echo $xml;
