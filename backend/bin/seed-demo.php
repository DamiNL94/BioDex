<?php
// ================================================================================================
// Datos de demostración: docker compose exec php php bin/seed-demo.php
// Solo con APP_ENV=dev. Idempotente: si el catálogo de demo ya existe, no toca nada y vuelve a imprimir
// las cuentas. Para empezar de cero: bin/reset-demo.php.
//
// Además, 4 sitios con visitas anuales 2014–2026 de la Plantago nivalis (serie con declive: EN por A2).
// Crea 3 cuentas verificadas (owner, custodio, editor) y el catálogo DEMO_CATALOG_NAME con 10 especies (12 desde F11g)
// reales de EIDOS de Sierra Nevada y su nivel sugerido, confirmado por la owner. Después, 60 marcas (73 desde F11g)
// FICTICIAS de los tres (presencias, 5 ausencias, individuos y maduros, 2 marcadas en el mapa), notas de
// especie y la evaluación UICN de referencia de la Artemisia (EN B1ab(iii)+2ab(iii) con el EOO/AOO que
// calcula el servidor). Casi todo pasa por la API (nginx), como lo haría la app: así quedan los
// server_seq, el access_log y los niveles igual que en uso real.
// Necesita red para traer las fichas de EIDOS la primera vez (luego salen de species_cache).
// ================================================================================================

require_once __DIR__ . "/demo/demo-lib.php";
require_once __DIR__ . "/../Lib/api.php";        // uuidV4()
require_once __DIR__ . "/test-lib.php";          // http()

demoRequireDev();
$pdo = db();
runMigrations($pdo);

// Especies (taxonID de EIDOS), localidades ficticias [lat, lon] y hábitat. La Artemisia va aparte, con
// puntos fijos: su EOO/AOO (62,455 km² / 12 km², 3 celdas) es el de la evaluación de referencia.
const ARTEMISIA = "9918";
const DEMO_SPECIES = [
    ARTEMISIA => ["habitat" => "Pastizal psicroxerófilo sobre esquistos", "stage" => "flowering"],
    "2723" => ["name" => "Atropa baetica", "locs" => [[37.1250, -3.4450], [37.0980, -3.2050]], "present" => 6, "absent" => 1,
               "habitat" => "Claro de pinar con nitrificación, junto a arroyo"],
    "7171" => ["name" => "Plantago nivalis", "locs" => [[37.0750, -3.3700], [37.0560, -3.3000]], "present" => 7, "absent" => 1,
               "habitat" => "Borreguil húmedo"],
    "9990" => ["name" => "Erodium boissieri", "locs" => [[37.0850, -3.3500]], "present" => 4, "absent" => 1,
               "habitat" => "Pastizal pedregoso"],
    "9392" => ["name" => "Viola crassiuscula", "locs" => [[37.0580, -3.3600]], "present" => 5, "absent" => 0,
               "habitat" => "Canchal de esquistos"],
    "8242" => ["name" => "Sempervivum minutum", "locs" => [[37.0900, -3.3900]], "present" => 5, "absent" => 0,
               "habitat" => "Fisuras de roca"],
    "5676" => ["name" => "Laserpitium longiradium", "locs" => [[37.1100, -3.4100]], "present" => 4, "absent" => 0,
               "habitat" => "Orla de acequia de alta montaña"],
    "2337" => ["name" => "Arenaria nevadensis", "locs" => [[37.0620, -3.3550]], "present" => 4, "absent" => 0,
               "habitat" => "Pedregal de cumbres"],
    "546"  => ["name" => "Narcissus nevadensis", "locs" => [[37.1400, -3.3000]], "present" => 4, "absent" => 1,
               "habitat" => "Prado húmedo de ribera"],
    "6989" => ["name" => "Pinguicula nevadensis", "locs" => [[37.0700, -3.3200]], "present" => 5, "absent" => 0,
               "habitat" => "Rezumadero sobre esquistos"],
];
const ARTEMISIA_POINTS = [
    [37.0800, -3.4000], [37.0808, -3.3985], [37.0793, -3.4012], [37.0815, -3.4008],   // Borreguiles
    [37.0700, -3.2600], [37.0712, -3.2588], [37.0695, -3.2615],                       // Alcazaba
    [36.9900, -3.3300], [36.9910, -3.3285], [36.9893, -3.3312], [36.9905, -3.3320],   // loma sur
];
const ARTEMISIA_ABSENT = [37.0400, -3.3300];
// Especies de los cuantificadores UICN (datos ficticios). EN por B: tres núcleos a varios km; VU D2: una mancha.
const DEMO_EN_B = "650";     // Rothmaleria granatensis
const DEMO_VU_D2 = "6452";   // Odontites viscosus subsp. granatensis
const DEMO_EN_B_CORES = [[37.1300, -3.3800], [37.1050, -3.3350], [37.0820, -3.3620]];

