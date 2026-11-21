import { registerHooks } from "node:module";
registerHooks({
  resolve(specifier, context, next) {
    return specifier === "cloudflare:workers"
      ? {
          url: new URL("./stage-a-platform-stub.mjs", import.meta.url).href,
          shortCircuit: true,
        }
      : next(specifier, context);
  },
});
