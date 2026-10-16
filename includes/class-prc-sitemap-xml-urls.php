<?php
/**
 * Extract page URLs from stored daily sitemap XML.
 *
 * @package PRC\Sitemap
 */

declare(strict_types=1);

/**
 * Parses sitemap urlset XML into url and lastmod pairs.
 */
class PRC_Sitemap_Xml_Urls {

	/**
	 * Sitemap protocol namespace used by stored daily XML.
	 *
	 * @var string
	 */
	public const SITEMAP_NS = 'http://www.sitemaps.org/schemas/sitemap/0.9';

	/**
	 * Extract page loc and lastmod entries from a sitemap urlset.
	 *
	 * Image locs in the image namespace are ignored. Returns false when the
	 * string is not valid XML.
	 *
	 * @param string $xml Sitemap XML.
	 * @return array<int, array{url: string, lastmod: string}>|false
	 */
	public static function extract( string $xml ) {
		if ( '' === trim( $xml ) ) {
			return false;
		}

		$previous = libxml_use_internal_errors( true );
		$doc      = simplexml_load_string( $xml );
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );

		if ( false === $doc ) {
			return false;
		}

		$namespaces = $doc->getDocNamespaces( true );
		$sitemap_ns = isset( $namespaces[''] ) ? (string) $namespaces[''] : '';

		$rows = array();
		foreach ( self::url_nodes( $doc, $sitemap_ns ) as $url_node ) {
			$children = '' !== $sitemap_ns ? $url_node->children( $sitemap_ns ) : $url_node->children();
			$loc      = trim( (string) $children->loc );
			if ( '' === $loc ) {
				continue;
			}

			$rows[] = array(
				'url'     => $loc,
				'lastmod' => trim( (string) $children->lastmod ),
			);
		}

		return $rows;
	}

	/**
	 * Collect url elements from a urlset, honoring the default sitemap namespace.
	 *
	 * @param SimpleXMLElement $doc        Parsed urlset.
	 * @param string           $sitemap_ns Default namespace URI, or empty.
	 * @return SimpleXMLElement[]
	 */
	private static function url_nodes( SimpleXMLElement $doc, string $sitemap_ns ): array {
		$children = '' !== $sitemap_ns ? $doc->children( $sitemap_ns ) : $doc->children();
		$nodes    = array();

		foreach ( $children->url as $url_node ) {
			$nodes[] = $url_node;
		}

		return $nodes;
	}
}
