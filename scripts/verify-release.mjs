// Verifies a release set made by scripts/assemble-release.mjs (locally, in CI and before a deploy):
//   - only www/, devis-crm/ and MANIFEST.txt at the top level;
//   - every file listed in MANIFEST.txt exists with that size and SHA-256, and no file is unlisted;
//   - the entry points exist; nothing that must stay off the hosting is present.
//
//   node scripts/verify-release.mjs [release/reg-ru]

import { createHash } from "node:crypto";
import { readdirSync, readFileSync, statSync } from "node:fs";
import { join, relative, resolve, sep } from "node:path";

const target = resolve(process.argv[2] ?? join("release", "reg-ru"));
const problems = [];

function files(directory) {
  return readdirSync(directory, { withFileTypes: true }).flatMap((entry) => {
    const path = join(directory, entry.name);
    return entry.isDirectory() ? files(path) : [path];
  });
}

const topLevel = readdirSync(target).sort();
if (topLevel.join(",") !== "MANIFEST.txt,devis-crm,www") {
  problems.push(`unexpected top level: ${topLevel.join(", ")}`);
}

const listed = new Map();
for (const line of readFileSync(join(target, "MANIFEST.txt"), "utf8").split("\n").filter(Boolean)) {
  const match = /^([0-9a-f]{64}) +(\d+) {2}(.+)$/.exec(line);
  if (!match) {
    problems.push(`unreadable manifest line: ${line}`);
    continue;
  }
  listed.set(match[3], { hash: match[1], size: Number(match[2]) });
}

const present = files(target)
  .map((file) => relative(target, file).split(sep).join("/"))
  .filter((path) => path !== "MANIFEST.txt");
for (const path of present) {
  const entry = listed.get(path);
  if (!entry) {
    problems.push(`not in the manifest: ${path}`);
    continue;
  }
  const content = readFileSync(join(target, path));
  if (statSync(join(target, path)).size !== entry.size || createHash("sha256").update(content).digest("hex") !== entry.hash) {
    problems.push(`differs from the manifest: ${path}`);
  }
}
for (const path of listed.keys()) {
  if (!present.includes(path)) {
    problems.push(`missing: ${path}`);
  }
}

const required = [
  "www/crm.devisd.online/index.html",
  "www/crm.devisd.online/api/index.php",
  "www/crm.devisd.online/api/.htaccess",
  "devis-crm/api/src/bootstrap.php",
  "devis-crm/api/src/Application.php",
];
for (const path of required) {
  if (!listed.has(path)) {
    problems.push(`required file missing: ${path}`);
  }
}

const forbidden = [
  /(^|\/)local\.php$/,
  /(^|\/)\.env/,
  /(^|\/)\.git(\/|$)/,
  /\.(sql|log)$/,
  /(^|\/)(bin|tests|migrations|config|logs|tools|node_modules)\//,
];
for (const path of listed.keys()) {
  if (forbidden.some((pattern) => pattern.test(path))) {
    problems.push(`must not be uploaded: ${path}`);
  }
  if (!path.startsWith("www/crm.devisd.online/") && !path.startsWith("devis-crm/api/src/")) {
    problems.push(`outside the deploy paths: ${path}`);
  }
  if (path.startsWith("devis-crm/api/src/") && !path.endsWith(".php")) {
    problems.push(`non-PHP file in the private code: ${path}`);
  }
}
const publicApi = [...listed.keys()].filter((path) => path.startsWith("www/crm.devisd.online/api/"));
if (publicApi.sort().join(",") !== "www/crm.devisd.online/api/.htaccess,www/crm.devisd.online/api/index.php") {
  problems.push(`public API directory must hold only index.php and .htaccess: ${publicApi.join(", ")}`);
}

if (problems.length > 0) {
  console.error(`verify-release: ${target} is not a valid release set`);
  for (const problem of problems) {
    console.error(`  - ${problem}`);
  }
  process.exit(1);
}
console.log(`verify-release: ${listed.size} files match MANIFEST.txt; entry points present; no forbidden files`);
