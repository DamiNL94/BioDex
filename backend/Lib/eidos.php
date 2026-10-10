<?php
// ================================================================================================
// eidos.php — cliente de EIDOS (Banco de Datos de la Naturaleza, IEPNB/MITECO).
// Portado de NaturDex (Tools/lib/eidos.js), con las mismas trampas resueltas. Funciones puras: sin BD
// ni caché (la caché en la tabla `species` está en Lib/species.php). Solo se llama desde el backend.
// ------------------------------------------------------------------------------------------------
// API PostgREST pública y SIN autenticación sobre https://iepnb.gob.es/api/, con dos esquemas:
//   /api/especie/    v_taxonomia · v_estadoconservacion · v_estadolegal · v_nombrescomunes ·
//                    v_atributos_pliniancore · v_imagenes
//   /api/catalogo/   Lista Patrón y tablas de valores (v_listapatronespecie_normas, ...)
//
// TRAMPAS (ver docs/eidos.md):
//   · El identificador del taxón se llama `taxonid` en v_taxonomia, `idtaxon` en las vistas de
//     detalle e `id_taxon` (¡texto!) en v_imagenes.
//   · Filtrar por una columna que no existe NO da error HTTP: PostgREST responde 200 con un OBJETO
//     {code, message, hint}. eidosRows() lo convierte en excepción; nunca devuelve algo que no sea
//     una lista de filas.
//   · Los nombres vienen CON autor ("Lynx pardinus (Temminck, 1825)"): se empareja por prefijo
//     terminado en espacio y se desempata por el rango que pide el nombre (Turdus merula).
//   · Nombres comunes en las cuatro lenguas del Estado (y inglés, francés...): castellano o null.
//   · Normas autonómicas servidas con `ambito: "Nacional"` (Ley 9/1999 de Castilla-La Mancha):
//     nacional = ámbito Nacional Y `ccaa` vacío.
// ================================================================================================

require_once __DIR__ . "/../Config/env.php";

// EIDOS_BASE_URL permite apuntar a un doble de pruebas (los tests no dependen de que EIDOS responda).
define("EIDOS_HOST", rtrim(biodex_env(["EIDOS_BASE_URL"]) ?? "https://iepnb.gob.es/api", "/"));
// Pausa entre peticiones: es un servicio público y un catálogo son cientos de peticiones.
const EIDOS_THROTTLE_MS = 150;
const EIDOS_TIMEOUT_S = 10;
const EIDOS_ACCEPTED = "Aceptado/válido";

class EidosException extends RuntimeException {}

/**
 * Petición a una vista. Devuelve [filas, total|null]. `total` solo viene si se pide
 * `Prefer: count=exact` (se lee de la cabecera Content-Range: "0-19/1234").
 */
function eidosRequest(string $view, array $params, string $base = "especie", bool $count = false): array {
    static $lastCall = 0.0;
    $wait = EIDOS_THROTTLE_MS / 1000 - (microtime(true) - $lastCall);
    if ($wait > 0) usleep((int) ($wait * 1e6));

    // RFC 3986: los espacios van como %20; PostgREST no traduce "+" a espacio.
    $url = EIDOS_HOST . "/{$base}/{$view}" . ($params ? "?" . http_build_query($params, "", "&", PHP_QUERY_RFC3986) : "");
    $headers = ["Accept: application/json"];
    if ($count) $headers[] = "Prefer: count=exact";

    $contentRange = null;
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => EIDOS_TIMEOUT_S,
        CURLOPT_CONNECTTIMEOUT => EIDOS_TIMEOUT_S,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_USERAGENT      => "BioDex/0.1 (+backend)",
        CURLOPT_HEADERFUNCTION => function ($ch, $line) use (&$contentRange) {
            if (stripos($line, "Content-Range:") === 0) $contentRange = trim(substr($line, 14));
            return strlen($line);
        },
    ]);
    $body = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    $lastCall = microtime(true);

    if ($body === false) throw new EidosException("EIDOS {$view}: {$error}");

    $data = json_decode($body, true);
    if ($status >= 400 || !is_array($data) || !array_is_list($data)) {
        $msg = is_array($data) ? ($data["message"] ?? $data["hint"] ?? $data["details"] ?? null) : null;
        throw new EidosException("EIDOS {$view} (HTTP {$status}): " . ($msg ?? substr((string) $body, 0, 200)));
    }

    $total = null;
    if ($contentRange !== null && preg_match('#/(\d+)$#', $contentRange, $m)) $total = (int) $m[1];
    return [$data, $total];
}

