<?php
/**
 * Genera il pezzo di OpenAPI dei tool che hanno dichiarato le loro action.
 *
 * PERCHE' UN GENERATORE E NON UN FILE SCRITTO A MANO. openapi.yml oggi e'
 * scritto a mano: trecentocinquantamila byte che invecchiano dal giorno dopo, e
 * che nessuno confronta piu' con il codice. Il registro delle action invece sta
 * accanto alle rotte e cambia con loro. Da li' l'OpenAPI si ricava; il
 * contrario no.
 *
 * NON SOVRASCRIVE NIENTE. Scrive un file suo. Il registro copre due tool su
 * novantacinque: rigenerare l'intero openapi.yml adesso vorrebbe dire cancellare
 * la documentazione di tutti gli altri per averla giusta su due. Quando il
 * registro sara' completo, questo stesso generatore potra' produrre il file
 * intero - ed e' il momento in cui openapi.yml smettera' di essere una copia
 * ferma.
 *
 * OGNI PARAMETRO SI PORTA DIETRO PER CHI E' OBBLIGATORIO. E' la sola cosa che
 * lo schema, da solo, non sa dire: qui un endpoint solo cambia forma a ogni
 * action, e "required" a livello di schema mentirebbe. La verita' sta nel
 * testo, e nel testo ci sta.
 *
 *   docker run --rm -v .:/src php:8.2-cli php /src/tools/genera-openapi-dalle-azioni.php
 */

define('ABSPATH', true);
$GLOBALS['filtri'] = [];
function add_filter($n, $fn, $p = 10, $a = 1) { $GLOBALS['filtri'][$n][] = $fn; }
function apply_filters($n, $v) { foreach ($GLOBALS['filtri'][$n] ?? [] as $fn) $v = $fn($v); return $v; }
function __($t, $d = null) { return $t; }

$radice = dirname(__DIR__);
require $radice . '/includes/class-wp-gpt-ambito-azioni.php';
foreach (glob($radice . '/includes/azioni-*.php') as $f) require $f;

/**
 * Il nome dell'operazione, nella convenzione che il plugin usa da sempre.
 *
 * CAMELCASE E NON SNAKE_CASE, e non e' una preferenza. Gli operationId sono
 * l'aggancio fra tre cose: lo schema, il catalogo dei pacchetti in
 * assets/gpt-route-categories.json, e i GPT che i clienti hanno gia' creato.
 * Cambiare convenzione li scollega tutti in una volta - il catalogo smette di
 * riconoscere le rotte e i GPT gia' in giro chiamano nomi che non esistono
 * piu'. La forma vecchia era manage-articles -> manageArticlesWithUserCode, e
 * quella resta.
 */
function operation_id(string $nome): string {
    $pulito = WGA_Azioni::nome_pulito($nome);
    $pezzi = preg_split('/[^a-zA-Z0-9]+/', $pulito, -1, PREG_SPLIT_NO_EMPTY);
    if (!$pezzi) return $pulito;
    $primo = array_shift($pezzi);
    return lcfirst($primo) . implode('', array_map('ucfirst', $pezzi));
}

/** Una stringa YAML sicura: si cita sempre, e si scappano le virgolette. */
function y(string $s): string {
    return '"' . str_replace(['\\', '"', "\n"], ['\\\\', '\\"', ' '], trim($s)) . '"';
}

$registro = WGA_Azioni::registro();

// I percorsi VERI delle rotte, letti dal codice.
//
// La chiave del registro non e' l'indirizzo: il dispatcher cerca il tool con
// l'ultimo pezzo del percorso, quindi /wp-gpt/v1/memory/goal sta in registro
// sotto 'goal'. Ricostruire l'indirizzo da quella chiave dava /v1/goal, che non
// risponde. Chi genera un GPT da questo file deve trovarci indirizzi che
// esistono, non nomi di comodo.
$percorsi_veri = [];
$giro = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($radice . '/includes'));
foreach ($giro as $f) {
    if (!$f->isFile() || strtolower($f->getExtension()) !== 'php') continue;
    $t = file_get_contents($f->getPathname());

    $costanti = [];
    preg_match_all("#const\s+(\w+)\s*=\s*'([^']+)'#", $t, $mc, PREG_SET_ORDER);
    foreach ($mc as $c) $costanti[$c[1]] = $c[2];

    preg_match_all("#register_rest_route\(\s*[^,]+,\s*(?:'([^']*)'\s*\.\s*self::(\w+)|'([^']+)'|self::(\w+))#",
                   $t, $mr, PREG_SET_ORDER);
    foreach ($mr as $r) {
        if (!empty($r[2]))      $p = ($r[1] ?? '') . ($costanti[$r[2]] ?? '');
        elseif (!empty($r[3]))  $p = $r[3];
        elseif (!empty($r[4]))  $p = $costanti[$r[4]] ?? '';
        else continue;
        if ($p === '') continue;

        $p = rtrim(preg_replace('#\(\?P<\w+>[^)]*\)#', '', $p), '/');
        $p = '/' . ltrim($p, '/');
        $chiave = WGA_Azioni::nome_pulito($p);
        // La forma piu' lunga vince: se una rotta e' registrata sia con l'id
        // nello slug sia senza, l'indirizzo buono e' quello senza, ma fra
        // memory/goal e goal va tenuto memory/goal.
        if (!isset($percorsi_veri[$chiave]) || strlen($p) > strlen($percorsi_veri[$chiave])) {
            $percorsi_veri[$chiave] = $p;
        }
    }
}
$righe = [];
$righe[] = '# GENERATO da tools/genera-openapi-dalle-azioni.php - non modificare a mano.';
$righe[] = '# La fonte sono le dichiarazioni in includes/azioni-*.php, che stanno';
$righe[] = '# accanto alle rotte e cambiano con loro.';
// Le righe dei soli paths: e' il pezzo che la guida in backend incolla sotto la
// sua intestazione, costruita con il dominio del sito vero.
$righe[] = 'paths:';

