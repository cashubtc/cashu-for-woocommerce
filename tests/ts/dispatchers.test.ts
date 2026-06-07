import { describe, expect, test, vi } from 'vitest';
import {
  checkOrderStatus,
  claimMeltPaid,
  type DispatcherDeps,
} from '../../src/ts/dispatchers';

// ----------------------------------------------------------------------------
// Test harness — build DispatcherDeps with overrides
// ----------------------------------------------------------------------------

type DepOverrides = Partial<Omit<DispatcherDeps, 'config' | 'data' | 'state'>> & {
  config?: Partial<DispatcherDeps['config']>;
  data?: Partial<DispatcherDeps['data']>;
  state?: Partial<DispatcherDeps['state']>;
};

function buildDeps(overrides: DepOverrides = {}): DispatcherDeps {
  const ac = new AbortController();
  return {
    config: {
      restRoot: 'https://shop/wp-json/cashu-wc/v1/',
      claimRoute: 'claim-melt-paid',
      confirmRoute: 'confirm-melt-quote',
      ...overrides.config,
    },
    data: {
      orderId: 42,
      orderKey: 'wc_order_abc',
      quoteId: 'quote-xyz',
      returnUrl: 'https://shop/return',
      quoteExpiryMs: 1_700_000_000_000,
      mintQuote: { id: 'mint-quote-id' },
      ...overrides.data,
    },
    state: { finalised: false, ...overrides.state },
    signal: overrides.signal ?? ac.signal,
    nowSeconds: overrides.nowSeconds ?? (() => 1_000_000),
    setStatus: overrides.setStatus ?? vi.fn(),
    t: overrides.t ?? ((key: string) => key),
    delay: overrides.delay ?? (() => Promise.resolve()),
    doConfettiBomb: overrides.doConfettiBomb ?? vi.fn(),
    clearStrandedProofs: overrides.clearStrandedProofs ?? vi.fn(),
    redirect: overrides.redirect ?? vi.fn(),
    fetchImpl: overrides.fetchImpl,
  };
}

function jsonResponse(body: unknown): Response {
  return new Response(JSON.stringify(body), {
    status: 200,
    headers: { 'Content-Type': 'application/json' },
  });
}

// ----------------------------------------------------------------------------
// claimMeltPaid — PAID happy path
// ----------------------------------------------------------------------------

describe('claimMeltPaid — PAID happy path', () => {
  test('POSTs {order_id, order_key, preimage} to composed endpoint then redirects', async () => {
    const fetchImpl = vi
      .fn()
      .mockResolvedValue(
        jsonResponse({ state: 'PAID', redirect: 'https://shop/thanks' }),
      );
    const setStatus = vi.fn();
    const doConfettiBomb = vi.fn();
    const redirect = vi.fn();
    const deps = buildDeps({
      fetchImpl,
      setStatus,
      doConfettiBomb,
      redirect,
      t: (k: string) => `<<${k}>>`,
    });

    await claimMeltPaid(deps, 'preimage-deadbeef');

    expect(fetchImpl).toHaveBeenCalledTimes(1);
    const [url, init] = fetchImpl.mock.calls[0];
    expect(url).toBe('https://shop/wp-json/cashu-wc/v1/claim-melt-paid');
    expect(init?.method).toBe('POST');
    expect(JSON.parse(String(init?.body))).toEqual({
      order_id: 42,
      order_key: 'wc_order_abc',
      preimage: 'preimage-deadbeef',
    });

    expect(deps.state.finalised).toBe(true);
    expect(setStatus).toHaveBeenCalledWith('<<payment_confirmed>>');
    expect(doConfettiBomb).toHaveBeenCalledTimes(1);
    expect(redirect).toHaveBeenCalledWith('https://shop/thanks');
  });

  test('redirect falls back to data.returnUrl when response omits redirect', async () => {
    const fetchImpl = vi.fn().mockResolvedValue(jsonResponse({ state: 'PAID' }));
    const redirect = vi.fn();
    const deps = buildDeps({
      fetchImpl,
      redirect,
      data: { returnUrl: 'https://shop/fallback' },
    });

    await claimMeltPaid(deps, 'pre');

    expect(redirect).toHaveBeenCalledWith('https://shop/fallback');
  });
});

// ----------------------------------------------------------------------------
// claimMeltPaid — short-circuits
// ----------------------------------------------------------------------------