/** GET de una vista de EIDOS, garantizando un ARRAY de filas (ver trampa del objeto de error). */
function eidosRows(string $view, array $params, string $base = "especie"): array {
    return eidosRequest($view, $params, $base)[0];
}

// --- Emparejamiento de nombres ------------------------------------------------------------------

function eidosNorm(?string $s): string {
    return preg_replace('/\s+/u', " ", mb_strtolower(trim((string) $s)));
}

/** eidosNorm sin diacríticos: «Águila» → «aguila», «Ñu» → «nu». Para comparar como la colación _ai_. */
function eidosFold(?string $s): string {
    $s = eidosNorm($s);
    if (class_exists("Normalizer")) $s = Normalizer::normalize($s, Normalizer::FORM_D);
    return preg_replace('/\p{Mn}+/u', "", $s);
}

/**
 * Patrón POSIX para `imatch` que ignora acentos: «aguila» → «[aáàâä]g[uúùûü][iíìîï]l[aáàâä]».
 * El `ilike` de EIDOS distingue acentos; el comodín `_` de LIKE funciona, pero trae mucho ruido
 * («_g__l_» casa con «Pata de gallo»: 546 nombres frente a 20). Ver docs/eidos.md, «Búsqueda sin acentos».
 */
function eidosAccentPattern(string $q): string {
    static $classes = [
        "a" => "[aáàâä]", "e" => "[eéèêë]", "i" => "[iíìîï]", "o" => "[oóòôö]", "u" => "[uúùûü]",
        "n" => "[nñ]", "c" => "[cç]",
    ];
    $out = "";
    foreach (mb_str_split(eidosFold($q)) as $ch) {
        $out .= $classes[$ch] ?? (preg_match('/[\p{L}\p{N} \'-]/u', $ch) ? $ch : "");
    }
    return $out;
}

/** "Aquila fasciata Vieillot, 1822" casa con "Aquila fasciata"; nunca se trunca a dos palabras. */
function eidosNameMatches(?string $eidosName, string $sci): bool {
    $n = eidosNorm($eidosName);
    $s = eidosNorm($sci);
    return $n === $s || str_starts_with($n, $s . " ");
}

/**
 * Rango que pide el nombre. Sin esto, "Turdus merula" casa igual de bien con la especie que con
 * "Turdus merula subsp. cabrerae" (el mirlo canario), y EIDOS lista primero la subespecie.
 */
function eidosRequestedRank(string $sci): string {
    $s = eidosNorm($sci) . " ";
    if (preg_match('/\b(subsp|ssp|var|subvar|f|forma|cv)\.?\s/i', $s)) {
        if (preg_match('/\bvar\.?\s/i', $s)) return "Variety";
        if (preg_match('/\b(f|forma)\.?\s/i', $s)) return "Form";
        return "Subspecies";
    }
    $words = count(array_filter(explode(" ", trim($s))));
    if ($words >= 3) return "Subspecies";   // trinomio sin marca: «Capra pyrenaica pyrenaica»
    if ($words === 2) return "Species";
    return "Genus";
}

function eidosRankMatches(?string $rowRank, ?string $requested): bool {
    return !$rowRank || !$requested || eidosNorm($rowRank) === eidosNorm($requested);
}

/**
 * Resuelve un nombre científico contra la nomenclatura de EIDOS. Devuelve null si EIDOS no lo
 * conoce, o:
 *   status        'ok' (nombre aceptado) | 'sinonimo' (taxonid/acceptedName apuntan al aceptado)
 *   taxonid       int, la clave maestra (taxonID de BioDex para especies de EIDOS)
 *   taxon         fila de v_taxonomia del nombre emparejado
 *   acceptedName, synonymName
 *   rankMismatch  true si lo devuelto NO es del rango que pedía el nombre (es lo único que hay)
 */
