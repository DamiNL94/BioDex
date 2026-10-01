// ================================================================================================
// catalog-team.js — Catálogo › Equipo, Niveles y Registro (F6), dentro de «Datos del catálogo».
// ------------------------------------------------------------------------------------------------
// Equipo (pestaña del detalle del catálogo): miembros con su rol en versalitas, email verificado y
// ORCID. El owner invita (por email o con un enlace que se muestra UNA vez), revoca invitaciones,
// cambia roles (conceder o quitar custodio explica lo que implica), expulsa y transfiere la propiedad
// (pide la contraseña). Quien no es owner puede salir del catálogo.
// Niveles y Registro (owner): paneles a pantalla completa con su tabla.
// Sin red se pinta lo último descargado («Datos de las HH:MM», meta de IndexedDB vía cachedRemote) y
// todo lo que cambia algo se desactiva con «Necesita conexión».
// ================================================================================================

import { apiGetJson, apiPostJson, apiPutJson, apiDeleteJson } from '../api.js';
import { state, subscribe, setActiveCatalog } from '../state.js';
import { html, render, $, plural, sciName } from '../util.js';
import { openOverlay, confirmDialog } from '../components/overlay.js';
import { cachedRemote, sensitivityLevels } from '../offline.js';
import { handleNotMember, syncNow } from '../sync.js';
import { outboxEntries } from '../marks.js';

export const ROLE_LABELS = { owner: 'Propietario', custodian: 'Custodio', editor: 'Editor', viewer: 'Lector' };
const ROLE_HELP = {
  custodian: 've las coordenadas exactas de todas las especies, también las amenazadas',
  editor: 'marca en el campo y añade especies; ve las marcas ajenas generalizadas según su nivel',
  viewer: 'consulta el catálogo y las marcas, generalizadas según su nivel; no marca',
};
const AUDIT_LABELS = {
  view_exact: 'Vista exacta', export_full: 'Exportación completa', set_level: 'Nivel', grant_role: 'Rol concedido',
  revoke_role: 'Rol retirado', login_failed: 'Contraseña fallida', invite_created: 'Invitación',
  invite_revoked: 'Invitación revocada', member_joined: 'Alta', member_removed: 'Expulsión', member_left: 'Salida',
  set_reference: 'Evaluación de referencia', photo_review: 'Foto de iNaturalist',
};
const OFFLINE_ACTION = 'Necesita conexión';

const pad = (n) => String(n).padStart(2, '0');
/** Fecha del servidor (UTC, «2026-09-30 10:18:39») → Date local. */
const serverDate = (s) => new Date(String(s).replace(' ', 'T') + (String(s).endsWith('Z') ? '' : 'Z'));
const fmtDay = (s) => (s ? serverDate(s).toLocaleDateString('es-ES', { day: 'numeric', month: 'short', year: 'numeric' }) : '');
const fmtDateTime = (s) => {
  const d = serverDate(s);
  return `${pad(d.getDate())}/${pad(d.getMonth() + 1)}/${d.getFullYear()} ${pad(d.getHours())}:${pad(d.getMinutes())}`;
};

/** «Datos de las 18:40» (hoy) o «Datos del 29/09 a las 18:40». */
export function dataNote(at) {
  const d = new Date(at);
  const hm = `${pad(d.getHours())}:${pad(d.getMinutes())}`;
  return d.toDateString() === new Date().toDateString() ? `Datos de las ${hm}` : `Datos del ${pad(d.getDate())}/${pad(d.getMonth() + 1)} a las ${hm}`;
}

const canAct = (fromDevice) => state.online && !fromDevice;
const catPath = (catalog) => '/catalogs/' + encodeURIComponent(catalog.id);

const ICON_VERIFIED = html`<svg class="ico" viewBox="0 0 24 24" aria-hidden="true"><path d="M4 6h16v12H4zM4 7l8 6 8-6"/><path d="m14 16 2 2 4-4"/></svg>`;

// ================================================================================================
// Equipo
// ================================================================================================

/**
 * Pinta el equipo de `catalog` ({id, name, role}) en `el`. `onLeft()` tras salir o transferir (el
 * catálogo cambia para quien mira). Devuelve la función de desmontaje.
 */
