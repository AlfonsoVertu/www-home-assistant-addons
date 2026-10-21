# Eclipse Finder

Add-on per Home Assistant che **prevede la prossima eclissi** (solare e lunare) dalle coordinate di casa e ti mostra **da quale finestra/balcone si vede meglio**, con mappa a bussola e overlay sulla foto della vista.

## Cosa fa

- Legge automaticamente **latitudine/longitudine** dalla configurazione di Home Assistant.
- Calcola la prossima **eclissi solare** e **lunare**: data/ora locale, tipo (parziale/totale/anulare/penombrale), **azimut + direzione bussola**, **altezza sull'orizzonte**, **% di oscuramento** e se è **visibile dal tuo punto**.
- **Wizard "Aggiungi finestra"**:
  1. Nome (es. *Balcone soggiorno*).
  2. Direzione: **bussola del telefono** (*Attiva bussola → Blocca direzione*) **oppure gradi a mano** (N=0, E=90, S=180, O=270).
  3. **Foto** della vista.
- Per ogni finestra mostra:
  - una **mappa a bussola** con il campo visivo della finestra e la posizione dell'eclissi;
  - la **foto con il Sole/Luna sovrapposto** nel punto stimato (o l'indicazione "ruota verso …" se fuori inquadratura).

## Uso

1. Installa e avvia l'add-on, poi apri **Eclissi** dal menu laterale.
2. Dal telefono (per la bussola serve **HTTPS**): apri Home Assistant via dominio HTTPS / Nabu Casa.
3. Aggiungi le tue finestre/balconi. L'add-on ti dirà da dove sarà visibile la prossima eclissi.

## Note tecniche

- Motore astronomico: [`astronomy-engine`](https://github.com/cosinekitty/astronomy).
- I dati (finestre + foto) sono salvati nel volume persistente `/data` dell'add-on.
- La bussola nel browser (DeviceOrientation API) funziona solo in **contesto sicuro (HTTPS)**; su desktop usa l'inserimento manuale dei gradi.
- L'altezza del Sole/Luna sulla foto è **stimata** (centro foto ~15°, FOV ~66°); l'accuratezza verticale migliorerà catturando l'inclinazione del telefono allo scatto (roadmap).

## Privacy

L'add-on non invia dati all'esterno: tutti i calcoli e i file (foto incluse) restano su Home Assistant.

## Il tuo avatar di Another Wilde Web nella casa (0.26)

Nella **Casa 3D** c'è il pulsante **🧍 Il mio avatar**: si entra con l'account del gioco *Another Wilde Web*
(nome e password di Minecraft) e il proprio personaggio compare nella casa, con il suo viso, i capelli, i colori e il
costume che indossa in gioco. **WASD** o le frecce per camminare, **Maiusc** per correre, il mouse per girare attorno;
i muri fermano il personaggio e diventano trasparenti quando stanno fra lui e la telecamera.

- La password passa una volta sola al portale del gioco e **non si salva**: Eclipse tiene solo un gettone di 30 giorni
  in `/data/gioco.json`. In alternativa si possono mettere `gioco_nome` e `gioco_password` nelle opzioni dell'add-on:
  Eclipse entra da solo quando serve.
- `gioco_url` (di serie `https://gioca.workingwithweb.eu`) è il portale da cui arriva il personaggio.
- I modelli del personaggio (ArcheAge, solo uso personale) e il codice che lo anima **non stanno in questo
  repository**: Eclipse li chiede al portale col gettone e li tiene in una cache in `/data/gioco_cache`. Gli indirizzi
  `gioco/`, `aspetti/`, `modelli/` rispondono solo dall'interfaccia di Home Assistant (ingress).
- La casa 3D ora usa three.js 0.160 (la stessa versione del gioco), incluso nell'immagine.

## Casa viva (0.27)

Eclipse sa com'è fatta la casa (stanze, finestre, mobili, piani); Home Assistant sa cosa succede. La sezione
**Casa viva** della pagina li mette insieme:

- **🏠 Stanze (il gemello)**: ogni dispositivo di HA si lega a una stanza (✨ *Proponi i legami* usa le aree di HA e i
  nomi; 🔗 *Lega a mano* per il resto). Ogni stanza mostra lo stato vivo: luci accese, qualcuno presente, porte e
  finestre aperte, temperatura, musica. Nella **Casa 3D** sopra ogni stanza compare lo stesso cartellino e le stanze con
  le luci accese si illuminano. Ogni comando passa prima dalla **simulazione**: le regole guardano la stanza vera
  (clima con la finestra della *stessa* stanza aperta = avviso; serrature e porte aperte, telecamere spente con
  l'allarme inserito, valvole aperte con nessuno in casa, comandi opposti nello stesso piano = bloccati).
- **🎬 Scene**: la fotografia dei dispositivi di una stanza (luci con luminosità e colore, tapparelle, clima, volumi);
  più scene di stanze diverse si **fondono** in una scena di casa. *Prova* mostra cosa farebbe, *Applica* chiede
  conferma.
- **💡 Abitudini**: dal registro di HA delle ultime due settimane, le cose fatte a mano (non da automazioni) sempre
  alla stessa ora, nei feriali, nel fine settimana o legate al tramonto. *Crea* la mette in HA come automazione (solo
  con qualcuno in casa); *No* non la ripropone.
- **🔉 Volume**: quando una delle *voci* scelte parla (altoparlante degli annunci, satellite dell'assistente) le
  *casse* scelte si abbassano; a voce finita tornano al volume di prima, tranne quelle toccate nel frattempo.
- **🚨 Allarme**: se un sensore scelto segnala fumo, gas, acqua o monossido, le luci lampeggiano lente di rosso, la
  frase con la stanza del sensore si ripete sulle casse, partono le notifiche; con *Ferma* o a tempo scaduto le luci
  tornano com'erano. *Prova a secco* dice cosa farebbe senza toccare niente; *Prova su una luce* la fa lampeggiare 6 s.

Opzioni dell'add-on: `casa_viva` (di serie acceso), `volume_automatico` e `allarme_automatico` (di serie spenti: gli
automatismi che agiscono da soli vanno accesi qui, oltre che nella pagina). Le impostazioni della pagina stanno in
`/data/domotica.json`. Tutto risponde solo dall'interfaccia di Home Assistant; ciò che cambia la casa chiede conferma.

## Il mio avatar: vederlo e parlarci (0.28)

In «Casa viva» la scheda **🧍 Il mio avatar** mostra il tuo personaggio del gioco (corpo, viso, colori e costume dal
portale, come nella casa 3D) da vicino, a mezzo busto o a figura intera, con una luce da ritratto. Se non sei ancora
entrato col gioco, la scheda chiede nome e password del gioco (vanno al portale, Eclipse tiene solo il gettone).

- **È vivo**: respira con l'attesa del gioco e, quando nessuno parla, fa le attese del gioco (braccia conserte, mani
  sui fianchi, si stiracchia, guarda l'orologio, si guarda intorno). Quando scrivi, parli o risponde, ti guarda.
- **Ci parli** scrivendo o col 🎤 (il riconoscimento vocale del browser, in italiano; in Chrome passa dai server di
  Google). Risponde l'**agente di conversazione di Home Assistant** (Assist di serie, o quello scritto in
  `avatar_agente`, ad esempio `conversation.openai_conversation`): può quindi anche comandare la casa, con le regole e
  le entità esposte di HA. Il filo del discorso resta finché la pagina è aperta.
- **Risponde a voce** col TTS di Home Assistant (`avatar_tts`, se no il primo motore che funziona): l'audio lo scarica
  Eclipse e suona **solo nel browser**, mai sugli altoparlanti di casa. Senza TTS usa la sintesi vocale del browser.
- **Labbra e gesti**: la bocca si apre con l'ampiezza della voce (una mascella mobile nel viso, il Nuian non ha l'osso
  della mascella); mentre parla fa una posa di conversazione di ArcheAge (le stesse degli NPC del gioco), all'apertura
  saluta. Se le pose non ci sono, gesti di spiegazione fatti a mano.

Opzioni: `avatar_agente` (vuoto = predefinito di HA), `avatar_tts` (vuoto = il primo che risponde). Rotte (solo dal
pannello): `POST api/avatar/parla`, `POST api/avatar/voce`, `GET api/avatar/audio/<id>` (10 minuti).

## La tua casa nel gioco (0.29)
Nella Casa 3D il pulsante **🏗️ Manda al gioco** porta la planimetria al portale del gioco, con l'account collegato.
Nel gioco l'**Architetto** (vicino all'Employment Agency) la misura e, per 100.000 Cadde, ne fa una **pergamena**: col
clic destro la casa sorge sul tuo lotto, 1 blocco = 50 cm, solai e tetto a mezze lastre (massimo 112x112 blocchi, 64 di
altezza). Il portale tiene la planimetria 7 giorni sotto un nome a caso.

