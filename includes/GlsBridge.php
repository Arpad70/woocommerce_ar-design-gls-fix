<?php

namespace ArDesign\GlsFix;

use WC_Order;

defined('ABSPATH') || exit;

class GlsBridge
{
    public const CARRIER = 'gls';
    public const CRON_HOOK = 'ard_shipping_gls_tracking_sync_event';

    public static function init(): void
    {
        add_action('gls_label_generated', [__CLASS__, 'syncGeneratedLabel'], 10, 3);
        add_action('init', [__CLASS__, 'disableLegacyTrackingCron']);
    }

    public static function disableLegacyTrackingCron(): void
    {
        while ($timestamp = wp_next_scheduled(self::CRON_HOOK)) {
            wp_unschedule_event($timestamp, self::CRON_HOOK);
        }
    }

    public static function maybeScheduleTrackingCron(): void
    {
        if (!Settings::isTrackingEnabled() || !self::ensureApiServiceLoaded()) {
            while ($timestamp = wp_next_scheduled(self::CRON_HOOK)) {
                wp_unschedule_event($timestamp, self::CRON_HOOK);
            }

            return;
        }

        if (!wp_next_scheduled(self::CRON_HOOK)) {
            wp_schedule_event(time() + (10 * MINUTE_IN_SECONDS), 'hourly', self::CRON_HOOK);
        }
    }

    public static function syncGeneratedLabel(int $orderId, $order = null, array $body = []): void
    {
        $order = $order instanceof WC_Order ? $order : wc_get_order($orderId);

        if (!$order instanceof WC_Order) {
            return;
        }

        $shipmentData = self::buildShipmentDataFromLabel($order, is_array($body) ? $body : []);
        if (empty($shipmentData['tracking_number']) && empty($shipmentData['label_url'])) {
            return;
        }

        Shipment::storeShipmentData($order, $shipmentData);
        $order->save_meta_data();

        do_action('ard_shipping_shipment_created', $order->get_id(), $shipmentData, $order);
        do_action('ard_shipping_shipment_updated', $order->get_id(), $shipmentData, $order);
    }

