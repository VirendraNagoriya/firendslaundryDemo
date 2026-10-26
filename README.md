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
   unified `staff` role, converts the former `pickup` / `delivery` accounts, and adds the
   `latitude` / `longitude` / `place_id` columns used by the map picker.

5. Open `http://localhost/LaundryFriendsDemo/` in the browser.

### Google Maps location picker (optional but recommended)

1. In [Google Cloud Console](https://console.cloud.google.com/) create (or select) a project and
   enable **Maps JavaScript API** and **Places API**.
2. Create an API key. Under *Application restrictions* choose **HTTP referrers** and add your
   site, e.g. `https://your-domain.com/*` (and `http://localhost/LaundryFriendsDemo/*` for local
   testing). Under *API restrictions* allow only the two APIs above.
3. Sign in as the owner → **Admin → Settings → Google Maps JavaScript API key**, paste the key and
   save.

Customers, staff and the owner can then search an address, tap the map, drag the pin or press
**Use my current location**; the address, city, PIN code, latitude, longitude and Google place id
are saved. Browser geolocation needs **HTTPS** in production. Without a key the form still works:
manual address entry and the *current location* pin are saved, and a short notice explains that
the map is not configured.

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
- **Google Maps address picker** on registration, pickup booking, profile and admin order
  create/edit: search an address, tap/drag a pin or use the phone's current location. The pin is
  stored on the customer and on the order, and staff get a one-tap **Navigate** link.

**Customer portal** (`customer/`)
- Dashboard with order counters, pending amount and recent orders.
- Schedule a pickup (per-piece *and* per-kg items, address, preferred slot, notes).
- Order list with live status, order detail page with the progress timeline and price breakdown.
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
  assigns it to that staff member, with the same unassigned / mine counters.
- **Dues** (`staff/dues.php`): pending bills of the customers on the delivery list, one expandable
  tile per invoice with a collect form – only invoices linked to the staff member's own customers.
- **Payments**: the staff member's own collection history (today's total, recent transactions).
- **Add Items (POS)**: open any order that is still open (assigned or not), add the garments that
  were handed over (catalog chips, per-piece or per-kg). Items already on the order can only be
  added to, never deleted by staff – the order total is recalculated automatically.
- **My Details**: read-only identity card (the owner maintains it).

**Paid orders are closed**
- As soon as an order is **fully paid** it is *closed*: the bill is final and no more items can be
  added or edited (staff pickup, delivery and POS show it read-only; admin order-edit shows a
  read-only item table). Payments are blocked too, because there is nothing left to collect.
- **Pending and partially paid** orders stay open, so staff can keep adding garments at the
  customer's place and collect the balance later. Collecting the last rupee closes the order.
- The owner can still change the customer, dates, staff, discount and delivery charge of a closed
  order. **Mark Unpaid** on the order page voids the payments, reopens the order and unlocks the
  items again. Every close / reopen is written to the order activity log and the audit trail.

**Mobile / PWA**
- Installable web app (`manifest.webmanifest` + `sw.js`): "Add to home screen" on Android and
  "Install app" on desktop Chrome, with app icons and a service worker that caches the shell.
- Mobile-first layout with a bottom navigation bar for staff and customers, 44 px touch targets
  and safe-area padding; denser panels, headings and gaps on phones.
- **Compact order tiles everywhere** (dashboard, orders, pickup / delivery queues, pickup,
  delivery, POS, dues, payments, customers, report, my orders): the summary line only shows the
  essentials – tap to expand the full detail (address, dates, staff, items, money, actions).
- **Item picker**: bottom sheet with quick picks, steppers for pieces / kilos and a sticky
  subtotal bar instead of a long item table.
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
├── staff/                  pickup, delivery, dues, payments, POS (add items)
├── config/                 config.php, db.php, auth.php, helpers.php, errors.php
├── database/               schema.sql, migrate.php, seed.php, backup.php, restore.php, backups/
├── includes/               shared layout, order logic, order items, order tiles, reports,
│                           backup engine, accounts, Google Maps location picker
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
