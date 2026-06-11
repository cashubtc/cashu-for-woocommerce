import { afterEach, describe, expect, test, vi } from 'vitest';

// canvas-confetti needs a real <canvas>; jsdom has none. Mock the module so
// doConfettiBomb can be asserted on call shape rather than pixels.
vi.mock('canvas-confetti', () => {
  const confetti = vi.fn() as ReturnType<typeof vi.fn> & {
    reset: ReturnType<typeof vi.fn>;
  };
  confetti.reset = vi.fn();
  return { default: confetti };
});

import confetti from 'canvas-confetti';
import {
  copyTextToClipboard,
  delay,
  doConfettiBomb,
  getErrorMessage,
} from '../../src/ts/utils';

afterEach(() => {
  vi.useRealTimers();
  vi.unstubAllGlobals();
});

describe('copyTextToClipboard', () => {
  test('returns false for empty text without touching the clipboard', async () => {
    const writeText = vi.fn();
    vi.stubGlobal('navigator', { clipboard: { writeText } });

    await expect(copyTextToClipboard('')).resolves.toBe(false);
    expect(writeText).not.toHaveBeenCalled();
  });

  test('uses the async clipboard API when available', async () => {
    const writeText = vi.fn().mockResolvedValue(undefined);
    vi.stubGlobal('navigator', { clipboard: { writeText } });

    await expect(copyTextToClipboard('lnbc1...')).resolves.toBe(true);
    expect(writeText).toHaveBeenCalledWith('lnbc1...');
  });

  test('returns false when the clipboard API rejects (permissions)', async () => {
    const writeText = vi.fn().mockRejectedValue(new Error('denied'));
    vi.stubGlobal('navigator', { clipboard: { writeText } });

    await expect(copyTextToClipboard('text')).resolves.toBe(false);
  });

  test('falls back to a temporary textarea + execCommand on http/localhost', async () => {
    // No clipboard API (the insecure-context case the fallback exists for).
    vi.stubGlobal('navigator', {});
    const execCommand = vi.fn().mockReturnValue(true);
    document.execCommand = execCommand as unknown as typeof document.execCommand;

    await expect(copyTextToClipboard('cashuB...')).resolves.toBe(true);
    expect(execCommand).toHaveBeenCalledWith('copy');
    // The scratch textarea must not leak into the page.
    expect(document.querySelector('textarea')).toBeNull();
  });

  test('returns false when execCommand copy is refused', async () => {
    vi.stubGlobal('navigator', {});
    document.execCommand = vi
      .fn()
      .mockReturnValue(false) as unknown as typeof document.execCommand;

    await expect(copyTextToClipboard('text')).resolves.toBe(false);
  });
});

describe('doConfettiBomb', () => {
  test('fires bursts from both edges and resets', () => {
    vi.useFakeTimers();
    doConfettiBomb();

    const calls = vi.mocked(confetti).mock.calls;
    expect(calls.some(([opts]) => opts?.origin?.x === 0)).toBe(true);
    expect(calls.some(([opts]) => opts?.origin?.x === 1)).toBe(true);
    expect(vi.mocked(confetti).reset).toHaveBeenCalled();
  });
});

describe('delay', () => {
  test('resolves after the given milliseconds', async () => {
    vi.useFakeTimers();
    const done = vi.fn();
    delay(500).then(done);

    await vi.advanceTimersByTimeAsync(499);
    expect(done).not.toHaveBeenCalled();
    await vi.advanceTimersByTimeAsync(1);
    expect(done).toHaveBeenCalled();
  });
});

describe('getErrorMessage', () => {
  test('unwraps Error instances', () => {
    expect(getErrorMessage(new Error('boom'))).toBe('boom');
  });

  test('passes through string throws', () => {
    expect(getErrorMessage('plain failure')).toBe('plain failure');
  });

  test('stringifies message-bearing objects', () => {
    expect(getErrorMessage({ message: 11006 })).toBe('11006');
  });

  test('falls back to the default for unknown shapes', () => {
    expect(getErrorMessage(null)).toBe('Unknown error');
    expect(getErrorMessage(undefined, 'custom default')).toBe('custom default');
    expect(getErrorMessage(42)).toBe('Unknown error');
  });
});
