// ================================================================================================
// components/qr.js — código QR de una invitación (F11e), para unir gente al equipo ya en el campo.
// ------------------------------------------------------------------------------------------------
// El QR lleva el enlace #/unirse/<código> entero: quien lo escanea con la cámara del móvil abre BioDex,
// entra o crea su cuenta (app.js guarda el código mientras tanto, ver views/join.js) y se une.
// Generador: vendor/qrcode-generator (MIT, script clásico que deja window.qrcode; sin red, precacheado).
// Se pinta como SVG con un solo <path> (sin style="" ni data: URL: la CSP sigue intacta) y siempre en
// negro sobre blanco, también en el tema oscuro: los lectores fallan con los colores invertidos.
// ================================================================================================

import { html, render, $ } from '../util.js';
import { openOverlay } from './overlay.js';

const QUIET = 4;   // margen blanco en módulos (lo que pide la norma)

/** Matriz de módulos de `text` (corrección M: aguanta una pantalla con reflejos). */
export function qrMatrix(text, generator = window.qrcode) {
  if (typeof generator !== 'function') throw new Error('Falta el generador de QR (vendor/qrcode-generator).');
  const qr = generator(0, 'M');
  qr.addData(String(text));
  qr.make();
  const n = qr.getModuleCount();
  const rows = [];
  for (let y = 0; y < n; y++) {
    const row = [];
    for (let x = 0; x < n; x++) row.push(qr.isDark(y, x));
    rows.push(row);
  }
  return rows;
}

/** Trazado SVG de la matriz: una «h» por tramo horizontal de módulos oscuros. */
export function qrPath(matrix, quiet = QUIET) {
  let d = '';
  matrix.forEach((row, y) => {
    let x = 0;
    while (x < row.length) {
      if (!row[x]) { x++; continue; }
      let end = x;
      while (end < row.length && row[end]) end++;
      d += `M${x + quiet} ${y + quiet}h${end - x}v1h${x - end}z`;
      x = end;
    }
  });
  return d;
}

/** html`` con el SVG del QR de `text`. */
export function qrSvg(text, label = 'Código QR') {
  const m = qrMatrix(text);
  const size = m.length + QUIET * 2;
  return html`<svg class="qr-code" viewBox="0 0 ${size} ${size}" role="img" aria-label="${label}" shape-rendering="crispEdges"
    xmlns="http://www.w3.org/2000/svg"><rect width="${size}" height="${size}" fill="#FFFFFF"/>
    <path d="${qrPath(m)}" fill="#000000"/></svg>`;
}

/**
 * Diálogo con el QR de una invitación, a tamaño de escanear.
 *   link        enlace de la invitación
 *   catalog     {name}
 *   role        rol con el que se entra (texto ya traducido)
 *   uses        usos que admite (o null)
 */
export function openInviteQr({ link, catalog, role = '', uses = null }) {
  const ov = openOverlay({ key: 'qr-invitacion', title: 'Invitar con QR', variant: 'dialog', className: 'qr-dialog' });
  let qr;
  try { qr = qrSvg(link, `Código QR de la invitación a «${catalog.name}»`); } catch (e) { qr = null; }
  const canShare = typeof navigator.share === 'function';
  render(ov.body, html`
    <p class="qr-catalog">${catalog.name}</p>
    ${qr ? html`<div class="qr-box">${qr}<p>Escanéalo con la cámara del móvil</p></div>`
      : html`<p class="guard-band warn" role="alert">No se ha podido generar el QR. Comparte el enlace.</p>`}
    <p class="hint">${role ? `Entrará como ${role.toLowerCase()}` : ''}${role && uses ? ' · ' : ''}${uses ? `vale para ${uses} ${uses === 1 ? 'persona' : 'personas'}` : ''}.
      Quien lo escanee necesita cobertura para crear su cuenta y descargar el catálogo.</p>
    <div class="copy-row"><input class="copy-input" readonly value="${link}" aria-label="Enlace de invitación">
      <button type="button" class="btn-secondary" data-copy>Copiar</button></div>
    <div class="form-actions dialog-actions">
      ${canShare ? html`<button type="button" class="btn-secondary" data-share>Compartir enlace</button>` : ''}
      <button type="button" class="btn-primary" data-close>Hecho</button>
    </div>`);
  $('[data-close]', ov.body).addEventListener('click', () => ov.close(true));
  $('[data-copy]', ov.body).addEventListener('click', async (ev) => {
    const input = $('.copy-input', ov.body);
    try { await navigator.clipboard.writeText(input.value); ev.target.textContent = 'Copiado'; } catch (e) {
      input.select();
      ev.target.textContent = 'Selecciónalo y cópialo';
    }
  });
  const share = $('[data-share]', ov.body);
  if (share) share.addEventListener('click', () => {
    navigator.share({ title: `BioDex · ${catalog.name}`, text: `Únete a «${catalog.name}» en BioDex`, url: link }).catch(() => {});
  });
  $('[data-close]', ov.body).focus();
  return ov;
}
