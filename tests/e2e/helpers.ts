import { Page } from '@playwright/test';

// Shared store-driving helpers for the live e2e specs. Kept theme-agnostic
// (classic + blocks checkout) since wp-env can run either.

export async function addBeanieToCart(page: Page): Promise<void> {
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

export async function fillCheckout(page: Page): Promise<void> {
  console.log('[step] checkout page');
  await page.goto('/checkout/', { waitUntil: 'domcontentloaded' });
  // Blocks checkout hydrates client-side; give React a beat before probing.
  await page.waitForTimeout(2_000);

  const isBlocks = (await page.locator('.wp-block-woocommerce-checkout').count()) > 0;
  console.log(`[step] checkout type: ${isBlocks ? 'blocks' : 'classic'} — ${page.url()}`);

  const field = async (sel: string, val: string) => {
    const el = page.locator(sel);
    if ((await el.count()) > 0 && (await el.first().isVisible())) {
      await el.first().fill(val);
    }
  };

  if (isBlocks) {
    await field('#email', 'e2e@example.com');
    for (const prefix of ['shipping', 'billing']) {
      await field(`#${prefix}-first_name`, 'E2E');
      await field(`#${prefix}-last_name`, 'Tester');
      await field(`#${prefix}-address_1`, '1 Demo Street');
      await field(`#${prefix}-city`, 'London');
      await field(`#${prefix}-postcode`, 'SW1A 1AA');
    }
    const radio = page.locator(
      'input[name="radio-control-wc-payment-method-options"][value="cashu_default"]',
    );
    if ((await radio.count()) > 0) {
      await radio.check();
    }
    await page.locator('.wc-block-components-checkout-place-order-button').click();
  } else {
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

export type ReceiptData = Record<string, string | undefined>;

/** Wait for the order-pay receipt page and return its #cashu-pay-root data-attrs. */
export async function readReceiptData(page: Page): Promise<ReceiptData> {
  await page.waitForURL(/order-pay/, { timeout: 60_000 });
  const root = page.locator('#cashu-pay-root');
  await root.first().waitFor({ state: 'attached', timeout: 30_000 });
  return root.first().evaluate((el) => ({ ...(el as HTMLElement).dataset }));
}
