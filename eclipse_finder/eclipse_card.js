// LA CARD «CASA VIVA» PER LE DASHBOARD DI HOME ASSISTANT (Eclipse Finder 0.31, 13 ottobre 2026). Licenza MIT.
// Alfonso: «Casa viva deve essere una card che si mette in dashboard». Scritta da zero, senza librerie.
//
//   type: custom:eclipse-casa-viva-card
//   titolo: Casa viva          (facoltativo)
//   avatar: true               (il proprio avatar del gioco, che vive, in un riquadro; false = niente)
//   addon: local_eclipse_finder (lo slug dell'add-on, di serie quello locale)
//
// COME PARLA CON L'ADD-ON. La card sta nel frontend di HA, che è già autenticato. Da lì chiede a HA (websocket
// «supervisor/api», solo per gli amministratori) l'indirizzo d'ingresso dell'add-on e una sessione d'ingresso, come fa
// il pannello laterale dell'add-on; poi parla con l'add-on attraverso l'ingress (/api/hassio_ingress/…). Niente
// gettoni nel browser, niente porte aperte: se l'utente non è amministratore la card lo dice e non mostra niente.
// SICUREZZA. Ogni comando si SIMULA prima (il gemello di Casa viva: le regole della casa) e si esegue solo dopo una
// conferma esplicita nella card, che mostra l'esito della simulazione.

const STILE = `
:host{display:block}
ha-card{overflow:hidden;padding:14px 14px 10px;background:var(--ha-card-background,var(--card-background-color));
  backdrop-filter:blur(10px)}
.testa{display:flex;align-items:center;gap:10px;margin-bottom:6px}
.testa h2{margin:0;font-size:1.15rem;font-weight:600;flex:1}
.testa a{font-size:.8rem;color:var(--primary-color);text-decoration:none}
.riep{font-size:.9rem;color:var(--secondary-text-color);margin:4px 0 10px;line-height:1.35}
.allarme{background:#b3261e;color:#fff;border-radius:12px;padding:10px 12px;margin-bottom:10px;display:flex;gap:10px;align-items:center;animation:pulsa 1.6s ease-in-out infinite}
@keyframes pulsa{50%{filter:brightness(1.25)}}
.allarme span{flex:1;font-weight:600}
.persone{display:flex;gap:6px;flex-wrap:wrap;margin-bottom:10px}
.chip{border-radius:999px;padding:4px 10px;font-size:.8rem;background:rgba(127,127,127,.15);border:1px solid rgba(127,127,127,.25)}
.chip.casa{background:rgba(63,185,80,.18);border-color:rgba(63,185,80,.45)}
.stanze{display:grid;grid-template-columns:repeat(auto-fill,minmax(150px,1fr));gap:8px}
.stanza{border-radius:14px;padding:10px;background:rgba(127,127,127,.10);border:1px solid rgba(127,127,127,.22);
  display:flex;flex-direction:column;gap:6px;min-height:92px;position:relative}
.stanza.luce{background:linear-gradient(160deg,rgba(255,196,64,.28),rgba(127,127,127,.08));border-color:rgba(255,196,64,.5)}
.stanza .nome{font-weight:600;font-size:.92rem;padding-right:18px}
.stanza .info{font-size:.78rem;color:var(--secondary-text-color);display:flex;gap:8px;flex-wrap:wrap}
.stanza .qui{position:absolute;right:9px;top:10px;width:9px;height:9px;border-radius:50%;background:#3fb950;box-shadow:0 0 8px #3fb950}
.stanza .aperta{color:#d29922}
button{font:inherit;font-size:.78rem;border-radius:10px;border:1px solid rgba(127,127,127,.35);background:rgba(127,127,127,.12);
  color:var(--primary-text-color);padding:5px 9px;cursor:pointer}
button.pieno{background:var(--primary-color);color:var(--text-primary-color,#fff);border-color:transparent}
button:disabled{opacity:.5;cursor:default}
.riga{display:flex;gap:6px;flex-wrap:wrap;align-items:center}
h3{font-size:.85rem;margin:12px 0 6px;color:var(--secondary-text-color);font-weight:600;text-transform:uppercase;letter-spacing:.04em}
.auto{display:flex;gap:6px;flex-wrap:wrap}
.auto .chip.on{background:rgba(88,166,255,.18);border-color:rgba(88,166,255,.5)}
.avatar{margin-top:10px;border-radius:14px;overflow:hidden;border:1px solid rgba(127,127,127,.22);height:360px;background:#0d1117}
.avatar iframe{border:0;width:100%;height:100%}
.conferma{position:absolute;inset:0;background:rgba(0,0,0,.55);display:flex;align-items:center;justify-content:center;z-index:5}
.conferma>div{background:var(--card-background-color,#1c1c1c);border-radius:16px;padding:16px;max-width:92%;width:420px;box-shadow:0 10px 40px rgba(0,0,0,.5)}
.conferma ul{padding-left:18px;margin:8px 0;font-size:.85rem}
.ok{color:#3fb950}.avv{color:#d29922}.blocco{color:#f85149}
.err{color:var(--error-color,#f85149);font-size:.85rem}
.mut{color:var(--secondary-text-color);font-size:.8rem}
`;

