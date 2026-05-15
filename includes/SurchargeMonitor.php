<?php

namespace ArDesign\GlsFix;

defined('ABSPATH') || exit;

class SurchargeMonitor
{
    private const CRON_HOOK = 'ard_gls_surcharge_sync_event';
    private const CRON_SCHEDULE = 'ard_weekly';
    private const MANUAL_SYNC_ACTION = 'ard_gls_surcharge_sync_now';
    private const MANUAL_SYNC_NONCE = 'ard_gls_surcharge_sync_now_nonce';
    private const STATE_OPTION_KEY = 'ar_design_gls_surcharge_monitor_state';
    private const SOURCE_URL_FUEL = 'https://gls-group.com/SK/sk/palivovy-priplatok/';
    private const SOURCE_URL_TOLL = 'https://gls-group.com/SK/sk/myto/';

    public static function init(): void
    {
        add_filter('cron_schedules', [__CLASS__, 'registerCronSchedule']);
        add_action(self::CRON_HOOK, [__CLASS__, 'runSync']);
        add_action('admin_post_' . self::MANUAL_SYNC_ACTION, [__CLASS__, 'handleManualSyncRequest']);
        add_action('init', [__CLASS__, 'ensureCronScheduled']);
        add_action('admin_notices', [__CLASS__, 'renderAdminNotice']);
    }

    public static function handleManualSyncRequest(): void
    {
        if (!current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('Nedostatečné oprávnění.', 'ar-design-gls-fix'));
        }

        check_admin_referer(self::MANUAL_SYNC_ACTION, self::MANUAL_SYNC_NONCE);

        self::runSync();

        $redirect = wp_get_referer();
        if (!$redirect) {
            $redirect = admin_url('admin.php?page=wc-settings&tab=shipping&section=' . Settings::SETTINGS_ID_KEY);
        }

