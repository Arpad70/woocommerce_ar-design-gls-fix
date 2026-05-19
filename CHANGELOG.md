# Changelog

## 1.0.6 - 2026-05-19

- Skrytý DPD `Export` button v stĺpci `Export to DPD` pre objednávky, ktoré už patria do GLS workflow, aby administrácia neponúkala zavádzajúci export do nesprávneho dopravcu.
- GLS fix teraz pre detekciu GLS objednávok zohľadňuje shared shipment carrier, GLS tracking čísla, secure label URL aj GLS shipping method.

## 1.0.5 - 2026-05-19

- Opraveny GLS order list fallbacky tak, aby stĺpec `GLS Tracking Number` zobrazoval iba skutočné GLS štítky a GLS tracking čísla.
- Zablokované zobrazenie DPD údajov v GLS stĺpci pri objednávkach so zdieľanými shipment meta dátami.

## 1.0.4 - 2026-05-19

- GLS order list teraz zobrazuje vo stĺpci `GLS Tracking Number` tlačidlo `Stiahnuť štítok` a tracking čísla v rovnakom vizuálnom štýle ako AR Design DPD export stĺpec.
- Úprava je dodaná cez `ar-design-gls-fix`, bez potreby patchovať upstream plugin `gls-shipping-for-woocommerce`.

## 1.0.3 - 2026-05-15

- Pridane nastavenia a monitoring shipping surcharge pre GLS workflow.
- Release dorovnava lokalne zmeny s GitHub release stavom.

## 1.0.1

- Pridano samostatne zobrazeni GLS shipment detailu v administraci objednavky a detailu objednavky.

## 1.0.0

- Oddelen GLS bridge z modulu `ar-design-dpd` do samostatneho pluginu.
- Pridana samostatna GLS automatizace, nastaveni, updater a release workflow.
- Zachovana kompatibilita se stavajicimi shipment meta klici `_ard_shipping_*`.
