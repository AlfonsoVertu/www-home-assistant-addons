// IL MIO AVATAR: VEDERLO E PARLARCI (Eclipse Finder 0.28, 13 ottobre 2026). Licenza MIT.
//
// Il personaggio del gioco (corpo Nuian, viso, capelli, colori, costume: dal portale come nella casa 3D) inquadrato
// da vicino, con una luce da ritratto, VIVO: l'attesa vera del gioco, le attese fatte a mano del gioco (braccia conserte,
// si guarda intorno...: gioco/gesti.js) quando nessuno parla, e lo sguardo verso chi gli parla (la telecamera) quando
// si scrive, si parla al microfono o risponde lui. Le risposte arrivano dall'agente di conversazione di Home Assistant
// (api/avatar/parla); la voce dal TTS di HA (api/avatar/voce), suonata qui con WebAudio: l'ampiezza dell'audio apre la
// BOCCA. Il Nuian non ha l'osso della mascella né forme del viso: la bocca è una mascella virtuale nello shader del
// viso (i vertici davanti e sotto la linea della bocca scendono di quanto è aperta). Mentre parla, i GESTI: una posa di
// conversazione di ArcheAge (quelle degli NPC del gioco; all'apertura il saluto), se no gesti di spiegazione con le
// braccia fatti a mano. Senza TTS: la sintesi vocale del browser, con la bocca a ritmo finto.
import * as THREE from 'three';
import { OrbitControls } from 'three/addons/controls/OrbitControls.js';

