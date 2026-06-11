import confetti from 'canvas-confetti';

/**
 * Copies text to clipboard, with fallback for localhost operation
 * @param text Text to copy
 */
export async function copyTextToClipboard(text: string) {
  if (!text) return Promise.resolve(false);
  if (navigator.clipboard && navigator.clipboard.writeText) {
    return navigator.clipboard
      .writeText(text)
      .then(() => true)
      .catch(() => false);
  }
  try {
    const ta = document.createElement('textarea');
    ta.value = text;
    // Avoid scrolling to bottom
    ta.style.top = '0';
    ta.style.left = '0';
    ta.style.position = 'fixed';
    document.body.appendChild(ta);
    ta.focus();
    ta.select();
    const ok = document.execCommand('copy');
    document.body.removeChild(ta);
    return Promise.resolve(!!ok);
  } catch {
    return Promise.resolve(false);
  }
}

/**
 * Activates the confetti bomb effect
 */
export function doConfettiBomb() {
  const duration = 0.25 * 1000;
  const end = Date.now() + duration;

  (function frame() {
    // launch a few confetti from the left edge
    confetti({
      particleCount: 7,
      angle: 60,
      spread: 55,
      origin: {
        x: 0,
      },
    });
    // and launch a few from the right edge
    confetti({
      particleCount: 7,
      angle: 120,
      spread: 55,
      origin: {
        x: 1,
      },
    });

    // keep going until we are out of time
    if (Date.now() < end) {
      requestAnimationFrame(frame);
    }
  })();
  confetti.reset();
}

/**
 * Returns a promise that resolves after `ms` milliseconds.
 * @example await delay(1000); // waits 1 second
 */
export const delay = (ms: number): Promise<void> =>
  new Promise<void>((res) => setTimeout(res, ms));

export function getErrorMessage(
  error: unknown,
  defaultMsg: string = 'Unknown error',
): string {
  if (error instanceof Error) {
    return error.message;
  }
  // Handle non-Error throws gracefully
  if (typeof error === 'string') {
    return error;
  }
  if (error && typeof error === 'object' && 'message' in error) {
    return String((error as { message: unknown }).message);
  }
  return defaultMsg;
}