describe('claimMeltPaid — short-circuits', () => {
  test('missing claimRoute → no fetch, no redirect', async () => {
    const fetchImpl = vi.fn();
    const redirect = vi.fn();
    const deps = buildDeps({
      fetchImpl,
      redirect,
      config: { claimRoute: '' },
    });

    await claimMeltPaid(deps, 'pre');

    expect(fetchImpl).not.toHaveBeenCalled();
    expect(redirect).not.toHaveBeenCalled();
  });

  test('missing restRoot → no fetch, no redirect', async () => {
    const fetchImpl = vi.fn();
    const redirect = vi.fn();
    const deps = buildDeps({
      fetchImpl,
      redirect,
      config: { restRoot: '' },
    });

    await claimMeltPaid(deps, 'pre');

    expect(fetchImpl).not.toHaveBeenCalled();
    expect(redirect).not.toHaveBeenCalled();
  });

  test('non-PAID response → no redirect, no finalised flip', async () => {
    const fetchImpl = vi.fn().mockResolvedValue(jsonResponse({ state: 'PENDING' }));
    const redirect = vi.fn();
    const doConfettiBomb = vi.fn();
    const deps = buildDeps({ fetchImpl, redirect, doConfettiBomb });

    await claimMeltPaid(deps, 'pre');

    expect(deps.state.finalised).toBe(false);
    expect(redirect).not.toHaveBeenCalled();
    expect(doConfettiBomb).not.toHaveBeenCalled();
  });

  test('already finalised → PAID response triggers neither confetti nor redirect', async () => {
    const fetchImpl = vi
      .fn()
      .mockResolvedValue(
        jsonResponse({ state: 'PAID', redirect: 'https://shop/thanks' }),
      );
    const redirect = vi.fn();
    const doConfettiBomb = vi.fn();
    const setStatus = vi.fn();
    const deps = buildDeps({
      fetchImpl,
      redirect,
      doConfettiBomb,
      setStatus,
      state: { finalised: true },
    });

    await claimMeltPaid(deps, 'pre');

    expect(redirect).not.toHaveBeenCalled();
    expect(doConfettiBomb).not.toHaveBeenCalled();
    expect(setStatus).not.toHaveBeenCalled();
    expect(deps.state.finalised).toBe(true);
  });
});

// ----------------------------------------------------------------------------
// claimMeltPaid — error handling
// ----------------------------------------------------------------------------

describe('claimMeltPaid — error handling', () => {
  test('fetch throws → no exception bubbles, warns once', async () => {
    const warnSpy = vi.spyOn(console, 'warn').mockImplementation(() => {});
    const fetchImpl = vi.fn().mockRejectedValue(new Error('network down'));
    const deps = buildDeps({ fetchImpl });

    await expect(claimMeltPaid(deps, 'pre')).resolves.toBeUndefined();
    expect(warnSpy).toHaveBeenCalledTimes(1);
    expect(warnSpy.mock.calls[0][0]).toMatch(/Claim POST failed/);
  });

  test('aborted signal → no warn, no redirect', async () => {
    const warnSpy = vi.spyOn(console, 'warn').mockImplementation(() => {});
    const ac = new AbortController();
    ac.abort();
    const fetchImpl = vi.fn().mockRejectedValue(new Error('aborted'));
    const redirect = vi.fn();
    const deps = buildDeps({ fetchImpl, redirect, signal: ac.signal });

    await claimMeltPaid(deps, 'pre');

    expect(warnSpy).not.toHaveBeenCalled();
    expect(redirect).not.toHaveBeenCalled();
  });
});

// ----------------------------------------------------------------------------
// checkOrderStatus — POST shape
// ----------------------------------------------------------------------------

describe('checkOrderStatus — POST shape', () => {
  test('POSTs {order_id, order_key, quote_id} to composed confirm endpoint', async () => {
    const fetchImpl = vi.fn().mockResolvedValue(jsonResponse({ state: 'UNPAID' }));
    const deps = buildDeps({ fetchImpl });

    await checkOrderStatus(deps);

    expect(fetchImpl).toHaveBeenCalledTimes(1);
    const [url, init] = fetchImpl.mock.calls[0];
    expect(url).toBe('https://shop/wp-json/cashu-wc/v1/confirm-melt-quote');
    expect(init?.method).toBe('POST');
    expect(JSON.parse(String(init?.body))).toEqual({
      order_id: 42,
      order_key: 'wc_order_abc',
      quote_id: 'quote-xyz',
    });
  });

  test('returns parsed json on success', async () => {
    const body = { state: 'PENDING', expiry: 1_000_500 };
    const fetchImpl = vi.fn().mockResolvedValue(jsonResponse(body));
    const deps = buildDeps({ fetchImpl });

    const result = await checkOrderStatus(deps);

    expect(result).toEqual(body);
  });
});

// ----------------------------------------------------------------------------
// checkOrderStatus — action dispatch
// ----------------------------------------------------------------------------

