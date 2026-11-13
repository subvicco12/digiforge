import { canonical, hash, insist, keys, Denied } from "../protocol.mjs";
import { validateStageAReceipt } from "../stage-a/driver.mjs";
import { operatorRequest, APPROVED_BUILD } from "./contract.mjs";
async function once(action) {
  let timer;
  const timeout = new Promise((_, reject) => {
    timer = setTimeout(() => reject(Error("CALLER_TIMEOUT")), 20000);
  });
  try {
    return await Promise.race([action(), timeout]);
  } finally {
    clearTimeout(timer);
  }
}
/** Only the caller service owns the target RPC; durable latch precedes its one qualification call. */
export class PrivateCaller {
  #journal;
  #service;
  #config;
  #clock;
  constructor({ journal, service, config, clock }) {
    this.#journal = journal;
    this.#service = service;
    this.#config = config;
    this.#clock = clock;
  }
  async inspect(id) {
    const op = this.#journal.read().operations[id];
    if (!op) return { status: "NOT_FOUND", retry_permitted: false };
    if (op.state === "COMPLETED")
      validateStageAReceipt(op.receipt, APPROVED_BUILD);
    return {
      status: op.state === "COMPLETED" ? "COMPLETED" : "UNKNOWN",
      operation_sha256: await hash(id),
      dispatch_attempts: op.attempts,
      receipt: op.state === "COMPLETED" ? op.receipt : null,
      reason:
        op.state === "COMPLETED"
          ? null
          : op.reason || "NO_DURABLE_TARGET_RECEIPT",
      retry_permitted: false,
      recovery: "RECOVERY_BLOCKED",
    };
  }
  async execute(request, instance = "stage-a-" + request?.request_id) {
    let admitted = false,
      admissionAttempted = false;
    try {
      operatorRequest(request, this.#config, this.#clock(), instance);
      const requestHash = await hash(canonical(request));
      operatorRequest(request, this.#config, this.#clock(), instance);
      admissionAttempted = true;
      const admission = this.#journal.admit(request, requestHash),
        state = admission.state;
      admitted = true;
      if (request.action !== "ADVANCE_ONE")
        return await this.inspect(request.operation_id);
      const op = state.operations[request.operation_id];
      if (!admission.created || op.state !== "PREPARED")
        return await this.inspect(request.operation_id);
      const status = await once(() => this.#service.status());
      keys(status, [
        "phase",
        "completed",
        "case_count",
        "invocations",
        "recovery_required",
        "results",
        "build",
        "provider_network_calls",
        "recovery",
      ]);
      insist(
        status.build === APPROVED_BUILD &&
          status.phase === "READY" &&
          status.completed === op.expected_index - 1 &&
          status.case_count === 24 &&
          status.recovery_required === false &&
          status.provider_network_calls === 0 &&
          status.recovery === "RECOVERY_BLOCKED",
        "TARGET_PREFLIGHT",
      );
      operatorRequest(request, this.#config, this.#clock(), instance);
      const latched = this.#journal.latch(request.operation_id).operations[
        request.operation_id
      ];
      const receipt = await once(() =>
        this.#service.qualify({ intent: latched.intent }),
      );
      validateStageAReceipt(receipt, APPROVED_BUILD);
      insist(receipt.case_index === op.expected_index, "CASE_SEQUENCE");
      operatorRequest(request, this.#config, this.#clock(), instance);
      insist(this.#clock() < this.#config.end, "CALLER_WINDOW");
      this.#journal.complete(request.operation_id, receipt);
      return await this.inspect(request.operation_id);
    } catch (error) {
      const uncertain =
        admitted || (admissionAttempted && !(error instanceof Denied));
      if (uncertain && request?.action === "ADVANCE_ONE")
        try {
          this.#journal.unknown(request.operation_id);
        } catch {}
      return {
        status: uncertain ? "UNKNOWN" : "DENIED",
        retry_permitted: false,
        recovery: "RECOVERY_BLOCKED",
      };
    }
  }
}
