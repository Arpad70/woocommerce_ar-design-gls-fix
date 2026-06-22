<?php

namespace ArDesign\GlsFix;

use ArDesign\Shared\Shipping\DeliveryWorkflowHelper;
use ArDesign\GlsFix\Shipment;
use ArDesign\GlsFix\Tracking;
use WC_Order;

defined('ABSPATH') || exit;

require_once WP_PLUGIN_DIR . '/ar-design-shared-support/includes/shipping/DeliveryWorkflowHelper.php';

class GlsBridge
{
    public const CARRIER = 'gls';
    private const LEGACY_TRACKING_CRON_HOOK = 'ard_shipping_gls_tracking_sync_event';
	private const SHIPMENT_CREATED_EVENT = 'ard_shipping_shipment_created';
	private const SHIPMENT_UPDATED_EVENT = 'ard_shipping_shipment_updated';

    public static function init(): void
    {
        add_action('gls_label_generated', [__CLASS__, 'syncGeneratedLabel'], 10, 3);
        add_action('init', [__CLASS__, 'clearLegacyTrackingCronQueue']);
    }

    public static function clearLegacyTrackingCronQueue(): void
    {
        while ($timestamp = wp_next_scheduled(self::LEGACY_TRACKING_CRON_HOOK)) {
            wp_unschedule_event($timestamp, self::LEGACY_TRACKING_CRON_HOOK);
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

		do_action(self::getShipmentCreatedEventName(), $order->get_id(), $shipmentData, $order);
		do_action(self::getShipmentUpdatedEventName(), $order->get_id(), $shipmentData, $order);
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

        return DeliveryWorkflowHelper::orderHasMatchingShippingMethod(
            $order,
            static fn (string $methodId): bool => false !== strpos($methodId, 'gls')
        );
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

			do_action(self::getShipmentUpdatedEventName(), $order->get_id(), $shipmentData, $order);

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
        $currentEvent = self::getCurrentTrackingEvent($events);
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

            $mappedStatus = self::mapTrackingEventStatus($event);

            $normalized[] = [
                'status' => $mappedStatus['status'],
                'label' => $mappedStatus['label'],
                'description' => sanitize_text_field((string) ($event['StatusInfo'] ?? '')),
                'date' => self::normalizeTrackingDate((string) ($event['StatusDate'] ?? '')),
                'location' => sanitize_text_field((string) ($event['DepotCity'] ?? '')),
                'current' => false,
                'reached' => true,
                'source' => 'gls_tracking',
            ];
        }

        usort($normalized, [__CLASS__, 'compareTrackingEvents']);

        $currentIndex = self::getCurrentTrackingEventIndex($normalized);
        if ($currentIndex !== null) {
            $normalized[$currentIndex]['current'] = true;
        }

        return $normalized;
    }