$conta_tool = 0; $conta_par = 0; $troppo_lunghi = 0; $visti = [];

/*
 * L'ordine dei tool nello schema non e' un dettaglio estetico.
 *
 * Chi legge uno schema con quaranta endpoint - una persona o un modello - non
 * lo legge tutto: guarda i primi e si fa un'idea di che strumento e'. Se il
 * primo che incontra e' quello che spiega come funzionano tutti gli altri,
 * parte sapendo dove chiedere. Se invece il primo e' Minecraft, si fa un'idea
 * sbagliata di tutto il resto.
 *
 * tools-manifest sta in testa perche' e' la documentazione: e' il tool che
 * risponde alla domanda "cosa posso fare qui". Dietro vengono quelli che
 * riguardano il funzionamento dello strumento in se', e poi tutti gli altri in
 * ordine alfabetico, che non e' un criterio ma almeno e' prevedibile.
 */
$PRIMI = [
    'tools-manifest',                                                    // la documentazione
    'goal',                                                              // la memoria
    'manage-advanced-memory-post',                                       // la memoria dei contenuti
    'gather-information-youtube-google-serp-keywrd-analisys-image-link', // cercare fuori
    'webpage-link-and-twitter-scraping',                                 // leggere pagine e post
    'read-post-of-all-type-paginated',                                   // cercare dentro il sito
    'manage-articles-with-user-code',                                    // scrivere
    'manage-file',                     // caricare file e immagini
];

uksort($registro, function ($a, $b) use ($PRIMI) {
    $pa = array_search(WGA_Azioni::nome_pulito($a), $PRIMI, true);
    $pb = array_search(WGA_Azioni::nome_pulito($b), $PRIMI, true);
    if ($pa !== false && $pb !== false) return $pa <=> $pb;
    if ($pa !== false) return -1;
    if ($pb !== false) return 1;
    return strcmp($a, $b);
});

