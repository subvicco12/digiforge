// Offline API-shape fixture, not a hosted Cloudflare runtime.
export class WorkerEntrypoint {
  constructor(ctx, env) {
    this.ctx = ctx;
    this.env = env;
  }
}
export class DurableObject extends WorkerEntrypoint {}