const esc = t => String(t == null ? '' : t).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));

class EclipseCasaVivaCard extends HTMLElement {
    static getStubConfig() { return { titolo: 'Casa viva', avatar: true }; }
    setConfig(c) {
        this._c = Object.assign({ titolo: 'Casa viva', avatar: true, addon: 'local_eclipse_finder', aggiorna: 10 }, c || {});
        if (!this.shadowRoot) this.attachShadow({ mode: 'open' });
        this._disegnaVuota();
    }
    getCardSize() { return this._c && this._c.avatar ? 10 : 6; }
    set hass(h) {
        this._hass = h;
        if (!this._partita) { this._partita = true; this._avvia(); }
    }
    disconnectedCallback() { clearInterval(this._giro); clearInterval(this._giroSessione); this._partita = false; }

    _disegnaVuota(msg = 'Collego Casa viva…') {
        this.shadowRoot.innerHTML = `<style>${STILE}</style><ha-card><div class="testa"><h2>${esc(this._c.titolo)}</h2></div><div class="mut">${esc(msg)}</div></ha-card>`;
    }

    // l'ingresso dell'add-on: indirizzo e sessione dal Supervisor, attraverso il websocket del frontend
    async _ingresso() {
        const info = await this._hass.callWS({ type: 'supervisor/api', endpoint: `/addons/${this._c.addon}/info`, method: 'get' });
        if (!info || !info.ingress_url) throw new Error("l'add-on non ha l'ingresso");
        this._base = info.ingress_url.endsWith('/') ? info.ingress_url : info.ingress_url + '/';
        this._pannello = '/hassio/ingress/' + this._c.addon;
        await this._sessione();
    }
    async _sessione() {
        const r = await this._hass.callWS({ type: 'supervisor/api', endpoint: '/ingress/session', method: 'post' });
        this._ses = r.session;
        document.cookie = `ingress_session=${r.session};path=/api/hassio_ingress/;SameSite=Strict${location.protocol === 'https:' ? ';Secure' : ''}`;
    }
    async _api(percorso, corpo) {
        const r = await fetch(this._base + percorso, corpo === undefined ? { credentials: 'same-origin' }
            : { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(corpo) });
        if ((r.status === 401 || r.status === 403) && !this._riprovato) { this._riprovato = true; await this._sessione(); return this._api(percorso, corpo); }
        this._riprovato = false;
        const j = await r.json().catch(() => ({}));
        if (!r.ok) throw new Error(j.detail || j.errore || ('errore ' + r.status));
        return j;
    }

    async _avvia() {
        try { await this._ingresso(); }
        catch (e) {
            this._disegnaVuota(/admin|unauthor|permesso|403/i.test(String(e && e.message)) ? 'Casa viva è solo per gli amministratori di Home Assistant.' : 'Casa viva non risponde: ' + (e && e.message || e));
            this._partita = false; return;
        }
        // la sessione d'ingresso scade: si rinnova come fa il pannello (ogni 60 s si valida, se non vale se ne chiede una nuova)
        this._giroSessione = setInterval(async () => {
            try { await this._hass.callWS({ type: 'supervisor/api', endpoint: '/ingress/validate_session', method: 'post', data: { session: this._ses } }); }
            catch { try { await this._sessione(); } catch { /* riprova al prossimo giro */ } }
        }, 60000);
        await this._carica();
        this._giro = setInterval(() => this._carica(), Math.max(5, +this._c.aggiorna || 10) * 1000);
    }

