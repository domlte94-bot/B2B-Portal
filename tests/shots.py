import os, sys, re
from playwright.sync_api import sync_playwright

S = os.environ.get('B2BCP_TEST_DIR', '/tmp/b2bcp-test')
OUT = S + '/shots'
os.makedirs(OUT, exist_ok=True)
BASE = 'http://localhost:8099'
cookies = {}
for line in open(S + '/cookies.txt'):
    who, kv = line.strip().split(' ', 1)
    name, value = kv.split('=', 1)
    cookies.setdefault(who, []).append({'name': name, 'value': value, 'domain': 'localhost', 'path': '/'})

ids = {}
for kv in sys.argv[1:]:
    k, v = kv.split('=')
    ids[k] = v

errors = []

def ctx_for(browser, who, width=1280):
    ctx = browser.new_context(viewport={'width': width, 'height': 900})
    if who in cookies:
        ctx.add_cookies(cookies[who])
    page = ctx.new_page()
    page.on('pageerror', lambda e: errors.append(f'{who}: JS error: {e}'))
    return ctx, page

def shot(page, name, full=True):
    page.screenshot(path=f'{OUT}/{name}.png', full_page=full)
    html = page.content()
    for m in re.findall(r'(Warning|Notice|Fatal error|Deprecated)</b>:[^<]*<b>[^<]*', html):
        errors.append(f'{name}: PHP {m}')
    print('shot', name)

