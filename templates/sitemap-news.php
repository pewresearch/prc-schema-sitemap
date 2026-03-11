<?php
/**
 * Template for generating Google News sitemap
 *
 * @package prc-sitemap
 */

if ( ! PRC_Sitemap::is_blog_public() ) {
	wp_die(
		esc_html__( 'Sorry, this site is not public so sitemaps are not available.', 'prc-sitemap' ),
		esc_html__( 'News Sitemap Not Available', 'prc-sitemap' ),
		array( 'response' => 404 )
	);
}

$sitemap_dates = array(
	gmdate( 'Y-m-d', time() ),
	gmdate( 'Y-m-d', time() - DAY_IN_SECONDS ),
);
$date_hash     = md5( implode( '_', $sitemap_dates ) );
$cache_key     = "prc_sitemap_news_xml_{$date_hash}";

$xml = PRC_Sitemap_Cache::get_cached_data(
	$cache_key,
	function () use ( $sitemap_dates ) {
		ob_start();
		echo '<?xml version="1.0" encoding="UTF-8"?>' . PHP_EOL;
		echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:news="http://www.google.com/schemas/sitemap-news/0.9" xmlns:image="http://www.google.com/schemas/sitemap-image/1.1">' . PHP_EOL;

		foreach ( $sitemap_dates as $current_date ) {
			list( $sitemap_year, $sitemap_month, $sitemap_day ) = explode( '-', $current_date );

			$sitemap_xml = PRC_Sitemap::build_individual_sitemap_xml( $sitemap_year, $sitemap_month, $sitemap_day );

			if ( $sitemap_xml ) {
				$xml = new SimpleXMLElement( $sitemap_xml );

				foreach ( $xml->url as $url ) {
					$current_url = (string) $url->loc;

					if ( function_exists( 'wpcom_vip_url_to_postid' ) ) {
						$current_post_id = \wpcom_vip_url_to_postid( $current_url );
					} else {
						$current_post_id = url_to_postid( $current_url );
					}
					if ( ! $current_post_id ) {
						continue;
					}

					$current_post = get_post( $current_post_id );
					if ( ! $current_post || 'publish' !== $current_post->post_status ) {
						continue;
					}

					if ( ! in_array( $current_post->post_type, PRC_Sitemap::get_supported_post_types(), true ) ) {
						continue;
					}

					echo "\t<url>\n";
					echo "\t\t<loc>" . esc_url( $current_url ) . "</loc>\n";

					$thumbnail_url = get_the_post_thumbnail_url( $current_post_id, 'large' );
					if ( $thumbnail_url ) {
						echo "\t\t<image:image>\n";
						echo "\t\t\t<image:loc>" . esc_url( $thumbnail_url ) . "</image:loc>\n";
						echo "\t\t</image:image>\n";
					}

					echo "\t\t<news:news>\n";
					echo "\t\t\t<news:publication>\n";
					echo "\t\t\t\t<news:name>" . esc_xml( get_bloginfo( 'name' ) ) . "</news:name>\n";
					echo "\t\t\t\t<news:language>en</news:language>\n";
					echo "\t\t\t</news:publication>\n";
					echo "\t\t\t<news:publication_date>" . esc_xml( get_the_date( 'c', $current_post ) ) . "</news:publication_date>\n";
					echo "\t\t\t<news:title>" . esc_xml( get_the_title( $current_post ) ) . "</news:title>\n";
					echo "\t\t</news:news>\n";
					echo "\t</url>\n";
				}
			}
		}

		echo '</urlset>';

		return ob_get_clean();
	},
	'short'
);

header( 'Content-Type: application/xml; charset=UTF-8' );
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- XML is pre-escaped during generation.
echo $xml;