    private static function storeTrackingSnapshot(WC_Order $order, array $shipmentData): void
    {
        $payload = isset($shipmentData['payload']) && is_array($shipmentData['payload']) ? $shipmentData['payload'] : [];
        $events = isset($payload['events']) && is_array($payload['events']) ? $payload['events'] : [];
        $currentEvent = self::getCurrentTrackingEvent($events);

        Tracking::storeSnapshot($order, [
            'status' => (string) ($shipmentData['status'] ?? ''),
            'label' => (string) ($shipmentData['status_label'] ?? ''),
            'description' => (string) ($currentEvent['description'] ?? ''),
            'date' => (string) ($currentEvent['date'] ?? current_time('mysql')),
            'location' => (string) ($currentEvent['location'] ?? ''),
            'last_sync_at' => current_time('mysql'),
            'last_error' => '',
            'history' => Tracking::mergeTrackingHistory($order, $events),
        ]);
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

    private static function getCurrentTrackingEvent(array $events): array
    {
        $currentIndex = self::getCurrentTrackingEventIndex($events);

        return $currentIndex !== null ? (array) $events[$currentIndex] : [];
    }

    private static function getCurrentTrackingEventIndex(array $events): ?int
    {
        $currentIndex = null;
        $currentEvent = null;

        foreach ($events as $index => $event) {
            if (!is_array($event)) {
                continue;
            }

            if ($currentEvent === null || self::compareTrackingEvents($currentEvent, $event) < 0) {
                $currentEvent = $event;
                $currentIndex = $index;
            }
        }

        return $currentIndex;
    }

    private static function compareTrackingEvents(array $left, array $right): int
    {
        $dateComparison = strcmp((string) ($left['date'] ?? ''), (string) ($right['date'] ?? ''));
        if ($dateComparison !== 0) {
            return $dateComparison;
        }

        return self::getTrackingStatusPriority((string) ($left['status'] ?? '')) <=> self::getTrackingStatusPriority((string) ($right['status'] ?? ''));
    }

    private static function getTrackingStatusPriority(string $status): int
    {
        $priorities = [
            'sender_received_return' => 100,
            'returning_to_sender' => 90,
            'delivered' => 80,
            'out_for_delivery' => 70,
            'in_transit' => 60,
            'picked_up' => 50,
            'data_sent' => 10,
        ];

        return isset($priorities[$status]) ? (int) $priorities[$status] : 0;
    }

    private static function mapTrackingEventStatus(array $event): array
    {
        $statusCode = sanitize_text_field((string) ($event['StatusCode'] ?? ''));
        $statusDescription = sanitize_text_field((string) ($event['StatusDescription'] ?? ''));
        $statusInfo = sanitize_text_field((string) ($event['StatusInfo'] ?? ''));
        $normalized = self::normalizeText($statusDescription . ' ' . $statusInfo . ' ' . $statusCode);

        $status = $statusCode !== '' ? $statusCode : 'tracking_update';
        $label = $statusDescription !== '' ? $statusDescription : ($statusCode !== '' ? $statusCode : __('Tracking update', 'ar-design-gls-fix'));

        $statusFromCode = self::mapTrackingStatusCode($statusCode);
        if ($statusFromCode !== []) {
            return $statusFromCode;
        }

        if (self::containsAny($normalized, ['sender received', 'returned to sender completed', 'return completed', 'shipper received', 'received by sender'])) {
            return [
                'status' => 'sender_received_return',
                'label' => __('Returned to sender', 'ar-design-gls-fix'),
            ];
        }

        if (self::containsAny($normalized, ['return to sender', 'returning to sender', 'returned to sender', 'undeliverable', 'delivery failed', 'refused', 'back to sender'])) {
            return [
                'status' => 'returning_to_sender',
                'label' => __('Returning to sender', 'ar-design-gls-fix'),
            ];
        }

        if (self::containsAny($normalized, ['delivered', 'successful delivery', 'successfully delivered', 'signed by consignee', 'handed over to consignee'])) {
            return [
                'status' => 'delivered',
                'label' => __('Delivered', 'ar-design-gls-fix'),
            ];
        }

        if (self::containsAny($normalized, ['out for delivery', 'courier delivery', 'delivery route', 'delivery list scan'])) {
            return [
                'status' => 'out_for_delivery',
                'label' => __('Out for delivery', 'ar-design-gls-fix'),
            ];
        }

        if (self::containsAny($normalized, ['in transit', 'transit', 'sorting', 'depot', 'hub', 'linehaul', 'transport', 'depot entry', 'rollcarte check'])) {
            return [
                'status' => 'in_transit',
                'label' => __('In transit', 'ar-design-gls-fix'),
            ];
        }

        if (self::containsAny($normalized, ['picked up', 'pickup', 'collected', 'accepted by courier', 'taken over'])) {
            return [
                'status' => 'picked_up',
                'label' => __('Picked up by courier', 'ar-design-gls-fix'),
            ];
        }

        return [
            'status' => $status,
            'label' => $label,
        ];
    }

    private static function mapTrackingStatusCode(string $statusCode): array
    {
        $statusCode = trim($statusCode);
        if ($statusCode === '') {
            return [];
        }

        $statusMap = [
            'sender_received_return' => [],
            'returning_to_sender' => [],
            'delivered' => ['05'],
            'out_for_delivery' => ['04'],
            'in_transit' => ['01', '03', '10'],
            'picked_up' => ['86'],
            'data_sent' => ['51', '52'],
        ];

        foreach ($statusMap as $status => $codes) {
            if (!in_array($statusCode, $codes, true)) {
                continue;
            }

            return [
                'status' => $status,
                'label' => self::getTrackingStatusLabel($status),
            ];
        }

        return [];
    }

    private static function getTrackingStatusLabel(string $status): string
    {
        switch ($status) {
            case 'sender_received_return':
                return __('Returned to sender', 'ar-design-gls-fix');
            case 'returning_to_sender':
                return __('Returning to sender', 'ar-design-gls-fix');
            case 'delivered':
                return __('Delivered', 'ar-design-gls-fix');
            case 'out_for_delivery':
                return __('Out for delivery', 'ar-design-gls-fix');
            case 'in_transit':
                return __('In transit', 'ar-design-gls-fix');
            case 'picked_up':
                return __('Picked up by courier', 'ar-design-gls-fix');
            case 'data_sent':
                return __('Shipment data sent to GLS', 'ar-design-gls-fix');
            default:
                return __('Tracking update', 'ar-design-gls-fix');
        }
    }

    private static function normalizeText(string $value): string
    {
        $value = remove_accents(wp_strip_all_tags($value));
        $value = strtolower($value);

        return trim((string) preg_replace('/\s+/', ' ', $value));
    }

    private static function containsAny(string $haystack, array $needles): bool
    {
        foreach ($needles as $needle) {
            if ($needle !== '' && strpos($haystack, self::normalizeText((string) $needle)) !== false) {
                return true;
            }
        }

        return false;
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

    private static function getShipmentCreatedEventName(): string
    {
        return DeliveryWorkflowHelper::getWorkflowEventName('ARD_WORKFLOW_EVENT_SHIPMENT_CREATED', self::SHIPMENT_CREATED_EVENT);
    }

    private static function getShipmentUpdatedEventName(): string
    {
        return DeliveryWorkflowHelper::getWorkflowEventName('ARD_WORKFLOW_EVENT_SHIPMENT_UPDATED', self::SHIPMENT_UPDATED_EVENT);
    }
}
