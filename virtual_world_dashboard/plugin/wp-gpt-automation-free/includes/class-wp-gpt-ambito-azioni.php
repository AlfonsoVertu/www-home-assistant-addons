<?php
/**
 * Il registro delle action, e la documentazione che arriva quando serve.
 *
 * PERCHE' NON BASTA L'INTROSPEZIONE. Il manifesto legge le rotte da WordPress e
 * i parametri da come la rotta si e' registrata. Funziona per una rotta normale,
 * ma qui le rotte sono mega-tool: un solo endpoint, un parametro `action`, e un
 * corpo che cambia forma a ogni azione. Gli `args` di WordPress non sanno dire
 * "se action=control-entity allora servono domain, service, entity_id" - e
 * infatti la rotta della casa si registra con `args` vuoti. Chi ci arriva sa che
 * l'endpoint esiste e deve indovinare tutto il resto.
 *
 * OGNI ROTTA E' UN TOOL, OGNI TOOL HA LE SUE ACTION. Il registro tiene questo, e
 * lo tiene per nome di rotta: chi aggiunge una rotta aggiunge la sua voce, e chi
 * non la aggiunge si vede il tool comparire senza action - che e' il modo per
 * accorgersene, invece di scoprirlo da un assistente che indovina.
 *
 * LO RIEMPIONO I PLUGIN, non questo file. Le voci arrivano dal filtro
 * `wp_gpt_azioni_dei_tool`, cosi' un plugin qualsiasi - anche di terzi - dichiara
 * le proprie senza che nessuno debba modificare il manifesto. Un elenco scritto
 * qui dentro sarebbe la stessa copia ferma che questo meccanismo esiste per
 * evitare.
 *
 * LA DOCUMENTAZIONE ARRIVA DOVE SERVE: quando sbagli. Chiamare una rotta con le
 * credenziali giuste e i parametri sbagliati e' il momento esatto in cui uno ha
 * bisogno di sapere come si chiama - e finora si prendeva un errore secco. Qui
 * si risponde a tre livelli, dal generico al preciso, secondo quanto in la' si
 * e' arrivati.
 */
if (!defined('ABSPATH')) exit;

if (!class_exists('WGA_Azioni')) :

class WGA_Azioni {

  /** Le rotte di servizio non sono tool: non hanno action e non si documentano. */
  const SERVIZIO = ['verify-license', 'download-update', 'log-attempt', 'tools-manifest'];

  /**
   * Il messaggio che si riceve sbagliando. Uguale per tutti, apposta.
   *
   * Deve insegnare il MECCANISMO, non solo segnalare l'errore: chi lo legge la
   * prima volta impara come si esplora tutto il resto, e non torna a chiedere.
   */
  const BUSSOLA =
    "Attenzione: qui non c'e' un tool per ogni operazione. " .
    "Ci sono pochi tool, e ogni tool contiene molte operazioni. " .
    "L'operazione si sceglie con il parametro action dentro la chiamata. " .
    "Non esiste un tool che si chiama search. " .
    "Si chiama il tool che contiene search, e si passa action uguale a search. " .
    "Per sapere quali tool esistono chiama tools-manifest con action uguale a " .
    "lista-dei-tool-che-contengono-le-action. " .
    "Per sapere cosa vuole una operazione chiama la sua stessa rotta con action uguale a " .
    "documentazione_ seguito dal nome della operazione.";

  /**
   * La trappola, detta corta e senza simboli.
   *
   * I MODELLI SONO ADDESTRATI SU UN'ALTRA FORMA. Ovunque un tool e' UNA
   * operazione: search(query), send_mail(to). Qui un tool ne contiene quindici e
   * l'operazione e' un parametro. Chi arriva col riflesso normale cerca un tool
   * "search", non lo trova, e conclude che il sito non sappia cercare: il caso
   * peggiore, perche' rinuncia senza sbagliare nulla e quindi senza ricevere
   * nessun errore che lo corregga.
   *
   * POCHI SIMBOLI, PAROLE PIENE. Le parentesi angolari, gli asterischi e i
   * segnaposto costano attenzione e vengono copiati alla lettera dai modelli
   * piccoli - si e' visto chiamare action uguale a "<nome-action>". Si scrive
   * "action uguale a search" e non si sbaglia nessuno.
   */
  const FORMA = 'Questo tool contiene piu\' operazioni. Si chiama sempre passando il '
              . 'parametro action con il nome della operazione voluta.';

  // ------------------------------------------------------------- il registro

  /** Tutti i tool con le loro action, per nome di rotta. */
  public static function registro(): array {
    static $r = null;
    if (null !== $r) return $r;
    /**
     * Ogni voce: 'nome-rotta' => ['descrizione' => ..., 'azioni' => [
     *   'nome-action' => [
     *     'descrizione' => 'cosa fa',
     *     'parametri' => [
     *       'nome' => ['obbligatorio' => true, 'tipo' => 'string',
     *                  'valori' => ['a','b'],        // se sono chiusi
     *                  'descrizione' => 'a cosa serve'],
     *     ],
     *   ],
     * ]]
     */
    $r = apply_filters('wp_gpt_azioni_dei_tool', []);
    return is_array($r) ? $r : [];
  }

  public static function tool(string $nome): array {
    $reg = self::registro();
    $nome = self::nome_pulito($nome);
    return isset($reg[$nome]) && is_array($reg[$nome]) ? $reg[$nome] : [];
  }