foreach ($registro as $nome => $t) {
    if (empty($t['azioni'])) continue;
    $conta_tool++;
    // Il prefisso e' /v1/ e non /wp-gpt/v1/: il server dichiarato sopra finisce
    // gia' con /wp-json/wp-gpt, quindi ripetere wp-gpt qui produce un indirizzo
    // doppio che non risponde. Il pezzo che manca sta nel server, non nel path.
    $chiave = WGA_Azioni::nome_pulito($nome);

    // Solo le chiavi che corrispondono a una rotta vera. Nel registro ci sono
    // anche degli alias - contact-form7 punta alla stessa scheda di forms -
    // che servono a chi cerca la documentazione per nome. Scriverli qui
    // creerebbe nello schema un indirizzo che non risponde, e un GPT costruito
    // da questo file ci sbatterebbe contro.
    if (!isset($percorsi_veri[$chiave])) continue;
    $percorso = '/v1' . $percorsi_veri[$chiave];
    if (isset($visti[$percorso])) continue;
    $visti[$percorso] = true;
    // Il tetto si applica ALLA FINE, non alla partenza. Prima si accorciava la
    // descrizione a 300 e poi ci si attaccava la frase di contorno: il risultato
    // usciva a 375 caratteri, oltre il limite, e nessuno se ne accorgeva perche'
    // il conteggio guardava il pezzo di prima.
    $grezza = trim($t['descrizione'] ?? '');
    $descrizione = WGA_Azioni::descrizione_corta($grezza);

    // La frase di contorno si aggiunge solo se ci sta tutta. Prima si
    // concatenava e poi si tagliava a 300: usciva una descrizione che finisce a
    // meta' parola con i puntini - "Un solo…" - che e' peggio di non averla,
    // perche' sembra che manchi qualcosa di importante e nessuno sa cosa.
    $contorno = ' Un solo endpoint con tante action: il parametro action decide '
              . 'quali altri parametri servono.';
    $estesa = (strlen($descrizione . $contorno) <= WGA_Azioni::TETTO_DESCRIZIONE)
        ? $descrizione . $contorno
        : $descrizione;

    $righe[] = '  ' . $percorso . ':';
    $righe[] = '    post:';
    $righe[] = '      operationId: ' . operation_id($nome);
    $righe[] = '      summary: ' . y($descrizione);
    $righe[] = '      description: ' . y($estesa);
    $righe[] = '      requestBody:';
    $righe[] = '        required: true';
    $righe[] = '        content:';
    $righe[] = '          application/json:';
    $righe[] = '            schema:';
    $righe[] = '              type: object';
    $righe[] = '              required: [action]';
    $righe[] = '              properties:';

    foreach (WGA_Azioni::parametri_uniti($nome) as $par => $def) {
        $conta_par++;
        // I parametri hanno un tetto piu' alto degli endpoint: OpenAI dichiara
        // 300 caratteri per la descrizione di un endpoint e 700 per quella di un
        // parametro. Usare 300 anche qui buttava via quattrocento caratteri per
        // parametro - proprio dove va scritto per quali action serve.
        $testo = $def['description'];
        if (strlen($testo) > WGA_Azioni::TETTO_PARAMETRO) {
            $testo = WGA_Azioni::descrizione_corta($testo, WGA_Azioni::TETTO_PARAMETRO);
            $troppo_lunghi++;
        }
        $righe[] = '                ' . $par . ':';
        $tipo = $def['type'] ?: 'string';
        $righe[] = '                  type: ' . $tipo;

        // Un array senza items non e' uno schema valido, e ChatGPT lo rifiuta
        // dicendo "array schema missing items". Non si vede finche' non si
        // prova davvero: il file resta YAML corretto, e' lo schema a essere
        // incompleto. Senza indicazioni si assume una lista di stringhe, che e'
        // quello che sono quasi tutte qui.
        if ($tipo === 'array') {
            $righe[] = '                  items:';
            $righe[] = '                    type: ' . ($def['items'] ?? 'string');
        }
        // enum PRIMA della descrizione: e' il vincolo, e chi legge poco legge
        // le prime righe. Qui i valori non si troncano mai.
        if (!empty($def['enum'])) {
            $righe[] = '                  enum: [' .
                implode(', ', array_map('y', $def['enum'])) . ']';
        }
        $righe[] = '                  description: ' . y($testo);
    }
}

$fuori = $radice . '/data/openapi-azioni.yml';
file_put_contents($fuori, implode("\n", $righe) . "\n");

// ----------------------------------------------------------------- il file vero
//
// Adesso il registro copre tutte le rotte tranne le due di amministrazione,
// quindi si puo' scrivere openapi.yml per intero invece del solo frammento.
// Quello che c'era prima erano TRE documenti OpenAPI incollati uno dopo l'altro
// nello stesso file - che non e' YAML valido - e descriveva ancora rotte
// cambiate da un pezzo, come link-scraping o gli id dentro lo slug.
//
// IL SERVER NON E' UN SITO VERO. Il file viaggia con il plugin e finisce su
// tutti i siti: scriverci dentro il dominio di un cliente vuol dire che il GPT
// di chiunque altro punta li'. Qui si usa una variabile di OpenAPI, e la guida
// in backend la sostituisce con il dominio del sito su cui gira.

$intestazione = [
    '# GENERATO da tools/genera-openapi-dalle-azioni.php - non modificare a mano.',
    '# Si rigenera con: php tools/genera-openapi-dalle-azioni.php',
    'openapi: 3.1.0',
    'info:',
    '  version: 2.5.2',
    '  title: WordPress Automation Architect',
    '  description: ' . y('Un endpoint per ogni strumento, e dentro ogni strumento tante '
        . 'action. Il parametro action decide l operazione e quali altri parametri '
        . 'servono. Per sapere cosa esiste si chiama tools-manifest.'),
    '  contact:',
    '    name: Supporto',
    '    url: https://workingwithweb.it/webagency',
    'servers:',
    '  - url: "{sito}/wp-json/wp-gpt"',
    '    variables:',
    '      sito:',
    '        default: https://esempio.it',
    '        description: ' . y('L indirizzo del sito WordPress su cui gira il plugin. '
        . 'La guida in backend lo sostituisce con quello vero.'),
    'components:',
    '  securitySchemes:',
    '    bearerAuth:',
    '      type: http',
    '      scheme: bearer',
    'security:',
    '  - bearerAuth: []',
];

$completo = $radice . '/openapi.yml';
if (file_exists($completo) && !file_exists($completo . '.prima-della-rigenerazione')) {
    // Una copia sola, la prima volta: serve a poter confrontare, non a fare
    // una collezione di copie a ogni rigenerazione.
    copy($completo, $completo . '.prima-della-rigenerazione');
}
file_put_contents($completo, implode("\n", array_merge($intestazione, $righe)) . "\n");

echo "scritto: data/openapi-azioni.yml (solo i paths, per la guida)\n";
echo "scritto: openapi.yml (documento intero)\n";
echo "tool: $conta_tool | parametri documentati: $conta_par | accorciati a 300: $troppo_lunghi\n";
echo "byte: " . filesize($completo) . "\n";