## Il pannello del personaggio (0.29)
Nella scheda «🧍 Il mio avatar», sopra la chat, c'è lo stato del tuo personaggio in gioco (mondo, posizione, vita) e il
suo **RobotMODE**: «Accendi il robot», «Spegni», e la casella «gli ordini della chat al robot». Il gioco vero gira in una
finestra di Chrome («🎮 Apri il gioco»: gioca.workingwithweb.eu, entri e premi Gioca); da qui, con la casella spuntata e
il robot acceso, quello che scrivi o dici va al robot e le sue risposte arrivano nella chat (anche a voce). Con la casella
spenta la chat parla con l'assistente di Home Assistant come sempre. Il modello del robot si sceglie nel gioco (F8).

## Il mio avatar come costume (0.30)
Nella scheda «🧍 Il mio avatar», in fondo, «🎭 Il mio avatar come costume»: scegli un tuo avatar (.glb o .vrm con lo
scheletro, tipo Mixamo o VRM; al massimo 15 MB e 60.000 triangoli), lo vedi girare in anteprima e con «Porta nel gioco» va
al portale del gioco col tuo account. Lì lo guarda un amministratore (niente nudo esplicito, mai approvazioni automatiche);
approvato, lo compri dal Costume Tailor per 30.000 Cadde e nel gioco si muove con le animazioni del tuo personaggio.