const esc = t => String(t == null ? '' : t).replace(/[&<>"]/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));

// ---------------------------------------------------------------- la mascella virtuale
// Il viso è in metri, Y in su, il davanti verso +Z (come lo scheletro). La linea della bocca sta al 30% dell'altezza
// della testa; sotto di lei, e solo nella metà davanti, i vertici pesano di più quanto più sono davanti e in basso
function preparaMascella(n, apertura) {
    const facce = [];
    n.radice.traverse(o => {
        if (!o.isSkinnedMesh || !o.visible || (n.capelli || []).includes(o)) return;
        const nome = ((o.name || '') + ' ' + ((o.parent && o.parent.name) || '')).toLowerCase();
        const dellaTesta = (n.testa || []).includes(o) || /_face\d/.test(nome);
        if (!dellaTesta || /hair/.test(nome)) return;
        facce.push(o);
    });
    for (const m of facce) {
        const g = m.geometry, p = g.attributes.position; if (!p || g.attributes.pesoMascella) continue;
        g.computeBoundingBox(); const b = g.boundingBox, h = b.max.y - b.min.y;
        if (h < 0.1 || h > 0.4) continue;                       // non è una testa
        const bocca = b.min.y + 0.30 * h, cz = (b.min.z + b.max.z) / 2, dz = Math.max(0.01, b.max.z - cz);
        const peso = new Float32Array(p.count);
        for (let i = 0; i < p.count; i++) {
            const y = p.getY(i), z = p.getZ(i);
            const sotto = Math.min(1, Math.max(0, (bocca - y) / (0.05 * h)));
            const davanti = Math.min(1, Math.max(0, (z - cz) / (0.35 * dz)));
            peso[i] = sotto * davanti;
        }
        g.setAttribute('pesoMascella', new THREE.BufferAttribute(peso, 1));
        const mats = Array.isArray(m.material) ? m.material : [m.material];
        for (const mat of mats) {
            mat.onBeforeCompile = sh => {
                sh.uniforms.uApertura = apertura;
                sh.vertexShader = 'attribute float pesoMascella;\nuniform float uApertura;\n' + sh.vertexShader.replace('#include <begin_vertex>',
                    '#include <begin_vertex>\n  transformed.y -= uApertura * 0.024 * pesoMascella;\n  transformed.z -= uApertura * 0.007 * pesoMascella;');
            };
            mat.customProgramCacheKey = () => 'mascella';
            mat.needsUpdate = true;
        }
    }
    return facce.length;
}

// LE POSE DI CONVERSAZIONE DI ArcheAge (le stesse degli NPC del gioco: animazioni/<corpo>/parlare.glb, attrezzo
// «parlare»): mentre risponde, una posa in piedi a caso fra queste; all'apertura il saluto
const POSE_IN_PIEDI = ['parlare_pensa', 'parlare_chiama', 'parlare_generale', 'parlare_braccia_conserte', 'parlare_mani_dietro', 'parlare_cerca'];

// gira un osso attorno a un asse del mondo (lo sguardo)
function giraNelMondo(osso, asse, angolo) {
    if (!osso || !osso.parent || !angolo) return;
    const qp = osso.parent.getWorldQuaternion(new THREE.Quaternion()), qw = osso.getWorldQuaternion(new THREE.Quaternion());
    qw.premultiply(new THREE.Quaternion().setFromAxisAngle(asse, angolo));
    osso.quaternion.copy(qp.invert().multiply(qw));
    osso.updateMatrixWorld(true);
}

export async function apriRitratto(corpo) {
    corpo.innerHTML = `<div style="display:grid;grid-template-columns:minmax(260px,1.2fr) minmax(240px,1fr);gap:10px" id="rtGriglia">
      <div style="position:relative"><div id="rtScena" style="height:62vh;min-height:360px;border-radius:12px;overflow:hidden;background:radial-gradient(circle at 50% 35%,#24324a,#0b0f14 70%)"></div>
        <div class="row" style="position:absolute;left:8px;bottom:8px;gap:6px"><button class="sec" id="rtBusto">Mezzo busto</button><button class="sec" id="rtIntera">Figura intera</button></div></div>
      <div style="display:flex;flex-direction:column;min-height:360px;height:62vh">
        <div id="rtGioco" style="padding:8px;border-radius:12px;background:rgba(255,255,255,.06);margin-bottom:6px;font-size:13px">
          <div class="row" style="gap:6px;align-items:center;flex-wrap:wrap"><span id="rtGiocoStato" class="mut">Il personaggio in gioco…</span>
            <a id="rtApriGioco" class="sec" target="_blank" rel="noopener" style="margin-left:auto">🎮 Apri il gioco</a><button class="sec" id="rtEsciGioco" title="Scollega l'account del gioco da Eclipse (per entrare con un altro)">⏻ Esci</button></div>
          <div class="row" style="gap:6px;align-items:center;flex-wrap:wrap;margin-top:6px">
            <button class="sec" id="rtRobotSi">🤖 Accendi il robot</button><button class="sec" id="rtRobotNo">Spegni</button>
            <label style="display:flex;gap:4px;align-items:center"><input type="checkbox" id="rtAlRobot"> gli ordini della chat al robot</label></div></div>
        <div id="rtChat" style="flex:1;overflow:auto;padding:6px;border-radius:12px;background:rgba(255,255,255,.04);font-size:14px"></div>
        <div class="row" style="gap:6px;margin-top:6px"><input id="rtTesto" placeholder="Scrivi al tuo avatar…" style="flex:1" maxlength="500"><button id="rtMic" class="sec" title="Parla al microfono">🎤</button><button id="rtInvia">Invia</button></div>
        <div class="mut" id="rtStato" style="font-size:12px;margin-top:4px"></div>
        <details id="rtCostume" style="margin-top:6px;padding:8px;border-radius:12px;background:rgba(255,255,255,.06);font-size:13px">
          <summary>🎭 Il mio avatar come costume</summary>
          <div class="mut" style="margin:6px 0">Un tuo avatar (.glb o .vrm con lo scheletro, tipo Mixamo o VRM; al massimo 15 MB e 60.000 triangoli) diventa un costume nel gioco. Lo guarda prima un amministratore: niente nudo esplicito. Approvato, lo compri dal Costume Tailor per 30.000 Cadde.</div>
          <div class="row" style="gap:6px;align-items:center;flex-wrap:wrap"><input type="file" id="rtCosFile" accept=".glb,.vrm"><button id="rtCosManda" disabled>Porta nel gioco</button></div>
          <canvas id="rtCosVista" style="width:100%;height:220px;margin-top:6px;border-radius:10px;background:#0b0f14;display:none"></canvas>
          <div class="mut" id="rtCosStato" style="margin-top:4px"></div>
        </details></div></div>`;
    if (innerWidth < 760) corpo.querySelector('#rtGriglia').style.gridTemplateColumns = '1fr';
    const scatola = corpo.querySelector('#rtScena'), chat = corpo.querySelector('#rtChat'), stato = corpo.querySelector('#rtStato');
    const scrivi = (chi, t) => { const d = document.createElement('div'); d.style.margin = '6px 0'; d.innerHTML = chi === 'tu' ? `<b>Tu</b>: ${esc(t)}` : `<b style="color:var(--acc)">${esc(chi)}</b>: ${esc(t)}`; chat.appendChild(d); chat.scrollTop = chat.scrollHeight; };

    // il personaggio dal gioco
    let r = await fetch('api/gioco/avatar');
    if (r.status === 401) {
        // non si è ancora entrati col gioco: il modulo qui, come nella casa 3D (la password va al portale, non resta qui)
        scatola.innerHTML = `<form id="rtLogin" style="padding:24px;max-width:300px;margin:auto"><div style="margin-bottom:8px">Entra con l'account del gioco per vedere il tuo personaggio.</div>
          <input id="rtNome" placeholder="Nome nel gioco" autocomplete="username" style="width:100%;margin-bottom:6px"><input id="rtPw" type="password" placeholder="Password" autocomplete="current-password" style="width:100%;margin-bottom:6px">
          <button>Entra</button><div class="bad" id="rtErr" style="margin-top:6px"></div></form>`;
        await new Promise(ok => { scatola.querySelector('#rtLogin').onsubmit = async e => {
            e.preventDefault();
            const pw = scatola.querySelector('#rtPw'), j = await fetch('api/gioco/accesso', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ nome: scatola.querySelector('#rtNome').value.trim(), password: pw.value }) }).then(x => x.json()).catch(() => ({ ok: false, errore: 'Eclipse non risponde.' }));
            pw.value = '';
            if (j.ok) ok(); else scatola.querySelector('#rtErr').textContent = j.errore || 'Accesso non riuscito.';
        }; });
        r = await fetch('api/gioco/avatar');
    }
    if (!r.ok) { scatola.innerHTML = '<div class="bad" style="padding:20px">Il portale del gioco non mi dà il tuo personaggio (' + r.status + ').</div>'; return () => {}; }
    const io = await r.json();
    stato.textContent = 'Carico il tuo personaggio…';
    const [P, M] = await Promise.all([import('./gioco/personaggioAA.js'), import('./gioco/mocap.js')]);
    let G = null, N = null;
    try { [G, N] = await Promise.all([import('./gioco/gesti.js'), import('./gioco/nuoto.js')]); } catch (e) { /* senza attese: solo l'attesa base */ }
    const n = await P.caricaNuian(io.corpo === 'aa:donna' ? 'donna' : 'uomo');
    const clip = await n.clip;
    const andatura = new M.Andatura(n.mixer, clip), lettore = new M.Lettore(n.mixer, clip);
    try { await P.testaPer(n, io.nome, io.scelta || null); } catch (e) { /* viso di base */ }
    let conCostume = false;
    try { const c = io.scelta && io.scelta.costume ? await P.pezziCostume(n.nome, io.scelta.costume) : null; if (c) { conCostume = true; await P.vesti(n, c.file); P.conElmo(n, c.elmo); } } catch (e) { /* senza costume */ }
    // (0.31) le armi e l'armatura del gioco (gioco/equip_avatar.js): l'ultima arma usata sulla schiena, skin e aura del grado
    try { const E = await import('./gioco/equip_avatar.js'); await E.vestiEquip(n, io.scelta || null, { costume: conCostume }); } catch (e) { /* senza armi */ }
    const apertura = { value: 0 };
    const facce = preparaMascella(n, apertura);
    let pose = [];
    let clipParla = null;
    const lettore2 = { suonaPosa() { if (!pose.length) return; clipParla = pose[Math.floor(Math.random() * pose.length)]; lettore.suona(clipParla, { ciclo: true, entra: 0.4, esce: 0.5 }); scatola.dataset.posa = clipParla; } };
    P.clipAttrezzo(n, 'parlare').then(voci => {
        if (!voci) return;
        pose = POSE_IN_PIEDI.filter(k => voci[k]);
        if (voci.parlare_emote_saluto) lettore.suona('parlare_emote_saluto', { entra: 0.2, esce: 0.3 });
    }).catch(() => { /* senza le pose del gioco: i gesti fatti a mano */ });

    // la scena: luce da ritratto (chiave calda di lato, controluce freddo, riempimento dal cielo), un disco a terra
    const W = scatola.clientWidth, H = scatola.clientHeight;
    const renderer = new THREE.WebGLRenderer({ antialias: true, alpha: true }); renderer.setPixelRatio(Math.min(devicePixelRatio, 2)); renderer.setSize(W, H);
    renderer.outputColorSpace = THREE.SRGBColorSpace; renderer.toneMapping = THREE.ACESFilmicToneMapping; renderer.toneMappingExposure = 1.05; renderer.shadowMap.enabled = true;
    scatola.innerHTML = ''; scatola.appendChild(renderer.domElement);
    const scena = new THREE.Scene();
    scena.add(new THREE.HemisphereLight(0xbfd4ff, 0x2a2018, 0.9));
    const chiave = new THREE.DirectionalLight(0xffe2c4, 2.6); chiave.position.set(1.4, 2.4, 2.2); chiave.castShadow = true; scena.add(chiave);
    const contro = new THREE.DirectionalLight(0x8fb8ff, 1.8); contro.position.set(-1.6, 2.2, -2); scena.add(contro);
    const terra = new THREE.Mesh(new THREE.CircleGeometry(1.1, 48), new THREE.MeshStandardMaterial({ color: 0x1a2230, roughness: 0.9 }));
    terra.rotation.x = -Math.PI / 2; terra.receiveShadow = true; scena.add(terra);
    n.radice.traverse(o => { if (o.isMesh) { o.castShadow = true; o.frustumCulled = false; } });
    scena.add(n.radice);
    const camera = new THREE.PerspectiveCamera(30, W / H, 0.05, 50);
    const controlli = new OrbitControls(camera, renderer.domElement);
    controlli.enablePan = false; controlli.minDistance = 0.7; controlli.maxDistance = 6; controlli.enableDamping = true;
    const inquadra = intera => { if (intera) { camera.position.set(0.25, 1.05, 4.2); controlli.target.set(0, 0.92, 0); } else { camera.position.set(0.18, 1.52, 1.35); controlli.target.set(0, 1.42, 0); } controlli.update(); };
    inquadra(false);
    corpo.querySelector('#rtBusto').onclick = () => inquadra(false);
    corpo.querySelector('#rtIntera').onclick = () => inquadra(true);
    const onResize = () => { const w = scatola.clientWidth, h = scatola.clientHeight; if (!w || !h) return; camera.aspect = w / h; camera.updateProjectionMatrix(); renderer.setSize(w, h); };
    addEventListener('resize', onResize);
    stato.textContent = `${io.nome} è pronto. Scrivigli o premi 🎤 per parlargli.` + (facce ? '' : ' (viso senza mascella mobile)');

    // ---------------------------------------------------------------- vivere
    let ascolta = 0;            // fino a quando guarda chi parla (ms)
    let parla = null;           // {analizzatore, buf, finto, inizio}
    let attesa = null, prossima = performance.now() + 9000, pesoGesti = 0;
    const ossa = n.ossa, D = (x, y, z) => new THREE.Vector3(x, y, z).normalize().applyQuaternion(n.radice.getWorldQuaternion(new THREE.Quaternion()));
    let prima = performance.now(), vivo = true, idAnim = 0;
    const su = new THREE.Vector3(0, 1, 0);
    function guarda(dt, t) {
        // lo sguardo: verso la telecamera se qualcuno parla o se parla lui; se no si guarda intorno piano
        const testa = ossa.Head, collo = ossa.Neck; if (!testa) return;
        const pt = testa.getWorldPosition(new THREE.Vector3());
        let bersaglio;
        if (performance.now() < ascolta || parla) bersaglio = camera.position.clone();
        else bersaglio = pt.clone().add(new THREE.Vector3(Math.sin(t * 0.23) * 0.8, Math.sin(t * 0.17) * 0.15, 2));
        const v = bersaglio.sub(pt).normalize();
        const yaw = Math.max(-0.7, Math.min(0.7, Math.atan2(v.x, v.z)));
        const pitch = Math.max(-0.35, Math.min(0.35, Math.asin(Math.max(-1, Math.min(1, v.y)))));
        guarda.y = (guarda.y || 0) + (yaw - (guarda.y || 0)) * (1 - Math.exp(-dt * 5));
        guarda.p = (guarda.p || 0) + (pitch - (guarda.p || 0)) * (1 - Math.exp(-dt * 5));
        giraNelMondo(collo, su, guarda.y * 0.4); giraNelMondo(testa, su, guarda.y * 0.6);
        const fianco = new THREE.Vector3(1, 0, 0).applyQuaternion(n.radice.getWorldQuaternion(new THREE.Quaternion()));
        giraNelMondo(testa, fianco, -guarda.p * 0.8);
    }
    function gestiParlando(dt, t, livello) {
        // le braccia che spiegano (senza la clip del gioco): a turno destra e sinistra, avanti all'altezza della vita
        pesoGesti += ((parla && !pose.length ? 1 : 0) - pesoGesti) * (1 - Math.exp(-dt * 4));
        if (pesoGesti < 0.02 || !N || !ossa.RightArm) return;
        const a = Math.sin(t * 1.7), b = Math.sin(t * 1.3 + 1.2), k = 0.55 + 0.45 * Math.min(1, livello * 3);
        N.punta(ossa.RightArm, ossa.RightForeArm, D(-0.35, -0.8, 0.35 + 0.25 * Math.max(0, a) * k), pesoGesti * 0.85);
        N.punta(ossa.RightForeArm, ossa.RightHand, D(-0.15 + 0.2 * a, 0.15 + 0.25 * Math.max(0, a) * k, 1), pesoGesti * 0.85);
        N.punta(ossa.LeftArm, ossa.LeftForeArm, D(0.35, -0.85, 0.25 + 0.2 * Math.max(0, b) * k), pesoGesti * 0.7);
        N.punta(ossa.LeftForeArm, ossa.LeftHand, D(0.15 - 0.2 * b, 0.05 + 0.2 * Math.max(0, b) * k, 1), pesoGesti * 0.7);
    }
    function ciclo() {
        if (!vivo) return;
        idAnim = requestAnimationFrame(ciclo);
        const ora = performance.now(), dt = Math.min(0.1, (ora - prima) / 1000), t = ora / 1000; prima = ora;
        // la bocca: l'ampiezza dell'audio (RMS) o un ritmo finto per la voce del browser
        let livello = 0;
        if (parla && parla.analizzatore) { parla.analizzatore.getFloatTimeDomainData(parla.buf); let s = 0; for (const x of parla.buf) s += x * x; livello = Math.sqrt(s / parla.buf.length); }
        else if (parla && parla.finto) livello = 0.12 * (0.5 + 0.5 * Math.sin(t * 15)) * (0.6 + 0.4 * Math.sin(t * 3.1));
        apertura.value += (Math.min(1, livello * 6) - apertura.value) * (1 - Math.exp(-dt * 22));
        if (apertura.value > (+scatola.dataset.bocca || 0)) scatola.dataset.bocca = apertura.value.toFixed(2);   // la bocca più aperta (per le prove)
        lettore.aggiorna(dt);
        andatura.aggiorna(0, dt, { giu: lettore.attenua });
        n.mixer.update(dt);
        // le attese (braccia conserte, si guarda intorno...) solo quando nessuno parla da un po'
        if (G && !parla && ora > ascolta + 4000) {
            if (!attesa && ora > prossima) { const [nome, durata] = G.ATTESE[Math.floor(Math.random() * G.ATTESE.length)]; attesa = { nome, durata: durata * 1000, t0: ora }; }
            if (attesa) {
                const e = ora - attesa.t0;
                if (e > attesa.durata) { attesa = null; prossima = ora + 8000 + Math.random() * 9000; }
                else G.attesa(ossa, n.radice, attesa.nome, THREE.MathUtils.smoothstep(Math.min(1, e / 700, (attesa.durata - e) / 700), 0, 1), t);
            }
        } else if (attesa) { attesa = null; prossima = ora + 8000; }
        gestiParlando(dt, t, livello);
        guarda(dt, t);
        controlli.update();
        renderer.render(scena, camera);
    }
    ciclo();

    // ---------------------------------------------------------------- parlare
    let conversazione = null, audioCtx = null;
    try { conversazione = sessionStorage.getItem('eclipse:conversazione'); } catch (e) { /* niente memoria */ }
    const voceBrowser = testo => new Promise(fine => {
        if (!('speechSynthesis' in window)) return fine();
        const u = new SpeechSynthesisUtterance(testo); u.lang = 'it-IT';
        const v = speechSynthesis.getVoices().find(x => /^it/i.test(x.lang)); if (v) u.voice = v;
        u.onstart = () => { parla = { finto: true }; scatola.dataset.voce = 'browser'; }; u.onend = u.onerror = () => { parla = null; fine(); };
        speechSynthesis.speak(u);
    });
    async function di(testo) {
        lettore2.suonaPosa();
        try {
            const v = await fetch('api/avatar/voce', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ testo }) }).then(r => r.json());
            if (!v.url) { await voceBrowser(testo); return; }
            audioCtx = audioCtx || new (window.AudioContext || window.webkitAudioContext)();
            // il browser lascia suonare solo dopo un gesto di chi guarda: se l'audio resta fermo, la voce del browser
            if (audioCtx.state === 'suspended') await Promise.race([audioCtx.resume(), new Promise(ok => setTimeout(ok, 1500))]);
            if (audioCtx.state !== 'running') throw new Error('il browser non lascia ancora suonare: tocca la pagina');
            scatola.dataset.voce = v.motore;
            const buf = await audioCtx.decodeAudioData(await (await fetch(v.url)).arrayBuffer());
            const src = audioCtx.createBufferSource(); src.buffer = buf;
            const an = audioCtx.createAnalyser(); an.fftSize = 1024; an.smoothingTimeConstant = 0.3;
            src.connect(an); an.connect(audioCtx.destination);
            parla = { analizzatore: an, buf: new Float32Array(an.fftSize) };
            await new Promise(fine => { src.onended = fine; src.start(); });
        } catch (e) { stato.textContent = 'Voce non disponibile: ' + e.message; await voceBrowser(testo); }
        finally { parla = null; if (clipParla) { lettore.ferma(clipParla, 0.5); clipParla = null; } scatola.dataset.posa = ''; }
    }
    // (0.29) IL PANNELLO DEL PERSONAGGIO: il gioco vero in una finestra di Chrome (gioca.workingwithweb.eu); qui lo stato e
    // il RobotMODE. Con la casella spuntata e il robot acceso la chat va al robot; spenta, ad Assist come sempre
    // (13/10 notte, Alfonso: «dentro HA non vedo il tasto logout per sloggare il pg in Eclipse») scollega l'account del
    // gioco (il gettone si cancella) e riapre la scheda, che chiede di nuovo nome e password
    const esciGioco = corpo.querySelector('#rtEsciGioco');
    if (esciGioco) esciGioco.onclick = async () => {
        if (!confirm("Scollegare l'account del gioco da Eclipse?")) return;
        await fetch('api/gioco/esci', { method: 'POST' }).catch(() => {});
        try { if (typeof chiudi === 'function') chiudi(); } catch (e) {}
        location.reload();
    };
    const gStato = corpo.querySelector('#rtGiocoStato'), alRobot = corpo.querySelector('#rtAlRobot');
    let robotAcceso = false, dopoRisp = 0, giroRobot = null;
    try { alRobot.checked = localStorage.getItem('eclipse:alrobot') === '1'; } catch (e) {}
    alRobot.onchange = () => { try { localStorage.setItem('eclipse:alrobot', alRobot.checked ? '1' : '0'); } catch (e) {} };
    async function aggiornaGioco() {
        const j = await fetch('api/gioco/personaggio').then(x => x.json()).catch(() => null);
        if (!vivo) return;
        if (!j || !j.ok) { gStato.textContent = 'Il portale del gioco non risponde.'; return; }
        if (j.link) corpo.querySelector('#rtApriGioco').href = j.link;
        robotAcceso = !!(j.robot && j.robot.acceso);
        if (!j.gioca) gStato.textContent = 'Non sei in gioco: apri il gioco in Chrome ed entra, poi qui comandi il robot.';
        else gStato.textContent = 'In gioco' + (j.dove ? ` · ${j.dove.mondo || ''} ${j.dove.x} ${j.dove.y} ${j.dove.z} · vita ${Math.round(j.dove.vita || 0)}` : '') +
            ' · robot ' + (robotAcceso ? 'ACCESO' + (j.robot.modello ? ' (' + j.robot.modello + ')' : '') : j.robot && !j.robot.configurato ? 'da configurare nel gioco (F8)' : 'spento');
    }
    async function robot(azione, testo) {
        const r = await fetch('api/gioco/robot', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ azione, testo }) });
        const j = await r.json().catch(() => ({})); if (!r.ok || !j.ok) throw new Error(j.errore || j.detail || r.status);
        robotAcceso = !!(j.robot && j.robot.acceso); return j;
    }
    async function leggiRobot() {
        const j = await fetch('api/gioco/robot/risposte?dopo=' + dopoRisp).then(x => x.json()).catch(() => null);
        if (!vivo || !j || !j.ok) return;
        for (const r of j.risposte || []) {
            if (dopoRisp && r.canale === 'robot') { scrivi(io.nome + ' 🤖', r.testo); di(r.testo); }
            dopoRisp = Math.max(dopoRisp, r.id);
        }
    }
    corpo.querySelector('#rtRobotSi').onclick = () => robot('accendi').then(aggiornaGioco).catch(e => { stato.textContent = 'Robot: ' + e.message; });
    corpo.querySelector('#rtRobotNo').onclick = () => robot('spegni').then(aggiornaGioco).catch(e => { stato.textContent = 'Robot: ' + e.message; });
    aggiornaGioco(); leggiRobot();
    giroRobot = setInterval(() => { if (!vivo) return clearInterval(giroRobot); leggiRobot(); }, 2500);
    const giroStato = setInterval(() => { if (!vivo) return clearInterval(giroStato); aggiornaGioco(); }, 15000);

    // (0.30) IL MIO AVATAR COME COSTUME: anteprima nel browser, poi il file va al gioco (api/gioco/costume)
    const cosFile = corpo.querySelector('#rtCosFile'), cosManda = corpo.querySelector('#rtCosManda'), cosStato = corpo.querySelector('#rtCosStato'), cosVista = corpo.querySelector('#rtCosVista');
    let cosDati = null, cosNome = '', cosAnim = 0;
    const STATI_COS = { attesa: 'in attesa: lo guarda un amministratore', approvato: 'approvato: compralo dal Costume Tailor nel gioco', rifiutato: 'non approvato' };
    async function statoCostume() {
        const j = await fetch('api/gioco/costume').then(x => x.json()).catch(() => null);
        if (!vivo || !j || !j.ok) return;
        if (j.costume) cosStato.textContent = 'Il tuo ultimo avatar: ' + (STATI_COS[j.costume.stato] || j.costume.stato) + (j.costume.motivo ? ' (' + j.costume.motivo + ')' : '') + ' · ' + j.costume.triangoli.toLocaleString('it-IT') + ' triangoli.';
    }
    statoCostume();
    cosFile.onchange = async () => {
        const f = cosFile.files[0]; cosManda.disabled = true; cosDati = null; cancelAnimationFrame(cosAnim);
        if (!f) return;
        if (f.size > 15 * 1024 * 1024) { cosStato.textContent = 'Il file è più grande di 15 MB.'; return; }
        cosDati = await f.arrayBuffer(); cosNome = f.name;
        try {
            const { GLTFLoader } = await import('three/addons/loaders/GLTFLoader.js');
            const g = await new GLTFLoader().parseAsync(cosDati.slice(0), '');
            let ossa = 0; g.scene.traverse(o => { if (o.isBone) ossa++; });
            if (!ossa) { cosStato.textContent = 'Questo modello non ha uno scheletro: serve un avatar con le ossa (Mixamo o VRM).'; return; }
            cosVista.style.display = 'block';
            const r = new THREE.WebGLRenderer({ canvas: cosVista, antialias: true }); r.setPixelRatio(devicePixelRatio); r.setSize(cosVista.clientWidth, cosVista.clientHeight, false);
            const sc = new THREE.Scene(); sc.add(new THREE.HemisphereLight(0xffffff, 0x334455, 2)); sc.add(g.scene);
            const b = new THREE.Box3().setFromObject(g.scene), c = b.getCenter(new THREE.Vector3()), h = b.getSize(new THREE.Vector3()).y || 1;
            const cam = new THREE.PerspectiveCamera(35, cosVista.clientWidth / cosVista.clientHeight, 0.01, 100); cam.position.set(c.x, c.y, c.z + h * 2.2); cam.lookAt(c);
            const gira = () => { if (!vivo || !cosVista.isConnected) return; cosAnim = requestAnimationFrame(gira); g.scene.rotation.y += 0.01; r.render(sc, cam); };
            gira();
            cosStato.textContent = 'Anteprima di ' + f.name + ' (' + ossa + ' ossa). Se va bene: «Porta nel gioco».';
            cosManda.disabled = false;
        } catch (e) { cosStato.textContent = 'Il file non si apre: ' + e.message; }
    };
    cosManda.onclick = async () => {
        if (!cosDati) return;
        cosManda.disabled = true; cosStato.textContent = 'Lo mando al gioco…';
        const r = await fetch('api/gioco/costume', { method: 'POST', headers: { 'Content-Type': 'application/octet-stream', 'X-Nome-File': cosNome.replace(/[^\w.\- ]/g, '') }, body: cosDati });
        const j = await r.json().catch(() => ({}));
        cosStato.textContent = r.ok && j.ok ? (j.messaggio || 'Mandato.') : 'Non mandato: ' + (j.errore || j.detail || r.status);
        if (!(r.ok && j.ok)) cosManda.disabled = false;
    };

    async function invia(testo) {
        testo = String(testo || '').trim(); if (!testo) return;
        scrivi('tu', testo); ascolta = performance.now() + 30000;
        if (alRobot.checked && robotAcceso) {
            stato.textContent = 'Ordine al robot…';
            try { await robot('ordine', testo); stato.textContent = ''; } catch (e) { stato.textContent = 'Il robot non riceve: ' + e.message; }
            return;
        }
        stato.textContent = `${io.nome} ci pensa…`;
        try {
            const r = await fetch('api/avatar/parla', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ testo, conversation_id: conversazione }) });
            const j = await r.json(); if (!r.ok) throw new Error(j.detail || r.status);
            conversazione = j.conversation_id || conversazione; try { sessionStorage.setItem('eclipse:conversazione', conversazione || ''); } catch (e) {}
            const risposta = j.risposta || '…';
            scrivi(io.nome, risposta); stato.textContent = '';
            await di(risposta);
            ascolta = performance.now() + 6000;
            if (j.continua && riconoscitore) ascoltaMic();
        } catch (e) { stato.textContent = 'Non riesco a rispondere: ' + e.message; }
    }
    const campo = corpo.querySelector('#rtTesto');
    campo.addEventListener('input', () => { ascolta = performance.now() + 8000; });
    campo.addEventListener('keydown', e => { if (e.key === 'Enter') { const t = campo.value; campo.value = ''; invia(t); } });
    corpo.querySelector('#rtInvia').onclick = () => { const t = campo.value; campo.value = ''; invia(t); };
    // il microfono: il riconoscimento vocale del browser (in Chrome passa dai server di Google), in italiano
    const Riconosci = window.SpeechRecognition || window.webkitSpeechRecognition;
    const riconoscitore = Riconosci ? new Riconosci() : null;
    const mic = corpo.querySelector('#rtMic');
    function ascoltaMic() {
        if (!riconoscitore) return;
        try { riconoscitore.start(); } catch (e) { return; }
    }
    if (riconoscitore) {
        riconoscitore.lang = 'it-IT'; riconoscitore.interimResults = true; riconoscitore.continuous = false;
        riconoscitore.onstart = () => { mic.textContent = '🔴'; stato.textContent = 'Ti ascolto…'; ascolta = performance.now() + 20000; };
        riconoscitore.onresult = e => { const r = [...e.results].map(x => x[0].transcript).join(' '); campo.value = r; if (e.results[e.results.length - 1].isFinal) { campo.value = ''; invia(r); } };
        riconoscitore.onerror = e => { stato.textContent = 'Microfono: ' + (e.error === 'not-allowed' ? 'permesso negato dal browser' : e.error); };
        riconoscitore.onend = () => { mic.textContent = '🎤'; };
        mic.onclick = ascoltaMic;
    } else { mic.disabled = true; mic.title = 'Questo browser non sa riconoscere la voce: scrivi'; }

    // per chiudere (quando si cambia scheda)
    return () => {
        vivo = false; cancelAnimationFrame(idAnim); removeEventListener('resize', onResize);
        try { riconoscitore && riconoscitore.abort(); } catch (e) {}
        try { speechSynthesis.cancel(); } catch (e) {}
        try { renderer.dispose(); renderer.forceContextLoss(); } catch (e) {}
    };
}
