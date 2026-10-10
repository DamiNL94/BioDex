// ================================================================================================
// components/species-notes.js — notas de cuaderno de una especie en el catálogo activo (F8a).
// ------------------------------------------------------------------------------------------------
// Lista cronológica con autor y fecha; cada uno añade, edita y borra las suyas (sin red también: van
// a IndexedDB y a la cola, y sync.js las sube). Las del equipo llegan con el pull. Añadir exige un rol
// que pueda marcar (el push lo pide); el lector solo las ve. El texto viene de usuarios: html``.
// ================================================================================================

import { state, subscribe } from '../state.js';
import { html, render, $, formatDateTime, SYNC_BADGES } from '../util.js';
import { confirmDialog } from './overlay.js';
import { createNote, updateNote, deleteNote, notesFor, NOTE_MAX, MARK_ROLES } from '../marks.js';
import { requestSync } from '../sync.js';

/** Pinta en `el` las notas de `species` en `catalog` ({id, name, role}). Devuelve {refresh, destroy}. */
export function mountNotes(el, { species, catalog }) {
  let alive = true;
  let notes = [];
  let editing = null;   // noteID en edición
  const canWrite = MARK_ROLES.includes(catalog.role);
  const me = () => state.user && state.user.id;

  async function refresh() {
    try { notes = await notesFor(catalog.id, species.taxonID); } catch (e) { return; }
    if (!alive) return;
    // No se repinta bajo los dedos: con algo escrito en el formulario, solo la lista.
    const draft = $('textarea[name="newNote"]', el);
    const keep = draft ? draft.value : '';
    render(el, html`
      ${notes.length ? html`<ol class="notes">${notes.map((n) => {
        const mine = n.authorId === me();
        const [label, kind] = SYNC_BADGES[n.status] || [];
        if (editing === n.noteID) {
          return html`<li class="note is-editing"><form class="form note-edit" data-note="${n.noteID}" novalidate>
            <label class="field"><span>Editar nota</span><textarea name="text" rows="4" maxlength="${NOTE_MAX}">${n.text}</textarea></label>
            <p class="form-error" role="alert" hidden></p>
            <div class="form-actions"><button type="submit" class="btn-primary">Guardar nota</button>
              <button type="button" class="link-btn" data-cancel-edit>Cancelar</button></div></form></li>`;
        }
        return html`<li class="note">
          <div class="note-head"><span class="mark-author">${mine ? 'Tú' : n.recordedBy || 'Otro miembro'}</span>
            <time class="mark-when" datetime="${n.clientUpdatedAt}">${formatDateTime(n.clientUpdatedAt)}</time>
            ${label ? html`<span class="badge ${kind}">${label}</span>` : ''}</div>
          <p class="note-text">${n.text}</p>
          ${n.status === 'rejected' && n.reason ? html`<p class="hint">${n.reason}</p>` : ''}
          ${mine ? html`<div class="form-actions note-actions">
            <button type="button" class="link-btn" data-edit-note="${n.noteID}">Editar</button>
            <button type="button" class="link-btn danger-link" data-del-note="${n.noteID}">Borrar</button></div>` : ''}
        </li>`;
      })}</ol>` : html`<p class="muted">Sin notas de esta especie en «${catalog.name}».</p>`}
      ${canWrite ? html`<form class="form note-new" novalidate>
        <label class="field"><span>Nueva nota</span>
          <textarea name="newNote" rows="3" maxlength="${NOTE_MAX}" placeholder="Localidades a revisar, amenazas vistas, dudas de identificación…"></textarea></label>
        <p class="form-error" role="alert" hidden></p>
        <div class="form-actions"><button type="submit" class="btn-secondary">Añadir nota</button></div>
      </form>` : html`<p class="hint">Tu rol en «${catalog.name}» solo permite leer las notas.</p>`}`);
    const nd = $('textarea[name="newNote"]', el);
    if (nd && keep) nd.value = keep;
    const ed = $('.note-edit textarea', el);
    if (ed) ed.focus();
  }

  function fail(form, e) {
    const err = $('.form-error', form);
    err.textContent = e.message;
    err.hidden = false;
  }

  el.addEventListener('submit', async (ev) => {
    ev.preventDefault();
    const form = ev.target;
    try {
      if (form.classList.contains('note-new')) {
        await createNote({ catalogId: catalog.id, taxonID: species.taxonID, text: form.newNote.value });
        form.newNote.value = '';
      } else if (form.classList.contains('note-edit')) {
        await updateNote(form.dataset.note, form.text.value);
        editing = null;
      }
    } catch (e) { fail(form, e); return; }
    requestSync();
    refresh();
  });

  el.addEventListener('click', async (ev) => {
    const edit = ev.target.closest('[data-edit-note]');
    if (edit) { editing = edit.dataset.editNote; refresh(); return; }
    if (ev.target.closest('[data-cancel-edit]')) { editing = null; refresh(); return; }
    const del = ev.target.closest('[data-del-note]');
    if (!del) return;
    const ok = await confirmDialog({ key: 'borrar-nota', title: 'Borrar nota', danger: true, confirmLabel: 'Borrar nota',
      text: html`<p>Se borra la nota para todo el equipo en cuanto se sincronice.</p>` });
    if (!ok) return;
    await deleteNote(del.dataset.delNote);
    requestSync();
    refresh();
  });

  // Tras cada sincronización llegan las del equipo; no se repinta si estás editando una.
  const unsubscribe = subscribe((key) => { if (key === 'sync' && !state.sync.running && !editing) refresh(); });
  refresh();
  return { refresh, destroy: () => { alive = false; unsubscribe(); } };
}