  public static function azione(string $tool, string $azione): array {
    $t = self::tool($tool);
    $a = $t['azioni'][$azione] ?? null;
    return is_array($a) ? $a : [];
  }

  /** Dal percorso completo al nome del tool: sono la stessa cosa. */
  public static function nome_pulito(string $nome): string {
    $n = trim($nome);
    if (false !== strpos($n, '/')) $n = substr($n, strrpos($n, '/') + 1);
    return $n;
  }

  public static function di_servizio(string $nome): bool {
    return in_array(self::nome_pulito($nome), self::SERVIZIO, true);
  }

  // ------------------------------------------------------------- i permessi

  /**
   * Chi sta chiedendo puo' usare questo tool?
   *
   * SI CHIEDE ALLA STESSA GUARDIA che decide quando arriva la chiamata vera,
   * non a una copia dei permessi: cosi' la documentazione non puo' andare
   * d'accordo con se stessa e in disaccordo con la realta'.
   *
   * SE NON SI PUO' SAPERE, si mostra. Questo filtro riguarda cosa si RACCONTA,
   * non cosa si lascia fare: l'accesso lo nega comunque la rotta al momento
   * della chiamata. Sbagliando da questa parte si rivela un nome; sbagliando
   * dall'altra si nasconde a un utente legittimo meta' del suo strumento senza
   * dirgli perche'.
   */
  public static function consentito(string $tool): bool {
    if (!class_exists('WGA_Manifesto') || !method_exists('WGA_Manifesto', 'consentiti')) return true;
    $ok = WGA_Manifesto::consentiti();
    if (!is_array($ok) || !$ok) return true;
    return in_array(self::nome_pulito($tool), $ok, true);
  }

  /** Esiste nel registro ma a chi chiede non e' concesso. */
  public static function vietato(string $tool): bool {
    return self::tool($tool) && !self::consentito($tool);
  }

  /** Il prefisso con cui si chiede la documentazione di un'action, sulla rotta stessa. */
  const PREFISSO_DOC = 'documentazione_';

  // ------------------------------------------------------------- le pagine

  /**
   * Il blocco che accompagna ogni risposta a pagine: i numeri e cosa farne.
   *
   * I NUMERI DA SOLI NON BASTANO. "pagina: 1, pagine: 4" dice a chi legge che
   * gli manca qualcosa, non come prenderselo: deve dedurre che esiste un
   * parametro, indovinarne il nome e sperare. Un elenco troncato che dichiara
   * di essere troncato e non si lascia scorrere e' peggio di uno lungo.
   *
   * LA FRASE E' CORTA E NELLA RISPOSTA, non nella documentazione: chi sta
   * leggendo la pagina 1 e' esattamente chi ha bisogno di sapere come si arriva
   * alla 2, e in quel momento non ha voglia di aprire un manuale. Documentare
   * e' un'altra cosa e si fa altrove; questo e' il promemoria sul posto.
   *
   * @param string $come Il nome del parametro nella rotta che sta rispondendo:
   *                     "pagina" qui, "page" nelle rotte in inglese. Dire il
   *                     nome sbagliato sarebbe peggio che tacere.
   */
  public static function pagine(int $pagina, int $pagine, int $per_pagina,
                               int $totale, string $come = 'pagina'): array {
    $altre = $pagina < $pagine;
    return [
      $come => $pagina,
      'pagine_totali' => $pagine,
      'per_pagina' => $per_pagina,
      'totale' => $totale,
      'altre_pagine' => $altre,
      'come_avere_il_resto' => $altre
        ? sprintf('Sei alla pagina %d di %d. Per la prossima ripeti la STESSA chiamata '
                . 'cambiando solo %s=%d. Per vederne di piu\' per volta alza per_pagina '
                . '(ora %d).', $pagina, $pagine, $come, $pagina + 1, $per_pagina)
        : sprintf('Questa e\' l\'ultima pagina (%d di %d): hai visto tutti i %d risultati.',
                  $pagina, $pagine, $totale),
    ];
  }

  /**
   * La bussola, detta per QUESTO tool.
   *
   * SULLA ROTTA STESSA, non su tools-manifest: chi ha in mano questo tool ce
   * l'ha e basta - magari gliel'hanno dato dentro uno schema, senza il resto
   * del sito. Mandarlo a chiamare un altro tool per sapere come si usa questo
   * e' un passo che spesso non fa, e allora indovina.
   */
  public static function bussola_del_tool(string $tool): string {
    $prima = array_key_first(self::tool($tool)['azioni'] ?? []) ?: 'nome_operazione';
    // l'esempio usa un'operazione VERA di questo tool, non un segnaposto: un
    // modello piccolo copia alla lettera quello che legge, e da un segnaposto
    // copia il segnaposto
    return self::FORMA . ' Per sapere cosa vuole una operazione chiama questa stessa '
         . 'rotta con action uguale a ' . self::PREFISSO_DOC . $prima
         . ', mettendo al posto di ' . $prima . ' la operazione che ti interessa.';
  }

