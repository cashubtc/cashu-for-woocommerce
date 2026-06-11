import { test, expect, Page } from '@playwright/test';
import { PaymentRequest, PaymentRequestTransportType } from '@cashu/cashu-ts';
import * as fs from 'fs';
import * as path from 'path';

// Live checkout smoke: product → cart → checkout → Cashu receipt page.
//
// The spec extracts the payment payloads (BOLT11 invoice for the Lightning
// leg, NUT-18 creq for the Cashu leg) into tests/e2e/artifacts/ and then
// KEEPS THE PAGE OPEN waiting for a human to pay from a real wallet. The
// open page matters: on the Lightning leg the receipt page's JS is the
// settlement engine (it mints proofs from the paid quote and melts them to
// the merchant), so closing it would leave the order to the NUT-09
// recovery path instead of the happy path under test.
//
// Pass CASHU_E2E_PAY_WAIT_MS to control how long to wait for payment
// (default 14 min — just inside the 15-min spot quote window).

const ARTIFACTS = path.resolve(__dirname, 'artifacts');
const PAY_WAIT_MS = Number(process.env.CASHU_E2E_PAY_WAIT_MS ?? 14 * 60_000);

function save(name: string, contents: string): void {
  fs.mkdirSync(ARTIFACTS, { recursive: true });
  fs.writeFileSync(path.join(ARTIFACTS, name), contents);
}

async function addBeanieToCart(page: Page): Promise<void> {
  console.log('[step] product page');
  await page.goto('/product/beanie/', { waitUntil: 'domcontentloaded' });
  console.log('[step] add to cart');
  await page
    .locator('button[name="add-to-cart"], .single_add_to_cart_button')
    .first()
    .click();
  // Classic themes navigate / show a notice; blocks themes update the
  // mini-cart over fetch. Don't rely on networkidle (cart fragments keep
  // the wire busy) — just give the add-to-cart response a moment to land.
  await page
    .waitForResponse((r) => r.url().includes('add'), { timeout: 15_000 })
    .catch(() => undefined);
  await page.waitForTimeout(1_500);
  console.log('[step] added to cart');
}

async function fillCheckout(page: Page): Promise<void> {
  console.log('[step] checkout page');
  await page.goto('/checkout/', { waitUntil: 'domcontentloaded' });
  // Blocks checkout hydrates client-side; give React a beat before probing.
  await page.waitForTimeout(2_000);

  const isBlocks = (await page.locator('.wp-block-woocommerce-checkout').count()) > 0;
  console.log(`[step] checkout type: ${isBlocks ? 'blocks' : 'classic'} — ${page.url()}`);

  if (isBlocks) {
    await page.locator('#email').fill('e2e@example.com');
    const field = async (sel: string, val: string) => {
      const el = page.locator(sel);
      if ((await el.count()) > 0 && (await el.first().isVisible())) {
        await el.first().fill(val);
      }
    };
    for (const prefix of ['shipping', 'billing']) {
      await field(`#${prefix}-first_name`, 'E2E');
      await field(`#${prefix}-last_name`, 'Tester');
      await field(`#${prefix}-address_1`, '1 Demo Street');
      await field(`#${prefix}-city`, 'London');
      await field(`#${prefix}-postcode`, 'SW1A 1AA');
    }
    // Select the Cashu gateway (label says "Bitcoin").
    const radio = page.locator(
      'input[name="radio-control-wc-payment-method-options"][value="cashu_default"]',
    );
    if ((await radio.count()) > 0) {
      await radio.check();
    }
    await page.locator('.wc-block-components-checkout-place-order-button').click();
  } else {
    const field = async (sel: string, val: string) => {
      const el = page.locator(sel);
      if ((await el.count()) > 0 && (await el.first().isVisible())) {
        await el.first().fill(val);
      }
    };
    await field('#billing_first_name', 'E2E');
    await field('#billing_last_name', 'Tester');
    await field('#billing_address_1', '1 Demo Street');
    await field('#billing_city', 'London');
    await field('#billing_postcode', 'SW1A 1AA');
    await field('#billing_phone', '07700900000');
    await field('#billing_email', 'e2e@example.com');
    await page.locator('label[for="payment_method_cashu_default"]').click();
    await page.locator('#place_order').click();
  }
}

