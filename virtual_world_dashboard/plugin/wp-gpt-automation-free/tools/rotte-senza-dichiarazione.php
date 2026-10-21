<?php
/**
 * Quali rotte registrate non hanno ancora una scheda nel registro.
 *
 * Serve a rispondere a una domanda sola, prima di rigenerare openapi.yml: se
 * rigenero adesso, quale documentazione perdo? Il generatore sa scrivere solo
 * quello che il registro dichiara; una rotta senza scheda sparirebbe dal file
 * senza che nessuno se ne accorga.
 *
 *   php tools/rotte-senza-dichiarazione.php
 */
define('ABSPATH', true);
$GLOBALS['filtri'] = [];
function add_filter($n, $fn, $p = 10, $a = 1) { $GLOBALS['filtri'][$n][] = $fn; }
function apply_filters($n, $v) { foreach ($GLOBALS['filtri'][$n] ?? [] as $fn) $v = $fn($v); return $v; }
function __($t, $d = null) { return $t; }

$radice = dirname(__DIR__);
foreach (glob($radice . '/includes/azioni-*.php') as $f) require $f;

$registro = [];
foreach ($GLOBALS['filtri']['wp_gpt_azioni_dei_tool'] ?? [] as $fn) $registro = $fn($registro);

// Le rotte davvero registrate, lette dal codice.
$rotte = [];
// Ricorsivo, non glob semplice: le rotte della memoria stanno in
// includes/memory/ e un glob su includes/*.php non le vede. Il controllo
// diceva che era tutto coperto solo perche' quel file non lo apriva mai.
$php = [];
$giro = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($radice . '/includes'));
foreach ($giro as $f) {
    if ($f->isFile() && strtolower($f->getExtension()) === 'php') $php[] = $f->getPathname();
}
sort($php);

foreach ($php as $file) {
    $t = file_get_contents($file);

    // Le costanti che tengono il percorso, tipo const ROUTE = '/ha';
    $costanti = [];
    preg_match_all("#const\s+(\w+)\s*=\s*'([^']+)'#", $t, $mc, PREG_SET_ORDER);
    foreach ($mc as $c) $costanti[$c[1]] = $c[2];

    // Il percorso puo' essere un letterale, una costante, oppure i due
    // concatenati: '/' . self::ROUTE_PATH. Senza l'ultimo caso il
    // programmatore di chiamate risultava una scheda senza rotta, che e' una
    // pista falsa buona per far perdere mezz'ora a chi legge.
    preg_match_all("#register_rest_route\(\s*[^,]+,\s*(?:'([^']*)'\s*\.\s*self::(\w+)|'([^']+)'|self::(\w+))#",
                   $t, $m, PREG_SET_ORDER);
    foreach ($m as $r) {
        if (!empty($r[2])) {
            $p = ($r[1] ?? '') . ($costanti[$r[2]] ?? '');   // '/' . self::ROTTA
        } elseif (!empty($r[3])) {
            $p = $r[3];                                       // letterale
        } elseif (!empty($r[4])) {
            $p = $costanti[$r[4]] ?? '';                      // self::ROTTA
        } else {
            continue;
        }
        if ($p === '') continue;
        // Dal percorso al nome del tool, con la STESSA regola del dispatcher:
        // WGA_Azioni::nome_pulito() tiene solo l'ultimo pezzo dopo la barra.
        // Un controllo che usasse una regola sua direbbe che una scheda c'e'
        // mentre a chiamare la rotta non la trova nessuno.
        $nome = trim(preg_replace('#\(\?P<\w+>[^)]*\)#', '', $p), '/');
        $nome = trim(preg_replace('#/+$#', '', $nome), '/');
        if (false !== strpos($nome, '/')) $nome = substr($nome, strrpos($nome, '/') + 1);
        if ($nome === '') continue;
        $rotte[$nome] = basename($file);
    }
}

$mancanti = [];
foreach ($rotte as $nome => $file) {
    if (!isset($registro[$nome])) $mancanti[$nome] = $file;
}

echo count($rotte) . " rotte registrate, " . count($registro) . " tool nel registro\n";
echo count($mancanti) . " rotte SENZA scheda:\n";
foreach ($mancanti as $nome => $file) echo "  $nome   ($file)\n";

$orfane = array_diff(array_keys($registro), array_keys($rotte));
if ($orfane) {
    echo "\nSchede che non corrispondono a nessuna rotta trovata (da controllare a mano):\n";
    foreach ($orfane as $o) echo "  $o\n";
}