export function mountTeam(el, catalog, { onLeft, onChanged } = {}) {
  let alive = true;
  let team = null;           // {data: {role, members}, at, fromDevice}
  let invites = null;        // {data: {invites}, at, fromDevice} (owner)
  let error = null;
  let flash = null;          // invitación recién creada: {link, email, emailSent}
  let message = null;        // {kind, text}
  const isOwner = catalog.role === 'owner';
  const me = state.user && state.user.id;

  async function load() {
    try {
      const jobs = [cachedRemote('team:' + catalog.id, () => apiGetJson(`${catPath(catalog)}/members`))];
      if (isOwner) jobs.push(cachedRemote('invites:' + catalog.id, () => apiGetJson(`${catPath(catalog)}/invites`)).catch(() => null));
      const [t, inv] = await Promise.all(jobs);
      team = t;
      invites = inv || null;
      error = null;
    } catch (e) {
      error = e.offline ? 'Sin conexión y sin datos del equipo guardados en este dispositivo.' : e.message;
    }
    if (alive) paint();
  }

  function memberItem(m) {
    const mine = m.userId === me;
    const editable = isOwner && !mine && m.role !== 'owner';
    const live = canAct(team.fromDevice);
    return html`<li class="member">
      <div class="member-main">
        <span class="member-name">${m.name}${mine ? html` <span class="muted">(tú)</span>` : ''}</span>
        <span class="badge role-badge role-${m.role}">${ROLE_LABELS[m.role] || m.role}</span>
      </div>
      <div class="member-meta">
        ${m.emailVerified ? html`<span class="member-flag ok" title="Email verificado">${ICON_VERIFIED}<span>Email verificado</span></span>`
          : html`<span class="member-flag muted" title="Email sin verificar">Email sin verificar</span>`}
        ${m.orcid ? html`<a class="orcid" href="https://orcid.org/${m.orcid}" target="_blank" rel="noopener noreferrer"
          aria-label="ORCID de ${m.name}: ${m.orcid}"><span class="orcid-id" aria-hidden="true">iD</span>${m.orcid}</a>` : ''}
        ${m.email ? html`<span class="muted member-email">${m.email}</span>` : ''}
        <span class="muted">Desde ${fmtDay(m.joinedAt)}</span>
      </div>
      ${editable ? html`<div class="member-actions">
        <label class="field field-inline"><span>Rol</span>
          <select data-role="${m.userId}" ${live ? '' : html`disabled`}>
            ${['custodian', 'editor', 'viewer'].map((r) => html`<option value="${r}" ${r === m.role ? html`selected` : ''}
              ${r === 'custodian' && !m.emailVerified && m.role !== 'custodian' ? html`disabled` : ''}>${ROLE_LABELS[r]}${r === 'custodian' && !m.emailVerified && m.role !== 'custodian' ? ' (necesita email verificado)' : ''}</option>`)}
          </select></label>
        <button type="button" class="link-btn danger-link" data-expel="${m.userId}" ${live ? '' : html`disabled`}>Expulsar</button>
      </div>` : ''}
    </li>`;
  }

  function inviteItem(inv) {
    const live = canAct(invites.fromDevice);
    return html`<li class="invite">
      <div class="member-main">
        <span class="member-name">${inv.email || 'Enlace'}</span>
        <span class="badge role-badge">${ROLE_LABELS[inv.role]}</span>
      </div>
      <div class="member-meta">
        <span class="muted">${inv.uses} de ${plural(inv.maxUses, 'uso', 'usos')}</span>
        <span class="muted">Caduca el ${fmtDay(inv.expiresAt)}</span>
        <button type="button" class="link-btn danger-link" data-revoke="${inv.id}" ${live ? '' : html`disabled`}>Revocar</button>
      </div>
    </li>`;
  }

  function paint() {
    if (!alive) return;
    if (error) { render(el, html`<p class="panel muted">${error}</p>`); return; }
    if (!team) { render(el, html`<p class="muted">Cargando equipo…</p>`); return; }
    const live = canAct(team.fromDevice);
    const custodians = team.data.members.filter((m) => m.role === 'custodian');
    render(el, html`
      ${team.fromDevice || !state.online ? html`<p class="copy-note" role="status">${dataNote(team.at)} · ${OFFLINE_ACTION} para cambiar el equipo</p>` : ''}
      ${message ? html`<p class="guard-band ${message.kind}" role="status">${message.text}</p>` : ''}
      <h4>${plural(team.data.members.length, 'miembro', 'miembros')}</h4>
      <ul class="members">${team.data.members.map(memberItem)}</ul>

      ${isOwner ? html`
        <h4>Invitar</h4>
        ${flash ? html`<div class="invite-flash" role="status">
          ${flash.email ? html`<p>${flash.emailSent ? html`Invitación enviada a <strong>${flash.email}</strong>.`
            : html`<strong>No se pudo enviar el correo a ${flash.email}.</strong> Pásale el enlace por otra vía.`}</p>` : ''}
          <p class="flash-warn"><strong>Guárdalo ahora; no se volverá a mostrar.</strong></p>
          <div class="copy-row"><input class="copy-input" readonly value="${flash.link}" aria-label="Enlace de invitación">
            <button type="button" class="btn-secondary" data-copy>Copiar</button></div>
          <button type="button" class="link-btn" data-flash-ok>Hecho</button>
        </div>` : html`
        <form class="form invite-form" novalidate>
          <div class="segmented" role="radiogroup" aria-label="Tipo de invitación">
            <button type="button" role="radio" data-kind="email" aria-checked="true" class="active">Por email</button>
            <button type="button" role="radio" data-kind="link" aria-checked="false">Enlace</button>
          </div>
          <label class="field" data-for="email"><span>Email</span><input name="email" type="email" autocomplete="off" required></label>
          <div class="field-row">
            <label class="field"><span>Rol</span><select name="role">
              <option value="editor">Editor</option><option value="viewer">Lector</option></select></label>
            <label class="field" data-for="link" hidden><span>Usos</span><input name="maxUses" type="number" min="1" max="20" value="1" inputmode="numeric"></label>
            <label class="field"><span>Días</span><input name="days" type="number" min="1" max="30" value="7" inputmode="numeric"></label>
          </div>
          <p class="hint" data-role-help>${ROLE_HELP.editor}.</p>
          <p class="form-error" role="alert" hidden></p>
          <div class="form-actions"><button type="submit" class="btn-primary" ${live ? '' : html`disabled`}>Invitar</button>
            ${live ? '' : html`<span class="hint">${OFFLINE_ACTION}</span>`}</div>
        </form>`}
        ${invites && invites.data.invites.length ? html`<h4>Invitaciones pendientes</h4>
          <ul class="members">${invites.data.invites.map(inviteItem)}</ul>` : ''}

        <div class="danger-zone">
          <h4>Transferir la propiedad</h4>
          ${custodians.length ? html`<p class="hint">Pasa a ser propietario un custodio; tú quedas como custodio.</p>
            <button type="button" class="btn-secondary" data-transfer ${live ? '' : html`disabled`}>Transferir la propiedad</button>`
            : html`<p class="hint">Solo se puede transferir a un custodio. Haz custodio antes a alguien con el email verificado.</p>`}
          <p class="hint">Como propietario no puedes salir del catálogo sin transferirlo antes.</p>
        </div>` : html`
        <div class="danger-zone">
          <button type="button" class="btn-secondary danger" data-leave ${live ? '' : html`disabled`}>Salir del catálogo</button>
          ${live ? '' : html`<p class="hint">${OFFLINE_ACTION}</p>`}
        </div>`}`);
    wire();
  }

  function wire() {
    el.querySelectorAll('[data-role]').forEach((s) => s.addEventListener('change', () => changeRole(Number(s.dataset.role), s)));
    el.querySelectorAll('[data-expel]').forEach((b) => b.addEventListener('click', () => expel(Number(b.dataset.expel))));
    el.querySelectorAll('[data-revoke]').forEach((b) => b.addEventListener('click', () => revoke(Number(b.dataset.revoke))));
    const on = (sel, fn) => { const n = $(sel, el); if (n) n.addEventListener('click', fn); };
    on('[data-leave]', leave);
    on('[data-transfer]', transfer);
    on('[data-flash-ok]', () => { flash = null; paint(); });
    on('[data-copy]', async (ev) => {
      const input = $('.copy-input', el);
      try { await navigator.clipboard.writeText(input.value); ev.target.textContent = 'Copiado'; } catch (e) {
        input.select();
        ev.target.textContent = 'Selecciónalo y cópialo';
      }
    });
    const form = $('.invite-form', el);
    if (form) wireInviteForm(form);
  }

  function wireInviteForm(form) {
    let kind = 'email';
    const err = $('.form-error', form);
    form.querySelectorAll('[data-kind]').forEach((b) => b.addEventListener('click', () => {
      kind = b.dataset.kind;
      form.querySelectorAll('[data-kind]').forEach((x) => {
        const on = x === b;
        x.classList.toggle('active', on);
        x.setAttribute('aria-checked', String(on));
      });
      $('[data-for="email"]', form).hidden = kind !== 'email';
      $('[data-for="link"]', form).hidden = kind !== 'link';
    }));
    form.role.addEventListener('change', () => { $('[data-role-help]', form).textContent = ROLE_HELP[form.role.value] + '.'; });
    form.addEventListener('submit', async (ev) => {
      ev.preventDefault();
      const body = { role: form.role.value, days: Number(form.days.value) };
      if (kind === 'email') {
        body.email = form.email.value.trim();
        if (!body.email || !form.email.checkValidity()) { err.textContent = 'Escribe un email válido.'; err.hidden = false; form.email.focus(); return; }
      } else {
        body.maxUses = Number(form.maxUses.value);
        if (!(body.maxUses >= 1 && body.maxUses <= 20)) { err.textContent = 'Un enlace vale de 1 a 20 usos.'; err.hidden = false; return; }
      }
      if (!(body.days >= 1 && body.days <= 30)) { err.textContent = 'La invitación caduca en 1 a 30 días.'; err.hidden = false; return; }
      const button = $('button[type="submit"]', form);
      button.disabled = true;
      try {
        const res = await apiPostJson(`${catPath(catalog)}/invites`, body);
        flash = { link: res.link, email: body.email || null, emailSent: res.emailSent };
        message = null;
        await load();
        const input = $('.copy-input', el);
        if (input) { input.focus(); input.select(); }
      } catch (e) {
        err.textContent = e.message;
        err.hidden = false;
        button.disabled = false;
      }
    });
  }

  async function changeRole(userId, select) {
    const m = team.data.members.find((x) => x.userId === userId);
    const role = select.value;
    if (!m || role === m.role) return;
    let ok = true;
    if (role === 'custodian') {
      ok = await confirmDialog({ key: 'dar-custodio', title: `Hacer custodio a ${m.name}`, confirmLabel: 'Hacer custodio',
        text: html`<p><strong>Verá las coordenadas exactas de todas las especies, también las amenazadas.</strong></p>
          <p>Queda registrado. Concédelo solo a quien necesite la ubicación precisa y sepa protegerla.</p>` });
    } else if (m.role === 'custodian') {
      ok = await confirmDialog({ key: 'quitar-custodio', title: `Quitar custodio a ${m.name}`, danger: true,
        confirmLabel: `Pasar a ${ROLE_LABELS[role].toLowerCase()}`,
        text: html`<p><strong>Sus dispositivos borrarán las coordenadas exactas en la próxima sincronización.</strong></p>
          <p>A partir de ahora verá las marcas ajenas generalizadas según el nivel de cada especie.</p>` });
    }
    if (!ok) { select.value = m.role; return; }
    select.disabled = true;
    try {
      await apiPutJson(`${catPath(catalog)}/members/${userId}`, { role });
      message = { kind: 'info', text: `${m.name} es ahora ${ROLE_LABELS[role].toLowerCase()}.` };
      await load();
      if (onChanged) onChanged();
    } catch (e) {
      message = { kind: 'warn', text: e.message };
      select.value = m.role;
      paint();
    }
  }

  async function expel(userId) {
    const m = team.data.members.find((x) => x.userId === userId);
    if (!m) return;
    const ok = await confirmDialog({ key: 'expulsar', title: `Expulsar a ${m.name}`, danger: true, confirmLabel: 'Expulsar',
      text: html`<p>Deja de tener acceso a «${catalog.name}». En su próxima sincronización el catálogo desaparece de sus
        dispositivos.</p><p>Sus marcas se quedan en el catálogo, con su autoría.</p>` });
    if (!ok) return;
    try {
      await apiDeleteJson(`${catPath(catalog)}/members/${userId}`);
      message = { kind: 'info', text: `${m.name} ya no es miembro del catálogo.` };
      await load();
      if (onChanged) onChanged();
    } catch (e) { message = { kind: 'warn', text: e.message }; paint(); }
  }

  async function revoke(inviteId) {
    const inv = invites.data.invites.find((x) => x.id === inviteId);
    const ok = await confirmDialog({ key: 'revocar', title: 'Revocar invitación', danger: true, confirmLabel: 'Revocar',
      text: html`<p>${inv && inv.email ? html`La invitación para ${inv.email}` : 'El enlace'} dejará de valer. Quien ya se unió sigue en el equipo.</p>` });
    if (!ok) return;
    try {
      await apiDeleteJson(`${catPath(catalog)}/invites/${inviteId}`);
      await load();
    } catch (e) { message = { kind: 'warn', text: e.message }; paint(); }
  }

  async function leave() {
    const pending = (await outboxEntries().catch(() => [])).filter((e) => e.catalogId === catalog.id).length;
    const ok = await confirmDialog({ key: 'salir', title: 'Salir del catálogo', danger: true, confirmLabel: 'Salir del catálogo',
      text: html`<p>Dejas de tener acceso a «${catalog.name}» y se borra de este dispositivo. Para volver necesitarás otra invitación.</p>
        ${pending ? html`<p class="guard-band warn">Tienes ${plural(pending, 'marca', 'marcas')} sin sincronizar de este catálogo.
          Se quedarán en el dispositivo para que las exportes (Ajustes › Sincronización); el servidor ya no las aceptará.</p>` : ''}` });
    if (!ok) return;
    try {
      if (pending) await syncNow();
      await apiPostJson(`${catPath(catalog)}/leave`);
      await handleNotMember(catalog.id);
      if (onLeft) onLeft();
    } catch (e) { message = { kind: 'warn', text: e.message }; paint(); }
  }

  function transfer() {
    const custodians = team.data.members.filter((m) => m.role === 'custodian');
    const ov = openOverlay({ key: 'transferir', title: 'Transferir la propiedad', variant: 'dialog' });
    render(ov.body, html`<form class="form" novalidate>
      <p class="dialog-text">La persona elegida pasa a ser <strong>propietaria</strong> de «${catalog.name}»: gestiona el equipo,
        los niveles y el registro. Tú quedas como <strong>custodio</strong>. Solo ella podrá devolvértelo.</p>
      <label class="field"><span>Nuevo propietario</span><select name="userId">${custodians.map((m) => html`
        <option value="${m.userId}">${m.name}</option>`)}</select></label>
      <label class="field"><span>Tu contraseña</span><input name="password" type="password" autocomplete="current-password" required></label>
      <p class="form-error" role="alert" hidden></p>
      <div class="form-actions dialog-actions">
        <button type="submit" class="btn-secondary danger">Transferir</button>
        <button type="button" class="link-btn" data-cancel>Cancelar</button>
      </div></form>`);
    const form = $('form', ov.body);
    const err = $('.form-error', form);
    $('[data-cancel]', form).addEventListener('click', () => ov.close(true));
    form.password.focus();
    form.addEventListener('submit', async (ev) => {
      ev.preventDefault();
      if (!form.password.value) { err.textContent = 'Escribe tu contraseña.'; err.hidden = false; return; }
      const button = $('button[type="submit"]', form);
      button.disabled = true;
      try {
        await apiPostJson(`${catPath(catalog)}/transfer`, { userId: Number(form.userId.value), password: form.password.value });
        ov.close(true);
        if (state.activeCatalog && state.activeCatalog.id === catalog.id) setActiveCatalog({ ...state.activeCatalog, role: 'custodian' });
        if (onLeft) onLeft();
      } catch (e) {
        err.textContent = e.message;
        err.hidden = false;
        button.disabled = false;
        form.password.select();
      }
    });
  }

  const unsubscribe = subscribe((key) => { if (key === 'online') { if (state.online && team && team.fromDevice) load(); else paint(); } });
  paint();
  load();
  return () => { alive = false; unsubscribe(); };
}

