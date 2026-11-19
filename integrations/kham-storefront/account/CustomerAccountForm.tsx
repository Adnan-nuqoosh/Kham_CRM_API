"use client";
/** Portable component for KHAM Next.js customer account area. */
import { useEffect, useState, type FormEvent } from "react";
import { createCustomerAccountApi } from "./storefront-account-api";

export default function CustomerAccountForm({
  apiBaseUrl, getToken,
}: {
  apiBaseUrl: string; getToken: () => string | null;
}) {
  const api = createCustomerAccountApi(apiBaseUrl, getToken);
  const [profile, setProfile] = useState({ first_name: "", last_name: "", phone: "" });
  const [current, setCurrent] = useState("");
  const [password, setPassword] = useState("");
  const [confirm, setConfirm] = useState("");
  const [message, setMessage] = useState("");
  const [busy, setBusy] = useState(false);
  useEffect(() => {
    let active = true;
    void api.me().then(p => {
      if (active) setProfile({ first_name: p.first_name, last_name: p.last_name || "", phone: p.phone });
    }).catch(() => { if (active) setMessage("Sign in to edit your profile."); });
    return () => { active = false; };
  // Stable props should be supplied by the hosting application.
  }, [apiBaseUrl, getToken]);

  async function submit(action: () => Promise<unknown>, success: string) {
    setBusy(true); setMessage("");
    try { await action(); setMessage(success); }
    catch (e) { setMessage(e instanceof Error ? e.message : "Request failed."); }
    finally { setBusy(false); }
  }

  return <div>
    <form onSubmit={(e: FormEvent) => {
      e.preventDefault();
      void submit(() => api.updateProfile(profile), "Profile updated.");
    }}>
      <h2>Your profile</h2>
      <input aria-label="First name" required maxLength={100}
        value={profile.first_name} onChange={e => setProfile(p => ({...p, first_name:e.target.value}))} />
      <input aria-label="Last name" maxLength={100}
        value={profile.last_name} onChange={e => setProfile(p => ({...p, last_name:e.target.value}))} />
      <input aria-label="Phone" required maxLength={30}
        value={profile.phone} onChange={e => setProfile(p => ({...p, phone:e.target.value}))} />
      <button type="submit" disabled={busy}>Save profile</button>
    </form>
    <form onSubmit={e => {
      e.preventDefault();
      void submit(async () => {
        await api.changePassword(current, password, confirm);
        setCurrent(""); setPassword(""); setConfirm("");
      }, "Password changed. Other sessions have been revoked.");
    }}>
      <h2>Change password</h2>
      <input aria-label="Current password" type="password" autoComplete="current-password"
        required value={current} onChange={e => setCurrent(e.target.value)} />
      <input aria-label="New password" type="password" autoComplete="new-password"
        required minLength={12} value={password} onChange={e => setPassword(e.target.value)} />
      <input aria-label="Confirm new password" type="password" autoComplete="new-password"
        required minLength={12} value={confirm} onChange={e => setConfirm(e.target.value)} />
      <button disabled={busy} type="submit">Change password</button>
    </form>
    {message && <p role="status">{message}</p>}
  </div>;
}
