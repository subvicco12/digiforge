import { insist } from "../protocol.mjs";
const sealed = new WeakMap();
const names = Object.freeze([
  "fetch",
  "WebSocket",
  "XMLHttpRequest",
  "EventSource",
  "WebTransport",
]);
/** Source-confinement guard, not a Cloudflare network firewall or protection from malicious redeployment. */
export function sealEgress(target = globalThis) {
  if (sealed.has(target)) return sealed.get(target);
  const deny = () => {
    throw Error("STAGE_A_EGRESS_DENIED");
  };
  try {
    for (const name of names)
      Object.defineProperty(target, name, {
        value: deny,
        writable: false,
        configurable: false,
      });
  } catch {
    throw Error("STAGE_A_EGRESS_SEAL_UNAVAILABLE");
  }
  insist(
    names.every(
      (n) =>
        target[n] === deny &&
        Object.getOwnPropertyDescriptor(target, n).writable === false,
    ),
    "STAGE_A_EGRESS_SEAL_UNAVAILABLE",
  );
  const guard = Object.freeze({
    check: () =>
      insist(
        names.every((n) => target[n] === deny),
        "STAGE_A_EGRESS_SEAL_UNAVAILABLE",
      ),
  });
  sealed.set(target, guard);
  return guard;
}
