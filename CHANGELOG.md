# Changelog

## 1.0.12 - 2026-05-25

- GitHub updater now accepts both the legacy `ar-design-gls-fix.zip` asset name and versioned release ZIP names such as `ar-design-gls-fix-1.0.12.zip`, restoring WordPress update detection for the current release pipeline.

## 1.0.11 - 2026-05-24

- Tlačidlo `Stiahnuť štítok` sa po doručení GLS zásielky v detaile objednávky aj v prehľade objednávok už nevykresľuje ako aktívny odkaz, ale ako disabled tlačidlo bez možnosti stiahnutia štítku.
- Kontrola doručenia používa shared shipment stav, tracking snapshot meta aj vendor GLS tracking meta, takže disabled stav funguje konzistentne aj pri starších objednávkach s rôznym zdrojom tracking dát.

## 1.0.10 - 2026-05-24

- Opravený výber aktuálneho GLS tracking eventu v AR bridge vrstve: eventy sa teraz normalizujú na zmysluplné workflow stavy, zoradia podľa dátumu a ako current sa vyberie skutočne najnovší event namiesto poslednej položky z API poľa.
- Tracking snapshot meta (`dpd_shipment_tracking_*` a shared shipment stav) sa už neprepíšu späť na historické `Data sent`, keď GLS API vráti `ParcelStatusList` v zostupnom poradí od najnovšej udalosti.

## 1.0.9 - 2026-05-23

- Release/build pipeline je zladená so spoločným AR Design build skriptom a GitHub release workflow.
- Pridaný explicitný `uninstall.php`, aby samostatný release artefakt mal definovaný uninstall contract.

## 1.0.8 - 2026-05-21

- GLS bridge vrstva už nepreberá workflow ownership od vendor pluginu a necháva finálne status rozhodnutia na `gls-shipping-for-woocommerce`.
- Zrušený zostávajúci legacy tracking cron v AR vrstve, aby sa v produkcii nespúšťali paralelné GLS sync cesty.
- Tracking summary teraz bezpečne fallbackuje aj na vendor GLS meta dáta, takže admin a zákazník vidia aktuálny stav aj pri bridge-only režime.

## 1.0.7 - 2026-05-19

- Opravené odpojenie pôvodného DPD order-list callbacku v administrácii, aby sa v produkcii nevykresľoval duplicitný obsah v stĺpci `Export to DPD`.
- GLS guard pre DPD export teraz nahrádza pôvodný renderer korektne a pri GLS objednávkach zobrazuje iba `-` bez druhého tlačidla `Export`.

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