test('live checkout reaches receipt page and settles after payment', async ({
  page,
  baseURL,
}) => {
  test.setTimeout(PAY_WAIT_MS + 10 * 60_000);

  // Surface browser console + cashu status changes in the runner output so
  // the operator can follow the mint/melt progress live.
  page.on('console', (msg) => {
    const text = msg.text();
    if (/cashu|mint|melt|quote|restore|ws/i.test(text)) {
      console.log(`[browser:${msg.type()}] ${text}`);
    }
  });

  await addBeanieToCart(page);
  await fillCheckout(page);

  // process_payment redirects to the order-pay receipt page.
  await page.waitForURL(/order-pay/, { timeout: 60_000 });
  const root = page.locator('#cashu-pay-root');
  // The root is an empty data-carrier div (zero height) — Playwright's
  // default 'visible' state would never match; wait for DOM attachment.
  await root.first().waitFor({ state: 'attached', timeout: 30_000 });
  const rootCount = await root.count();
  // order_details is WC core's summary list — its count reveals how many
  // times the order-pay template itself rendered (our root is deduped by
  // the plugin guard, so it alone can't distinguish one render from two).
  const summaryCount = await page.locator('ul.order_details').count();
  console.log(
    `[step] receipt page (cashu-pay-root count=${rootCount}, template renders=${summaryCount})`,
  );
  expect(rootCount).toBe(1);

  const data = await root.first().evaluate((el) => ({ ...(el as HTMLElement).dataset }));
  const orderId = String(data.orderId ?? '');
  const orderKey = String(data.orderKey ?? '');
  const bolt11 = String(data.mintQuoteRequest ?? '');
  const expectedAmount = Number(data.expectedAmount ?? 0);
  const mintQuoteAmount = Number(data.mintQuoteAmount ?? 0);

  expect(orderId).not.toBe('');
  expect(bolt11).toMatch(/^lnbc/i);
  expect(mintQuoteAmount).toBeGreaterThan(0);

  // Rebuild the NUT-18 payment request exactly as checkout.ts does, so the
  // Cashu leg can be exercised by pasting the creq into a wallet.
  const creq = new PaymentRequest(
    [
      {
        type: PaymentRequestTransportType.POST,
        target: String(data.payCallback ?? ''),
      },
    ],
    String(data.paymentId ?? ''),
    expectedAmount,
    'sat',
    [String(data.trustedMint ?? '')],
    String(data.description ?? ''),
    true,
    undefined,
  ).toEncodedCreqB();

  save('invoice.txt', bolt11);
  save('creq.txt', creq);
  save(
    'order.json',
    JSON.stringify(
      {
        orderId,
        orderKey,
        baseURL,
        payUrl: page.url(),
        expectedAmount,
        mintQuoteAmount,
        trustedMint: data.trustedMint,
        meltQuoteId: data.meltQuoteId,
        mintQuoteId: data.mintQuoteId,
      },
      null,
      2,
    ),
  );

  // Wait for the QR to render, then snapshot it (scannable from the file).
  await page.locator('[data-cashu-qr] svg').first().waitFor({ timeout: 60_000 });
  await page.screenshot({
    path: path.join(ARTIFACTS, 'receipt-page.png'),
    fullPage: true,
  });

  console.log('');
  console.log('================= PAY NOW =================');
  console.log(
    `Order #${orderId} — ${mintQuoteAmount} sat (melt total ${expectedAmount})`,
  );
  console.log(`BOLT11 (Lightning leg): ${bolt11}`);
  console.log(`creq (Cashu leg):       ${creq}`);
  console.log(`Waiting up to ${Math.round(PAY_WAIT_MS / 60000)} min for settlement…`);
  console.log('===========================================');
  console.log('');

  // Track status box changes while we wait.
  let lastStatus = '';
  const statusPoll = setInterval(() => {
    void page
      .locator('#cashu-status')
      .textContent()
      .then((s) => {
        const cur = (s ?? '').trim();
        if (cur && cur !== lastStatus) {
          lastStatus = cur;
          console.log(`[status] ${cur}`);
        }
      })
      .catch(() => undefined);
  }, 2_000);

  try {
    await page.waitForURL(/order-received/, { timeout: PAY_WAIT_MS });
  } finally {
    clearInterval(statusPoll);
  }

  await page.waitForLoadState('domcontentloaded');
  await page.screenshot({
    path: path.join(ARTIFACTS, 'thankyou-page.png'),
    fullPage: true,
  });
  console.log(`Settled: ${page.url()}`);
});
