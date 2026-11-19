/**
 * Copy into KHAM's actual Next.js storefront.
 * Invoke from customer account forms. Never use a CRM admin bearer token.
 */
export type AccountEnvelope<T> = {
  success: boolean; code: string; message: string; data: T;
  errors?: Record<string, string[]>;
};
export type CustomerProfile = {
  id: number; first_name: string; last_name?: string | null;
  email: string; phone: string;
};
export class AccountApiError extends Error {
  constructor(message: string, public readonly fields: Record<string, string[]> = {}) {
    super(message);
  }
}
export function createCustomerAccountApi(baseUrl: string, getToken: () => string | null) {
  const base = baseUrl.replace(/\/$/, "");
  async function call<T>(path: string, method = "GET", payload?: object, authenticated = true): Promise<T> {
    const token = authenticated ? getToken() : null;
    if (authenticated && !token) throw new AccountApiError("Please log in first.");
    const response = await fetch(base + path, {
      method, cache: "no-store",
      headers: {
        Accept: "application/json",
        ...(payload ? { "Content-Type": "application/json" } : {}),
        ...(token ? { Authorization: `Bearer ${token}` } : {}),
      },
      body: payload ? JSON.stringify(payload) : undefined,
    });
    const json = await response.json() as AccountEnvelope<T>;
    if (!response.ok || !json.success) {
      throw new AccountApiError(json.message || "Request failed.", json.errors);
    }
    return json.data;
  }
  return {
    me: () => call<CustomerProfile>("/storefront/auth/me"),
    updateProfile: (profile: Pick<CustomerProfile, "first_name" | "phone"> & { last_name?: string | null }) =>
      call<CustomerProfile>("/storefront/auth/me", "PATCH", profile),
    changePassword: (current_password: string, password: string, password_confirmation: string) =>
      call<null>("/storefront/auth/change-password", "POST", { current_password, password, password_confirmation }),
    forgotPassword: (email: string) =>
      call<null>("/storefront/auth/forgot-password", "POST", { email }, false),
    resetPassword: (email: string, token: string, password: string, password_confirmation: string) =>
      call<null>("/storefront/auth/reset-password", "POST", { email, token, password, password_confirmation }, false),
  };
}
