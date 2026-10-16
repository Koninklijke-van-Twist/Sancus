<?php
/**
 * Auth-template voor Sancus. Kopieer naar web/auth.php (niet in git).
 *
 * Mímir eerst, en houd het BC-blok als automatische fallback als Mímir plat ligt:
 *   $mimirApi  = 'mimir_…';  // verplicht om Mímir te activeren
 *   $mimirBase = 'https://sleutels.kvt.nl/mimir/api'; // optioneel
 *
 * Met $mimirApi gezet gaan fetches eerst naar Mímir en vallen terug op de BC-variabelen hieronder.
 * Zonder $mimirApi wordt alleen het BC-blok gebruikt.
 * Laat $auth_list / $environment / $auth / $baseUrl naast $mimirApi staan.
 */

// --- Mímir (aanbevolen) ---
// $mimirApi  = 'mimir_…';
// $mimirBase = 'https://sleutels.kvt.nl/mimir/api';

// --- Business Central (direct pad, én fallback als Mímir faalt) ---
$auth_list =
    [
        "env1" => ['mode' => 'basic', 'user' => 'USERNAME', 'pass' => 'PASSWORD'],
    ];
$environment = "env1";
$auth = $auth_list[$environment];
$baseUrl = "https://my-bc-domain.com:7148/";

$allowedUsers = [
    "user@domain.nl"
];

// Optioneel, alleen als een HTTP-runner odata.php?action=… moet aanroepen.
// Nightly laadt odata.php als bibliotheek en raakt deze acties niet.
// Header X-Sancus-Secret moet exact dit geheim zijn. Weglaten: geen anonieme toegang.
// CLI (php web/nightly.php) heeft dit niet nodig.
// $sancusRunnerSecret = '';