function eidosResolveTaxon(string $sci): ?array {
    $sci = preg_replace('/\s+/', " ", trim($sci));
    if ($sci === "") return null;
    $select = "taxonid,nameid,acceptednameid,name,taxonrank,nametype,genus,specificepithet,"
            . "scientificnameauthorship,taxonomicgroup,origen,kingdom,phylum,class,ordertaxonomic,family";
    $rows = eidosRows("v_taxonomia", ["name" => "like." . $sci . "*", "select" => $select]);
    // EIDOS no es coherente con los trinomios: «Capra pyrenaica victoriae» va sin marca, pero
    // «Turdus merula subsp. cabrerae» la lleva. Sin resultados, se reintenta con «subsp.».
    $words = explode(" ", $sci);
    if (!$rows && count($words) === 3 && !preg_match('/\b(subsp|ssp|var|f)\./i', $sci)) {
        $sci = "{$words[0]} {$words[1]} subsp. {$words[2]}";
        $rows = eidosRows("v_taxonomia", ["name" => "like." . $sci . "*", "select" => $select]);
    }
    // Filas huérfanas (taxonid null, nametype null): nombres sin taxón asignado, p. ej. el
    // «Lynx pardinus (Temminck, 1827)» duplicado. No identifican nada.
    $rows = array_values(array_filter($rows, fn($r) => $r["taxonid"] !== null));
    if (!$rows) return null;

    // El rango pedido manda sobre el orden de EIDOS y sobre si la fila es aceptada o sinónimo.
    $requested = eidosRequestedRank($sci);
    $byName = array_values(array_filter($rows, fn($r) => eidosNameMatches($r["name"], $sci)));
    $withRank = array_values(array_filter($byName, fn($r) => eidosRankMatches($r["taxonrank"], $requested)));
    $candidates = $withRank ?: ($byName ?: $rows);

    foreach ($candidates as $r) {
        if ($r["nametype"] === EIDOS_ACCEPTED) {
            return [
                "status" => "ok", "taxonid" => (int) $r["taxonid"], "taxon" => $r,
                "acceptedName" => $r["name"], "synonymName" => null,
                "rankMismatch" => !eidosRankMatches($r["taxonrank"], $requested),
            ];
        }
    }

    // Ninguna aceptada: el nombre es un sinónimo. Su taxonid YA es el del taxón al que pertenece
    // (EIDOS agrupa todos los nombres bajo el mismo); la segunda consulta da el nombre aceptado y su
    // rango. El rango que cuenta es el del ACEPTADO: «Capra pyrenaica victoriae» es hoy sinónimo de
    // la especie, así que lo devuelto ya no es la subespecie que se pidió (rankMismatch).
    $syn = $candidates[0];
    $accepted = null;
    try {
        foreach (eidosRows("v_taxonomia", ["taxonid" => "eq." . $syn["taxonid"], "select" => "name,nametype,taxonrank"]) as $r) {
            if ($r["nametype"] === EIDOS_ACCEPTED) { $accepted = $r; break; }
        }
    } catch (EidosException) { /* se informa del sinónimo sin el aceptado */ }

    return [
        "status" => "sinonimo", "taxonid" => (int) $syn["taxonid"], "taxon" => $syn,
        "acceptedName" => $accepted["name"] ?? null, "synonymName" => $syn["name"],
        "rankMismatch" => !eidosRankMatches($accepted["taxonrank"] ?? $syn["taxonrank"], $requested),
    ];
}

/** "CR (En peligro crítico)" → "CR". Vale para UICN (LC…EX) y para Art. 17 (FV, U1, U2, XX). */
function eidosCategoryCode(?string $label): ?string {
    return ($label !== null && preg_match('/^([A-Z][A-Z0-9]{1,2})\b/', trim($label), $m)) ? $m[1] : null;
}

/**
 * Categoría UICN vigente por ámbito (`aplicaa`: Mundial, España, Península, Canarias...).
 * Se queda con la MÁS RECIENTE de cada ámbito: EIDOS acumula evaluaciones históricas todas
 * marcadas «Vigente» (el lince tiene EN-1992 y CR-2007 para España).
 * @return array<string, array{code:?string, label:string, year:?int, criteria:?string}>
 */
