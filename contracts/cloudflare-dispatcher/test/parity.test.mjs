import test from "node:test";
import assert from "node:assert/strict";
import { readFileSync } from "node:fs";
import { fileURLToPath } from "node:url";
import { spawnSync } from "node:child_process";
import { inspect, RULES } from "../allowlist.mjs";
import { CONTRACT_SHA, hash, b64, canonical } from "../protocol.mjs";
import { fixture } from "./support.mjs";
const root = fileURLToPath(new URL("../../../", import.meta.url));
function php(script, args, input) {
  const r = spawnSync(
    process.env.DIGIFORGE_TEST_PHP || "php",
    ["-r", script, ...args],
    { input: JSON.stringify(input), encoding: "utf8", timeout: 20000 },
  );
  assert.equal(r.status, 0, r.stderr);
  return r.stdout;
}
test("all frozen GET operations match existing authoritative PHP contracts", async () => {
  assert.equal(
    await hash(
      readFileSync(root + "includes/POD/PrintifyAcceptanceContract.php"),
    ),
    CONTRACT_SHA,
  );
  const rows = Object.entries(RULES).map(([operation, path]) => ({
    operation,
    method: "GET",
    endpoint: path
      .replaceAll("[1-9][0-9]{0,9}", "12")
      .replaceAll("[A-Za-z0-9_-]{1,160}", "product_1")
      .replaceAll("(?:standard|priority|express|economy)", "standard")
      .replaceAll("\\?", "?")
      .replaceAll("[1-3]", "1")
      .replaceAll("(?:[1-9]|10)", "10"),
    body: [],
  }));
  const code =
    'require $argv[1];$rows=json_decode(stream_get_contents(STDIN),true);$out=[];foreach($rows as $r){try{$out[]=DigiForge\\POD\\PrintifyAcceptanceContract::inspect($r,"28930593");}catch(Throwable){$out[]=false;}}echo json_encode($out);';
  const answers = JSON.parse(
    php(code, [root + "includes/POD/PrintifyAcceptanceContract.php"], rows),
  );
  for (let i = 0; i < rows.length; i++) {
    assert.notEqual(answers[i], false, rows[i].operation);
    const js = inspect(rows[i]);
    for (const key of ["operation", "api_version", "endpoint_template"])
      assert.equal(js[key], answers[i][key]);
  }
  const source = readFileSync(
    root + "includes/POD/PrintifyAcceptanceContract.php",
    "utf8",
  )
    .split("$rules=[")[1]
    .split("];")[0];
  const names = [...source.matchAll(/'([A-Z_]+)'\s*=>/g)].map((x) => x[1]);
  assert.deepEqual(Object.keys(RULES).sort(), names.sort());
});
test("pagination host method traversal and malformed requests rejected by both languages", () => {
  const base = {
    operation: "PRODUCTS_READ",
    method: "GET",
    endpoint: "/v1/shops/28930593/products.json?page=1&limit=10",
    body: [],
  };
  const rows = [
    { ...base, method: "POST" },
    { ...base, endpoint: base.endpoint.replace("page=1", "page=4") },
    { ...base, endpoint: base.endpoint.replace("limit=10", "limit=11") },
    { ...base, endpoint: base.endpoint + "&url=evil" },
    { ...base, endpoint: "https://api.printify.com" + base.endpoint },
    { ...base, endpoint: base.endpoint + "\n" },
    { ...base, body: { url: "evil" } },
    { ...base, headers: { Authorization: "caller" } },
    { ...base, endpoint: base.endpoint.replace("28930593", "28925875") },
  ];
  const code =
    'require $argv[1];$a=[];foreach(json_decode(stream_get_contents(STDIN),true) as $r){try{DigiForge\\POD\\PrintifyAcceptanceContract::inspect($r,"28930593");$a[]=true;}catch(Throwable){$a[]=false;}}echo json_encode($a);';
  const answers = JSON.parse(
    php(code, [root + "includes/POD/PrintifyAcceptanceContract.php"], rows),
  );
  for (let i = 0; i < rows.length; i++) {
    assert.throws(() => inspect(rows[i]));
    assert.equal(answers[i], false);
  }
});
test("Worker-issued Ed25519 authority remains verifiable by preserved PHP protocol", async () => {
  const f = await fixture(),
    s = await f.session();
  const publicKey = b64(
    await crypto.subtle.exportKey("raw", f.runtime.issuer.ports.readPublic),
  );
  const code =
    'require $argv[1];$v=json_decode(stream_get_contents(STDIN),true);try{DigiForge\\IndependentAcceptance\\Protocol::verified($v["envelope"],"read-v1",base64_decode($v["public"]));echo "VERIFIED";}catch(Throwable){exit(1);}';
  assert.equal(
    php(code, [root + "contracts/printify-authority/Authority.php"], {
      envelope: s.requests[0],
      public: publicKey,
    }),
    "VERIFIED",
  );
});
