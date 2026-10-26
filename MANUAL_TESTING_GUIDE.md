# Laundry Friends - Manual Testing Guide (Non-Technical User)

## Setup
- Base URL: http://127.0.0.1:8899/LaundryFriendsDemo
- Browser: Chrome/Edge (works on mobile too). For "Mark Paid/Unpaid" and status buttons, use desktop or mobile view.
- Database on a real server: import `database/schema.sql` first, then import `database/seed_safe_existing.sql` (it only adds missing demo rows and never overwrites real data; safe to run twice). Demo order numbers: DEMO-1001 to DEMO-1004.
- Upgrading an existing database (adds the map columns and fixes duplicate mobiles): `php database/migrate.php`. Safe to run more than once.
- Local demo reset (development only): `php database\migrate.php --fresh && php database\seed.php` from the project root.
- To see technical error details while testing, set the environment variable `APP_DEBUG=1` before starting the server (keep it off on the live site).
- Test users (from seed):
  | Role | Login (Email/Mobile) | Password | Notes |
  | --- | --- | --- | --- |
  | Super Admin | superadmin@laundryfriends.test / 9000000001 | superadmin@123 | Highest level access |
  | Owner (Admin) | owner@laundryfriends.test / 9000000002 | owner@123 | Full access (orders, customers, staff, payments, reports) |
  | Pickup Staff | ramesh@laundryfriends.test / 9000000003 OR vikas@laundryfriends.test / 9000000005 | pickup@123 | Pickup tasks, add items on pickup, collect payment |
  | Delivery Staff | suresh@laundryfriends.test / 9000000004 OR anita@laundryfriends.test / 9000000006 | delivery@123 | Delivery tasks, collect/change payment with note |
  | Customer | anita.sharma@example.com / 9812345670 | customer123 | Customer dashboard |

> Mobile number is accepted as identity on login (with/without leading zero). Name and 10-digit mobile are mandatory everywhere.

## 1) Login Flow
1. Open base URL. Click **Login** or go to `/login.php`.
2. Try logging in with email or 10-digit mobile. Role is auto-detected (or select role hint if needed).
3. Enter correct password → should go to role dashboard (Owner → Admin, Staff → Staff area, Customer → Customer).
4. On mobile, check topbar shows **Logout** button.
5. Logout and login with wrong password → should show error and stay on login page.

## 2) Owner - Create Order (Auto-fill from existing customer + track old dues)
1. Login as Owner → **Orders → Create Order** (`/admin/order-create.php`).
2. Start typing existing customer name or mobile (e.g. `Anita Sharma` or `9812345670`). Wait ~350ms - **Existing customer found** panel appears.
3. Click **Use details** on a match. Name, phone, address should auto-fill (all editable).
4. If customer has old pending dues, an **alert** shows "Pending dues from old invoices" with total and count, plus **View old invoices** link.
5. Fill remaining details as needed (dates/staff/pricing in "More options"). Add items in **Add items now (optional)** if desired, else leave blank.
6. Click **Create Order**. Should redirect to order view with success message.
7. Go to **Admin → Customers** or search for that customer - old dues remain visible until paid.

## 3) Owner - Manage Payments on Order
1. Go to **Orders** → open an order (`/admin/order-view.php?id=...`).
2. If order not fully paid, see **Payment actions**: **Mark Paid** with method dropdown. Click it (no special note required) → order becomes paid, payment recorded.
3. If order is paid, see **Mark Unpaid**. Click it → all payments voided, paid amount becomes 0, status becomes pending. "Voided" badge appears in payments table.
4. Also can **Collect payment** manually with amount/method/reference/notes.

## 4) Pickup Staff - Shared Queue, Add Items, Collect Payment, Mark Picked Up
1. Login as **ramesh** (pickup). Go to **Staff → Pickup Queue** (`/staff/pickup.php`).
2. The list shows **all open pickup orders**, including ones assigned to another staff member or to nobody. Each tile shows the assignment ("Assigned to you", "Assigned to …", "Unassigned") and the **Unassigned** counter.
3. Find any order tile (assigned or not). Click **Add items to this order** (expand). Use item picker to add items (quantity/weight/rate) and click **Add to order** → success message, tile summary updates, and the order is now assigned to you.
4. If due amount > 0 and order in `new`/`pickup_assigned`, see **Collect payment** form. Enter amount (can be full or partial) and method → click **Collect** → payment recorded, due updates. Paying the full amount **closes** the order (see section 9).
5. Click **Mark Picked Up** on an order assigned to someone else → it succeeds and the order is reassigned to you. System asks for confirmation **twice** (OK then OK again).
6. Or **Ready for Delivery** if at facility - also double confirmed.
7. Login as **vikas** (the other pickup staff) and check the same orders are visible there too.

## 5) Delivery Staff - Shared Queue, Collect with Amount Change Rule
1. Login as **suresh** (delivery). Go to **Staff → Delivery Queue** (`/staff/delivery.php`).
2. The list shows **all orders that are ready for delivery or out for delivery**, assigned or not, with the **Unassigned** counter and the assignment on each tile.
3. Find order in `ready_for_delivery`/`out_for_delivery` (even if assigned to another delivery boy). Start delivery if needed (double confirmed) → the order is assigned to you.
4. In **Mark Delivered** form: if collecting payment and amount entered differs from pending due, **Note (required if amount changed)** becomes mandatory. Try submitting with different amount and empty note → error "The payment amount … differs from the pending due …. Add a note…"
5. Fill note (e.g. "Customer paid balance in cash") and submit → succeeds, order marked delivered, payment recorded.
6. For an order that is already **fully paid**, the payment inputs are hidden and the tile says *Closed (fully paid)*; **Mark Delivered** still works without collecting.