// ================================================================================================
// Niveles de sensibilidad (owner)
// ================================================================================================

/** Panel a pantalla completa: especie · sugerido · actual · motivo · autor del cambio, editable en línea. */
export function openLevels(catalog, { onChanged } = {}) {
  let alive = true;
  let data = null;         // {data: GET /catalogs/{id}, at, fromDevice}
  let levels = [];
  const ov = openOverlay({ key: 'niveles', title: `Niveles de sensibilidad · ${catalog.name}`, variant: 'full', className: 'team-panel',
    onClose: () => { alive = false; } });
  render(ov.body, html`<p class="muted">Cargando niveles…</p>`);

  async function load() {
    try {
      [data, levels] = await Promise.all([
        cachedRemote('levels:' + catalog.id, () => apiGetJson(catPath(catalog))),
        sensitivityLevels(),
      ]);
      paint();
    } catch (e) {
      if (alive) render(ov.body, html`<p class="guard-band warn">${e.offline ? 'Sin conexión y sin niveles guardados en este dispositivo.' : e.message}</p>`);
    }
  }

  const levelName = (n) => { const l = levels.find((x) => x.level === n); return l ? `${n} · ${l.name}` : String(n); };

  function paint() {
    if (!alive) return;
    const live = canAct(data.fromDevice);
    const rows = data.data.species;
    render(ov.body, html`
      <p class="hint">Nivel 1 = máxima protección (sin coordenadas para quien no es de confianza) · 4 = coordenada exacta para todo el equipo.
        Bajar un nivel respecto al sugerido exige motivo.</p>
      ${data.fromDevice || !state.online ? html`<p class="copy-note" role="status">${dataNote(data.at)} · ${OFFLINE_ACTION} para cambiar niveles</p>` : ''}
      <div class="table-scroll"><table class="data-table levels-table">
        <thead><tr><th scope="col">Especie</th><th scope="col">Sugerido</th><th scope="col">Actual</th>
          <th scope="col">Motivo</th><th scope="col">Autor del cambio</th><th scope="col"><span class="sr-only">Guardar</span></th></tr></thead>
        <tbody>${rows.map((sp) => {
          const s = sp.sensitivity || {};
          return html`<tr data-taxon="${sp.taxonID}">
            <th scope="row">${sciName(sp)}${sp.vernacularName ? html`<span class="muted table-sub">${sp.vernacularName}</span>` : ''}</th>
            <td>${s.suggestedLevel ? levelName(s.suggestedLevel) : '—'}</td>
            <td><select name="level" aria-label="Nivel de ${sp.canonicalName || sp.scientificName}" class="level-${s.level}" ${live ? '' : html`disabled`}>
              ${[1, 2, 3, 4].map((n) => html`<option value="${n}" ${n === s.level ? html`selected` : ''}>${levelName(n)}</option>`)}</select></td>
            <td><input name="reason" maxlength="255" value="${s.reason || ''}" aria-label="Motivo" ${live ? '' : html`disabled`}></td>
            <td>${s.confirmed ? html`${s.setBy || '—'}<span class="muted table-sub">${fmtDay(s.setAt)}</span>` : html`<span class="muted">Sugerido por BioDex</span>`}</td>
            <td><button type="button" class="link-btn" data-save hidden>Guardar</button><span class="form-error" role="alert" hidden></span></td>
          </tr>`;
        })}</tbody></table></div>`);
    ov.body.querySelectorAll('tr[data-taxon]').forEach(wireRow);
  }

  function wireRow(tr) {
    const sp = data.data.species.find((x) => x.taxonID === tr.dataset.taxon);
    const s = sp.sensitivity || {};
    const select = $('select', tr);
    const reason = $('input', tr);
    const save = $('[data-save]', tr);
    const err = $('.form-error', tr);
    const dirty = () => { save.hidden = Number(select.value) === s.level && reason.value.trim() === (s.reason || ''); };
    select.addEventListener('change', dirty);
    reason.addEventListener('input', dirty);
    save.addEventListener('click', async () => {
      const level = Number(select.value);
      const why = reason.value.trim();
      err.hidden = true;
      if (s.suggestedLevel && level > s.suggestedLevel && !why) {
        err.textContent = `Bajar respecto al sugerido (${s.suggestedLevel}) exige motivo.`;
        err.hidden = false;
        reason.focus();
        return;
      }
      if (level < s.level) {
        const ok = await confirmDialog({ key: 'subir-nivel', title: 'Subir el nivel de protección', confirmLabel: 'Subir nivel',
          text: html`<p>${sciName(sp)}: de ${levelName(s.level)} a ${levelName(level)}.</p>
            <p><strong>Los dispositivos del equipo sin confianza borrarán las coordenadas exactas de esta especie</strong> en su próxima sincronización.</p>` });
        if (!ok) return;
      }
      save.disabled = true;
      try {
        const res = await apiPutJson(`${catPath(catalog)}/species/${encodeURIComponent(sp.taxonID)}/sensitivity`, { level, reason: why });
        sp.sensitivity = res.sensitivity;
        paint();
        if (onChanged) onChanged();
      } catch (e) {
        err.textContent = e.message;
        err.hidden = false;
        save.disabled = false;
      }
    });
  }

  load();
  return ov;
}