  /**
   * Un esempio di chiamata giusta, costruito dalla dichiarazione.
   *
   * SI DA' SEMPRE UN ESEMPIO, e quando non se ne puo' dare uno del lavoro vero -
   * perche' non sappiamo nemmeno quale operazione voleva - si da' quello per
   * informarsi. Un errore che spiega senza mostrare lascia a chi legge l'ultimo
   * salto da fare, e i modelli piccoli quel salto lo sbagliano: ritentano
   * variando un parametro a caso invece di cambiare forma.
   */
  public static function esempio_di(string $tool, string $azione = '', array $gia = []): array {
    $t = self::tool($tool);
    $azioni = $t['azioni'] ?? [];
    if ('' === $azione || !isset($azioni[$azione])) {
        // non sappiamo cosa volesse: l'esempio e' come informarsi
        $prima = array_key_first($azioni) ?: 'nome_operazione';
        return ['action' => self::PREFISSO_DOC . $prima];
    }
    $esempio = ['action' => $azione];
    foreach (($azioni[$azione]['parametri'] ?? []) as $nome => $p) {
        if (empty($p['obbligatorio'])) continue;
        // SI RIUSA QUELLO CHE HA GIA' INDOVINATO. Rimettergli un segnaposto al
        // posto di un valore che aveva scritto giusto e' un invito a cambiare
        // la cosa che funzionava - ed e' proprio cio' che fa un modello piccolo
        // quando ritenta: tocca l'ultima cosa che ha visto cambiare.
        if (isset($gia[$nome]) && is_scalar($gia[$nome]) && '' !== (string) $gia[$nome]) {
            $esempio[$nome] = $gia[$nome]; continue;
        }
        if (!empty($p['valori'])) { $esempio[$nome] = $p['valori'][0]; continue; }
        $esempio[$nome] = isset($p['predefinito']) ? $p['predefinito']
            : ('integer' === ($p['tipo'] ?? '') ? 1 : 'valore_da_mettere_qui');
    }
    return $esempio;
  }

  /**
   * Cosa stai sbagliando rispetto a come sei stato addestrato, in parole piane.
   *
   * A GRADINI, E IL PRIMO E' IL DISINGANNO. Un modello arriva qui col riflesso
   * giusto per il resto del mondo - un tool, una operazione - e il primo passo
   * non e' insegnargli il nostro schema: e' dirgli che il suo riflesso, QUI,
   * non funziona. Finche' crede di dover trovare un tool "search" continua a
   * cercarlo, e nessuna spiegazione successiva lo raggiunge.
   *
   * POI UN GRADINO PER VOLTA, e ognuno e' una chiamata sola da fare. Chi e'
   * piccolo esegue il primo e torna; chi e' grande li legge tutti e salta alla
   * fine. Nessuno dei due deve tenere a mente niente fra un passo e l'altro.
   */
  public static function gradini(string $tool): array {
    $azioni = array_keys(self::tool($tool)['azioni'] ?? []);
    $prima = $azioni[0] ?? 'nome_operazione';
    return [
      'di solito' => 'Nella maggior parte dei sistemi ogni operazione e\' un tool a se\': '
                   . 'un tool per cercare, un tool per accendere. Qui no.',
      'qui invece' => 'Qui il tool e\' un contenitore. Un tool solo contiene molte '
                    . 'operazioni, e la operazione si sceglie con il parametro action. '
                    . 'Non cercare un tool con il nome della operazione: non esiste.',
      'passo 1' => 'Chiama questo tool con action uguale a ' . self::PREFISSO_DOC . $prima
                 . ' per farti dire cosa fa quella operazione e che parametri vuole. '
                 . 'Cambia ' . $prima . ' con la operazione che ti serve.',
      'passo 2' => 'Rifai la chiamata con action uguale al nome della operazione e i '
                 . 'parametri che ti sono stati detti.',
      'se non sai da dove partire' => 'Chiama il tool tools-manifest con action uguale a '
                 . 'lista-dei-tool-che-contengono-le-action: ti elenca i tool e le loro '
                 . 'operazioni, con cerca e pagina per restringere.',
    ];
  }

  /**
   * La richiesta e' "documentami questa action"?
   *
   * Torna il nome dell'action chiesta, o stringa vuota se non e' una richiesta
   * di documentazione.
   */
  public static function doc_chiesta(string $azione): string {
    $a = strtolower(trim($azione));
    if (0 !== strpos($a, self::PREFISSO_DOC)) return '';
    return substr($a, strlen(self::PREFISSO_DOC));
  }

  /** La parola nuda: la documentazione di TUTTA la rotta, non di una action. */
  const DOC_INTERA = 'documentazione';

  public static function doc_intera_chiesta(string $azione): bool {
    return self::DOC_INTERA === strtolower(trim($azione));
  }

