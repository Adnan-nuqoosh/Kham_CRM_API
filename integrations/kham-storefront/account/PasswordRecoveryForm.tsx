"use client";
import { useState, type FormEvent } from "react";
import { createCustomerAccountApi } from "./storefront-account-api";

export default function PasswordRecoveryForm({
  apiBaseUrl, resetToken, resetEmail,
}: {
  apiBaseUrl: string;
  resetToken?: string | null;
  resetEmail?: string | null;
}) {
  const api = createCustomerAccountApi(apiBaseUrl, () => null);
  const [email, setEmail] = useState(resetEmail || "");
  const [password, setPassword] = useState("");
  const [confirmation, setConfirmation] = useState("");
  const [result, setResult] = useState("");
  const [busy, setBusy] = useState(false);
  const isReset = !!resetToken;
  async function submit(e: FormEvent) {
    e.preventDefault(); setBusy(true); setResult("");
    try {
      if (resetToken) {
        await api.resetPassword(email, resetToken, password, confirmation);
        setResult("Password reset successfully. Sign in again.");
      } else {
        await api.forgotPassword(email);
        setResult("If this email is registered, a password reset link will be sent.");
      }
    } catch (e) {
      setResult(e instanceof Error ? e.message : "Request failed.");
    } finally { setBusy(false); }
  }
  return <form onSubmit={e => void submit(e)}>
    <h2>{isReset ? "Reset password" : "Forgot password"}</h2>
    <input type="email" required autoComplete="email" aria-label="Email"
      value={email} onChange={e => setEmail(e.target.value)} />
    {isReset && <>
      <input type="password" required minLength={12} autoComplete="new-password"
        aria-label="New password" value={password} onChange={e => setPassword(e.target.value)} />
      <input type="password" required minLength={12} autoComplete="new-password"
        aria-label="Confirm password" value={confirmation} onChange={e => setConfirmation(e.target.value)} />
    </>}
    <button type="submit" disabled={busy}>{isReset ? "Set new password" : "Send reset link"}</button>
    {result && <p role="status">{result}</p>}
  </form>;
}
