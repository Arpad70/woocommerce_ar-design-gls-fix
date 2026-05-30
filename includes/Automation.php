<?php

namespace ArDesign\GlsFix;

use WC_Order;

defined('ABSPATH') || exit;

class Automation
{
	private const DELIVERY_EVENT = 'ard_shipping_shipment_delivered';

    public static function init(): void
    {
		add_action(self::getDeliveryEventName(), [__CLASS__, 'handleDeliveredShipment'], 5, 3);
    }

    public static function handleDeliveredShipment(mixed $order_id, mixed $shipmentData = [], mixed $order = null): void
    {
        $order = $order instanceof WC_Order ? $order : wc_get_order($order_id);

        if (!$order instanceof WC_Order || (string) ($shipmentData['carrier'] ?? '') !== GlsBridge::CARRIER) {
            return;
        }

        if (!self::shouldHandleDeliveryWorkflowLocally($order, is_array($shipmentData) ? $shipmentData : [])) {
            return;
        }

        if ($order->get_meta(Shipment::DELIVERY_WORKFLOW_PROCESSED_AT_META_KEY, true)) {
            return;
        }

        $order->add_order_note(__('GLS delivery status confirmed successful delivery. Order workflow status is left to the primary GLS workflow owner.', 'ar-design-gls-fix'));

        if (self::shouldSendInvoiceAfterDelivery($order)) {
            $invoiceFile = self::ensureInvoiceFile($order);

            if ($invoiceFile) {
                $order->update_meta_data(Shipment::INVOICE_FILE_META_KEY, $invoiceFile);
                $order->add_order_note(__('Invoice was prepared for cash on delivery follow-up email.', 'ar-design-gls-fix'));
            }
        }

        $order->update_meta_data(Shipment::DELIVERY_WORKFLOW_PROCESSED_AT_META_KEY, current_time('mysql'));
        $order->save_meta_data();
    }

    public static function shouldSendInvoiceAfterDelivery(WC_Order $order): bool
    {
        $codPaymentMethods = (array) apply_filters('ard_gls_fix_cod_payment_method_ids', ['cod'], $order);

        return in_array($order->get_payment_method(), $codPaymentMethods, true);
    }

    public static function ensureInvoiceFile(WC_Order $order): ?string
    {
		if (function_exists('ard_workflow_get_invoice_file_for_order')) {
            return \ard_workflow_get_invoice_file_for_order($order);
		}

		if (!function_exists('wcpdf_get_document') || !function_exists('wcpdf_get_document_file')) {
			return null;
		}

		$document = wcpdf_get_document('invoice', $order, true);
		if (!$document) {
			return null;
		}

		$file = wcpdf_get_document_file($document, 'pdf');

		return $file && file_exists($file) ? $file : null;
    }

    private static function shouldHandleDeliveryWorkflowLocally(WC_Order $order, array $shipmentData): bool
    {
        $owner = function_exists('apply_filters')
            ? (string) apply_filters('ard_shipping_delivery_workflow_owner', 'carrier', $order, $shipmentData)
            : 'carrier';

        return 'carrier' === $owner;
    }

	private static function getDeliveryEventName(): string
	{
		return defined('ARD_WORKFLOW_EVENT_SHIPMENT_DELIVERED')
			? (string) ARD_WORKFLOW_EVENT_SHIPMENT_DELIVERED
			: self::DELIVERY_EVENT;
	}
}
