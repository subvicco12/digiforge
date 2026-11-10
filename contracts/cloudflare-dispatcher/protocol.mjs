/** Portable WebCrypto protocol; no Node, WordPress, network or credential fallback. */
export const LIMITS = Object.freeze({
  ttl: 300,
  slots: 10,
  perMinute: 3,
  pages: 3,
  records: 10,
  bytes: 1048576,
  timeout: 20000,
});
export const CONTRACT_SHA =
  "d04cea4a3c9133b507a369577da6e82e529e2d1ad87b4cc03eea3389aaea542e";
export class Denied extends Error {
  constructor(code = "DENIED") {
    super(code);
    this.code = code;
  }
}
export function insist(ok, code = "DENIED") {
  if (!ok) throw new Denied(code);
}
export function canonical(v, limit = 16384, depth = 0) {
  insist(depth <= 24, "SCHEMA");
  let out;
  if (v === null || typeof v === "boolean" || Number.isSafeInteger(v))
    out = JSON.stringify(v);
  else if (typeof v === "string") {
    insist(
      v.length <= 4096 &&
        !/[\uD800-\uDBFF](?![\uDC00-\uDFFF])|(?<![\uD800-\uDBFF])[\uDC00-\uDFFF]/u.test(
          v,
        ),
      "SCHEMA",
    );
    out = JSON.stringify(v);
  } else if (Array.isArray(v)) {
    insist(v.length <= 2048, "SCHEMA");
    out = "[" + v.map((x) => canonical(x, limit, depth + 1)).join(",") + "]";
  } else {
    insist(v && Object.getPrototypeOf(v) === Object.prototype, "SCHEMA");
    const keys = Object.keys(v).sort();
    insist(
      keys.length <= 2048 &&
        !keys.some((k) =>
          ["__proto__", "constructor", "prototype"].includes(k),
        ),
      "SCHEMA",
    );
    out =
      "{" +
      keys
        .map(
          (k) =>
            canonical(k, limit, depth + 1) +
            ":" +
            canonical(v[k], limit, depth + 1),
        )
        .join(",") +
      "}";
  }
  insist(new TextEncoder().encode(out).length <= limit, "SIZE");
  return out;
}
export function keys(v, wanted) {
  insist(v && !Array.isArray(v) && typeof v === "object", "SCHEMA");
  insist(
    Object.keys(v).sort().join("|") === [...wanted].sort().join("|"),
    "SCHEMA",
  );
}
export function hex(v, n = 64) {
  return typeof v === "string" && new RegExp(`^[a-f0-9]{${n}}$`).test(v);
}
export async function hash(v) {
  const b = typeof v === "string" ? new TextEncoder().encode(v) : v;
  return Array.from(
    new Uint8Array(await crypto.subtle.digest("SHA-256", b)),
    (x) => x.toString(16).padStart(2, "0"),
  ).join("");
}
export function b64(bytes) {
  return btoa(String.fromCharCode(...new Uint8Array(bytes)));
}
export function unb64(v, len) {
  insist(typeof v === "string" && v.length < 4096, "SIGNATURE");
  let b;
  try {
    b = Uint8Array.from(atob(v), (x) => x.charCodeAt(0));
  } catch {
    throw new Denied("SIGNATURE");
  }
  insist(b.length === len && b64(b) === v, "SIGNATURE");
  return b;
}
export async function sign(key, id, manifest) {
  return {
    key_id: id,
    manifest,
    signature: b64(
      await crypto.subtle.sign(
        "Ed25519",
        key,
        new TextEncoder().encode(canonical({ key_id: id, manifest })),
      ),
    ),
  };
}
export async function verify(envelope, id, key) {
  keys(envelope, ["key_id", "manifest", "signature"]);
  insist(envelope.key_id === id, "SIGNATURE");
  canonical(envelope.manifest);
  insist(
    await crypto.subtle.verify(
      "Ed25519",
      key,
      unb64(envelope.signature, 64),
      new TextEncoder().encode(
        canonical({ key_id: id, manifest: envelope.manifest }),
      ),
    ),
    "SIGNATURE",
  );
  return envelope.manifest;
}
export function freshness(m, now, ttl = 300) {
  insist(
    Number.isSafeInteger(now) &&
      now > 0 &&
      Number.isSafeInteger(m.issued_at) &&
      Number.isSafeInteger(m.expires_at) &&
      m.issued_at <= now &&
      m.expires_at > now &&
      m.expires_at > m.issued_at &&
      m.expires_at - m.issued_at <= ttl,
    "EXPIRED",
  );
}
export function binding(m, c) {
  for (const key of ["authority", "epoch", "account", "credential_binding"])
    insist(m[key] === c[key], "BINDING");
  insist(
    m.actor === 1 &&
      m.shop_key === "personalized_pod" &&
      m.internal_shop_id === 2 &&
      m.integration_id === 3 &&
      m.provider_shop_id === "28930593",
    "BINDING",
  );
}
export function observations(data) {
  insist(data && typeof data === "object", "INVALID_RESPONSE");
  const names = [
    "id",
    "data",
    "variants",
    "print_areas",
    "size_guide",
    "shipping",
    "status",
    "line_items",
    "current_page",
    "last_page",
    "per_page",
    "support_requests",
    "support_request",
    "subscriptions",
    "production_time",
    "tracking",
    "images",
    "blueprint_id",
    "print_provider_id",
  ];
  const fields = {};
  for (const k of names)
    fields[k] = Object.hasOwn(data, k)
      ? Array.isArray(data[k])
        ? "array"
        : data[k] === null
          ? "null"
          : typeof data[k]
      : "absent";
  return {
    container: Array.isArray(data) ? "list" : "object",
    item_count: Array.isArray(data) ? Math.min(data.length, 1000) : null,
    fields,
    values_disclosed: false,
  };
}

export async function bindingHash(c) {
  return hash(
    canonical({
      authority: c.authority,
      epoch: c.epoch,
      namespace: c.namespace,
      code: c.code,
      account: c.account,
      credential_binding: c.credential_binding,
      shop_key: "personalized_pod",
      internal_shop_id: 2,
      integration_id: 3,
      provider_shop_id: "28930593",
    }),
  );
}

export const CAPABILITY = "printify.acceptance.read",
  POLICY_REVISION =
    "ffbbfe63ecc4223d3192c7fc01a8a33e872cfb51f78a896696f2537465526c9e";
