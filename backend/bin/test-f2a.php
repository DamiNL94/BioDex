<?php
// ================================================================================================
// Pruebas de F2a (PHP puro): docker compose exec php php bin/test-f2a.php
// Imprime OK/FAIL por comprobación y sale con código 1 si alguna falla.
// Las pruebas HTTP van contra nginx dentro de la red de Docker (TEST_BASE_URL, por defecto http://nginx)
// y el correo se lee de la API de Mailpit (TEST_MAILPIT_URL, por defecto http://mailpit:8025).
// Crea usuarios f2a-*@biodex.test y los borra al terminar.
// ================================================================================================

if (PHP_SAPI !== "cli") exit(1);

require_once __DIR__ . "/../Config/db.php";
require_once __DIR__ . "/../Lib/migrate.php";
require_once __DIR__ . "/../Lib/authz.php";
require_once __DIR__ . "/../Lib/sensitivity.php";
require_once __DIR__ . "/../Lib/audit.php";
require_once __DIR__ . "/test-lib.php";

$MAILPIT = getenv("TEST_MAILPIT_URL") ?: "http://mailpit:8025";

function getJson(string $url): ?array {
    $raw = @file_get_contents($url);
    return $raw === false ? null : json_decode($raw, true);
}

/** Enlace de verificación del último correo de Mailpit dirigido a $email, o null. */
function verificationLinkFor(string $email): ?string {
    global $MAILPIT;
    for ($i = 0; $i < 10; $i++) {
        $list = getJson($MAILPIT . "/api/v1/search?query=" . urlencode("to:\"{$email}\""));
        $id = $list["messages"][0]["ID"] ?? null;
        if ($id) {
            $msg = getJson($MAILPIT . "/api/v1/message/" . urlencode($id));
            if (preg_match('#https?://\S+/api/auth/verify\?token=([0-9a-f]{64})#', $msg["Text"] ?? "", $m)) {
                return "/api/auth/verify?token=" . $m[1];
            }
        }
        usleep(200_000);
    }
    return null;
}

$pdo = db();

/** Borra lo que dejan las pruebas (también restos de una ejecución interrumpida). */
function cleanup(PDO $pdo): void {
    $pdo->exec("DELETE FROM login_attempts WHERE ip IN (
                    SELECT ip FROM (SELECT DISTINCT ip FROM login_attempts WHERE email LIKE '%@biodex.test') t)");
    $pdo->exec("DELETE FROM login_attempts WHERE email LIKE '%@biodex.test'");
    $pdo->exec("DELETE FROM access_log WHERE target LIKE '%@biodex.test' OR target LIKE 'test-f2a:%'");
    $pdo->exec("DELETE FROM catalogs WHERE name LIKE 'test-f2a %'");
    // Solo sus cuentas: los recorridos de otras fases dejan catálogos de otros @biodex.test (FK fk_catalogs_owner).
    $pdo->exec("DELETE FROM users WHERE email LIKE 'f2a-%@biodex.test'");
}
cleanup($pdo);

// ------------------------------------------------------------------------------------------------
section("Migraciones");
runMigrations($pdo);
$second = runMigrations($pdo);
check("un segundo pase de migrate no aplica nada", $second === [], implode(", ", $second));
$tables = $pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE()
                       AND table_collation = 'utf8mb4_0900_ai_ci'")->fetchColumn();
check("todas las tablas con utf8mb4_0900_ai_ci", (int) $tables === (int) $pdo->query(
    "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE()")->fetchColumn());
check("búsqueda insensible a acentos", (int) $pdo->query("SELECT 'Atropa baética' = 'atropa baetica'")->fetchColumn() === 1);
check("sync_seq tiene exactamente una fila", (int) $pdo->query("SELECT COUNT(*) FROM sync_seq")->fetchColumn() === 1);

// ------------------------------------------------------------------------------------------------
section("Autenticación");
$email = "f2a-" . bin2hex(random_bytes(4)) . "@biodex.test";
$password = "correcta-horse-battery";
$jar = [];

[$c, $b] = http("POST", "/api/auth/register", ["email" => $email, "password" => "corta"], $jar);
check("register con contraseña < 10 caracteres → 400", $c === 400, "HTTP {$c}");

[$c, $b] = http("POST", "/api/auth/register", ["email" => $email, "password" => $password, "name" => "Prueba F2a"], $jar);
check("register → 201", $c === 201, "HTTP {$c} " . json_encode($b));
check("register deja la sesión abierta y la cookie biodex_remember", isset($jar["biodex_remember"]));
check("cuenta nueva sin verificar", ($b["user"]["emailVerified"] ?? null) === false);
check("el correo de verificación ha salido", ($b["verificationSent"] ?? false) === true);

