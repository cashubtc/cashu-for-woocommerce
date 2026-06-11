import { test, expect } from '@playwright/test';
import * as fs from 'fs';
import * as path from 'path';
import { addBeanieToCart, fillCheckout, readReceiptData } from './helpers';

// Live RECOVERY smoke. Simulates the worst customer-funds-at-risk case on
// the Lightning leg and proves NUT-09 recovery rescues it:
//
//   1. Customer pays the mint-quote BOLT11. The browser detects payment and
//      mints proofs — the mint quote is now ISSUED, proofs exist only in the
//      tab, the merchant has NOT been paid.
//   2. We BLOCK the melt POST (route abort) — standing in for "tab died /
//      network cut between mint and melt". The order is left unsettled with
//      proofs stranded at the mint.
//   3. We CLEAR localStorage and reload. This removes the fast-path stranded-
//      proof snapshot, forcing the slow path: NUT-09 restore via the
//      deterministic per-order seed (order_key + mint_quote_id), which is the
//      cross-device recovery the whole design hinges on.
//   4. The reloaded page must restore the proofs, re-melt to the merchant,
//      claim, and settle the order — with no second LN payment from the
//      customer.
//
// Run headed so a human can pay:
//   CASHU_E2E_BASE_URL=<tunnel> npx playwright test live-recovery --headed

const ARTIFACTS = path.resolve(__dirname, 'artifacts');
const PAY_WAIT_MS = Number(process.env.CASHU_E2E_PAY_WAIT_MS ?? 14 * 60_000);

// Only the melt POST (/v1/melt/bolt11). NOT the quote-state probe
// (/v1/melt/quote/bolt11/{id}) — that must stay live so the post-throw
// classifier can see the proofs are still safe.
function isMeltPost(url: string, method: string): boolean {
  return method === 'POST' && /\/v1\/melt\/bolt11$/.test(new URL(url).pathname);
}

test('stranded proofs recover via NUT-09 after a mid-melt failure', async ({ page }) => {
  test.setTimeout(PAY_WAIT_MS + 10 * 60_000);

  const log = (m: string) => console.log(m);
  page.on('console', (msg) => {
    const text = msg.text();
    if (/cashu|mint|melt|quote|restore|issued|recover|ws/i.test(text)) {
      log(`[browser:${msg.type()}] ${text}`);
    }
  });

  // ---- Stage 1: order, block the melt, wait for payment + strand ----
  let meltBlocked = true;
  let meltAttempted = false;
  await page.route('**/v1/melt/bolt11', async (route) => {
    const req = route.request();
    if (meltBlocked && isMeltPost(req.url(), req.method())) {
      meltAttempted = true;
      log('[inject] aborting melt POST (simulating mid-flight death)');
      await route.abort('connectionreset');
      return;
    }
    await route.continue();
  });

  // Observe NUT-09 restore calls. A POST to {mint}/v1/restore in stage 2 is
  // the hard signal that recovery went through the deterministic-seed path
  // (not a leftover localStorage snapshot — we clear that), so we can assert
  // on it instead of relying on console-status timing.
  let restoreCalled = false;
  page.on('request', (req) => {
    if (req.method() === 'POST' && /\/v1\/restore$/.test(new URL(req.url()).pathname)) {
      restoreCalled = true;
      log('[observe] NUT-09 /v1/restore POST');
    }
  });

  await addBeanieToCart(page);
  await fillCheckout(page);

  const data = await readReceiptData(page);
  const orderId = String(data.orderId ?? '');
  const orderKey = String(data.orderKey ?? '');
  const bolt11 = String(data.mintQuoteRequest ?? '');
  const mintQuoteAmount = Number(data.mintQuoteAmount ?? 0);
  expect(orderId).not.toBe('');
  expect(bolt11).toMatch(/^lnbc/i);

  const payUrl = page.url();
  fs.mkdirSync(ARTIFACTS, { recursive: true });
  fs.writeFileSync(path.join(ARTIFACTS, 'recovery-invoice.txt'), bolt11);

  log('');
  log('============== PAY NOW (recovery test) ==============');
  log(`Order #${orderId} — ${mintQuoteAmount} sat`);
  log(`BOLT11: ${bolt11}`);
  log('After payment the melt is deliberately blocked; the page will then');
  log('reload with cleared storage to force NUT-09 recovery.');
  log('=====================================================');
  log('');

  // Wait until the melt was attempted (and aborted): that means the browser
  // detected payment, minted proofs, and tried to melt. Proofs are now
  // stranded at the mint.
  await expect
    .poll(() => meltAttempted, {
      timeout: PAY_WAIT_MS,
      message: 'waiting for payment + mint + (blocked) melt attempt',
    })
    .toBe(true);
  log('[step] melt attempt blocked — proofs stranded, order still unpaid');

  // Give the client a moment to finish its failure handling / status write.
  await page.waitForTimeout(3_000);

  // The order must NOT be paid yet — recovery must be what settles it.
  const statusAfterStrand = await page.locator('#cashu-status').textContent();
  log(`[step] status after strand: ${(statusAfterStrand ?? '').trim()}`);
  expect(page.url()).not.toMatch(/order-received/);

  // ---- Stage 2: force NUT-09 recovery ----
  // Unblock the melt and wipe localStorage so the reload can't use the
  // fast-path stranded-proof snapshot — only the deterministic-seed
  // NUT-09 restore can rescue the order now.
  restoreCalled = false; // ignore any stage-1 restore noise
  meltBlocked = false;
  await page.evaluate(() => {
    try {
      localStorage.clear();
    } catch {
      /* ignore restricted storage */
    }
  });
  log('[step] localStorage cleared, melt unblocked — reloading for NUT-09 recovery');

  let lastStatus = '';
  const statusPoll = setInterval(() => {
    void page
      .locator('#cashu-status')
      .textContent()
      .then((s) => {
        const cur = (s ?? '').trim();
        if (cur && cur !== lastStatus) {
          lastStatus = cur;
          log(`[status] ${cur}`);
        }
      })
      .catch(() => undefined);
  }, 2_000);

  try {
    await page.goto(payUrl, { waitUntil: 'domcontentloaded' });
    // The ISSUED branch of startMintQuoteWatcher should fire: restore proofs
    // via NUT-09, re-melt, claim, and redirect to the thank-you page.
    await page.waitForURL(/order-received/, { timeout: 5 * 60_000 });
  } finally {
    clearInterval(statusPoll);
  }

  await page.screenshot({
    path: path.join(ARTIFACTS, 'recovery-settled.png'),
    fullPage: true,
  });
  log(`[step] recovered + settled: ${page.url()}`);
  // Self-certify that recovery used the deterministic-seed restore, not a
  // fast-path snapshot (which we deleted) — this is the cross-device claim.
  expect(
    restoreCalled,
    'expected a NUT-09 /v1/restore call during recovery (cross-device path)',
  ).toBe(true);
  // Surface the order id/key so the runner can verify server state.
  console.log(`RECOVERY_ORDER ${orderId} ${orderKey}`);
});
