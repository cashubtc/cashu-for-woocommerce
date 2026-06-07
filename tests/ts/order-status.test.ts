import { describe, expect, test } from 'vitest';
import {
  deriveOrderStatusActions,
  type OrderStatusAction,
  type OrderStatusContext,
} from '../../src/ts/helpers';

const baseCtx = (
  json: OrderStatusContext['json'],
  overrides: Partial<OrderStatusContext> = {},
): OrderStatusContext => ({
  json,
  nowSeconds: 1_000_000,
  finalised: false,
  mintQuoteId: 'mint-quote-id',
  returnUrl: 'https://shop/return',
  ...overrides,
});

const findAction = <T extends OrderStatusAction['type']>(
  actions: OrderStatusAction[],
  type: T,
): Extract<OrderStatusAction, { type: T }> | undefined =>
  actions.find((a) => a.type === type) as
    | Extract<OrderStatusAction, { type: T }>
    | undefined;

// ----------------------------------------------------------------------------
// Empty / null
// ----------------------------------------------------------------------------

describe('deriveOrderStatusActions — empty response', () => {
  test('null response yields no actions', () => {
    expect(deriveOrderStatusActions(baseCtx(null))).toEqual([]);
  });

  test('empty object response yields no actions', () => {
    expect(deriveOrderStatusActions(baseCtx({}))).toEqual([]);
  });
});

// ----------------------------------------------------------------------------
// Expiry refresh
// ----------------------------------------------------------------------------

describe('deriveOrderStatusActions — expiry refresh', () => {
  test('positive expiry triggers updateQuoteExpiry in ms', () => {
    const actions = deriveOrderStatusActions(
      baseCtx({ state: 'UNPAID', expiry: 1_005_000 }),
    );
    expect(findAction(actions, 'updateQuoteExpiry')).toEqual({
      type: 'updateQuoteExpiry',
      ms: 1_005_000_000,
    });
  });

  test('zero expiry skips updateQuoteExpiry', () => {
    const actions = deriveOrderStatusActions(baseCtx({ state: 'UNPAID', expiry: 0 }));
    expect(findAction(actions, 'updateQuoteExpiry')).toBeUndefined();
  });

  test('null expiry skips updateQuoteExpiry', () => {
    const actions = deriveOrderStatusActions(baseCtx({ state: 'UNPAID', expiry: null }));
    expect(findAction(actions, 'updateQuoteExpiry')).toBeUndefined();
  });
});

// ----------------------------------------------------------------------------
// PAID
// ----------------------------------------------------------------------------

describe('deriveOrderStatusActions — PAID', () => {
  test('clears stranded + finalises + setStatus + redirect-with-confetti', () => {
    const actions = deriveOrderStatusActions(
      baseCtx({ state: 'PAID', redirect: 'https://shop/thanks' }),
    );
    expect(actions.map((a) => a.type)).toEqual([
      'clearStranded',
      'markFinalised',
      'setStatus',
      'redirect',
    ]);
    expect(findAction(actions, 'clearStranded')).toEqual({
      type: 'clearStranded',
      quoteId: 'mint-quote-id',
    });
    expect(findAction(actions, 'setStatus')).toEqual({
      type: 'setStatus',
      key: 'payment_confirmed',
      isError: false,
    });
    expect(findAction(actions, 'redirect')).toEqual({
      type: 'redirect',
      url: 'https://shop/thanks',
      withConfetti: true,
      delayMs: 2000,
    });
  });

  test('falls back to returnUrl when response omits redirect', () => {
    const actions = deriveOrderStatusActions(baseCtx({ state: 'PAID' }));
    expect(findAction(actions, 'redirect')?.url).toBe('https://shop/return');
  });

  test('when already finalised, only clears stranded (no redirect)', () => {
    const actions = deriveOrderStatusActions(
      baseCtx({ state: 'PAID' }, { finalised: true }),
    );
    expect(actions.map((a) => a.type)).toEqual(['clearStranded']);
  });
});

// ----------------------------------------------------------------------------
// EXPIRED
// ----------------------------------------------------------------------------

describe('deriveOrderStatusActions — EXPIRED', () => {
  test('clears stranded + setStatus error + redirect no confetti', () => {
    const actions = deriveOrderStatusActions(baseCtx({ state: 'EXPIRED' }));
    expect(findAction(actions, 'clearStranded')).toEqual({
      type: 'clearStranded',
      quoteId: 'mint-quote-id',
    });
    expect(findAction(actions, 'setStatus')).toEqual({
      type: 'setStatus',
      key: 'invoice_expired',
      isError: true,
    });
    expect(findAction(actions, 'redirect')).toEqual({
      type: 'redirect',
      url: 'https://shop/return',
      withConfetti: false,
      delayMs: 2000,
    });
  });
});

// ----------------------------------------------------------------------------
// PENDING
// ----------------------------------------------------------------------------

