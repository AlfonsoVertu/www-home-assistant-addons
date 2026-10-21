<?php
/**
 * Prova la logica che il linter non vede: la ricerca che perdona, il taglio a
 * 300 e la documentazione a tre livelli. Senza WordPress: si finge quel poco
 * che serve.
 */
define('ABSPATH', true);
$GLOBALS['filtri'] = [];
function add_filter($nome, $fn, $p = 10, $n = 1) { $GLOBALS['filtri'][$nome][] = $fn; }
function apply_filters($nome, $valore) {
    foreach ($GLOBALS['filtri'][$nome] ?? [] as $fn) $valore = $fn($valore);
    return $valore;
}
function __($t, $d = null) { return $t; }

require __DIR__ . '/../includes/class-wp-gpt-ambito-azioni.php';
require __DIR__ . '/../includes/azioni-homeassistant.php';

$ok = 0; $ko = 0;
function prova($nome, $condizione) {
    global $ok, $ko;
    if ($condizione) { $ok++; echo "  ok   $nome\n"; }
    else { $ko++; echo "  KO   $nome\n"; }
}

echo "--- la lettera fuori posto\n";
prova('marvin ~ marivn',        WGA_Azioni::una_lettera_spostata('marivn', 'marvin'));
prova('search ~ saerch',        WGA_Azioni::una_lettera_spostata('saerch', 'search'));
prova('camera != cameera',      !WGA_Azioni::una_lettera_spostata('cameera', 'camera'));
prova('search != seaech',       !WGA_Azioni::una_lettera_spostata('seaech', 'search'));
// 'esarch' -> 'search' E' una lettera spostata (la e va avanti di uno): la mia
// prima aspettativa era sbagliata, non il codice. Qui il caso vero da escludere
// e' quello con due lettere fuori posto.
prova('esarch e una lettera',   WGA_Azioni::una_lettera_spostata('esarch', 'search'));
prova('due lettere spostate no', !WGA_Azioni::una_lettera_spostata('esacrh', 'search'));

echo "--- il taglio a 300\n";
$lunga = str_repeat('parola ', 80);
$corta = WGA_Azioni::descrizione_corta($lunga);
prova('sta sotto il tetto',     strlen($corta) <= 300);
// i puntini sono TRE byte: confrontarne uno solo non prova niente
prova('finisce coi puntini',    substr($corta, -3) === '…');
prova('la corta resta intatta', WGA_Azioni::descrizione_corta('breve') === 'breve');
// il caso che il taglio su spazio non protegge: nemmeno uno spazio in 300 byte
$senzaSpazi = str_repeat('perché', 90);
$tagliata = WGA_Azioni::descrizione_corta($senzaSpazi);
prova('non spezza un accento',  mb_check_encoding($tagliata, 'UTF-8'));
prova('sotto il tetto anche li', strlen($tagliata) <= 300);

echo "--- l'elenco\n";
$e = WGA_Azioni::elenco();
prova('gli attrezzi per primi', $e['tool'][0]['tool'] === 'tools-manifest');
prova('spiega il meccanismo',   false !== strpos($e['come_funziona'], 'ACTION'));
prova('c\'e\' la casa',          in_array('manage-casahomeassistant-domoticandashboard-videocam',
                                  array_column($e['tool'], 'tool'), true));
$c = WGA_Azioni::elenco('caemra');           // "camera" con due lettere invertite
prova('trova con refuso',       count($c['tool']) > 0);
$p = WGA_Azioni::elenco('', 1, 1);
prova('la pagina taglia',       count($p['tool']) === 1 && $p['altre_pagine'] === true);

echo "--- la documentazione a tre livelli\n";
$T = 'manage-casahomeassistant-domoticandashboard-videocam';
$l1 = WGA_Azioni::spiega($T, []);
prova('1: senza action -> tool', isset($l1['action_disponibili']) && count($l1['action_disponibili']) === 19);
$l2 = WGA_Azioni::spiega($T, ['action' => 'control-entity', 'domain' => 'light']);
prova('2: manca service',        isset($l2['mancano']) && in_array('service', $l2['mancano'], true));
$l3 = WGA_Azioni::spiega($T, ['action' => 'search', 'query' => 'condizionatore']);
prova('3: chiamata giusta -> null', null === $l3);
$lx = WGA_Azioni::spiega($T, ['action' => 'inventata']);
prova('action inesistente',      isset($lx['errore']) && false !== strpos($lx['errore'], 'non esiste'));

echo "--- i parametri uniti (per lo schema)\n";
$u = WGA_Azioni::parametri_uniti($T);
prova('action per prima',        array_key_first($u) === 'action');
// i nomi stanno in enum, non nella frase: nella frase li troncherebbe il
// tetto dei 300 e le ultime action sparirebbero senza che nessuno lo sappia
prova('le action stanno in enum', ($u['action']['enum'] ?? []) === array_keys(
      WGA_Azioni::tool($T)['azioni']));
prova('sono tutte e diciannove', count($u['action']['enum']) === 19);
prova('nessuna descrizione troncata', 0 === count(array_filter($u, function ($d) {
      return false !== strpos($d['description'], "â¦"); })));
prova('entity_id dice a chi serve',
      false !== strpos($u['entity_id']['description'], 'Obbligatorio per:'));
prova('page e opzionale ovunque',
      false === strpos($u['page']['description'], 'Obbligatorio per:'));

echo "\n$ok passate, $ko fallite\n";
exit($ko ? 1 : 0);
