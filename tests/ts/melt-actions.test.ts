import { describe, expect, test } from 'vitest';
import { actionsForMeltOutcome } from '../../src/ts/helpers';

// ----------------------------------------------------------------------------
// melt_succeeded — happy path (most-common outcome)
// ----------------------------------------------------------------------------

describe('actionsForMeltOutcome — melt_succeeded', () => {
  test('clear stranded, save response change, confirming status, claim with preimage', () => {
    const proofs = [{ id: '00', amount: 21, secret: 's', C: 'c' }] as never;
    expect(
      actionsForMeltOutcome({
        kind: 'melt_succeeded',
        preimage: 'pre123',
        changeProofs: proofs,
        mintQuoteId: 'mq-1',
      }),
    ).toEqual([
      { type: 'clearStranded', mintQuoteId: 'mq-1' },
      { type: 'saveResponseChange', proofs },
      { type: 'setStatus', key: 'confirming_payment', isError: false },
      { type: 'claim', preimage: 'pre123' },
    ]);
  });

  test('empty change array preserved on the saveResponseChange action', () => {
    const actions = actionsForMeltOutcome({
      kind: 'melt_succeeded',
      preimage: 'pre',
      changeProofs: [],
      mintQuoteId: 'mq',
    });
    expect(actions[1]).toEqual({ type: 'saveResponseChange', proofs: [] });
  });
});

// ----------------------------------------------------------------------------
// entry_check_threw — initial quote check rejected
// ----------------------------------------------------------------------------

describe('actionsForMeltOutcome — entry_check_threw', () => {
  test('showRecovery with the input token + payment_failed error status', () => {
    expect(
      actionsForMeltOutcome({
        kind: 'entry_check_threw',
        encodedToken: 'cashuAaaa...',
      }),
    ).toEqual([
      { type: 'showRecovery', token: 'cashuAaaa...' },
      { type: 'setStatus', key: 'payment_failed', isError: true },
    ]);
  });
});

// ----------------------------------------------------------------------------
// entry_paid_stale_snapshot — quote already PAID at entry
// ----------------------------------------------------------------------------

describe('actionsForMeltOutcome — entry_paid_stale_snapshot', () => {
  test('clear stranded + restore change + confirming status + claim with preimage', () => {
    expect(
      actionsForMeltOutcome({
        kind: 'entry_paid_stale_snapshot',
        preimage: 'pre-from-quote',
        mintQuoteId: 'mq-1',
      }),
    ).toEqual([
      { type: 'clearStranded', mintQuoteId: 'mq-1' },
      { type: 'restoreAndSaveChange' },
      { type: 'setStatus', key: 'confirming_payment', isError: false },
      { type: 'claim', preimage: 'pre-from-quote' },
    ]);
  });

  test('restoreAndSaveChange precedes setStatus precedes claim', () => {
    const actions = actionsForMeltOutcome({
      kind: 'entry_paid_stale_snapshot',
      preimage: 'pre',
      mintQuoteId: 'mq',
    });
    const types = actions.map((a) => a.type);
    expect(types.indexOf('restoreAndSaveChange')).toBeLessThan(
      types.indexOf('setStatus'),
    );
    expect(types.indexOf('setStatus')).toBeLessThan(types.indexOf('claim'));
  });
});

// ----------------------------------------------------------------------------
// melt_threw_inputs_spent — inputs spent at the mint
// ----------------------------------------------------------------------------

describe('actionsForMeltOutcome — melt_threw_inputs_spent', () => {
  test('clear stranded + restore change + confirming status + claim with empty preimage', () => {
    expect(
      actionsForMeltOutcome({
        kind: 'melt_threw_inputs_spent',
        mintQuoteId: 'mq-99',
      }),
    ).toEqual([
      { type: 'clearStranded', mintQuoteId: 'mq-99' },
      { type: 'restoreAndSaveChange' },
      { type: 'setStatus', key: 'confirming_payment', isError: false },
      { type: 'claim', preimage: '' },
    ]);
  });

  test('preimage is the empty string, not undefined or null', () => {
    const actions = actionsForMeltOutcome({
      kind: 'melt_threw_inputs_spent',
      mintQuoteId: 'mq',
    });
    const claim = actions.find((a) => a.type === 'claim');
    expect(claim).toBeDefined();
    expect(claim && claim.type === 'claim' ? claim.preimage : null).toBe('');
  });
});

// ----------------------------------------------------------------------------
// melt_threw_inputs_safe — inputs never spent
// ----------------------------------------------------------------------------

describe('actionsForMeltOutcome — melt_threw_inputs_safe', () => {
  test('showRecovery with the input token + payment_failed error status', () => {
    expect(
      actionsForMeltOutcome({
        kind: 'melt_threw_inputs_safe',
        encodedToken: 'cashuBbbb...',
      }),
    ).toEqual([
      { type: 'showRecovery', token: 'cashuBbbb...' },
      { type: 'setStatus', key: 'payment_failed', isError: true },
    ]);
  });

  test('does NOT issue a clearStranded — input snapshot still valid', () => {
    const actions = actionsForMeltOutcome({
      kind: 'melt_threw_inputs_safe',
      encodedToken: 'cashuBbbb...',
    });
    expect(actions.find((a) => a.type === 'clearStranded')).toBeUndefined();
  });

  test('does NOT issue a claim — inputs were never spent so server has nothing to verify', () => {
    const actions = actionsForMeltOutcome({
      kind: 'melt_threw_inputs_safe',
      encodedToken: 'cashuBbbb...',
    });
    expect(actions.find((a) => a.type === 'claim')).toBeUndefined();
  });
});

// ----------------------------------------------------------------------------
// melt_threw_state_unknown — let server probe
// ----------------------------------------------------------------------------

describe('actionsForMeltOutcome — melt_threw_state_unknown', () => {
  test('reconciling status + claim with empty preimage, NO recovery shown', () => {
    expect(actionsForMeltOutcome({ kind: 'melt_threw_state_unknown' })).toEqual([
      { type: 'setStatus', key: 'reconciling_with_mint', isError: true },
      { type: 'claim', preimage: '' },
    ]);
  });

  test('does NOT show recovery — proofs may be spent so the token could be worthless', () => {
    const actions = actionsForMeltOutcome({ kind: 'melt_threw_state_unknown' });
    expect(actions.find((a) => a.type === 'showRecovery')).toBeUndefined();
  });

  test('does NOT clear stranded — fate of the inputs is unknown', () => {
    const actions = actionsForMeltOutcome({ kind: 'melt_threw_state_unknown' });
    expect(actions.find((a) => a.type === 'clearStranded')).toBeUndefined();
  });
});
