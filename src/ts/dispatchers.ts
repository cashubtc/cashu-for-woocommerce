// Network dispatchers for the receipt-page poll loop.
//
// Both endpoints (claim-melt-paid + confirm-melt-quote) are server-idempotent
// and race each other on a Lightning settlement: claimMeltPaid fires once
// when the browser-side melt response shows PAID, checkOrderStatus polls on
// a [5s, 15s, 30s] backoff. Whichever lands first flips `state.finalised`
// and fires the redirect; the other short-circuits.
//
// Fully dependency-injected so they can be unit-tested without jQuery,
// wp-env, or window.location. The caller (checkout.ts init) constructs
// `DispatcherDeps` once and reuses it for every poll tick.

import type { MeltQuoteState } from '@cashu/cashu-ts';
import { composeRestUrl, deriveOrderStatusActions } from './helpers';
import { getErrorMessage } from './utils';

export type ConfirmPaidResponse = {
  ok?: boolean;
  state?: MeltQuoteState | 'EXPIRED';
  redirect?: string;
  message?: string;
  expiry?: number | null;
  last_attempt?: number | null;
};

export async function claimMeltPaid(
  deps: DispatcherDeps,
  preimage: string,
): Promise<void> {
  const endpoint = composeRestUrl(deps.config.restRoot, deps.config.claimRoute);
  if (!endpoint) return;
  const fetchImpl = deps.fetchImpl ?? fetch;
  try {
    const res = await fetchImpl(endpoint, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      credentials: 'same-origin',
      signal: deps.signal,
      body: JSON.stringify({
        order_id: deps.data.orderId,
        order_key: deps.data.orderKey,
        preimage,
      }),
    });
    const json = (await res.json()) as ConfirmPaidResponse;
    if (json?.state === 'PAID') {
      if (deps.state.finalised) return;
      deps.state.finalised = true;
      deps.setStatus(deps.t('payment_confirmed'));
      deps.doConfettiBomb();
      await deps.delay(2000);
      deps.redirect(String(json.redirect ?? deps.data.returnUrl));
    }
  } catch (e) {
    if (deps.signal.aborted) return;
    console.warn('Claim POST failed, falling back to poll:', getErrorMessage(e));
  }
}

export async function checkOrderStatus(
  deps: DispatcherDeps,
): Promise<ConfirmPaidResponse | null> {
  const endpoint = composeRestUrl(deps.config.restRoot, deps.config.confirmRoute);
  if (!endpoint) return null;
  const fetchImpl = deps.fetchImpl ?? fetch;

  try {
    const res = await fetchImpl(endpoint, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      credentials: 'same-origin',
      signal: deps.signal,
      body: JSON.stringify({
        order_id: deps.data.orderId,
        order_key: deps.data.orderKey,
        quote_id: deps.data.quoteId,
      }),
    });
    const json = (await res.json()) as ConfirmPaidResponse;

    const actions = deriveOrderStatusActions({
      json,
      nowSeconds: deps.nowSeconds(),
      finalised: deps.state.finalised,
      mintQuoteId: deps.data.mintQuote.id,
      returnUrl: deps.data.returnUrl,
    });
    for (const a of actions) {
      switch (a.type) {
        case 'updateQuoteExpiry':
          deps.data.quoteExpiryMs = a.ms;
          break;
        case 'clearStranded':
          deps.clearStrandedProofs(a.quoteId);
          break;
        case 'markFinalised':
          deps.state.finalised = true;
          break;
        case 'setStatus':
          deps.setStatus(deps.t(a.key, ...(a.args ?? [])), a.isError);
          break;
        case 'redirect':
          if (a.withConfetti) deps.doConfettiBomb();
          await deps.delay(a.delayMs);
          deps.redirect(a.url);
          break;
      }
    }

    return json ?? null;
  } catch {
    return null;
  }
}

export type DispatcherDeps = {
  config: {
    restRoot: string;
    claimRoute: string;
    confirmRoute: string;
  };
  data: {
    orderId: number;
    orderKey: string;
    quoteId: string;
    returnUrl: string;
    quoteExpiryMs: number;
    mintQuote: { id: string };
  };
  state: { finalised: boolean };
  signal: AbortSignal;
  nowSeconds: () => number;
  setStatus: (msg: string, isError?: boolean) => void;
  t: (key: string, ...args: unknown[]) => string;
  delay: (ms: number) => Promise<void>;
  doConfettiBomb: () => void;
  clearStrandedProofs: (quoteId: string) => void;
  redirect: (url: string) => void;
  fetchImpl?: typeof fetch;
};
