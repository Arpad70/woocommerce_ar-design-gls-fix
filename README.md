# AR Design GLS Fix for WooCommerce

Samostatny modul pro GLS automatizaci. Modul navazuje na plugin `gls-shipping-for-woocommerce` a oddeluje GLS provozni automatizaci od `ar-design-dpd`.

## Funkce

- zachyti vygenerovani GLS stitku pres hook `gls_label_generated`,
- uklada normalizovana shipment metadata do stavajicich `_ard_shipping_*` klicu,
- synchronizuje otevrene GLS zasilky hodinovym WP-Cron hookem,
- pri doruceni spousti sdileny workflow hook `ard_shipping_shipment_delivered`,
- umi objednavku po doruceni prepnout na `completed`,
- pripravi fakturu pro COD follow-up, pokud je dostupny plugin PDF Invoices & Packing Slips.

## Pozadavky

- WordPress 5.3+
- WooCommerce 7.0+
- PHP 7.4+
- plugin GLS Shipping for WooCommerce

## Instalace

1. Nahrajte adresar `ar-design-gls-fix` do `wp-content/plugins`.
2. Aktivujte plugin `AR Design GLS Fix for WooCommerce`.
3. V administraci WooCommerce otevrette `WooCommerce -> Nastavenia -> Doprava -> GLS Fix`.
4. Zkontrolujte automatizaci a volitelne prepinani objednavek na `completed`.

## Release

```bash
php scripts/verify-version-consistency.php
scripts/build-plugin.sh
```

GitHub Actions workflow `.github/workflows/release.yml` vytvori zip asset `ar-design-gls-fix.zip`.
