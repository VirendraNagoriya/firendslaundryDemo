# Laundry Friends - Manual Testing Guide (Non-Technical User)

## Setup
- Base URL: http://127.0.0.1:8899/LaundryFriendsDemo
- Browser: Chrome/Edge (works on mobile too). For "Mark Paid/Unpaid" and status buttons, use desktop or mobile view.
- Database on a real server: import `database/schema.sql` first, then import `database/seed_safe_existing.sql` (it only adds missing demo rows and never overwrites real data; safe to run twice). Demo order numbers: DEMO-1001 to DEMO-1004.
- Upgrading an existing database: **back up first**, then run `php database/migrate.php`. It adds the unified staff role, the owner-managed `services` table (mapping the old catalog and order lines to it) and the weight-verification columns. Safe to run more than once.
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
   - Tap **+ Add item**, choose a **service** chip first, then tap one of that service's items (or type a custom name for a piece service). For a kg service only that service's catalog items are offered, and the weight you enter is an estimate that the pickup boy verifies.
6. Click **Create Order**. Should redirect to order view with success message.
7. Go to **Admin → Customers** or search for that customer - old dues remain visible until paid.

## 3) Owner - Manage Payments on Order
1. Go to **Orders** → open an order (`/admin/order-view.php?id=...`).
2. If order not fully paid, see **Payment actions**: **Mark Paid** with method dropdown. Click it (no special note required) → order becomes paid, payment recorded. It only *closes* once the order is also delivered.
3. If order is paid, see **Mark Unpaid**. Click it → all payments voided, paid amount becomes 0, status becomes pending. "Voided" badge appears in payments table.
4. Also can **Collect payment** manually with amount/method/reference/notes.

