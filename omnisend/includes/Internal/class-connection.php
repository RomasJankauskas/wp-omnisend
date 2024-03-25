<?php
/**
 * Omnisend plugin
 *
 * @package OmnisendPlugin
 */

namespace Omnisend\Internal;

use Omnisend_Core_Bootstrap;

defined( 'ABSPATH' ) || die( 'no direct access' );

class Connection {

	public static function display(): void {
		Options::set_landing_page_visited();

		$connected = Options::is_store_connected();

		if ( $connected ) {

			add_action(
				'admin_enqueue_scripts',
				function ( $suffix ) {
					$asset_file_page = plugin_dir_path( __FILE__ ) . 'build/connected.asset.php';
					if ( file_exists( $asset_file_page ) && 'toplevel_page_omnisend' === $suffix ) {
						$assets = require_once $asset_file_page;
						wp_enqueue_script(
							'connected-script',
							plugin_dir_url( __FILE__ ) . 'build/connected.js',
							$assets['dependencies'],
							$assets['version'],
							true
						);
						foreach ( $assets['dependencies'] as $style ) {
							wp_enqueue_style( $style );
						}
					}
				}
			);

			?>
			<div id="omnisend-connected"></div>
			<?php
			return;
		}

		if ( ! empty( $_GET['action'] ) && 'show_connection_form' == $_GET['action'] ) {

			add_action(
				'admin_enqueue_scripts',
				function ( $suffix ) {
					$asset_file_page = plugin_dir_path( __FILE__ ) . 'build/appMarket.asset.php';
					if ( file_exists( $asset_file_page ) && 'omnisend_page_omnisend-app-market' === $suffix ) {
						$assets = require_once $asset_file_page;
						wp_enqueue_script(
							'omnisend-app-market-script',
							plugin_dir_url( __FILE__ ) . 'build/appMarket.js',
							$assets['dependencies'],
							$assets['version'],
							true
						);
						foreach ( $assets['dependencies'] as $style ) {
							wp_enqueue_style( $style );
						}
					}
				}
			);

			?>
				<div id="omnisend-connection"></div>
			<?php
			return;
		}

		require_once __DIR__ . '/../../view/landing-page.html';
	}

	private static function get_account_data( $api_key ): array {
		$response = wp_remote_get(
			OMNISEND_CORE_API_V3 . '/accounts',
			array(
				'headers' => array(
					'Content-Type' => 'application/json',
					'X-API-Key'    => $api_key,
				),
				'timeout' => 10,
			)
		);

		$body = wp_remote_retrieve_body( $response );
		if ( ! $body ) {
			return '';
		}

		$arr = json_decode( $body, true );

		return is_array( $arr ) ? $arr : array();
	}

	private static function connect_store( $api_key ): bool {
		$data = array(
			'website'         => site_url(),
			'platform'        => 'wordpress',
			'version'         => OMNISEND_CORE_PLUGIN_VERSION,
			'phpVersion'      => phpversion(),
			'platformVersion' => get_bloginfo( 'version' ),
		);

		$response = wp_remote_post(
			OMNISEND_CORE_API_V3 . '/accounts',
			array(
				'body'    => wp_json_encode( $data ),
				'headers' => array(
					'Content-Type' => 'application/json',
					'X-API-Key'    => $api_key,
				),
				'timeout' => 10,
			)
		);

		if ( is_wp_error( $response ) ) {
			return false;
		}

		$http_code = wp_remote_retrieve_response_code( $response );
		if ( $http_code >= 400 ) {
			return false;
		}

		$body = wp_remote_retrieve_body( $response );
		if ( ! $body ) {
			return false;
		}

		$arr = json_decode( $body, true );

		return ! empty( $arr['verified'] );
	}

	public static function connect_with_omnisend_for_woo_plugin(): void {
		if ( Options::is_connected() ) {
			return; // Already connected.
		}

		if ( ! Omnisend_Core_Bootstrap::is_omnisend_woocommerce_plugin_active() ) {
			return;
		}

		$api_key = get_option( OMNISEND_CORE_WOOCOMMERCE_PLUGIN_API_KEY_OPTION );
		if ( ! $api_key ) {
			return;
		}

		$response = self::get_account_data( $api_key );
		if ( ! $response['brandID'] ) {
			return;
		}

		Options::set_api_key( $api_key );
		Options::set_brand_id( $response['brandID'] );
		Options::set_store_connected();
	}

	public static function omnisend_post_connection() {
		$connected = Options::is_store_connected();

		// phpcs:ignore WordPress.WP.CapitalPDangit.MisspelledInText
		$wordpress_platform = 'wordpress'; // WordPress is lowercase as it's required by integration

		if ( empty( $_POST['api_key'] ) ) {
			return rest_ensure_response(
				array(
					'success' => false,
					'error'   => 'API key is required.',
				)
			);
		}

		if ( ! $connected && ! empty( $_POST['api_key'] ) ) {
			$api_key  = sanitize_text_field( wp_unslash( $_POST['api_key'] ) );
			$response = self::get_account_data( $api_key );
			$brand_id = ! empty( $response['brandID'] ) ? $response['brandID'] : '';

			if ( ! $brand_id ) {
				return rest_ensure_response(
					array(
						'success' => false,
						'error'   => 'The connection didn’t go through. Check if the API key is correct.',
					)
				);
			}

			if ( $response['verified'] === true && $response['platform'] !== $wordpress_platform ) {
				return rest_ensure_response(
					array(
						'success' => false,
						'error'   => 'This Omnisend account is already connected to non-WordPress site. Log in to access it.',
					)
				);
			}

			$connected = false;
			if ( $response['platform'] === $wordpress_platform ) {
				$connected = true;
			}

			if ( $response['platform'] === '' ) {
				$connected = self::connect_store( $api_key );
			}

			if ( $connected ) {
				Options::set_api_key( $api_key );
				Options::set_brand_id( $brand_id );
				Options::set_store_connected();

				if ( ! wp_next_scheduled( OMNISEND_CORE_CRON_SYNC_CONTACT ) && ! Omnisend_Core_Bootstrap::is_omnisend_woocommerce_plugin_connected() ) {
					wp_schedule_event( time(), OMNISEND_CORE_CRON_SCHEDULE_EVERY_MINUTE, OMNISEND_CORE_CRON_SYNC_CONTACT );
				}
				return rest_ensure_response(
					array(
						'success' => true,
						'error'   => '',
					)
				);
			}

			if ( ! $connected ) {
				Options::disconnect(); // Store was not connected, clean up.
				return rest_ensure_response(
					array(
						'success' => false,
						'error'   => 'The connection didn’t go through. Check if the API key is correct.',
					)
				);
			}
		}

		return rest_ensure_response(
			array(
				'success' => false,
				'error'   => 'Something went wrong. Please try again.',
			)
		);
	}
}
