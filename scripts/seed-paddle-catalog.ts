/**
 * Paddle Sandbox — Seed Product Catalog
 *
 * Creates 3 products (Starter / Pro / Advanced) with monthly + yearly prices,
 * 7-day free trial on all plans, and country price overrides (GBP/EUR/AUD).
 *
 * Run: PADDLE_API_KEY=pdl_sdbx_... npx tsx scripts/seed-paddle-catalog.ts
 */
import { Environment, Paddle } from "@paddle/paddle-node-sdk";

const paddle = new Paddle(process.env.PADDLE_API_KEY!, {
  environment: Environment.sandbox,
});

async function seed() {
  console.log("Creating Paddle catalog in sandbox...\n");

  // ── Starter ($10/mo, $100/yr) ──
  const starter = await paddle.products.create({
    name: "Starter",
    taxCategory: "saas",
    description: "For solo sellers — 1 A-site + 2 B-sites, preset strategies.",
  });
  const starterMonthly = await paddle.prices.create({
    productId: starter.id,
    description: "Starter monthly USD",
    unitPrice: { amount: "1000", currencyCode: "USD" }, // $10.00
    billingCycle: { interval: "month", frequency: 1 },
    trialPeriod: { interval: "day", frequency: 7 },
    unitPriceOverrides: [
      { countryCodes: ["GB"], unitPrice: { amount: "900", currencyCode: "GBP" } },    // £9.00
      { countryCodes: ["DE","FR","IT","ES","NL","IE"], unitPrice: { amount: "1000", currencyCode: "EUR" } }, // €10.00
      { countryCodes: ["AU"], unitPrice: { amount: "1500", currencyCode: "AUD" } },   // A$15.00
    ],
  });
  const starterYearly = await paddle.prices.create({
    productId: starter.id,
    description: "Starter yearly USD",
    unitPrice: { amount: "10000", currencyCode: "USD" }, // $100.00
    billingCycle: { interval: "year", frequency: 1 },
    trialPeriod: { interval: "day", frequency: 7 },
    unitPriceOverrides: [
      { countryCodes: ["GB"], unitPrice: { amount: "9000", currencyCode: "GBP" } },   // £90.00
      { countryCodes: ["DE","FR","IT","ES","NL","IE"], unitPrice: { amount: "10000", currencyCode: "EUR" } }, // €100.00
      { countryCodes: ["AU"], unitPrice: { amount: "15000", currencyCode: "AUD" } },  // A$150.00
    ],
  });
  console.log(`✅ Starter:  ${starter.id}`);
  console.log(`   Monthly: ${starterMonthly.id}  ($10.00 USD/mo, 7-day trial)`);
  console.log(`   Yearly:  ${starterYearly.id}  ($100.00 USD/yr, 7-day trial)`);

  // ── Pro ($40/mo, $400/yr) ──
  const pro = await paddle.products.create({
    name: "Pro",
    taxCategory: "saas",
    description: "For growing sellers — 2 A-sites + 5 B-sites, custom strategies, source code.",
  });
  const proMonthly = await paddle.prices.create({
    productId: pro.id,
    description: "Pro monthly USD",
    unitPrice: { amount: "4000", currencyCode: "USD" }, // $40.00
    billingCycle: { interval: "month", frequency: 1 },
    trialPeriod: { interval: "day", frequency: 7 },
    unitPriceOverrides: [
      { countryCodes: ["GB"], unitPrice: { amount: "3500", currencyCode: "GBP" } },    // £35.00
      { countryCodes: ["DE","FR","IT","ES","NL","IE"], unitPrice: { amount: "4000", currencyCode: "EUR" } }, // €40.00
      { countryCodes: ["AU"], unitPrice: { amount: "6000", currencyCode: "AUD" } },    // A$60.00
    ],
  });
  const proYearly = await paddle.prices.create({
    productId: pro.id,
    description: "Pro yearly USD",
    unitPrice: { amount: "40000", currencyCode: "USD" }, // $400.00
    billingCycle: { interval: "year", frequency: 1 },
    trialPeriod: { interval: "day", frequency: 7 },
    unitPriceOverrides: [
      { countryCodes: ["GB"], unitPrice: { amount: "35000", currencyCode: "GBP" } },   // £350.00
      { countryCodes: ["DE","FR","IT","ES","NL","IE"], unitPrice: { amount: "40000", currencyCode: "EUR" } }, // €400.00
      { countryCodes: ["AU"], unitPrice: { amount: "60000", currencyCode: "AUD" } },   // A$600.00
    ],
  });
  console.log(`✅ Pro:      ${pro.id}`);
  console.log(`   Monthly: ${proMonthly.id}  ($40.00 USD/mo, 7-day trial)`);
  console.log(`   Yearly:  ${proYearly.id}  ($400.00 USD/yr, 7-day trial)`);

  // ── Advanced ($120/mo, $1200/yr) ──
  const advanced = await paddle.products.create({
    name: "Advanced",
    taxCategory: "saas",
    description: "For agencies/enterprise — unlimited A/B sites, DSL routing, OEM white-label, dedicated support.",
  });
  const advMonthly = await paddle.prices.create({
    productId: advanced.id,
    description: "Advanced monthly USD",
    unitPrice: { amount: "12000", currencyCode: "USD" }, // $120.00
    billingCycle: { interval: "month", frequency: 1 },
    trialPeriod: { interval: "day", frequency: 7 },
    unitPriceOverrides: [
      { countryCodes: ["GB"], unitPrice: { amount: "10500", currencyCode: "GBP" } },   // £105.00
      { countryCodes: ["DE","FR","IT","ES","NL","IE"], unitPrice: { amount: "12000", currencyCode: "EUR" } }, // €120.00
      { countryCodes: ["AU"], unitPrice: { amount: "18000", currencyCode: "AUD" } },   // A$180.00
    ],
  });
  const advYearly = await paddle.prices.create({
    productId: advanced.id,
    description: "Advanced yearly USD",
    unitPrice: { amount: "120000", currencyCode: "USD" }, // $1,200.00
    billingCycle: { interval: "year", frequency: 1 },
    trialPeriod: { interval: "day", frequency: 7 },
    unitPriceOverrides: [
      { countryCodes: ["GB"], unitPrice: { amount: "105000", currencyCode: "GBP" } },  // £1,050.00
      { countryCodes: ["DE","FR","IT","ES","NL","IE"], unitPrice: { amount: "120000", currencyCode: "EUR" } }, // €1,200.00
      { countryCodes: ["AU"], unitPrice: { amount: "180000", currencyCode: "AUD" } },  // A$1,800.00
    ],
  });
  console.log(`✅ Advanced: ${advanced.id}`);
  console.log(`   Monthly: ${advMonthly.id}  ($120.00 USD/mo, 7-day trial)`);
  console.log(`   Yearly:  ${advYearly.id}  ($1,200.00 USD/yr, 7-day trial)`);

  // ── Summary ──
  console.log("\n══════════════════════════════════════════");
  console.log("  Catalog Created — ID Mapping");
  console.log("══════════════════════════════════════════\n");
  console.log(JSON.stringify({
    starter: {
      product_id: starter.id,
      monthly:  starterMonthly.id,
      yearly:   starterYearly.id,
    },
    pro: {
      product_id: pro.id,
      monthly:  proMonthly.id,
      yearly:   proYearly.id,
    },
    advanced: {
      product_id: advanced.id,
      monthly:  advMonthly.id,
      yearly:   advYearly.id,
    },
  }, null, 2));
  console.log("\n✅ Done. Paste the price IDs into your app config.");
}

seed().catch((e) => {
  console.error("❌", e);
  process.exit(1);
});
