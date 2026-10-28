<?php
declare(strict_types=1);

function layout_nav_items(): array
{
    $user = current_user();
    if ($user === null) {
        return [];
    }
    switch ($user['role']) {
        case 'super_admin':
        case 'admin':
            return [
                ['label' => 'Dashboard', 'href' => 'admin/index.php', 'icon' => 'M4 13h6V4H4v9zm0 7h6v-5H4v5zm8 0h8v-9h-8v9zm0-16v5h8V4h-8z'],
                ['label' => 'Pickup Queue', 'href' => 'admin/pickup-queue.php', 'icon' => 'M4 6h16v12H4V6zm2 2v8h12V8H6zm6 6a2 2 0 110-4 2 2 0 010 4z'],
                ['label' => 'Delivery Queue', 'href' => 'admin/delivery-queue.php', 'icon' => 'M3 7h11v8H3V7zm12 3h3l3 3v2h-6v-5zm-9 5a2 2 0 100-4 2 2 0 000 4zm11 0a2 2 0 100-4 2 2 0 000 4z'],
                ['label' => 'Orders', 'href' => 'admin/orders.php', 'icon' => 'M7 3h10a2 2 0 012 2v14a2 2 0 01-2 2H7a2 2 0 01-2-2V5a2 2 0 012-2zm0 3v2h10V6H7zm0 4v2h10v-2H7zm0 4v2h7v-2H7z'],
                ['label' => 'Dues', 'href' => 'admin/dues.php', 'icon' => 'M12 2a10 10 0 100 20 10 10 0 000-20zm1 15h-2v-1.2c-.6-.2-1.1-.6-1.2-1.3h2c.1.3.3.5.7.5.5 0 .8-.3.8-.8 0-.5-.3-.7-.9-1-.8-.3-1.6-.7-1.6-1.7 0-.7.5-1.2 1.1-1.4V8h2v1.1c.7.2 1.1.7 1.2 1.4h-2c-.1-.3-.3-.5-.6-.5-.4 0-.7.3-.7.7 0 .4.3.6.9.8.8.3 1.7.7 1.7 1.8 0 .8-.5 1.3-1.2 1.5V17z'],
                ['label' => 'Payments', 'href' => 'admin/payments.php', 'icon' => 'M3 6h18v12H3V6zm2 2v8h14V8H5zm7 3a2 2 0 110 4 2 2 0 010-4z'],
                ['label' => 'Customers', 'href' => 'admin/customers.php', 'icon' => 'M12 12a4 4 0 100-8 4 4 0 000 8zm-8 8a8 8 0 1116 0H4z'],
                ['label' => 'Daily Report', 'href' => 'admin/reports/daily.php', 'icon' => 'M4 20h16v-2H4v2zM6 16h3V8H6v8zm5 0h3V4h-3v12zm5 0h3v-6h-3v6z'],
                ['label' => 'Service Catalog', 'href' => 'admin/catalog.php', 'icon' => 'M4 6h16v12H4V6zm2 2v8h12V8H6zm2 2h8v2H8v-2z'],
                ['label' => 'Staff Accounts', 'href' => 'admin/staff.php', 'icon' => 'M12 12a4 4 0 100-8 4 4 0 000 8zm-9 8a9 9 0 1118 0H3z'],
                ['label' => 'Backup', 'href' => 'admin/backup.php', 'icon' => 'M12 3l4 4h-3v6h-2V7H8l4-4zm-7 15h14v3H5v-3z'],
                ['label' => 'Settings', 'href' => 'admin/settings.php', 'icon' => 'M12 8a4 4 0 100 8 4 4 0 000-8zm9 4l-2 1.2.3 2.3-2.3.3L15.5 18l-1.9-1.4L12 17.6l-1.6-1L8.5 18l-1.5-2.2-2.3-.3.3-2.3L3 12l2-1.2-.3-2.3L7 8.2 8.5 6l1.9 1.4L12 6.4l1.6 1L15.5 6l1.5 2.2 2.3.3-.3 2.3L21 12z'],
            ];
        case 'staff':
        case 'pickup':
        case 'delivery':
            return [
                ['label' => 'Pickup', 'href' => 'staff/pickup.php', 'icon' => 'M4 6h16v12H4V6zm2 2v8h12V8H6zm6 6a2 2 0 110-4 2 2 0 010 4z'],
                ['label' => 'Deliver', 'href' => 'staff/delivery.php', 'icon' => 'M3 7h11v8H3V7zm12 3h3l3 3v2h-6v-5zm-9 5a2 2 0 100-4 2 2 0 000 4zm11 0a2 2 0 100-4 2 2 0 000 4z'],
                ['label' => 'All Orders', 'href' => 'staff/orders.php', 'icon' => 'M7 3h10a2 2 0 012 2v14a2 2 0 01-2 2H7a2 2 0 01-2-2V5a2 2 0 012-2zm0 3v2h10V6H7zm0 4v2h10v-2H7zm0 4v2h7v-2H7z'],
                ['label' => 'Dues', 'href' => 'staff/dues.php', 'icon' => 'M12 2a10 10 0 100 20 10 10 0 000-20zm1 15h-2v-1.2c-.6-.2-1.1-.6-1.2-1.3h2c.1.3.3.5.7.5.5 0 .8-.3.8-.8 0-.5-.3-.7-.9-1-.8-.3-1.6-.7-1.6-1.7 0-.7.5-1.2 1.1-1.4V8h2v1.1c.7.2 1.1.7 1.2 1.4h-2c-.1-.3-.3-.5-.6-.5-.4 0-.7.3-.7.7 0 .4.3.6.9.8.8.3 1.7.7 1.7 1.8 0 .8-.5 1.3-1.2 1.5V17z'],
                ['label' => 'Payments', 'href' => 'staff/payments.php', 'icon' => 'M3 6h18v12H3V6zm7 3a2 2 0 110 4 2 2 0 010-4z'],
                ['label' => 'Add Items', 'href' => 'staff/pos.php', 'icon' => 'M11 7h2v4h4v2h-4v4h-2v-4H7v-2h4V7zm-6 14a2 2 0 110-4 2 2 0 010 4zm12 0a2 2 0 110-4 2 2 0 010 4z'],
                ['label' => 'My Details', 'href' => 'account/profile.php', 'icon' => 'M12 12a4 4 0 100-8 4 4 0 000 8zm-8 8a8 8 0 1116 0H4z'],
            ];
        case 'customer':
            return [
                ['label' => 'Dashboard', 'href' => 'customer/index.php', 'icon' => 'M4 13h6V4H4v9zm0 7h6v-5H4v5zm8 0h8v-9h-8v9zm0-16v5h8V4h-8z'],
                ['label' => 'Schedule Pickup', 'href' => 'customer/schedule-pickup.php', 'icon' => 'M7 2v2H5a2 2 0 00-2 2v13a2 2 0 002 2h14a2 2 0 002-2V6a2 2 0 00-2-2h-2V2h-2v2H9V2H7zm-2 8h14v9H5v-9z'],
                ['label' => 'My Orders', 'href' => 'customer/orders.php', 'icon' => 'M7 3h10a2 2 0 012 2v14a2 2 0 01-2 2H7a2 2 0 01-2-2V5a2 2 0 012-2zm0 3v2h10V6H7zm0 4v2h10v-2H7zm0 4v2h7v-2H7z'],
                ['label' => 'My Profile', 'href' => 'account/profile.php', 'icon' => 'M12 12a4 4 0 100-8 4 4 0 000 8zm-8 8a8 8 0 1116 0H4z'],
            ];
    }
    return [];
}

