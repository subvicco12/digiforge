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
      c.available === true &&
        c.exclusive === true &&
        c.hostCopiesInvalidated === true &&
        Number.isSafeInteger(c.generation) && c.generation > 0 &&
        c.binding === this.#config.credential_binding &&
        c.account === this.#config.account &&
        c.shop === "28930593",
      "CUSTODY_BLOCKED",
    );
  }
  async send(request, claim) {
    // Snapshot first, then validate the immutable tuple used by witness and fetch.
    request = Object.freeze({ ...request, body: Object.freeze([...request.body]) });
    inspect(request);
    this.check();
    const generation = this.#custody.generation;
    const abort = new AbortController();
    let expired = false,
      physical = false,
      callbackUsed = false,
      closed = false,
      response,
      reader,
      timer;
    const deadline = new Promise((resolve) => {
      timer = setTimeout(() => {
        expired = true;
        abort.abort();
        if (reader) reader.cancel().catch(() => {});
        // The custody/witness callback can still be pending even before fetch starts.
        resolve(unknown(true));
      }, LIMITS.timeout);
    });
    const current = () => {
      insist(!closed && !expired && !abort.signal.aborted, "DEADLINE");
      this.check();
      insist(this.#custody.generation === generation, "CUSTODY_GENERATION_CHANGED");
    };
    const physicalWork = async () => {
      try {
        current();
        response = await this.#custody.perform(() => {
          // Custody may defer this callback. Recheck at the actual physical boundary.
          insist(!callbackUsed, "CUSTODY_CALLBACK_REPLAY");
          callbackUsed = true;
          current();
          const authorization = this.#custody.syntheticAuthorization();
          current();
          physical = true;
          return this.#fetch.fetch("https://api.printify.com" + request.endpoint, {
            method: "GET",
            redirect: "error",
            signal: abort.signal,
            headers: {
              Accept: "application/json",
              "User-Agent": "DigiForge-offline-dispatcher/1",
              Authorization: authorization,
            },
          });
        });
        if (expired) return unknown(physical);
        current();
        if (!response || !Number.isInteger(response.status))
          return unknown(physical);
        if (response.status >= 300) {
          if (response.body) await response.body.cancel();
          current();
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
          current();
          return unknown(false);
        }
        reader = response.body?.getReader();
        insist(reader, "INVALID_RESPONSE");
        let chunks = [],
          size = 0;
        while (true) {
          const part = await reader.read();
          current();
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
          current();
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
      closed = true;
      abort.abort();
      clearTimeout(timer);
      // Do not await an uncooperative body: retain UNKNOWN rather than extending deadline.
      try {
        if (response?.body && !response.body.locked) response.body.cancel().catch(() => {});
        else if (reader) reader.cancel().catch(() => {});
      } catch {}
    }
  }
}
function parseRetry(value) {
  return typeof value === "string" && /^[0-9]{1,6}$/.test(value)
    ? Math.max(1, Math.min(3600, Number(value)))
    : 60;
}