function eidosConservation(int $idtaxon): array {
    $rows = eidosRows("v_estadoconservacion", [
        "idtaxon" => "eq." . $idtaxon,
        "select"  => "categoriaconservacion,autoridad,aplicaa,anio,vigencia,criterios",
    ]);
    $byScope = [];
    foreach ($rows as $r) {
        if ($r["vigencia"] !== "Vigente" || $r["autoridad"] !== "UICN") continue;
        $scope = $r["aplicaa"] ?: "?";
        if (!isset($byScope[$scope]) || ($r["anio"] ?? 0) >= ($byScope[$scope]["year"] ?? 0)) {
            $byScope[$scope] = [
                "code"     => eidosCategoryCode($r["categoriaconservacion"]),
                "label"    => $r["categoriaconservacion"],
                "year"     => $r["anio"] !== null ? (int) $r["anio"] : null,
                "criteria" => $r["criterios"] ?: null,
            ];
        }
    }
    return $byScope;
}

/** Prioridad de ámbito para la categoría UICN principal: el más específico que haya. */
const EIDOS_IUCN_SCOPE_PRIORITY = ["Península", "España", "Mundial"];

/**
 * Categoría UICN principal a partir de la salida de eidosConservation(): la vigente más reciente del
 * ámbito más específico disponible (Península > España > Mundial). Si no hay ninguno de los tres, la
 * más reciente de cualquier otro ámbito (Canarias...). null si no hay evaluaciones.
 * @return array{code:?string, scope:string, year:?int}|null
 */
function eidosPrimaryIucn(array $byScope): ?array {
    foreach (EIDOS_IUCN_SCOPE_PRIORITY as $scope) {
        if (isset($byScope[$scope])) {
            return ["code" => $byScope[$scope]["code"], "scope" => $scope, "year" => $byScope[$scope]["year"]];
        }
    }
    $best = null;
    foreach ($byScope as $scope => $a) {
        if ($best === null || ($a["year"] ?? 0) > ($best["year"] ?? 0)) {
            $best = ["code" => $a["code"], "scope" => (string) $scope, "year" => $a["year"]];
        }
    }
    return $best;
}

/** Columnas de v_taxonomia que BioDex guarda en `species` (clasificación con ids estables). */
const EIDOS_TAXON_SELECT = "taxonid,name,taxonrank,nametype,scientificnameauthorship,genus,specificepithet,"
    . "infraspecificepithet,taxonomicgroup,origen,areacode,kingdom,kingdomid,phylum,phylumid,class,classid,"
    . "ordertaxonomic,ordertaxonomicid,family,familyid";

/** Fila ACEPTADA de v_taxonomia de un taxonid (con EIDOS_TAXON_SELECT) y sus sinónimos. null si no existe. */
function eidosTaxon(int $idtaxon): ?array {
    $rows = eidosRows("v_taxonomia", ["taxonid" => "eq." . $idtaxon, "select" => EIDOS_TAXON_SELECT]);
    $accepted = null;
    $synonyms = [];
    foreach ($rows as $r) {
        if ($r["nametype"] === EIDOS_ACCEPTED) $accepted ??= $r;
        elseif ($r["name"]) $synonyms[] = $r["name"];
    }
    if ($accepted === null) return null;
    $accepted["synonyms"] = array_values(array_unique($synonyms));
    return $accepted;
}

/**
 * Clasificación (reino…familia, con ids) de un género según EIDOS, o null. Las filas de rango Genus
 * suelen ser huérfanas (taxonid null), pero su clasificación es válida; si no hay fila del género,
 * se toma la de cualquier taxón aceptado de ese género.
 */
function eidosGenusClassification(string $genus): ?array {
    $genus = trim($genus);
    if (!preg_match('/^\p{Lu}\p{Ll}+$/u', $genus)) return null;
    $select = "name,taxonrank,kingdom,kingdomid,phylum,phylumid,class,classid,ordertaxonomic,ordertaxonomicid,family,familyid";
    $rows = eidosRows("v_taxonomia", ["name" => "like.{$genus}*", "taxonrank" => "eq.Genus", "select" => $select]);
    $rows = array_values(array_filter($rows, fn($r) => eidosNameMatches($r["name"], $genus) && $r["kingdom"]));
    if (!$rows) {
        $rows = eidosRows("v_taxonomia", [
            "genus" => "eq.{$genus}", "nametype" => "eq." . EIDOS_ACCEPTED, "select" => $select, "limit" => 1,
        ]);
    }
    return $rows[0] ?? null;
}

