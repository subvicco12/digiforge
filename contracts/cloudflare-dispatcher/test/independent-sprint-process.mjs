// Test-only synchronous process boundary for the existing authority journal.
import { readFileSync } from "node:fs";
import { LocalSqliteStorage } from "./support.mjs";
import { AuthorityJournal } from "../authority-journal.mjs";

const operations = new Set([
  "initialize", "boot", "active", "read", "claim", "anchor", "lease", "complete", "stop", "fence",
]);
const path = process.argv[2];
const input = readFileSync(0, "utf8");
if (!path || !path.endsWith("/authority.sqlite") || input.length > 16384) process.exit(2);
const { operation, clock, args, crashAfterCommit } = JSON.parse(input);
if (!operations.has(operation) || !Number.isSafeInteger(clock) || !Array.isArray(args)) process.exit(2);
const storage = new LocalSqliteStorage(path);
try {
  storage.db.exec("PRAGMA journal_mode=WAL; PRAGMA synchronous=FULL");
  const journal = new AuthorityJournal(storage, () => clock);
  const value = journal[operation](...args);
  if (operation === "stop" && crashAfterCommit === true) process.kill(process.pid, "SIGKILL");
  process.stdout.write(JSON.stringify({ ok: true, value: value ?? null }));
} catch (e) {
  process.stdout.write(JSON.stringify({ ok: false, code: e.code ?? "INDEPENDENT_PORT_UNAVAILABLE" }));
} finally {
  storage.db.close();
}
