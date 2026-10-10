# B2B Contract Portal for WooCommerce

WordPress plugin sold by Muvves Studio (Dome Torres) to print shops, sign shops and promo-product stores that sell to companies on contract. Version 0.3.0 = "Fase 1" of the spec plus the parts/addresses changes and templates → company products. Owner talks in Spanish; plugin UI strings are English with the `b2b-contract-portal` text domain (a `.pot` is in `languages/`).

Origin: the ideas come from the Kane RFQ plugin Dome built at Kane Graphical (nametags, desk bars, KaneKlings multi-branch orders). This is a clean rebuild with no Kane code, no Fulcrum/ERP integration and nothing client-specific. Keep it that way.

## Product decisions (agreed with Dome, Oct 2026)

- Companies are the core unit: users, branches, fixed billing address, payment mode, terms, logo + accent color.
- Users join a company **by invitation only**, after the company signs with the print shop. One user can belong to several companies; they pick the active one in My Account → Company (cart is emptied on switch).
- Products are **Public** (everyone; companies may have a contract price) or **Exclusive** (only assigned companies). Assignments + prices are editable from the company screen and the product tab (same table).
- **Templates and company products** (0.3.0): a name badge or desk bar has each company's own design (logo, colors), so it is not one general product assigned to many. A product ticked as **Template** is never sold; from the company screen "New product from template" copies it (WooCommerce's duplicator) into "Company – Template name", a draft, exclusive, assigned only to that company, where the store adds that company's images, artwork, fields and price. Copies are independent: editing the template later does not touch them. Shared products (public/exclusive assigned to several companies) stay for things that really are the same for everyone.
- **Products with parts** (Dome does not want the word "kit"): a desk bar = base + name magnet + back plate; a welcome pack = badge + desk bar + plate. A part is either just a name (production only, uses the main product's personalization) or a store product (uses its own personalization and price). Each part has "can be ordered alone" (only for store-product parts), e.g. the replacement name magnet. Customer chooses "Complete product" or "Only some parts (replacements)". Personalization fields merge by field key so a name is typed once.
- **Personalization**: one row per person = one unit. Field builder (text, dropdown, note) with required, max chars, Title Case / UPPERCASE.
- **Addresses**: buyers add addresses themselves, on the product page (AJAX panel, saved to the company address book) and in My Account → Company, like the multiple-addresses flow in Dome's Kane plugin. Per-company setting "Buyers can add new shipping addresses" (default on). Company admins can always add; only company admins delete. Customer-facing text says "address", not "branch".
- **Multi-address**: per product toggle. Each branch is its own WooCommerce shipping package, rated by the store's normal zones/methods (no custom rate system). Company option "shipping included in contract" → single $0 rate. Checkout does not ask for a shipping address when every item ships to a saved address; the order's shipping company then reads "Several addresses — see each item".
- **No quotes in the cart.** Prices are negotiated outside the plugin. The plugin only has **quote-request products** (no price, form with admin-defined options incl. file upload) for new clients or products outside a company's list. Requests are a CPT with status + emails; follow-up happens outside.
- **Payment**: per company card, invoice (net terms) or both. Invoice gateway puts orders on hold.
- Pending business decisions: product name, pricing model (annual license vs monthly). Phase 2 ideas: volume tiers, % discounts, CSV import of prices, bulk CSV people upload, approvals by company admin, contract expiry, live preview of text on the product. Phase 3: ERP integrations, reports, recurring orders.

## Code map

```
b2b-contract-portal.php      bootstrap, HPOS compatibility, loads includes on plugins_loaded
includes/class-install.php   tables: {prefix}b2bcp_prices (company_id, product_id, price NULL=regular), {prefix}b2bcp_addresses
includes/helpers.php         data API: companies, user↔company (user meta _b2bcp_companies/_b2bcp_company_admin/_b2bcp_active_company),
                             assignments, product config (post meta _b2bcp_*, parts in _b2bcp_has_parts/_b2bcp_parts), access rules, addresses, color contrast, request cache,
                             templates (_b2bcp_template) and company products (_b2bcp_owner_company, _b2bcp_template_id; b2bcp_create_company_product())
includes/class-fields.php    field builder: definitions, sanitize, render, validate/format answers
includes/class-company-admin.php   CPT b2bcp_company, meta boxes, save, invitations
includes/class-product-admin.php   "B2B Portal" product tab + save
includes/class-catalog.php   hides exclusive products (queries, loops, related, single → 404), contract prices
includes/class-order-form.php      product-page form, build_lines() (pure: POST → cart lines), cart display, qty lock, order line meta
includes/class-shipping.php  splits packages by branch, package names, included shipping, shipping line meta
includes/class-payments.php  invoice gateway + gateway filtering + billing lock + order stamping (_b2bcp_company_id)
includes/class-gateway-invoice-blocks.php + assets/invoice-blocks.js   invoice in the Checkout block
includes/class-branding.php  company bar + accent CSS variables
includes/class-account.php   My Account → Company endpoint (switch company, addresses) + AJAX b2bcp_add_address
includes/class-quotes.php    quote products, form, CPT b2bcp_quote, admin screens
includes/class-emails.php    invitation, quote emails (via WC mailer), company header + extra recipients on order emails
includes/class-export.php    production sheet CSV (order box + bulk action)
includes/class-settings.php  B2B Portal → Settings
```

Cart line data lives in `$cart_item['b2bcp']` and is copied to order item meta `_b2bcp` (array: company_id, address_id, address, people, labels, per_person, part_of, complete, components snapshot, line_id). Readable rows (Ship to, Kit includes, Person N) are added as visible meta.

Gotchas already handled:
- Per-person lines carry a unique `line_id` so WooCommerce never merges two submissions (that would add quantities but keep one people list).
- `b2bcp_hidden_product_ids()` queries with `b2bcp_skip` so the catalog filter does not filter its own lookup.
- In-request caches are versioned; `b2bcp_cache_bust()` runs on assignment changes and any `_b2bcp_*` post meta change.
- WooCommerce's own add-to-cart is stopped (`unset $_REQUEST['add-to-cart']`) when our form handles the submit on `wp_loaded` priority 19.

## Testing

`tests/` holds the scripts used to verify 0.1.0 on WordPress 6.8 + WooCommerce 10.2.2 (SQLite, Twenty Twenty-One, classic cart/checkout):

- `e2e.php` — run with `wp eval-file tests/e2e.php` on a throwaway site: creates products, two companies, branches, invites a user, checks visibility, prices, parts, form validation, cart, shipping packages, payment filtering, order creation, invoice payment, emails, production CSV, quote requests. 89 checks.
- `check2.php` — `B2BCP_WHO=guest|bank|coop wp eval-file tests/check2.php` after e2e: visibility, gateways, included shipping, shipping-address skip in fresh processes.
- `admin-save.php` — company and product admin saves, who may add addresses, templates and company products. 30 checks (the 12 template checks were written for 0.3.0 without a PHP runtime at hand: run them before releasing).
- `shots.py` — Playwright: full buyer flow incl. adding an address on the product page, parts mode, classic checkout with invoice, admin screens. Set B2BCP_TEST_DIR; needs logged-in cookies (see the script).
- `fetch_vendor.py`, `gen_autoload.php` — how WooCommerce was built from its git source when wordpress.org was unreachable.

Never run these on a live store: they create data and change WooCommerce settings.

Not yet tested end to end: the block-based Cart/Checkout in a real store (code paths exist), page caching plugins, multisite.
