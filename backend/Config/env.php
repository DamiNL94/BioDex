<?php
// ================================================================================================
// env.php — la ÚNICA lectura de credenciales y configuración desde el entorno (adaptado de NaturDex).
// ------------------------------------------------------------------------------------------------
// Separado de db.php para que leer una variable no abra la conexión a MySQL como efecto secundario.
// Se consultan getenv(), $_ENV y $_SERVER porque, según cómo llegue la variable al proceso
// (entorno del contenedor, php-fpm...), acaba en unos sitios u otros.
// ================================================================================================

/** Valor de la primera variable de entorno definida y no vacía de la lista, o null. */
function biodex_env(array $names): ?string {
    foreach ($names as $name) {
        foreach ([getenv($name), $_ENV[$name] ?? false, $_SERVER[$name] ?? false] as $value) {
            if (is_string($value) && $value !== "") return $value;
        }
    }
    return null;
}
