import { insist, LIMITS, observations } from "./protocol.mjs";
import { inspect } from "./allowlist.mjs";
const unknown = (uncertain = false) => ({
  status: "UNKNOWN",
  http_status: null,
  observations: { values_disclosed: false },
  retry_after: 0,
  uncertain_inflight: uncertain,
});
/** Privileged physical service. The Worker receives minimized results, never credentials/raw bodies. */
export class SyntheticFinalSender {
  #custody;
  #fetch;
  #witness;
  #config;
  constructor(custody, syntheticFetch, witness, config) {
    insist(
      custody?.synthetic === true && syntheticFetch?.synthetic === true,
      "LIVE_SENDER_NOT_IMPLEMENTED",
    );
    this.#custody = custody;
    this.#fetch = syntheticFetch;
    this.#witness = witness;
    this.#config = config;
  }
  check() {
    const c = this.#custody;
    insist(
      c.available &&
        c.exclusive &&
        c.hostCopiesInvalidated &&
        c.binding === this.#config.credential_binding &&
        c.account === this.#config.account &&
        c.shop === "28930593",
      "CUSTODY_BLOCKED",
    );
  }
  async send(request, claim) {
    inspect(request);
    this.check();
    const abort = new AbortController();
    let expired = false,
      physical = false,
      reader,
      timer;
    const deadline = new Promise((resolve) => {
      timer = setTimeout(() => {
        expired = true;
        abort.abort();
        if (reader) reader.cancel().catch(() => {});
        resolve(unknown(physical));
      }, LIMITS.timeout);
    });
    const physicalWork = async () => {
      try {
        insist(!expired, "DEADLINE");
        this.check();
        physical = true;
        const response = await this.#custody.perform(() =>
          this.#fetch.fetch("https://api.printify.com" + request.endpoint, {
            method: "GET",
            redirect: "error",
            signal: abort.signal,
            headers: {
              Accept: "application/json",
              "User-Agent": "DigiForge-offline-dispatcher/1",
              Authorization: this.#custody.syntheticAuthorization(),
            },
          }),
        );
        if (expired) return unknown(physical);
        if (!response || !Number.isInteger(response.status))
          return unknown(physical);
        if (response.status >= 300) {
          if (response.body) await response.body.cancel();
          return {
            status: "REJECTED",
            http_status: response.status,
            observations: { values_disclosed: false },
            retry_after:
              response.status === 429
                ? parseRetry(response.headers.get("retry-after"))
                : 0,
            uncertain_inflight: false,
          };
        }
        if (response.status !== 200) {
          if (response.body) await response.body.cancel();
          return unknown(false);
        }
        reader = response.body?.getReader();
        insist(reader, "INVALID_RESPONSE");
        let chunks = [],
          size = 0;
        while (true) {
          const part = await reader.read();
          if (part.done) break;
          size += part.value.byteLength;
          if (size > LIMITS.bytes) {
            await reader.cancel();
            return unknown(false);
          }
          chunks.push(part.value);
        }
        const raw = new Uint8Array(size);
        let at = 0;
        for (const chunk of chunks) {
          raw.set(chunk, at);
          at += chunk.byteLength;
        }
        try {
          return {
            status: "OBSERVED",
            http_status: 200,
            observations: observations(
              JSON.parse(new TextDecoder("utf-8", { fatal: true }).decode(raw)),
            ),
            retry_after: 0,
            uncertain_inflight: false,
          };
        } catch {
          return unknown(false);
        }
      } catch {
        return unknown(physical);
      } finally {
        try {
          reader?.releaseLock();
        } catch {}
      }
    };
    // Entire physical lifecycle is inside witness-owned admission/completion, not just headers.
    const work = this.#witness
      .admitAndSend(claim, request, physicalWork)
      .catch(() => unknown(physical));
    try {
      return await Promise.race([work, deadline]);
    } finally {
      clearTimeout(timer);
    }
  }
}
function parseRetry(value) {
  return typeof value === "string" && /^[0-9]{1,6}$/.test(value)
    ? Math.max(1, Math.min(3600, Number(value)))
    : 60;
}