## 6) Customer Flow
1. Login as customer (email or mobile). **Dashboard** shows totals.
2. **My Orders** - see orders with status/progress.
3. **Schedule Pickup** - can book pickup (guest or logged in). If logged in with existing account having dues, booking page shows **dues strip** with pending invoice info.
4. **Profile** - phone is mandatory (10 digits). Save with valid 10-digit mobile → updates profile; duplicate mobile shows error.

## 7) Admin Extras (Quick Checks)
- **Payments**: Pending tab shows dues, can collect. History tab shows collected payments (voided payments excluded).
- **Dues**: Lists customers with outstanding balance; can collect from dues page.
- **Reports/Daily**: Filter by date, download CSV, print view works.
- **Catalog/Staff/Customers**: Manage as normal.

## 8) Validation Checklist (Must Pass)
- [ ] Login with mobile (with/without leading zero) works
- [ ] Name + 10-digit mobile required on register/profile/schedule/create/edit
- [ ] Owner can search existing customer on order-create, auto-fill editable, see old dues
- [ ] Every pickup staff sees all open pickup orders (assigned or not); handling one assigns it to them
- [ ] Every delivery staff sees all open deliveries (assigned or not); handling one assigns it to them
- [ ] Pickup boy can add items from pickup page and mark picked up (double confirm)
- [ ] Pickup/delivery payment collection works; delivery requires note if amount differs
- [ ] Fully paid order is closed: no item can be added from pickup, delivery, POS or admin edit
- [ ] Unpaid/partial order stays open: staff can still add items
- [ ] Owner Mark Paid/Mark Unpaid works; unpaid voids payments and reopens the order
- [ ] Registering a mobile that already has an order-time customer links the account (no fatal)
- [ ] Google Maps picker saves address + pin; Navigate link opens directions
- [ ] Duplicate/DB errors show a friendly message, never a stack trace; details appear in `storage/logs/app.log`
- [ ] Logout visible on mobile topbar
- [ ] No PHP errors on all pages

## 9) Closed Orders (Fully Paid)
1. Open an unpaid order and collect the full balance (pickup page, delivery page, Payments, Dues or **Mark Paid**).
2. The order now shows a green **Closed** badge on its tile and order page, and the activity log gains a *closed* entry.
3. Try to add an item: on the pickup/delivery page there is no *Add items* form and the tile says **Fully paid - items are locked**; POS and admin order-edit show the items read-only with a notice. Any direct POST is rejected.
4. Try to collect another payment: the forms are hidden and a direct POST returns "This order is already fully paid and closed."
5. As owner, click **Mark Unpaid** on the order page → payments are voided, the order reopens (activity log *reopened*) and items can be added again.
6. A **partially paid** order is *not* closed: collect part of the bill, then confirm staff can still add items and the new total is recalculated.

## 10) Registration With an Existing Order-Time Customer
Use this to reproduce the old `Duplicate entry` fatal safely.
1. As owner, create an order for a **new** mobile number (e.g. `9552903206`) so a customer record exists without a login.
2. Sign out and open **Register** (`/register.php`). Use the **same** mobile number, a name and a password.
3. Submit → it must **not** show a fatal error. The login is linked to the existing customer record, the address/name are refreshed, and the customer lands on *Schedule a Pickup*.
4. Confirm **Admin → Customers** still shows only **one** row for that mobile number, and its orders are still attached.
5. Try to register the same mobile number again → a friendly message says an account already exists; the page stays usable.
6. Guest booking (`/customer/schedule-pickup.php`) with a mobile that already has an order-time customer must also link and book successfully.

## 11) Google Maps Location Picker
1. As owner, go to **Admin → Settings**, paste a Google Maps JavaScript API key (Maps JavaScript API + Places API enabled, restricted to your domain) and save.
2. Open **Register**, **Schedule Pickup**, **My Profile** or **Admin → Create Order / Edit Order**. The map should appear with a **Use my current location** button and an address search box.
3. Search an address, or tap/drag the pin. The address, city, PIN code, latitude/longitude and place id are saved with the form.
4. Allow location access on a phone (HTTPS required in production) → the pin moves to the current position and the address is filled in.
5. Save the order, then open **Admin → Order View** or the staff pickup/delivery queue: the **Navigate** link opens Google Maps directions to the saved pin.
6. Remove the key and reload a form → manual address entry still works, the current-location button still saves a pin, and a notice explains that the map is not configured.

## 12) Error Handling
1. Trigger a duplicate registration (section 10). You should see a normal red form message, never a PHP stack trace.
2. Temporarily stop MySQL and open any page. You should see the friendly **Something went wrong** page with a reference code (or the database message in debug mode), not raw credentials or SQL.
3. Check `storage/logs/app.log` — the same reference code should be present with the technical details.
4. Confirm the page has **Back to home** and **Go back** buttons and the site is still usable after restarting MySQL.

## Tips for Testing
- Use the seed data first (reset if state changed). 
- For double-confirm buttons, be ready to click OK twice.
- When testing payment changes on delivery, always test both cases (same amount vs different amount).
- Check browser console for JS errors if something looks off.
