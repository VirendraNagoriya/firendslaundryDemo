<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/includes/layout.php';
require_once dirname(__DIR__) . '/includes/orders.php';
require_once dirname(__DIR__) . '/includes/order-items.php';

$user = require_admin();
$catalog = active_catalog();
$backUrl = 'order-create.php';

layout_start('Add Item', 'app');
?>
<div class="app-content" style="padding:0;">
    <div style="padding:.75rem 1rem; border-bottom:1px solid var(--line); background:#fff; display:flex; justify-content:space-between; align-items:center;">
        <strong>Add Item</strong>
        <a class="btn btn-sm btn-ghost done-back" href="<?= e(url($backUrl)) ?>">Done &amp; Back</a>
    </div>
    <div style="padding:1rem;">
        <?php render_item_picker([], $catalog, ['draft_key' => 'admin-order', 'intro' => 'Tap an article to add it. Items are saved automatically.', 'back_url' => 'order-create.php']); ?>
    </div>
</div>
<script>
window.addEventListener('load', function () {
    var btn = document.querySelector('[data-add-item]');
    if (btn) { btn.click(); }
});
</script>
<?php
layout_end();