$hash = $pdo->query("SELECT password_hash FROM users WHERE email = " . $pdo->quote($email))->fetchColumn();
check("contraseña con hash argon2id/bcrypt", str_starts_with((string) $hash, '$argon2id$') || str_starts_with((string) $hash, '$2y$'));

$dupJar = [];
[$c] = http("POST", "/api/auth/register", ["email" => strtoupper($email), "password" => $password], $dupJar);
check("register duplicado (mayúsculas) → 409", $c === 409, "HTTP {$c}");

[$c, $b] = http("GET", "/api/auth/me", null, $jar);
check("me tras register → 200", $c === 200 && ($b["user"]["email"] ?? "") === $email, "HTTP {$c}");

$link = verificationLinkFor($email);
check("Mailpit recibe el correo con el enlace de verificación", $link !== null);
if ($link) {
    $anon = [];
    [$c, $b] = http("GET", $link, null, $anon);
    check("verify → 200 y email verificado", $c === 200 && ($b["user"]["emailVerified"] ?? false) === true, "HTTP {$c}");
    [$c] = http("GET", $link, null, $anon);
    check("verify con el mismo enlace otra vez → 400", $c === 400, "HTTP {$c}");
}

[$c] = http("POST", "/api/auth/logout", null, $jar);
check("logout → 200", $c === 200, "HTTP {$c}");
check("logout borra la cookie biodex_remember", !isset($jar["biodex_remember"]));
[$c] = http("GET", "/api/auth/me", null, $jar);
check("me tras logout → 401", $c === 401, "HTTP {$c}");

$jar = [];
[$c, $b] = http("POST", "/api/auth/login", ["email" => $email, "password" => $password], $jar);
check("login → 200", $c === 200 && ($b["user"]["email"] ?? "") === $email, "HTTP {$c}");
[$c] = http("GET", "/api/auth/me", null, $jar);
check("me tras login → 200", $c === 200, "HTTP {$c}");

// "Seguir conectado": solo con la cookie del token (sin cookie de sesión) se recupera la sesión y el validador rota.
$oldCookie = $jar["biodex_remember"] ?? "";
$tokenJar = ["biodex_remember" => $oldCookie];
[$c] = http("GET", "/api/auth/me", null, $tokenJar);
$newCookie = $tokenJar["biodex_remember"] ?? "";
check("me solo con biodex_remember → 200", $c === 200, "HTTP {$c}");
check("la cookie rota el validador y conserva el selector",
    $newCookie !== "" && $newCookie !== $oldCookie && explode(":", $newCookie)[0] === explode(":", $oldCookie)[0]);

$graceJar = ["biodex_remember" => $oldCookie];
[$c] = http("GET", "/api/auth/me", null, $graceJar);
check("el validador anterior vale dentro de la ventana de gracia", $c === 200, "HTTP {$c}");

$selector = explode(":", $oldCookie)[0];
$pdo->prepare("UPDATE user_auth_tokens SET rotated_at = DATE_SUB(NOW(), INTERVAL 1 HOUR) WHERE selector = ?")->execute([$selector]);
$stolenJar = ["biodex_remember" => $oldCookie];
[$c] = http("GET", "/api/auth/me", null, $stolenJar);
check("validador viejo fuera de la gracia → 401", $c === 401, "HTTP {$c}");
$left = $pdo->prepare("SELECT COUNT(*) FROM user_auth_tokens WHERE selector = ?");
$left->execute([$selector]);
check("…y el token queda revocado", (int) $left->fetchColumn() === 0);

// Límite de intentos: 5 fallos por email → el 6.º intento es 429, aunque la contraseña sea buena.
$failJar = [];
$codes = [];
for ($i = 0; $i < 5; $i++) {
    [$codes[]] = http("POST", "/api/auth/login", ["email" => $email, "password" => "mala-contraseña-$i"], $failJar);
}
check("5 logins fallidos → 401", $codes === [401, 401, 401, 401, 401], implode(",", $codes));
[$c] = http("POST", "/api/auth/login", ["email" => $email, "password" => "otra-mala-mas"], $failJar);
check("6.º login fallido → 429", $c === 429, "HTTP {$c}");
[$c] = http("POST", "/api/auth/login", ["email" => $email, "password" => $password], $failJar);
check("bloqueado incluso con la contraseña correcta → 429", $c === 429, "HTTP {$c}");
$logged = $pdo->prepare("SELECT COUNT(*) FROM access_log WHERE action = 'login_failed' AND target = ?");
$logged->execute([$email]);
check("cada fallo queda en access_log como login_failed", (int) $logged->fetchColumn() === 5);

