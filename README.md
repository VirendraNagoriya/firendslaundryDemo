# Laundry Friends – Laundry Service Management System

A role-based web application for a laundry business: public marketing site, customer portal,
owner/admin back office, pickup & delivery staff modules, payment collection, daily reports and
database backup/restore.

Built with plain PHP 8 (no frameworks), MySQL/MariaDB, HTML5, CSS and vanilla JavaScript so it
runs on a stock XAMPP installation.

---

## 1. Requirements

| Component | Version used |
|-----------|---------------|
| PHP       | 8.0.30 (XAMPP) with PDO MySQL |
| Database  | MariaDB 10.4 / MySQL 5.7+ |
| Web server | Apache (XAMPP) or `php -S` for a quick test |

No Composer / npm dependencies.

## 2. Installation

1. Copy the project into the web root, e.g. `C:\xampp\htdocs\LaundryFriendsDemo`.
2. Start **Apache** and **MySQL** from the XAMPP control panel.
3. Check the database credentials in `config/config.php` (`DB_HOST`, `DB_PORT`, `DB_NAME`,
   `DB_USER`, `DB_PASS`). To override them without touching the tracked file, create
   `config/local.php` (git-ignored) that redefines those constants.
4. Create the database and import the schema + demo data:

   ```bash
   php database/migrate.php            # create database + tables (safe to re-run)
   php database/migrate.php --fresh    # DROP all tables and rebuild from scratch
   php database/seed.php               # demo users, catalog, customers and orders
   ```

   `migrate.php` is also the upgrade script: re-running it on an older database adds the
   unified `staff` role, converts the former `pickup` / `delivery` accounts, creates the
   owner-managed `services` table while mapping the old catalog and existing order lines to it,
   and adds the weight-verification columns and the `delivery_assigned_at` timestamp. The legacy
   `latitude` / `longitude` / `place_id` columns are kept so any previously saved pins still
   power the **Navigate** links.
   **Back up the database before upgrading a live site.**

5. Open `http://localhost/LaundryFriendsDemo/` in the browser.

### Error handling and logs

* A global handler catches uncaught exceptions, PHP warnings and fatal shutdown errors. Visitors
  see a friendly page with a short reference code (or a JSON error for AJAX requests) instead of a
  stack trace; the full details go to `storage/logs/app.log`.
* Database helpers translate duplicate-key and foreign-key failures into readable form messages.
  Registration also re-links an existing order-time customer record (same mobile number, no login)
  instead of failing with `Duplicate entry`.
* Set `APP_DEBUG=1` in the environment (or define `APP_DEBUG` in `config/local.php`) to show the
  technical details on the error page while developing. Keep it off on the live site.
* On a live server the web user must be able to write to `storage/logs/`.

The `BASE_URL` is detected automatically from `DOCUMENT_ROOT`, so the app works in a sub-folder
(`/LaundryFriendsDemo`) or at the document root without configuration.

### Quick test without Apache

```bash
php -S 127.0.0.1:8899 -t C:\xampp\htdocs
# then open http://127.0.0.1:8899/LaundryFriendsDemo
```

## 3. Demo accounts

| Role | Login (email or mobile) | Password |
|------|-------------------------|----------|
| Super admin | `superadmin@laundryfriends.test` / `9000000001` | `superadmin@123` |
| Owner (admin) | `owner@laundryfriends.test` / `9000000002` | `owner@123` |
| Staff | `ramesh@laundryfriends.test` / `9000000003` | `pickup@123` |
| Staff | `suresh@laundryfriends.test` / `9000000004` | `delivery@123` |
| Staff | `vikas@laundryfriends.test` / `9000000005` | `pickup@123` |
| Staff | `anita@laundryfriends.test` / `9000000006` | `delivery@123` |
| Customer | `anita.sharma@example.com` / `9812345670` | `customer123` |

All roles sign in from the same **Sign in** button on the public home page; the tab only filters
the intended role (Customer / Staff / Admin).

### Staff accounts

* There is **one staff role** – every staff member can do pickup work, delivery work, collect
  payments and add items at the customer's place.
* Staff credentials are fully controlled by the owner: staff accounts are created in
  *Staff Accounts → Create Staff Account* and the owner resets passwords from the same page.
* Staff members therefore **cannot** edit their own name, mobile number, email or password –
  *My Details* shows a read-only card with their Staff ID (`LF-0003`) and the owner is asked to
  make any change.
* Databases created before the unified role are upgraded automatically by `database/migrate.php`
  (old `pickup` / `delivery` accounts become `staff`). Use
  `php database/migrate.php --db=other_db` to migrate a different database name.

## 4. Features

