=== B2B Contract Portal for WooCommerce ===
Contributors: muvvesstudio
Tags: b2b, wholesale, personalization, name badges, multiple addresses
Requires at least: 6.3
Tested up to: 6.8
Requires PHP: 7.4
WC requires at least: 8.0
WC tested up to: 10.2
Stable tag: 0.3.3
License: GPLv2 or later

Contract customers for print and promo shops: client companies, exclusive catalogs and prices, products with parts, per-person names and titles, many addresses in one order.

== Description ==

Built for print shops, sign shops and promotional-product stores that sell to companies on contract.

* **Client companies.** Each company has its users (invitation only), shipping addresses, fixed billing address, payment terms and its own logo and accent color.
* **Templates and company products.** Mark "Name badge" or "Desk bar" as a template once, then create each company's own copy from the company page and give it that company's design, fields and price.
* **Exclusive catalog and contract prices.** Mark a product "Exclusive" and only the companies it is assigned to can see or order it. Each company can have its own price. Edit assignments from the company or from the product.
* **Personalized products.** Buyers add one row per person (name, title, any field you define). Each row is one unit. Text can be forced to Title Case or UPPERCASE, with a character limit for the space on the product. Lists can be pasted straight from Excel.
* **Products with parts.** A product can be made of parts: a desk bar = base + name magnet + back plate, or a welcome pack = badge + desk bar + door plate. Parts can be just a name (for production) or a store product. Mark which parts can also be ordered alone, e.g. a replacement name magnet, charged at that part's own price. Names typed once fill every part that uses them.
* **Many addresses, one order.** Turn on multi-address per product. Buyers send each person's item, or a quantity per address, to their company's saved addresses, and can add a new address right on the product page (or in My Account) without leaving it. You can turn that off per company. One order and one invoice; each address is its own shipping package rated by your normal WooCommerce shipping zones. Optional "shipping included in contract".
* **Card, invoice or both per company.** Adds an "Invoice (net terms)" payment method that only companies allowed to use it can see.
* **Quote requests.** Products with no price: visitors fill a form with options you define (including file upload) and the request lands in B2B Portal → Quote requests and in your inbox.
* **Production sheet.** Download a CSV from any order (or many at once from the orders list): one row per part and person, with the address it ships to.

== Installation ==

1. Upload the plugin zip in Plugins → Add New → Upload Plugin, then activate. WooCommerce must be active.
2. Go to B2B Portal → Add company. Fill the contract details, addresses and products, and invite users.
3. On each product, open the "B2B Portal" tab to make it exclusive, personalized, made of parts, multi-address or quote-only.
4. In WooCommerce → Settings → Payments, check that "Invoice (contract terms)" is enabled.

== Frequently Asked Questions ==

= Does it work with the block Cart and Checkout? =
It is built for both, using WooCommerce's shared cart, shipping and order hooks plus a block integration for the invoice payment method. Version 0.2.0 was tested end to end on the classic cart and checkout; test the block checkout on your store before going live. In both, the fixed company billing address is applied when the order is saved.

= Does it work with variable products? =
Contract prices apply to all variations of a product. Personalization, parts and multi-address are designed for simple products.

= What happens when a buyer belongs to two companies? =
They choose which company they are ordering for in My Account → Company. Catalog, prices and cart follow that choice.

== Changelog ==

= 0.3.3 =
* Invitations open a welcome page to choose a password (with the company logo and a message you can edit in Settings), then log the buyer in. The link is valid for a week.

= 0.3.2 =
* Emails: no stray characters after the button.

= 0.3.1 =
* Field builder: the options box only shows for dropdown fields, and is wider.

= 0.3.0 =
* Templates: mark a product as a template and create each company's own copy from the company page, with that company's design, fields and price.
* The company page separates products made for the company from shared products.
* Updates arrive in WordPress like any other plugin (Dashboard → Updates), from the plugin's GitHub releases.

= 0.2.0 =
* Kits become "products with parts": parts can be just a name, and each part says whether it can be ordered alone.
* Buyers can add shipping addresses on the product page and in My Account (per-company setting).
* Orders shipped only to saved addresses say so instead of showing N/A.

= 0.1.0 =
* First version: companies, exclusive catalog, contract prices, personalization, kits, multi-address shipping, invoice payment, quote requests, branding, production CSV.
