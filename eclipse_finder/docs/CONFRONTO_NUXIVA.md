# Eclipse «Casa viva» e Nuxiva: confronto funzione per funzione

Aggiornato alla versione 0.31.0 di Eclipse (13 ottobre 2026).
Il riferimento è Nuxiva OS di Nunzio Aprile, in licenza MIT, nel clone `scratchpad/esterni/Nuxiva`: cartelle `installer_wizard/features`, `web/display` e `server`.

Eclipse non copia codice di Nuxiva: le funzioni sono riscritte da zero, partendo da quello che Home Assistant sa già fare.

**Legenda dello stato**
- ✅ c'è, alla pari o meglio;
- ➕ fatto nella 0.31;
- 🏠 lo fa già Home Assistant: si usa quello, non si rifà;
- ⏭️ fuori dallo scopo di Casa viva.

## La casa

| Funzione | Nuxiva (`features/…`) | Eclipse Casa viva | Stato | Cosa manca o perché è meglio |
| :--- | :--- | :--- | :---: | :--- |
| Stanze, piani e dispositivi | `home_assistant` (studia HA in un database SQLite proprio), `places` (mappa a mano) | Le stanze vengono dalla **planimetria disegnata in Eclipse**, su più piani, con muri, finestre e mobili. Le entità di HA si legano alle stanze con proposte automatiche (`proponi_legami`: aree di HA e nomi simili). | ✅ | Le stanze hanno coordinate, finestre e mobili veri, e la casa 3D è la stessa pianta. Nessun database duplicato: lo stato vivo lo dà HA. |
| Gemello digitale (simulare prima di comandare) | `twin` (simula su una copia e scarta ciò che viola le regole) | `simula()` ed `esegui()`, regole **per stanza**: per esempio il clima con la finestra della **stessa** stanza aperta, l'allarme inserito con aperture, le valvole a casa vuota, i comandi opposti nello stesso piano. Ogni comando della pagina e della card mostra la simulazione e chiede conferma. | ✅ | Le regole sanno la stanza vera, non «una finestra qualunque». Si può scegliere fra avviso e blocco. |
| Conferma per serrature, allarme, cancelli e garage | `home_assistant` (chiede conferma a voce) | Tutto ciò che cambia la casa vuole `conferma: true`. Dalla card c'è un dialogo con l'esito della simulazione. | ✅ | |
| Scene | `scene` (grafo spaziale, oggetti visti dalle telecamere) | Scene **per stanza** (la foto delle entità della stanza) **fuse** in scene di casa («Sera» = soggiorno caldo + cucina spenta). Si applicano passando dal gemello. | ✅ | Gli oggetti visti dalle telecamere li lasciamo a Frigate e al riconoscimento di HA (🏠). |
| Presenza | `scene/fusion.py` (volto, voce e tracker fusi) | ➕ **Presenza fusa**: persone di HA, tracker di stanza (Bermuda, ESPresense, room-assistant: stato o attributo `area`), sensori di movimento delle stanze. Per ogni persona dà la stanza più probabile **e il perché**. Si vede nella scheda «👥 Presenza» e nella card. | ➕ | Niente volti né registrazioni: usa quello che HA ha già, ed è privato per costruzione. |
| Abitudini | `habits` (regolarità notturne, proposte a voce) | Dallo storico di HA: stessa ora nei feriali o nel fine settimana, oppure al tramonto. Le proposte diventano automazioni di HA solo con un clic. | ✅ | Le automazioni create sono **automazioni vere di HA**, modificabili nell'editor: niente motore parallelo. |
| Situazioni insolite a casa vuota | `habits` (porte, finestre o movimenti a casa vuota) | ➕ **Avvisi a casa vuota**: con tutte le persone fuori, porte, finestre o movimento nelle stanze legate mandano una notifica, nella campanella di HA e nei servizi `notify` scelti. C'è una pausa per sensore. Spenti di serie. | ➕ | Solo avvisi: non tocca niente della casa. |
| Volume durante la conversazione | `ducking` | **Volume**: mentre una voce parla (satellite Assist, annunci) le casse scelte si abbassano, poi tornano come prima, tranne quelle toccate nel frattempo. | ✅ | Funziona con qualsiasi voce di HA, non solo con la propria. |
| Allarme rosso | `redalert` (luci rosse lampeggianti, messaggio sui Chromecast) | **Allarme**: fumo, gas, acqua o monossido. Luci rosse lente (sicure per chi è sensibile alla luce), annuncio **con la stanza** del sensore, notifiche, poi luci **esattamente** come prima. C'è la prova a secco e la prova di una luce. | ✅ | Dice dove succede e rimette i colori nel loro modo (temperatura o RGB). |
| Riepilogo della casa a voce | `home_assistant/answers.py` (risposte sullo stato) | ➕ **Riepilogo** in frasi: chi c'è e dove, luci accese, aperture, temperature, cosa suona, allarme. È in `/api/casa/riepilogo`, nella card e nella scheda Presenza. | ➕ | |
| Automazioni a più stadi | `automations` (motore proprio) | 🏠 Le automazioni di HA. Eclipse ci arriva dalle Abitudini. | 🏠 | Un secondo motore farebbe a pugni con quello di HA. |
| Comandi a voce in millisecondi | `home_assistant/nlu`, `ear` | 🏠 Assist di HA, e l'avatar che parla con l'agente di conversazione scelto (`avatar_agente`). | 🏠 | |
| Esploratore della rete | `network` (nmap) | ⏭️ | ⏭️ | Non è domotica. Esistono integrazioni di HA (nmap tracker). |
| Telecamere e NVR | `cameras`, `nvr` | 🏠 Camere di HA, Frigate. | 🏠 | |
| TV, IPTV, musica, Spotify | `tv`, `music`, `spotify` | 🏠 Media player di HA. Casa viva li vede per stanza (🎵) e li abbassa col Volume. | 🏠 | |