  /**
   * Tutta la documentazione di una rotta, a pagine.
   *
   * DUE LIVELLI, NON UNO. `documentazione` da sola risponde con l'intera rotta:
   * cosa fa, quali operazioni contiene e cosa vuole ognuna. `documentazione_`
   * seguito dal nome di una operazione apre quella sola. Il primo serve a chi
   * non sa ancora cosa c'e' dentro, il secondo a chi lo sa e deve solo
   * ricordarsi i parametri: sono due domande diverse e meritano due risposte
   * diverse.
   *
   * A PAGINE PERCHE' ALCUNE ROTTE NE HANNO TRENTATRE. Rovesciare addosso a un
   * modello trentatre schede complete significa che ne legge tre e tira a
   * indovinare sul resto. Meglio poche per volta, con scritto come avere le
   * altre.
   *
   * E OGNI PAGINA RIPETE COME SCENDERE. Chi arriva alla pagina quattro non ha
   * letto la prima: se l'invito a chiedere il dettaglio sta solo li', per lui
   * non esiste.
   */
  public static function documentazione_rotta(string $tool, int $pagina = 1, int $per_pagina = 8): array {
    if (!self::consentito($tool)) {
      return ['errore' => 'Questo tool esiste ma non e\' fra quelli concessi alle tue credenziali.',
              'tool' => self::nome_pulito($tool)];
    }

    $t = self::tool($tool);
    if (empty($t['azioni'])) {
      return ['errore' => 'Questo tool non ha action dichiarate.',
              'tool' => self::nome_pulito($tool),
              'come_si_esplora' => self::BUSSOLA];
    }

    $nome = self::nome_pulito($tool);
    $tutte = $t['azioni'];
    $totale = count($tutte);

    $per_pagina = max(1, min(50, $per_pagina));
    $pagine = (int) ceil($totale / $per_pagina);
    $pagina = max(1, min($pagina, $pagine));

    $fetta = array_slice($tutte, ($pagina - 1) * $per_pagina, $per_pagina, true);

    $schede = [];
    foreach ($fetta as $azione => $def) {
      $schede[$azione] = [
        'cosa_fa' => $def['descrizione'] ?? '',
        'parametri' => self::parametri_leggibili($def['parametri'] ?? []),
        'per_approfondire' => 'Richiama questa stessa rotta con action uguale a '
                            . self::PREFISSO_DOC . $azione,
      ];
    }

    return array_merge([
      'tool' => $nome,
      'cosa_fa' => $t['descrizione'] ?? '',
      'come_funziona' => self::FORMA,
      'action_di_questa_pagina' => $schede,
      'per_approfondire_una_operazione' =>
        'Richiama questa stessa rotta con action uguale a ' . self::PREFISSO_DOC
        . ' seguito dal nome dell\'operazione che ti interessa, fra quelle elencate qui.',
    ], self::pagine($pagina, $pagine, $per_pagina, $totale));
  }

  /**
   * La scheda di una action, chiesta sulla rotta stessa.
   *
   * Se l'action non esiste non si risponde "no": si risponde con l'elenco di
   * quelle che esistono. Chi ha sbagliato il nome e' a un passo dal trovarlo,
   * e negargli quel passo lo costringe a ricominciare da capo.
   */
  public static function documentazione_di(string $tool, string $azione): array {
    if (!self::consentito($tool)) {
        return ['errore' => 'Questo tool esiste ma non e\' fra quelli concessi alle tue credenziali.',
                'tool' => self::nome_pulito($tool)];
    }
    $t = self::tool($tool);
    $def = self::azione($tool, $azione);
    if (!$def) {
        return [
            'errore' => sprintf('L\'action "%s" non esiste in questo tool.', $azione),
            'tool' => self::nome_pulito($tool),
            'cosa_fa' => self::descrizione_corta($t['descrizione'] ?? ''),
            'action_disponibili' => array_keys($t['azioni'] ?? []),
            'come_si_esplora' => self::bussola_del_tool(self::nome_pulito($tool)),
        ];
    }
    return [
        'tool' => self::nome_pulito($tool),
        'action' => $azione,
        'cosa_fa' => $def['descrizione'] ?? '',
        'parametri' => self::parametri_leggibili($def['parametri'] ?? []),
    ];
  }

  // ------------------------------------------------- la documentazione a tre livelli

