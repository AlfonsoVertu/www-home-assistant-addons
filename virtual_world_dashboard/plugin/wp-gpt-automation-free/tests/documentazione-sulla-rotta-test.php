<?php
/**
 * La documentazione chiesta sulla rotta stessa, e la risposta corta.
 *
 * Gira senza WordPress: si finge quel poco che serve.
 *   docker run --rm -v .:/src php:8.2-cli php /src/tests/documentazione-sulla-rotta-test.php
 */
define('ABSPATH', true);
$GLOBALS['filtri'] = [];
function add_filter($n, $fn, $p = 10, $a = 1) { $GLOBALS['filtri'][$n][] = $fn; }
function apply_filters($n, $v) { foreach ($GLOBALS['filtri'][$n] ?? [] as $fn) $v = $fn($v); return $v; }
function __($t, $d = null) { return $t; }

require __DIR__ . '/../includes/class-wp-gpt-ambito-azioni.php';
require __DIR__ . '/../includes/azioni-homeassistant.php';
require __DIR__ . '/../includes/azioni-tools-manifest.php';

$ok = 0; $ko = 0;
function prova($nome, $cond) {
    global $ok, $ko;
    if ($cond) { $ok++; echo "  ok   $nome\n"; } else { $ko++; echo "  KO   $nome\n"; }
}

$T = 'manage-casahomeassistant-domoticandashboard-videocam';

echo "--- la risposta a chi sbaglia dev'essere CORTA\n";
$r = WGA_Azioni::spiega($T, []);
$peso = strlen(json_encode($r, JSON_UNESCAPED_UNICODE));
// Chi arriva senza action non ha capito la FORMA: qui i gradini valgono i loro
// byte. Resta comunque un paragrafo, non un manuale.
prova('resta un paragrafo', $peso < 2200);
prova('dice cosa fa il tool', !empty($r['cosa_fa']));
prova('elenca i NOMI, non le schede',
      isset($r['action_disponibili']) && $r['action_disponibili'] === array_values($r['action_disponibili']));
prova('sono diciannove', count($r['action_disponibili']) === 19);
prova('spiega lo scarto con l addestramento',
      false !== strpos(json_encode($r['come_funziona_qui']), 'Qui no'));
prova('da un esempio subito usabile', isset($r['esempio_di_chiamata']['action']));
// Chi sbaglia un parametro la forma l'ha gia' capita: rispiegargliela lo
// allontanerebbe dalla riga che cerca. Quella risposta deve restare stretta.
$r2 = WGA_Azioni::spiega($T, ['action' => 'control-entity', 'domain' => 'climate']);
prova('l errore di parametro resta corto', strlen(json_encode($r2)) < 1200);
prova('e non rispiega la forma', !isset($r2['come_funziona_qui']));
prova('e riusa il valore gia giusto', $r2['esempio_di_chiamata']['domain'] === 'climate');
echo "     (peso della risposta: $peso byte)\n";

echo "--- documentazione_<action> sulla rotta stessa\n";
prova('riconosce il prefisso', WGA_Azioni::doc_chiesta('documentazione_search') === 'search');
prova('ignora le altre action', WGA_Azioni::doc_chiesta('search') === '');
$d = WGA_Azioni::documentazione_di($T, 'control-entity');
prova('da\' i parametri di quella action', isset($d['parametri']['service']));
prova('dice quali sono obbligatori', true === $d['parametri']['service']['obbligatorio']);
prova('e quali no', false === $d['parametri']['entity_id']['obbligatorio']);
prova('non porta le altre action', !isset($d['action_disponibili']));

echo "--- action sbagliata dentro la richiesta di documentazione\n";
$x = WGA_Azioni::documentazione_di($T, 'serch');
prova('non dice solo no', isset($x['action_disponibili']) && count($x['action_disponibili']) === 19);

echo "--- tools-manifest si comporta come tutti gli altri\n";
$m = WGA_Azioni::tool('tools-manifest');
prova('e\' nel registro', !empty($m['azioni']));
prova('chiamato nudo non e\' un errore', null === WGA_Azioni::spiega('tools-manifest', []));
$ms = WGA_Azioni::spiega('tools-manifest', ['action' => 'scopri-parametri-dei-tool']);
prova('senza tool avvisa', isset($ms['mancano']) && in_array('tool', $ms['mancano'], true));
$md = WGA_Azioni::documentazione_di('tools-manifest', 'lista-dei-tool-che-contengono-le-action');
prova('si documenta da se\'', isset($md['parametri']['cerca']));

echo "\n$ok passate, $ko fallite\n";
exit($ko ? 1 : 0);
