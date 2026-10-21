<?php
/** Chiamate sbagliate apposta, per vedere cosa risponde la documentazione. */
define('ABSPATH', true);
$GLOBALS['filtri'] = [];
function add_filter($n, $fn, $p = 10, $a = 1) { $GLOBALS['filtri'][$n][] = $fn; }
function apply_filters($n, $v) { foreach ($GLOBALS['filtri'][$n] ?? [] as $fn) $v = $fn($v); return $v; }
function __($t, $d = null) { return $t; }

require __DIR__ . '/../includes/class-wp-gpt-ambito-azioni.php';
require __DIR__ . '/../includes/azioni-homeassistant.php';

$T = 'manage-casahomeassistant-domoticandashboard-videocam';

function mostra($titolo, $r) {
    echo "\n══ $titolo\n";
    echo json_encode($r, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), "\n";
}

// 1. la rotta chiamata nuda, come farebbe chi non sa niente
mostra('chiamata senza niente', WGA_Azioni::spiega($T, []));

// 2. un'action inventata
mostra('action inventata: spegni-tutto', WGA_Azioni::spiega($T, ['action' => 'spegni-tutto']));

// 3. action giusta, manca un obbligatorio, e un nome inventato
mostra('control-entity senza service, con un nome sbagliato',
    WGA_Azioni::spiega($T, ['action' => 'control-entity', 'domain' => 'climate', 'entita' => 'x']));

// 4. tool inesistente
mostra('tool che non esiste', WGA_Azioni::spiega('manage-frigorifero', ['action' => 'x']));

// 5. la chiamata giusta: non deve dire niente
mostra('chiamata corretta (deve essere null)',
    WGA_Azioni::spiega($T, ['action' => 'search', 'query' => 'condizionatore soggiorno']));

// 6. UN TOOL A CUI NON SI HA ACCESSO.
// La finta guardia si dichiara con eval, NON come classe normale: PHP issa le
// dichiarazioni di classe in cima al file, quindi una classe scritta qui sotto
// esisterebbe gia' dalla prima riga e avrebbe reso "vietati" anche i casi
// precedenti. (Successo davvero, alla prima stesura di questa prova.)
eval('class WGA_Manifesto { public static function consentiti(): array {
        return ["manage-page-with-user-code"]; } }');
mostra('documentazione di un tool vietato', WGA_Azioni::spiega($T, ['action' => 'search', 'query' => 'x']));

$e = WGA_Azioni::elenco();
echo "\n══ e nell'elenco, con quella guardia, cosa si vede\n";
foreach ($e['tool'] as $t) echo "   - {$t['tool']}\n";
echo "   totale: {$e['totale']}\n";
