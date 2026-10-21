// CASA VIVA nella pagina di Eclipse (0.27). Licenza MIT. Cinque schede (più «Il mio avatar» dalla 0.28, in ritratto.js): Stanze (il gemello: lo stato vivo di ogni
// stanza e i legami entità → stanza), Scene (fotografie di stanza, fuse in scene di casa), Abitudini (le cose fatte a
// mano sempre alla stessa ora, proposte come automazioni), Volume (le casse si abbassano quando parla una voce),
// Allarme (fumo, gas, acqua: luci rosse lente e annuncio con la stanza). Tutto ciò che cambia la casa chiede conferma;
// prima di applicare si vede la SIMULAZIONE del gemello (cosa passa, cosa è bloccato e perché).
(function () {
  const box = document.getElementById('casaViva'); if (!box) return;
  const api = (u, o) => fetch(u, o).then(async r => { const j = await r.json().catch(() => ({})); if (!r.ok) throw new Error(j.detail || ('errore ' + r.status)); return j; });
  const post = (u, b) => api(u, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(b || {}) });
  const esc = t => String(t == null ? '' : t).replace(/[&<>"]/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));
  const SCHEDE = [['stanze', '🏠 Stanze'], ['scene', '🎬 Scene'], ['abitudini', '💡 Abitudini'], ['volume', '🔉 Volume'], ['allarme', '🚨 Allarme'], ['presenza', '👥 Presenza'], ['avatar', '🧍 Il mio avatar']];
  let scheda = 'stanze';
  try { scheda = localStorage.getItem('eclipse:casaviva') || 'stanze'; } catch (e) { /* niente memoria */ }
  box.innerHTML = '<div class="row" id="cvSchede" style="gap:6px;flex-wrap:wrap"></div><div id="cvCorpo" style="margin-top:10px"></div>';
  const corpo = box.querySelector('#cvCorpo');
  function schede() {
    const r = box.querySelector('#cvSchede'); r.innerHTML = '';
    for (const [id, t] of SCHEDE) { const b = document.createElement('button'); b.className = id === scheda ? '' : 'sec'; b.textContent = t; b.onclick = () => { scheda = id; try { localStorage.setItem('eclipse:casaviva', id); } catch (e) {} schede(); apri(); }; r.appendChild(b); }
  }
  const errore = e => { corpo.innerHTML = '<div class="bad">' + esc(e.message) + '</div>'; };
  let chiudi = null;              // la scheda dell'avatar si spegne (disegno, microfono, voce) quando se ne esce
  function apri() { if (chiudi) { try { chiudi(); } catch (e) {} chiudi = null; } ({ stanze, scene, abitudini, volume, allarme, presenza, avatar })[scheda]().catch(errore); }

  // ---------------------------------------------------------------- il mio avatar (0.28)
  // il personaggio del gioco da vicino, e ci si parla (scritto o a voce): ritratto.js
  async function avatar() {
    corpo.innerHTML = '<div class="mut">Preparo il tuo avatar…</div>';
    const { apriRitratto } = await import('./ritratto.js');
    const c = await apriRitratto(corpo);
    if (scheda === 'avatar') chiudi = c; else c();
  }
  // l'esito di una simulazione (o di un'esecuzione): una riga per azione
  const esiti = l => '<div style="margin-top:6px">' + l.map(e => `<div class="${e.esito === 'bloccata' ? 'bad' : e.esito === 'avviso' ? '' : 'mut'}" style="font-size:13px">${e.esito === 'bloccata' ? '⛔' : e.esito === 'avviso' ? '⚠️' : '✓'} ${esc(e.entity_id)} · ${esc(e.servizio)}${e.perche ? ' — ' + esc(e.perche) : ''}${e.eseguita === false ? ' (non eseguita)' : ''}</div>`).join('') + '</div>';

  // ---------------------------------------------------------------- stanze
  async function stanze() {
    corpo.innerHTML = '<div class="mut">Leggo la casa…</div>';
    const [g, l] = await Promise.all([api('api/casa/gemello'), api('api/casa/legami')]);
    const legate = Object.keys(l.legami).length;
    let h = `<div class="mut" style="margin-bottom:8px">${legate} dispositivi legati alle stanze. Le regole di sicurezza del gemello guardano la stanza vera: il clima del soggiorno con la finestra del soggiorno aperta.</div><div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(210px,1fr));gap:8px">`;
    for (const r of g.stanze) {
      const icone = [r.luci ? `💡 ${r.luci_accese}/${r.luci}` : '', r.aperture.length ? `🪟 ${r.aperture.length} aperte` : '', r.presenza ? '🧍 qualcuno' : '', r.temperatura != null ? `🌡 ${r.temperatura}°` : '', r.media.length ? '🎵' : ''].filter(Boolean).join(' · ');
      h += `<div class="card" style="margin:0;padding:8px"><b>${esc(r.nome)}</b> <span class="mut">piano ${esc(r.piano)}</span><div style="font-size:13px;margin-top:4px">${icone || '<span class="mut">niente di legato</span>'}</div>` +
        (r.entita.length ? `<div class="mut" style="font-size:12px;margin-top:4px">${r.entita.map(e => esc(e.nome) + ': ' + esc(e.stato)).join('<br>')}</div>` : '') + '</div>';
    }
    h += '</div><div class="row" style="margin-top:10px;gap:6px"><button class="sec" id="cvProponi">✨ Proponi i legami</button><button class="sec" id="cvLegaMano">🔗 Lega a mano</button></div><div id="cvLegami"></div>';
    corpo.innerHTML = h;
    corpo.querySelector('#cvProponi').onclick = async () => {
      const box2 = corpo.querySelector('#cvLegami'); box2.innerHTML = '<div class="mut">Cerco nelle aree di Home Assistant e nei nomi…</div>';
      const p = (await api('api/casa/proposte_legami')).proposte;
      if (!p.length) { box2.innerHTML = '<div class="mut">Nessuna proposta: i dispositivi senza stanza non hanno un nome o un\'area che somigli a una stanza. Legali a mano.</div>'; return; }
      box2.innerHTML = '<div style="margin-top:8px">' + p.map((x, i) => `<label style="display:block;font-size:13px"><input type="checkbox" data-i="${i}" checked> ${esc(x.nome)} → <b>${esc(x.stanza_nome)}</b> <span class="mut">(${esc(x.perche)})</span></label>`).join('') + '<button id="cvSalvaLegami" style="margin-top:6px">Salva i legami scelti</button></div>';
      box2.querySelector('#cvSalvaLegami').onclick = async () => {
        const lista = [...box2.querySelectorAll('input:checked')].map(c => p[+c.dataset.i]).map(x => ({ entity_id: x.entity_id, stanza: x.stanza }));
        await post('api/casa/legami', { lista }); stanze();
      };
    };
    corpo.querySelector('#cvLegaMano').onclick = () => {
      const box2 = corpo.querySelector('#cvLegami');
      const opz = '<option value="">— nessuna —</option>' + l.stanze.map(s => `<option value="${esc(s.id)}">${esc(s.nome)} (piano ${esc(s.piano)})</option>`).join('');
      box2.innerHTML = `<div style="margin-top:8px"><input id="cvFiltro" placeholder="Cerca un dispositivo…" style="width:100%;margin-bottom:6px"><div id="cvElenco" style="max-height:320px;overflow:auto"></div></div>`;
      const elenco = box2.querySelector('#cvElenco');
      const disegna = q => {
        elenco.innerHTML = l.entita.filter(e => !q || (e.nome + ' ' + e.entity_id).toLowerCase().includes(q)).slice(0, 150).map(e =>
          `<div class="row" style="justify-content:space-between;font-size:13px;gap:6px"><span>${esc(e.nome)} <span class="mut">${esc(e.entity_id)}</span></span><select data-e="${esc(e.entity_id)}">${opz}</select></div>`).join('');
        elenco.querySelectorAll('select').forEach(s => { s.value = (l.legami[s.dataset.e] || {}).stanza || ''; s.onchange = () => post('api/casa/legami', { entity_id: s.dataset.e, stanza: s.value || null }).then(() => { if (s.value) l.legami[s.dataset.e] = { stanza: s.value }; else delete l.legami[s.dataset.e]; }); });
      };
      box2.querySelector('#cvFiltro').oninput = e => disegna(e.target.value.trim().toLowerCase());
      disegna('');
    };
  }

  // ---------------------------------------------------------------- scene
  async function scene() {
    const [s, l] = await Promise.all([api('api/casa/scene'), api('api/casa/legami')]);
    const nomeStanza = id => (l.stanze.find(x => x.id === id) || {}).nome || id;
    const righe = Object.entries(s.scene).map(([id, x]) => `<div class="row" style="justify-content:space-between;gap:6px"><label><input type="checkbox" class="cvFondi" value="${id}"> <b>${esc(x.nome)}</b> <span class="mut">${esc(nomeStanza(x.stanza))} · ${Object.keys(x.entita).length} dispositivi</span></label><span><button class="sec" data-prova="${id}">Prova</button> <button data-applica="${id}">Applica</button> <button class="sec" data-togli="${id}">✖</button></span></div>`).join('') || '<div class="mut">Nessuna scena: scegli una stanza e scatta la foto di come è adesso.</div>';
    const casa = Object.entries(s.scene_casa).map(([id, x]) => `<div class="row" style="justify-content:space-between;gap:6px"><span><b>🏡 ${esc(x.nome)}</b> <span class="mut">${x.scene.map(z => esc((s.scene[z] || {}).nome || '?')).join(' + ')}</span></span><span><button class="sec" data-prova="${id}">Prova</button> <button data-applica="${id}">Applica</button> <button class="sec" data-togli="${id}">✖</button></span></div>`).join('');
    corpo.innerHTML = `<div class="mut">Una scena è la fotografia dei dispositivi di UNA stanza (luci, tapparelle, clima, volumi). Più scene di stanze diverse si fondono in una scena di casa. «Prova» mostra cosa farebbe il gemello, senza toccare niente.</div>
      <div class="row" style="gap:6px;margin:8px 0"><select id="cvStanza">${l.stanze.map(x => `<option value="${esc(x.id)}">${esc(x.nome)}</option>`).join('')}</select><input id="cvNomeScena" placeholder="Nome (es. Relax)" maxlength="40"><button id="cvScatta">📸 Scatta com'è adesso</button></div>
      ${righe}<div class="row" style="gap:6px;margin-top:8px"><input id="cvNomeCasa" placeholder="Nome della scena di casa (es. Sera)" maxlength="40"><button class="sec" id="cvFondi">🔗 Fondi le scene spuntate</button></div>${casa}<div id="cvEsito"></div>`;
    corpo.querySelector('#cvScatta').onclick = async () => { const r = await post('api/casa/scene', { nome: corpo.querySelector('#cvNomeScena').value || 'Scena', stanza: corpo.querySelector('#cvStanza').value }); if (!r.entita) alert('In questa stanza non c\'è niente di legato da fotografare: legalo nella scheda Stanze.'); scene(); };
    corpo.querySelector('#cvFondi').onclick = async () => { const sc = [...corpo.querySelectorAll('.cvFondi:checked')].map(c => c.value); if (sc.length < 2) return alert('Spunta almeno due scene da fondere.'); await post('api/casa/scene_casa', { nome: corpo.querySelector('#cvNomeCasa').value || 'Scena di casa', scene: sc }); scene(); };
    corpo.querySelectorAll('[data-prova]').forEach(b => b.onclick = async () => { const r = await post(`api/casa/scene/${b.dataset.prova}/applica`, { solo_simula: true }); corpo.querySelector('#cvEsito').innerHTML = '<b>Simulazione</b>' + esiti(r.esiti); });
    corpo.querySelectorAll('[data-applica]').forEach(b => b.onclick = async () => {
      const sim = await post(`api/casa/scene/${b.dataset.applica}/applica`, { solo_simula: true });
      const bloccate = sim.esiti.filter(e => e.esito === 'bloccata').length;
      if (!confirm(`Applico la scena? ${sim.esiti.length} comandi${bloccate ? `, ${bloccate} bloccati dal gemello` : ''}.`)) return;
      const r = await post(`api/casa/scene/${b.dataset.applica}/applica`, { conferma: true }); corpo.querySelector('#cvEsito').innerHTML = '<b>Fatto</b>' + esiti(r.esiti);
    });
    corpo.querySelectorAll('[data-togli]').forEach(b => b.onclick = async () => { if (confirm('Tolgo la scena?')) { await api(`api/casa/scene/${b.dataset.togli}`, { method: 'DELETE' }); scene(); } });
  }

  // ---------------------------------------------------------------- abitudini
  async function abitudini() {
    corpo.innerHTML = '<div class="mut">Leggo il registro di Home Assistant delle ultime due settimane (cosa è stato fatto a mano, e quando)…</div>';
    const p = (await api('api/casa/abitudini?giorni=14')).proposte;
    if (!p.length) { corpo.innerHTML = '<div class="mut">Ancora nessuna abitudine chiara: servono almeno 5 giorni con la stessa cosa fatta a mano alla stessa ora (o 4 feriali, o 3 fine settimana). Riprova fra qualche giorno.</div>'; return; }
    corpo.innerHTML = '<div class="mut" style="margin-bottom:6px">Cose fatte a mano sempre alla stessa ora. «Crea» la mette in Home Assistant come automazione (solo se c\'è qualcuno in casa); «No» non la ripropone più.</div>' +
      p.map((x, i) => `<div class="row" style="justify-content:space-between;gap:6px;border-top:1px solid var(--bd);padding:6px 0"><span><b>${esc(x.entity_id)}</b> → ${esc(x.stato)} ${x.tramonto != null ? `al tramonto ${x.tramonto >= 0 ? '+' : ''}${x.tramonto} min` : 'alle ' + esc(x.ora)} ${esc(x.quando)} <span class="mut">(${x.giorni} giorni, ${Math.round(x.confidenza * 100)}%)</span></span><span><button data-crea="${i}">Crea</button> <button class="sec" data-no="${i}">No</button></span></div>`).join('');
    corpo.querySelectorAll('[data-crea]').forEach(b => b.onclick = async () => {
      const x = p[+b.dataset.crea];
      if (!confirm(`Creo in Home Assistant l'automazione: ${x.entity_id} → ${x.stato} ${x.tramonto != null ? 'al tramonto' : 'alle ' + x.ora} ${x.quando}?`)) return;
      try { const r = await post('api/casa/abitudini/crea', { proposta: x, conferma: true }); alert('Creata: ' + r.automazione); abitudini(); } catch (e) { alert(e.message); }
    });
    corpo.querySelectorAll('[data-no]').forEach(b => b.onclick = async () => { await post('api/casa/abitudini/scarta', { id: p[+b.dataset.no].id }); abitudini(); });
  }

  // ---------------------------------------------------------------- impostazioni di volume e allarme
  const scelta = (lista, scelti, filtro) => lista.filter(filtro).map(e => `<label style="display:block;font-size:13px"><input type="checkbox" value="${esc(e.entity_id)}" ${scelti.includes(e.entity_id) ? 'checked' : ''}> ${esc(e.nome)} <span class="mut">${esc(e.entity_id)}</span></label>`).join('') || '<div class="mut">nessuna</div>';
  const spuntati = sel => [...corpo.querySelectorAll(sel + ' input:checked')].map(c => c.value);

  // ---------------------------------------------------------------- presenza e casa vuota (0.31)
  // chi è in casa e in che stanza (persone di HA, tracker di stanza come Bermuda o ESPresense, ultimo movimento), la casa
  // in frasi, e gli avvisi quando la casa è vuota (porte, finestre o movimento: solo notifiche)
  async function presenza() {
    const [p, r, imp, servizi] = await Promise.all([api('api/casa/presenza'), api('api/casa/riepilogo'), api('api/casa/impostazioni'), api('api/casa/allarme').catch(() => ({}))]);
    const v = imp.vuota || {};
    const persone = p.persone || [];
    corpo.innerHTML = `<div style="font-size:15px;line-height:1.4;margin-bottom:10px">${esc(r.testo)}</div>
      <b>Persone</b>${persone.length ? '<div style="margin:6px 0 12px">' + persone.map(x => `<div style="font-size:14px">${x.in_casa ? '🏠' : '🚶'} <b>${esc(x.nome)}</b> — ${x.in_casa ? (x.stanza ? esc(x.stanza) + ' <span class="mut">(' + esc(x.perche) + ')</span>' : 'in casa, stanza non nota') : esc(x.stato)}</div>`).join('') + '</div>'
        : '<div class="mut" style="margin:6px 0 12px">Nessuna persona in Home Assistant (Impostazioni → Persone): senza, la presenza viene solo dai sensori di movimento.</div>'}
      <div class="mut">La stanza viene da un tracker di stanza (Bermuda, ESPresense, room-assistant: lo stato o l'attributo «area» uguale al nome di una stanza di Eclipse) oppure, se in casa c'è una persona sola, dall'ultimo movimento delle stanze.</div>
      <h3 style="margin-top:14px">Avvisi a casa vuota</h3>
      <div class="mut">Quando nessuna persona è in casa, una porta o finestra che si apre o un movimento in una stanza legata diventano una notifica (nella campanella di HA e nei servizi scelti). Non si tocca niente della casa.</div>
      <label style="display:block;margin:8px 0"><input type="checkbox" id="cvVuotaAttivo" ${v.attivo ? 'checked' : ''}> Attivo</label>
      <label style="display:block;margin:6px 0">Servizi di notifica (es. <code>notify.mobile_app_telefono</code>, separati da virgola) <input id="cvVuotaNot" style="width:100%" value="${esc((v.notifiche || []).join(', '))}"></label>
      <label style="display:block;margin:6px 0">Al più un avviso per sensore ogni <input type="number" id="cvVuotaPausa" min="1" max="240" value="${esc(v.pausa_minuti || 10)}" style="width:60px"> minuti</label>
      <button id="cvSalvaVuota">Salva</button> <span id="cvStatoVuota" class="mut"></span>`;
    corpo.querySelector('#cvSalvaVuota').onclick = async () => {
      const notifiche = corpo.querySelector('#cvVuotaNot').value.split(',').map(x => x.trim()).filter(Boolean);
      await post('api/casa/impostazioni', { vuota: { attivo: corpo.querySelector('#cvVuotaAttivo').checked, notifiche, pausa_minuti: Math.max(1, Math.min(240, +corpo.querySelector('#cvVuotaPausa').value || 10)) } });
      corpo.querySelector('#cvStatoVuota').textContent = 'Salvato.';
    };
  }

  async function volume() {
    const [imp, l] = await Promise.all([api('api/casa/impostazioni'), api('api/casa/legami')]);
    const v = imp.volume, media = e => /^(media_player|assist_satellite)\./.test(e.entity_id);
    corpo.innerHTML = `${imp.permessi.volume ? '' : '<div class="bad" style="margin-bottom:6px">Spento nelle opzioni dell\'add-on: accendi «volume_automatico» nella configurazione di Eclipse.</div>'}
      <div class="mut">Quando una delle VOCI parla (l'altoparlante degli annunci, il satellite dell'assistente), le CASSE scelte si abbassano; a voce finita tornano al volume di prima, tranne quelle che nel frattempo qualcuno ha toccato.</div>
      <label style="display:block;margin:8px 0"><input type="checkbox" id="cvVolAttivo" ${v.attivo ? 'checked' : ''}> Attivo</label>
      <div class="row" style="align-items:flex-start;gap:16px"><div style="flex:1"><b>Voci</b><div id="cvVoci" style="max-height:220px;overflow:auto">${scelta(l.entita, v.voci || [], media)}</div></div>
      <div style="flex:1"><b>Casse da abbassare</b><div id="cvCasse" style="max-height:220px;overflow:auto">${scelta(l.entita, v.casse || [], e => e.entity_id.startsWith('media_player.'))}</div></div></div>
      <label style="display:block;margin-top:8px">Abbassa al <input type="number" id="cvQuanto" min="0" max="90" value="${Math.round((v.quanto || 0.25) * 100)}" style="width:60px">% del volume</label>
      <button id="cvSalvaVol" style="margin-top:8px">Salva</button> <span id="cvStatoVol" class="mut"></span>`;
    corpo.querySelector('#cvSalvaVol').onclick = async () => {
      await post('api/casa/impostazioni', { volume: { attivo: corpo.querySelector('#cvVolAttivo').checked, voci: spuntati('#cvVoci'), casse: spuntati('#cvCasse'), quanto: Math.max(0, Math.min(0.9, +corpo.querySelector('#cvQuanto').value / 100)) } });
      corpo.querySelector('#cvStatoVol').textContent = 'Salvato.';
    };
  }

  async function allarme() {
    const [imp, l, stato] = await Promise.all([api('api/casa/impostazioni'), api('api/casa/legami'), api('api/casa/allarme')]);
    const a = imp.allarme, pericolo = e => e.entity_id.startsWith('binary_sensor.') && /^(smoke|gas|moisture|carbon_monoxide|safety|heat)$/.test(e.classe);
    corpo.innerHTML = `${imp.permessi.allarme ? '' : '<div class="bad" style="margin-bottom:6px">L\'allarme automatico è spento nelle opzioni dell\'add-on («allarme_automatico»): qui puoi prepararlo e provarlo.</div>'}
      ${stato.attivo ? `<div class="bad" style="margin-bottom:6px">🚨 ALLARME IN CORSO: ${esc(stato.allarme.frase)} <button id="cvFerma">Ferma e rimetti le luci</button></div>` : ''}
      <div class="mut">Se un sensore scelto segnala fumo, gas, acqua o monossido: le luci scelte (o tutte) lampeggiano lente di rosso, la frase con la STANZA del sensore si ripete ogni 25 s sulle casse, partono le notifiche. Dopo il tempo massimo, o con «Ferma», le luci tornano esattamente com'erano.</div>
      <label style="display:block;margin:8px 0"><input type="checkbox" id="cvAllAttivo" ${a.attivo ? 'checked' : ''}> Attivo</label>
      <div class="row" style="align-items:flex-start;gap:16px;flex-wrap:wrap"><div style="flex:1;min-width:220px"><b>Sensori</b><div id="cvSens" style="max-height:200px;overflow:auto">${scelta(l.entita, a.sensori || [], pericolo)}</div></div>
      <div style="flex:1;min-width:220px"><b>Luci</b> <span class="mut">(nessuna = tutte)</span><div id="cvLuci" style="max-height:200px;overflow:auto">${scelta(l.entita, a.luci || [], e => e.entity_id.startsWith('light.'))}</div></div>
      <div style="flex:1;min-width:220px"><b>Casse per l'annuncio</b><div id="cvCasseA" style="max-height:200px;overflow:auto">${scelta(l.entita, a.casse || [], e => e.entity_id.startsWith('media_player.'))}</div></div></div>
      <div class="row" style="gap:8px;margin-top:8px;flex-wrap:wrap"><label>Voce (entità tts) <input id="cvTts" value="${esc(a.tts)}" placeholder="tts.google_translate_it" style="width:200px"></label>
      <label>Notifiche <input id="cvNotif" value="${esc((a.notifiche || []).join(', '))}" placeholder="notify.mobile_app_telefono" style="width:240px"></label>
      <label>Durata massima <input type="number" id="cvSec" min="20" max="900" value="${a.secondi || 120}" style="width:70px"> s</label></div>
      <div class="row" style="gap:6px;margin-top:8px"><button id="cvSalvaAll">Salva</button><button class="sec" id="cvProva">🧪 Prova a secco</button><select id="cvLuceProva"><option value="">luce per la prova…</option>${l.entita.filter(e => e.entity_id.startsWith('light.')).map(e => `<option value="${esc(e.entity_id)}">${esc(e.nome)}</option>`).join('')}</select><button class="sec" id="cvProvaLuce">💡 Prova su una luce (6 s)</button></div>
      <div id="cvEsitoAll" style="margin-top:8px"></div>
      <details style="margin-top:8px"><summary class="mut">Registro</summary><div class="mut" style="font-size:12px">${(stato.registro || []).map(esc).join('<br>') || 'vuoto'}</div></details>`;
    const f = corpo.querySelector('#cvFerma'); if (f) f.onclick = async () => { await post('api/casa/allarme/ferma'); allarme(); };
    corpo.querySelector('#cvSalvaAll').onclick = async () => {
      await post('api/casa/impostazioni', { allarme: { attivo: corpo.querySelector('#cvAllAttivo').checked, sensori: spuntati('#cvSens'), luci: spuntati('#cvLuci'), casse: spuntati('#cvCasseA'), tts: corpo.querySelector('#cvTts').value.trim(), notifiche: corpo.querySelector('#cvNotif').value.split(',').map(s => s.trim()).filter(Boolean), secondi: +corpo.querySelector('#cvSec').value || 120 } });
      corpo.querySelector('#cvEsitoAll').textContent = 'Salvato.';
    };
    corpo.querySelector('#cvProva').onclick = async () => {
      const r = await post('api/casa/allarme/prova', { sensore: spuntati('#cvSens')[0] });
      corpo.querySelector('#cvEsitoAll').innerHTML = `<b>Farebbe così</b> (non è stato toccato niente):<br>annuncio «${esc(r.frase)}»${r.casse && r.casse.length ? ' su ' + r.casse.map(esc).join(', ') : ' <span class="bad">nessuna cassa scelta</span>'}<br>${r.luci.length} luci rosse lampeggianti<br>notifiche: ${(r.notifiche || []).map(esc).join(', ') || 'nessuna'}`;
    };
    corpo.querySelector('#cvProvaLuce').onclick = async () => {
      const e = corpo.querySelector('#cvLuceProva').value; if (!e) return alert('Scegli una luce.');
      if (!confirm('Faccio lampeggiare di rosso ' + e + ' per 6 secondi, poi torna com\'era?')) return;
      await post('api/casa/allarme/prova_luce', { entity_id: e, conferma: true }); corpo.querySelector('#cvEsitoAll').textContent = 'Luce in prova: torna com\'era fra 6 secondi.';
    };
  }

  schede(); apri();
})();
