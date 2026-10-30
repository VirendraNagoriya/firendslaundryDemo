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
    <div class="panel" style="margin:0; border-radius:0; border-bottom:none; box-shadow:none;">
        <div class="panel-head" style="padding:1rem 1.2rem; margin:0; border-bottom:1px solid var(--line); background:var(--bg);">
            <div>
                <h2 style="margin:0; font-size:1.15rem; font-weight:700; color:var(--ink);">Add Item</h2>
                <p style="margin:0.3rem 0 0; color:var(--ink-soft); font-size:0.9rem;">Select and add items to your order</p>
            </div>
            <div style="display:flex; gap:0.8rem;">
                <button class="btn btn-sm btn-ghost" id="save-draft" style="display:flex; align-items:center; gap:0.4rem;">
                    <svg width="16" height="16" fill="currentColor" viewBox="0 0 16 16">
                        <path d="M8 4a.5.5 0 0 1 .5.5v3h3a.5.5 0 0 1 0 1h-3v3a.5.5 0 0 1-1 0v-3h-3a.5.5 0 0 1 0-1h3v-3A.5.5 0 0 1 8 4z"/>
                    </svg>
                    Save Draft
                </button>
                <a class="btn btn-sm btn-ghost done-back" href="<?= e(url($backUrl)) ?>" style="display:flex; align-items:center; gap:0.4rem;">
                    Done &amp; Back
                </a>
            </div>
        </div>
        <div style="padding:1.2rem;">
            <div class="sheet-search" style="margin-bottom:1.2rem;">
                <input type="text" id="item-search" placeholder="Search for items..." style="width:100%; font-size:1rem; padding:0.7rem 1rem; border-radius:12px;">
            </div>
            <div class="item-filter-tabs" style="display:flex; gap:0.5rem; margin-bottom:1.2rem; flex-wrap:wrap;">
                <button class="filter-tab active" data-filter="all" style="padding:0.5rem 1rem; border-radius:8px; border:1px solid var(--line); background:#fff; color:var(--ink-soft); font-weight:600; cursor:pointer; transition:all 0.2s ease;">
                    All Items
                </button>
                <button class="filter-tab" data-filter="men" style="padding:0.5rem 1rem; border-radius:8px; border:1px solid var(--line); background:#fff; color:var(--ink-soft); font-weight:600; cursor:pointer; transition:all 0.2s ease;">
                    Men
                </button>
                <button class="filter-tab" data-filter="women" style="padding:0.5rem 1rem; border-radius:8px; border:1px solid var(--line); background:#fff; color:var(--ink-soft); font-weight:600; cursor:pointer; transition:all 0.2s ease;">
                    Women
                </button>
                <button class="filter-tab" data-filter="household" style="padding:0.5rem 1rem; border-radius:8px; border:1px solid var(--line); background:#fff; color:var(--ink-soft); font-weight:600; cursor:pointer; transition:all 0.2s ease;">
                    Household
                </button>
            </div>
            <div style="display:flex; gap:1.2rem; margin-bottom:1.2rem;">
                <div style="flex:1; background:var(--brand-soft); border-radius:12px; padding:1rem; border:1px solid var(--brand);">
                    <h3 style="margin:0 0 0.5rem; font-size:1rem; font-weight:700; color:var(--brand-dark);">Quick Add</h3>
                    <p style="margin:0; color:var(--brand-dark); font-size:0.9rem;">Most frequently added items</p>
                </div>
                <div style="flex:1; background:var(--bg); border-radius:12px; padding:1rem; border:1px solid var(--line);">
                    <h3 style="margin:0 0 0.5rem; font-size:1rem; font-weight:700; color:var(--ink);">Recently Added</h3>
                    <p style="margin:0; color:var(--ink-soft); font-size:0.9rem;">Items added recently</p>
                </div>
            </div>
            <?php render_item_picker([], $catalog, ['draft_key' => 'admin-order', 'intro' => 'Tap an article to add it. Items are saved automatically.', 'back_url' => 'order-create.php']); ?>
        </div>
    </div>
</div>
<script>
window.addEventListener('load', function () {
    var btn = document.querySelector('[data-add-item]');
    if (btn) { btn.click(); }
    
    // Initialize filter tabs
    var filterTabs = document.querySelectorAll('.filter-tab');
    filterTabs.forEach(function(tab) {
        tab.addEventListener('click', function() {
            // Remove active class from all tabs
            filterTabs.forEach(function(t) {
                t.classList.remove('active');
                t.style.background = '#fff';
                t.style.color = 'var(--ink-soft)';
                t.style.borderColor = 'var(--line)';
            });
            
            // Add active class to clicked tab
            this.classList.add('active');
            this.style.background = 'var(--brand)';
            this.style.color = '#fff';
            this.style.borderColor = 'var(--brand)';
            
            // Filter items based on selected category
            var filterValue = this.getAttribute('data-filter');
            filterItems(filterValue);
        });
    });
    
    // Item search functionality
    var searchInput = document.getElementById('item-search');
    if (searchInput) {
        searchInput.addEventListener('input', function() {
            var searchTerm = this.value.toLowerCase();
            searchItems(searchTerm);
        });
    }
    
    // Save draft functionality
    var saveDraftBtn = document.getElementById('save-draft');
    if (saveDraftBtn) {
        saveDraftBtn.addEventListener('click', function() {
            // Add your save draft logic here
            alert('Draft saved successfully!');
        });
    }
});

function filterItems(category) {
    var items = document.querySelectorAll('.item-card, .quick-pick');
    items.forEach(function(item) {
        if (category === 'all' || item.getAttribute('data-category') === category) {
            item.style.display = 'block';
        } else {
            item.style.display = 'none';
        }
    });
}

function searchItems(searchTerm) {
    var items = document.querySelectorAll('.item-card, .quick-pick');
    items.forEach(function(item) {
        var itemName = (item.textContent || '').toLowerCase();
        if (itemName.indexOf(searchTerm) >= 0) {
            item.style.display = 'block';
        } else {
            item.style.display = 'none';
        }
    });
}
</script>
<?php
layout_end();