    async _carica() {
        if (this._inDialogo) return;                     // con la conferma aperta la card non si ridisegna (la perderebbe)
        try { this._dati = await this._api('api/casa/card'); this._errore = null; }
        catch (e) { this._errore = e.message || String(e); }
        this._disegna();
    }

    _disegna() {
        const d = this._dati, root = this.shadowRoot;
        if (!d) { this._disegnaVuota(this._errore ? 'Casa viva: ' + this._errore : 'Carico…'); return; }
        const persone = (d.presenza && d.presenza.persone) || [];
        const dove = {};
        for (const p of persone) if (p.in_casa && p.stanza_id) (dove[p.stanza_id] = dove[p.stanza_id] || []).push(p.nome);
        const stanze = (d.stanze || []).filter(r => r.entita && r.entita.length);
        const avatar = this._c.avatar !== false;
        // l'iframe dell'avatar si crea una volta sola (ridisegnando la card non deve ricaricarsi)
        const vecchioAvatar = root.querySelector('.avatar');
        root.innerHTML = `<style>${STILE}</style><ha-card>
          <div class="testa"><h2>${esc(this._c.titolo)}</h2><a href="${esc(this._pannello)}">Apri Eclipse ›</a></div>
          ${d.allarme ? `<div class="allarme"><span>🚨 ${esc(d.allarme.frase || d.allarme.cosa)}${d.allarme.prova ? ' (prova)' : ''}</span><button class="pieno" data-az="ferma">Ferma</button></div>` : ''}
          <div class="riep">${esc((d.riepilogo && d.riepilogo.testo) || '')}</div>
          ${persone.length ? `<div class="persone">${persone.map(p => `<span class="chip ${p.in_casa ? 'casa' : ''}" title="${esc(p.perche || p.stato)}">${p.in_casa ? '🏠' : '🚶'} ${esc(p.nome)}${p.stanza ? ' · ' + esc(p.stanza) : p.in_casa ? '' : ' · fuori'}</span>`).join('')}</div>` : ''}
          ${this._errore ? `<div class="err">${esc(this._errore)}</div>` : ''}
          <div class="stanze">${stanze.map(r => {
              const luci = r.entita.filter(e => /^light\./.test(e.entity_id) || (/^switch\./.test(e.entity_id) && /lam.?pad|lamp|luc|light|abat/i.test(e.nome || '')));
              return `<div class="stanza ${r.luci_accese ? 'luce' : ''}">
                ${(r.presenza || dove[r.id]) ? '<span class="qui" title="qualcuno qui"></span>' : ''}
                <div class="nome">${esc(r.nome)}</div>
                <div class="info">${r.temperatura != null ? `<span>🌡 ${esc(r.temperatura)}°</span>` : ''}${r.luci ? `<span>💡 ${r.luci_accese}/${r.luci}</span>` : ''}${(dove[r.id] || []).length ? `<span>👤 ${esc(dove[r.id].join(', '))}</span>` : ''}${r.media && r.media.length ? '<span>🎵</span>' : ''}</div>
                ${r.aperture && r.aperture.length ? `<div class="info aperta">🪟 ${esc(r.aperture.join(', '))}</div>` : ''}
                ${luci.length ? `<div class="riga"><button data-az="luci" data-stanza="${esc(r.id)}" data-on="${r.luci_accese ? 0 : 1}">${r.luci_accese ? 'Spegni le luci' : 'Accendi le luci'}</button></div>` : ''}
              </div>`; }).join('') || '<div class="mut">Nessuna entità legata alle stanze: si legano in Eclipse → Casa viva → Stanze.</div>'}</div>
          ${(d.scene || []).length || (d.scene_casa || []).length ? `<h3>Scene</h3><div class="riga">${(d.scene_casa || []).map(s => `<button data-az="scena" data-id="${esc(s.id)}">🏠 ${esc(s.nome)}</button>`).join('')}${(d.scene || []).map(s => `<button data-az="scena" data-id="${esc(s.id)}">${esc(s.nome)}</button>`).join('')}</div>` : ''}
          <h3>Automatismi</h3><div class="auto">
            <span class="chip ${d.automatismi && d.automatismi.volume ? 'on' : ''}">🔉 volume durante le voci</span>
            <span class="chip ${d.automatismi && d.automatismi.allarme ? 'on' : ''}">🚨 allarme rosso</span>
            <span class="chip ${d.automatismi && d.automatismi.vuota ? 'on' : ''}">🏠 avvisi a casa vuota</span>
          </div>
          ${avatar ? '<div class="avatar"></div>' : ''}
        </ha-card>`;
        if (avatar) {
            const box = root.querySelector('.avatar');
            if (vecchioAvatar && vecchioAvatar.firstChild) box.replaceWith(vecchioAvatar);
            else { const f = document.createElement('iframe'); f.src = this._base + 'ritratto'; f.allow = 'microphone; autoplay'; f.title = 'Il mio avatar'; box.appendChild(f); }
        }
        root.querySelectorAll('button[data-az]').forEach(b => b.addEventListener('click', () => this._azione(b)));
    }

