<?php

declare(strict_types=1);

namespace ArDesign\GlsFix;

use WC_Order;
use WP_Hook;

defined('ABSPATH') || exit;

final class AdminOrderList
{
    private const COLUMN_KEY = 'gls_parcel_id';

    public static function init(): void
    {
        if (!is_admin()) {
            return;
        }

        self::replaceOriginalColumnHooks();
        self::replaceDpdColumnHooks();

        add_filter('manage_edit-shop_order_columns', [__CLASS__, 'addTrackingColumn'], 20);
        add_filter('manage_woocommerce_page_wc-orders_columns', [__CLASS__, 'addTrackingColumn'], 20);
        add_action('manage_shop_order_posts_custom_column', [__CLASS__, 'renderTrackingColumn'], 20, 2);
        add_action('manage_woocommerce_page_wc-orders_custom_column', [__CLASS__, 'renderTrackingColumn'], 20, 2);
    }

    /**
     * @param array<string, string> $columns
     * @return array<string, string>
     */
    public static function addTrackingColumn(array $columns): array
    {
        $newColumns = [];

        foreach ($columns as $key => $label) {
            $newColumns[$key] = $label;

            if ('order_total' === $key) {
                $newColumns[self::COLUMN_KEY] = __('GLS Tracking Number', 'gls-shipping-for-woocommerce');
            }
        }

        if (!isset($newColumns[self::COLUMN_KEY])) {
            $newColumns[self::COLUMN_KEY] = __('GLS Tracking Number', 'gls-shipping-for-woocommerce');
        }

        return $newColumns;
    }

    /**
     * @param mixed $orderOrOrderId
     */
    public static function renderTrackingColumn(string $column, $orderOrOrderId = null): void
    {
        if (self::COLUMN_KEY !== $column) {
            return;
        }

        $order = self::resolveOrder($orderOrOrderId);
        if (!$order instanceof WC_Order) {
            echo '-';
            return;
        }

        $labelUrl = self::getLabelUrl($order);
        $trackingNumbers = self::getTrackingNumbers($order);

        if ('' !== $labelUrl) {
            echo '<p><a class="button" href="' . esc_url($labelUrl) . '" target="_blank" rel="noopener noreferrer">Stiahnuť štítok</a></p>';
        }

        if ([] !== $trackingNumbers) {
            $trackingLabel = count($trackingNumbers) > 1 ? 'GLS Tracking numbers' : 'GLS Tracking number';
            $trackingNumbersHtml = implode('<br>', array_map('esc_html', $trackingNumbers));

            echo '<p style="font-size: 12px; margin-top: 5px;">' . esc_html($trackingLabel) . '<br><strong>' . wp_kses_post($trackingNumbersHtml) . '</strong></p>';
            return;
        }

        if ('' === $labelUrl) {
            echo '-';
        }
    }

    private static function replaceOriginalColumnHooks(): void
    {
        if (!class_exists('GLS_Shipping_Bulk')) {
            return;
        }

        self::removeBulkCallback('manage_edit-shop_order_columns', 'add_gls_parcel_id_column');
        self::removeBulkCallback('manage_woocommerce_page_wc-orders_columns', 'add_gls_parcel_id_column');
        self::removeBulkCallback('manage_shop_order_posts_custom_column', 'populate_gls_parcel_id_column');
        self::removeBulkCallback('manage_woocommerce_page_wc-orders_custom_column', 'populate_gls_parcel_id_column');
    }

    private static function removeBulkCallback(string $hookName, string $methodName): void
    {
        global $wp_filter;

        if (!isset($wp_filter[$hookName]) || !$wp_filter[$hookName] instanceof WP_Hook) {
            return;
        }

        foreach ($wp_filter[$hookName]->callbacks as $priority => $callbacks) {
            foreach ($callbacks as $callback) {
                $function = $callback['function'] ?? null;

                if (!is_array($function) || !isset($function[0], $function[1]) || !is_object($function[0])) {
                    continue;
                }

                if (!$function[0] instanceof \GLS_Shipping_Bulk || $function[1] !== $methodName) {
                    continue;
                }

                remove_filter($hookName, [$function[0], $methodName], (int) $priority);
            }
        }
    }

    private static function replaceDpdColumnHooks(): void
    {
        if (!class_exists('ArDesign\DPD\OrderList') || !class_exists('ArDesign\DPD\DpdExportSettings')) {
            return;
        }

        self::removeStaticCallback('manage_shop_order_posts_custom_column', 'ArDesign\DPD\OrderList', 'addOrderByDPDExportColumn');
        self::removeStaticCallback('manage_woocommerce_page_wc-orders_custom_column', 'ArDesign\DPD\OrderList', 'addOrderByDPDExportColumn');

        add_action('manage_shop_order_posts_custom_column', [__CLASS__, 'renderGuardedDpdExportColumn'], 10, 2);
        add_action('manage_woocommerce_page_wc-orders_custom_column', [__CLASS__, 'renderGuardedDpdExportColumn'], 10, 2);
    }