  /**
   * Cosa rispondere a una chiamata autenticata ma sbagliata.
   *
   * TRE LIVELLI, dal generico al preciso, perche' rispondere sempre con tutto
   * sarebbe come non rispondere: chi ha sbagliato un valore non deve rileggersi
   * quindici action per trovare la riga che gli serve.
   *
   *   1. action assente o inesistente  -> la documentazione del tool
   *   2. action giusta, nomi sbagliati -> i parametri di QUELLA action
   *   3. nomi giusti, valore sbagliato -> come dev'essere QUEL parametro
   *
   * Torna null se non c'e' niente da dire: chi chiama deve poter distinguere
   * "va bene" da "non lo so documentare", e non scambiare il silenzio per un ok.
   */
  public static function spiega(string $tool, array $parametri) {
    $t = self::tool($tool);
    if (!$t || empty($t['azioni'])) return null;

    // QUI INVECE SI DICE, e la differenza e' voluta. Nell'elenco tacere e'
    // giusto: nessuno deve costruirsi un assistente attorno a porte chiuse. Ma
    // se uno il nome ce l'ha gia' e lo sta chiedendo, "non esiste" lo manda a
    // cercare un errore di battitura che non ha fatto. Si dice che esiste e che
    // non gli e' concesso - senza descrivergli in dettaglio cosa c'e' dietro.
    if (!self::consentito($tool)) {
        return [
            'errore' => 'Questo tool esiste ma non e\' fra quelli concessi alle tue credenziali.',
            'tool' => self::nome_pulito($tool),
            'cosa_fare' => 'Chiedi tools-manifest con action=lista-dei-tool-che-contengono-le-action '
                         . 'per vedere quali puoi usare davvero.',
        ];
    }

    $chiesta = isset($parametri['action']) ? strtolower(trim((string) $parametri['action'])) : '';
    $azioni = $t['azioni'];

    // UN TOOL PUO' AVERE UN'AZIONE PREDEFINITA, e allora chiamarlo nudo non e'
    // un errore: e' quella. Senza questo, aggiungere l'aiuto a chi sbaglia
    // trasformerebbe in errore una chiamata che oggi funziona - e chi la fa non
    // ha sbagliato niente, e' il comportamento che gli abbiamo promesso.
    if ('' === $chiesta && !empty($t['action_predefinita'])) {
        $chiesta = strtolower(trim((string) $t['action_predefinita']));
    }

    // livello 1: non ha detto quale action, o ne ha detta una che non esiste
    //
    // CORTA, e non e' avarizia. Chi arriva qui ha sbagliato la prima mossa e
    // deve capire il meccanismo, non leggersi il manuale: nome del tool, cosa
    // fa in una riga, i NOMI delle action, e come chiedere il resto. Rovesciare
    // addosso quindici schede complete a chi ha appena sbagliato e' il modo di
    // farsi ignorare - e in un contesto a finestra limitata e' anche spazio
    // rubato alla conversazione vera.
    if ('' === $chiesta || !isset($azioni[$chiesta])) {
        return [
            'errore' => '' === $chiesta ? 'Parametro action mancante.'
                                        : sprintf('La operazione %s non esiste in questo tool.', $chiesta),
            'attenzione' => self::FORMA,
            'tool' => self::nome_pulito($tool),
            'cosa_fa' => self::descrizione_corta($t['descrizione'] ?? ''),
            'action_disponibili' => array_keys($azioni),
            // non sappiamo quale operazione volesse: l'esempio e' come informarsi
            'esempio_di_chiamata' => self::esempio_di($tool),
            // QUI i gradini, e solo qui: chi arriva senza action e' chi non ha
            // ancora capito la forma. Chi sbaglia un parametro l'ha gia' capita
            // e rispiegargliela lo allontanerebbe dalla riga che gli serve.
            'come_funziona_qui' => self::gradini($tool),
        ];
    }

    $def = $azioni[$chiesta];
    $attesi = isset($def['parametri']) && is_array($def['parametri']) ? $def['parametri'] : [];
    $dati = $parametri;
    unset($dati['action'], $dati['user_code']);

    // livello 2: obbligatori mancanti, o nomi che non appartengono a questa action
    $mancanti = [];
    foreach ($attesi as $nome => $p) {
        if (!empty($p['obbligatorio']) && !isset($dati[$nome])) $mancanti[] = $nome;
    }
    // gli sconosciuti si segnalano ma non si rifiutano: molte action passano
    // dati liberi al servizio a valle, e bloccarli spezzerebbe chiamate valide
    $sconosciuti = array_values(array_diff(array_keys($dati), array_keys($attesi)));

    if ($mancanti) {
        return [
            'errore' => 'Parametri obbligatori mancanti per questa action.',
            'tool' => self::nome_pulito($tool),
            'action' => $chiesta,
            'cosa_fa' => $def['descrizione'] ?? '',
            'mancano' => $mancanti,
            'non_riconosciuti' => $sconosciuti,
            'parametri' => self::parametri_leggibili($attesi),
            // qui l'operazione la sappiamo: l'esempio e' del lavoro vero, e si
            // porta dentro i valori che aveva gia' scritto giusti
            'esempio_di_chiamata' => self::esempio_di($tool, $chiesta, $dati),
        ];
    }

    // livello 3: i nomi ci sono tutti, ma un valore non e' fra quelli ammessi
    foreach ($attesi as $nome => $p) {
        if (!isset($dati[$nome])) continue;
        $ammessi = isset($p['valori']) && is_array($p['valori']) ? $p['valori'] : [];
        if (!$ammessi) continue;
        $v = is_scalar($dati[$nome]) ? (string) $dati[$nome] : '';
        if (in_array($v, $ammessi, true)) continue;
        return [
            'errore' => sprintf('Il parametro "%s" ha un valore non ammesso.', $nome),
            'tool' => self::nome_pulito($tool),
            'action' => $chiesta,
            'parametro' => $nome,
            'ricevuto' => $v,
            'come_deve_essere' => [
                'tipo' => $p['tipo'] ?? 'string',
                'valori_ammessi' => $ammessi,
                'descrizione' => $p['descrizione'] ?? '',
            ],
            // l'esempio tiene i valori buoni e sostituisce solo quello sbagliato
            'esempio_di_chiamata' => array_merge(
                self::esempio_di($tool, $chiesta, $dati),
                [$nome => $ammessi[0]]),
        ];
    }

    return null;   // niente da correggere: non e' questo il problema
  }

  private static function parametri_leggibili(array $attesi): array {
    $fuori = [];
    foreach ($attesi as $nome => $p) {
        $fuori[$nome] = array_filter([
            'obbligatorio' => !empty($p['obbligatorio']),
            'tipo' => $p['tipo'] ?? 'string',
            'valori_ammessi' => $p['valori'] ?? null,
            'predefinito' => $p['predefinito'] ?? null,
            'descrizione' => $p['descrizione'] ?? '',
        ], function ($v) { return null !== $v && '' !== $v && false !== $v; });
        // l'obbligatorieta' va detta anche quando e' falsa: "opzionale" e'
        // un'informazione, la sua assenza no
        $fuori[$nome]['obbligatorio'] = !empty($p['obbligatorio']);
    }
    return $fuori;
  }

  // ------------------------------------------- lo schema, dove lo spazio e' poco