## 4) Pickup Staff - Shared Queue, Add Items, Collect Payment, Mark Picked Up
1. Login as **ramesh** (pickup). Go to **Staff → Pickup Queue** (`/staff/pickup.php`).
2. The list shows **all open pickup orders**, including ones assigned to another staff member or to nobody. Each tile shows the assignment ("Assigned to you", "Assigned to …", "Unassigned") and the **Unassigned** counter.
3. Find any order tile (assigned or not). Click **Add items to this order** (expand). Use the service-first item picker (choose a service, then tap its items; for kg services only that service's items are allowed), set quantity/weight/rate and click **Add to order** → success message, tile summary updates, and the order is now assigned to you.
4. For a kg order, open **POS** (`/staff/pos.php?order_id=…`) and use **Verify actual weight**: enter the measured kg, save, and the line is marked **Verified** (customer entries show **Estimated**). The bill recalculates.
5. If due amount > 0 and order in `new`/`pickup_assigned`, see **Collect payment** form. Enter amount (can be full or partial) and method → click **Collect** → payment recorded, due updates. Paying the full amount **closes** the order only if it is already delivered (see section 10).
6. Click **Mark Picked Up** on an order assigned to someone else → it succeeds and the order is reassigned to you. System asks for confirmation **twice** (OK then OK again).
7. Or **Ready for Delivery** if at facility - also double confirmed; this records you as the delivery handler.
8. Login as **vikas** (the other pickup staff) and check the same orders are visible there too.
9. Open **Staff → All Orders** (`/staff/orders.php`): every order is listed for every staff login, with search, status/payment filters and **All / Open / Delivered / Payment Pending** tabs.

## 5) Delivery Staff - Shared Queue, Collect with Amount Change Rule
1. Login as **suresh** (delivery). Go to **Staff → Delivery Queue** (`/staff/delivery.php`).
2. The list shows **all orders that are ready for delivery or out for delivery**, assigned or not, with the **Unassigned** counter and the assignment on each tile.
3. Find order in `ready_for_delivery`/`out_for_delivery` (even if assigned to another delivery boy). Start delivery if needed (double confirmed) → the order is assigned to you.
4. In **Mark Delivered** form: if collecting payment and amount entered differs from pending due, **Note (required if amount changed)** becomes mandatory. Try submitting with different amount and empty note → error "The payment amount … differs from the pending due …. Add a note…"
5. Fill note (e.g. "Customer paid balance in cash") and submit → succeeds, order marked delivered, payment recorded.
6. For an order that is already **fully paid**, the payment inputs are hidden; **Mark Delivered** still works without collecting. After delivery it shows **Closed (delivered & paid)**.
7. After a delivered order with an unpaid balance, open the **Payment Pending** tab: the order stays visible, any staff member can collect the balance there, and the last payment closes it.

## 6) Customer Flow
1. Login as customer (email or mobile). **Dashboard** shows totals.
2. **My Orders** - see orders with status/progress.
3. **Schedule Pickup** - can book pickup (guest or logged in). If logged in with existing account having dues, booking page shows **dues strip** with pending invoice info.
   - Items are added service-first. For kg services the page states that the weight is an **estimate** and the pickup boy will measure/verify the actual kg; only that service's catalog items can be picked.
4. **My Orders → order detail** shows per-kg lines with **Estimate** or **Verified** badges.
5. **Profile** - phone is mandatory (10 digits). Save with valid 10-digit mobile → updates profile; duplicate mobile shows error.

## 7) Admin Extras (Quick Checks)
- **Payments**: Pending tab shows dues, can collect. History tab shows collected payments (voided payments excluded). Delivered orders with a balance are listed until the last payment closes them.
- **Dues**: Lists customers with outstanding balance; can collect from dues page.
- **Orders**: the **Delivered** and **Payment Pending** tabs filter the order list.
- **Reports/Daily**: Filter by date, download CSV, print view works.
- **Staff/Customers**: Manage as normal.

## 8) Owner - Services & Items (kg / piece)
1. Login as Owner → **Services & Items** (`/admin/catalog.php`).
2. In **Add Service**, create e.g. *Premium Wash* with **Billed by = Per piece**, rate `25`, and save. Create a second service with **Per kg** and a kg rate.
3. In **Add Item**, enter an item name (e.g. `Shirt`), choose the service and save. The same name can be added to a different service with a different rate.
4. For a kg service, only items mapped to that service appear in the item picker. For a piece service a custom name can still be typed.
5. Edit or **Disable** a service/item; disabled services and items disappear from every picker but old orders keep their line names.
6. Open **Create Order** and confirm the new service appears in the picker with its items.

## 9) Validation Checklist (Must Pass)
- [ ] Login with mobile (with/without leading zero) works
- [ ] Name + 10-digit mobile required on register/profile/schedule/create/edit
- [ ] Owner can search existing customer on order-create, auto-fill editable, see old dues
- [ ] Every pickup staff sees all open pickup orders (assigned or not); handling one assigns it to them
- [ ] Every delivery staff sees all open deliveries (assigned or not); handling one assigns it to them
- [ ] **All Orders** page lists every order for every staff login, with working filters and tabs
- [ ] Pickup boy can add items from pickup page and mark picked up (double confirm)
- [ ] Pickup/delivery payment collection works; delivery requires note if amount differs
- [ ] Owner can create a piece service and a kg service, map items, and edit/disable them
- [ ] Kg services only accept their own catalog items; the weight hint says the pickup boy verifies
- [ ] Customer weight shows as **Estimate**; POS weight verification switches it to **Verified** and recalculates
- [ ] Delivered + fully paid order is closed: no item can be added from pickup, delivery, POS or admin edit
- [ ] Delivered + unpaid order stays in **Payment Pending**; any staff member can collect the balance
- [ ] Open/undelivered order stays editable even when fully paid
- [ ] Owner Mark Paid/Mark Unpaid works; unpaid voids payments and reopens the balance
- [ ] Install App button appears on the public page and in customer/staff/admin layouts
- [ ] Registering a mobile that already has an order-time customer links the account (no fatal)
- [ ] Navigate link opens Google Maps directions for the order address
- [ ] Duplicate/DB errors show a friendly message, never a stack trace; details appear in `storage/logs/app.log`
- [ ] Logout visible on mobile topbar
- [ ] No PHP errors on all pages

## 10) Closed Orders (Delivered + Fully Paid)
1. Open an order that is **delivered** and still has a balance (or deliver it while collecting only part of the bill).
2. Collect the full balance (delivery **Payment Pending** tab, All Orders, pickup page, Payments, Dues or **Mark Paid**).
3. The order now shows a green **Closed** badge on its tile and order page, and the activity log gains a *closed* entry.
4. Try to add an item: on the pickup/delivery/All Orders pages there is no *Add items* form and the tile says **Closed (delivered & paid)**; POS and admin order-edit show the items read-only with a notice. Any direct POST is rejected.
5. Try to collect another payment: the forms are hidden and a direct POST returns "This order is delivered and fully paid, so it is closed."
6. As owner, click **Mark Unpaid** on the order page → payments are voided and the balance reopens (activity log *reopened*), but a delivered order keeps its items locked.
7. A **delivered but partially paid** order is *not* closed: it stays in **Payment Pending**, any staff member can collect the rest, and the items stay locked.
8. An **undelivered** order stays editable even when fully paid – closure only happens after delivery. Collecting the full amount before delivery does not lock the items.

## 11) Registration With an Existing Order-Time Customer
Use this to reproduce the old `Duplicate entry` fatal safely.
1. As owner, create an order for a **new** mobile number (e.g. `9552903206`) so a customer record exists without a login.
2. Sign out and open **Register** (`/register.php`). Use the **same** mobile number, a name and a password.
3. Submit → it must **not** show a fatal error. The login is linked to the existing customer record, the address/name are refreshed, and the customer lands on *Schedule a Pickup*.
4. Confirm **Admin → Customers** still shows only **one** row for that mobile number, and its orders are still attached.
5. Try to register the same mobile number again → a friendly message says an account already exists; the page stays usable.
6. Guest booking (`/customer/schedule-pickup.php`) with a mobile that already has an order-time customer must also link and book successfully.

## 12) Navigate to the Customer
1. Open **Register**, **Schedule Pickup**, **My Profile** or **Admin → Create Order / Edit Order** and confirm the forms only ask for the address, city, landmark and PIN code (there is no map or current-location button).
2. Save an order with an address, then open **Admin → Order View** or the staff pickup/delivery/All Orders queue.
3. Click **Navigate** → Google Maps opens with directions to the order address (a previously saved pin is used when one exists).
4. Confirm **Admin → Settings** no longer shows a Google Maps API key field.

## 13) Install App (PWA)
1. Open the public home page in Chrome/Edge on desktop or Android → click **Install App** in the header. The browser's install prompt should appear; accept it and check the app opens in its own window.
2. Sign in as customer, staff and owner and confirm **Install App** is also in the app header/topbar.
3. On iPhone/iPad Safari (where the native prompt is not offered) click **Install App** → a short message explains *Share → Add to Home Screen*.
4. After installing, open the app from the home screen and confirm it loads without the browser address bar.

## 14) Error Handling
1. Trigger a duplicate registration (section 11). You should see a normal red form message, never a PHP stack trace.
2. Temporarily stop MySQL and open any page. You should see the friendly **Something went wrong** page with a reference code (or the database message in debug mode), not raw credentials or SQL.
3. Check `storage/logs/app.log` — the same reference code should be present with the technical details.
4. Confirm the page has **Back to home** and **Go back** buttons and the site is still usable after restarting MySQL.

## Tips for Testing
- Use the seed data first (reset if state changed). 
- For double-confirm buttons, be ready to click OK twice.
- When testing payment changes on delivery, always test both cases (same amount vs different amount).
- Always test a kg service: the customer enters an estimate, then verify the actual weight in POS and check the total changes.
- Check browser console for JS errors if something looks off.
