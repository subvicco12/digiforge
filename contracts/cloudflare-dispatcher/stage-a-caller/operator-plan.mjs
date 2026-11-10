import { keys, insist, hex, canonical } from "../protocol.mjs";
import { APPROVED_BUILD, WORKFLOW_NAME } from "./contract.mjs";
/** Generates a public control-plane request plan ONLY. No client, token lookup or network operation. */
export function operatorPlan(input) {
  keys(input, [
    "account_id",
    "action",
    "request_id",
    "operation_id",
    "approval",
    "issued_at",
    "expires_at",
  ]);
  insist(
    hex(input.account_id, 32) &&
      ["ADVANCE_ONE", "READ_RECEIPT", "RECONCILE"].includes(input.action) &&
      hex(input.request_id, 32) &&
      hex(input.operation_id, 32) &&
      hex(input.approval) &&
      input.approval !== "0".repeat(64),
    "OPERATOR_PLAN",
  );
  insist(
    Number.isSafeInteger(input.issued_at) &&
      Number.isSafeInteger(input.expires_at) &&
      input.expires_at > input.issued_at &&
      input.expires_at - input.issued_at <= 120,
    "OPERATOR_PLAN",
  );
  if (input.action === "ADVANCE_ONE")
    insist(input.request_id === input.operation_id, "OPERATION_ID");
  const id = "stage-a-" + input.request_id;
  const { account_id, ...params } = input;
  return {
    status: "PLAN_ONLY_NOT_EXECUTION_AUTHORIZED",
    method: "POST",
    endpoint: `/accounts/${account_id}/workflows/${WORKFLOW_NAME}/instances`,
    body: {
      instance_id: id,
      params: canonical({ ...params, build: APPROVED_BUILD }),
    },
    status_read: {
      method: "GET",
      endpoint: `/accounts/${account_id}/workflows/${WORKFLOW_NAME}/instances/${id}`,
    },
    authentication:
      "Approved Cloudflare control-plane operator session; never a Worker request token",
    required_permission:
      "Workers Scripts Write for initiation; instance-read permission must be separately verified; actual grants unverified",
    automatic_retry: false,
  };
}
