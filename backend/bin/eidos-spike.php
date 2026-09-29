<?php
// ================================================================================================
// eidos-spike.php — validación del cliente EIDOS (F1) contra la API real.
//   docker compose exec php php bin/eidos-spike.php
// 1) Resuelve 9 taxones de prueba, ejecuta cada función y mide tiempos.
// 2) Guarda una muestra recortada (máx. 3 filas por vista) en docs/eidos-samples/<especie>.json.
// 3) Recoge la evidencia de las preguntas de docs/eidos.md en docs/eidos-samples/_evidencias.json.
// ================================================================================================

require_once __DIR__ . "/../Lib/eidos.php";

const SPECIES = [
    "Artemisia granatensis", "Atropa baetica", "Parnassius apollo", "Lynx pardinus", "Aquila adalberti",
    "Turdus merula",           // trampa subespecie
    "Capra pyrenaica",         // trampa renumeración
    "Rosmarinus officinalis",  // trampa sinónimo (hoy Salvia rosmarinus)
    "Inventadus falsus",       // no existe
];
const SAMPLE_ROWS = 3;
const SAMPLE_STR = 300;

$outDir = realpath(__DIR__ . "/../../docs") ?: "/var/www/docs";
$outDir .= "/eidos-samples";
if (!is_dir($outDir)) mkdir($outDir, 0775, true);

/** Ejecuta y cronometra; nunca deja escapar la excepción (se anota como error). */
function timed(callable $fn): array {
    $t = microtime(true);
    try { $value = $fn(); $error = null; }
    catch (Throwable $e) { $value = null; $error = get_class($e) . ": " . $e->getMessage(); }
    return ["ms" => (int) round((microtime(true) - $t) * 1000), "value" => $value, "error" => $error];
}

/** Recorta filas y textos para que las muestras pesen poco. */
function trim_sample($data, int $rows = SAMPLE_ROWS, bool $dropEmpty = true) {
    if (is_array($data) && array_is_list($data)) $data = array_slice($data, 0, $rows);
    // En las filas (no en las listas) se omiten los campos null/"": EIDOS trae muchos vacíos.
    elseif (is_array($data) && $dropEmpty) $data = array_filter($data, fn($v) => $v !== null && $v !== "");
    if (is_array($data)) return array_map(fn($v) => trim_sample($v, $rows, $dropEmpty), $data);
    if (is_string($data) && mb_strlen($data) > SAMPLE_STR) return mb_substr($data, 0, SAMPLE_STR) . "…[+" . (mb_strlen($data) - SAMPLE_STR) . "]";
    return $data;
}

function slug(string $s): string {
    return trim(preg_replace('/[^a-z0-9]+/', "-", strtolower($s)), "-");
}

/** HEAD/GET con curl; devuelve status, cabeceras elegidas y tamaño. */
function probe(string $url, bool $head = true, array $headers = []): array {
    $got = [];
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_NOBODY => $head, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20,
        CURLOPT_FOLLOWLOCATION => true, CURLOPT_HTTPHEADER => $headers,
        CURLOPT_HEADERFUNCTION => function ($ch, $line) use (&$got) {
            if (preg_match('/^(content-type|content-length|access-control-[a-z-]+|content-range):\s*(.*)$/i', trim($line), $m)) $got[strtolower($m[1])] = $m[2];
            return strlen($line);
        },
    ]);
    $body = curl_exec($ch);
    $res = ["url" => $url, "status" => curl_getinfo($ch, CURLINFO_RESPONSE_CODE), "headers" => $got];
    if (!$head) $res["bytes"] = strlen((string) $body);
    if (!$head) $res["body"] = $body;
    curl_close($ch);
    return $res;
}

// ------------------------------------------------------------------------------------------------
// 1. Los nueve taxones
// ------------------------------------------------------------------------------------------------
echo "== Taxones de prueba ==\n";
printf("%-24s %-9s %-7s %-34s %-22s %-8s %4s %4s %5s %3s %6s\n",
    "nombre", "estado", "taxonid", "aceptado", "nombre común", "UICN-ES", "leg", "nac", "desc", "img", "ms");

