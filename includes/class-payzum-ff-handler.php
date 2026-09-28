<?php
/**
 * Wires the Payzum method into Fluent Forms' payment hooks: global settings
 * tab, form-level method entry, the payment processor and the IPN endpoint.
 * Mirrors the shape of the bundled StripeHandler.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use FluentForm\Framework\Helpers\ArrayHelper;

class Payzum_FF_Handler {

	protected $key = 'payzum';

	public function init() {
		add_filter( 'fluentform/payment_methods_global_settings', array( $this, 'addGlobalSettings' ) );
		add_filter( 'fluentform/payment_settings_' . $this->key, array( $this, 'maskedSettings' ) );
		add_filter( 'fluentform/payment_method_settings_validation_' . $this->key, array( $this, 'validateSettings' ), 10, 2 );
		add_filter( 'fluentform/payment_method_settings_save_' . $this->key, array( $this, 'sanitizeGlobalSettings' ) );

		if ( ! Payzum_FF_Settings::isActive() ) {
			return;
		}

		add_filter( 'fluentform/available_payment_methods', array( $this, 'pushPaymentMethodToForm' ) );
		add_filter( 'fluentform/transaction_data_' . $this->key, array( $this, 'modifyTransaction' ) );

		$processor = new Payzum_FF_Processor();
		$processor->init();

		add_action( 'fluentform/ipn_endpoint_' . $this->key, array( $processor, 'handleIpn' ) );
	}

	public function addGlobalSettings( $methods ) {
		$methods[ $this->key ] = Payzum_FF_Settings::globalFields();
		return $methods;
	}

	/** Settings for the admin UI — secrets masked, never sent to the browser. */
	public function maskedSettings() {
		$settings = Payzum_FF_Settings::getSettings( false );
		foreach ( array( 'api_key', 'webhook_secret' ) as $key ) {
			if ( '' !== $settings[ $key ] ) {
				$settings[ $key ] = 'ENCRYPTED_KEY';
			}
		}
		return $settings;
	}

	public function validateSettings( $errors, $settings ) {
		if ( ArrayHelper::get( $settings, 'is_active' ) !== 'yes' ) {
			return array();
		}
		if ( in_array( ArrayHelper::get( $settings, 'api_key' ), array( '', null ), true ) ) {
			$errors['api_key'] = __( 'The API key is required', 'payzum-for-fluent-forms' );
		}
		if ( in_array( ArrayHelper::get( $settings, 'webhook_secret' ), array( '', null ), true ) ) {
			$errors['webhook_secret'] = __( 'The webhook secret is required — payments are confirmed from signed notifications', 'payzum-for-fluent-forms' );
		}
		return $errors;
	}

	/** Runs before Fluent Forms persists the option: re-attach masked secrets, encrypt. */
	public function sanitizeGlobalSettings( $settings ) {
		$stored = Payzum_FF_Settings::getSettings( true );

		foreach ( array( 'api_key', 'webhook_secret' ) as $key ) {
			if ( ArrayHelper::get( $settings, $key ) === 'ENCRYPTED_KEY' ) {
				$settings[ $key ] = $stored[ $key ];
			}
		}

		$environment             = ArrayHelper::get( $settings, 'environment' );
		$settings['environment'] = 'staging' === $environment ? 'staging' : 'production';

		$pay_currency             = strtolower( trim( (string) ArrayHelper::get( $settings, 'pay_currency', 'all' ) ) );
		$settings['pay_currency'] = '' === $pay_currency ? 'all' : preg_replace( '/[^a-z0-9]/', '', $pay_currency );

		return Payzum_FF_Settings::encryptForStorage( $settings );
	}

	public function pushPaymentMethodToForm( $methods ) {
		$methods[ $this->key ] = array(
			'title'        => __( 'Crypto / stablecoin (Payzum)', 'payzum-for-fluent-forms' ),
			'enabled'      => 'yes',
			'method_value' => $this->key,
			'settings'     => array(
				'option_label' => array(
					'type'     => 'text',
					'template' => 'inputText',
					'value'    => __( 'Crypto / stablecoin (USDC, USDT and more)', 'payzum-for-fluent-forms' ),
					'label'    => __( 'Method Label', 'payzum-for-fluent-forms' ),
				),
			),
		);
		return $methods;
	}

	/** Link the transaction in the admin to the payment in the Payzum dashboard. */
	public function modifyTransaction( $transaction ) {
		if ( $transaction->charge_id ) {
			$transaction->action_url = 'https://merchant.payzum.com/payments/' . rawurlencode( $transaction->charge_id );
		}
		return $transaction;
	}
}
