<?php

namespace ArDesign\GlsFix;

defined('ABSPATH') || exit;

function is_woocommerce_active(): bool
{
    return class_exists('WooCommerce') && function_exists('WC');
}

function ard_gls_fix_log(string $message, $context = null): void
{
    if (function_exists('wc_get_logger')) {
        wc_get_logger()->debug($message . ($context !== null ? ' ' . wp_json_encode($context) : ''), [
            'source' => 'ar-design-gls-fix',
        ]);
    }
}
