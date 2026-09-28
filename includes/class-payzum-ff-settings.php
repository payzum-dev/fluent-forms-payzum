<?php
/**
 * Global settings for the Payzum method: storage, encryption and the admin
 * schema rendered by Fluent Forms' payment settings tab.
 *
 * Settings live in the option Fluent Forms itself writes on save
 * (fluentform_payment_settings_payzum); the save filter hands it the encrypted
 * array, mirroring how the bundled Stripe method protects its secret keys.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use FluentForm\App\Modules\Payments\PaymentHelper;

class Payzum_FF_Settings {

	const OPTION = 'fluentform_payment_settings_payzum';

	public static function getSettings( $decrypted = true ) {
		$defaults = array(
			'is_active'      => 'no',
			'api_key'        => '',
			'webhook_secret' => '',
			'environment'    => 'production',
			'pay_currency'   => 'all',
			'debug'          => 'no',
			'is_encrypted'   => 'no',
		);

		$settings = wp_parse_args( get_option( self::OPTION, array() ), $defaults );

		if ( $decrypted && 'yes' === $settings['is_encrypted'] ) {
			foreach ( array( 'api_key', 'webhook_secret' ) as $key ) {
				if ( '' !== $settings[ $key ] ) {
					$plain = PaymentHelper::decryptKey( $settings[ $key ] );
					// A decrypt failure (salts changed) reads as empty rather
					// than as a garbage key sent to the API.
					$settings[ $key ] = false === $plain ? '' : $plain;
				}
			}
		}

		return $settings;
	}

	public static function encryptForStorage( $settings ) {
		foreach ( array( 'api_key', 'webhook_secret' ) as $key ) {
			$value = isset( $settings[ $key ] ) ? trim( (string) $settings[ $key ] ) : '';
			$settings[ $key ] = '' === $value ? '' : PaymentHelper::encryptKey( $value );
		}
		$settings['is_encrypted'] = 'yes';
		return $settings;
	}

	public static function isActive() {
		$settings = self::getSettings( false );
		return 'yes' === $settings['is_active'];
	}

	public static function ipnUrl() {
		return add_query_arg(
			array(
				'fluentform_payment_api_notify' => 1,
				'payment_method'                => 'payzum',
			),
			site_url( 'index.php' )
		);
	}

	/** The admin tab schema for fluentform/payment_methods_global_settings. */
	public static function globalFields() {
		return array(
			'label'  => __( 'Payzum', 'payzum-for-fluent-forms' ),
			'fields' => array(
				array(
					'settings_key'   => 'is_active',
					'type'           => 'yes-no-checkbox',
					'label'          => __( 'Status', 'payzum-for-fluent-forms' ),
					'checkbox_label' => __( 'Enable Payzum (crypto & stablecoin payments — USDC, USDT and more)', 'payzum-for-fluent-forms' ),
				),
				array(
					'settings_key' => 'api_key',
					'type'         => 'input-text',
					'data_type'    => 'password',
					'label'        => __( 'API key', 'payzum-for-fluent-forms' ),
					'info_help'    => __( 'From Dashboard → Settings → API Keys at merchant.payzum.com. The sandbox environment needs its own key.', 'payzum-for-fluent-forms' ),
				),
				array(
					'settings_key' => 'webhook_secret',
					'type'         => 'input-text',
					'data_type'    => 'password',
					'label'        => __( 'Webhook secret', 'payzum-for-fluent-forms' ),
					'info_help'    => __( 'Shown once, at merchant creation or rotation. Verifies the signature of payment notifications — submissions are marked as paid from those notifications, not from the buyer\'s redirect.', 'payzum-for-fluent-forms' ),
				),
				array(
					'settings_key' => 'environment',
					'type'         => 'input-radio',
					'label'        => __( 'Environment', 'payzum-for-fluent-forms' ),
					'options'      => array(
						array(
							'value' => 'production',
							'label' => __( 'Production', 'payzum-for-fluent-forms' ),
						),
						array(
							'value' => 'staging',
							'label' => __( 'Sandbox (staging.payzum.com, separate API keys)', 'payzum-for-fluent-forms' ),
						),
					),
				),
				array(
					'settings_key' => 'pay_currency',
					'type'         => 'input-text',
					'label'        => __( 'Pay currency', 'payzum-for-fluent-forms' ),
					'info_help'    => __( '"all" lets the buyer pick the asset and network on the hosted checkout page (limited to your merchant allowlist). Alternatively a specific code like "usdcmatic".', 'payzum-for-fluent-forms' ),
				),
				array(
					'settings_key'   => 'debug',
					'type'           => 'yes-no-checkbox',
					'label'          => __( 'Debug log', 'payzum-for-fluent-forms' ),
					'checkbox_label' => __( 'Log gateway events to the WordPress debug log, prefixed [payzum-ff].', 'payzum-for-fluent-forms' ),
				),
			),
		);
	}

	public static function log( $message ) {
		$settings = self::getSettings( false );
		if ( 'yes' !== $settings['debug'] ) {
			return;
		}
		error_log( '[payzum-ff] ' . $message ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
	}
}
