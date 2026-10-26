// Assembles the files to upload to REG.RU into release/reg-ru/, laid out like the hosting home:
//   www/crm.devisd.online/            static frontend (out/, from `npm run build`)
//   www/crm.devisd.online/api/        public API entry: index.php, .htaccess
//   devis-crm/api/src/                private API code
//   devis-crm/api/bin/migrate-002-auth.php, devis-crm/api/migrations/{002_auth.sql,checks/002_auth_check.sql}
//                                     one-time migration runner, started by cron (CLI only)
// Never copied: config/local.php, logs, other api/bin tools, api/tests, other migrations, tools/.
// MANIFEST.txt lists every file with its size and SHA-256 to compare after uploading.
//
//   node scripts/assemble-release.mjs

import { createHash } from "node:crypto";
import { cpSync, existsSync, mkdirSync, readdirSync, readFileSync, rmSync, statSync, writeFileSync } from "node:fs";
import { dirname, join, relative, sep } from "node:path";
import { fileURLToPath } from "node:url";

const root = join(dirname(fileURLToPath(import.meta.url)), "..");
const target = join(root, "release", "reg-ru");
const site = join(target, "www", "crm.devisd.online");

function fail(message) {
  console.error(`assemble-release: ${message}`);
  process.exit(1);
}

function files(directory) {
  return readdirSync(directory, { withFileTypes: true }).flatMap((entry) => {
    const path = join(directory, entry.name);
    return entry.isDirectory() ? files(path) : [path];
  });
}

const out = join(root, "out");
if (!existsSync(join(out, "index.html"))) {
  fail("out/index.html is missing: run `npm run build` first");
}
if (existsSync(join(out, "api"))) {
  fail("out/api exists and would collide with the API directory");
}
const apiSource = join(root, "api", "src");
const unexpected = files(apiSource).filter((file) => !file.endsWith(".php"));
if (unexpected.length > 0) {
  fail(`api/src contains non-PHP files: ${unexpected.map((file) => relative(root, file)).join(", ")}`);
}

rmSync(target, { recursive: true, force: true });
mkdirSync(join(site, "api"), { recursive: true });
cpSync(out, site, { recursive: true });
cpSync(join(root, "api", "public", "index.php"), join(site, "api", "index.php"));
cpSync(join(root, "api", "public", ".htaccess"), join(site, "api", ".htaccess"));
cpSync(apiSource, join(target, "devis-crm", "api", "src"), { recursive: true });
for (const file of ["bin/migrate-002-auth.php", "migrations/002_auth.sql", "migrations/checks/002_auth_check.sql"]) {
  mkdirSync(dirname(join(target, "devis-crm", "api", file)), { recursive: true });
  cpSync(join(root, "api", file), join(target, "devis-crm", "api", file));
}

const manifest = files(target)
  .map((file) => {
    const path = relative(target, file).split(sep).join("/");
    const hash = createHash("sha256").update(readFileSync(file)).digest("hex");
    return `${hash}  ${String(statSync(file).size).padStart(9)}  ${path}`;
  })
  .sort((a, b) => a.slice(77).localeCompare(b.slice(77)));
if (manifest.some((line) => /local\.php|\.log$|hash-owner-password|\/tests\//.test(line) || (/\.sql$/.test(line) && !/ {2}devis-crm\/api\/migrations\//.test(line)))) {
  fail("a file that must not be uploaded ended up in the release");
}
writeFileSync(join(target, "MANIFEST.txt"), `${manifest.join("\n")}\n`);

const count = (prefix) => manifest.filter((line) => line.slice(77).startsWith(prefix)).length;
console.log(`release/reg-ru ready: ${manifest.length} files`);
console.log(`  www/crm.devisd.online/api/  ${count("www/crm.devisd.online/api/")} files (public API entry)`);
console.log(`  www/crm.devisd.online/      ${count("www/crm.devisd.online/") - count("www/crm.devisd.online/api/")} files (frontend)`);
console.log(`  devis-crm/api/src/          ${count("devis-crm/api/src/")} files (private API)`);
