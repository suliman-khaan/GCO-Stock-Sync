<?php
/**
 * Product Matcher — resolves a supplier SKU to a WooCommerce product/variation.
 *
 * @package GCO_Stock_Sync
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class GCO_Stock_Sync_Product_Matcher
 */
class GCO_Stock_Sync_Product_Matcher {

	/**
	 * Find the WooCommerce product (or variation) ID matching a supplier SKU.
	 *
	 * Tries an exact match first, then falls back to a case-insensitive,
	 * whitespace-trimmed match so minor data-entry differences between the
	 * supplier feed and the store's SKUs don't cause silent unmatched rows.
	 *
	 * @param string $sku Raw SKU from the supplier feed.
	 * @return array {
	 *     @type int|null  $product_id Matched product/variation ID, or null.
	 *     @type bool      $normalized Whether the match only succeeded after normalisation.
	 *     @type string    $note       Human-readable note for the log, or ''.
	 * }
	 */
	public function find_by_sku( $sku ) {
		$original = (string) $sku;
		$trimmed  = trim( $original );

		if ( '' === $trimmed ) {
			return array(
				'product_id' => null,
				'normalized' => false,
				'note'       => '',
			);
		}

		$product_id = wc_get_product_id_by_sku( $trimmed );

		if ( $product_id ) {
			$normalized = ( $trimmed !== $original );

			return array(
				'product_id' => (int) $product_id,
				'normalized' => $normalized,
				'note'       => $normalized
					? __( 'SKU matched only after trimming surrounding whitespace.', 'gco-stock-sync' )
					: '',
			);
		}

		return $this->find_by_sku_case_insensitive( $trimmed );
	}

	/**
	 * Fallback lookup: case-insensitive, whitespace-trimmed match against
	 * stored SKUs. Read-only — matches WooCommerce CRUD data via a SELECT
	 * only, never writes product data directly.
	 *
	 * @param string $trimmed Already-trimmed SKU to search for.
	 * @return array Same shape as find_by_sku().
	 */
	private function find_by_sku_case_insensitive( $trimmed ) {
		global $wpdb;

		$matches = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT p.ID FROM {$wpdb->posts} p
				INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = '_sku'
				WHERE p.post_type IN ( 'product', 'product_variation' )
				AND p.post_status != 'trash'
				AND LOWER( TRIM( pm.meta_value ) ) = LOWER( %s )
				ORDER BY p.ID ASC",
				$trimmed
			)
		);

		if ( empty( $matches ) ) {
			return array(
				'product_id' => null,
				'normalized' => false,
				'note'       => '',
			);
		}

		if ( count( $matches ) > 1 ) {
			return array(
				'product_id' => (int) $matches[0],
				'normalized' => true,
				/* translators: 1: SKU, 2: number of matching products */
				'note'       => sprintf(
					__( 'SKU "%1$s" matched %2$d products after case/whitespace normalisation; used the first match. Ask the client to de-duplicate SKUs.', 'gco-stock-sync' ),
					$trimmed,
					count( $matches )
				),
			);
		}

		return array(
			'product_id' => (int) $matches[0],
			'normalized' => true,
			/* translators: %s: SKU */
			'note'       => sprintf(
				__( 'SKU "%s" matched only after case/whitespace normalisation. Consider cleaning up SKU data.', 'gco-stock-sync' ),
				$trimmed
			),
		);
	}
}
