=== AR Design GLS Fix for WooCommerce ===
Contributors: arpad70
Requires at least: 5.3
Tested up to: 6.9.4
Requires PHP: 7.4
Stable tag: 1.0.11
License: GPLv2
License URI: https://www.gnu.org/licenses/gpl-2.0.txt

Samostatny GLS fix modul pro WooCommerce automatizaci.

== Description ==

Plugin oddeluje GLS automatizaci od AR Design DPD modulu. Zajistuje tracking sync a dorucovaci workflow.

== Changelog ==

= 1.0.11 =
* Po doruceni GLS zasielky sa tlacidlo `Stiahnut stitok` v detaile objednavky aj v prehlade objednavok vykresli ako disabled a uz neobsahuje aktivny odkaz na PDF stitok.

= 1.0.10 =
* Opraveny vyber aktualneho GLS tracking eventu v AR bridge, aby sa snapshot nestacil vratit na historicky stav `Data sent`, ked API vracia eventy od najnovsieho po najstarsi.

= 1.0.9 =
* Release/build pipeline je zladena so spolocnym AR Design build skriptom a GitHub release workflow.
* Pridany explicitny `uninstall.php` pre samostatny release artefakt pluginu.

= 1.0.8 =
* GLS bridge uz neprebera workflow ownership od vendor pluginu a tracking summary bezpecne fallbackuje na vendor meta data.

= 1.0.1 =
* Pridano samostatne zobrazeni GLS shipment detailu.

= 1.0.0 =
* Prvni samostatny release.
