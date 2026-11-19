/**
 * COPY to the KHAM Next.js frontend's preferred API helper directory.
 * This is not wired to the checkout form automatically.
 * The key must never be stored in localStorage, URL parameters, or logs.
 */
export interface TestCheckoutItem {
  variant_id: number;
  quantity: number;
}
export interface TestCheckoutPayload {
  idempotency_key: string;
  market_id: number;
  items: TestCheckoutItem[];
  customer: {
    first_name: string;
    last_name?: string | null;
    email: string;
    phone: string;
  };
  shipping_address: {
    first_name: string;
    last_name?: string | null;
    phone?: string;
    line1: string;
    line2?: string;
    city: string;
    country_code: string;
  };
}
export interface MockOrderResult {
  success: boolean;
  code: string;
  message: string;
  data?: {
    order_number: string;
    is_test_order: boolean;
    payment_status: "pending";
    currency_code: string;
  };
  errors?: Record<string, string[]>;
}

export async function submitStagingTestCheckout(
  payload: TestCheckoutPayload,
  accessKey: string,
  signal?: AbortSignal
): Promise<MockOrderResult> {
  if (!accessKey) throw new Error("Staging test access key required.");
  const result = await fetch("/api/test-checkout", {
    method: "POST",
    cache: "no-store",
    signal,
    headers: {
      "Content-Type": "application/json",
      "x-kham-test-key": accessKey,
    },
    body: JSON.stringify(payload),
  });
  const json: MockOrderResult = await result.json();
  if (!result.ok || !json.success || !json.data?.is_test_order) {
    throw new Error(json.message || "Unable to create staging order.");
  }
  return json;
}
