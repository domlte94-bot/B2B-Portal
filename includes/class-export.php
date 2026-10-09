<?php
/**
 * Production sheet: one CSV row per piece to make, per person, with the branch it ships to.
 * Sorted by order and branch, so it doubles as a packing list.
 *
 * @package B2BCP
 */

defined( 'ABSPATH' ) || exit;

/**
 * CSV export from the order screen and the orders list.
 */
class B2BCP_Export {

	/**
	 * Hooks.
	 */
	public static function init() {
		add_action( 'add_meta_boxes', array( __CLASS__, 'meta_box' ) );
		add_action( 'admin_post_b2bcp_production_csv', array( __CLASS__, 'download' ) );
		foreach ( array( 'edit-shop_order', 'woocommerce_page_wc-orders' ) as $screen ) {
			add_filter( 'bulk_actions-' . $screen, array( __CLASS__, 'bulk_action' ) );
			add_filter( 'handle_bulk_actions-' . $screen, array( __CLASS__, 'handle_bulk' ), 10, 3 );
		}
	}

	/**
	 * Box on the order screen (classic storage and HPOS).
	 */
	public static function meta_box() {
		$screens = array( 'shop_order' );
		if ( function_exists( 'wc_get_page_screen_id' ) ) {
			$screens[] = wc_get_page_screen_id( 'shop-order' );
		}
		foreach ( array_unique( $screens ) as $screen ) {
			add_meta_box( 'b2bcp-production', __( 'Production sheet', 'b2b-contract-portal' ), array( __CLASS__, 'box' ), $screen, 'side', 'default' );
		}
	}

	/**
	 * Download button.
	 *
	 * @param WP_Post|WC_Order $post_or_order Order.
	 */
	public static function box( $post_or_order ) {
		$order = $post_or_order instanceof WC_Order ? $post_or_order : wc_get_order( $post_or_order->ID );
		if ( ! $order ) {
			return;
		}
		$url = wp_nonce_url( admin_url( 'admin-post.php?action=b2bcp_production_csv&orders=' . $order->get_id() ), 'b2bcp_production_csv' );
		echo '<p><a class="button" href="' . esc_url( $url ) . '">' . esc_html__( 'Download CSV', 'b2b-contract-portal' ) . '</a></p>';
		echo '<p class="description">' . esc_html__( 'One row per piece and person, with its branch. Opens in Excel or Google Sheets.', 'b2b-contract-portal' ) . '</p>';
	}

	/**
	 * Bulk action on the orders list.
	 *
	 * @param array $actions Actions.
	 * @return array
	 */
	public static function bulk_action( $actions ) {
		$actions['b2bcp_production_csv'] = __( 'Download production sheet (CSV)', 'b2b-contract-portal' );
		return $actions;
	}

	/**
	 * Run the bulk action: redirect to the download with the chosen ids.
	 *
	 * @param string $redirect Redirect URL.
	 * @param string $action   Action.
	 * @param int[]  $ids      Order ids.
	 * @return string
	 */
	public static function handle_bulk( $redirect, $action, $ids ) {
		if ( 'b2bcp_production_csv' !== $action || ! $ids ) {
			return $redirect;
		}
		return wp_nonce_url( admin_url( 'admin-post.php?action=b2bcp_production_csv&orders=' . implode( ',', array_map( 'absint', $ids ) ) ), 'b2bcp_production_csv' );
	}

	/**
	 * Stream the CSV.
	 */
	public static function download() {
		if ( ! current_user_can( 'edit_shop_orders' ) || ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'b2bcp_production_csv' ) ) {
			wp_die( esc_html__( 'You are not allowed to download this.', 'b2b-contract-portal' ), 403 );
		}
		$ids    = array_filter( array_map( 'absint', explode( ',', sanitize_text_field( wp_unslash( $_GET['orders'] ?? '' ) ) ) ) );
		$orders = array_filter( array_map( 'wc_get_order', $ids ) );
		if ( ! $orders ) {
			wp_die( esc_html__( 'No orders selected.', 'b2b-contract-portal' ) );
		}

