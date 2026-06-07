import { describe, expect, test } from 'vitest';
import { readRootData, type RootDataReader } from '../../src/ts/helpers';

// ----------------------------------------------------------------------------
// Test harness — readRootData takes a `{ data: (key: string) => unknown }`
// adapter so it can be unit-tested without jsdom + jQuery. In production
// checkout.ts wires it to `(k) => $root.data(k)`.
// ----------------------------------------------------------------------------

const validRecord = (): Record<string, unknown> => ({
  'order-id': 42,
  'order-key': 'wc_order_abc',
  'return-url': 'https://shop/order-received/42',
  'expected-amount': 1500,
  'melt-quote-id': 'merchant-quote',
  'spot-quote-expiry': 1_700_000_300,
  'trusted-mint': 'https://mint.example/',
  'mint-quote-id': 'mint-quote-id',
  'mint-quote-request': 'lnbc1500...',
  'mint-quote-amount': 1450,
  'mint-quote-expiry': 1_700_000_300,
  'pay-callback': 'https://shop/wp-json/cashu-wc/v1/pay/42',
  'payment-id': 'pid-deadbeef',
  description: 'Order #42',
  'default-tab': 'cashu',
});

const reader = (overrides: Record<string, unknown> = {}): RootDataReader => {
  const record = { ...validRecord(), ...overrides };
  return { data: (key: string) => record[key] };
};

// ----------------------------------------------------------------------------
// Happy path
// ----------------------------------------------------------------------------

describe('readRootData — happy path', () => {
  test('maps every valid field to its RootData slot', () => {
    expect(readRootData(reader())).toEqual({
      orderId: 42,
      orderKey: 'wc_order_abc',
      returnUrl: 'https://shop/order-received/42',
      expectedAmount: 1500,
      quoteId: 'merchant-quote',
      quoteExpiryMs: 1_700_000_300_000,
      trustedMint: 'https://mint.example/',
      mintQuote: {
        id: 'mint-quote-id',
        request: 'lnbc1500...',
        amount: 1450,
        expiry: 1_700_000_300,
      },
      payCallback: 'https://shop/wp-json/cashu-wc/v1/pay/42',
      paymentId: 'pid-deadbeef',
      description: 'Order #42',
      defaultTab: 'cashu',
    });
  });

  test('converts spot-quote-expiry from unix seconds to milliseconds', () => {
    const result = readRootData(reader({ 'spot-quote-expiry': 1_700_000_005 }));
    expect(result.quoteExpiryMs).toBe(1_700_000_005_000);
  });
});

// ----------------------------------------------------------------------------
// Required-field validation — all of these throw `Bad order data`
// ----------------------------------------------------------------------------

describe.each([
  ['order-id missing', { 'order-id': undefined }],
  ['order-id NaN', { 'order-id': 'not-a-number' }],
  ['order-id zero', { 'order-id': 0 }],
  ['order-id negative', { 'order-id': -1 }],
  ['order-key empty', { 'order-key': '' }],
  ['return-url empty', { 'return-url': '' }],
  ['expected-amount zero', { 'expected-amount': 0 }],
  ['expected-amount negative', { 'expected-amount': -5 }],
  ['expected-amount NaN', { 'expected-amount': 'abc' }],
  ['melt-quote-id empty', { 'melt-quote-id': '' }],
  ['trusted-mint empty', { 'trusted-mint': '' }],
  ['mint-quote-id empty', { 'mint-quote-id': '' }],
  ['mint-quote-request empty', { 'mint-quote-request': '' }],
  ['mint-quote-amount zero', { 'mint-quote-amount': 0 }],
  ['mint-quote-amount NaN', { 'mint-quote-amount': 'abc' }],
  ['pay-callback empty', { 'pay-callback': '' }],
  ['payment-id empty', { 'payment-id': '' }],
])('readRootData — %s throws', (_label, overrides) => {
  test('throws Bad order data', () => {
    expect(() => readRootData(reader(overrides))).toThrow('Bad order data');
  });
});

// ----------------------------------------------------------------------------
// mintQuote.expiry transform — 0/missing → null, >0 → preserved
// ----------------------------------------------------------------------------

describe('readRootData — mintQuote.expiry transform', () => {
  test('positive integer is preserved as-is', () => {
    expect(readRootData(reader({ 'mint-quote-expiry': 1_700_000_999 })).mintQuote.expiry).toBe(
      1_700_000_999,
    );
  });

  test('zero collapses to null', () => {
    expect(readRootData(reader({ 'mint-quote-expiry': 0 })).mintQuote.expiry).toBeNull();
  });

  test('missing collapses to null', () => {
    expect(
      readRootData(reader({ 'mint-quote-expiry': undefined })).mintQuote.expiry,
    ).toBeNull();
  });

  test('negative collapses to null', () => {
    expect(readRootData(reader({ 'mint-quote-expiry': -1 })).mintQuote.expiry).toBeNull();
  });
});

// ----------------------------------------------------------------------------
// defaultTab cascade — only the three valid values pass through
// ----------------------------------------------------------------------------

describe('readRootData — defaultTab', () => {
  test.each([
    ['unified', 'unified'],
    ['cashu', 'cashu'],
    ['lightning', 'lightning'],
  ])('valid value %s passes through', (input, expected) => {
    expect(readRootData(reader({ 'default-tab': input })).defaultTab).toBe(expected);
  });

  test('unknown value falls back to unified', () => {
    expect(
      readRootData(reader({ 'default-tab': 'something-weird' })).defaultTab,
    ).toBe('unified');
  });

  test('missing value falls back to unified', () => {
    expect(readRootData(reader({ 'default-tab': undefined })).defaultTab).toBe(
      'unified',
    );
  });
});
