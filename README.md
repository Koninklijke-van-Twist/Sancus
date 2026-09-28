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

`web/odata.php` blijft verder onaangeroerd. De Mímir-fallback (directe BC-route en cache-key) is een uitzondering, goedgekeurd door Tim Falken op 2026-09-28.
