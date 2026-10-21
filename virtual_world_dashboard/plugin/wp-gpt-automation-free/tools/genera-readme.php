<?php
/**
 * Scrive il README delle rotte partendo dal registro.
 *
 * PERCHE' GENERATO. Il README che c'era e' ereditato dalla Pro e descrive rotte
 * che questa edizione non ha: chi lo legge cerca manage-articles e non lo trova,
 * e non capisce se sbaglia lui o se e' rotto il plugin. Un manuale scritto a
 * mano descrive il software del giorno in cui e' stato scritto; questo descrive
 * quello di adesso, e si rifa' con un comando.
 *
 * COSA CI VA. Le rotte con le loro action e i parametri: quello che serve a
 * chi deve chiamarle. Non la storia del plugin, non l'installazione - quella
 * cambia di rado e sta bene scritta a mano altrove.
 *
 *   php tools/genera-readme.php
 */

define('ABSPATH', true);
$GLOBALS['filtri'] = [];
function add_filter($n, $fn, $p = 10, $a = 1) { $GLOBALS['filtri'][$n][] = $fn; }
function apply_filters($n, $v) { foreach ($GLOBALS['filtri'][$n] ?? [] as $fn) $v = $fn($v); return $v; }
function __($t, $d = null) { return $t; }

$radice = dirname(__DIR__);
require $radice . '/includes/class-wp-gpt-ambito-azioni.php';
foreach (glob($radice . '/includes/azioni-*.php') as $f) require $f;

$registro = WGA_Azioni::registro();

// Le rotte davvero registrate: una scheda senza rotta non va nel manuale.
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
        $p = '/' . ltrim($p, '/');
        $chiave = WGA_Azioni::nome_pulito($p);
        if (!isset($percorsi[$chiave]) || strlen($p) > strlen($percorsi[$chiave])) {
            $percorsi[$chiave] = $p;
        }
    }
}

$righe = [];
$righe[] = '# Le rotte di WP GPT Automation Free';
$righe[] = '';
$righe[] = '> Generato da `tools/genera-readme.php`. Non si modifica a mano: si rigenera.';
$righe[] = '';
$righe[] = 'Ogni rotta e\' un tool, e ogni tool contiene piu\' operazioni. L\'operazione si';
$righe[] = 'sceglie con il parametro `action`. Non esiste una rotta per operazione.';
$righe[] = '';
$righe[] = 'Su ogni rotta si puo\' chiedere la documentazione senza uscire dalla rotta:';
$righe[] = '';
$righe[] = '- `action: documentazione` — tutta la rotta, a pagine';
$righe[] = '- `action: documentazione_<nome-operazione>` — una operazione sola';
$righe[] = '';
$righe[] = 'Ogni chiamata vuole il **bearer token** nell\'intestazione e **user_code** nel corpo.';
$righe[] = '';

$conta_tool = 0; $conta_azioni = 0;
$corpo = [];

// L'indice prima, cosi' chi cerca una cosa sola non scorre tutto.
$indice = [];

foreach ($registro as $nome => $t) {
    if (empty($t['azioni'])) continue;
    $chiave = WGA_Azioni::nome_pulito($nome);
    if (!isset($percorsi[$chiave])) continue;
    $conta_tool++;

    $percorso = '/wp-json/wp-gpt/v1' . $percorsi[$chiave];
    $indice[] = '- [' . $chiave . '](#' . strtolower(preg_replace('/[^a-z0-9]+/i', '-', $chiave)) . ') — '
              . count($t['azioni']) . ' operazioni';

    $corpo[] = '## ' . $chiave;
    $corpo[] = '';
    $corpo[] = '`POST ' . $percorso . '`';
    $corpo[] = '';
    $corpo[] = trim((string) ($t['descrizione'] ?? ''));
    $corpo[] = '';

    foreach ($t['azioni'] as $azione => $def) {
        $conta_azioni++;
        $corpo[] = '### `' . $azione . '`';
        $corpo[] = '';
        $corpo[] = trim((string) ($def['descrizione'] ?? ''));
        $corpo[] = '';

        $par = $def['parametri'] ?? [];
        if (!$par) {
            $corpo[] = 'Nessun parametro oltre ad `action`.';
            $corpo[] = '';
            continue;
        }

        $corpo[] = '| parametro | tipo | obbligatorio | a cosa serve |';
        $corpo[] = '|---|---|---|---|';
        foreach ($par as $p => $d) {
            $corpo[] = '| `' . $p . '` | ' . ($d['tipo'] ?? 'string') . ' | '
                     . (!empty($d['obbligatorio']) ? 'si' : 'no') . ' | '
                     . str_replace('|', '\\|', trim((string) ($d['descrizione'] ?? ''))) . ' |';
        }
        $corpo[] = '';
    }
}

$righe[] = '## Indice';
$righe[] = '';
$righe = array_merge($righe, $indice);
$righe[] = '';
$righe = array_merge($righe, $corpo);

$fuori = $radice . '/docs/README_wp_gpt_automation_free.md';
file_put_contents($fuori, implode("\n", $righe) . "\n");

echo "scritto: docs/README_wp_gpt_automation_free.md\n";
echo "$conta_tool tool, $conta_azioni operazioni, " . filesize($fuori) . " byte\n";