// ------------------------------------------------------------------------------------------------
section("Sensibilidad: nivel sugerido (docs/eidos-samples/)");
function sample(string $slug): array {
    $d = json_decode(file_get_contents(__DIR__ . "/../../docs/eidos-samples/{$slug}.json"), true);
    return [$d["conservation"]["value"] ?? [], $d["legal"]["value"] ?? []];
}
foreach ([
    // Atropa baetica: el prompt de F2a espera 3 por UICN EN, pero EIDOS la da también como
    // «CEEA: En peligro de extinción» (RD 139/2011, nacional) y shared/sensitivity.json dice que eso es
    // nivel 2 y que gana el más restrictivo. Mandan las reglas del JSON.
    ["artemisia-granatensis", 2, "CR"],
    ["atropa-baetica", 2, "CEEA: En peligro de extinción"],
    ["parnassius-apollo", 4, null],       // LC; LESRPE y protección autonómica no suben el nivel
    ["turdus-merula", 4, null],
    ["inventadus-falsus", 4, null],       // sin datos
] as [$slug, $expected, $reasonHas]) {
    [$iucn, $legal] = sample($slug);
    [$level, $reason] = suggestSensitivityLevel($iucn, $legal);
    check("{$slug} → {$expected}", $level === $expected && ($reasonHas === null || str_contains($reason, $reasonHas)),
        "nivel {$level} ({$reason})");
}
[$atropaIucn] = sample("atropa-baetica");
check("Atropa baetica solo por UICN (EN) → 3", suggestSensitivityLevel($atropaIucn, [])[0] === 3);
check("literal sucio sin `code`: se usa el código", suggestSensitivityLevel(
    [["label" => "VU (Vulnerable) ", "scope" => "España"]], [])[0] === 3);
check("«CEEA: Vulnerable» nacional → 3", suggestSensitivityLevel([], [[
    "estadolegal" => "CEEA: Vulnerable", "ambito" => "Nacional", "idvigente" => 1,
    "norma" => "Real Decreto 139/2011, de 4 de febrero"]])[0] === 3);
check("CEEA con ccaa (norma autonómica servida como Nacional) no cuenta", suggestSensitivityLevel([], [[
    "estadolegal" => "CEEA: En peligro de extinción", "ambito" => "Nacional", "ccaa" => "Castilla-La Mancha",
    "idvigente" => 1, "norma" => "Real Decreto 139/2011"]])[0] === 4);
check("nunca se sugiere el nivel 1", suggestSensitivityLevel([["code" => "EX"], ["code" => "CR"]], [])[0] === 2);

// ------------------------------------------------------------------------------------------------
section("Sensibilidad: generalización");
$g2 = generalizePoint(37.0921, -3.3947, 2, 5);
check("nivel 2 → centro de celda 0,1° (37.05, -3.35)",
    $g2["decimalLatitude"] === 37.05 && $g2["decimalLongitude"] === -3.35, json_encode($g2));
check("nivel 2 → incertidumbre ≈ 7,1 km (semidiagonal)",
    $g2["coordinateUncertaintyInMeters"] >= 7000 && $g2["coordinateUncertaintyInMeters"] <= 7200,
    (string) $g2["coordinateUncertaintyInMeters"]);
check("nivel 2 → dataGeneralizations del JSON", $g2["dataGeneralizations"] === "Coordenadas generalizadas a 0,1°");
$g3 = generalizePoint(37.0921, -3.3947, 3, 5);
check("nivel 3 → celda 0,01° (37.095, -3.395)",
    $g3["decimalLatitude"] === 37.095 && $g3["decimalLongitude"] === -3.395, json_encode($g3));
check("nivel 3 → incertidumbre ≈ 710 m", $g3["coordinateUncertaintyInMeters"] >= 700 && $g3["coordinateUncertaintyInMeters"] <= 720,
    (string) $g3["coordinateUncertaintyInMeters"]);
$g1 = generalizePoint(37.0921, -3.3947, 1, 5);
check("nivel 1 → sin coordenadas", $g1["decimalLatitude"] === null && $g1["decimalLongitude"] === null
    && $g1["coordinateUncertaintyInMeters"] === null && $g1["informationWithheld"] !== null);