		list( $header, $rows ) = self::rows( $orders );
		$name = 1 === count( $orders ) ? 'production-order-' . reset( $orders )->get_order_number() . '.csv' : 'production-' . gmdate( 'Y-m-d' ) . '.csv';

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . sanitize_file_name( $name ) . '"' );
		$out = fopen( 'php://output', 'w' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		fwrite( $out, "\xEF\xBB\xBF" ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- UTF-8 BOM so Excel shows accents.
		fputcsv( $out, $header );
		foreach ( $rows as $row ) {
			fputcsv( $out, array_map( array( __CLASS__, 'cell' ), $row ) );
		}
		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		exit;
	}

	/**
	 * Neutralize spreadsheet formulas in user-typed text.
	 *
	 * @param mixed $value Cell.
	 * @return string
	 */
	public static function cell( $value ) {
		$value = (string) $value;
		return ( '' !== $value && in_array( $value[0], array( '=', '+', '-', '@', "\t", "\r" ), true ) && ! is_numeric( $value ) ) ? "'" . $value : $value;
	}

	/**
	 * Build header and rows.
	 *
	 * @param WC_Order[] $orders Orders.
	 * @return array{0:string[],1:array[]}
	 */
	public static function rows( array $orders ) {
		$fixed  = array(
			__( 'Order', 'b2b-contract-portal' ),
			__( 'Date', 'b2b-contract-portal' ),
			__( 'Status', 'b2b-contract-portal' ),
			__( 'Company', 'b2b-contract-portal' ),
			__( 'Ship to', 'b2b-contract-portal' ),
			__( 'Address', 'b2b-contract-portal' ),
			__( 'Kit', 'b2b-contract-portal' ),
			__( 'Product', 'b2b-contract-portal' ),
			__( 'SKU', 'b2b-contract-portal' ),
			__( 'Qty', 'b2b-contract-portal' ),
			__( 'Person #', 'b2b-contract-portal' ),
		);
		$labels = array(); // Field key => label, in order of first appearance.
		$rows   = array();

		foreach ( $orders as $order ) {
			$default_to   = trim( $order->get_formatted_shipping_full_name() );
			$default_addr = preg_replace( '/\s*<br\s*\/?>\s*/i', ', ', (string) $order->get_formatted_shipping_address() );
			if ( '' === trim( wp_strip_all_tags( $default_addr ) ) ) {
				$default_to   = trim( $order->get_formatted_billing_full_name() );
				$default_addr = preg_replace( '/\s*<br\s*\/?>\s*/i', ', ', (string) $order->get_formatted_billing_address() );
			}
			$base = array(
				'order'   => $order->get_order_number(),
				'date'    => $order->get_date_created() ? $order->get_date_created()->date_i18n( 'Y-m-d' ) : '',
				'status'  => wc_get_order_status_name( $order->get_status() ),
				'company' => (string) $order->get_meta( '_b2bcp_company_name' ),
			);

			foreach ( $order->get_items() as $item ) {
				if ( ! $item instanceof WC_Order_Item_Product ) {
					continue;
				}
				$data    = $item->get_meta( '_b2bcp' );
				$data    = is_array( $data ) ? $data : array();
				$product = $item->get_product();

				if ( ! empty( $data['address'] ) ) {
					$ship_to   = b2bcp_address_label( $data['address'] );
					$ship_addr = b2bcp_address_line( $data['address'] );
				} else {
					$ship_to   = $default_to;
					$ship_addr = wp_strip_all_tags( $default_addr );
				}

				foreach ( (array) ( $data['labels'] ?? array() ) as $key => $label ) {
					if ( ! isset( $labels[ $key ] ) ) {
						$labels[ $key ] = $label;
					}
				}

				// What gets produced for this line.
				if ( ! empty( $data['kit_full'] ) && ! empty( $data['components'] ) ) {
					$kit_name   = $item->get_name();
					$components = $data['components'];
				} else {
					$kit_name   = '';
					if ( ! empty( $data['kit_id'] ) ) {
						$kit      = wc_get_product( (int) $data['kit_id'] );
						$kit_name = $kit ? $kit->get_name() : '';
					}
					$components = array(
						array(
							'name' => $item->get_name(),
							'sku'  => $product ? $product->get_sku() : '',
							'qty'  => max( 1, (int) ( $data['per_person'] ?? 1 ) ),
							'keys' => array_keys( (array) ( $data['labels'] ?? array() ) ),
						),
					);
				}

				$common = $base + array(
					'ship_to'   => $ship_to,
					'ship_addr' => $ship_addr,
					'kit'       => $kit_name,
				);

				if ( ! empty( $data['people'] ) ) {
					foreach ( $data['people'] as $n => $person ) {
						foreach ( $components as $component ) {
							$values = array();
							foreach ( (array) $component['keys'] as $key ) {
								$values[ $key ] = $person[ $key ] ?? '';
							}
							$rows[] = $common + array(
								'product' => $component['name'],
								'sku'     => $component['sku'],
								'qty'     => (int) $component['qty'],
								'person'  => $n + 1,
								'fields'  => $values,
							);
						}
					}
				} else {
					$units = (int) $item->get_quantity();
					foreach ( $components as $component ) {
						$per    = ! empty( $data['kit_full'] ) ? (int) $component['qty'] : 1;
						$rows[] = $common + array(
							'product' => $component['name'],
							'sku'     => $component['sku'],
							'qty'     => $units * $per,
							'person'  => '',
							'fields'  => array(),
						);
					}
				}
			}
		}

		$header = array_merge( $fixed, array_values( $labels ) );
		$out    = array();
		foreach ( $rows as $row ) {
			$line = array( $row['order'], $row['date'], $row['status'], $row['company'], $row['ship_to'], $row['ship_addr'], $row['kit'], $row['product'], $row['sku'], $row['qty'], $row['person'] );
			foreach ( array_keys( $labels ) as $key ) {
				$line[] = $row['fields'][ $key ] ?? '';
			}
			$out[] = $line;
		}

		// Group by order, then branch, keeping the original order inside each group.
		$index = array_keys( $out );
		usort(
			$index,
			function ( $a, $b ) use ( $out ) {
				return array( $out[ $a ][0], $out[ $a ][4], $a ) <=> array( $out[ $b ][0], $out[ $b ][4], $b );
			}
		);
		$sorted = array();
		foreach ( $index as $i ) {
			$sorted[] = $out[ $i ];
		}
		return array( $header, $sorted );
	}
}
