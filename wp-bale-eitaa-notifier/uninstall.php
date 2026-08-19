<?php
/**
 * حذف کامل داده‌های افزونه هنگام حذف نهایی.
 *
 * @package Bale_Eitaa_Notifier
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

// حذف تنظیمات.
delete_option( 'bei_options' );

// حذف ترنزینت‌های افزونه.
global $wpdb;
$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	$wpdb->prepare(
		"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
		$wpdb->esc_like( '_transient_bei_' ) . '%',
		$wpdb->esc_like( '_transient_timeout_bei_' ) . '%'
	)
);