    private static function removeStaticCallback(string $hookName, string $className, string $methodName): void
    {
        global $wp_filter;

        if (!isset($wp_filter[$hookName]) || !$wp_filter[$hookName] instanceof WP_Hook) {
            return;
        }

        foreach ($wp_filter[$hookName]->callbacks as $priority => $callbacks) {
            foreach ($callbacks as $callback) {
                $function = $callback['function'] ?? null;

                if (!is_array($function) || !isset($function[0], $function[1]) || !is_string($function[0])) {
                    continue;
                }

                if (ltrim($function[0], '\\') !== $className || $function[1] !== $methodName) {
                    continue;
                }

                remove_action($hookName, [$function[0], $methodName], (int) $priority);
            }
        }
    }

    /**
     * @param mixed $orderOrOrderId
     */
    public static function renderGuardedDpdExportColumn(string $column, $orderOrOrderId = null): void
    {
        if (!class_exists('ArDesign\DPD\DpdExportSettings') || !class_exists('ArDesign\DPD\OrderList')) {
            return;
        }

        if ($column !== \ArDesign\DPD\DpdExportSettings::SETTINGS_ID_KEY) {
            return;
        }

        $order = self::resolveOrder($orderOrOrderId);
        if (!$order instanceof WC_Order) {
            return;
        }

        if (self::isExplicitGlsOrder($order)) {
            echo '-';
            return;
        }

        \ArDesign\DPD\OrderList::addOrderByDPDExportColumn($column, $order);
    }

    /**
     * @param mixed $orderOrOrderId
     */
    private static function resolveOrder($orderOrOrderId): ?WC_Order
    {
        if ($orderOrOrderId instanceof WC_Order) {
            return $orderOrOrderId;
        }

        if (is_numeric($orderOrOrderId)) {
            $order = wc_get_order((int) $orderOrOrderId);
            return $order instanceof WC_Order ? $order : null;
        }

        return null;
    }

    /**
     * @return string[]
     */
    private static function getTrackingNumbers(WC_Order $order): array
    {
        $trackingNumbers = GlsBridge::getTrackingNumbers($order);

        if ([] !== $trackingNumbers) {
            return array_values(array_unique(array_filter($trackingNumbers)));
        }

        $shipment = Shipment::getShipmentData($order);
        if (!self::isGlsShipment($shipment)) {
            return [];
        }

        $fallbackTrackingNumbers = array_values(array_filter(array_map('sanitize_text_field', (array) ($shipment['tracking_numbers'] ?? []))));
        $primaryTrackingNumber = sanitize_text_field((string) ($shipment['tracking_number'] ?? ''));

        if ('' !== $primaryTrackingNumber && !in_array($primaryTrackingNumber, $fallbackTrackingNumbers, true)) {
            array_unshift($fallbackTrackingNumbers, $primaryTrackingNumber);
        }

        return array_values(array_unique(array_filter($fallbackTrackingNumbers)));
    }

    private static function getLabelUrl(WC_Order $order): string
    {
        $secureLabelUrl = GlsBridge::getSecureLabelUrl($order);
        if ('' !== $secureLabelUrl) {
            return $secureLabelUrl;
        }

        $shipment = Shipment::getShipmentData($order);
        if (!self::isGlsShipment($shipment)) {
            return '';
        }

        $labelUrl = (string) ($shipment['label_url'] ?? '');

        return '' !== $labelUrl ? esc_url_raw($labelUrl) : '';
    }

    /**
     * @param array<string, mixed> $shipment
     */
    private static function isGlsShipment(array $shipment): bool
    {
        return GlsBridge::CARRIER === sanitize_key((string) ($shipment['carrier'] ?? ''));
    }

    private static function isExplicitGlsOrder(WC_Order $order): bool
    {
        if (self::getTrackingNumbers($order) !== []) {
            return true;
        }

        if (self::getLabelUrl($order) !== '') {
            return true;
        }

        foreach ($order->get_shipping_methods() as $shippingMethod) {
            if (!is_object($shippingMethod) || !method_exists($shippingMethod, 'get_method_id')) {
                continue;
            }

            if (false !== strpos(sanitize_key((string) $shippingMethod->get_method_id()), 'gls')) {
                return true;
            }
        }

        return false;
    }
}
