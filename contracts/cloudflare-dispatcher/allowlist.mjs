import { insist, keys } from "./protocol.mjs";
// Frozen counterpart of PrintifyAcceptanceContract; parity tests pin PHP bytes.
const id = "[A-Za-z0-9_-]{1,160}",
  n = "[1-9][0-9]{0,9}",
  root = "/v1/shops/28930593";
export const RULES = Object.freeze({
  SHOPS_READ: "/v1/shops.json",
  PROVIDERS_READ: "/v1/catalog/print_providers.json",
  PROVIDER_READ: `/v1/catalog/print_providers/${n}.json`,
  BLUEPRINTS: "/v1/catalog/blueprints.json",
  BLUEPRINT: `/v1/catalog/blueprints/${n}.json`,
  BLUEPRINT_PROVIDERS: `/v1/catalog/blueprints/${n}/print_providers.json`,
  BLUEPRINT_PROVIDER: `/v1/catalog/blueprints/${n}/print_providers/${n}.json`,
  VARIANTS: `/v1/catalog/blueprints/${n}/print_providers/${n}/variants.json`,
  SIZE_GUIDE: `/v1/catalog/blueprints/${n}/size_guide.json`,
  SHIPPING_METHODS: `/v2/catalog/blueprints/${n}/print_providers/${n}/shipping.json`,
  REGIONAL_SHIPPING: `/v2/catalog/blueprints/${n}/print_providers/${n}/shipping/(?:standard|priority|express|economy).json`,
  PRODUCT_READ: `${root}/products/${id}.json`,
  PRODUCT_GPSR_READ: `${root}/products/${id}/gpsr.json`,
  PERSONALIZATION_OPTIONS: `${root}/products/${id}/personalization_options.json`,
  PREVIEW_READ: `${root}/products/${id}/personalization_previews/${id}.json`,
  IMAGE_READ: `/v1/uploads/${id}.json`,
  UPLOADS_READ: "/v1/uploads.json\\?page=[1-3]&limit=(?:[1-9]|10)",
  PRODUCTS_READ: `${root}/products.json\\?page=[1-3]&limit=(?:[1-9]|10)`,
  ORDERS_READ: `${root}/orders.json\\?page=[1-3]&limit=(?:[1-9]|10)`,
  ORDER_READ: `${root}/orders/${id}.json`,
  WEBHOOKS_READ: `${root}/webhooks.json`,
  WEBHOOK_READ: `${root}/webhooks.json`,
  SUPPORT_LIST: `${root}/orders/${id}/support-requests`,
  SUPPORT_DETAIL: `${root}/orders/${id}/support-requests/${id}`,
});
export function inspect(r) {
  keys(r, ["operation", "method", "endpoint", "body"]);
  insist(
    r.method === "GET" &&
      Array.isArray(r.body) &&
      r.body.length === 0 &&
      typeof r.endpoint === "string" &&
      r.endpoint.length <= 600 &&
      Object.hasOwn(RULES, r.operation),
    "ENDPOINT",
  );
  const rule = RULES[r.operation];
  insist(
    new RegExp("^" + rule.replaceAll(".json", "\\.json") + "$").test(
      r.endpoint,
    ) && !/[\r\n]/.test(r.endpoint),
    "ENDPOINT",
  );
  return {
    operation: r.operation,
    api_version: r.endpoint.startsWith("/v2/") ? "v2" : "v1",
    endpoint_template: rule
      .replaceAll(root, "/v1/shops/{shop}")
      .replaceAll(n, "{id}")
      .replaceAll(id, "{id}")
      .replaceAll("(?:standard|priority|express|economy)", "{method}")
      .replaceAll("[1-3]", "{page}")
      .replaceAll("(?:[1-9]|10)", "{limit}")
      .replaceAll("\\?", "?"),
  };
}