with sync_playwright() as p:
    b = p.chromium.launch()

    # Buyer for the bank: kit page, fill two people across two branches.
    ctx, page = ctx_for(b, 'ana')
    page.goto(f'{BASE}/?p={ids["kit"]}')
    page.wait_for_selector('.b2bcp-order')
    rows = page.locator('.b2bcp-people-list .b2bcp-person')
    rows.nth(0).locator('input[name$="[name]"]').fill('maria garcia')
    rows.nth(0).locator('input[name$="[title]"]').fill('Branch Manager')
    rows.nth(0).locator('select[name$="[color]"]').select_option('Gold')
    rows.nth(0).locator('select.b2bcp-person-address').select_option(ids['a1'])
    page.click('.b2bcp-add-person')
    rows.nth(1).locator('input[name$="[name]"]').fill('JOHN SMITH')
    rows.nth(1).locator('input[name$="[title]"]').fill('Teller')
    rows.nth(1).locator('select[name$="[color]"]').select_option('Silver')
    rows.nth(1).locator('select.b2bcp-person-address').select_option(ids['a2'])
    page.click('.b2bcp-add-person')
    rows.nth(2).locator('input[name$="[name]"]').fill('luz rivera')
    rows.nth(2).locator('select[name$="[color]"]').select_option('Gold')
    rows.nth(2).locator('select.b2bcp-person-address').select_option(ids['a1'])
    page.locator('input[name$="[title]"]').first.click()  # blur to apply Title Case
    shot(page, '01-kit-form')
    # Only some parts.
    page.check('input[name=b2bcp_parts_mode][value=parts]')
    page.check(f'input[name="b2bcp_parts_chosen[]"][value="{ids["plate"]}"]')
    shot(page, '02-parts-mode', full=False)
    page.check('input[name=b2bcp_parts_mode][value=complete]')
    page.click('button.single_add_to_cart_button')
    page.wait_for_load_state('networkidle')
    shot(page, '03-after-add')

    # Magnetic desk bar: name-only parts + replaceable magnet.
    page.goto(f'{BASE}/?p={ids["mbar"]}')
    page.check('input[name=b2bcp_parts_mode][value=parts]')
    shot(page, '04a-deskbar-parts', full=False)

    # Holiday sign: quantities per address, and a new address added on the spot.
    page.goto(f'{BASE}/?p={ids["sign"]}')
    page.click('.b2bcp-new-address summary')
    page.fill('input[data-address-key=label]', 'Springfield Office')
    page.fill('input[data-address-key=code]', '045')
    page.fill('input[data-address-key=address_1]', '700 Capitol Ave')
    page.fill('input[data-address-key=city]', 'Springfield')
    page.fill('input[data-address-key=state]', 'IL')
    page.fill('input[data-address-key=postcode]', '62701')
    page.press('input[data-address-key=postcode]', 'Enter')
    page.wait_for_selector('input[name^="b2bcp_qty"][aria-label="Springfield Office (#045)"]', timeout=10000)
    if 'Saved' not in page.inner_text('.b2bcp-new-address-status'):
        errors.append('new address: ' + page.inner_text('.b2bcp-new-address-status'))
    shot(page, '04b-new-address', full=False)
    page.fill('.b2bcp-all-qty', '2')
    page.click('.b2bcp-apply-qty')
    page.fill(f'input[name="b2bcp_qty[{ids["a1"]}]"]', '4')
    page.fill('input[aria-label="Springfield Office (#045)"]', '3')
    shot(page, '04-sign-branches')
    page.click('button.single_add_to_cart_button')
    page.wait_for_load_state('networkidle')

    page.goto(f'{BASE}/?page_id={ids["cart_page"]}')
    shot(page, '05-cart')
    page.goto(f'{BASE}/?page_id={ids["checkout_page"]}')
    page.wait_for_timeout(1500)
    shot(page, '06-checkout')
    try:
        page.fill('#billing_first_name', 'Ana')
        page.fill('#billing_last_name', 'Lopez')
        if page.locator('#payment_method_b2bcp_invoice').count():
            page.click('label[for=payment_method_b2bcp_invoice]'); page.wait_for_timeout(800)
        page.click('#place_order')
        page.wait_for_url(re.compile(r'order-received'), timeout=20000)
        shot(page, '07-order-received')
    except Exception as e:
        errors.append(f'checkout: {e}')
        shot(page, '07-checkout-error')

    page.goto(f'{BASE}/?page_id={ids["account_page"]}&company=1')
    shot(page, '08-account-company')
    ctx.close()

    # Phone width.
    ctx, page = ctx_for(b, 'ana', width=390)
    page.goto(f'{BASE}/?p={ids["kit"]}')
    shot(page, '09-kit-phone')
    ctx.close()

    # Guest: quote form and shop.
    ctx, page = ctx_for(b, 'guest')
    page.goto(f'{BASE}/?post_type=product')
    shot(page, '10-guest-shop')
    page.goto(f'{BASE}/?p={ids["quote"]}')
    shot(page, '11-quote-form')
    page.goto(f'{BASE}/?p={ids["kit"]}')
    title = page.title()
    if '404' not in title and 'not found' not in title.lower():
        errors.append('guest could open the exclusive kit page: ' + title)
    else:
        print('guest kit page: 404 OK')
    ctx.close()

    # Store admin.
    ctx, page = ctx_for(b, 'admin', width=1440)
    page.goto(f'{BASE}/wp-admin/post.php?post={ids["bank"]}&action=edit')
    shot(page, '12-admin-company')
    page.goto(f'{BASE}/wp-admin/post.php?post={ids["kit"]}&action=edit')
    page.click('li.b2bcp_options a, li.b2bcp_tab a', timeout=5000) if page.locator('li.b2bcp_options a, li.b2bcp_tab a').count() else None
    page.wait_for_timeout(500)
    el = page.locator('#woocommerce-product-data')
    el.screenshot(path=f'{OUT}/13-admin-product-tab.png')
    print('shot 13-admin-product-tab')
    page.goto(f'{BASE}/wp-admin/edit.php?post_type=b2bcp_quote')
    shot(page, '14-admin-quotes')
    page.goto(f'{BASE}/wp-admin/edit.php?post_type=b2bcp_company')
    shot(page, '15-admin-companies')
    ctx.close()
    b.close()

print('\n'.join(errors) if errors else 'NO ERRORS')