/**
 * ¿La norma de esta fila se aplica en la península, o solo en Canarias/Baleares? El RD 139/2011
 * tiene una columna «Población referida» que EIDOS sirve en `observacionespoblacion`: sin mirarla,
 * *Charadrius alexandrinus* sale «CEEA Vulnerable» por la población canaria.
 */
function eidosAppliesOnPeninsula(array $row): bool {
    $obs = eidosNorm($row["observacionespoblacion"] ?? null);
    $islands = "(?:canarias|baleares|illes balears|ibiza|formentera)";
    return $obs === "" || !preg_match("/^(?:ámbito de aplicación:\\s*)?{$islands}(?:\\s*(?:,|y|e)\\s*{$islands})*$/iu", $obs);
}

/**
 * Estado legal vigente (`idvigente` = 1):
 *   vigentes    todas las filas vigentes
 *   nacional    ámbito Nacional Y sin ccaa (las filas de Castilla-La Mancha llegan como «Nacional»)
 *   peninsular  las de `nacional` que se aplican en la península (ver eidosAppliesOnPeninsula)
 *   autonomico  las de la comunidad `$ccaa` (por `ccaa` o por el texto de la norma); [] si null
 */
function eidosLegal(int $idtaxon, ?string $ccaa = "Andalucía"): array {
    $rows = eidosRows("v_estadolegal", [
        "idtaxon" => "eq." . $idtaxon,
        "select"  => "estadolegal,norma,ambito,ccaa,idvigente,observacionespoblacion",
    ]);
    $vigentes = array_values(array_filter($rows, fn($r) => (int) $r["idvigente"] === 1));
    $nacional = array_values(array_filter($vigentes, fn($r) => $r["ambito"] === "Nacional" && empty($r["ccaa"])));
    $autonomico = [];
    if ($ccaa !== null && $ccaa !== "") {
        $needle = eidosNorm($ccaa);
        $autonomico = array_values(array_filter($vigentes, fn($r) =>
            str_contains(eidosNorm($r["ccaa"]), $needle) || str_contains(eidosNorm($r["norma"]), $needle)));
    }
    return [
        "vigentes"   => $vigentes,
        "nacional"   => $nacional,
        "peninsular" => array_values(array_filter($nacional, "eidosAppliesOnPeninsula")),
        "autonomico" => $autonomico,
    ];
}

/** Nombre común preferente en castellano, o null. Nunca otro idioma (EIDOS da catalán, euskera...). */
function eidosCommonName(int $idtaxon): ?string {
    $rows = eidosRows("v_nombrescomunes", [
        "idtaxon" => "eq." . $idtaxon,
        "select"  => "nombre_comun,idioma,espreferente",
    ]);
    $es = array_values(array_filter($rows, fn($r) => !$r["idioma"] || preg_match('/castellano|espa/i', $r["idioma"])));
    foreach ($es as $r) if ($r["espreferente"]) return $r["nombre_comun"];
    return $es[0]["nombre_comun"] ?? null;
}

/** Texto del atributo «Descripción de la especie» (vigente), o null. Texto plano. */
function eidosDescription(int $idtaxon): ?string {
    $rows = eidosRows("v_atributos_pliniancore", [
        "idtaxon"  => "eq." . $idtaxon,
        "atributo" => "eq.Descripción de la especie",
        "select"   => "valor_atributo,fuente,idvigente,prioritario",
    ]);
    usort($rows, fn($a, $b) => ((int) $a["idvigente"] !== 1) <=> ((int) $b["idvigente"] !== 1));
    foreach ($rows as $r) {
        $text = trim((string) $r["valor_atributo"]);
        if ($text !== "") return $text;
    }
    return null;
}

/**
 * Fotos de la fototeca del MITECO. `ruta_foto` llega SIN esquema ("www.miteco.gob.es/..."); v_imagenes
 * no trae autor ni licencia (null: la tarjeta cita «Fototeca MITECO» como fuente).
 * @return list<array{url:string, id:int, priority:int, description:?string, author:null, license:null}>
 */