describe('checkOrderStatus — action dispatch', () => {
  test('PAID → finalised + clearStranded(mintQuoteId) + setStatus + confetti + redirect', async () => {
    const fetchImpl = vi
      .fn()
      .mockResolvedValue(
        jsonResponse({ state: 'PAID', redirect: 'https://shop/thanks' }),
      );
    const setStatus = vi.fn();
    const doConfettiBomb = vi.fn();
    const redirect = vi.fn();
    const clearStrandedProofs = vi.fn();
    const deps = buildDeps({
      fetchImpl,
      setStatus,
      doConfettiBomb,
      redirect,
      clearStrandedProofs,
      t: (k: string) => `<<${k}>>`,
    });

    await checkOrderStatus(deps);

    expect(clearStrandedProofs).toHaveBeenCalledWith('mint-quote-id');
    expect(deps.state.finalised).toBe(true);
    expect(setStatus).toHaveBeenCalledWith('<<payment_confirmed>>', false);
    expect(doConfettiBomb).toHaveBeenCalledTimes(1);
    expect(redirect).toHaveBeenCalledWith('https://shop/thanks');
  });

  test('UNPAID with expiry → mutates data.quoteExpiryMs in place', async () => {
    const fetchImpl = vi
      .fn()
      .mockResolvedValue(jsonResponse({ state: 'UNPAID', expiry: 1_005_000 }));
    const deps = buildDeps({ fetchImpl });
    const before = deps.data.quoteExpiryMs;

    await checkOrderStatus(deps);

    expect(deps.data.quoteExpiryMs).toBe(1_005_000_000);
    expect(deps.data.quoteExpiryMs).not.toBe(before);
  });

  test('UNPAID with last_attempt → setStatus with previous-attempt-failed key + error color', async () => {
    const fetchImpl = vi
      .fn()
      .mockResolvedValue(jsonResponse({ state: 'UNPAID', last_attempt: 999_999 }));
    const setStatus = vi.fn();
    const deps = buildDeps({ fetchImpl, setStatus, t: (k: string) => `<<${k}>>` });

    await checkOrderStatus(deps);

    expect(setStatus).toHaveBeenCalledWith('<<previous_attempt_failed>>', true);
  });

  test('EXPIRED → redirect with no confetti', async () => {
    const fetchImpl = vi.fn().mockResolvedValue(jsonResponse({ state: 'EXPIRED' }));
    const doConfettiBomb = vi.fn();
    const redirect = vi.fn();
    const deps = buildDeps({ fetchImpl, doConfettiBomb, redirect });

    await checkOrderStatus(deps);

    expect(doConfettiBomb).not.toHaveBeenCalled();
    expect(redirect).toHaveBeenCalledWith('https://shop/return');
  });

  test('PAID when already finalised → clearStranded fires but no redirect', async () => {
    const fetchImpl = vi.fn().mockResolvedValue(jsonResponse({ state: 'PAID' }));
    const redirect = vi.fn();
    const doConfettiBomb = vi.fn();
    const clearStrandedProofs = vi.fn();
    const deps = buildDeps({
      fetchImpl,
      redirect,
      doConfettiBomb,
      clearStrandedProofs,
      state: { finalised: true },
    });

    await checkOrderStatus(deps);

    expect(clearStrandedProofs).toHaveBeenCalledWith('mint-quote-id');
    expect(redirect).not.toHaveBeenCalled();
    expect(doConfettiBomb).not.toHaveBeenCalled();
  });
});

// ----------------------------------------------------------------------------
// checkOrderStatus — short-circuits
// ----------------------------------------------------------------------------

describe('checkOrderStatus — short-circuits', () => {
  test('missing confirmRoute → returns null, no fetch', async () => {
    const fetchImpl = vi.fn();
    const deps = buildDeps({
      fetchImpl,
      config: { confirmRoute: '' },
    });

    const result = await checkOrderStatus(deps);

    expect(result).toBeNull();
    expect(fetchImpl).not.toHaveBeenCalled();
  });

  test('missing restRoot → returns null, no fetch', async () => {
    const fetchImpl = vi.fn();
    const deps = buildDeps({
      fetchImpl,
      config: { restRoot: '' },
    });

    const result = await checkOrderStatus(deps);

    expect(result).toBeNull();
    expect(fetchImpl).not.toHaveBeenCalled();
  });

  test('fetch throws → returns null, no exception bubble', async () => {
    const fetchImpl = vi.fn().mockRejectedValue(new Error('network'));
    const deps = buildDeps({ fetchImpl });

    const result = await checkOrderStatus(deps);

    expect(result).toBeNull();
  });
});