function layout_is_active(string $href): bool
{
    $self = basename($_SERVER['SCRIPT_NAME'] ?? '');
    $target = basename($href);
    if ($self === $target) {
        return true;
    }
    $selfDir = basename(dirname($_SERVER['SCRIPT_NAME'] ?? ''));
    $targetDir = basename(dirname($href));
    return $self === $target && $selfDir === $targetDir;
}

function layout_start(string $title, string $layout = 'app'): void
{
    $GLOBALS['__layout'] = $layout;
    $user = current_user();
    $flashes = take_flashes();
    ?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title><?= e($title) ?> &middot; <?= e(APP_NAME) ?></title>
<meta name="theme-color" content="#2f6df6">
<meta name="description" content="Laundry Friends - pickup, laundry and delivery management.">
<link rel="manifest" href="<?= e(url('manifest.webmanifest')) ?>">
<link rel="icon" type="image/png" sizes="32x32" href="<?= e(asset('assets/icons/favicon-32.png')) ?>">
<link rel="apple-touch-icon" href="<?= e(asset('assets/icons/apple-touch-icon.png')) ?>">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="stylesheet" href="<?= e(asset('assets/css/app.css')) ?>">
</head>
<body class="layout-<?= e($layout) ?>">
<?php if ($layout === 'public'): ?>
<header class="public-header">
    <div class="container nav-row">
        <a class="brand" href="<?= e(url('index.php')) ?>">
            <span class="brand-mark">LF</span>
            <span><?= e(APP_NAME) ?></span>
        </a>
        <nav class="public-nav">
            <a href="<?= e(url('index.php')) ?>#services">Services</a>
            <a href="<?= e(url('index.php')) ?>#how">How it works</a>
            <a href="<?= e(url('index.php')) ?>#contact">Contact</a>
            <button class="btn btn-soft btn-sm install-btn" type="button" data-install-app>Install App</button>
            <?php if ($user !== null): ?>
                <a class="btn btn-primary btn-sm" href="<?= e(url(home_for_role($user['role']))) ?>">Go to Dashboard</a>
                <a class="btn btn-ghost btn-sm" href="<?= e(url('logout.php')) ?>">Logout</a>
            <?php else: ?>
                <a class="btn btn-ghost btn-sm" href="<?= e(url('login.php')) ?>">Login</a>
                <a class="btn btn-primary btn-sm" href="<?= e(url('customer/schedule-pickup.php')) ?>">Schedule Pickup</a>
            <?php endif; ?>
        </nav>
    </div>
</header>
<?php elseif ($layout === 'app'): ?>
<div class="app-shell">
    <aside class="sidebar" id="sidebar">
        <div class="sidebar-brand">
            <a class="brand" href="<?= e(url('index.php')) ?>">
                <span class="brand-mark">LF</span>
                <span><?= e(APP_NAME) ?></span>
            </a>
        </div>
        <nav class="sidebar-nav">
            <?php foreach (layout_nav_items() as $item): ?>
                <a class="nav-link<?= layout_is_active($item['href']) ? ' active' : '' ?>" href="<?= e(url($item['href'])) ?>">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="<?= e($item['icon']) ?>"/></svg>
                    <span><?= e($item['label']) ?></span>
                </a>
            <?php endforeach; ?>
        </nav>
        <div class="sidebar-footer">
            <div class="user-chip">
                <div class="avatar"><?= e(strtoupper(mb_substr($user['name'] ?? 'U', 0, 1))) ?></div>
                <div>
                    <strong><?= e($user['name'] ?? 'Guest') ?></strong>
                    <small><?= e(role_label($user['role'] ?? '')) ?></small>
                </div>
            </div>
            <a class="btn btn-ghost btn-sm btn-block" href="<?= e(url('logout.php')) ?>">Logout</a>
        </div>
    </aside>
    <div class="app-main">
        <header class="app-topbar">
            <button class="icon-btn menu-toggle" type="button" data-toggle-sidebar aria-label="Toggle menu">☰</button>
            <h1 class="page-title"><?= e($title) ?></h1>
            <div class="topbar-right">
                <button class="btn btn-sm btn-soft install-btn" type="button" data-install-app>Install App</button>
                <span class="today-label"><?= e(date('d M Y')) ?></span>
                <a class="btn btn-sm btn-ghost topbar-logout" href="<?= e(url('logout.php')) ?>">Logout</a>
            </div>
        </header>
        <main class="app-content">
<?php else: ?>
<header class="customer-header">
    <div class="container nav-row">
        <a class="brand" href="<?= e(url('customer/index.php')) ?>">
            <span class="brand-mark">LF</span>
            <span><?= e(APP_NAME) ?></span>
        </a>
        <nav class="public-nav">
            <?php foreach (layout_nav_items() as $item): ?>
                <a class="nav-link-sm<?= layout_is_active($item['href']) ? ' active' : '' ?>" href="<?= e(url($item['href'])) ?>"><?= e($item['label']) ?></a>
            <?php endforeach; ?>
            <button class="btn btn-soft btn-sm install-btn" type="button" data-install-app>Install App</button>
            <a class="btn btn-ghost btn-sm" href="<?= e(url('logout.php')) ?>">Logout</a>
        </nav>
    </div>
</header>
<main class="customer-main">
<?php endif; ?>
<?php foreach ($flashes as $flash): ?>
    <div class="alert alert-<?= e($flash['type']) ?>"><?= e($flash['message']) ?></div>
<?php endforeach; ?>
<?php
}