**Public site**
- Marketing landing page with services, pricing and a "Schedule a Pickup" call to action.
- Guest pickup booking creates the customer account and logs the guest in.
- Customer registration and login (customer / staff / admin tabs).
- Address fields on registration, pickup booking, profile and admin order create/edit. Every
  order and staff queue has a one-tap **Navigate** link that opens Google Maps directions for the
  saved address (or a previously saved pin).

**Customer portal** (`customer/`)
- Dashboard with order counters, pending amount and recent orders.
- Schedule a pickup (per-piece *and* per-kg items, address, preferred slot, notes).
- **Service-first item entry**: pick a service, then tap one of its catalog items (pant, shirt,
  curtain…). For a kg service only that service's items can be added, and the customer's weight is
  clearly labelled as an estimate – the pickup boy measures the actual kg.
- Order list with live status, order detail page with the progress timeline and price breakdown,
  including **Estimated** vs **Verified** weight badges on per-kg lines.
- Profile update and password change.

**Admin / owner** (`admin/`)
- Dashboard: today's orders, revenue, pending deliveries, pending payments – *Recent Orders* as
  tap-to-expand tiles, with quick links into the queues below.
- **Pickup Queue** (`admin/pickup-queue.php`) and **Delivery Queue** (`admin/delivery-queue.php`):
  one tap from the sidebar. Waiting / unassigned / scheduled-today counters plus an expandable
  tile per order (Open & assign, Call), and an "In laundry" panel on the delivery side.
- Orders: list with filters + pagination, create, edit, detail, status changes, staff assignment
  (one *Reassign* shortcut per row), cancel with reason, activity log and audit trail.
- **Quick order creation**: only name, mobile number and address are required – the existing
  customer is looked up automatically (name / phone / e-mail) and their pending dues are shown,
  so no duplicate customer is created. Items are added later by the staff member on the spot.
- Customers: the list shows only the name, mobile number and invoice count – tap a row to see the
  full detail (e-mail, address, billed, due, last order) with *View orders* / *Collect dues*
  shortcuts. Staff accounts, service catalog and application settings.
- **Services & Items** (`admin/catalog.php`): the owner creates services (e.g. *Wash & Fold*,
  *Wash & Iron*, *Dry Clean*, *Wash & Fold (kg)*), chooses **per piece** or **per kg** billing, sets
  the service rate, and maps catalog items (Shirt, Trouser, Curtain…) to each service. The same item
  name may exist in several services at a different rate. Services and items can be edited,
  disabled and re-enabled; a disabled service hides its items from every picker.
- Payments: pending collection as expandable tiles with an in-tile cash / UPI / card collect form
  and the per-invoice **Customer Dues**; the history view is a compact row list with method,
  date and amount filters.
- **Dues** (`admin/dues.php`): every customer with an unpaid invoice, expandable per invoice,
  with a collect form and an *Open* shortcut – the owner's dedicated dues list.
- Order detail shows the **Old Invoice Dues** of that customer, and the owner can change the
  delivery boy at any time.
- Daily report with CSV download and a print / "save as PDF" view; the report body uses compact
  rows and tiles (no sideways scrolling tables), and the print view opens inside the normal app
  shell so the navigation stays fixed like on every other page.
- Database backup: create, download, restore, delete, retention pruning.

**Staff** (`staff/`) – one login for everything
- **Pickup**: a **shared pickup queue** – every staff member sees every open pickup order, whether
  it is assigned to someone else or not. Handling an order (adding items, collecting, marking
  *picked up* or *ready for delivery*) assigns it to whoever handled it, and the unassigned / mine
  counters show what is left to claim.
- **Deliver**: a **shared delivery queue** – every staff member sees every order that is ready for
  delivery or out for delivery, assigned or not. Starting the delivery or marking it *delivered*
  assigns it to that staff member, with the same unassigned / mine counters. The queue has
  **Open**, **Delivered** and **Payment Pending** tabs; a delivered order with an unpaid balance
  stays in *Payment Pending* and any staff member can collect the balance there.
- **All Orders** (`staff/orders.php`): the shared order book – every order, assigned or not, with
  search, status / payment filters and **All / Open / Delivered / Payment Pending** tabs. Each tile
  links to the POS, the map, or the collect form for a delivered pending balance.
- **Dues** (`staff/dues.php`): pending bills of the customers on the delivery list, one expandable
  tile per invoice with a collect form. A **Delivered · Payment Pending** section lists delivered
  orders that still owe money, and any staff member can collect those.
- **Payments**: the staff member's own collection history (today's total, recent transactions).
- **Add Items (POS)**: open any order that is still open (assigned or not) and add the garments
  that were handed over using the service-first picker (per-piece or per-kg). For kg services only
  the catalog items of that service can be added. The **Verify actual weight** panel lets the
  pickup boy replace the customer's estimate with the measured kg and the bill recalculates
  automatically. Items already on the order can only be added to, never deleted by staff.