// ------------------------------------------------------------------------------------------------
// 1. Cuentas: se crean verificadas o, si ya están, se les devuelve la contraseña de la demo.
$ids = [];
foreach (DEMO_USERS as $k => $u) {
    $hash = password_hash($u["password"], defined("PASSWORD_ARGON2ID") ? PASSWORD_ARGON2ID : PASSWORD_DEFAULT);
    $pdo->prepare("INSERT INTO users (email, password_hash, name, email_verified_at) VALUES (?, ?, ?, NOW())
                   ON DUPLICATE KEY UPDATE password_hash = VALUES(password_hash), name = VALUES(name),
                                           email_verified_at = COALESCE(email_verified_at, NOW())")
        ->execute([$u["email"], $hash, $u["name"]]);
    $ids[$k] = (int) $pdo->query("SELECT id FROM users WHERE email = " . $pdo->quote($u["email"]))->fetchColumn();
}
$pdo->exec("DELETE FROM login_attempts WHERE email LIKE '%@biodex.demo'");

$st = $pdo->prepare("SELECT c.id, (SELECT COUNT(*) FROM occurrences o WHERE o.catalog_id = c.id AND o.deleted_at IS NULL) AS n
                     FROM catalogs c WHERE c.name = ? AND c.deleted_at IS NULL");
$st->execute([DEMO_CATALOG_NAME]);
if ($existing = $st->fetch()) {
    echo "El catálogo de demo ya existe ({$existing["n"]} marcas): no se toca. Para empezar de cero: php bin/reset-demo.php\n";
    demoPrintAccounts();
    exit(0);
}

// Cualquier fallo a medias deja la BD como estaba.
function fail(string $msg): never {
    global $pdo;
    fwrite(STDERR, "ERROR: {$msg}\nSe deshace lo creado (reset-demo).\n");
    demoReset($pdo);
    exit(1);
}
function expect(int $code, array $ok, string $what, mixed $body): void {
    if (!in_array($code, $ok, true)) fail("{$what}: HTTP {$code} " . json_encode($body, JSON_UNESCAPED_UNICODE));
}

$jar = ["owner" => [], "custodian" => [], "editor" => []];
$device = array_map(fn() => uuidV4(), $jar);   // un «dispositivo» por cuenta
foreach (DEMO_USERS as $k => $u) {
    [$code, $r] = http("POST", "/api/auth/login", ["email" => $u["email"], "password" => $u["password"]], $jar[$k]);
    expect($code, [200], "login de {$u["email"]} (¿nginx arrancado?)", $r);
}
http("PATCH", "/api/auth/me", ["orcid" => "0000-0002-1825-0097"], $jar["owner"]);   // ORCID de ejemplo de orcid.org

// ------------------------------------------------------------------------------------------------
// 2. Catálogo, equipo y niveles
echo "Creando el catálogo (trae las fichas de EIDOS si no están en caché)…\n";
[$code, $r] = http("POST", "/api/catalogs", ["name" => DEMO_CATALOG_NAME, "taxonIDs" => array_map("strval", array_keys(DEMO_SPECIES)),
    "description" => DEMO_NOTICE . " Catálogo de ejemplo de BioDex: especies reales de EIDOS con observaciones inventadas "
        . "en Sierra Nevada (Granada) para enseñar la aplicación."], $jar["owner"]);
expect($code, [201], "crear el catálogo", $r);
$cat = $r["catalog"]["id"];

foreach (["custodian", "editor"] as $k) {
    [$code, $r] = http("POST", "/api/catalogs/{$cat}/invites", ["email" => DEMO_USERS[$k]["email"], "role" => "editor"], $jar["owner"]);
    expect($code, [201], "invitar a {$k}", $r);
    [$code, $r] = http("POST", "/api/invites/accept", ["code" => $r["code"]], $jar[$k]);
    expect($code, [200], "aceptar la invitación de {$k}", $r);
}
[$code, $r] = http("PUT", "/api/catalogs/{$cat}/members/{$ids["custodian"]}", ["role" => "custodian"], $jar["owner"]);
expect($code, [200], "hacer custodio", $r);

[$code, $r] = http("GET", "/api/catalogs/{$cat}", null, $jar["owner"]);
expect($code, [200], "leer el catálogo", $r);
$levels = [];
// La owner confirma los sugeridos y protege más la Plantago (un caso de nivel subido a mano para enseñar).
const DEMO_LEVEL_OVERRIDES = ["7171" => [3, "Población junto a la pista del Veleta: riesgo de expolio"]];
foreach ($r["species"] as $s) {
    [$lv, $reason] = DEMO_LEVEL_OVERRIDES[$s["taxonID"]] ?? [(int) $s["sensitivity"]["level"], "Confirmado: el sugerido por BioDex"];
    $levels[$s["taxonID"]] = [$lv, $s["canonicalName"] ?? $s["scientificName"]];
    [$code, $r2] = http("PUT", "/api/catalogs/{$cat}/species/{$s["taxonID"]}/sensitivity",
        ["level" => $lv, "reason" => $reason], $jar["owner"]);
    expect($code, [200], "confirmar el nivel de {$s["taxonID"]}", $r2);
}

// ------------------------------------------------------------------------------------------------
// 3. Marcas ficticias. Fechas de mayo a septiembre de 2026 y autor rotando entre los tres.
mt_srand(20260615);
$authors = ["owner", "custodian", "editor"];
$marks = ["owner" => [], "custodian" => [], "editor" => []];
$n = 0;
$newMark = function (string $taxon, float $lat, float $lon, bool $present, array $patch = []) use (&$n, &$marks, $authors): void {
    $who = $authors[$n % 3];
    $day = (new DateTimeImmutable("2026-05-20 08:30:00", new DateTimeZone("Europe/Madrid")))
        ->modify("+" . (($n * 37) % 118) . " days")->modify("+" . (($n * 53) % 420) . " minutes");
    $count = $present ? mt_rand(1, 40) : 0;
    $mark = [
        "occurrenceID" => uuidV4(), "taxonID" => (string) $taxon,
        "eventDate" => $day->format("c"), "clientUpdatedAt" => $day->modify("+12 minutes")->setTimezone(new DateTimeZone("UTC"))->format("Y-m-d\TH:i:s.000\Z"),
        "decimalLatitude" => round($lat, 7), "decimalLongitude" => round($lon, 7),
        "coordinateUncertaintyInMeters" => mt_rand(4, 15),
        "occurrenceStatus" => $present ? "present" : "absent",
        "habitat" => DEMO_SPECIES[$taxon]["habitat"],
    ];
    if ($present) {
        if ($n % 5 !== 4) {                       // 4 de cada 5 con recuento; casi todas con maduros
            $mark["individualCount"] = $count;
            if ($n % 4 !== 3) $mark["mature_count"] = mt_rand((int) ceil($count / 3), $count);
        }
        $month = (int) $day->format("n");
        $mark["lifeStage"] = $month <= 6 ? "vegetative" : ($month <= 8 ? "flowering" : "fruiting");
    } else {
        $mark["occurrenceRemarks"] = "Buscada 30 min en la localidad conocida sin encontrarla.";
    }
    $marks[$who][] = $patch + $mark;
    $n++;
};
$jitter = fn() => (mt_rand(-900, 900) / 100000);   // ± ~0,009° (≈ 800 m)

foreach (ARTEMISIA_POINTS as [$lat, $lon]) $newMark(ARTEMISIA, $lat, $lon, true, ["coordinateUncertaintyInMeters" => 6]);
$newMark(ARTEMISIA, ARTEMISIA_ABSENT[0], ARTEMISIA_ABSENT[1], false);
foreach (DEMO_SPECIES as $taxon => $sp) {
    if ((string) $taxon === ARTEMISIA) continue;
    for ($i = 0; $i < $sp["present"] + $sp["absent"]; $i++) {
        [$lat, $lon] = $sp["locs"][$i % count($sp["locs"])];
        $patch = [];
        if (in_array((string) $taxon, ["2723", "546"], true) && $i === 0) {   // las 2 marcadas en el mapa
            $patch = ["georeferenceProtocol" => "Punto marcado en el mapa", "coordinateUncertaintyInMeters" => 30,
                      "georeferenceRemarks" => "Vista con prismáticos desde la pista: ladera inaccesible."];
        }
        $newMark((string) $taxon, $lat + $jitter(), $lon + $jitter(), $i < $sp["present"], $patch);
    }
}

$total = 0;
foreach ($marks as $k => $list) {
    [$code, $r] = http("POST", "/api/sync/push", ["catalogId" => $cat, "deviceId" => $device[$k], "occurrences" => $list], $jar[$k]);
    expect($code, [200], "subir las marcas de {$k}", $r);
    $bad = array_filter($r["results"], fn($x) => $x["status"] !== "accepted");
    if ($bad) fail("marcas rechazadas: " . json_encode(array_values($bad), JSON_UNESCAPED_UNICODE));
    $total += count($list);
}

// ------------------------------------------------------------------------------------------------
// 4. Notas de especie
$note = fn(string $taxon, string $text, string $at) => ["noteID" => uuidV4(), "taxonID" => $taxon, "text" => $text, "clientUpdatedAt" => $at];
$notes = [
    "owner" => [$note(ARTEMISIA, "Población de Borreguiles con signos de pisoteo de ganado junto a la vereda. Revisar en julio.", "2026-06-10T09:00:00.000Z"),
                $note("546", "Florece dos semanas antes que el año pasado; el prado se secó a mediados de junio.", "2026-05-28T10:00:00.000Z")],
    "custodian" => [$note(ARTEMISIA, "Tres núcleos separados (Borreguiles, Alcazaba y loma sur): los trato como 3 localidades por la amenaza del pastoreo.", "2026-07-02T08:00:00.000Z")],
    "editor" => [$note("6989", "Rezumadero muy reducido este verano; buscar más abajo en el barranco.", "2026-08-12T11:00:00.000Z")],
];
foreach ($notes as $k => $list) {
    [$code, $r] = http("POST", "/api/sync/push", ["catalogId" => $cat, "deviceId" => $device[$k], "notes" => $list], $jar[$k]);
    expect($code, [200], "subir las notas de {$k}", $r);
}

// ------------------------------------------------------------------------------------------------
// 5. Evaluación UICN de referencia de la Artemisia con el EOO/AOO del servidor
[$code, $m] = http("GET", "/api/catalogs/{$cat}/species/" . ARTEMISIA . "/metrics?scope=team&cell=2", null, $jar["custodian"]);
expect($code, [200], "EOO/AOO de la Artemisia", $m);
$fixture = json_decode(file_get_contents(__DIR__ . "/demo/artemisia-assessment.json"), true);
if ($m["eooKm2"] != $fixture["inputs"]["B"]["eoo"] || $m["aooKm2"] != $fixture["inputs"]["B"]["aoo"]) {
    fail("el servidor calcula EOO {$m["eooKm2"]} y AOO {$m["aooKm2"]}; la evaluación de demo/artemisia-assessment.json espera "
        . "{$fixture["inputs"]["B"]["eoo"]} y {$fixture["inputs"]["B"]["aoo"]}");
}
$inputs = $fixture["inputs"];
$inputs["B"]["metricsOrigin"] = ["source" => "server", "computedAt" => $m["computedAt"], "cellSizeKm" => $m["cellSizeKm"],
    "nRecords" => $m["nRecords"], "nExcluded" => $m["nExcluded"], "nExact" => null, "eooKm2" => $m["eooKm2"]];
$assessmentID = uuidV4();
[$code, $r] = http("POST", "/api/sync/push", ["catalogId" => $cat, "deviceId" => $device["custodian"], "assessments" => [[
    "assessmentID" => $assessmentID, "taxonID" => ARTEMISIA, "status" => "final", "clientUpdatedAt" => "2026-07-03T09:00:00.000Z",
    "inputs" => $inputs, "result" => $fixture["result"],
    "justification" => "Tres localidades en Sierra Nevada; retroceso del hábitat por pisoteo y pastoreo. " . DEMO_NOTICE,
]]], $jar["custodian"]);
expect($code, [200], "subir la evaluación", $r);
if (($r["assessmentResults"][0]["status"] ?? "") !== "accepted") fail("evaluación rechazada: " . json_encode($r["assessmentResults"], JSON_UNESCAPED_UNICODE));
[$code, $r] = http("PUT", "/api/catalogs/{$cat}/species/" . ARTEMISIA . "/reference-assessment", ["assessmentID" => $assessmentID], $jar["owner"]);
expect($code, [200], "marcar la evaluación de referencia", $r);

// ------------------------------------------------------------------------------------------------
// 5b. F11g · Cuantificadores UICN: una especie EN por B (tres núcleos con parcelas y recuentos, impactos en
//     curso, b(iii) y 3 localidades confirmadas) y otra VU D2 (una sola mancha sin recuento de maduros y una
//     amenaza plausible). Las dos con su evaluación guardada (instantánea, recalculada en el servidor).
$addSpecies = function (string $taxon) use ($cat, &$jar): void {
    [$code, $r] = http("POST", "/api/catalogs/{$cat}/species", ["taxonID" => $taxon], $jar["owner"]);
    expect($code, [200, 201], "añadir {$taxon} al catálogo", $r);
};
$addSpecies(DEMO_EN_B);
$addSpecies(DEMO_VU_D2);
$f11gMarks = ["owner" => [], "custodian" => [], "editor" => []];
$k = 0;
foreach (DEMO_EN_B_CORES as $c => [$lat, $lon]) {
    foreach ([[0, 0, 60, 1000], [0.0012, 0.0009, 45, 1000], [-0.0010, 0.0014, 80, 2000]] as [$dLat, $dLon, $mature, $area]) {
        $who = $authors[$k++ % 3];
        $day = sprintf("2026-07-%02dT10:%02d:00+02:00", 8 + $c * 6, 10 + $k);
        $f11gMarks[$who][] = ["occurrenceID" => uuidV4(), "taxonID" => DEMO_EN_B, "eventDate" => $day,
            "clientUpdatedAt" => (new DateTimeImmutable($day))->setTimezone(new DateTimeZone("UTC"))->format("Y-m-d\TH:i:s.000\Z"),
            "decimalLatitude" => round($lat + $dLat, 7), "decimalLongitude" => round($lon + $dLon, 7), "coordinateUncertaintyInMeters" => 5,
            "individualCount" => $mature + 12, "mature_count" => $mature, "lifeStage" => "flowering", "sampleSizeValue" => $area,
            "habitat" => "Matorral almohadillado sobre micaesquistos"];
    }
}
foreach ([[37.1560, -3.4210], [37.1566, -3.4202], [37.1552, -3.4215], [37.1571, -3.4220]] as $i => [$lat, $lon]) {
    $day = sprintf("2026-08-%02dT11:00:00+02:00", 3 + $i);
    $f11gMarks["editor"][] = ["occurrenceID" => uuidV4(), "taxonID" => DEMO_VU_D2, "eventDate" => $day,
        "clientUpdatedAt" => (new DateTimeImmutable($day))->setTimezone(new DateTimeZone("UTC"))->format("Y-m-d\TH:i:s.000\Z"),
        "decimalLatitude" => $lat, "decimalLongitude" => $lon, "coordinateUncertaintyInMeters" => 8, "individualCount" => 20 + 7 * $i,
        "habitat" => "Talud de la pista, sobre esquistos removidos"];
}
foreach ($f11gMarks as $who => $list) {
    [$code, $r] = http("POST", "/api/sync/push", ["catalogId" => $cat, "deviceId" => $device[$who], "occurrences" => $list], $jar[$who]);
    expect($code, [200], "subir las marcas F11g de {$who}", $r);
    if (array_filter($r["results"], fn($x) => $x["status"] !== "accepted")) fail("marcas F11g rechazadas: " . json_encode($r["results"]));
}
// Impactos en curso, uno por núcleo (tres eventos distintos → tres localidades), registrados por la editora.
$impacts = [];
foreach ([["6.1", "trampling", "high", 40], ["2.3", "grazing", "medium", 25], ["4.1", "works", "high", 60]] as $c => [$code_, $quick, $int, $scope]) {
    [$lat, $lon] = DEMO_EN_B_CORES[$c];
    $impacts[] = ["impactID" => uuidV4(), "threatCode" => $code_, "quickKey" => $quick, "decimalLatitude" => $lat, "decimalLongitude" => $lon,
        "radiusM" => 300, "eventDate" => sprintf("2026-07-%02dT12:00:00+02:00", 8 + $c * 6), "intensity" => $int, "scopePct" => $scope,
        "timing" => "ongoing", "note" => DEMO_NOTICE, "species" => [DEMO_EN_B], "clientUpdatedAt" => sprintf("2026-07-%02dT10:30:00.000Z", 8 + $c * 6)];
}
$impacts[] = ["impactID" => uuidV4(), "threatCode" => "4.1", "quickKey" => "works", "decimalLatitude" => 37.1562, "decimalLongitude" => -3.4212,
    "radiusM" => 150, "eventDate" => "2026-08-04T12:00:00+02:00", "intensity" => "high", "scopePct" => 100, "timing" => "future",
    "note" => "Ensanche previsto de la pista. " . DEMO_NOTICE, "species" => [DEMO_VU_D2], "clientUpdatedAt" => "2026-08-04T10:30:00.000Z"];
[$code, $r] = http("POST", "/api/sync/push", ["catalogId" => $cat, "deviceId" => $device["editor"], "impacts" => $impacts], $jar["editor"]);
expect($code, [200], "subir los impactos", $r);
if (array_filter($r["impactResults"], fn($x) => $x["status"] !== "accepted")) fail("impactos rechazados: " . json_encode($r["impactResults"]));
// Parámetros y hechos (la custodia)
$put = function (string $taxon, string $key, array $body) use ($cat, &$jar): void {
    [$code, $r] = http("PUT", "/api/catalogs/{$cat}/species/{$taxon}/parameters/{$key}", $body, $jar["custodian"]);
    expect($code, [200], "fijar {$key} de {$taxon}", $r);
};
$put(DEMO_EN_B, "separation_distance", ["value" => 1, "quality" => "estimated", "source" => "Valor por defecto del grupo (planta con flor) en BioDex"]);
$put(DEMO_EN_B, "occupied_habitat_km2", ["value" => 0.05, "valueMin" => 0.03, "valueMax" => 0.08, "quality" => "estimated", "source" => "Cartografía de hábitat (ficticia)"]);
$put(DEMO_EN_B, "decline_b_iii", ["value" => "yes", "quality" => "inferred",
    "justification" => "Pisoteo, pastoreo y obras en curso en los tres núcleos reducen la calidad del hábitat. " . DEMO_NOTICE]);
$put(DEMO_VU_D2, "plausible_threat", ["value" => "yes", "quality" => "projected",
    "justification" => "Una única mancha en el talud de la pista: el ensanche previsto la eliminaría en muy poco tiempo. " . DEMO_NOTICE]);
[$code, $a] = http("GET", "/api/catalogs/{$cat}/species/" . DEMO_EN_B . "/assessment", null, $jar["custodian"]);
expect($code, [200], "evaluación de " . DEMO_EN_B, $a);
$groups = array_map(fn($g) => array_intersect_key($g, array_flip(["label", "subpops", "impactID", "threatCode", "noThreat"])), $a["locationsProposal"]["groups"]);
[$code, $r] = http("PUT", "/api/catalogs/{$cat}/species/" . DEMO_EN_B . "/locations", ["count" => $a["locationsProposal"]["count"], "groups" => $groups,
    "justification" => "Cada núcleo sufre un evento de amenaza distinto (pisoteo, pastoreo, obras): tres localidades. " . DEMO_NOTICE,
    "quality" => "inferred", "separationKm" => 1], $jar["custodian"]);
expect($code, [200], "confirmar las localidades", $r);
$snapshots = [];
foreach ([DEMO_EN_B, DEMO_VU_D2] as $taxon) {
    [$code, $a] = http("GET", "/api/catalogs/{$cat}/species/{$taxon}/assessment", null, $jar["custodian"]);
    expect($code, [200], "evaluación de {$taxon}", $a);
    $snapshots[$taxon] = $a["result"]["criteriaString"] ?? $a["result"]["category"];
    [$code, $r] = http("POST", "/api/sync/push", ["catalogId" => $cat, "deviceId" => $device["custodian"], "assessments" => [[
        "assessmentID" => uuidV4(), "taxonID" => $taxon, "status" => "final", "clientUpdatedAt" => "2026-08-20T09:00:00.000Z",
        "inputs" => $a["inputs"], "result" => ["quantifiers" => ["origin" => "server", "nPoints" => $a["nPoints"]]],
        "justification" => DEMO_NOTICE]]], $jar["custodian"]);
    expect($code, [200], "guardar la evaluación de {$taxon}", $r);
}
if ($snapshots[DEMO_EN_B] !== "EN B1ab(iii)+2ab(iii)" || $snapshots[DEMO_VU_D2] !== "VU D2") {
    fail("las especies de F11g no dan lo esperado: " . json_encode($snapshots, JSON_UNESCAPED_UNICODE));
}

// ------------------------------------------------------------------------------------------------
// 5c. F11h · Visitas y series temporales: 4 sitios FICTICIOS de la Plantago nivalis con visitas anuales de 2014 a 2026
// (sin ninguna en 2019 y algún hueco por sitio) y un declive de r ≈ −0,088/año (≈ 58 % en 10 años): con la generación
// de 3 años la ventana de A es de 10 años y la tarjeta A sale completa: EN A2ab. Ruido fijo (nada aleatorio).
const DEMO_SERIES = "7171";   // Plantago nivalis (nivel 3: los sitios se ven generalizados para quien no es de confianza)
const DEMO_SITES = [
    // [nombre, tipo, centro, radio o null, polígono [[lat, lon]…] o null, maduros en 2014, quién, años sin visita]
    ["Borreguil de la Caldera", "plot", [37.0560, -3.3000], null,
        [[37.0563, -3.3005], [37.0563, -3.2995], [37.0557, -3.2995], [37.0557, -3.3005]], 62, "custodian", []],
    ["Laguna de las Yeguas", "point", [37.0750, -3.3700], 30, null, 41, "custodian", [2021]],
    ["Vacares, pradera alta", "point", [37.0640, -3.3420], 25, null, 28, "editor", [2015, 2023]],
    ["Río Seco", "plot", [37.0590, -3.3150], 40, null, 85, "editor", [2016]],
];
const DEMO_SERIES_NOISE = [1.00, 1.08, 0.93, 1.05, 0.97, 1.10, 0.90, 1.04, 0.96, 1.07, 0.94, 1.02, 0.99];
const DEMO_SERIES_R = -0.088;
$seriesVisits = 0;
foreach (DEMO_SITES as $i => [$name, $type, $c, $radius, $poly, $base, $who, $gaps]) {
    $siteID = uuidV4();
    $site = ["siteID" => $siteID, "name" => "{$name} (ficticio)", "siteType" => $type, "protocol" => "target_census",
             "species" => [DEMO_SERIES], "clientUpdatedAt" => "2014-04-01T08:00:00.000Z"];
    if ($poly) $site["footprintWKT"] = "POLYGON((" . implode(", ", array_map(fn($p) => "{$p[1]} {$p[0]}", [...$poly, $poly[0]])) . "))";
    else $site += ["radiusM" => $radius, "decimalLatitude" => $c[0], "decimalLongitude" => $c[1]];
    $visits = [];
    $occ = [];
    foreach (range(2014, 2026) as $k => $year) {
        if ($year === 2019 || in_array($year, $gaps, true)) continue;   // 2019: nadie subió; y los huecos de cada sitio
        $visitID = uuidV4();
        $day = sprintf("%04d-06-%02dT%02d:00:00Z", $year, 10 + $i * 3, 8 + $i);
        $end = sprintf("%04d-06-%02dT%02d:45:00Z", $year, 10 + $i * 3, 9 + $i);
        $visits[] = ["visitID" => $visitID, "siteID" => $siteID, "protocol" => "target_census", "status" => "closed", "eventStart" => $day,
                     "eventEnd" => $end, "effortMinutes" => 105, "observers" => DEMO_USERS[$who]["name"], "species" => [DEMO_SERIES],
                     "conditions" => DEMO_NOTICE, "clientUpdatedAt" => $end];
        $mature = (int) round($base * exp(DEMO_SERIES_R * ($year - 2014)) * DEMO_SERIES_NOISE[($k + $i * 4) % count(DEMO_SERIES_NOISE)]);
        $mark = ["occurrenceID" => uuidV4(), "taxonID" => DEMO_SERIES, "visitID" => $visitID, "eventDate" => $day,
                 "decimalLatitude" => $c[0] + 0.0001 * ($k % 3 - 1), "decimalLongitude" => $c[1] + 0.0001 * ($k % 2),
                 "coordinateUncertaintyInMeters" => 8, "clientUpdatedAt" => $end, "occurrenceRemarks" => DEMO_NOTICE];
        $occ[] = $mature > 0
            ? $mark + ["individualCount" => $mature + (int) round($mature / 3), "mature_count" => $mature, "lifeStage" => "flowering"]
            : $mark + ["occurrenceStatus" => "absent", "individualCount" => 0];
    }
    [$code, $r] = http("POST", "/api/sync/push", ["catalogId" => $cat, "deviceId" => $device[$who], "sites" => [$site], "visits" => $visits,
        "occurrences" => $occ], $jar[$who]);
    expect($code, [200], "subir el sitio {$name} y sus visitas", $r);
    foreach (["siteResults", "visitResults", "results"] as $k) {
        if (array_filter($r[$k], fn($x) => $x["status"] !== "accepted")) fail("{$k} rechazados en {$name}: " . json_encode($r[$k], JSON_UNESCAPED_UNICODE));
    }
    $seriesVisits += count($visits);
}
// Duración de la generación (ventana de A) y los hechos de A: causas comprendidas pero ni reversibles ni cesadas → A2;
// bases (a) observación directa (los recuentos) y (b) índice de abundancia.
$put(DEMO_SERIES, "generation_length", ["value" => 3, "quality" => "estimated", "source" => "Perenne de vida corta (ficticio para la demo)"]);
foreach (["a_reversible" => "no", "a_understood" => "yes", "a_ceased" => "no", "a_basis_a" => "yes", "a_basis_b" => "yes"] as $key => $v) {
    $put(DEMO_SERIES, $key, ["value" => $v, "quality" => "inferred", "justification" => "Pisoteo y desecación de los borreguiles. " . DEMO_NOTICE]);
}
[$code, $a] = http("GET", "/api/catalogs/{$cat}/species/" . DEMO_SERIES . "/assessment", null, $jar["custodian"]);
expect($code, [200], "evaluación de la serie", $a);
$seriesA = $a["result"]["byCriterion"]["A"];
if (($a["series"]["status"] ?? null) !== "ok" || ($seriesA["category"] ?? null) !== "EN") {
    fail("la serie de la demo no da EN por A2: " . json_encode([$a["series"]["status"] ?? null, $a["series"]["reduction"] ?? null, $seriesA["notation"] ?? null], JSON_UNESCAPED_UNICODE));
}

// 6. La demo exporta Plinian Core: el servidor valida el XML contra el XSD y, si no valida, no da ZIP.
foreach (["public"] as $mode) {   // la completa dejaría una fila export_full en el Registro de la demo
    $ch = curl_init("{$BASE}/api/catalogs/{$cat}/export");
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 120,
        CURLOPT_POSTFIELDS => json_encode(["mode" => $mode, "formats" => ["plinian"]]),
        CURLOPT_HTTPHEADER => ["Content-Type: application/json", "Cookie: " . implode("; ", array_map(fn($k, $v) => "{$k}={$v}",
            array_keys($jar["owner"]), $jar["owner"]))]]);
    $raw = (string) curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    expect($code, [200], "exportar Plinian Core ({$mode})", json_decode($raw, true));
}

