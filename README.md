# Sancus

Kosten/opbrengsten-overzicht voor contracten via Business Central-projectposten.

## Mímir

Zet in `web/auth.php` (niet in git), naast de bestaande BC-gegevens (`$baseUrl`, `$auth` / `$auth_list`, `$environment`):

```php
$mimirApi  = 'mimir_…';
// optioneel:
$mimirBase = 'https://sleutels.kvt.nl/mimir/api';
```

Met `$mimirApi` gaan OData-fetches en company-discovery eerst naar Mímir. Faalt die aanroep (verbinding/timeout, non-2xx, ongeldige JSON of een Mímir-foutpayload), dan haalt Sancus dezelfde data via het oude directe Business Central-pad (`$baseUrl`, `$auth` / `$auth_list`, `$environment`, lokale odata-filecache) en slaat Mímir voor de rest van dat PHP-verzoek over. Laat die BC-credentials in `auth.php` staan naast `$mimirApi`; ontbreken ze, dan komt de oorspronkelijke Mímir-fout terug. Zonder `$mimirApi` blijft alleen het directe BC-pad actief.

`web/index.php`, `web/nightly.php` en `web/hourly.php` laden `auth.php` vóór de OData-calls. Dezelfde fallback geldt voor een live request en voor nightly/hourly, of cron die via HTTP of via `php` (CLI) aanroept. Een CLI-proces houdt de lange Mímir-timeout; een webrequest gebruikt een kortere.

Directe `odata.php`-acties (`cache_status`, `cache_delete`, `cache_clear` en elke andere `action`) gaan eerst door `login/lib.php` en `$allowedUsers`. `cache_status` stuurt geen voorbeeldrijen of attributes. Een runner mag een actie alleen via CLI of via `$sancusRunnerSecret` / `SANCUS_RUNNER_SECRET` in de header `X-Sancus-Secret` (geen queryparameter, leeg geheim telt niet). `web/nightly.php` laadt `odata.php` alleen als bibliotheek. `web/cache/`, `web/cache/odata/` en `web/data/` zijn met `.htaccess` afgesloten.

De Mímir-fallback in `web/odata.php` (directe BC-route en cache-key) blijft de goedgekeurde uitzondering van Tim Falken op 2026-09-28. De login-gate en het weghalen van voorbeeldrijen uit `cache_status` zijn de beveiligingsfix van 2026-10-16.

## Contractlijst (verkoper/klant/gecombineerd)

`salesperson.php` toont eerst alleen de contractkoppen (Onderhoudscontract, Projecten, klantkaart). De kengetallen komen per groepje van `SANCUS_LIST_SUMMARY_GROUP` contracten via `salesperson.php?action=summary` (`project_contract_summary_rows_batched`): AppProjecten, AppWerkorders, AppMaintenanceContracts, ContractPlanningsregels en Projecten met een or-keten op het contractnummer, posten en planningsregels met een or-keten op Job_No. Er wordt geen volledig contractoverzicht per contract meer geladen; de regels laden pas als een contract wordt geopend. Het afgesloten-filter en de Excel-knop wachten tot de totalen binnen zijn. Een hangende lijstpagina na 'Pagina openen…' wordt na 120 s afgebroken met de time-outmelding.

## Nightly: alle contracten in cache

`web/nightly.php` warmt eerst de recent opgezochte contracten op. Daarna haalt het per bedrijf álle contracten met hun lijsttotalen op (klant, verkoper, % afgesloten, ...). Dat gebeurt in bundels van 40 contracten met maximaal 4 Mímir-calls tegelijk en Mímir `max_age` = 4 uur. Het resultaat komt in `web/data/list_summary/all_*.json` (cacheversie `v5`, geldig ±25 uur). Overdag serveren de lijstpagina's uit deze cache. Live ophalen gebeurt alleen als de cache ontbreekt of als je op **Vernieuwen** klikt; dat herschrijft ook de cache. De pagina toont "Gegevens van <datum tijd>".

Plannen (één keer per nacht, bijvoorbeeld 03:30):

- CLI (aanbevolen, geen sessie en geen tijdslimiet): `30 3 * * * php /pad/naar/web/nightly.php > /dev/null 2>&1`
- HTTP: `curl -fsS -H "X-Sancus-Secret: <runner-geheim>" https://<host>/<pad>/nightly.php`. Het geheim staat in `$sancusRunnerSecret` (`auth.php`) of in `SANCUS_RUNNER_SECRET`, en mag nooit als queryparameter worden meegegeven.

Zoeken met contract/project, verkoper én klant leeg toont alle contracten. Datum van/tot en het afgesloten-filter blijven gelden. Alle lijstweergaven (alle contracten, verkoper, klant) tonen maximaal 200 contracten per pagina. De Excel-export bevat alle gefilterde rijen. Een contract direct openen (detailweergave) is niet gepagineerd.