- **My Details**: read-only identity card (the owner maintains it).

**Delivered + paid orders are closed**
- An order is *closed* only when it is **delivered** **and** **fully paid**. The bill is then final:
  no more items can be added or edited (staff pickup, delivery, All Orders and POS show it
  read-only; admin order-edit shows a read-only item table) and payments are blocked.
- A **delivered but unpaid** (or partially paid) order is *not* closed – its items are locked, but
  it stays visible in the delivered / payment-pending lists so any staff member can collect the
  balance. The last payment closes it.
- **Open** and **picked up** orders stay editable, so staff can keep adding garments at the
  customer's place and collect later.
- The owner can still change the customer, dates, staff, discount and delivery charge of a closed
  order. **Mark Unpaid** on the order page voids the payments and reopens the balance, but a
  delivered order keeps its items locked. Every close / reopen is written to the order activity
  log and the audit trail.

**Mobile / PWA**
- Installable web app (`manifest.webmanifest` + `sw.js`): "Add to home screen" on Android and
  "Install app" on desktop Chrome, with app icons and a service worker that caches the shell.
- An **Install App** button is shown in the public site header and in every signed-in layout
  (customer, staff and admin). It uses the native install prompt where the browser offers one and
  otherwise shows short instructions (browser menu on Android/desktop, Share → *Add to Home
  Screen* on iPhone/iPad).
- Mobile-first layout with a bottom navigation bar for staff and customers, 44 px touch targets
  and safe-area padding; denser panels, headings and gaps on phones.
- **Compact order tiles everywhere** (dashboard, orders, pickup / delivery queues, pickup,
  delivery, POS, dues, payments, customers, report, my orders): the summary line only shows the
  essentials – tap to expand the full detail (address, dates, staff, items, money, actions).
- **Item picker**: service-first bottom sheet with quick picks filtered to the chosen service,
  steppers for pieces / kilos and a sticky subtotal bar instead of a long item table. Kg services
  only accept their own catalog items and show the "weight will be measured / verified by the
  pickup boy" hint.
- **Mobile number is the primary identity**: sign in with e-mail *or* mobile (with or without the
  `0` / `+91` / `91` prefix), customer and staff search by mobile, and one customer account per
  mobile number – a repeat booking on the same number never creates a duplicate customer.
- A signed-in repeat customer sees their **last pending invoice** while booking a new pickup, and
  the owner's order-create lookup shows the same pending invoice while typing the number.

**Cross-cutting**
- Session authentication, role guards, CSRF tokens on every POST, password hashing,
  flash messages, status history and audit logging.
- Global exception / fatal-error handling with a friendly error page and reference code, plus
  form-level duplicate handling and safe transactions for customer + order creation.

## 5. Project layout

```
LaundryFriendsDemo/
├── index.php               public landing page
├── login.php register.php logout.php
├── bootstrap.php           single entry point for every page
├── manifest.webmanifest    PWA manifest
├── sw.js                   service worker (offline shell)
├── account/                profile, change password
├── admin/                  back office (orders, dues, staff, payments, reports, backup…)
│   └── reports/            daily report + print view
├── customer/               customer portal
├── staff/                  pickup, delivery, all orders, dues, payments, POS (add items)
├── config/                 config.php, db.php, auth.php, helpers.php, errors.php
├── database/               schema.sql, migrate.php, seed.php, backup.php, restore.php, backups/
├── includes/               shared layout, order logic, order items, order tiles, reports,
│                           backup engine, accounts
├── assets/                 css/app.css, js/app.js, icons/
└── storage/logs/           app.log (created automatically at runtime)
```

## 6. Backups

* From the admin UI: **Admin → Backups → Create backup now** (files land in `database/backups/`).
* From the CLI:

  ```bash
  php database/backup.php          # create a dump
  php database/backup.php --list   # list existing dumps
  php database/restore.php laundry_backup_20260101_120000.sql
  ```

* Schedule it with cron (Windows Task Scheduler works too):

  ```cron
  0 2 * * * php /path/to/database/backup.php
  ```

  Backups older than `backup_retention_days` (settings page) are pruned automatically.

## 7. Troubleshooting

| Symptom | Fix |
|---------|-----|
| "Database connection failed" | Check MySQL is running and the credentials in `config/config.php` / `config/local.php`. |
| Pages redirect to the home page | Your session expired – sign in again. |
| Styles look broken | The app must be served over `http://` from the web root; `BASE_URL` is derived from `DOCUMENT_ROOT`. |
| "Please sign in to continue" on every page | The `LAUNDRYFRDS` cookie path is based on `BASE_URL`; clear cookies if you moved the project. |
| Want a clean slate | `php database/migrate.php --fresh && php database/seed.php` |