function eidosImages(int $idtaxon): array {
    $rows = eidosRows("v_imagenes", [
        "id_taxon" => "eq." . $idtaxon,   // columna de texto, y con otro nombre
        "select"   => "id_foto,es_prioridad,descripcion,ruta_foto",
        "order"    => "es_prioridad.desc,id_foto.asc",
    ]);
    $out = [];
    foreach ($rows as $r) {
        $path = trim((string) $r["ruta_foto"]);
        if ($path === "") continue;
        $out[] = [
            "url"         => preg_match('#^https?://#i', $path) ? $path : "https://" . ltrim($path, "/"),
            "id"          => (int) $r["id_foto"],
            "priority"    => (int) $r["es_prioridad"],
            "description" => $r["descripcion"],
            "author"      => null,
            "license"     => null,
        ];
    }
    return $out;
}

// --- Búsqueda -----------------------------------------------------------------------------------

/** Filtros que EIDOS resuelve en servidor (columna de v_taxonomia). Legal y categoría: caché (Api/species.php). */
const EIDOS_SEARCH_FILTERS = [
    "group"  => "taxonomicgroup",   // "Aves", "Plantas vasculares"...
    "origin" => "origen",           // "Especie nativa", "Especie introducida"...
    "area"   => "areacode",         // ámbito: ver docs/eidos.md
    "rank"   => "taxonrank",        // "Species", "Subspecies"...
];
// Tope de candidatos por vía (científico / común) antes de unir y paginar.
const EIDOS_SEARCH_CAP = 200;

/**
 * Búsqueda por nombre científico Y común (castellano), solo taxones aceptados. Pagina sobre la
 * unión de las dos vías, así que el total está acotado a 2 × EIDOS_SEARCH_CAP (`truncated`).
 * @return array{total:int, truncated:bool, items:list<array>}
 */
