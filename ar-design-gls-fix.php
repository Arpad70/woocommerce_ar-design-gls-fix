<?php

/*
 * Plugin Name: AR Design GLS Fix for WooCommerce
 * Description: Samostatný GLS fix modul pre WooCommerce spravovaný Arpád Horák. Oddeľuje GLS automatizáciu od AR Design DPD modulu.
 * Version: 1.0.14
 * Author: Arpád Horák
 * Author URI: https://arpad-horak.cz
 * Update URI: https://github.com/Arpad70/woocommerce_ar-design-gls-fix
 * License: GPLv2
 * License URI: https://www.gnu.org/licenses/gpl-2.0.txt
 * Text Domain: ar-design-gls-fix
 * Domain Path: /languages
 * Requires at least: 5.3
 * Tested up to: 6.9.4
 * Requires PHP: 7.4
 * WC requires at least: 7.0
 * WC tested up to: 10.6.1
 */

namespace ArDesign\GlsFix;

defined('ABSPATH') || exit;

$plugin_dir = str_replace(basename(__FILE__), '', plugin_basename(__FILE__));
$plugin_dir = substr($plugin_dir, 0, strlen($plugin_dir) - 1);

define('AR_DESIGN_GLS_FIX_PLUGIN_PATH', plugin_dir_path(__FILE__));
define('AR_DESIGN_GLS_FIX_PLUGIN_DIR', $plugin_dir);
define('AR_DESIGN_GLS_FIX_PLUGIN_INDEX', __FILE__);
define('AR_DESIGN_GLS_FIX_PLUGIN_WC_MIN_VERSION', '7.0');
define('AR_DESIGN_GLS_FIX_VERSION', '1.0.14');
define('AR_DESIGN_GLS_FIX_BASENAME', plugin_basename(__FILE__));
define('AR_DESIGN_GLS_FIX_REPOSITORY', 'Arpad70/woocommerce_ar-design-gls-fix');
define('AR_DESIGN_GLS_FIX_TEXT_DOMAIN', 'ar-design-gls-fix');

require_once AR_DESIGN_GLS_FIX_PLUGIN_PATH . 'includes/Updater.php';
require_once AR_DESIGN_GLS_FIX_PLUGIN_PATH . 'includes/RollbackManager.php';
require_once AR_DESIGN_GLS_FIX_PLUGIN_PATH . 'includes/helpers.php';
require_once AR_DESIGN_GLS_FIX_PLUGIN_PATH . 'includes/AdminOrderList.php';
require_once AR_DESIGN_GLS_FIX_PLUGIN_PATH . 'includes/SurchargeMonitor.php';
require_once AR_DESIGN_GLS_FIX_PLUGIN_PATH . 'includes/Shipment.php';
require_once AR_DESIGN_GLS_FIX_PLUGIN_PATH . 'includes/Tracking.php';
require_once AR_DESIGN_GLS_FIX_PLUGIN_PATH . 'includes/TrackingDisplay.php';
require_once AR_DESIGN_GLS_FIX_PLUGIN_PATH . 'includes/Automation.php';
require_once AR_DESIGN_GLS_FIX_PLUGIN_PATH . 'includes/GlsBridge.php';
require_once AR_DESIGN_GLS_FIX_PLUGIN_PATH . 'includes/ShippingSurcharge.php';

add_action('before_woocommerce_init', function () {
    if (class_exists(\Automattic\WooCommerce\Utilities\FeaturesUtil::class)) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
    }
});

add_action('admin_notices', function () {
    if (class_exists('WooCommerce') && version_compare(WC()->version, AR_DESIGN_GLS_FIX_PLUGIN_WC_MIN_VERSION, '>=')) {
        return;
    }

    ?>
    <div class="notice notice-error is-dismissible">
        <p><?php echo esc_html(sprintf(
            /* translators: %s: minimum required WooCommerce version. */
            __('AR Design GLS Fix requires WooCommerce version %s or higher.', 'ar-design-gls-fix'),
            AR_DESIGN_GLS_FIX_PLUGIN_WC_MIN_VERSION
        )); ?></p>
    </div>
    <?php
});

add_action('init', function () {
    load_plugin_textdomain(
        AR_DESIGN_GLS_FIX_TEXT_DOMAIN,
        false,
        dirname(plugin_basename(__FILE__)) . '/languages'
    );

});

function ard_gls_fix_bootstrap_woo_runtime(): void
{
    if (!is_woocommerce_active()) {
        return;
    }

    if (!class_exists('WooCommerce') || version_compare(WC()->version, AR_DESIGN_GLS_FIX_PLUGIN_WC_MIN_VERSION, '<')) {
        return;
    }

    $settings_class_path = AR_DESIGN_GLS_FIX_PLUGIN_PATH . 'includes/Settings.php';

    if (file_exists($settings_class_path)) {
        require_once $settings_class_path;
    }

    if (!class_exists(Settings::class)) {
        return;
    }

    Settings::init();
    \ArDesign\GlsFix\AdminOrderList::init();
    SurchargeMonitor::init();
    Automation::init();
    GlsBridge::init();
    ShippingSurcharge::init();
    TrackingDisplay::init();
}

add_action('woocommerce_loaded', __NAMESPACE__ . '\\ard_gls_fix_bootstrap_woo_runtime', 20);

$ar_design_gls_fix_updater = new ArDesignGlsFixUpdater(
    AR_DESIGN_GLS_FIX_REPOSITORY,
    AR_DESIGN_GLS_FIX_BASENAME,
    AR_DESIGN_GLS_FIX_VERSION
);
$ar_design_gls_fix_updater->register();

$ar_design_gls_fix_rollback_manager = new \ArDesign\GlsFix\ArDesignGlsFixRollbackManager(
    AR_DESIGN_GLS_FIX_BASENAME,
    AR_DESIGN_GLS_FIX_PLUGIN_PATH
);
$ar_design_gls_fix_rollback_manager->register();
