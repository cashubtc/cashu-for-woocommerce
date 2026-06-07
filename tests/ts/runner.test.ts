import { describe, expect, test, vi } from 'vitest';
import { createSerialRunner } from '../../src/ts/helpers';

describe('createSerialRunner', () => {
  test('serialises concurrent run() calls — second does not start until first resolves', async () => {
    const setStatus = vi.fn();
    const run = createSerialRunner(setStatus);
    const events: string[] = [];

    const first = run(async () => {
      events.push('first:start');
      await Promise.resolve();
      await Promise.resolve();
      events.push('first:end');
      return 1;
    });
    const second = run(async () => {
      events.push('second:start');
      events.push('second:end');
      return 2;
    });

    await Promise.all([first, second]);
    expect(events).toEqual(['first:start', 'first:end', 'second:start', 'second:end']);
  });

  test('returns the fn result on success', async () => {
    const run = createSerialRunner(vi.fn());
    const result = await run(async () => 42);
    expect(result).toBe(42);
  });

  test('catches thrown errors and routes to setStatus(message, true)', async () => {
    const setStatus = vi.fn();
    const run = createSerialRunner(setStatus);
    await run(async () => {
      throw new Error('boom');
    });
    expect(setStatus).toHaveBeenCalledTimes(1);
    expect(setStatus.mock.calls[0]).toEqual(['boom', true]);
  });

  test('returns undefined when fn throws', async () => {
    const run = createSerialRunner(vi.fn());
    const result = await run(async () => {
      throw new Error('boom');
    });
    expect(result).toBeUndefined();
  });

  test('chain stays alive after an error — subsequent run() still executes', async () => {
    const setStatus = vi.fn();
    const run = createSerialRunner(setStatus);
    await run(async () => {
      throw new Error('first fails');
    });
    const result = await run(async () => 99);
    expect(result).toBe(99);
    expect(setStatus).toHaveBeenCalledTimes(1); // only the first error surfaced
  });

  test('each runner instance has its own chain (test isolation)', async () => {
    const events: string[] = [];
    const setStatus = vi.fn();
    const runA = createSerialRunner(setStatus);
    const runB = createSerialRunner(setStatus);

    // If they shared a chain, runA's first fn would block runB. With
    // separate chains, runB starts immediately.
    const aFirst = runA(async () => {
      events.push('A:start');
      await new Promise((r) => setTimeout(r, 10));
      events.push('A:end');
      return 'A';
    });
    const bFirst = runB(async () => {
      events.push('B:start');
      events.push('B:end');
      return 'B';
    });

    await Promise.all([aFirst, bFirst]);
    // B should have completed before A's timeout finished.
    expect(events.indexOf('B:end')).toBeLessThan(events.indexOf('A:end'));
  });
});
