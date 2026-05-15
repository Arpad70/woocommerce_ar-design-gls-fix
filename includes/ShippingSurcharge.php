<?php

namespace ArDesign\GlsFix;

defined('ABSPATH') || exit;

class ShippingSurcharge
{
    public static function init(): void
    {
        add_filter('woocommerce_package_rates', [__CLASS__, 'applyToRates'], 9999, 2);
    }

    public static function applyToRates(array $rates, array $package): array
    {
        $settings = Settings::getDefaultSettings();
        $fuelPercent = self::toFloat($settings[Settings::FUEL_SURCHARGE_PERCENT_OPTION_KEY] ?? 0);
        $tollPercent = self::toFloat($settings[Settings::TOLL_SURCHARGE_PERCENT_OPTION_KEY] ?? 0);
        $tollFixed = self::toFloat($settings[Settings::TOLL_SURCHARGE_FIXED_OPTION_KEY] ?? 0);

        if ($fuelPercent <= 0 && $tollPercent <= 0 && $tollFixed <= 0) {
            return $rates;
        }

        foreach ($rates as $rateKey => $rate) {
            if (!$rate instanceof \WC_Shipping_Rate) {
                continue;
            }

            $methodId = strtolower((string) $rate->get_method_id());
            $rateId = strtolower((string) $rate->get_id());

            if (strpos($methodId, 'gls') === false && strpos($rateId, 'gls') === false) {
                continue;
            }

            $baseCost = (float) $rate->get_cost();
            if ($baseCost <= 0) {
                continue;
            }

            $multiplier = 1 + ($fuelPercent / 100) + ($tollPercent / 100);
            $newCost = ($baseCost * $multiplier) + $tollFixed;
            $newCost = (float) wc_format_decimal($newCost, wc_get_price_decimals());

            $rates[$rateKey]->set_cost($newCost);
        }

        return $rates;
    }

    private static function toFloat($value): float
    {
        if (is_string($value)) {
            $value = str_replace(',', '.', $value);
        }

        return is_numeric($value) ? (float) $value : 0.0;
    }
}
