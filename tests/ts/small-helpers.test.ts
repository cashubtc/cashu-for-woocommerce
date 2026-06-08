import { describe, expect, test } from 'vitest';
import {
  composeRestUrl,
  deriveMeltFailureBranch,
  extractPaymentPreimage,
  selectPollIntervalMs,
} from '../../src/ts/helpers';

// ----------------------------------------------------------------------------
// selectPollIntervalMs
// ----------------------------------------------------------------------------

describe('selectPollIntervalMs', () => {
  const intervals = [5_000, 15_000, 30_000];

  test('streak 0 picks the first interval', () => {
    expect(selectPollIntervalMs(0, intervals)).toBe(5_000);
  });

  test('streak in-range picks the matching interval', () => {
    expect(selectPollIntervalMs(1, intervals)).toBe(15_000);
    expect(selectPollIntervalMs(2, intervals)).toBe(30_000);
  });

  test('streak past last index clamps to last interval', () => {
    expect(selectPollIntervalMs(3, intervals)).toBe(30_000);
    expect(selectPollIntervalMs(100, intervals)).toBe(30_000);
  });

  test('negative streak clamps to first interval', () => {
    expect(selectPollIntervalMs(-1, intervals)).toBe(5_000);
    expect(selectPollIntervalMs(-100, intervals)).toBe(5_000);
  });

  test('empty intervals array returns 0', () => {
    expect(selectPollIntervalMs(0, [])).toBe(0);
  });

  test('single-element intervals array returns that one for any streak', () => {
    expect(selectPollIntervalMs(0, [42])).toBe(42);
    expect(selectPollIntervalMs(5, [42])).toBe(42);
  });
});

// ----------------------------------------------------------------------------
// deriveMeltFailureBranch
// ----------------------------------------------------------------------------

describe('deriveMeltFailureBranch', () => {
  test('PAID → paid_inputs_spent', () => {
    expect(deriveMeltFailureBranch('PAID')).toBe('paid_inputs_spent');
  });

  test('UNPAID → unpaid_inputs_safe', () => {
    expect(deriveMeltFailureBranch('UNPAID')).toBe('unpaid_inputs_safe');
  });

  test('PENDING → unknown_let_server_probe', () => {
    expect(deriveMeltFailureBranch('PENDING')).toBe('unknown_let_server_probe');
  });

  test('empty string → unknown_let_server_probe', () => {
    expect(deriveMeltFailureBranch('')).toBe('unknown_let_server_probe');
  });

  test('null → unknown_let_server_probe', () => {
    expect(deriveMeltFailureBranch(null)).toBe('unknown_let_server_probe');
  });

  test('undefined → unknown_let_server_probe', () => {
    expect(deriveMeltFailureBranch(undefined)).toBe('unknown_let_server_probe');
  });

  test('unrecognised state → unknown_let_server_probe (fail-safe)', () => {
    expect(deriveMeltFailureBranch('OOPS')).toBe('unknown_let_server_probe');
  });

  test('case-sensitive: lowercase paid does NOT match', () => {
    // Mint protocol uses uppercase; lowercase would be a malformed response,
    // safest to treat as unknown.
    expect(deriveMeltFailureBranch('paid')).toBe('unknown_let_server_probe');
  });
});

// ----------------------------------------------------------------------------
// extractPaymentPreimage
// ----------------------------------------------------------------------------

describe('extractPaymentPreimage', () => {
  test('direct field on the source object', () => {
    expect(extractPaymentPreimage({ payment_preimage: 'abc123' })).toBe('abc123');
  });

  test('nested under quote.payment_preimage', () => {
    expect(extractPaymentPreimage({ quote: { payment_preimage: 'def456' } })).toBe(
      'def456',
    );
  });

  test('direct field takes precedence over nested', () => {
    expect(
      extractPaymentPreimage({
        payment_preimage: 'top',
        quote: { payment_preimage: 'nested' },
      }),
    ).toBe('top');
  });

  test('returns empty string when source is null', () => {
    expect(extractPaymentPreimage(null)).toBe('');
  });

  test('returns empty string when source is undefined', () => {
    expect(extractPaymentPreimage(undefined)).toBe('');
  });

  test('returns empty string when source is primitive', () => {
    expect(extractPaymentPreimage('hex')).toBe('');
    expect(extractPaymentPreimage(123)).toBe('');
  });

  test('returns empty string when payment_preimage is non-string', () => {
    expect(extractPaymentPreimage({ payment_preimage: 42 })).toBe('');
    expect(extractPaymentPreimage({ payment_preimage: null })).toBe('');
    expect(extractPaymentPreimage({ payment_preimage: undefined })).toBe('');
  });

  test('returns empty string when neither direct nor nested exists', () => {
    expect(extractPaymentPreimage({})).toBe('');
    expect(extractPaymentPreimage({ quote: {} })).toBe('');
    expect(extractPaymentPreimage({ quote: 'not-an-object' })).toBe('');
  });

  test('handles undefined nested quote without throwing', () => {
    expect(extractPaymentPreimage({ quote: undefined })).toBe('');
  });
});

// ----------------------------------------------------------------------------
// composeRestUrl
// ----------------------------------------------------------------------------

describe('composeRestUrl', () => {
  // All four slash combinations must collapse to exactly one '/' between
  // base + route. Naive concatenation produces '//foo' or 'rootfoo'
  // depending on inputs; the helper guarantees neither.
  test('base trailing slash + route leading slash', () => {
    expect(
      composeRestUrl('https://x.test/wp-json/cashu-wc/v1/', '/claim-melt-quote'),
    ).toBe('https://x.test/wp-json/cashu-wc/v1/claim-melt-quote');
  });

  test('base trailing slash + route bare', () => {
    expect(
      composeRestUrl('https://x.test/wp-json/cashu-wc/v1/', 'claim-melt-quote'),
    ).toBe('https://x.test/wp-json/cashu-wc/v1/claim-melt-quote');
  });

  test('base bare + route leading slash', () => {
    expect(
      composeRestUrl('https://x.test/wp-json/cashu-wc/v1', '/claim-melt-quote'),
    ).toBe('https://x.test/wp-json/cashu-wc/v1/claim-melt-quote');
  });

  test('base bare + route bare', () => {
    expect(composeRestUrl('https://x.test/wp-json/cashu-wc/v1', 'claim-melt-quote')).toBe(
      'https://x.test/wp-json/cashu-wc/v1/claim-melt-quote',
    );
  });

  // Missing config must return '' so the caller short-circuits rather than
  // POSTing to a malformed URL.
  test('empty restRoot returns empty string', () => {
    expect(composeRestUrl('', 'claim-melt-quote')).toBe('');
  });

  test('empty route returns empty string', () => {
    expect(composeRestUrl('https://x.test/wp-json/cashu-wc/v1/', '')).toBe('');
  });

  test('both empty returns empty string', () => {
    expect(composeRestUrl('', '')).toBe('');
  });

  // The leading-slash strip only fires once — `//foo` collapses to `/foo`,
  // not `foo`. This matters because some WP setups namespace REST under a
  // subdir and a double-slashed route shouldn't break the join.
  test('route with multiple leading slashes preserves all but the first', () => {
    expect(composeRestUrl('https://x.test/', '//foo')).toBe('https://x.test//foo');
  });
});
