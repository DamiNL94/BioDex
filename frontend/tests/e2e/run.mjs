// ================================================================================================
// run.mjs — lanza Playwright desde la ruta real de la carpeta (npm test).
// En Windows, entrar con otras mayúsculas («cd desktop\biodex») hace que Node cargue @playwright/test dos
// veces (una por cada forma de la ruta) y Playwright falla con «did not expect test() to be called here».
// Aquí se pasa a la ruta tal como está en disco antes de arrancar. Los argumentos van tal cual:
//   npm test -- --project=movil -g "atrás"
// ================================================================================================
import fs from 'node:fs';
import path from 'node:path';
import { spawnSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';

const dir = fs.realpathSync.native(path.dirname(fileURLToPath(import.meta.url)));
process.chdir(dir);
const cli = path.join(dir, 'node_modules', '@playwright', 'test', 'cli.js');
const res = spawnSync(process.execPath, [cli, 'test', ...process.argv.slice(2)], { cwd: dir, stdio: 'inherit' });
process.exit(res.status ?? 1);
