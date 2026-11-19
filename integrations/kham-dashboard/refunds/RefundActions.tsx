"use client";

/**
 * Portable KHAM dashboard component; install in the ACTUAL KHAM dashboard
 * Next.js repo. Do not install in CRM_front_end- (Nuqoosh CRM).
 */
import { useCallback, useEffect, useState } from "react";

type Refund = {
  id: number; order_id: number; requested_by: number;
  status: "pending_approval" | "approved_awaiting_provider" | "rejected" | "cancelled";
  reason: string; amount: string; currency_code: string;
};
type ApiEnvelope<T> = {
  success: boolean; code: string; message: string; data: T;
  errors?: Record<string, string[]>;
};

export function RefundActions({
  apiBaseUrl, orderId, bearerToken, currentUserId, permissions,
}: {
  apiBaseUrl: string; orderId: number; bearerToken: string;
  currentUserId: number; permissions: readonly string[];
}) {
  const [refunds, setRefunds] = useState<Refund[]>([]);
  const [busyId, setBusyId] = useState<number | null>(null);
  const [error, setError] = useState("");
  const [reason, setReason] = useState<Record<number, string>>({});
  const canRead = permissions.includes("orders.view");
  const canCancel = permissions.includes("orders.manage");
  const canApprove = permissions.includes("refunds.approve");

  const request = useCallback(async <T,>(path: string, init?: RequestInit): Promise<T> => {
    const response = await fetch(
      `${apiBaseUrl.replace(/\/$/, "")}${path}`,
      {
        ...init,
        cache: "no-store",
        headers: {
          Accept: "application/json",
          Authorization: `Bearer ${bearerToken}`,
          ...init?.headers,
        },
      }
    );
    const json = await response.json() as ApiEnvelope<T>;
    if (!response.ok || !json.success) {
      const fields = Object.values(json.errors || {}).flat().join("; ");
      throw new Error(fields || json.message || "CRM request failed.");
    }
    return json.data;
  }, [apiBaseUrl, bearerToken]);

  const refresh = useCallback(async () => {
    if (!canRead || !bearerToken) return;
    try {
      setRefunds(await request<Refund[]>(`/orders/${orderId}/refund-requests`));
    } catch (e) {
      setError(e instanceof Error ? e.message : "Failed to load refunds.");
    }
  }, [request, canRead, bearerToken, orderId]);

  useEffect(() => { void refresh(); }, [refresh]);

  async function action(refundId: number, kind: "approve" | "reject" | "cancel") {
    setBusyId(refundId); setError("");
    try {
      const payload = kind === "approve" ? { confirm: true }
        : kind === "reject" ? { reason: reason[refundId]?.trim() } : {};
      await request<Refund>(`/refund-requests/${refundId}/${kind}`, {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify(payload),
      });
      await refresh();
    } catch (e) {
      setError(e instanceof Error ? e.message : "Refund action failed.");
    } finally {
      setBusyId(null);
    }
  }

  if (!canRead) return null;

  return <section aria-label="Order refund requests">
    <h3>Refund requests</h3>
    {error && <p role="alert">{error}</p>}
    {refunds.length === 0 && <p>No refund requests for this order.</p>}
    {refunds.map(refund => {
      const pending = refund.status === "pending_approval";
      const otherApprover = refund.requested_by !== currentUserId;
      const ownRequest = refund.requested_by === currentUserId;
      const validReason = (reason[refund.id] || "").trim().length >= 5;
      return <article key={refund.id}>
        <p><strong>#{refund.id}</strong> — {refund.amount} {refund.currency_code} — {refund.status}</p>
        <p>{refund.reason}</p>
        {pending && canApprove && otherApprover && <>
          <button disabled={busyId !== null} onClick={() => void action(refund.id, "approve")}>
            Approve (awaiting provider)
          </button>
          <label htmlFor={`reject-${refund.id}`}>Rejection reason (5+ characters)</label>
          <textarea id={`reject-${refund.id}`} maxLength={2000}
            value={reason[refund.id] || ""}
            onChange={e => setReason(prev => ({ ...prev, [refund.id]: e.target.value }))} />
          <button disabled={busyId !== null || !validReason}
            onClick={() => void action(refund.id, "reject")}>Reject refund</button>
        </>}
        {pending && canCancel && ownRequest &&
          <button disabled={busyId !== null} onClick={() => void action(refund.id, "cancel")}>
            Withdraw request
          </button>}
      </article>;
    })}
  </section>;
}
