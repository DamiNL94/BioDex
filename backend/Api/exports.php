<?php
// ================================================================================================
// /api/catalogs/{id}/export — exportación del catálogo (F10a). Incluido desde Api/catalogs.php, que ya
// ha abierto la sesión ($pdo, $userId, $method) y deja la ruta en $exportRoute = [catalogId, sub].
// ------------------------------------------------------------------------------------------------
//   POST /api/catalogs/{id}/export/preview   view (full: export_full) → resumen y opciones, JSON
//   POST /api/catalogs/{id}/export           view (full: export_full) → application/zip
// Cuerpo de las dos: {mode: full|public, dateFrom?, dateTo?, taxonIDs?, observers?, includeAbsences?,
// license?, title?, description?, formats?: [dwc|plinian|sheets]} (Lib/export.php, exportOptions()).
// Si Plinian Core no valida contra su XSD, 500 con un mensaje claro y sin ZIP (F10c).
// «full» (coordenadas exactas) solo owner y custodio, y cada descarga va a access_log (export_full).
// El ZIP es la única respuesta de /api que no es JSON; sus errores sí lo son.
// ================================================================================================

// /api/exports a secas (el router de public/index.php) no es una ruta: solo se entra desde catalogs.php.
if (!isset($exportRoute)) apiError("Recurso no encontrado.", 404);

require_once __DIR__ . "/../Lib/export.php";

[$exportCatalogId, $exportSub] = $exportRoute;
if ($method !== "POST") apiMethodNotAllowed("POST");

$role = catalogRole($pdo, $exportCatalogId, $userId);
if (!catalogCan($role, "view")) apiError("No tienes permiso para esta acción en el catálogo.", 403);
$stmt = $pdo->prepare("SELECT * FROM catalogs WHERE id = ? AND deleted_at IS NULL");
$stmt->execute([$exportCatalogId]);
$catalog = $stmt->fetch() ?: apiError("Catálogo no encontrado.", 404);

$opts = exportOptions(requireJsonBody("las opciones de la exportación"), $catalog);
if ($opts["mode"] === "full" && !catalogCan($role, "export_full")) {
    apiError("La exportación completa (coordenadas exactas) es solo para el propietario y los custodios. Usa «Para publicar».", 403);
}

$prep = exportPrepare($pdo, $exportCatalogId, $catalog, $opts, $role, $userId);

if ($exportSub === "preview") {
    respond([
        "mode"      => $opts["mode"],
        "role"      => $role,
        "canFull"   => catalogCan($role, "export_full"),
        "defaults"  => ["title" => $catalog["name"], "description" => (string) ($catalog["description"] ?? ""),
                        "license" => array_key_first(DWC_LICENSES)],
        "licenses"  => array_map(fn($k, $l) => ["value" => $k, "label" => $l[1], "url" => $l[0]], array_keys(DWC_LICENSES), DWC_LICENSES),
        "available" => exportAvailable($pdo, $exportCatalogId),
        "summary"   => $prep["counts"],
    ]);
}

$stmt = $pdo->prepare("SELECT name FROM users WHERE id = ?");
$stmt->execute([$userId]);
try {
    [$zipPath, $zipName] = exportBuild($pdo, $exportCatalogId, $catalog, $opts, $prep, $userId, (string) $stmt->fetchColumn());
} catch (PlinianInvalidXml $e) {
    // No se entrega un ZIP con Plinian Core inválido. El detalle (línea y mensaje de libxml) ya está en el log.
    apiError("No se ha podido generar la ficha Plinian Core: no cumple el esquema oficial (" . PLIC_VERSION . "). "
        . "Descarga sin Plinian Core o avisa a quien mantiene BioDex.", 500);
}

if ($opts["mode"] === "full") {
    $c = $prep["counts"];
    logAccess($pdo, $userId, "export_full", $exportCatalogId,
        "zip completo: {$c["occurrences"]} registros, {$c["species"]} especies" . ($opts["dateFrom"] || $opts["dateTo"] ? ", con fechas" : ""));
}

header_remove("Content-Type");
header("Content-Type: application/zip");
header("Content-Disposition: attachment; filename=\"{$zipName}\"; filename*=UTF-8''" . rawurlencode($zipName));
header("Content-Length: " . filesize($zipPath));
header("Cache-Control: no-store");
header("X-Content-Type-Options: nosniff");
readfile($zipPath);
@unlink($zipPath);
exit;
