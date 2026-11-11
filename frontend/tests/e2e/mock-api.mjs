// ================================================================================================
// mock-api.mjs — API de BioDex simulada para Playwright, a partir de respuestas reales de la demo
// grabadas en fixtures/demo-owner.json (catálogo «DEMO — Flora amenazada de Sierra Nevada», datos
// ficticios; la usuaria es la propietaria). Guarda en memoria lo que la prueba cambia: marcas subidas
// (push) y parámetros (PUT/DELETE), con su server_seq, y el pull los devuelve como el servidor. También sitios y
// visitas (push y pull), y el orden en que llegan (`batches`) para comprobar que van antes que sus marcas.
// Lo que no está simulado responde 404 JSON (nunca 5xx: la app lo tomaría por servidor caído).
// ================================================================================================
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const here = path.dirname(fileURLToPath(import.meta.url));
export const FX = JSON.parse(fs.readFileSync(path.join(here, 'fixtures', 'demo-owner.json'), 'utf8'));
export const CATALOG_ID = FX.catalog.catalog.id;

/** Instala la simulación en el contexto. → estado (para comprobar lo que llegó al «servidor»). */
export async function mockApi(context) {
  const st = {
    seq: Math.max(0, ...FX.pull.items.map((i) => i.serverSeq || 0), FX.pull.cursor || 0) + 1000,
    params: new Map(),     // `${taxonID}|${key}` → parámetro (con deleted)
    pushed: [],            // marcas recibidas
    impacts: [],           // Impactos recibidos (el pull los devuelve con su server_seq)
    sites: [],             // Sitios recibidos
    visits: [],            // Visitas recibidas (la última versión de cada una)
    batches: [],           // Por push, {sites, visits, occurrences} (ids) en el orden recibido
    calls: [],
  };
  const me = FX.me.user;
  const json = (route, body, status = 200) => route.fulfill({ status, contentType: 'application/json', body: JSON.stringify(body) });

  await context.route('**/api/**', async (route) => {
    const req = route.request();
    const url = new URL(req.url());
    const p = url.pathname.replace(/^\/api/, '');
    const m = req.method();
    st.calls.push(`${m} ${p}`);
    let r;
    if (p === '/health') return json(route, { ok: true });
    if (p === '/auth/me') return json(route, FX.me);
    if (p === '/catalogs' && m === 'GET') return json(route, FX.catalogs);
    if (p === '/species/filters') return json(route, FX.filters);
    if ((r = p.match(/^\/species\/([^/]+)$/)) && m === 'GET') {
      const sp = FX.species[decodeURIComponent(r[1])];
      return sp ? json(route, sp) : json(route, { error: 'No encontrada' }, 404);
    }
    if ((r = p.match(/^\/catalogs\/([0-9a-f-]{36})(\/.*)?$/))) {
      const rest = r[2] || '';
      if (rest === '' && m === 'GET') return json(route, FX.catalog);
      if (rest === '/tree') return json(route, FX.tree);
      if (rest === '/offline') return json(route, FX.offline);
      if (rest === '/members') return json(route, FX.members);
      if (rest === '/invites') return json(route, FX.invites);
      if (rest.startsWith('/audit')) return json(route, FX.audit);
      const pm = rest.match(/^\/species\/([^/]+)\/parameters(?:\/([a-z0-9_]+))?$/);
      if (pm) return parameters(route, decodeURIComponent(pm[1]), pm[2], m, req);
      // La evaluación del servidor no se simula: sin respuesta, la app calcula la provisional en el
      // dispositivo con shared/iucn-quantifiers.js (lo que se prueba es ese camino).
      if (/^\/species\/[^/]+\/assessment$/.test(rest)) return route.abort('internetdisconnected');
    }
    if (p === '/sync/pull') return pull(route, Number(url.searchParams.get('since') || 0));
    if (p === '/sync/push' && m === 'POST') return push(route, req.postDataJSON());
    return json(route, { error: `Sin simular: ${m} ${p}` }, 404);
  });

  function parameters(route, taxonID, key, method, req) {
    if (!key) {
      const list = [...st.params.values()].filter((x) => x.taxonID === taxonID && !x.deleted);
      const base = FX.parameters[taxonID] || { defaults: { separation_distance: null } };
      return json(route, { taxonID, parameters: list, defaults: base.defaults, canEdit: true });
    }
    const id = `${taxonID}|${key}`;
    if (method === 'DELETE') {
      const old = st.params.get(id);
      if (!old || old.deleted) return json(route, { taxonID, parameter: null, changed: false });
      st.params.set(id, { taxonID, key, serverSeq: ++st.seq, setAt: new Date().toISOString(), deleted: true });
      return json(route, { taxonID, parameter: null, changed: true });
    }
    const b = req.postDataJSON() || {};
    const num = (v) => (v === null || v === undefined || v === '' ? null : Number(String(v).replace(',', '.')));
    const numeric = !['mature_definition', 'life_form'].includes(key);
    const param = { taxonID, key, serverSeq: ++st.seq, setAt: new Date().toISOString(), deleted: false,
      value: numeric ? num(b.value) : b.value, valueMin: numeric ? num(b.valueMin) : null, valueMax: numeric ? num(b.valueMax) : null,
      quality: b.quality || null, source: b.source || null, setById: me.id, setBy: me.name };
    st.params.set(id, param);
    return json(route, { taxonID, parameter: param, changed: true });
  }

  function pull(route, since) {
    const base = since === 0 ? FX.pull : { ...FX.pull, items: [], notes: [], assessments: [] };
    const extra = [...st.params.values()].filter((x) => x.serverSeq > since);
    const impacts = st.impacts.filter((x) => x.serverSeq > since);
    const sites = st.sites.filter((x) => x.serverSeq > since);
    const visits = st.visits.filter((x) => x.serverSeq > since);
    const cursor = Math.max(since, FX.pull.cursor || 0, ...extra.map((x) => x.serverSeq), ...st.pushed.map((x) => x.serverSeq),
      ...impacts.map((x) => x.serverSeq), ...sites.map((x) => x.serverSeq), ...visits.map((x) => x.serverSeq));
    return json(route, { ...base, parameters: extra, impacts, locations: [], sites, visits, cursor, hasMore: false });
  }

  function push(route, body) {
    st.batches.push({ sites: (body.sites || []).map((x) => x.siteID), visits: (body.visits || []).map((x) => x.visitID),
      occurrences: (body.occurrences || []).map((x) => x.occurrenceID) });
    const keep = (list, idKey, rec) => [...list.filter((x) => x[idKey] !== rec[idKey]), rec];
    const siteResults = (body.sites || []).map((x) => {
      const serverSeq = ++st.seq;
      st.sites = keep(st.sites, 'siteID', { ...x, userId: me.id, recordedBy: me.name, serverSeq, deleted: false, generalized: false });
      return { siteID: x.siteID, status: 'accepted', serverSeq };
    });
    const visitResults = (body.visits || []).map((x) => {
      const serverSeq = ++st.seq;
      st.visits = keep(st.visits, 'visitID', { ...x, userId: me.id, recordedBy: me.name, serverSeq, deleted: false, generalized: false });
      return { visitID: x.visitID, status: 'accepted', serverSeq };
    });
    const results = (body.occurrences || []).map((o) => {
      const serverSeq = ++st.seq;
      st.pushed.push({ ...o, serverSeq });
      return { occurrenceID: o.occurrenceID, status: 'accepted', serverSeq };
    });
    const ok = (list, id) => (list || []).map((x) => ({ [id]: x[id], status: 'accepted', serverSeq: ++st.seq }));
    return json(route, { results: [...results, ...(body.deletes || []).map((d) => ({ occurrenceID: d.occurrenceID, status: 'accepted', serverSeq: ++st.seq }))],
      noteResults: [...ok(body.notes, 'noteID'), ...ok(body.noteDeletes, 'noteID')],
      assessmentResults: [...ok(body.assessments, 'assessmentID'), ...ok(body.assessmentDeletes, 'assessmentID')],
      siteResults, visitResults,
      impactResults: (body.impacts || []).map((i) => {
        const serverSeq = ++st.seq;
        st.impacts = st.impacts.filter((x) => x.impactID !== i.impactID);
        st.impacts.push({ ...i, userId: me.id, recordedBy: me.name, serverSeq, deleted: false, generalized: false });
        return { impactID: i.impactID, status: 'accepted', serverSeq };
      }) });
  }

  return st;
}