check("misma entrada → misma salida", generalizePoint(37.0921, -3.3947, 2, 5) === $g2);
check("se conserva una incertidumbre original mayor", generalizePoint(37.0921, -3.3947, 2, 20000)["coordinateUncertaintyInMeters"] === 20000);
check("borde de celda exacto (37.1) cae en la celda superior", generalizePoint(37.1, -3.3, 2, null)["decimalLatitude"] === 37.15);
check("nivel 4 → exacto", generalizePoint(37.0921, -3.3947, 4, 5)["decimalLatitude"] === 37.0921);

// ------------------------------------------------------------------------------------------------
section("Sensibilidad: applyVisibility");
$occ = ["occurrenceID" => "00000000-0000-4000-8000-000000000001", "user_id" => 10,
        "decimalLatitude" => "37.0921000", "decimalLongitude" => "-3.3947000", "coordinateUncertaintyInMeters" => 5];
check("el autor ve su marca exacta", applyVisibility($occ, 2, "editor", 10)["decimalLatitude"] === "37.0921000");
$seen = applyVisibility($occ, 2, "editor", 11);
check("un editor ajeno la ve generalizada", $seen["decimalLatitude"] === 37.05 && $seen["decimalLongitude"] === -3.35);
check("un viewer ajeno la ve generalizada", applyVisibility($occ, 3, "viewer", 11)["decimalLatitude"] === 37.095);
check("el custodio ve exacto", applyVisibility($occ, 2, "custodian", 12)["decimalLatitude"] === "37.0921000");
check("el owner ve exacto", applyVisibility($occ, 1, "owner", 12)["decimalLatitude"] === "37.0921000");
check("nivel 4 → exacto para todos", applyVisibility($occ, 4, "viewer", 13)["decimalLatitude"] === "37.0921000");
check("nivel 1 → sin coordenadas para un editor ajeno", applyVisibility($occ, 1, "editor", 11)["decimalLatitude"] === null);
check("nivel desconocido → se trata como 1", applyVisibility($occ, 9, "editor", 11)["decimalLatitude"] === null);
check("el registro original no se altera", $occ["decimalLatitude"] === "37.0921000");

// ------------------------------------------------------------------------------------------------
section("Permisos de catálogo");
check("la matriz y exactAccess.roles coinciden",
    CATALOG_PERMISSIONS["view_exact"] === sensitivityConfig()["exactAccess"]["roles"]);
check("viewer puede ver pero no marcar", catalogCan("viewer", "view") && !catalogCan("viewer", "mark"));
check("editor añade especies pero no las quita", catalogCan("editor", "add_species") && !catalogCan("editor", "remove_species"));
check("custodio exporta completo pero no gestiona miembros", catalogCan("custodian", "export_full") && !catalogCan("custodian", "manage_members"));
check("solo el owner cambia niveles", array_filter(CATALOG_ROLES, fn($r) => catalogCan($r, "set_level")) === ["owner"]);
check("sin rol no se puede nada", !catalogCan(null, "view"));

$uid = (int) $pdo->query("SELECT id FROM users WHERE email = " . $pdo->quote($email))->fetchColumn();
$catalogId = sprintf("%s-%s-4%s-8%s-%s", bin2hex(random_bytes(4)), bin2hex(random_bytes(2)),
    substr(bin2hex(random_bytes(2)), 1), substr(bin2hex(random_bytes(2)), 1), bin2hex(random_bytes(6)));
$pdo->prepare("INSERT INTO catalogs (id, owner_id, name) VALUES (?, ?, 'test-f2a catálogo')")->execute([$catalogId, $uid]);
$pdo->prepare("INSERT INTO catalog_members (catalog_id, user_id, role) VALUES (?, ?, 'editor')")->execute([$catalogId, $uid]);
check("catalogRole devuelve el rol del miembro", catalogRole($pdo, $catalogId, $uid) === "editor");
check("catalogRole de un no miembro → null", catalogRole($pdo, $catalogId, $uid + 100000) === null);
$pdo->prepare("UPDATE catalogs SET deleted_at = NOW() WHERE id = ?")->execute([$catalogId]);
check("catálogo borrado → null", catalogRole($pdo, $catalogId, $uid) === null);

logAccess($pdo, $uid, "set_level", $catalogId, "test-f2a:9918");
$row = $pdo->query("SELECT * FROM access_log WHERE target = 'test-f2a:9918'")->fetch();
check("logAccess escribe en access_log", $row && $row["action"] === "set_level" && $row["catalog_id"] === $catalogId);

// ------------------------------------------------------------------------------------------------
cleanup($pdo);
echo "\n" . ($failures === 0 ? "TODO OK" : "{$failures} FALLO(S)") . "\n";
exit($failures === 0 ? 0 : 1);
