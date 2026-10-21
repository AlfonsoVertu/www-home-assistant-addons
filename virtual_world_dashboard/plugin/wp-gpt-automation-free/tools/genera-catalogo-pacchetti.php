<?php
/**
 * Rimette assets/gpt-route-categories.json in accordo con le rotte vere.
 *
 * COSA E' QUEL FILE. L'elenco dei pacchetti - Premium Full, Blog, Marketer... -
 * e per ognuno quali rotte contiene, per operationId. Lo legge la pagina della
 * guida per decidere quali caselle mostrare allo step 6.
 *
 * PERCHE' VA RIGENERATO. E' scritto a mano e non lo aggiorna nessuno quando le
 * rotte cambiano. Alla prova dei fatti conteneva 90 operationId che non
 * esistono piu' - segnaposto diventati action di manage-placeholders, rotte di
 * servizio, doppioni assorbiti da manage-articles - e non conteneva 25 rotte
 * vere. L'utente spuntava caselle di cose che non ci sono e non trovava quelle
 * che ci sono.
 *
 * COSA FA. Premium Full torna a voler dire quello che dice: TUTTE le rotte
 * disponibili, prese dal registro. Gli altri pacchetti restano come sono stati
 * segnati a mano, ma gli si tolgono le voci morte: tenerle non e' rispettare
 * una scelta, e' lasciare in giro caselle che non possono funzionare.
 *
 *   php tools/genera-catalogo-pacchetti.php
 */

define('ABSPATH', true);
$GLOBALS['filtri'] = [];
function add_filter($n, $fn, $p = 10, $a = 1) { $GLOBALS['filtri'][$n][] = $fn; }
function apply_filters($n, $v) { foreach ($GLOBALS['filtri'][$n] ?? [] as $fn) $v = $fn($v); return $v; }
function __($t, $d = null) { return $t; }

$radice = dirname(__DIR__);
require $radice . '/includes/class-wp-gpt-ambito-azioni.php';
foreach (glob($radice . '/includes/azioni-*.php') as $f) require $f;

/** Lo stesso nome che scrive il generatore dello schema: devono coincidere. */
function operation_id_catalogo(string $nome): string {
    $pulito = WGA_Azioni::nome_pulito($nome);
    $pezzi = preg_split('/[^a-zA-Z0-9]+/', $pulito, -1, PREG_SPLIT_NO_EMPTY);
    if (!$pezzi) return $pulito;
    $primo = array_shift($pezzi);
    return lcfirst($primo) . implode('', array_map('ucfirst', $pezzi));
}

// Le rotte vere: quelle registrate davvero, non le chiavi del registro, che
// comprendono anche gli alias.
$percorsi = [];
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
        $percorsi[WGA_Azioni::nome_pulito($p)] = true;
    }
}

/*
 * L'ordine con cui i tool compaiono nelle card, e chi ci sta in testa.
 *
 * tools-manifest e' il tool della documentazione: e' quello che risponde a
 * "cosa posso fare qui" e da cui si impara a usare tutti gli altri. Deve
 * esserci sempre e deve essere il primo, in ogni pacchetto - un utente che si
 * monta lo schema senza di lui si ritrova un GPT che ha gli strumenti ma non
 * sa come si chiamano le operazioni dentro.
 *
 * Dietro vengono i tool che riguardano il funzionamento dello strumento in se'.
 */
$PRIMI = [
    'toolsManifest',
    'goal',
    'manageAdvancedMemoryPost',
    'gatherInformationYoutubeGoogleSerpKeywrdAnalisysImageLink',
    'webpageLinkAndTwitterScraping',
    'readPostOfAllTypePaginated',
    'manageArticlesWithUserCode',
    'manageFile',
];

/** Mette in testa i tool di base, nell'ordine, e lascia gli altri come stanno. */
function ordina(array $ids, array $primi): array {
    $testa = [];
    foreach ($primi as $p) {
        if (in_array($p, $ids, true)) $testa[] = $p;
    }
    $coda = array_values(array_diff($ids, $testa));
    sort($coda);
    return array_merge($testa, $coda);
}

$registro = WGA_Azioni::registro();
$veri = [];
foreach ($registro as $nome => $t) {
    if (empty($t['azioni'])) continue;
    $chiave = WGA_Azioni::nome_pulito($nome);
    if (!isset($percorsi[$chiave])) continue;          // alias, non una rotta
    $veri[operation_id_catalogo($chiave)] = true;
}
// NB: tools-manifest e' fra le rotte "di servizio" per la documentazione
// interna, ma nei pacchetti ci deve stare: e' l'unico modo che un GPT ha di
// scoprire quali action esistono. Escluderlo qui sarebbe coerente con una
// etichetta e sbagliato per l'utente.
$veri = ordina(array_keys($veri), $PRIMI);

$file = $radice . '/assets/gpt-route-categories.json';
$catalogo = json_decode(file_get_contents($file), true);
if (!is_array($catalogo)) {
    fwrite(STDERR, "catalogo illeggibile\n");
    exit(1);
}

echo "rotte vere: " . count($veri) . "\n";

foreach ($catalogo as &$pacchetto) {
    $prima = $pacchetto['operationIds'] ?? [];

    if (($pacchetto['name'] ?? '') === 'Premium Full') {
        // Premium Full vuol dire tutto: si riscrive, non si ripulisce.
        $pacchetto['operationIds'] = $veri;
        echo sprintf("  %-14s riscritto: %d -> %d\n", $pacchetto['name'], count($prima), count($veri));
        continue;
    }

    $tenuti = array_values(array_intersect($prima, $veri));
    $tolti = count($prima) - count($tenuti);

    // La documentazione va aggiunta anche dove non era stata segnata: senza,
    // quel pacchetto produce un GPT che non sa chiedere cosa sa fare.
    $aggiunti = 0;
    if (!in_array('toolsManifest', $tenuti, true)) {
        $tenuti[] = 'toolsManifest';
        $aggiunti++;
    }

    $tenuti = ordina($tenuti, $PRIMI);
    $pacchetto['operationIds'] = $tenuti;

    // Tolte e aggiunte separate: sommarle in un numero solo faceva comparire
    // "tolte -1 voci morte", che non vuol dire niente.
    echo sprintf("  %-14s %d -> %d (tolte %d morte, aggiunta la documentazione: %s)\n",
                 $pacchetto['name'] ?? '?', count($prima), count($tenuti),
                 $tolti, $aggiunti ? 'si' : 'c era gia');
}
unset($pacchetto);

file_put_contents($file, json_encode($catalogo, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
echo "scritto: assets/gpt-route-categories.json\n";