function layout_end(): void
{
    $layout = $GLOBALS['__layout'] ?? 'app';
    ?>
<?php if ($layout === 'app'): ?>
        </main>
    </div>
</div>
<?php layout_bottom_nav(); ?>
<?php elseif ($layout === 'customer'): ?>
</main>
<?php layout_bottom_nav(); ?>
<?php endif; ?>
<script src="<?= e(asset('assets/js/app.js')) ?>"></script>
<script src="<?= e(asset('assets/js/phone-validate.js')) ?>"></script>
<script>
if ('serviceWorker' in navigator) {
    window.addEventListener('load', function () {
        navigator.serviceWorker.register('<?= e(url('sw.js')) ?>').catch(function () {});
    });
}
</script>
</body>
</html>
<?php
}

function layout_bottom_nav(): void
{
    $user = current_user();
    if ($user === null || !in_array($user['role'], array_merge(staff_roles(), ['customer']), true)) {
        return;
    }
    $items = array_slice(layout_nav_items(), 0, 5);
    if ($items === []) {
        return;
    }
    ?>
    <nav class="bottom-nav" aria-label="Main navigation">
        <?php foreach ($items as $item): ?>
            <a class="<?= layout_is_active($item['href']) ? 'active' : '' ?>" href="<?= e(url($item['href'])) ?>">
                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="<?= e($item['icon']) ?>"/></svg>
                <span><?= e($item['label']) ?></span>
            </a>
        <?php endforeach; ?>
    </nav>
    <?php
}
