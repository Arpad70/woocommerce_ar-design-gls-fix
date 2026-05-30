<?php

namespace ArDesign\GlsFix;

use WC_Order;

defined('ABSPATH') || exit;

class Tracking
{
    public const CURRENT_STATUS_META_KEY = 'gls_shipment_tracking_status';
    public const CURRENT_STATUS_LABEL_META_KEY = 'gls_shipment_tracking_label';
    public const CURRENT_STATUS_DESCRIPTION_META_KEY = 'gls_shipment_tracking_description';
    public const CURRENT_STATUS_DATE_META_KEY = 'gls_shipment_tracking_date';
    public const CURRENT_STATUS_LOCATION_META_KEY = 'gls_shipment_tracking_location';
    public const STATUS_HISTORY_META_KEY = 'gls_shipment_tracking_history';
    public const LAST_SYNC_AT_META_KEY = 'gls_shipment_tracking_last_sync_at';
    public const LAST_SYNC_ERROR_META_KEY = 'gls_shipment_tracking_last_error';

    private const LEGACY_CURRENT_STATUS_META_KEY = 'dpd_shipment_tracking_status';
    private const LEGACY_CURRENT_STATUS_LABEL_META_KEY = 'dpd_shipment_tracking_label';
    private const LEGACY_CURRENT_STATUS_DESCRIPTION_META_KEY = 'dpd_shipment_tracking_description';
    private const LEGACY_CURRENT_STATUS_DATE_META_KEY = 'dpd_shipment_tracking_date';
    private const LEGACY_CURRENT_STATUS_LOCATION_META_KEY = 'dpd_shipment_tracking_location';
    private const LEGACY_STATUS_HISTORY_META_KEY = 'dpd_shipment_tracking_history';
    private const LEGACY_LAST_SYNC_AT_META_KEY = 'dpd_shipment_tracking_last_sync_at';
    private const LEGACY_LAST_SYNC_ERROR_META_KEY = 'dpd_shipment_tracking_last_error';

    public static function getCurrentStatusMeta(WC_Order $order): string
    {
        return self::getCompatStringMeta($order, self::CURRENT_STATUS_META_KEY, self::LEGACY_CURRENT_STATUS_META_KEY);
    }

    public static function getCurrentStatusLabelMeta(WC_Order $order): string
    {
        return self::getCompatStringMeta($order, self::CURRENT_STATUS_LABEL_META_KEY, self::LEGACY_CURRENT_STATUS_LABEL_META_KEY);
    }

    public static function getCurrentStatusDescriptionMeta(WC_Order $order): string
    {
        return self::getCompatStringMeta($order, self::CURRENT_STATUS_DESCRIPTION_META_KEY, self::LEGACY_CURRENT_STATUS_DESCRIPTION_META_KEY);
    }

    public static function getCurrentStatusDateMeta(WC_Order $order): string
    {
        return self::getCompatStringMeta($order, self::CURRENT_STATUS_DATE_META_KEY, self::LEGACY_CURRENT_STATUS_DATE_META_KEY);
    }

    public static function getCurrentStatusLocationMeta(WC_Order $order): string
    {
        return self::getCompatStringMeta($order, self::CURRENT_STATUS_LOCATION_META_KEY, self::LEGACY_CURRENT_STATUS_LOCATION_META_KEY);
    }

    public static function getLastSyncAtMeta(WC_Order $order): string
    {
        return self::getCompatStringMeta($order, self::LAST_SYNC_AT_META_KEY, self::LEGACY_LAST_SYNC_AT_META_KEY);
    }

