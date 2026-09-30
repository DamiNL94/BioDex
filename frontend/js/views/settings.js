// Vista Ajustes: cuenta, sincronización (F5), datos del dispositivo, tema, versión y cierre de sesión.
// Sincronización: pendientes, última pasada, «Sincronizar ahora», marcas rechazadas (reintentar o
// descartar a mano) y catálogos de los que te han sacado con marcas sin subir (exportar o descartar).
// Dispositivo: espacio usado (storage.estimate), catálogos descargados y respaldo (exportar/importar).

import { state, subscribe, setTheme, APP_VERSION, CONNECTION_LABELS } from '../state.js';
import { html, render, $, plural } from '../util.js';
import { confirmDialog } from '../components/overlay.js';
import { syncNow, refreshCounts } from '../sync.js';
import { rejectedItems, retryRejected, discardRejected, downloadBackup, importBackup, outboxEntries } from '../marks.js';
import { localCatalogs, storageInfo, formatBytes, shortDate, forgetCatalog } from '../offline.js';

const THEMES = [['system', 'Sistema'], ['light', 'Claro'], ['dark', 'Oscuro']];

export function mount(el, { onLogout }) {
  let alive = true;
  let rejected = [];
  let catalogs = [];
  let storage = { usage: null, quota: null, persisted: null };
  let revokedPending = new Map();    // catalogId → nº de marcas sin subir
  let message = null;                // {kind, text} tras importar/exportar

  async function loadLocal() {
    try {
      [rejected, catalogs, storage] = await Promise.all([rejectedItems(), localCatalogs(), storageInfo()]);
      const entries = await outboxEntries();
      revokedPending = new Map(catalogs.filter((c) => c.revoked)
        .map((c) => [c.id, entries.filter((e) => e.catalogId === c.id).length]));
    } catch (e) { /* sin base local: se pinta lo demás */ }
    if (alive) paint();
  }

  function syncLine() {
    const s = state.sync;
    if (s.running) return 'Sincronizando…';
    if (s.unauthorized) return `Inicia sesión para sincronizar (${plural(s.unsynced, 'pendiente', 'pendientes')}).`;
    if (s.error && s.nextRetryAt) {
      const secs = Math.max(0, Math.round((s.nextRetryAt - Date.now()) / 1000));
      return `${s.error} Se reintentará en ${secs < 60 ? `${secs} s` : `${Math.round(secs / 60)} min`}.`;
    }
    return s.lastSync ? `Última sincronización: ${shortDate(s.lastSync)}` : 'Aún no se ha sincronizado en este dispositivo.';
  }

  function paint() {
    const u = state.user || {};
    const s = state.sync;
    const sw = 'serviceWorker' in navigator && navigator.serviceWorker.controller;
    const downloaded = catalogs.filter((c) => c.downloadedAt);
    const revoked = catalogs.filter((c) => c.revoked);
    render(el, html`
      <div class="page-head"><h2>Ajustes</h2></div>
      <div class="settings">
        <section class="panel">
          <h3>Cuenta</h3>
          <dl class="facts">
            <div><dt>Nombre</dt><dd>${u.name || '—'}</dd></div>
            <div><dt>Email</dt><dd>${u.email}
              ${u.emailVerified ? '' : html`<span class="badge warn">Email sin verificar</span>`}</dd></div>
            ${u.orcid ? html`<div><dt>ORCID</dt><dd>${u.orcid}</dd></div>` : ''}
          </dl>
        </section>
        <section class="panel" aria-labelledby="set-sync">
          <h3 id="set-sync">Sincronización</h3>
          <dl class="facts">
            <div><dt>Pendientes</dt><dd id="syncPending">${s.pending}</dd></div>
            ${s.rejected ? html`<div><dt>Rechazadas</dt><dd>${s.rejected}</dd></div>` : ''}
          </dl>
          <p class="hint" role="status" id="syncLine">${syncLine()}</p>
          <div class="form-actions"><button type="button" class="btn-primary" id="syncNowBtn"
            ${s.running || !state.online ? html`disabled` : ''}>Sincronizar ahora</button></div>
          ${state.online ? '' : html`<p class="hint">${CONNECTION_LABELS[state.connection]}: se sincronizará sola al volver.</p>`}
          ${rejected.length ? html`<h4>Rechazadas por el servidor</h4>
            <ul class="marks rejected-list">${rejected.map(({ entry, occurrence, species }) => html`<li class="mark">
              <div class="mark-main"><span class="mark-when">${entry.kind === 'species'
                ? html`Especie ${species ? species.canonicalName || species.scientificName : entry.ref}`
                : entry.kind === 'delete' ? 'Borrado de marca' : html`Marca del ${occurrence ? new Date(occurrence.eventDate).toLocaleString('es-ES') : '?'}`}</span></div>
              <p class="hint mark-reason">${(entry.rejected && (entry.rejected.detail || entry.rejected.reason)) || entry.lastError || ''}</p>
              <div class="form-actions"><button type="button" class="link-btn" data-retry="${entry.id}">Reintentar</button>
                <button type="button" class="link-btn danger-link" data-discard="${entry.id}">Descartar</button></div>
            </li>`)}</ul>` : ''}
          ${revoked.map((c) => html`<div class="guard-band warn revoked">
            <p>Ya no eres miembro de «${c.name}». Tienes ${plural(revokedPending.get(c.id) || 0, 'marca', 'marcas')} sin
              sincronizar de ese catálogo que el servidor ya no aceptará.</p>
            <div class="form-actions"><button type="button" class="btn-secondary" data-export-cat="${c.id}">Exportar respaldo</button>
              <button type="button" class="link-btn danger-link" data-forget="${c.id}">Descartar y borrar del dispositivo</button></div>
          </div>`)}
        </section>
        <section class="panel" aria-labelledby="set-device">
          <h3 id="set-device">Datos en este dispositivo</h3>
          <dl class="facts">
            <div><dt>Espacio usado</dt><dd>${storage.usage !== null && storage.usage !== undefined
              ? html`${formatBytes(storage.usage)}${storage.quota ? html` <span class="muted">de ${formatBytes(storage.quota)} disponibles</span>` : ''}`
              : 'No disponible'}</dd></div>
            <div><dt>Almacenamiento</dt><dd>${storage.persisted ? 'Persistente: el navegador no lo borrará solo'
              : storage.persisted === false ? 'Temporal: el navegador podría borrarlo si le falta espacio' : '—'}</dd></div>
          </dl>
          ${downloaded.length ? html`<h4>Catálogos sin conexión</h4><ul class="plain-list">${downloaded.map((c) => html`
            <li><strong>${c.name}</strong> <span class="muted">· ${shortDate(c.downloadedAt)}</span></li>`)}</ul>`
            : html`<p class="hint">Ningún catálogo descargado. En Catálogos, «Descargar para usar sin conexión».</p>`}
          <h4>Respaldo</h4>
          <p class="hint">Un fichero con tus marcas sin sincronizar, por si hay que cambiar de dispositivo o cerrar sesión.</p>
          ${message ? html`<p class="guard-band ${message.kind}" role="status">${message.text}</p>` : ''}
          <div class="form-actions">
            <button type="button" class="btn-secondary" id="exportBtn" ${s.unsynced ? '' : html`disabled`}>Exportar respaldo</button>
            <label class="btn-secondary file-btn">Importar respaldo<input type="file" id="importInput" accept="application/json,.json"></label>
          </div>
        </section>
        <section class="panel">
          <h3>Apariencia</h3>
          <div class="segmented" role="radiogroup" aria-label="Tema">${THEMES.map(([value, label]) => html`
            <button type="button" role="radio" data-choice="${value}" aria-checked="${state.theme === value}"
                    class="${state.theme === value ? 'active' : ''}">${label}</button>`)}</div>
          <p class="hint">«Sistema» sigue el modo claro u oscuro del dispositivo.</p>
        </section>
        <section class="panel">
          <h3>Aplicación</h3>
          <dl class="facts">
            <div><dt>Versión</dt><dd>${APP_VERSION}</dd></div>
            <div><dt>Conexión</dt><dd>${CONNECTION_LABELS[state.connection]}</dd></div>
            <div><dt>Uso sin conexión</dt><dd>${sw ? 'Preparado' : 'No disponible en este navegador o aún instalándose'}</dd></div>
          </dl>
        </section>
        <section class="panel">
          <button type="button" class="btn-secondary danger" id="logoutBtn">Cerrar sesión</button>
          <p class="hint">${state.online ? 'Cierra la sesión solo en este dispositivo y borra de él tus datos.'
            : `${CONNECTION_LABELS[state.connection]}: se olvida la sesión en este dispositivo; el servidor la cerrará cuando caduque.`}</p>
        </section>
      </div>`);

    el.querySelectorAll('[data-choice]').forEach((b) => b.addEventListener('click', () => setTheme(b.dataset.choice)));
    $('#syncNowBtn', el).addEventListener('click', () => syncNow());
    $('#logoutBtn', el).addEventListener('click', async (ev) => {
      const btn = ev.currentTarget;
      btn.disabled = true;
      const done = await onLogout();
      if (!done && btn.isConnected) btn.disabled = false;
    });
    $('#exportBtn', el).addEventListener('click', async () => {
      const n = await downloadBackup();
      message = { kind: 'info', text: `Respaldo descargado (${plural(n, 'marca', 'marcas')}).` };
      paint();
    });
    $('#importInput', el).addEventListener('change', async (ev) => {
      const file = ev.target.files && ev.target.files[0];
      if (!file) return;
      try {
        const { added, skipped } = await importBackup(JSON.parse(await file.text()));
        message = { kind: 'info', text: `${plural(added, 'marca vuelve', 'marcas vuelven')} a la cola${skipped ? ` · ${skipped} ya estaban en el dispositivo` : ''}.` };
        await refreshCounts();
        if (added && state.online) syncNow();
      } catch (e) {
        message = { kind: 'warn', text: e instanceof SyntaxError ? 'Ese fichero no es un respaldo de BioDex.' : e.message };
      }
      loadLocal();
    });
    el.querySelectorAll('[data-retry]').forEach((b) => b.addEventListener('click', async () => {
      await retryRejected(Number(b.dataset.retry));
      await refreshCounts();
      syncNow();
      loadLocal();
    }));
    el.querySelectorAll('[data-discard]').forEach((b) => b.addEventListener('click', async () => {
      const ok = await confirmDialog({ key: 'descartar', title: 'Descartar', danger: true, confirmLabel: 'Descartar',
        text: html`<p>Se borra del dispositivo y no llegará al servidor. No se puede deshacer.</p>` });
      if (!ok) return;
      await discardRejected(Number(b.dataset.discard));
      await refreshCounts();
      loadLocal();
    }));
    el.querySelectorAll('[data-export-cat]').forEach((b) => b.addEventListener('click', async () => {
      const n = await downloadBackup(b.dataset.exportCat);
      message = { kind: 'info', text: `Respaldo descargado (${plural(n, 'marca', 'marcas')}).` };
      paint();
    }));
    el.querySelectorAll('[data-forget]').forEach((b) => b.addEventListener('click', async () => {
      const cat = catalogs.find((c) => c.id === b.dataset.forget);
      const ok = await confirmDialog({ key: 'descartar-catalogo', title: 'Borrar del dispositivo', danger: true,
        confirmLabel: 'Descartar y borrar', text: html`<p>Se borran de este dispositivo «${cat ? cat.name : ''}» y sus
          ${plural(revokedPending.get(b.dataset.forget) || 0, 'marca', 'marcas')} sin sincronizar.</p>` });
      if (!ok) return;
      await forgetCatalog(b.dataset.forget);
      await refreshCounts();
      loadLocal();
    }));
  }

  const unsubscribe = subscribe((key) => {
    if (key === 'theme' || key === 'online' || key === 'user') paint();
    if (key === 'sync' || key === 'download') loadLocal();
  });
  // La línea «se reintentará en …» cuenta hacia atrás.
  const tick = setInterval(() => { const l = $('#syncLine', el); if (l) l.textContent = syncLine(); }, 5000);
  paint();
  loadLocal();
  return () => { alive = false; unsubscribe(); clearInterval(tick); };
}