foreach ($jar as $k => $j) http("POST", "/api/auth/logout", null, $jar[$k]);

// ------------------------------------------------------------------------------------------------
$absent = count(array_filter(array_merge(...array_values($marks)), fn($x) => $x["occurrenceStatus"] === "absent"));
echo "\nCatálogo «" . DEMO_CATALOG_NAME . "» ({$cat})\n";
echo "  " . count($levels) . " especies: " . implode(", ", array_map(fn($x) => "{$x[1]} (nivel {$x[0]})", $levels)) . "\n";
echo "  {$total} marcas ficticias ({$absent} ausencias, 2 marcadas en el mapa) · 4 notas · evaluación de referencia "
    . $fixture["result"]["criteriaString"] . " (EOO {$m["eooKm2"]} km², AOO {$m["aooKm2"]} km²)\n";
echo "  F11g: " . count($impacts) . " impactos · Rothmaleria granatensis {$snapshots[DEMO_EN_B]} · Odontites viscosus subsp. granatensis {$snapshots[DEMO_VU_D2]}\n";
echo "  F11h: " . count(DEMO_SITES) . " sitios y {$seriesVisits} visitas (2014–2026, sin visitas en 2019) de la Plantago nivalis · reducción "
    . "{$a["series"]["reduction"]["best"]} % en {$a["series"]["window"]["years"]} años (IC {$a["series"]["reduction"]["min"]}–{$a["series"]["reduction"]["max"]} %) · A: "
    . "{$seriesA["category"]} {$seriesA["notation"]}\n";
demoPrintAccounts();