    public static function getLastSyncErrorMeta(WC_Order $order): string
    {
        return self::getCompatStringMeta($order, self::LAST_SYNC_ERROR_META_KEY, self::LEGACY_LAST_SYNC_ERROR_META_KEY);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function getStatusHistory(WC_Order $order): array
    {
        $history = $order->get_meta(self::STATUS_HISTORY_META_KEY, true);
        if (is_array($history) && $history !== []) {
            return $history;
        }

        $legacyHistory = $order->get_meta(self::LEGACY_STATUS_HISTORY_META_KEY, true);

        return is_array($legacyHistory) ? $legacyHistory : [];
    }

    /**
     * @param array<string, mixed> $snapshot
     */
    public static function storeSnapshot(WC_Order $order, array $snapshot): void
    {
        self::updateCompatStringMeta($order, self::CURRENT_STATUS_META_KEY, self::LEGACY_CURRENT_STATUS_META_KEY, (string) ($snapshot['status'] ?? ''));
        self::updateCompatStringMeta($order, self::CURRENT_STATUS_LABEL_META_KEY, self::LEGACY_CURRENT_STATUS_LABEL_META_KEY, (string) ($snapshot['label'] ?? ''));
        self::updateCompatStringMeta($order, self::CURRENT_STATUS_DESCRIPTION_META_KEY, self::LEGACY_CURRENT_STATUS_DESCRIPTION_META_KEY, (string) ($snapshot['description'] ?? ''));
        self::updateCompatStringMeta($order, self::CURRENT_STATUS_DATE_META_KEY, self::LEGACY_CURRENT_STATUS_DATE_META_KEY, (string) ($snapshot['date'] ?? current_time('mysql')));
        self::updateCompatStringMeta($order, self::CURRENT_STATUS_LOCATION_META_KEY, self::LEGACY_CURRENT_STATUS_LOCATION_META_KEY, (string) ($snapshot['location'] ?? ''));
        self::updateCompatStringMeta($order, self::LAST_SYNC_AT_META_KEY, self::LEGACY_LAST_SYNC_AT_META_KEY, (string) ($snapshot['last_sync_at'] ?? current_time('mysql')));

        if (array_key_exists('history', $snapshot) && is_array($snapshot['history'])) {
            self::updateCompatArrayMeta($order, self::STATUS_HISTORY_META_KEY, self::LEGACY_STATUS_HISTORY_META_KEY, $snapshot['history']);
        }

        if (array_key_exists('last_error', $snapshot)) {
            $lastError = trim((string) $snapshot['last_error']);
            if ('' === $lastError) {
                self::clearLastSyncError($order);
            } else {
                self::updateCompatStringMeta($order, self::LAST_SYNC_ERROR_META_KEY, self::LEGACY_LAST_SYNC_ERROR_META_KEY, $lastError);
            }
        }
    }

    public static function clearLastSyncError(WC_Order $order): void
    {
        self::deleteCompatMeta($order, self::LAST_SYNC_ERROR_META_KEY, self::LEGACY_LAST_SYNC_ERROR_META_KEY);
    }

    public static function isDeliveredStatus(string $status, string $label = '', string $description = ''): bool
    {
        $haystack = strtolower(trim(wp_strip_all_tags($status . ' ' . $label . ' ' . $description)));

        foreach (['delivered', 'doručen', 'dorucen', 'prevzat', 'vyzdvihnut'] as $needle) {
            if (strpos($haystack, $needle) !== false) {
                return true;
            }
        }

        return false;
    }

    public static function isTerminalStatus(string $status): bool
    {
        $status = strtolower(trim(wp_strip_all_tags($status)));

        return in_array($status, ['delivered', 'returned', 'cancelled', 'rejected'], true);
    }

    public static function mergeTrackingHistory(\WC_Order $order, array $events): array
    {
        $history = self::getStatusHistory($order);
        $historyIndex = [];

        foreach (array_merge($history, $events) as $event) {
            if (!is_array($event)) {
                continue;
            }

            $normalizedEvent = [
                'status' => sanitize_text_field((string) ($event['status'] ?? '')),
                'label' => sanitize_text_field((string) ($event['label'] ?? '')),
                'description' => sanitize_text_field((string) ($event['description'] ?? '')),
                'date' => sanitize_text_field((string) ($event['date'] ?? current_time('mysql'))),
                'location' => sanitize_text_field((string) ($event['location'] ?? '')),
                'current' => !empty($event['current']),
                'reached' => !empty($event['reached']),
                'source' => sanitize_text_field((string) ($event['source'] ?? 'gls_tracking')),
            ];

            $historyIndex[self::getHistoryHash($normalizedEvent)] = $normalizedEvent;
        }

        $merged = array_values($historyIndex);
        usort($merged, static function ($left, $right) {
            return strcmp((string) ($left['date'] ?? ''), (string) ($right['date'] ?? ''));
        });

        return $merged;
    }

    public static function getHistoryHash(array $event): string
    {
        return md5(wp_json_encode([
            (string) ($event['status'] ?? ''),
            (string) ($event['label'] ?? ''),
            (string) ($event['description'] ?? ''),
            (string) ($event['date'] ?? ''),
            (string) ($event['location'] ?? ''),
        ]));
    }

    private static function getCompatStringMeta(WC_Order $order, string $metaKey, string $legacyMetaKey): string
    {
        $value = trim((string) $order->get_meta($metaKey, true));
        if ('' !== $value) {
            return $value;
        }

        return trim((string) $order->get_meta($legacyMetaKey, true));
    }

    private static function updateCompatStringMeta(WC_Order $order, string $metaKey, string $legacyMetaKey, string $value): void
    {
        $order->update_meta_data($metaKey, $value);
        $order->update_meta_data($legacyMetaKey, $value);
    }

    /**
     * @param array<int, array<string, mixed>> $value
     */
    private static function updateCompatArrayMeta(WC_Order $order, string $metaKey, string $legacyMetaKey, array $value): void
    {
        $order->update_meta_data($metaKey, $value);
        $order->update_meta_data($legacyMetaKey, $value);
    }

    private static function deleteCompatMeta(WC_Order $order, string $metaKey, string $legacyMetaKey): void
    {
        if (method_exists($order, 'delete_meta_data')) {
            $order->delete_meta_data($metaKey);
            $order->delete_meta_data($legacyMetaKey);

            return;
        }

        $order->update_meta_data($metaKey, '');
        $order->update_meta_data($legacyMetaKey, '');
    }
}