  /**
   * Quanto puo' essere lunga la descrizione di un tool nello schema OpenAPI.
   *
   * I due tetti non sono uguali, e non li scegliamo noi: OpenAI dichiara 300
   * caratteri per la descrizione di un endpoint e 700 per quella di un
   * parametro. Valgono per il file openapi.yml, quello che l'utente si monta
   * dallo step 6 della guida e incolla nella creazione di un GPT. Le risposte
   * che il plugin da' al bot non hanno questo limite.
   */
  const TETTO_DESCRIZIONE = 300;

  /** Il tetto per la descrizione di un parametro: piu' del doppio. */
  const TETTO_PARAMETRO = 700;

  /**
   * La descrizione del tool, tagliata dove serve.
   *
   * Il tetto e' di chi legge lo schema, non nostro: le descrizioni dei tool
   * oltre i trecento caratteri vengono troncate a monte, e una frase tagliata a
   * meta' da qualcun altro e' peggio di una frase corta scritta apposta. Si
   * taglia su uno spazio, mai in mezzo a una parola.
   */
  public static function descrizione_corta(string $testo, int $tetto = self::TETTO_DESCRIZIONE): string {
    $t = trim(preg_replace('/\s+/', ' ', $testo));
    if (strlen($t) <= $tetto) return $t;
    // mb_strcut, non substr: taglia entro un tetto di BYTE ma senza spezzare un
    // carattere. Di solito non serve, perche' si finisce per tagliare su uno
    // spazio e lo spazio e' ASCII; ma se in trecento byte non c'e' nemmeno uno
    // spazio - un indirizzo lungo, un identificatore - il ripiego taglierebbe a
    // meta' una lettera accentata e il testo arriverebbe rotto.
    $tagliato = function_exists('mb_strcut')
        ? mb_strcut($t, 0, $tetto - 3, 'UTF-8')
        : substr($t, 0, $tetto - 3);
    $spazio = strrpos($tagliato, ' ');
    return rtrim($spazio ? substr($tagliato, 0, $spazio) : $tagliato, " ,;.") . '…';
  }