## La card «Casa viva» in dashboard e la presenza (0.31)
- **Card per le dashboard**: `custom:eclipse-casa-viva-card` mostra le stanze vive (luci, aperture, temperature, chi c'è),
  le persone e dove sono, le scene, gli automatismi, l'allarme (col tasto Ferma) e il tuo avatar che vive. Ogni comando
  si simula e si conferma. L'add-on la copia da solo in `/config/www/eclipse/` e la registra fra le risorse di Lovelace;
  poi in una dashboard: Modifica → Aggiungi card → «Eclipse · Casa viva», oppure in YAML:
  ```yaml
  type: custom:eclipse-casa-viva-card
  titolo: Casa viva   # facoltativo
  avatar: true        # false = senza avatar
  ```
  Se le tue dashboard sono in modalità YAML, aggiungi la risorsa a mano: `/local/eclipse/eclipse-casa-viva-card.js?v=0.31.2`
  (tipo `module`). La card parla con l'add-on attraverso l'ingress di HA, con la tua sessione: funziona per gli
  amministratori di Home Assistant.
- **Presenza**: per ogni persona di HA la stanza più probabile (tracker di stanza come Bermuda o ESPresense, oppure
  l'ultimo movimento) e il perché; scheda «👥 Presenza».
- **Avvisi a casa vuota**: con tutti fuori, porte, finestre o movimento diventano notifiche (spento di serie).
- **Riepilogo** della casa in frasi (`api/casa/riepilogo`).
- **Le armi del gioco** sul tuo avatar: l'ultima arma usata sulla schiena, con la skin e l'aura del grado, e l'armatura
  indossata (le scrive il gioco quando giochi).
- Il confronto con Nuxiva, funzione per funzione: `docs/CONFRONTO_NUXIVA.md`.
