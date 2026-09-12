<?php
/**
 * Plugin Name: Payzum for Fluent Forms
 * Plugin URI: https://github.com/payzum-dev/fluent-forms-payzum
 * Description: Accept crypto & stablecoin payments (USDC, USDT and more, multi-chain) in Fluent Forms payment forms through Payzum — non-custodial, funds settle directly to your own wallet.
 * Version: 1.0.0
 * Requires at least: 6.0
 * Requires PHP: 8.1
 * Requires Plugins: fluentform
 * Author: Payzum
 * Author URI: https://payzum.com
 * License: MIT
 * License URI: https://opensource.org/licenses/MIT
 * Text Domain: payzum-fluent-forms
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'PAYZUM_FF_VERSION', '1.0.0' );
define( 'PAYZUM_FF_PATH', plugin_dir_path( __FILE__ ) );

require_once PAYZUM_FF_PATH . 'vendor/autoload.php';

/**
 * Boot once Fluent Forms is fully loaded. The payment module classes only
 * exist from Fluent Forms 6.x (payments in the free plugin), so their absence
 * means an older Fluent Forms — surface a notice instead of fataling.
 */
add_action( 'fluentform/loaded', function () {
	if ( ! class_exists( '\FluentForm\App\Modules\Payments\PaymentMethods\BaseProcessor' ) ) {
		add_action( 'admin_notices', function () {
			echo '<div class="notice notice-error"><p>';
			echo esc_html__( 'Payzum for Fluent Forms requires Fluent Forms 6.0 or newer (the version that ships the payments module).', 'payzum-fluent-forms' );
			echo '</p></div>';
		} );
		return;
	}

	require_once PAYZUM_FF_PATH . 'includes/class-payzum-ff-settings.php';
	require_once PAYZUM_FF_PATH . 'includes/class-payzum-ff-processor.php';
	require_once PAYZUM_FF_PATH . 'includes/class-payzum-ff-handler.php';

	( new Payzum_FF_Handler() )->init();
} );