$summary = [];
foreach (SPECIES as $sci) {
    $res = timed(fn() => eidosResolveTaxon($sci));
    $r = $res["value"];
    $row = ["name" => $sci, "resolve" => ["ms" => $res["ms"], "error" => $res["error"], "value" => $r]];
    $sample = ["query" => $sci, "resolve" => $row["resolve"]];
    $total = $res["ms"];

    if ($r) {
        $id = $r["taxonid"];
        $calls = [
            "conservation" => fn() => eidosConservation($id),
            "legal"        => fn() => eidosLegal($id, "Andalucía"),
            "commonName"   => fn() => eidosCommonName($id),
            "description"  => fn() => eidosDescription($id),
            "images"       => fn() => eidosImages($id),
        ];
        foreach ($calls as $name => $fn) {
            $row[$name] = timed($fn);
            $total += $row[$name]["ms"];
        }
        // Filas crudas por vista (máx. 3), para ver columnas reales.
        $sample["raw"] = [
            "v_taxonomia"             => eidosRows("v_taxonomia", ["taxonid" => "eq.$id", "limit" => SAMPLE_ROWS]),
            "v_estadoconservacion"    => eidosRows("v_estadoconservacion", ["idtaxon" => "eq.$id", "limit" => SAMPLE_ROWS]),
            "v_estadolegal"           => eidosRows("v_estadolegal", ["idtaxon" => "eq.$id", "limit" => SAMPLE_ROWS]),
            "v_nombrescomunes"        => eidosRows("v_nombrescomunes", ["idtaxon" => "eq.$id", "limit" => SAMPLE_ROWS]),
            "v_atributos_pliniancore" => eidosRows("v_atributos_pliniancore", ["idtaxon" => "eq.$id", "limit" => SAMPLE_ROWS]),
            "v_imagenes"              => eidosRows("v_imagenes", ["id_taxon" => "eq.$id", "limit" => SAMPLE_ROWS]),
        ];
        foreach (array_keys($calls) as $name) $sample[$name] = $row[$name];
    }
    $sample["totalMs"] = $total;
    file_put_contents("$outDir/" . slug($sci) . ".json",
        json_encode(trim_sample($sample), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

    $cons = $row["conservation"]["value"] ?? [];
    printf("%-24s %-9s %-7s %-34s %-22s %-8s %4s %4s %5s %3s %6d%s\n",
        $sci,
        $r["status"] ?? "null",
        $r["taxonid"] ?? "-",
        mb_strimwidth((string) ($r["acceptedName"] ?? "-"), 0, 34, "…"),
        mb_strimwidth((string) ($row["commonName"]["value"] ?? "-"), 0, 22, "…"),
        ($cons["España"]["code"] ?? "-") . "/" . ($cons["Mundial"]["code"] ?? "-"),
        count($row["legal"]["value"]["vigentes"] ?? []),
        count($row["legal"]["value"]["nacional"] ?? []),
        mb_strlen((string) ($row["description"]["value"] ?? "")),
        count($row["images"]["value"] ?? []),
        $total,
        ($r["rankMismatch"] ?? false) ? "  [rango distinto]" : "");
    $summary[$sci] = $row;
}

// ------------------------------------------------------------------------------------------------
// 2. Evidencias para docs/eidos.md
// ------------------------------------------------------------------------------------------------
echo "\n== Evidencias ==\n";
$ev = [];
$lynx = $summary["Lynx pardinus"]["resolve"]["value"]["taxonid"] ?? 11943;

/** Consulta con total (Prefer: count=exact) y tiempo. */
function counted(string $view, array $params, string $base = "especie"): array {
    $t = microtime(true);
    try {
        [$rows, $total] = eidosRequest($view, $params + ["limit" => 3], $base, true);
        return ["ms" => (int) round((microtime(true) - $t) * 1000), "total" => $total, "first" => trim_sample($rows, 3)];
    } catch (Throwable $e) {
        return ["ms" => (int) round((microtime(true) - $t) * 1000), "error" => $e->getMessage()];
    }
}

// Trampas de resolución: el nombre inverso del sinónimo y las subespecies
$ev["trampas_resolucion"] = [];
foreach (["Salvia rosmarinus", "Capra pyrenaica victoriae", "Turdus merula cabrerae", "Turdus merula subsp. cabrerae"] as $n) {
    $r = eidosResolveTaxon($n);
    $ev["trampas_resolucion"][$n] = $r ? array_intersect_key($r, array_flip(["status", "taxonid", "acceptedName", "synonymName", "rankMismatch"])) + ["rank" => $r["taxon"]["taxonrank"]] : null;
}

// Q1 Clasificación
$ev["q1_clasificacion"] = eidosRows("v_taxonomia", [
    "taxonid" => "eq.$lynx", "nametype" => "eq." . EIDOS_ACCEPTED,
    "select" => "kingdomid,kingdom,phylumid,phylum,classid,class,ordertaxonomicid,ordertaxonomic,familyid,family,genusid,genus,taxonrank,taxonomicgroup",
]);

// Q2 Búsqueda
$ev["q2_busqueda"] = [
    "name_ilike"        => counted("v_taxonomia", ["name" => "ilike.*pardinus*", "nametype" => "eq." . EIDOS_ACCEPTED, "select" => "taxonid,name"]),
    "name_like_case"    => counted("v_taxonomia", ["name" => "like.*PARDINUS*", "select" => "taxonid,name"]),
    "comun_ilike"       => counted("v_nombrescomunes", ["nombre_comun" => "ilike.*lince*", "idioma" => "eq.Castellano", "select" => "idtaxon,nombre_comun"]),
    "comun_sin_acento"  => counted("v_nombrescomunes", ["nombre_comun" => "ilike.*aguila imperial*", "select" => "idtaxon,nombre_comun"]),
    "comun_con_acento"  => counted("v_nombrescomunes", ["nombre_comun" => "ilike.*águila imperial*", "select" => "idtaxon,nombre_comun"]),
    "paginacion_offset" => counted("v_taxonomia", ["name" => "ilike.aquila*", "select" => "taxonid,name", "order" => "name.asc", "offset" => 3]),
];
$lat = [];
foreach (["lince", "aquila", "romero", "apollo", "salvia"] as $q) {
    $r = timed(fn() => eidosSearch($q, [], 20));
    $lat[$q] = ["ms" => $r["ms"], "total" => $r["value"]["total"] ?? null, "truncated" => $r["value"]["truncated"] ?? null,
                "error" => $r["error"], "top3" => array_slice($r["value"]["items"] ?? [], 0, 3)];
}
$ev["q2_eidosSearch"] = $lat;
$ev["q2_eidosSearch_media_ms"] = (int) round(array_sum(array_column($lat, "ms")) / count($lat));

// Q3 Filtros
$areas = [];
foreach (["T", "M", "MT", "C"] as $a) $areas[$a] = counted("v_taxonomia", ["areacode" => "eq.$a", "nametype" => "eq." . EIDOS_ACCEPTED, "select" => "name,areacode"])["total"] ?? null;
$origins = [];
foreach (eidosRows("v_taxonomia", ["select" => "origen", "origen" => "not.is.null", "limit" => 5000, "nametype" => "eq." . EIDOS_ACCEPTED]) as $r) $origins[$r["origen"]] = true;
$ev["q3_filtros"] = [
    "grupo_aves"          => counted("v_taxonomia", ["taxonomicgroup" => "eq.Aves", "nametype" => "eq." . EIDOS_ACCEPTED, "select" => "taxonid,name"]),
    "grupo_y_nombre"      => counted("v_taxonomia", ["taxonomicgroup" => "eq.Aves", "name" => "ilike.*aquila*", "nametype" => "eq." . EIDOS_ACCEPTED, "select" => "taxonid,name"]),
    "origen_nativa"       => counted("v_taxonomia", ["origen" => "eq.Especie nativa", "nametype" => "eq." . EIDOS_ACCEPTED, "select" => "taxonid,name"]),
    "origen_valores_5000" => array_keys($origins),
    "areacode_totales"    => $areas,
    "legal_rd139"         => counted("v_estadolegal", ["norma" => "ilike.*139/2011*", "idvigente" => "eq.1", "select" => "idtaxon,estadolegal"]),
    "legal_habitats"      => counted("v_estadolegal", ["norma" => "ilike.*92/43*", "idvigente" => "eq.1", "select" => "idtaxon,estadolegal"]),
    "legal_aves"          => counted("v_estadolegal", ["norma" => "ilike.*2009/147*", "idvigente" => "eq.1", "select" => "idtaxon,estadolegal"]),
    "legal_rd630"         => counted("v_estadolegal", ["norma" => "ilike.*630/2013*", "idvigente" => "eq.1", "select" => "idtaxon,estadolegal"]),
    "legal_ue1143"        => counted("v_estadolegal", ["norma" => "ilike.*1143/2014*", "idvigente" => "eq.1", "select" => "idtaxon,estadolegal"]),
    "embedding_vistas"    => counted("v_taxonomia", ["taxonid" => "eq.$lynx", "select" => "name,v_estadolegal(estadolegal)"]),
    "patron_normas_combinado" => counted("v_listapatronespecie_normas",
        ["Normativa" => "ilike.*139/2011*", "ScientificName" => "ilike.*aquila*", "select" => "idtaxon,ScientificName,Normativa,Categoría"], "catalogo"),
    "patron_grupo_entrecomillado" => counted("v_listapatronespecie",
        ['"Grupo taxonómico"' => "eq.Aves", "select" => "idtaxon,ScientificName,Environment"], "catalogo"),
    "patron_environment" => counted("v_listapatronespecie", ["Environment" => "not.is.null", "select" => "idtaxon,ScientificName,Environment"], "catalogo"),
];
$cats = [];
foreach (["LC", "NT", "VU", "EN", "CR", "EX", "RE", "EW", "DD", "NE", "FV", "U1", "U2", "XX"] as $c) {
    $r = counted("v_estadoconservacion", ["categoriaconservacion" => "like.$c *", "vigencia" => "eq.Vigente", "select" => "idtaxon,categoriaconservacion,autoridad,aplicaa"]);
    $cats[$c] = ["total" => $r["total"] ?? null, "literal" => $r["first"][0]["categoriaconservacion"] ?? null,
                 "autoridad" => $r["first"][0]["autoridad"] ?? null, "ms" => $r["ms"], "error" => $r["error"] ?? null];
}
$ev["q3_categorias"] = $cats;

// Q4 Imágenes
$imgs = $summary["Lynx pardinus"]["images"]["value"] ?? [];
$ev["q4_imagenes"] = [
    "columnas" => array_keys(eidosRows("v_imagenes", ["id_taxon" => "eq.$lynx", "limit" => 1])[0] ?? []),
    "https"    => $imgs ? probe($imgs[0]["url"]) : null,
    "http"     => $imgs ? probe(str_replace("https://", "http://", $imgs[0]["url"])) : null,
    "por_especie" => array_map(fn($s) => count($s["images"]["value"] ?? []), $summary),
];

// Q5 Descripción
$ev["q5_descripcion"] = array_map(fn($s) => isset($s["description"]["value"]) ? [
    "chars" => mb_strlen($s["description"]["value"]),
    "html"  => $s["description"]["value"] !== strip_tags($s["description"]["value"]),
    "saltos_linea" => substr_count($s["description"]["value"], "\n"),
] : null, $summary);

// Q6 Ficha web
$fichaBase = "https://iepnb.gob.es/areas-tematicas/especies-silvestres/eidos/";
$f1 = probe($fichaBase . $lynx, false);
$f2 = probe($fichaBase . "999999999", false);
$ev["q6_ficha"] = [
    "head_sin_slug" => probe($fichaBase . $lynx),
    "get_sin_slug"  => ["status" => $f1["status"], "bytes" => $f1["bytes"], "menciona_pardinus" => substr_count($f1["body"], "pardinus")],
    "get_inexistente" => ["status" => $f2["status"], "bytes" => $f2["bytes"], "menciona_pardinus" => substr_count($f2["body"], "pardinus")],
];

// Q7 CORS
$ev["q7_cors"] = probe(EIDOS_HOST . "/especie/v_taxonomia?taxonid=eq.$lynx&select=name", true, ["Origin: http://localhost:8090"]);

// Q8 Literales de sensibilidad
$ev["q8_literales_legales"] = array_values(array_unique(array_column(
    eidosRows("v_estadolegal", ["estadolegal" => "like.CEEA*", "select" => "estadolegal", "limit" => 2000]), "estadolegal")));
$ev["q8_literales_lesrpe"] = array_values(array_unique(array_column(
    eidosRows("v_estadolegal", ["estadolegal" => "like.LESRPE*", "select" => "estadolegal", "limit" => 200]), "estadolegal")));
$ev["q8_literales_uicn"] = array_map(fn($c) => $c["literal"], $cats);

// Q9 rpc/obtenertaxonespornombre
$t = microtime(true);
try {
    $rpc = eidosRows("rpc/obtenertaxonespornombre", ["_nombretaxon" => "Lynx pardinus"]);
    $ev["q9_rpc"] = ["ms" => (int) round((microtime(true) - $t) * 1000), "filas" => count($rpc),
                     "columnas" => array_keys($rpc[0] ?? []), "muestra" => trim_sample($rpc, 2)];
} catch (Throwable $e) {
    $ev["q9_rpc"] = ["error" => $e->getMessage()];
}

// Q10 Especie inexistente (y un idtaxon que no existe)
$ghost = 999999999;
$ev["q10_inexistente"] = [
    "eidosResolveTaxon('Inventadus falsus')" => timed(fn() => eidosResolveTaxon("Inventadus falsus")),
    "eidosConservation($ghost)" => timed(fn() => eidosConservation($ghost)),
    "eidosLegal($ghost)"        => timed(fn() => eidosLegal($ghost)),
    "eidosCommonName($ghost)"   => timed(fn() => eidosCommonName($ghost)),
    "eidosDescription($ghost)"  => timed(fn() => eidosDescription($ghost)),
    "eidosImages($ghost)"       => timed(fn() => eidosImages($ghost)),
    "eidosSearch('Inventadus falsus')" => timed(fn() => eidosSearch("Inventadus falsus")),
    "columna_erronea"           => timed(fn() => eidosRows("v_imagenes", ["idtaxon" => "eq.$lynx"])),
];

file_put_contents("$outDir/_evidencias.json",
    json_encode(trim_sample($ev, 5, false), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

// Resumen breve en consola
foreach ($ev["q2_eidosSearch"] as $q => $r) printf("buscar %-8s %5d ms  total=%s%s\n", $q, $r["ms"], $r["total"] ?? "?", $r["error"] ? "  ERROR " . $r["error"] : "");
foreach ($ev["q3_filtros"] as $k => $r) if (is_array($r) && isset($r["ms"])) printf("filtro %-28s %5d ms  total=%s%s\n", $k, $r["ms"], $r["total"] ?? "?", isset($r["error"]) ? "  ERROR " . mb_strimwidth($r["error"], 0, 90, "…") : "");
foreach ($ev["q10_inexistente"] as $k => $r) printf("q10 %-40s %s\n", $k, $r["error"] ? "EXCEPCIÓN " . mb_strimwidth($r["error"], 0, 80, "…") : json_encode($r["value"]));

$bytes = array_sum(array_map("filesize", glob("$outDir/*.json")));
printf("\nMuestras en docs/eidos-samples: %d ficheros, %.1f KB\n", count(glob("$outDir/*.json")), $bytes / 1024);
