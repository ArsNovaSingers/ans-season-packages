# ans-season-packages

Applies the Ars Nova Singers season package discounts by counting **distinct concert
categories** in the cart, not line items.

| Distinct concerts | Discount |
|---|---|
| 0-2 | none |
| 3-4 | 15% - Flex Pass |
| 5+  | 20% - Season Package |

Highest matching tier wins. The discount applies **only** to cart items carrying a child term of
the `Season Concerts` product category (term 86), which is what excludes the House Concert, the
Blake Morgan residency, Livestream, all Student and Youth tickets, and the Nova Circle membership
fee.

## Why it exists

It replaces two hand-configured **Discount Rules for WooCommerce** (Flycart, free) rules that
enumerated Adult product IDs, because category filtering is a PRO feature. Those rules went stale
silently when Springs & Gears was rebuilt from one performance into four, and on 2026-08-18 a real
customer was charged **$176 for a $160 package**. Nothing surfaced an error and nothing could have.

A category-driven rule cannot go stale when a performance is added, because adding a performance
*is* categorising a product.

It also closes a second, older defect: three different **nights** of one concert used to read as
three concerts and wrongly earned 15% off - $102 where it should be $120.

## Design notes for whoever edits this next

- **`woocommerce_before_calculate_totals` fires repeatedly in one request.** Every price is
  computed from the product's *stored* price meta (`get_regular_price()` / `get_sale_price()` in
  `edit` context), never from `get_price()`, which is the value this plugin sets. That makes the
  calculation idempotent. Do not "optimise" `ans_sp_base_price()` into `get_price()`.
- **Item price, not a negative cart fee.** A fee is taxed separately from the items it discounts;
  adjusting the item price keeps tax following the item.
- **The category is the gate, not ticket-ness.** Since 2026-08-20 the Nova Circle membership fee
  is itself a real Tickera ticket type, so a "skip tickets" rule would skip everything.
- **Nothing is injected into the block checkout's DOM.** The customer-visible reason goes through
  `woocommerce_get_item_data`, which reaches the classic cart, the block cart, the checkout and the
  Store API's `item_data` as data rather than markup.

## Filters

| Filter | Default | Purpose |
|---|---|---|
| `ans_sp_parent_term_id` | `86` | Parent `product_cat` term whose children are the concerts |
| `ans_sp_tiers` | 5 -> 20%, 3 -> 15% | The tier table; re-sorted descending by `min` after filtering |

## Order records

Each discounted line item carries `_ans_season_package` (machine-readable array: tier, label,
percent, distinct concert count, base price, unit price, plugin version) and a human-readable
`Season discount` meta for the box office and for refunds.

## Rollback

This is a standalone plugin on purpose - deactivating it is the entire rollback, and it touches no
ticketing infrastructure. Re-enabling the two Flycart rules restores the previous behaviour with no
code change.

## Free audit that falls out of the design

The sum of the `product_cat` counts on the concert terms must equal the number of Adult products
the discount considers. Two API calls, and it catches a miscategorised product before a customer
does.