function eidosSearch(string $q, array $filters = [], int $limit = 20, int $offset = 0): array {
    // Sin acentos ni caracteres con significado en PostgREST o en una regex (ver eidosAccentPattern).
    $pattern = eidosAccentPattern(trim(preg_replace('/\s+/u', " ", $q)));
    if (mb_strlen(trim($pattern)) < 2) return ["total" => 0, "truncated" => false, "items" => []];

    $taxFilters = [];
    foreach ($filters as $key => $value) {
        if (!isset(EIDOS_SEARCH_FILTERS[$key])) throw new InvalidArgumentException("Filtro EIDOS no soportado: {$key}");
        $taxFilters[EIDOS_SEARCH_FILTERS[$key]] = "eq." . $value;
    }
    $base = ["nametype" => "eq." . EIDOS_ACCEPTED, "taxonid" => "not.is.null", "select" => EIDOS_TAXON_SELECT] + $taxFilters;

    // Vía 1: nombre científico.
    [$sciRows, $sciTotal] = eidosRequest("v_taxonomia",
        ["name" => "imatch.{$pattern}", "order" => "name.asc", "limit" => EIDOS_SEARCH_CAP] + $base, "especie", true);

    // Vía 2: nombre común en castellano → idtaxon → taxón aceptado (con los mismos filtros).
    $common = eidosRows("v_nombrescomunes", [
        "nombre_comun" => "imatch.{$pattern}", "idioma" => "eq.Castellano",
        "select" => "idtaxon", "limit" => EIDOS_SEARCH_CAP,
    ]);
    $ids = array_values(array_unique(array_map(fn($r) => (int) $r["idtaxon"], $common)));
    $comRows = $ids ? eidosRows("v_taxonomia", ["taxonid" => "in.(" . implode(",", $ids) . ")"] + $base) : [];

    // Unión por taxonid; primero los que empiezan por el texto buscado (comparando sin acentos).
    $byId = [];
    foreach (array_merge($sciRows, $comRows) as $r) $byId[(int) $r["taxonid"]] ??= $r;
    $needle = eidosFold($q);
    uasort($byId, fn($a, $b) =>
        [!str_starts_with(eidosFold($a["name"]), $needle), $a["name"]] <=> [!str_starts_with(eidosFold($b["name"]), $needle), $b["name"]]);
    $page = array_slice(array_values($byId), $offset, $limit);
    $truncated = ($sciTotal ?? 0) > EIDOS_SEARCH_CAP || count($common) >= EIDOS_SEARCH_CAP;
    if (!$page) return ["total" => count($byId), "truncated" => $truncated, "items" => []];

    // Enriquecer la página: nombre común y evaluaciones UICN vigentes, en dos peticiones.
    $pageIds = implode(",", array_map(fn($r) => (int) $r["taxonid"], $page));
    $names = [];
    foreach (eidosRows("v_nombrescomunes", [
        "idtaxon" => "in.({$pageIds})", "idioma" => "eq.Castellano",
        "select" => "idtaxon,nombre_comun,espreferente", "order" => "espreferente.desc",
    ]) as $r) $names[(int) $r["idtaxon"]] ??= $r["nombre_comun"];

    // Por taxón y ámbito, la más reciente (mismo criterio que eidosConservation).
    $iucn = [];
    foreach (eidosRows("v_estadoconservacion", [
        "idtaxon" => "in.({$pageIds})", "autoridad" => "eq.UICN", "vigencia" => "eq.Vigente",
        "select" => "idtaxon,categoriaconservacion,aplicaa,anio,criterios",
    ]) as $r) {
        $scope = $r["aplicaa"] ?: "?";
        $prev = $iucn[(int) $r["idtaxon"]][$scope] ?? null;
        if ($prev === null || ($r["anio"] ?? 0) >= ($prev["year"] ?? 0)) {
            $iucn[(int) $r["idtaxon"]][$scope] = [
                "code" => eidosCategoryCode($r["categoriaconservacion"]), "label" => $r["categoriaconservacion"],
                "year" => $r["anio"] !== null ? (int) $r["anio"] : null, "criteria" => $r["criterios"] ?: null,
            ];
        }
    }

    // Tarjeta (F4): foto prioritaria y descripción, otras dos peticiones por lotes (~0,2 s cada una).
    $photos = [];
    foreach (eidosRows("v_imagenes", [
        "id_taxon" => "in.({$pageIds})", "select" => "id_taxon,id_foto,es_prioridad,descripcion,ruta_foto",
        "order" => "es_prioridad.desc,id_foto.asc", "limit" => 500,
    ]) as $r) {
        $path = trim((string) $r["ruta_foto"]);
        if ($path === "" || isset($photos[(int) $r["id_taxon"]])) continue;
        $photos[(int) $r["id_taxon"]] = [
            "url" => preg_match('#^https?://#i', $path) ? $path : "https://" . ltrim($path, "/"),
            "id" => (int) $r["id_foto"], "priority" => (int) $r["es_prioridad"], "description" => $r["descripcion"],
        ];
    }
    $descriptions = [];
    $descRows = eidosRows("v_atributos_pliniancore", [
        "idtaxon" => "in.({$pageIds})", "atributo" => "eq.Descripción de la especie",
        "select" => "idtaxon,valor_atributo,idvigente",
    ]);
    usort($descRows, fn($a, $b) => ((int) $a["idvigente"] !== 1) <=> ((int) $b["idvigente"] !== 1));
    foreach ($descRows as $r) {
        $text = trim((string) $r["valor_atributo"]);
        if ($text !== "") $descriptions[(int) $r["idtaxon"]] ??= $text;
    }

    $items = [];
    foreach ($page as $r) {
        $id = (int) $r["taxonid"];
        $items[] = [
            "taxonID"         => (string) $id,
            "scientificName"  => $r["name"],
            "vernacularName"  => $names[$id] ?? null,
            "group"           => $r["taxonomicgroup"],
            "taxonRank"       => $r["taxonrank"],
            "iucn"            => eidosPrimaryIucn($iucn[$id] ?? []),
            "iucnAssessments" => $iucn[$id] ?? [],
            "photo"           => $photos[$id] ?? null,
            "description"     => $descriptions[$id] ?? null,
            "taxon"           => $r,   // fila de v_taxonomia (EIDOS_TAXON_SELECT) para la caché
        ];
    }
    return ["total" => count($byId), "truncated" => $truncated, "items" => $items];
}
