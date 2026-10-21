// IL TUO AVATAR DI ANOTHER WILDE WEB NELLA CASA 3D (Eclipse Finder 0.26, 13 ottobre 2026). Licenza MIT.
//
// Il personaggio e il codice che lo anima sono quelli del gioco: si caricano da gioco/ (i moduli del portale, passando
// da Eclipse col gettone dell'account), e i moduli a loro volta prendono i modelli da aspetti/ e modelli/. Qui c'è solo
// quello che serve alla casa: metterlo nella scena (che è in centimetri), farlo camminare con WASD o le frecce (Maiusc =
// corsa), girarlo dove va, fermarlo contro i muri, e tenere la telecamera dietro di lui (si ruota col mouse come prima).
//
// const av = await creaAvatar({ scena, radice, muri, partenza, piano, telecamera, controlli })
//   av.aggiorna(dt)  a ogni fotogramma;  av.togli()  per toglierlo
import * as THREE from 'three';

const CAMMINA = 140, CORRI = 420;          // centimetri al secondo
const RAGGIO = 28;                        // quanto sta lontano dai muri (cm)

export async function creaAvatar({ scena, radice, muri, partenza, piano = 0, telecamera, controlli, avviso = () => {} }) {
    const r = await fetch('api/gioco/avatar');
    if (!r.ok) throw new Error((await r.json().catch(() => ({}))).detail || 'avatar non disponibile');
    const io = await r.json();
    const P = await import('./gioco/personaggioAA.js');
    const M = await import('./gioco/mocap.js');
    const sesso = io.corpo === 'aa:donna' ? 'donna' : 'uomo';
    const n = await P.caricaNuian(sesso);
    const clip = await n.clip;
    const andatura = new M.Andatura(n.mixer, clip);
    // viso, capelli e colori come in gioco; poi il costume indossato (se ce n'è uno)
    try { await P.testaPer(n, io.nome, io.scelta || null); } catch (e) { avviso('viso e capelli: ' + e.message); }
    try {
        const c = io.scelta && io.scelta.costume ? await P.pezziCostume(n.nome, io.scelta.costume) : null;
        if (c) { await P.vesti(n, c.file); P.conElmo(n, c.elmo); }
        // (0.31) le armi e l'armatura del gioco: l'ultima arma sulla schiena, con la sua skin e l'aura (gioco/equip_avatar.js)
        try { const E = await import('./gioco/equip_avatar.js'); await E.vestiEquip(n, io.scelta || null, { costume: !!c }); } catch (e) { avviso('armi: ' + e.message); }
    } catch (e) { avviso('costume: ' + e.message); }

    // nella casa: metri → centimetri; il corpo di ArcheAge guarda +Z, l'angolo è quello della direzione di marcia
    const corpo = new THREE.Group();
    corpo.add(n.radice);
    corpo.scale.setScalar(100);
    corpo.position.set(partenza.x, piano, partenza.z);
    (radice || scena).add(corpo);
    corpo.traverse(o => { if (o.isMesh) { o.frustumCulled = false; o.castShadow = true; } });

    const tasti = new Set();
    const giu = e => {
        if (/^(INPUT|TEXTAREA|SELECT)$/.test((document.activeElement || {}).tagName || '')) return;
        if (['KeyW', 'KeyA', 'KeyS', 'KeyD', 'ArrowUp', 'ArrowDown', 'ArrowLeft', 'ArrowRight', 'ShiftLeft', 'ShiftRight'].includes(e.code)) { tasti.add(e.code); e.preventDefault(); }
    };
    const su = e => tasti.delete(e.code);
    const via = () => tasti.clear();
    addEventListener('keydown', giu); addEventListener('keyup', su); addEventListener('blur', via);

    // la telecamera segue: si sposta di quanto si sposta il personaggio, l'orbita col mouse resta
    const testa = () => corpo.position.clone().add(new THREE.Vector3(0, 150, 0));
    // all'ingresso: dietro di lui, 3 m indietro e un po' più su, guardando la testa
    if (controlli) {
        const t0 = testa();
        controlli.target.copy(t0);
        telecamera.position.copy(t0).add(new THREE.Vector3(0, 120, -300));
        controlli.update();
    }
    // i muri fra la telecamera e il personaggio diventano trasparenti (la telecamera resta alla sua distanza: avvicinarla
    // la faceva finire dentro il personaggio). Ogni muro ha il suo materiale la prima volta che serve
    const raggioCam = new THREE.Raycaster();
    let trasparenti = new Set();
    const tieniFuoriDaiMuri = () => {
        if (!controlli || !muri || !muri.length) return;
        const t = controlli.target, v = telecamera.position.clone().sub(t), L = v.length();
        raggioCam.set(t, v.normalize()); raggioCam.far = L;
        const ora = new Set(raggioCam.intersectObjects(muri, false).map(h => h.object));
        for (const m of ora) {
            if (!m.userData.matProprio) { m.material = m.material.clone(); m.userData.matProprio = true; }
            m.material.transparent = true; m.material.opacity = 0.18; m.material.depthWrite = false;
        }
        for (const m of trasparenti) if (!ora.has(m)) { m.material.opacity = 1; m.material.transparent = false; m.material.depthWrite = true; }
        trasparenti = ora;
    };
    const raggi = new THREE.Raycaster(), fianco = new THREE.Vector3();
    // può andare di «passo» nella direzione «verso»? (raggi a ginocchio e a petto contro i muri)
    const libero = (verso, passo) => {
        if (!muri || !muri.length) return true;
        for (const h of [40, 120]) {
            raggi.set(corpo.position.clone().add(new THREE.Vector3(0, h, 0)), verso);
            raggi.far = passo + RAGGIO;
            if (raggi.intersectObjects(muri, false).length) return false;
        }
        return true;
    };
    let velocita = 0, angolo = 0;
    return {
        corpo, nome: io.nome,
        aggiorna(dt) {
            dt = Math.min(dt, 0.1);
            const avanti = (tasti.has('KeyW') || tasti.has('ArrowUp') ? 1 : 0) - (tasti.has('KeyS') || tasti.has('ArrowDown') ? 1 : 0);
            const destra = (tasti.has('KeyD') || tasti.has('ArrowRight') ? 1 : 0) - (tasti.has('KeyA') || tasti.has('ArrowLeft') ? 1 : 0);
            let v = 0;
            if (avanti || destra) {
                // avanti = dove guarda la telecamera, sul piano
                const fronte = new THREE.Vector3(); telecamera.getWorldDirection(fronte); fronte.y = 0; fronte.normalize();
                fianco.crossVectors(fronte, new THREE.Vector3(0, 1, 0));
                const dir = fronte.multiplyScalar(avanti).add(fianco.clone().multiplyScalar(destra)).normalize();
                const corsa = tasti.has('ShiftLeft') || tasti.has('ShiftRight');
                const passo = (corsa ? CORRI : CAMMINA) * dt;
                // contro un muro si scivola lungo (prima tutto, poi solo x, poi solo z)
                const prima = corpo.position.clone();
                const prova = d => { if (d.lengthSq() < 1e-6) return false; const u = d.clone().normalize(); if (!libero(u, d.length())) return false; corpo.position.add(d); return true; };
                const mossa = dir.clone().multiplyScalar(passo);
                if (!prova(mossa)) { if (!prova(new THREE.Vector3(mossa.x, 0, 0))) prova(new THREE.Vector3(0, 0, mossa.z)); }
                const fatta = corpo.position.clone().sub(prima);
                v = fatta.length() / dt;
                angolo = Math.atan2(dir.x, dir.z);
                if (controlli) { telecamera.position.add(fatta); controlli.target.add(fatta); }
            }
            // si gira piano verso dove va
            let d = angolo - corpo.rotation.y; d = Math.atan2(Math.sin(d), Math.cos(d));
            corpo.rotation.y += d * (1 - Math.exp(-dt * 12));
            velocita += (v - velocita) * (1 - Math.exp(-dt * 10));
            tieniFuoriDaiMuri();
            andatura.aggiorna(velocita / 100, dt);           // le andature del gioco vogliono metri al secondo
            n.mixer.update(dt);
        },
        togli() {
            for (const m of trasparenti) { m.material.opacity = 1; m.material.transparent = false; m.material.depthWrite = true; }
            removeEventListener('keydown', giu); removeEventListener('keyup', su); removeEventListener('blur', via);
            corpo.removeFromParent();
        },
    };
}