## L'avatar e la presenza in casa

| Funzione | Nuxiva | Eclipse | Stato | Note |
| :--- | :--- | :--- | :---: | :--- |
| Personaggio che vive (attese, gesti) | `display/scene/character` (Life, Gestures, Actions…), `appearance` | Il **proprio personaggio del gioco** Another Wilde Web: attese di ArcheAge, gesti di conversazione, sguardo verso chi parla, labbra con la voce. | ✅ | È il proprio avatar, con viso, costume, **armatura e armi del gioco** (➕ 0.31). |
| Parlare con l'avatar | display + `ear` + `voices` | Scheda «Il mio avatar»: scritto o a voce (riconoscimento del browser), risposte dall'agente di HA, voce dal TTS di HA, solo nella pagina. | ✅ | |
| Robot che esegue ordini | — | Il RobotMODE del gioco comandato dalla chat di HA (0.29). | ✅ | Solo Eclipse. |
| La casa nel gioco | — | «Manda al gioco»: la planimetria diventa una pergamena per costruire la casa (0.29). Il proprio avatar diventa un costume nel gioco (0.30). | ✅ | Solo Eclipse. |
| Card in dashboard | display a schermo intero (`kiosk`) | ➕ **Card Lovelace** `custom:eclipse-casa-viva-card`: stanze vive, persone, scene, automatismi, allarme e avatar, in qualsiasi dashboard e anche sul telefono. | ➕ | Non serve un PC dedicato in kiosk. |

## Fuori dallo scopo di Casa viva

Queste parti di Nuxiva sono un sistema operativo per assistenti, non domotica. Non entrano in Eclipse:

- cervello e modelli (`brain`, `mind`, `models3d`);
- agenti (`team`, `forge`, `skills`, `rpa`);
- documenti, studio, lavagna e presentazioni (`documents`, `study`, `whiteboard`, `presentation`);
- sistema e rete (`firewall`, `vpn`, `proxmox`, `printers`, `shares`, `vault`, `secure`);
- canali (`telegram`, `google`, `cloud`);
- biometria (`biometrics`).

Dove serve, Home Assistant ha già le sue integrazioni.
