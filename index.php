<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/includes/layout.php';

$user = current_user();

$services = [
    ['icon' => '👕', 'title' => 'Wash & Fold', 'text' => 'Everyday clothes washed, dried and neatly folded, ready for your wardrobe.'],
    ['icon' => '👔', 'title' => 'Wash & Iron', 'text' => 'Steam pressed finish for shirts, trousers and dresses that stay crisp.'],
    ['icon' => '🧥', 'title' => 'Dry Cleaning', 'text' => 'Careful dry cleaning for coats, suits, gowns and premium fabrics.'],
    ['icon' => '🛞', 'title' => 'Shoe Cleaning', 'text' => 'Sneakers, sports shoes and leather pairs cleaned and refurbished.'],
    ['icon' => '🛏️', 'title' => 'Home Linen', 'text' => 'Bed covers, curtains, carpets and pillow covers cleaned by weight or piece.'],
    ['icon' => '⚖️', 'title' => 'Kg-Wise Wash', 'text' => 'Bring your mixed laundry and we bill it per kilogram for complete wash and fold.'],
];

$steps = [
    'Schedule your pickup online with your items, address and preferred date.',
    'Our pickup staff collect the laundry and mark the order as picked up.',
    'Washing, drying, ironing and folding happen at our facility.',
    'Delivery staff bring it back to your doorstep and collect the payment.',
];

layout_start('Wash & Fold Laundry Service', 'public');
?>
<section class="hero">
    <div class="container hero-grid">
        <div>
            <h1>Laundry pickup &amp; delivery, handled end to end.</h1>
            <p class="lead"><?= e(APP_NAME) ?> handles your washing, dry cleaning, ironing and folding. Book a pickup in under a minute and track every step until the laundry is back at your door.</p>
            <div class="hero-actions">
                <a class="btn btn-lg btn-ghost" href="<?= e(url('customer/schedule-pickup.php')) ?>">Schedule Pickup</a>
                <a class="btn btn-lg btn-green" href="<?= e(url('login.php')) ?>">Login</a>
            </div>
            <div class="hero-stats">
                <div><strong>6+</strong><span>Service types</span></div>
                <div><strong>Per Kg</strong><span>or per piece billing</span></div>
                <div><strong>Cash / UPI / Card</strong><span>payment on delivery</span></div>
            </div>
        </div>
        <div class="hero-card">
            <h3>One login for everyone</h3>
            <ul>
                <li><span>Customers</span><strong>Book &amp; track orders</strong></li>
                <li><span>Admin / Owner</span><strong>Orders, staff &amp; reports</strong></li>
                <li><span>Pickup Staff</span><strong>Pickup task list</strong></li>
                <li><span>Delivery Staff</span><strong>Delivery &amp; payments</strong></li>
            </ul>
            <a class="btn btn-block btn-green mt-1" href="<?= e(url('login.php')) ?>">Sign in to your account</a>
        </div>
    </div>
</section>

<section class="section" id="services">
    <div class="container">
        <div class="section-head">
            <h2>Services we offer</h2>
            <p>Charge per piece or per kilogram. Add items at booking time and our team takes care of the rest.</p>
        </div>
        <div class="grid-3">
            <?php foreach ($services as $service): ?>
                <div class="card">
                    <div class="feature-icon"><?= e($service['icon']) ?></div>
                    <h3><?= e($service['title']) ?></h3>
                    <p><?= e($service['text']) ?></p>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</section>

<section class="section alt" id="how">
    <div class="container">
        <div class="section-head">
            <h2>How it works</h2>
            <p>Four simple steps from your door back to your door.</p>
        </div>
        <div class="steps">
            <?php foreach ($steps as $step): ?>
                <div class="step">
                    <p class="mb-0"><?= e($step) ?></p>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</section>

<section class="section" id="contact">
    <div class="container">
        <div class="card" style="max-width: 640px; margin-inline: auto; text-align:center;">
            <h2>Ready to get started?</h2>
            <p class="subtle">Create a customer account, schedule a pickup and pay only when your laundry is delivered.</p>
            <div class="hero-actions" style="justify-content:center;">
                <a class="btn btn-lg" href="<?= e(url('customer/schedule-pickup.php')) ?>">Schedule Pickup</a>
                <a class="btn btn-lg btn-ghost" href="<?= e(url('login.php')) ?>">Staff &amp; Admin Login</a>
            </div>
            <p class="subtle mt-1 mb-0"><?= e(APP_CONTACT_PHONE) ?> &nbsp;|&nbsp; <?= e(APP_CONTACT_EMAIL) ?></p>
        </div>
    </div>
</section>

<footer class="footer">
    <div class="container footer-grid">
        <div>
            <strong><?= e(APP_NAME) ?></strong>
            <div><?= e(APP_TAGLINE) ?></div>
        </div>
        <div>
            <a href="<?= e(url('customer/schedule-pickup.php')) ?>">Schedule Pickup</a> &nbsp;·&nbsp;
            <a href="<?= e(url('login.php')) ?>">Login</a> &nbsp;·&nbsp;
            <a href="<?= e(url('register.php')) ?>">Customer Registration</a>
        </div>
        <div>&copy; <?= e(date('Y')) ?> <?= e(APP_NAME) ?></div>
    </div>
</footer>
<?php layout_end(); ?>
