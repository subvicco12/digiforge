import { OfflineRuntime } from "./runtime.mjs";
import { canonical, insist, keys } from "./protocol.mjs";
export function reply(body, status = 200) {
  return new Response(JSON.stringify(body), {
    status,
    headers: {
      "Content-Type": "application/json",
      "Cache-Control": "no-store",
      "X-Content-Type-Options": "nosniff",
    },
  });
}
export async function ingress(request, runtime) {
  if (
    !(runtime instanceof OfflineRuntime) ||
    runtime.mode !== "TESTED_SYNTHETIC"
  )
    return reply({ status: "LIVE_EXECUTION_NOT_AUTHORIZED" }, 503);
  const u = new URL(request.url);
  if (
    u.origin !== "https://dispatcher.fixture.invalid" ||
    u.search ||
    u.hash ||
    !["/v1/session", "/v1/dispatch", "/v1/emergency"].includes(u.pathname)
  )
    return reply({ status: "NOT_FOUND" }, 404);
  if (request.method !== "POST") return reply({ status: "METHOD_DENIED" }, 405);
  try {
    insist(
      request.headers.get("content-type") === "application/json" &&
        !request.headers.has("authorization") &&
        !request.headers.has("cookie"),
      "HEADERS",
    );
    const reader = request.body?.getReader();
    insist(reader, "BODY");
    let size = 0,
      parts = [];
    try {
      while (true) {
        const v = await reader.read();
        if (v.done) break;
        size += v.value.byteLength;
        insist(size <= 16384, "SIZE");
        parts.push(v.value);
      }
    } finally {
      reader.releaseLock();
    }
    const bytes = new Uint8Array(size);
    let at = 0;
    for (const p of parts) {
      bytes.set(p, at);
      at += p.byteLength;
    }
    const raw = new TextDecoder("utf-8", { fatal: true }).decode(bytes),
      data = JSON.parse(raw);
    insist(canonical(data) === raw, "CANONICAL_REQUIRED");
    keys(
      data,
      u.pathname === "/v1/session"
        ? ["proof", "approval", "scope"]
        : u.pathname === "/v1/dispatch"
          ? ["authority", "authentication"]
          : ["authority"],
    );
    const result =
      await runtime[
        u.pathname === "/v1/session"
          ? "createSession"
          : u.pathname === "/v1/dispatch"
            ? "dispatch"
            : "emergency"
      ](data);
    return reply(result, result.status === "DENIED" ? 403 : 200);
  } catch {
    return reply({ status: "REQUEST_DENIED", retry_permitted: false }, 400);
  }
}
/** Compatible constructor/fetch shape; no deployment bindings or production factory. */
export class PrintifyAcceptanceDO {
  constructor(ctx, env) {
    this.runtime = env?.OFFLINE_RUNTIME;
    this.ready = ctx.blockConcurrencyWhile(async () => {
      if (
        !(this.runtime instanceof OfflineRuntime) ||
        this.runtime.state.storage !== ctx.storage
      )
        return false;
      return this.runtime.startup();
    });
  }
  async fetch(request) {
    try {
      if (!(await this.ready))
        return reply({ status: "RECOVERY_REQUIRED" }, 503);
      return ingress(request, this.runtime);
    } catch {
      return reply({ status: "DEPENDENCY_UNAVAILABLE" }, 503);
    }
  }
}
export default {
  async fetch(request, env) {
    return ingress(request, env?.OFFLINE_RUNTIME);
  },
};