describe('deriveOrderStatusActions — PENDING', () => {
  test('sets settling status (no redirect, not error)', () => {
    const actions = deriveOrderStatusActions(baseCtx({ state: 'PENDING' }));
    expect(findAction(actions, 'setStatus')).toEqual({
      type: 'setStatus',
      key: 'settling_at_mint',
      isError: false,
    });
    expect(findAction(actions, 'redirect')).toBeUndefined();
  });

  test('PENDING + expiry-near priority: shows settling, NOT countdown', () => {
    // The bug we're fixing: previously, if PENDING and expiry < 5min,
    // the countdown would overwrite the settling status.
    const expirySoon = 1_000_100; // 100s from now (under 5min threshold)
    const actions = deriveOrderStatusActions(
      baseCtx({ state: 'PENDING', expiry: expirySoon }),
    );
    const status = findAction(actions, 'setStatus');
    expect(status?.key).toBe('settling_at_mint');
    expect(status?.key).not.toBe('invoice_expires_in');
  });
});

// ----------------------------------------------------------------------------
// UNPAID + last_attempt (the previous-attempt-failed banner)
// ----------------------------------------------------------------------------

describe('deriveOrderStatusActions — UNPAID with last_attempt', () => {
  test('shows previous-attempt-failed banner with error color', () => {
    const actions = deriveOrderStatusActions(
      baseCtx({ state: 'UNPAID', last_attempt: 999_999 }),
    );
    expect(findAction(actions, 'setStatus')).toEqual({
      type: 'setStatus',
      key: 'previous_attempt_failed',
      isError: true,
    });
  });

  test('UNPAID + last_attempt + expiry-near priority: shows banner, NOT countdown', () => {
    // This is the priority bug we're fixing — banner should win.
    const actions = deriveOrderStatusActions(
      baseCtx({ state: 'UNPAID', last_attempt: 999_999, expiry: 1_000_100 }),
    );
    const status = findAction(actions, 'setStatus');
    expect(status?.key).toBe('previous_attempt_failed');
    expect(status?.key).not.toBe('invoice_expires_in');
  });

  test('last_attempt of 0 is treated as no previous attempt', () => {
    const actions = deriveOrderStatusActions(
      baseCtx({ state: 'UNPAID', last_attempt: 0 }),
    );
    expect(findAction(actions, 'setStatus')).toBeUndefined();
  });

  test('last_attempt of null is treated as no previous attempt', () => {
    const actions = deriveOrderStatusActions(
      baseCtx({ state: 'UNPAID', last_attempt: null }),
    );
    expect(findAction(actions, 'setStatus')).toBeUndefined();
  });
});

// ----------------------------------------------------------------------------
// UNPAID (no last_attempt) — the expiry countdown branch
// ----------------------------------------------------------------------------

describe('deriveOrderStatusActions — UNPAID expiry countdown', () => {
  test('expiry > 5min: no setStatus', () => {
    // 400 seconds in the future = above the 300s threshold
    const actions = deriveOrderStatusActions(
      baseCtx({ state: 'UNPAID', expiry: 1_000_400 }),
    );
    expect(findAction(actions, 'setStatus')).toBeUndefined();
  });

  test('expiry < 5min and > 1min: countdown without error color', () => {
    // 100 seconds in the future = under 300s, above 60s
    const actions = deriveOrderStatusActions(
      baseCtx({ state: 'UNPAID', expiry: 1_000_100 }),
    );
    const status = findAction(actions, 'setStatus');
    expect(status?.key).toBe('invoice_expires_in');
    expect(status?.isError).toBe(false);
    expect(status?.args?.[0]).toBe('01:40');
  });

  test('expiry < 1min: countdown with error color', () => {
    // 30 seconds in the future
    const actions = deriveOrderStatusActions(
      baseCtx({ state: 'UNPAID', expiry: 1_000_030 }),
    );
    const status = findAction(actions, 'setStatus');
    expect(status?.key).toBe('invoice_expires_in');
    expect(status?.isError).toBe(true);
    expect(status?.args?.[0]).toBe('00:30');
  });

  test('expiry exactly at 5min boundary: no countdown (< not <=)', () => {
    // 300 seconds in the future = boundary, the predicate is strict <
    const actions = deriveOrderStatusActions(
      baseCtx({ state: 'UNPAID', expiry: 1_000_300 }),
    );
    expect(findAction(actions, 'setStatus')).toBeUndefined();
  });
});

// ----------------------------------------------------------------------------
// Unknown states
// ----------------------------------------------------------------------------

describe('deriveOrderStatusActions — unknown / missing state', () => {
  test('missing state with no expiry yields no setStatus', () => {
    const actions = deriveOrderStatusActions(baseCtx({}));
    expect(findAction(actions, 'setStatus')).toBeUndefined();
  });

  test('unrecognised state acts as UNPAID-without-last_attempt', () => {
    const actions = deriveOrderStatusActions(baseCtx({ state: 'WAITING' }));
    expect(findAction(actions, 'setStatus')).toBeUndefined();
  });
});