    // un comando: prima la simulazione, poi la conferma, poi l'esecuzione
    async _azione(b) {
        const az = b.dataset.az, d = this._dati;
        try {
            if (az === 'ferma') { if (await this._chiedi('Fermare l’allarme? Le luci tornano come prima.', [])) { await this._api('api/casa/allarme/ferma', { conferma: true }); this._carica(); } return; }
            if (az === 'luci') {
                const r = d.stanze.find(x => x.id === b.dataset.stanza); if (!r) return;
                const on = b.dataset.on === '1';
                const azioni = r.entita.filter(e => /^light\./.test(e.entity_id) || (/^switch\./.test(e.entity_id) && /lam.?pad|lamp|luc|light|abat/i.test(e.nome || '')))
                    .map(e => ({ entity_id: e.entity_id, servizio: e.entity_id.split('.')[0] + (on ? '.turn_on' : '.turn_off'), dati: {} }));
                const sim = await this._api('api/casa/simula', { azioni });
                if (await this._chiedi(`${on ? 'Accendere' : 'Spegnere'} le luci di ${r.nome}?`, sim.esiti)) { await this._api('api/casa/esegui', { azioni, conferma: true }); this._carica(); }
                return;
            }
            if (az === 'scena') {
                const sim = await this._api(`api/casa/scene/${b.dataset.id}/applica`, { solo_simula: true });
                if (await this._chiedi('Applicare la scena?', sim.esiti)) { await this._api(`api/casa/scene/${b.dataset.id}/applica`, { conferma: true }); this._carica(); }
            }
        } catch (e) { this._errore = e.message || String(e); this._disegna(); }
    }
    _chiedi(testo, esiti) {
        this._inDialogo = true;
        return new Promise(ok0 => {
            const ok = v => { this._inDialogo = false; ok0(v); };
            const card = this.shadowRoot.querySelector('ha-card'); card.style.position = 'relative';
            const v = document.createElement('div'); v.className = 'conferma';
            const blocchi = (esiti || []).filter(e => e.esito === 'bloccata').length;
            v.innerHTML = `<div><b>${esc(testo)}</b>${(esiti || []).length ? `<ul>${esiti.map(e => `<li class="${e.esito === 'ok' ? 'ok' : e.esito === 'avviso' ? 'avv' : 'blocco'}">${esc(e.entity_id)}: ${esc(e.esito)}${e.perche ? ' — ' + esc(e.perche) : ''}</li>`).join('')}</ul>` : ''}
              ${blocchi ? `<div class="mut">${blocchi} ${blocchi === 1 ? 'azione resta ferma' : 'azioni restano ferme'}: le regole di Casa viva le bloccano.</div>` : ''}
              <div class="riga" style="justify-content:flex-end;margin-top:10px"><button data-r="0">Annulla</button><button class="pieno" data-r="1">Conferma</button></div></div>`;
            v.querySelectorAll('button').forEach(x => x.addEventListener('click', () => { v.remove(); ok(x.dataset.r === '1'); }));
            card.appendChild(v);
        });
    }
}

if (!customElements.get('eclipse-casa-viva-card')) customElements.define('eclipse-casa-viva-card', EclipseCasaVivaCard);
window.customCards = window.customCards || [];
if (!window.customCards.some(c => c.type === 'eclipse-casa-viva-card'))
    window.customCards.push({ type: 'eclipse-casa-viva-card', name: 'Eclipse · Casa viva', description: 'Le stanze vive della casa, le persone, le scene e il tuo avatar (add-on Eclipse Finder).', preview: false });
