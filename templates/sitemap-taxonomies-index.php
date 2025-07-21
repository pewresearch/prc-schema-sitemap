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

// Initialize WP_Sitemaps_Taxonomies for supported taxonomies.
if ( ! class_exists( 'WP_Sitemaps_Taxonomies' ) ) {
	wp_die(
		esc_html__( 'WordPress Sitemap functionality is not available.', 'prc-sitemap' ),
		esc_html__( 'Sitemap Error', 'prc-sitemap' ),
		array( 'response' => 500 )
	);
}

$supported_taxonomies = apply_filters( 'prc_sitemap_supported_taxonomies', array( 'category' ) );
$taxonomy_provider    = new WP_Sitemaps_Taxonomies();
$max_entries          = 1000; // Standard sitemap limit.

header( 'Content-Type: application/xml; charset=UTF-8' );
echo '<?xml version="1.0" encoding="UTF-8"?>' . PHP_EOL;
?>
<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
<?php
foreach ( $supported_taxonomies as $taxonomy ) {
	// Cache term count for performance
	$cache_key = "prc_sitemap_term_count_{$taxonomy}";
	$term_count = get_transient( $cache_key );
	
	if ( false === $term_count ) {
		$term_count = wp_count_terms(
			array(
				'taxonomy'   => $taxonomy,
				'hide_empty' => true,
			)
		);
		
		// Cache for 12 hours (standard duration for sitemap data)
		if ( ! is_wp_error( $term_count ) ) {
			set_transient( $cache_key, $term_count, 43200 ); // 12 hours
		}
	}

	if ( is_wp_error( $term_count ) || empty( $term_count ) ) {
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
		// Get the most recent modification time for this taxonomy's terms.
		$last_mod = PRC_Sitemap::get_taxonomy_last_modified_max( $taxonomy );
		if ( $last_mod ) {
			echo "\t\t<lastmod>" . esc_xml( mysql2date( 'c', $last_mod ) ) . "</lastmod>\n";
		}
		echo "\t</sitemap>\n";
	}
}
?>
</sitemapindex>