    public static function isGlsOrder(WC_Order $order): bool
    {
        $shipment = Shipment::getShipmentData($order);
        if (($shipment['carrier'] ?? '') === self::CARRIER) {
            return true;
        }

        if (self::getTrackingNumbers($order) !== []) {
            return true;
        }

        if (self::getSecureLabelUrl($order) !== '') {
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

    public static function syncOpenShipments(): void
    {
        if (!Settings::isTrackingEnabled() || !self::ensureApiServiceLoaded()) {
            return;
        }

        $orders = wc_get_orders([
            'limit' => -1,
            'return' => 'objects',
            'meta_query' => [
                [
                    'key' => Shipment::CARRIER_META_KEY,
                    'value' => self::CARRIER,
                ],
                [
                    'key' => Shipment::PRIMARY_TRACKING_NUMBER_META_KEY,
                    'compare' => 'EXISTS',
                ],
            ],
        ]);

        foreach ($orders as $order) {
            if (!$order instanceof WC_Order || !self::shouldSyncOrder($order)) {
                continue;
            }

            self::syncOrderTracking($order);
        }
    }

    public static function shouldSyncOrder(WC_Order $order): bool
    {
        if ($order->has_status(['cancelled', 'refunded', 'failed'])) {
            return false;
        }

        $shipment = Shipment::getShipmentData($order);

        return !Tracking::isTerminalStatus((string) ($shipment['status'] ?? ''));
    }

    public static function syncOrderTracking(WC_Order $order): bool
    {
        if (!self::ensureApiServiceLoaded()) {
            return false;
        }

        $trackingNumber = (string) (Shipment::getShipmentData($order)['tracking_number'] ?? '');
        if (!$trackingNumber) {
            $trackingNumbers = self::getTrackingNumbers($order);
            $trackingNumber = (string) ($trackingNumbers[0] ?? '');
        }

        if (!$trackingNumber) {
            return false;
        }

        try {
            $serviceClass = 'GLS_Shipping_API_Service';
            $apiService = new $serviceClass();
            $trackingData = $apiService->get_parcel_status($trackingNumber);

            if (!is_array($trackingData) || $trackingData === []) {
                return false;
            }

            $shipmentData = self::buildShipmentDataFromTracking($order, $trackingData);
            Shipment::storeShipmentData($order, $shipmentData);
            self::storeTrackingSnapshot($order, $shipmentData);
            $order->save_meta_data();

            do_action('ard_shipping_shipment_updated', $order->get_id(), $shipmentData, $order);

            if (Tracking::isDeliveredStatus((string) ($shipmentData['status'] ?? ''), (string) ($shipmentData['status_label'] ?? ''), (string) ($trackingData['StatusInfo'] ?? ''))) {
                Shipment::markDelivered($order, $shipmentData);
            }

            return true;
        } catch (\Throwable $exception) {
            $order->add_order_note(sprintf(
                /* translators: %s: GLS API or runtime error message. */
                __('GLS tracking sync failed: %s', 'ar-design-gls-fix'),
                sanitize_text_field($exception->getMessage())
            ));

            return false;
        }
    }

    public static function buildShipmentDataFromLabel(WC_Order $order, array $body = []): array
    {
        $trackingNumbers = self::getTrackingNumbers($order);
        $trackingNumber = (string) ($trackingNumbers[0] ?? '');
        $referenceValues = (array) $order->get_meta('_gls_parcel_ids', true);
        $reference = (string) ($referenceValues[0] ?? '');

        return array_merge(Shipment::getShipmentData($order), [
            'carrier' => self::CARRIER,
            'reference' => $reference,
            'tracking_number' => $trackingNumber,
            'tracking_numbers' => $trackingNumbers,
            'tracking_url' => self::buildTrackingUrl($trackingNumber),
            'label_url' => self::getSecureLabelUrl($order),
            'status' => 'created',
            'status_label' => __('Shipment exported to GLS', 'ar-design-gls-fix'),
            'created_at' => current_time('mysql'),
            'updated_at' => current_time('mysql'),
            'payload' => $body,
        ]);
    }

    public static function buildShipmentDataFromTracking(WC_Order $order, array $trackingData = []): array
    {
        $existingShipment = Shipment::getShipmentData($order);
        $events = self::normalizeTrackingEvents((array) ($trackingData['ParcelStatusList'] ?? []));
        $currentEvent = $events ? end($events) : [];
        reset($events);
        $trackingNumbers = self::getTrackingNumbers($order);
        $trackingNumber = (string) ($existingShipment['tracking_number'] ?: ($trackingNumbers[0] ?? ''));

        return array_merge($existingShipment, [
            'carrier' => self::CARRIER,
            'tracking_number' => $trackingNumber,
            'tracking_numbers' => $trackingNumbers,
            'tracking_url' => self::buildTrackingUrl($trackingNumber),
            'label_url' => $existingShipment['label_url'] ?: self::getSecureLabelUrl($order),
            'status' => (string) ($currentEvent['status'] ?? ''),
            'status_label' => (string) ($currentEvent['label'] ?? ''),
            'updated_at' => current_time('mysql'),
            'payload' => array_merge($trackingData, ['events' => $events]),
        ]);
    }

    public static function normalizeTrackingEvents(array $events): array
    {
        $normalized = [];

        foreach ($events as $event) {
            if (!is_array($event)) {
                continue;
            }

            $normalized[] = [
                'status' => sanitize_text_field((string) ($event['StatusCode'] ?? '')),
                'label' => sanitize_text_field((string) ($event['StatusDescription'] ?? ($event['StatusCode'] ?? ''))),
                'description' => sanitize_text_field((string) ($event['StatusInfo'] ?? '')),
                'date' => self::normalizeTrackingDate((string) ($event['StatusDate'] ?? '')),
                'location' => sanitize_text_field((string) ($event['DepotCity'] ?? '')),
                'current' => false,
                'reached' => true,
                'source' => 'gls_tracking',
            ];
        }

        return $normalized;
    }

    private static function storeTrackingSnapshot(WC_Order $order, array $shipmentData): void
    {
        $payload = isset($shipmentData['payload']) && is_array($shipmentData['payload']) ? $shipmentData['payload'] : [];
        $events = isset($payload['events']) && is_array($payload['events']) ? $payload['events'] : [];
        $currentEvent = $events ? end($events) : [];
        reset($events);

        $order->update_meta_data(Tracking::CURRENT_STATUS_META_KEY, (string) ($shipmentData['status'] ?? ''));
        $order->update_meta_data(Tracking::CURRENT_STATUS_LABEL_META_KEY, (string) ($shipmentData['status_label'] ?? ''));
        $order->update_meta_data(Tracking::CURRENT_STATUS_DESCRIPTION_META_KEY, (string) ($currentEvent['description'] ?? ''));
        $order->update_meta_data(Tracking::CURRENT_STATUS_DATE_META_KEY, (string) ($currentEvent['date'] ?? current_time('mysql')));
        $order->update_meta_data(Tracking::CURRENT_STATUS_LOCATION_META_KEY, (string) ($currentEvent['location'] ?? ''));
        $order->update_meta_data(Tracking::LAST_SYNC_AT_META_KEY, current_time('mysql'));
        $order->delete_meta_data(Tracking::LAST_SYNC_ERROR_META_KEY);
        $order->update_meta_data(Tracking::STATUS_HISTORY_META_KEY, Tracking::mergeTrackingHistory($order, $events));
    }

    public static function normalizeTrackingDate(string $value): string
    {
        if (!$value) {
            return current_time('mysql');
        }

        if (preg_match('/Date\((\d+)/', $value, $matches)) {
            return gmdate('Y-m-d H:i:s', (int) floor(((int) $matches[1]) / 1000));
        }

        $timestamp = strtotime($value);

        return $timestamp ? gmdate('Y-m-d H:i:s', $timestamp) : sanitize_text_field($value);
    }

    public static function getTrackingNumbers(WC_Order $order): array
    {
        $trackingNumbers = $order->get_meta('_gls_tracking_codes', true);
        if (!is_array($trackingNumbers)) {
            $trackingNumbers = $trackingNumbers ? [(string) $trackingNumbers] : [];
        }

        $legacyTrackingNumber = (string) $order->get_meta('_gls_tracking_code', true);
        if ($legacyTrackingNumber && !in_array($legacyTrackingNumber, $trackingNumbers, true)) {
            $trackingNumbers[] = $legacyTrackingNumber;
        }

        return array_values(array_filter(array_map('sanitize_text_field', $trackingNumbers)));
    }

    public static function buildTrackingUrl(string $trackingNumber): string
    {
        if (!$trackingNumber) {
            return '';
        }

        $settings = get_option('woocommerce_gls_shipping_method_settings', []);
        $country = isset($settings['country']) && $settings['country'] ? strtoupper(sanitize_text_field((string) $settings['country'])) : 'SK';

        return sprintf('https://gls-group.com/%s/en/parcel-tracking/?match=%s', rawurlencode($country), rawurlencode($trackingNumber));
    }

    public static function getSecureLabelUrl(WC_Order $order): string
    {
        $glsClass = 'GLS_Shipping_For_Woo';
        if (!class_exists($glsClass) || !is_callable([$glsClass, 'get_secure_label_url'])) {
            return '';
        }

        $url = call_user_func([$glsClass, 'get_secure_label_url'], $order->get_id());

        return is_string($url) ? esc_url_raw($url) : '';
    }

    public static function ensureApiServiceLoaded(): bool
    {
        if (class_exists('GLS_Shipping_API_Service')) {
            return true;
        }

        if (!defined('GLS_SHIPPING_ABSPATH')) {
            return false;
        }

        $glsBasePath = (string) constant('GLS_SHIPPING_ABSPATH');
        $apiDataFile = $glsBasePath . 'includes/api/class-gls-shipping-api-data.php';
        $apiServiceFile = $glsBasePath . 'includes/api/class-gls-shipping-api-service.php';

        if (file_exists($apiDataFile)) {
            require_once $apiDataFile;
        }

        if (file_exists($apiServiceFile)) {
            require_once $apiServiceFile;
        }

        return class_exists('GLS_Shipping_API_Service');
    }
}
