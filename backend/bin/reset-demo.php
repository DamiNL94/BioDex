<?php
// ================================================================================================
// Borra los datos de demostración (F10b): docker compose exec php php bin/reset-demo.php
// Solo con APP_ENV=dev. Borra el catálogo de demo, lo que las tres cuentas de demo hayan creado al
// ensayar (sus catálogos, marcas, notas, evaluaciones y especies propias sin usar) y las cuentas.
// Nada más: ni la caché de EIDOS ni los datos de otras cuentas. Idempotente.
// ================================================================================================

require_once __DIR__ . "/demo/demo-lib.php";

demoRequireDev();
$pdo = db();
runMigrations($pdo);

[$cats, $marks, $users] = demoReset($pdo);
echo $cats || $users
    ? "Demo borrada: {$cats} catálogo(s), {$marks} marca(s), {$users} cuenta(s).\n"
    : "No había datos de demo.\n";