        wp_safe_redirect(add_query_arg('ard_gls_manual_sync', '1', $redirect));
        exit;
    }

    public static function getManualSyncUrl(): string
    {
        return wp_nonce_url(
            admin_url('admin-post.php?action=' . self::MANUAL_SYNC_ACTION),
            self::MANUAL_SYNC_ACTION,
            self::MANUAL_SYNC_NONCE
        );
    }

    public static function getAdminStatusHtml(): string
    {
        $state = self::getState();
        $checkedAt = !empty($state['last_checked_at']) ? wp_date('d.m.Y H:i', (int) $state['last_checked_at']) : null;
        $fuelPercent = isset($state['values']['fuel_percent']) && $state['values']['fuel_percent'] !== null ? self::formatPercent((float) $state['values']['fuel_percent']) . ' %' : __('není dostupné', 'ar-design-gls-fix');
        $tollPerKg = isset($state['values']['toll_fixed_per_kg']) && $state['values']['toll_fixed_per_kg'] !== null ? self::formatMoney((float) $state['values']['toll_fixed_per_kg']) . ' EUR/kg' : __('není dostupné', 'ar-design-gls-fix');

        $html = '<p><strong>' . esc_html__('Stav z CRONu', 'ar-design-gls-fix') . ':</strong> ';
        $html .= $checkedAt ? esc_html(sprintf(__('naposledy %s', 'ar-design-gls-fix'), $checkedAt)) : esc_html__('zatím neproběhl', 'ar-design-gls-fix');
        $html .= '</p>';
        $html .= '<p>' . esc_html(sprintf(__('Palivový příplatek: %1$s. Mýtný poplatek: %2$s.', 'ar-design-gls-fix'), $fuelPercent, $tollPerKg)) . '</p>';
        $html .= '<p><a class="button button-primary" href="' . esc_url(self::getManualSyncUrl()) . '">' . esc_html__('Načíst ceny ručně', 'ar-design-gls-fix') . '</a></p>';

        return $html;
    }

    public static function registerCronSchedule(array $schedules): array
    {
        if (!isset($schedules[self::CRON_SCHEDULE])) {
            $schedules[self::CRON_SCHEDULE] = [
                'interval' => WEEK_IN_SECONDS,
                'display' => __('Once weekly', 'ar-design-gls-fix'),
            ];
        }

        return $schedules;
    }

    public static function ensureCronScheduled(): void
    {
        $timestamp = wp_next_scheduled(self::CRON_HOOK);
        $nextRun = self::getNextMondayTenTimestamp();

        if (!$timestamp) {
            wp_schedule_event($nextRun, self::CRON_SCHEDULE, self::CRON_HOOK);
            return;
        }

        if (abs($timestamp - $nextRun) > DAY_IN_SECONDS) {
            wp_unschedule_event($timestamp, self::CRON_HOOK);
            wp_schedule_event($nextRun, self::CRON_SCHEDULE, self::CRON_HOOK);
        }
    }

    public static function runSync(): void
    {
        $state = self::getState();
        $result = self::fetchCurrentSurcharges();

        $state['last_checked_at'] = time();
        $state['last_error'] = $result['error'] ?? '';

        $newValues = [];
        if (isset($result['values']['fuel_percent']) && $result['values']['fuel_percent'] !== null) {
            $newValues['fuel_percent'] = (float) $result['values']['fuel_percent'];
        }
        if (isset($result['values']['toll_fixed_per_kg']) && $result['values']['toll_fixed_per_kg'] !== null) {
            $newValues['toll_fixed_per_kg'] = (float) $result['values']['toll_fixed_per_kg'];
        }

        if (!empty($newValues)) {
            $newHash = md5(wp_json_encode($newValues));
            $oldHash = (string) ($state['last_hash'] ?? '');

            if ($oldHash !== '' && $oldHash !== $newHash) {
                $state['pending_notice'] = self::buildChangeNotice((array) ($state['values'] ?? []), $newValues);
            }

            $state['values'] = $newValues;
            $state['last_hash'] = $newHash;
            $state['source_urls'] = [self::SOURCE_URL_FUEL, self::SOURCE_URL_TOLL];
        }

        update_option(self::STATE_OPTION_KEY, $state, false);
    }

    public static function renderAdminNotice(): void
    {
        if (!current_user_can('manage_woocommerce')) {
            return;
        }

        $state = self::getState();
        $notice = trim((string) ($state['pending_notice'] ?? ''));

        if ($notice === '') {
            return;
        }

        echo '<div class="notice notice-warning"><p>' . esc_html($notice) . '</p></div>';

        $state['pending_notice'] = '';
        update_option(self::STATE_OPTION_KEY, $state, false);
    }

    public static function getHelperTexts(): array
    {
        $state = self::getState();
        $values = (array) ($state['values'] ?? []);
        $checkedAt = !empty($state['last_checked_at']) ? wp_date('d.m.Y H:i', (int) $state['last_checked_at']) : null;

        $prefix = $checkedAt
            ? sprintf(__('Poslední kontrola CRONem (%s): ', 'ar-design-gls-fix'), $checkedAt)
            : __('Poslední kontrola CRONem: ', 'ar-design-gls-fix');

        $fuel = isset($values['fuel_percent']) && $values['fuel_percent'] !== null
            ? $prefix . sprintf(__('palivový příplatek je aktuálně %s %% podle ceníku GLS (zdroj: %s).', 'ar-design-gls-fix'), self::formatPercent((float) $values['fuel_percent']), self::SOURCE_URL_FUEL)
            : __('Aktuální palivový příplatek se zatím nepodařilo z ceníku zjistit. Zkontrolujte jej prosím ručně.', 'ar-design-gls-fix');

        $tollPerKg = isset($values['toll_fixed_per_kg']) && $values['toll_fixed_per_kg'] !== null
            ? sprintf(__('mýtný poplatek je aktuálně %s EUR za každý další kilogram podle ceníku GLS', 'ar-design-gls-fix'), self::formatMoney((float) $values['toll_fixed_per_kg']))
            : __('Aktuální mýtný poplatek se zatím nepodařilo z ceníku zjistit.', 'ar-design-gls-fix');

        $tollPercentField = __('GLS mýto je v ceníku vedeno jako částka za kg, nikoli procento. Použijte pole pevné částky.', 'ar-design-gls-fix');
        $fixed = $prefix . sprintf(__('%s (zdroj: %s).', 'ar-design-gls-fix'), $tollPerKg, self::SOURCE_URL_TOLL);

        if (!empty($state['last_error'])) {
            $suffix = sprintf(__(' Posledná chyba synchronizácie: %s', 'ar-design-gls-fix'), (string) $state['last_error']);
            $fuel .= $suffix;
            $tollPercentField .= $suffix;
            $fixed .= $suffix;
        }

        return [
            Settings::FUEL_SURCHARGE_PERCENT_OPTION_KEY => $fuel,
            Settings::TOLL_SURCHARGE_PERCENT_OPTION_KEY => $tollPercentField,
            Settings::TOLL_SURCHARGE_FIXED_OPTION_KEY => $fixed,
        ];
    }

    private static function fetchCurrentSurcharges(): array
    {
        $fuelResult = self::fetchUrl(self::SOURCE_URL_FUEL);
        $tollResult = self::fetchUrl(self::SOURCE_URL_TOLL);

        $errors = [];
        if (!empty($fuelResult['error'])) {
            $errors[] = $fuelResult['error'];
        }
        if (!empty($tollResult['error'])) {
            $errors[] = $tollResult['error'];
        }

        $values = [
            'fuel_percent' => self::extractExplicitFuelPercent((string) ($fuelResult['body'] ?? '')),
            'toll_fixed_per_kg' => self::extractStrictTollPerKg((string) ($tollResult['body'] ?? '')),
        ];

        if ($values['fuel_percent'] === null) {
            $errors[] = __('GLS palivový príplatok sa nepodarilo získať z verejne dostupného textu stránky.', 'ar-design-gls-fix');
        }
        if ($values['toll_fixed_per_kg'] === null) {
            $errors[] = __('GLS mýtny poplatok sa nepodarilo vyparsovať zo stránky.', 'ar-design-gls-fix');
        }

        return [
            'values' => $values,
            'error' => $errors ? implode(' | ', $errors) : '',
        ];
    }

    private static function fetchUrl(string $url): array
    {
        $response = wp_remote_get($url, [
            'timeout' => 20,
            'redirection' => 5,
            'user-agent' => 'AR-Design-Surcharge-Monitor/1.0',
        ]);

        if (is_wp_error($response)) {
            return ['body' => '', 'error' => $response->get_error_message()];
        }

        $status = (int) wp_remote_retrieve_response_code($response);
        if ($status < 200 || $status >= 300) {
            return ['body' => '', 'error' => sprintf('HTTP %d for %s', $status, $url)];
        }

        return [
            'body' => (string) wp_remote_retrieve_body($response),
            'error' => '',
        ];
    }

    private static function extractExplicitFuelPercent(string $html): ?float
    {
        if ($html === '') {
            return null;
        }

        $text = html_entity_decode(wp_strip_all_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/\s+/u', ' ', $text) ?: $text;

        if (!preg_match_all('/aktu[aá]ln[ýy][^\.\n\r]{0,60}?palivov[ýy]\s+pr[ií]platok[^\.\n\r]{0,160}?([0-9]{1,2}(?:[\.,][0-9]{1,2})?)\s*%/iu', $text, $matches)) {
            return null;
        }

        if (!empty($matches[1])) {
            $value = end($matches[1]);
            return self::toFloat($value);
        }

        return null;
    }

    private static function extractStrictTollPerKg(string $html): ?float
    {
        if ($html === '') {
            return null;
        }

        $text = html_entity_decode(wp_strip_all_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/\s+/u', ' ', $text) ?: $text;

        if (preg_match_all('/z\s+p[ôo]vodn[ýy]ch\s+[0-9]{1,2}(?:[\.,][0-9]{1,3})?\s*(?:€|eur)\s+na\s+([0-9]{1,2}(?:[\.,][0-9]{1,3})?)\s*(?:€|eur)[^\n\r]{0,140}(?:kg|kilogram|kilogramu)/iu', $text, $matches) && !empty($matches[1])) {
            $value = end($matches[1]);
            return self::toFloat($value);
        }

        if (preg_match_all('/je\s+poplatok\s+([0-9]{1,2}(?:[\.,][0-9]{1,3})?)\s*(?:€|eur)[^\n\r]{0,140}(?:na\s+každ[ýy]\s+ďalš[ií]\s+kilogram|kg|kilogram|kilogramu)/iu', $text, $matches) && !empty($matches[1])) {
            $value = end($matches[1]);
            return self::toFloat($value);
        }

        if (preg_match_all('/m[ýy]to[^\.\n\r]{0,220}?([0-9]{1,2}(?:[\.,][0-9]{1,3})?)\s*(?:€|eur)[^\n\r]{0,120}(?:kg|kilogram|kilogramu)/iu', $text, $matches) && !empty($matches[1])) {
            $value = end($matches[1]);
            return self::toFloat($value);
        }

        return null;
    }

    private static function getState(): array
    {
        $state = get_option(self::STATE_OPTION_KEY, []);

        return is_array($state) ? $state : [];
    }

    private static function buildChangeNotice(array $oldValues, array $newValues): string
    {
        $oldFuel = isset($oldValues['fuel_percent']) && $oldValues['fuel_percent'] !== null ? self::formatPercent((float) $oldValues['fuel_percent']) . ' %' : 'n/a';
        $newFuel = isset($newValues['fuel_percent']) && $newValues['fuel_percent'] !== null ? self::formatPercent((float) $newValues['fuel_percent']) . ' %' : 'n/a';
        $oldToll = isset($oldValues['toll_fixed_per_kg']) && $oldValues['toll_fixed_per_kg'] !== null ? self::formatMoney((float) $oldValues['toll_fixed_per_kg']) . ' EUR/kg' : 'n/a';
        $newToll = isset($newValues['toll_fixed_per_kg']) && $newValues['toll_fixed_per_kg'] !== null ? self::formatMoney((float) $newValues['toll_fixed_per_kg']) . ' EUR/kg' : 'n/a';

        return sprintf(
            __('GLS príplatky sa zmenili: palivový %1$s → %2$s, mýtny poplatok %3$s → %4$s. Skontrolujte nastavenia dopravy.', 'ar-design-gls-fix'),
            $oldFuel,
            $newFuel,
            $oldToll,
            $newToll
        );
    }

    private static function getNextMondayTenTimestamp(): int
    {
        $tz = wp_timezone();
        $now = new \DateTimeImmutable('now', $tz);
        $target = $now->setTime(10, 0, 0);
        $weekday = (int) $now->format('N');
        $daysUntilMonday = (8 - $weekday) % 7;
        $next = $target->modify('+' . $daysUntilMonday . ' days');

        if ($daysUntilMonday === 0 && $now >= $target) {
            $next = $next->modify('+7 days');
        }

        return $next->getTimestamp();
    }

    private static function toFloat($value): float
    {
        if (is_string($value)) {
            $value = str_replace(',', '.', $value);
        }

        return is_numeric($value) ? (float) $value : 0.0;
    }

    private static function formatPercent(float $value): string
    {
        return number_format($value, 2, '.', '');
    }

    private static function formatMoney(float $value): string
    {
        return number_format($value, 3, '.', '');
    }
}