  /**
   * I parametri del tool visti tutti insieme, con dentro scritto a chi servono.
   *
   * IL PROBLEMA DELLO SPAZIO. Nello schema il tool ha UNA descrizione, e ci
   * stanno trecento caratteri: con quindici action non bastano nemmeno per
   * elencarle. Ma ogni parametro ha un campo di testo suo, e quelli non sono
   * contati - lo spazio quindi c'e', solo distribuito. Cosi' la scheda del tool
   * resta corta e il dettaglio va dove viene letto davvero: accanto al
   * parametro che uno sta per riempire.
   *
   * COSA CI SI SCRIVE. Per quali action e' obbligatorio, per quali opzionale, e
   * cosa deve contenere. Un parametro che compare in action diverse con ruoli
   * diversi - obbligatorio di la', facoltativo di qua - e' esattamente il caso
   * in cui una descrizione unica mentirebbe a meta' dei lettori.
   */
  public static function parametri_uniti(string $tool): array {
    $t = self::tool($tool);
    if (empty($t['azioni'])) return [];

    $raccolta = [];
    foreach ($t['azioni'] as $azione => $def) {
        $attesi = isset($def['parametri']) && is_array($def['parametri']) ? $def['parametri'] : [];
        foreach ($attesi as $nome => $p) {
            if (!isset($raccolta[$nome])) {
                $raccolta[$nome] = ['tipo' => $p['tipo'] ?? 'string', 'obbligatorio_in' => [],
                                    'opzionale_in' => [], 'valori' => [], 'note' => []];
            }
            $r =& $raccolta[$nome];
            if (!empty($p['obbligatorio'])) $r['obbligatorio_in'][] = $azione;
            else $r['opzionale_in'][] = $azione;
            if (!empty($p['valori']) && is_array($p['valori']))
                $r['valori'] = array_values(array_unique(array_merge($r['valori'], $p['valori'])));
            // la nota si tiene ATTACCATA alla sua action: lo stesso parametro
            // vuole cose diverse in action diverse, e una fila di frasi senza
            // dire di chi sono e' peggio del silenzio
            $d = trim((string) ($p['descrizione'] ?? ''));
            if ('' !== $d) $r['note'][$azione] = $d;
            unset($r);
        }
    }

    $fuori = [];
    foreach ($raccolta as $nome => $r) {
        $pezzi = [];
        /*
         * Se un parametro vale per TUTTE le action, si dice cosi' invece di
         * elencarle.
         *
         * Su un tool con venti action l'elenco diventa una riga di duecento
         * caratteri che ripete quello che il lettore ha appena visto
         * nell'enum, e si mangia lo spazio della sola frase che serve davvero:
         * a cosa serve il parametro. Su gather-information la descrizione
         * arrivava a superare i settecento caratteri e usciva troncata a meta'
         * parola.
         */
        $tutte = count($t['azioni']);
        $q_obb = count($r['obbligatorio_in']);
        $q_opz = count($r['opzionale_in']);

        if ($q_obb === $tutte) {
            $pezzi[] = 'Obbligatorio per tutte le action.';
        } elseif ($r['obbligatorio_in']) {
            // Stessa regola dell'elenco opzionale, che prima aveva solo quello:
            // su woocommerce l'id e' obbligatorio in venti action, e i soli
            // nomi facevano seicento caratteri. Il ripiego scattava e tagliava
            // la frase a meta' parola.
            $pezzi[] = ($q_obb > 6)
                ? 'Obbligatorio per ' . $q_obb . ' action su ' . $tutte . '.'
                : 'Obbligatorio per: ' . implode(', ', $r['obbligatorio_in']) . '.';
        }

        if ($q_opz === $tutte) {
            $pezzi[] = 'Opzionale per tutte le action.';
        } elseif ($r['opzionale_in']) {
            // Oltre le sei, l'elenco smette di aiutare e comincia a occupare
            // spazio: si dice quante sono e si rimanda alla documentazione
            // della singola action, che il modello sa gia' come chiedere.
            $pezzi[] = ($q_opz > 6)
                ? 'Opzionale per ' . $q_opz . ' action.'
                : 'Opzionale per: ' . implode(', ', $r['opzionale_in']) . '.';
        }

        // Le note uguali si accorpano sotto le loro action, invece di ripetersi:
        // meta' della lunghezza veniva da frasi identiche scritte piu' volte.
        $per_nota = [];
        foreach ($r['note'] as $azione => $n) $per_nota[rtrim($n, '.')][] = $azione;
        $note = [];
        foreach ($per_nota as $n => $quali) {
            $note[] = (count($quali) === count($r['note']))
                ? $n . '.'                                   // vale per tutte: non serve dirlo
                : implode('/', $quali) . ': ' . $n . '.';
        }

        // SE NON CI STA, NON SI TRONCA: si rimanda. Una frase tagliata a meta'
        // perde proprio l'ultima action, e chi legge non ha modo di sapere che
        // gliene manca una - mentre un rimando e' una porta che resta aperta.
        // Misurato su entity_id, che compare in sei action.
        // Il tetto qui e' quello dei PARAMETRI, 700, non quello degli endpoint.
        // Misurare contro 300 buttava via quattrocento caratteri buoni e faceva
        // scattare il ripiego quando non serviva - e nel caso peggiore il
        // troncamento, che lascia la descrizione a meta' parola con i puntini.
        $testo = implode(' ', array_merge($pezzi, $note));
        if (strlen($testo) > self::TETTO_PARAMETRO) {
            $testo = implode(' ', $pezzi) . ' Cambia significato secondo l\'action: chiedi '
                   . self::PREFISSO_DOC . '<nome-action> su questa stessa rotta.';
            if (strlen($testo) > self::TETTO_PARAMETRO) {
                $testo = self::descrizione_corta($testo, self::TETTO_PARAMETRO);
            }
        }
        $fuori[$nome] = [
            'type' => $r['tipo'],
            // I VALORI AMMESSI VANNO IN enum, NON NELLA FRASE. Infilarli nel
            // testo li espone al tetto dei trecento: misurato, l'elenco delle
            // action veniva troncato e le ultime due sparivano - un modello
            // debole non poteva sapere che esistevano, e uno forte doveva
            // indovinare che l'elenco fosse incompleto. In enum non si tagliano
            // mai, e chi legge li riceve come vincolo invece che come prosa.
            'enum' => $r['valori'],
            'description' => $testo,
            // NON si dichiara `required` a livello di schema: qui un parametro
            // e' obbligatorio solo per certe action, e marcarlo obbligatorio
            // sempre farebbe rifiutare a monte chiamate perfettamente valide.
            'obbligatorio_dipende_dall_action' => !empty($r['obbligatorio_in']),
        ];
    }

    // l'action va per prima: e' quella che decide il senso di tutte le altre.
    // I nomi stanno in enum, dove non li tocca nessun tetto di caratteri.
    $azione = ['type' => 'string',
      'enum' => array_keys($t['azioni']),
      'description' => 'Quale operazione eseguire: decide quali altri parametri servono. '
                     . 'I valori ammessi sono quelli elencati in enum. Per sapere cosa vuole '
                     . 'una singola operazione chiama questa stessa rotta con action='
                     . self::PREFISSO_DOC . '<nome-action>.',
      'obbligatorio_dipende_dall_action' => true];
    return array_merge(['action' => $azione], $fuori);
  }

  // ----------------------------------------------------- l'elenco, cercabile

