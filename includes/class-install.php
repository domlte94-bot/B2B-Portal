<?php
/**
 * Database tables and upgrades.
 *
 * @package B2BCP
 */

defined( 'ABSPATH' ) || exit;

/**
 * Creates and upgrades the plugin's tables.
 */
class B2BCP_Install {

	const DB_VERSION = '1';

	/**
	 * Activation hook.
	 */
	public static function activate() {
		self::create_tables();
		update_option( 'b2bcp_db_version', self::DB_VERSION );
		// The My Account endpoint is registered on init; flush rules on the next request.
		update_option( 'b2bcp_flush_rewrites', 'yes' );
	}

	/**
	 * Run table creation when the stored version is behind.
	 */
	public static function maybe_upgrade() {
		if ( get_option( 'b2bcp_db_version' ) !== self::DB_VERSION ) {
			self::create_tables();
			update_option( 'b2bcp_db_version', self::DB_VERSION );
		}
	}

	/**
	 * Price and address tables.
	 */
	public static function create_tables() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset = $wpdb->get_charset_collate();

		$prices = "CREATE TABLE {$wpdb->prefix}b2bcp_prices (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			company_id bigint(20) unsigned NOT NULL,
			product_id bigint(20) unsigned NOT NULL,
			price decimal(19,4) NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY company_product (company_id,product_id),
			KEY product_id (product_id)
		) $charset;";

		$addresses = "CREATE TABLE {$wpdb->prefix}b2bcp_addresses (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			company_id bigint(20) unsigned NOT NULL,
			label varchar(190) NOT NULL DEFAULT '',
			code varchar(60) NOT NULL DEFAULT '',
			attention varchar(190) NOT NULL DEFAULT '',
			address_1 varchar(255) NOT NULL DEFAULT '',
			address_2 varchar(255) NOT NULL DEFAULT '',
			city varchar(120) NOT NULL DEFAULT '',
			state varchar(120) NOT NULL DEFAULT '',
			postcode varchar(40) NOT NULL DEFAULT '',
			country varchar(2) NOT NULL DEFAULT '',
			phone varchar(60) NOT NULL DEFAULT '',
			PRIMARY KEY  (id),
			KEY company_id (company_id)
		) $charset;";

		dbDelta( $prices );
		dbDelta( $addresses );
	}
}