// ================================================================================================
// Registro (owner)
// ================================================================================================

/** Panel a pantalla completa: fecha · persona · acción · detalle, de lo más reciente a lo más antiguo. */
export function openAudit(catalog) {
  let alive = true;
  let data = null;         // {data: {items, hasMore, before}, at, fromDevice}
  let more = [];           // páginas siguientes (no se guardan en el dispositivo)
  let hasMore = false;
  let before = null;
  const ov = openOverlay({ key: 'registro', title: `Registro · ${catalog.name}`, variant: 'full', className: 'team-panel',
    onClose: () => { alive = false; } });
  render(ov.body, html`<p class="muted">Cargando registro…</p>`);

  async function load() {
    try {
      data = await cachedRemote('audit:' + catalog.id, () => apiGetJson(`${catPath(catalog)}/audit?limit=50`));
      hasMore = data.data.hasMore;
      before = data.data.before;
      more = [];
      paint();
    } catch (e) {
      if (alive) render(ov.body, html`<p class="guard-band warn">${e.offline ? 'Sin conexión y sin registro guardado en este dispositivo.' : e.message}</p>`);
    }
  }

  async function loadMore(btn) {
    btn.disabled = true;
    try {
      const res = await apiGetJson(`${catPath(catalog)}/audit?limit=50&before=${before}`);
      more = more.concat(res.items);
      hasMore = res.hasMore;
      before = res.before;
      paint();
    } catch (e) { btn.disabled = false; btn.textContent = e.message; }
  }

  function paint() {
    if (!alive) return;
    const items = data.data.items.concat(more);
    render(ov.body, html`
      <p class="hint">Vistas de coordenadas exactas, cambios de nivel y de rol, invitaciones y altas y bajas del equipo.</p>
      ${data.fromDevice || !state.online ? html`<p class="copy-note" role="status">${dataNote(data.at)}</p>` : ''}
      ${items.length ? html`<div class="table-scroll"><table class="data-table audit-table">
        <thead><tr><th scope="col">Fecha</th><th scope="col">Persona</th><th scope="col">Acción</th><th scope="col">Detalle</th></tr></thead>
        <tbody>${items.map((r) => html`<tr>
          <td class="nowrap">${fmtDateTime(r.at)}</td>
          <td>${r.userName || html`<span class="muted">—</span>`}</td>
          <td><span class="badge action-${r.action}">${AUDIT_LABELS[r.action] || r.action}</span></td>
          <td>${r.detail}</td></tr>`)}</tbody></table></div>` : html`<p class="muted">Aún no hay nada en el registro.</p>`}
      ${hasMore && canAct(data.fromDevice) ? html`<div class="form-actions"><button type="button" class="btn-secondary" data-more>Cargar más</button></div>` : ''}`);
    const btn = $('[data-more]', ov.body);
    if (btn) btn.addEventListener('click', () => loadMore(btn));
  }

  load();
  return ov;
}