  /**
   * L'elenco dei tool che contengono action, cercabile e a pagine.
   *
   * LA RICERCA PERDONA, perche' chi cerca un tool spesso ne ricorda il nome a
   * meta': si accetta il pezzo contenuto ("camera" trova
   * manage-homeassistant-dashboard-and-entities se e' fra le sue action) e la
   * lettera fuori posto - "amanage" per "manage" - che e' l'errore di battitura
   * piu' comune e quello che un confronto esatto punisce piu' ingiustamente.
   */
  public static function elenco(string $cerca = '', int $pagina = 1, int $per_pagina = 20): array {
    $reg = self::registro();
    $cerca = strtolower(trim($cerca));
    $voci = [];

    // GLI ATTREZZI PER CERCARE VANNO PER PRIMI.
    //
    // Erano esclusi come "rotte di servizio", e cosi' lo strumento con cui si
    // esplora non era esplorabile: chi arrivava senza sapere niente vedeva
    // novantacinque tool e nessun modo di restringere il campo. Chi legge un
    // elenco lungo legge le prime righe: e' li' che va messo come si fa a non
    // leggerlo tutto.
    foreach (self::utilita() as $u) {
        if ('' === $cerca || self::somiglia($cerca, $u['tool'], $u['action'], $u)) $voci[] = $u;
    }

    foreach ($reg as $nome => $t) {
        if (self::di_servizio($nome)) continue;
        // NELL'ELENCO SI TACE, e non si scrive "vietato": un elenco che nomina
        // cio' che non puoi usare e' un elenco di cose da provare, e ti fa
        // costruire un assistente attorno a funzioni che risponderanno 403.
        if (!self::consentito($nome)) continue;
        $azioni = isset($t['azioni']) && is_array($t['azioni']) ? array_keys($t['azioni']) : [];
        if (!$azioni) continue;                      // senza action non e' un tool
        if ('' !== $cerca && !self::somiglia($cerca, $nome, $azioni, $t)) continue;
        $voci[] = [
            'tool' => self::nome_pulito($nome),
            'descrizione' => $t['descrizione'] ?? '',
            'quante_action' => count($azioni),
            'action' => $azioni,
        ];
    }

    $totale = count($voci);
    $per_pagina = max(1, $per_pagina);
    $pagine = (int) max(1, ceil($totale / $per_pagina));
    $pagina = min(max(1, $pagina), $pagine);

    // LA REGOLA PRIMA DELL'ELENCO, e non in fondo: chi legge una risposta lunga
    // legge le prime righe. Se la regola sta in coda, la trova solo chi ha gia'
    // capito - cioe' chi non ne aveva bisogno.
    return [
        'come_funziona' => 'Qui ogni rotta e\' un TOOL e ogni tool contiene tante ACTION, '
                         . 'ognuna con parametri suoi. Non si chiama mai un tool "e basta": '
                         . 'si sceglie sempre un\'action, e sono i parametri di quell\'action '
                         . 'a cambiare. Vale per tutti i tool di questo sito, sempre.',
        'come_si_esplora' => self::BUSSOLA,
        'tool' => array_slice($voci, ($pagina - 1) * $per_pagina, $per_pagina),
    ] + self::pagine($pagina, $pagine, $per_pagina, $totale, 'pagina');
  }

  /**
   * Gli attrezzi per orientarsi, descritti corti.
   *
   * MINIMI APPOSTA. Stanno in cima a un elenco che uno sta scorrendo per
   * trovare altro: se occupano dieci righe a testa diventano il rumore da cui
   * volevano salvarlo. Una riga ciascuna, e dicono cosa fanno, non quanto sono
   * potenti.
   *
   * NON VENGONO DAL REGISTRO perche' non sono rotte come le altre: sono il modo
   * di leggere le altre, e devono esserci anche su un sito dove nessun plugin
   * ha ancora dichiarato niente - cioe' proprio quando servono di piu'.
   */
  public static function utilita(): array {
    return [[
      'tool' => 'tools-manifest',
      'descrizione' => 'L\'attrezzo per orientarsi: elenca i tool di questo sito, '
                     . 'le action di ognuno e i parametri di ogni action.',
      'quante_action' => 3,
      'action' => [
        'lista-dei-tool-che-contengono-le-action',
        'scopri-parametri-dei-tool',
        'scopri-parametri-di-un-action-in-un-tool',
      ],
      'a_cosa_servono' => [
        'lista-dei-tool-che-contengono-le-action' =>
          'Elenca i tool. Accetta cerca (anche parziale o con una lettera fuori posto), '
        . 'pagina e per_pagina.',
        'scopri-parametri-dei-tool' =>
          'Dato tool=<nome>, elenca le sue action e cosa fa ognuna.',
        'scopri-parametri-di-un-action-in-un-tool' =>
          'Dato tool=<nome> e nome_action=<action>, da\' i parametri obbligatori, '
        . 'gli opzionali e i valori ammessi.',
      ],
    ]];
  }

  /** Il termine cercato somiglia al tool, a una sua action o alla descrizione? */
  private static function somiglia(string $cerca, string $nome, array $azioni, array $t): bool {
    $fieno = array_merge([self::nome_pulito($nome), (string) ($t['descrizione'] ?? '')], $azioni);
    foreach ($fieno as $paglia) {
        $paglia = strtolower((string) $paglia);
        if ('' === $paglia) continue;
        if (false !== strpos($paglia, $cerca)) return true;
        foreach (preg_split('/[^a-z0-9]+/', $paglia) as $parola) {
            if ('' === $parola) continue;
            if (self::una_lettera_spostata($cerca, $parola)) return true;
        }
    }
    return false;
  }

  /**
   * Stesse lettere, una sola in un'altra posizione.
   *
   * Prima si confrontano le lettere ordinate: se non sono le stesse non e' un
   * refuso di posizione ed e' inutile andare oltre. Poi si prova a togliere una
   * lettera da una parte e a rimetterla altrove: se cosi' le due coincidono,
   * era una lettera fuori posto.
   */
  public static function una_lettera_spostata(string $a, string $b): bool {
    if ($a === $b) return true;
    if (strlen($a) !== strlen($b) || strlen($a) < 3) return false;
    $sa = str_split($a); sort($sa);
    $sb = str_split($b); sort($sb);
    if ($sa !== $sb) return false;
    for ($i = 0, $n = strlen($a); $i < $n; $i++) {
        $senza = substr($a, 0, $i) . substr($a, $i + 1);
        $lettera = $a[$i];
        for ($j = 0; $j <= strlen($senza); $j++) {
            if (substr($senza, 0, $j) . $lettera . substr($senza, $j) === $b) return true;
        }
    }
    return false;
  }
}

endif;
