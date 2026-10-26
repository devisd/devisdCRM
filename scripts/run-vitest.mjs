/**
 * Windows-safe Vitest launcher.
 *
 * Vitest 5.0.x can load two module instances when the CLI is resolved via a
 * lowercase drive letter (c:\\...) while Vite normalizes module ids to C:/...
 * describe() then throws: Cannot read properties of undefined (reading 'config').
 * See: https://github.com/vitest-dev/vitest/issues/10692
 *
 * Both the working directory and the Vitest entry path must use an uppercase
 * drive letter so Node's module registry keeps a single runtime instance.
 */
import { spawnSync } from "node:child_process";
import path from "node:path";
import { fileURLToPath } from "node:url";

function withUppercaseDrive(filePath) {
  if (process.platform !== "win32") {
    return filePath;
  }
  return filePath.replace(/^([a-zA-Z]):/, (_, letter) => `${letter.toUpperCase()}:`);
}

const projectRoot = withUppercaseDrive(
  path.resolve(path.dirname(fileURLToPath(import.meta.url)), ".."),
);
const vitestEntry = withUppercaseDrive(
  path.join(projectRoot, "node_modules", "vitest", "vitest.mjs"),
);

const result = spawnSync(process.execPath, [vitestEntry, ...process.argv.slice(2)], {
  cwd: projectRoot,
  stdio: "inherit",
  env: process.env,
});

if (result.error) {
  console.error(result.error);
  process.exit(1);
}

process.exit(result.status ?? 1);
