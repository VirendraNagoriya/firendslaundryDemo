<?php
declare(strict_types=1);

require_once __DIR__ . '/extra-charges.php';

/**
 * Active catalog rows joined with their service. An item always belongs to a
 * service, and the service decides whether it is billed per piece or per kg.
 */
function active_catalog(): array
{
    ensure_service_category_schema();
    ensure_subcategory_schema();
    return db_all(
        'SELECT i.*, s.service_name, s.price_mode AS service_price_mode, s.rate AS service_rate,
                s.category AS service_category, sc.name AS subcategory_name
         FROM laundry_items i
         JOIN services s ON s.id = i.service_id
         LEFT JOIN subcategories sc ON sc.id = i.subcategory_id
         WHERE i.is_active = 1 AND s.is_active = 1
         ORDER BY FIELD(s.category, \'piece\', \'kg\', \'extra\'),
                  sc.sort_order, sc.name, s.service_name, i.item_name'
    );
}

/**
 * Resolve the service for a saved line so the editor can preselect it even for
 * legacy rows that only carry a service_type key.
 */
function item_service_id(array $item, array $services): int
{
    $serviceId = (int) ($item['service_id'] ?? 0);
    if ($serviceId > 0) {
        foreach ($services as $service) {
            if ((int) $service['id'] === $serviceId) {
                return $serviceId;
            }
        }
    }

    $type = (string) ($item['service_type'] ?? '');
    $name = trim((string) ($item['item_name'] ?? ''));
    if ($type !== '') {
        foreach ($services as $service) {
            if (service_type_for_name((string) $service['service_name']) === $type) {
                return (int) $service['id'];
            }
        }
    }
    if ($name !== '') {
        $row = db_row(
            'SELECT service_id FROM laundry_items WHERE item_name = ? ORDER BY id LIMIT 1',
            [$name]
        );
        if ($row !== null) {
            return (int) $row['service_id'];
        }
    }
    return $services !== [] ? (int) $services[0]['id'] : 0;
}

/**
 * Who may change a line rate while an order is being built?
 *
 * - Customer / guest booking: never. The catalog rate is final.
 * - Pickup / delivery staff: yes, but a reason is mandatory so the change is
 *   visible on the invoice and in the audit trail.
 * - Owner / admin / super admin: yes, reason optional (a default is stored).
 */
function rate_override_permissions(?array $user = null): array
{
    $user = $user ?? current_user();
    $role = (string) ($user['role'] ?? '');

    if ($role === '') {
        return ['editable' => false, 'requires_reason' => false, 'role' => '', 'label' => 'Catalog price'];
    }
    if (in_array($role, ['super_admin', 'admin'], true)) {
        return ['editable' => true, 'requires_reason' => false, 'role' => $role, 'label' => 'Owner rate'];
    }
    if (in_array($role, staff_roles(), true)) {
        return ['editable' => true, 'requires_reason' => true, 'role' => $role, 'label' => 'Staff rate'];
    }
    return ['editable' => false, 'requires_reason' => false, 'role' => $role, 'label' => 'Catalog price'];
}

/**
 * Catalog lookup keyed by service + lowercase item name. Built once per request
 * so a cart does not run one query per line.
 */
function catalog_rate_index(): array
{
    static $index = null;
    if ($index !== null) {
        return $index;
    }

    $index = [];
    foreach (active_catalog() as $row) {
        $mode = (string) ($row['service_price_mode'] ?? $row['price_mode'] ?? 'per_piece') === 'per_kg' ? 'per_kg' : 'per_piece';
        $key = (int) $row['service_id'] . '|' . mb_strtolower(trim((string) $row['item_name']));
        $index[$key] = [
            'id' => (int) $row['id'],
            'name' => (string) $row['item_name'],
            'service_id' => (int) $row['service_id'],
            'service_name' => (string) ($row['service_name'] ?? ''),
            'subcategory_id' => (int) ($row['subcategory_id'] ?? 0),
            'subcategory' => (string) ($row['subcategory_name'] ?? ''),
            'category' => service_category_for($row),
            'mode' => $mode,
            'rate' => service_effective_rate(
                ['price_mode' => $mode, 'rate' => (float) ($row['service_rate'] ?? 0)],
                $row
            ),
            'min_weight_kg' => (float) ($row['min_weight_kg'] ?? 0),
        ];
    }
    return $index;
}

function catalog_entry_for(int $serviceId, string $itemName): ?array
{
    $key = $serviceId . '|' . mb_strtolower(trim($itemName));
    $index = catalog_rate_index();
    return $index[$key] ?? null;
}

/**
 * One item card (mobile first): service, item name, qty / weight, rate and amount.
 *
 * The service owns the billing mode. Per-kg services only accept catalog items,
 * so the name field becomes a select-like list of that service's items.
 */
function item_card_html(array $item, string $catalogListId = '', int $index = 0, array $services = []): string
{
    if ($services === []) {
        $services = active_services();
    }

    $serviceId = item_service_id($item, $services);
    $selectedService = null;
    foreach ($services as $service) {
        if ((int) $service['id'] === $serviceId) {
            $selectedService = $service;
            break;
        }
    }

    $mode = (string) ($item['price_mode'] ?? ($selectedService['price_mode'] ?? 'per_piece'));
    $mode = in_array($mode, ['per_kg', 'fixed'], true) ? $mode : 'per_piece';
    $category = $selectedService !== null
        ? service_category_for($selectedService)
        : ($mode === 'per_kg' ? 'kg' : 'piece');
    $name = (string) ($item['item_name'] ?? '');
    $qty = max(1, (int) ($item['qty'] ?? 1));
    $weight = (float) ($item['weight_kg'] ?? 0);
    $rate = (float) ($item['rate'] ?? 0);
    if ($rate <= 0 && $selectedService !== null) {
        $rate = (float) $selectedService['rate'];
    }

    $catalogEntry = $name !== '' ? catalog_entry_for($serviceId, $name) : null;
    $catalogRate = $catalogEntry !== null
        ? (float) $catalogEntry['rate']
        : ($selectedService !== null ? (float) $selectedService['rate'] : $rate);
    $rateChanged = $catalogRate > 0 && abs($rate - $catalogRate) > 0.004;
    $minWeight = $catalogEntry !== null
        ? (float) $catalogEntry['min_weight_kg']
        : ($selectedService !== null ? (float) $selectedService['min_weight_kg'] : 0.0);
    $ratePerms = rate_override_permissions();

    $subcategoryId = (int) ($item['subcategory_id'] ?? ($catalogEntry['subcategory_id'] ?? 0));
    $subcategoryName = (string) ($item['subcategory_name'] ?? ($catalogEntry['subcategory'] ?? ''));
    $weightBilled = $mode === 'per_kg';

    $serviceOptions = '';
    foreach ($services as $service) {
        $serviceMode = in_array((string) $service['price_mode'], ['per_kg', 'fixed'], true)
            ? (string) $service['price_mode']
            : 'per_piece';
        $serviceOptions .= '<option value="' . (int) $service['id'] . '"'
            . ' data-mode="' . e($serviceMode) . '"'
            . ' data-category="' . e(service_category_for($service)) . '"'
            . ' data-type="' . e(service_type_for_name((string) $service['service_name'])) . '"'
            . ' data-rate="' . e((string) (float) $service['rate']) . '"'
            . ((int) $service['id'] === $serviceId ? ' selected' : '') . '>'
            . e((string) $service['service_name'])
            . ' (' . ($serviceMode === 'per_kg' ? 'per kg' : ($serviceMode === 'fixed' ? 'fixed' : 'per piece')) . ')'
            . '</option>';
    }

    ob_start();
    ?>
    <div class="item-card" data-item-row data-service-mode="<?= e($mode) ?>" data-service-category="<?= e($category) ?>"
         data-catalog-rate="<?= e((string) $catalogRate) ?>" data-min-weight="<?= e((string) $minWeight) ?>">
        <div class="item-card-top">
            <div class="item-card-title">
                <input type="text" class="item-name-input" name="item_name[]" value="<?= e($name) ?>"
                       placeholder="Item name"<?= $catalogListId !== '' ? ' list="' . e($catalogListId) . '"' : '' ?>
                       autocomplete="off" autocapitalize="words">
                <span class="item-card-sub" data-item-summary></span>
            </div>
            <button type="button" class="item-remove" data-remove-item aria-label="Remove item">&times;</button>
        </div>
        <p class="item-warning" data-item-warning hidden></p>
        <div class="item-card-grid">
            <label class="item-field">
                <span class="item-field-label">Service</span>
                <select name="item_service_id[]" data-service-select><?= $serviceOptions ?></select>
            </label>
            <div class="item-field item-field-subcategory">
                <span class="item-field-label">Subcategory</span>
                <strong class="rate-readonly" data-subcategory-label><?= e($subcategoryName !== '' ? $subcategoryName : '—') ?></strong>
                <input type="hidden" name="item_subcategory_id[]" value="<?= e((string) $subcategoryId) ?>" data-subcategory-input>
            </div>
            <div class="item-field" data-field-qty>
                <span class="item-field-label" data-qty-label><?= $weightBilled ? 'Pieces (tracking)' : 'How many' ?></span>
                <div class="stepper">
                    <button type="button" data-step="-1" data-target="qty" aria-label="One less">&minus;</button>
                    <input type="number" name="item_qty[]" min="1" step="1" value="<?= e((string) $qty) ?>" inputmode="numeric">
                    <button type="button" data-step="1" data-target="qty" aria-label="One more">+</button>
                </div>
                <?php if ($weightBilled): ?>
                    <span class="item-field-hint">Counted for inventory only. Billing uses the scale weight.</span>
                <?php endif; ?>
            </div>
            <div class="item-field" data-field-weight<?= $weightBilled ? '' : ' hidden' ?>>
                <span class="item-field-label">Weight (kg)</span>
                <div class="stepper">
                    <button type="button" data-step="-0.5" data-target="weight" aria-label="Half kilo less">&minus;</button>
                    <input type="number" name="item_weight[]" min="0" step="0.5" value="<?= e((string) $weight) ?>" inputmode="decimal">
                    <button type="button" data-step="0.5" data-target="weight" aria-label="Half kilo more">+</button>
                </div>
                <?php if ($weightBilled): ?>
                    <span class="item-field-hint">
                        Weight will be measured / verified by the pickup boy.<?php if ($minWeight > 0): ?> Minimum <?= e(rtrim(rtrim(number_format($minWeight, 3, '.', ''), '0'), '.')) ?> kg.<?php endif; ?>
                        Total cost: <?= e(APP_CURRENCY) ?><span class="weight-cost">0.00</span>
                    </span>
                <?php endif; ?>
            </div>
            <label class="item-field" data-rate-field>
                <span class="item-field-label">Rate (<?= e(APP_CURRENCY) ?>)<?= $mode === 'fixed' ? ' &middot; fixed' : '' ?></span>
                <?php if ($ratePerms['editable']): ?>
                    <input type="number" name="item_rate[]" min="0" max="100000" step="0.5" value="<?= e((string) $rate) ?>"
                           inputmode="decimal" data-rate-input data-catalog-rate="<?= e((string) $catalogRate) ?>">
                    <span class="item-field-hint rate-original" data-rate-original<?= $rateChanged ? '' : ' hidden' ?>>
                        Catalog <?= e(money($catalogRate)) ?>
                    </span>
                <?php else: ?>
                    <input type="hidden" name="item_rate[]" value="<?= e((string) $rate) ?>" data-rate-input data-catalog-rate="<?= e((string) $catalogRate) ?>">
                    <strong class="rate-readonly"><?= e(money($rate)) ?></strong>
                    <span class="item-field-hint"><?= $mode === 'per_kg' ? 'Per kg' : ($mode === 'fixed' ? 'Fixed rate' : 'Per piece') ?> &middot; fixed price</span>
                <?php endif; ?>
            </label>
            <?php if ($ratePerms['editable']): ?>
                <label class="item-field rate-reason-field" data-rate-reason-field<?= ($rateChanged || (string) ($item['rate_change_reason'] ?? '') !== '') ? '' : ' hidden' ?>>
                    <span class="item-field-label">Rate change reason<?= $ratePerms['requires_reason'] ? ' (required)' : '' ?></span>
                    <input type="text" name="item_rate_reason[]" maxlength="255" value="<?= e((string) ($item['rate_change_reason'] ?? '')) ?>"
                           data-rate-reason placeholder="e.g. stain treatment, loyal customer">
                </label>
            <?php else: ?>
                <input type="hidden" name="item_rate_reason[]" value="">
            <?php endif; ?>
            <div class="item-field item-field-amount">
                <span class="item-field-label">Amount</span>
                <strong data-amount"><?= e(number_format(0, 2)) ?></strong>
            </div>
            <?php if (can_add_extra_charges(current_user())): ?>
                <div class="item-field">
                    <button type="button" class="btn btn-ghost btn-sm btn-add-extra" data-item-index="<?= $index ?>">
                        Add Extra Charge
                    </button>
                </div>
            <?php endif; ?>
        </div>
        <input type="hidden" name="item_mode[]" value="<?= e($mode) ?>" data-mode-value>
        <input type="hidden" name="item_service_type[]" value="<?= e(service_type_for_name((string) ($selectedService['service_name'] ?? ''))) ?>" data-service-type>
        <input type="hidden" name="item_notes[]" value="" data-notes-input>
    </div>
    <?php
    return (string) ob_get_clean();
}

/**
 * Mobile first item entry: cards + service-filtered quick picks + running total.
 * Every card posts a real service_id, so a kg service can only bill its own items.
 */
function render_item_picker(array $items, array $catalog, array $options = []): void
{
    static $instance = 0;
    $instance++;
    $catalogListId = 'catalogList' . $instance;
    $services = active_services();
    $subcategories = active_subcategories();
    $ratePerms = rate_override_permissions();

    $quickPicks = [];
    foreach ($catalog as $row) {
        $mode = (string) ($row['service_price_mode'] ?? $row['price_mode'] ?? 'per_piece') === 'per_kg' ? 'per_kg' : 'per_piece';
        $rate = service_effective_rate(
            ['price_mode' => $mode, 'rate' => (float) ($row['service_rate'] ?? 0)],
            $row
        );
        $quickPicks[] = [
            'name' => (string) $row['item_name'],
            'service_id' => (int) $row['service_id'],
            'service' => (string) ($row['service_name'] ?? ''),
            'subcategory_id' => (int) ($row['subcategory_id'] ?? 0),
            'subcategory' => (string) ($row['subcategory_name'] ?? ''),
            'category' => service_category_for($row),
            'service_type' => (string) $row['service_type'],
            'mode' => $mode,
            'rate' => $rate,
            'min_weight_kg' => (float) ($row['min_weight_kg'] ?? 0),
        ];
    }

    $catalogJson = (string) json_encode($quickPicks, JSON_UNESCAPED_UNICODE);
    $serviceChips = [];
    $chipsChecked = false;
    foreach ($services as $service) {
        $mode = in_array((string) $service['price_mode'], ['per_kg', 'fixed'], true)
            ? (string) $service['price_mode']
            : 'per_piece';
        $category = service_category_for($service);
        $checked = !$chipsChecked && $category === 'piece';
        if ($checked) {
            $chipsChecked = true;
        }
        $serviceChips[$category] = ($serviceChips[$category] ?? '')
            . '<label class="chip-choice"><input type="radio" name="sheet_service_id" value="' . (int) $service['id'] . '"'
            . ' data-mode="' . e($mode) . '" data-category="' . e($category) . '" data-rate="' . e((string) (float) $service['rate']) . '"'
            . ($checked ? ' checked' : '') . '><span>' . e((string) $service['service_name']) . '</span></label>';
    }
    // Make sure one chip is checked even when there is no piece service.
    if ($serviceChips !== [] && !$chipsChecked) {
        $firstCategory = array_key_first($serviceChips);
        $serviceChips[$firstCategory] = preg_replace('/>/', ' checked>', $serviceChips[$firstCategory], 1);
    }
    ?>
    <div class="item-picker" data-item-picker data-currency="<?= e(APP_CURRENCY) ?>"<?= !empty($options['draft_key']) ? ' data-draft-key="' . e((string) $options['draft_key']) . '"' : '' ?>>
        <?php if (!empty($options['intro'])): ?>
            <p class="subtle item-picker-intro"><?= e((string) $options['intro']) ?></p>
        <?php endif; ?>
        <p class="subtle item-picker-hint">Use the <b>Piece wise</b>, <b>Kg wise</b> or <b>Extra</b> tab. The pickup boy verifies the actual weight of kg items.</p>

        <div class="item-list" data-item-list>
            <?php if ($items === []): ?>
                <div class="empty-state item-empty" data-item-empty>
                    <strong>No items added yet</strong>
                    Tap <b>+ Add item</b> below and choose a service first.
                </div>
            <?php endif; ?>
            <?php foreach (array_values($items) as $index => $item): ?>
                <?= item_card_html($item, $catalogListId, $index, $services) ?>
            <?php endforeach; ?>
        </div>

        <div class="item-picker-bar">
            <div class="item-picker-total">
                <span class="subtle" data-item-count>0 items</span>
                <strong>
                    <span class="badge badge-muted" title="Per-kg items are billed on the weight verified at pickup">Estimated</span>
                    <?= e(APP_CURRENCY) ?><span data-subtotal>0.00</span>
                </strong>
            </div>
            <?php if (!empty($options['add_item_url'])): ?>
                <a class="btn btn-primary btn-add-item" href="<?= e($options['add_item_url']) ?>">
                    <span aria-hidden="true">+</span> Add item
                </a>
            <?php else: ?>
                <button type="button" class="btn btn-primary btn-add-item" data-add-item>
                    <span aria-hidden="true">+</span> Add item
                </button>
            <?php endif; ?>
        </div>
        <input type="hidden" name="total_amount" value="0">
        <script type="application/json" data-catalog><?= $catalogJson ?></script>
        <template data-item-template><?= item_card_html(['item_name' => '', 'qty' => 1, 'weight_kg' => 0, 'rate' => 0], $catalogListId, 0, $services) ?></template>

        <div class="sheet-backdrop" data-sheet-backdrop hidden></div>
        <div class="sheet layout-stacked" data-sheet role="dialog" aria-modal="true" aria-label="Add an item"<?= !empty($options['back_url']) ? ' data-back-url="' . e((string) $options['back_url']) . '"' : '' ?> hidden>
            <div class="sheet-grip"></div>
            <div class="sheet-head">
                <h3>Add an item</h3>
                <button type="button" class="sheet-close" data-sheet-close aria-label="Close">&times;</button>
            </div>
            <div class="sheet-search">
                <input type="search" data-sheet-search placeholder="Type item or service... (e.g., Jeans)" autocomplete="off">
            </div>
            <div class="sheet-body pos-layout">
                <aside class="pos-filters">
                    <div class="sheet-section">
                        <span class="sheet-label">Type</span>
                        <div class="item-tabs" role="tablist" aria-label="Item type" data-item-tabs>
                            <button type="button" class="item-tab active" role="tab" data-item-tab="piece" aria-selected="true">Piece wise</button>
                            <button type="button" class="item-tab" role="tab" data-item-tab="kg" aria-selected="false">Kg wise</button>
                            <button type="button" class="item-tab" role="tab" data-item-tab="extra" aria-selected="false">Extra</button>
                        </div>
                    </div>

                    <div class="sheet-section">
                        <span class="sheet-label">Subcategory</span>
                        <div class="chip-group subcategory-chips" data-subcategory-group>
                            <?php foreach ($subcategories as $subcategory): ?>
                                <label class="chip-choice">
                                    <input type="radio" name="sheet_subcategory_id" value="<?= (int) $subcategory['id'] ?>"
                                           data-subcategory="<?= e((string) $subcategory['name']) ?>">
                                    <span><?= e((string) ($subcategory['icon'] ?? '')) ?> <?= e((string) $subcategory['name']) ?></span>
                                </label>
                            <?php endforeach; ?>
                            <label class="chip-choice">
                                <input type="radio" name="sheet_subcategory_id" value="0" data-subcategory="" checked>
                                <span>All</span>
                            </label>
                        </div>
                    </div>

                    <div class="sheet-section">
                        <span class="sheet-label">Service</span>
                        <?php foreach (service_categories() as $categoryKey => $categoryLabel): ?>
                            <div class="chip-group" data-chip-group="<?= e($categoryKey) ?>"<?= $categoryKey === 'piece' ? '' : ' hidden' ?>>
                                <?= $serviceChips[$categoryKey] ?? '' ?>
                            </div>
                        <?php endforeach; ?>
                        <p class="subtle" data-tab-empty hidden>No service in this tab yet. Add one in Admin &rarr; Services &amp; items.</p>
                    </div>
                </aside>

                <main class="pos-articles">
                    <div class="sheet-section">
                        <span class="sheet-label">Article</span>
                        <input type="text" class="sheet-input" data-sheet-name list="<?= e($catalogListId) ?>" placeholder="Search or pick an article" autocomplete="off">
                        <p class="subtle" data-sheet-name-hint>Pick a subcategory, then the article. Its service and rate follow automatically.</p>
                    </div>

                    <?php if ($quickPicks !== []): ?>
                        <div class="sheet-section">
                            <span class="sheet-label">Tap to add</span>
                            <div class="quick-picks" data-quick-picks>
                                <?php foreach ($quickPicks as $pick): ?>
                                    <button type="button" class="quick-pick" data-service-id="<?= (int) $pick['service_id'] ?>"
                                            data-category="<?= e($pick['category']) ?>"
                                            data-subcategory-id="<?= (int) $pick['subcategory_id'] ?>"
                                            data-quick-item="<?= e((string) json_encode($pick, JSON_UNESCAPED_UNICODE)) ?>"
                                            <?= $pick['category'] === 'piece' ? '' : 'hidden' ?>>
                                        <strong><?= e($pick['name']) ?></strong>
                                        <span><?= e($pick['subcategory'] !== '' ? $pick['subcategory'] . ' · ' : '') ?><?= e(APP_CURRENCY) ?><?= e(number_format((float) $pick['rate'], 2)) ?><?= $pick['mode'] === 'per_kg' ? ' / kg' : ($pick['mode'] === 'fixed' ? ' fixed' : ' / pc') ?></span>
                                    </button>
                                <?php endforeach; ?>
                            </div>
                            <p class="subtle quick-picks-empty" data-quick-empty hidden>No articles here yet. Add them in Admin &rarr; Services &amp; items.</p>
                        </div>
                    <?php endif; ?>
                </main>

                <section class="pos-details">
                    <div class="sheet-grid">
                        <div class="sheet-field" data-sheet-qty>
                            <span class="sheet-label" data-sheet-qty-label>How many</span>
                            <div class="stepper stepper-lg">
                                <button type="button" data-step="-1" data-target="sheet_qty" aria-label="One less">&minus;</button>
                                <input type="number" data-sheet-qty min="1" step="1" value="1" inputmode="numeric">
                                <button type="button" data-step="1" data-target="sheet_qty" aria-label="One more">+</button>
                            </div>
                        </div>
                        <div class="sheet-field" data-sheet-weight hidden>
                            <span class="sheet-label">Estimated weight (kg)</span>
                            <div class="stepper stepper-lg">
                                <button type="button" data-step="-0.5" data-target="sheet_weight" aria-label="Half kilo less">&minus;</button>
                                <input type="number" data-sheet-weight min="0" step="0.5" value="1" inputmode="decimal">
                                <button type="button" data-step="0.5" data-target="sheet_weight" aria-label="Half kilo more">+</button>
                            </div>
                            <span class="item-field-hint" data-sheet-min-weight></span>
                        </div>
                        <div class="sheet-field" data-sheet-rate-field>
                            <span class="sheet-label">Rate (?)</span>
                            <input type="hidden" data-sheet-rate value="0" data-catalog-rate="0">
                            <strong class="rate-readonly" data-sheet-rate-readonly>&mdash;</strong>
                            <span class="item-field-hint">Catalog price &middot; fixed</span>
                        </div>
                        <div class="sheet-field sheet-line-total">
                            <span class="sheet-label">Line total</span>
                            <strong><?= e(APP_CURRENCY) ?><span data-sheet-line-total>0.00</span></strong>
                        </div>
                        <div class="sheet-field">
                            <span class="sheet-label">Remarks</span>
                            <label class="chip-choice" style="display:inline-flex;align-items:center;gap:.4rem;margin-right:.6rem;">
                                <input type="checkbox" data-remark="stain"> Severe Stain
                            </label>
                            <label class="chip-choice" style="display:inline-flex;align-items:center;gap:.4rem;">
                                <input type="checkbox" data-remark="torn"> Torn/Damaged
                            </label>
                        </div>
                    </div>
                    <p class="sheet-error" data-sheet-error hidden></p>
                </section>
            </div>

            <div class="sheet-foot">
                <button type="button" class="btn btn-ghost" data-sheet-close>Done &amp; Close</button>
                <button type="button" class="btn btn-ghost" data-done-back>Done &amp; Back to order</button>
                <button type="button" class="btn btn-primary btn-lg" data-sheet-add>Save &amp; Add Next</button>
            </div>

            <?php if (can_add_extra_charges(current_user())): ?>
                <div class="extra-charge-section" hidden>
                    <div class="sheet-section">
                        <span class="sheet-label">Extra Charges</span>
                        <div class="sheet-grid">
                            <div class="sheet-field">
                                <span class="sheet-label">Amount (<?= e(APP_CURRENCY) ?>)</span>
                                <input type="number" class="sheet-input" data-extra-charge-amount min="0" step="0.5" value="0" inputmode="decimal">
                            </div>
                            <div class="sheet-field">
                                <span class="sheet-label">Notes</span>
                                <input type="text" class="sheet-input" data-extra-charge-notes placeholder="Reason for extra charge">
                            </div>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>
    <?php
}

/**
 * Backwards compatible alias - the table editor was replaced by the mobile picker.
 */
function render_item_editor(array $items, array $catalog): void
{
    render_item_picker($items, $catalog);
}

/**
 * Merge repeated lines (same service, item, mode and rate) so a cart never
 * shows the same garment twice. Piece lines add quantity, kg lines add weight.
 */
function merge_duplicate_order_items(array $items): array
{
    $merged = [];
    foreach ($items as $item) {
        $key = (int) ($item['service_id'] ?? 0)
            . '|' . (int) ($item['subcategory_id'] ?? 0)
            . '|' . mb_strtolower(trim((string) ($item['item_name'] ?? '')))
            . '|' . (string) ($item['price_mode'] ?? 'per_piece')
            . '|' . number_format((float) ($item['rate'] ?? 0), 2, '.', '');

        if (!isset($merged[$key])) {
            $merged[$key] = $item;
            continue;
        }

        if ((string) $item['price_mode'] === 'per_kg') {
            $merged[$key]['weight_kg'] = round((float) $merged[$key]['weight_kg'] + (float) $item['weight_kg'], 3);
            $merged[$key]['qty'] = (int) $merged[$key]['qty'] + (int) $item['qty'];
        } else {
            $merged[$key]['qty'] = (int) $merged[$key]['qty'] + (int) $item['qty'];
        }
        if (($merged[$key]['notes'] ?? '') === '' && ($item['notes'] ?? '') !== '') {
            $merged[$key]['notes'] = $item['notes'];
        }
    }
    return array_values($merged);
}

/**
 * Turn the posted picker rows into validated order lines.
 *
 * Security rules enforced here (never in the browser only):
 * - Customers and guests can never change a rate; the catalog price wins.
 * - Staff may override a rate but must give a reason.
 * - Owners/admins may override freely; a default reason is stored.
 * - Per-kg lines must match a catalog item and meet its minimum weight.
 * - Invalid rows are reported in $errors instead of being silently dropped.
 */
function collect_items_from_post(bool $weightsVerified = false, ?array &$errors = null): array
{
    $names = $_POST['item_name'] ?? [];
    $serviceIds = $_POST['item_service_id'] ?? [];
    $subcategoryIds = $_POST['item_subcategory_id'] ?? [];
    $serviceTypes = $_POST['item_service_type'] ?? [];
    $modes = $_POST['item_mode'] ?? [];
    $qtys = $_POST['item_qty'] ?? [];
    $weights = $_POST['item_weight'] ?? [];
    $rates = $_POST['item_rate'] ?? [];
    $reasons = $_POST['item_rate_reason'] ?? [];
    $notes = $_POST['item_notes'] ?? [];

    $items = [];
    if (!is_array($names)) {
        return $items;
    }

    $perms = rate_override_permissions();
    if (function_exists('ensure_order_item_pricing_schema')) {
        ensure_order_item_pricing_schema();
    }
    ensure_subcategory_schema();
    $subcategories = [];
    foreach (active_subcategories() as $subcategory) {
        $subcategories[(int) $subcategory['id']] = (string) $subcategory['name'];
    }
    $user = current_user();
    $catalog = catalog_rate_index();
    $services = [];
    foreach (active_services() as $service) {
        $services[(int) $service['id']] = $service;
    }

    foreach (array_values($names) as $index => $name) {
        $name = trim((string) $name);
        if ($name === '') {
            continue;
        }

        $serviceId = (int) ($serviceIds[$index] ?? 0);
        $service = $services[$serviceId] ?? null;
        $serviceName = (string) ($service['service_name'] ?? '');
        $postedMode = (string) ($modes[$index] ?? 'per_piece');
        $mode = $service !== null
            ? (in_array((string) $service['price_mode'], ['per_kg', 'fixed'], true)
                ? (string) $service['price_mode']
                : 'per_piece')
            : (in_array($postedMode, ['per_kg', 'fixed'], true) ? $postedMode : 'per_piece');
        $entry = $catalog[$serviceId . '|' . mb_strtolower($name)] ?? null;

        $postedSubcategoryId = (int) ($subcategoryIds[$index] ?? 0);
        $subcategoryId = $postedSubcategoryId > 0 && isset($subcategories[$postedSubcategoryId])
            ? $postedSubcategoryId
            : (int) ($entry['subcategory_id'] ?? 0);
        if ($subcategoryId > 0 && !isset($subcategories[$subcategoryId])) {
            $subcategoryId = 0;
        }
        $subcategoryName = $subcategoryId > 0 ? $subcategories[$subcategoryId] : '';

        if ($mode === 'per_kg' && $entry === null) {
            if ($errors !== null) {
                $errors[] = $serviceName !== ''
                    ? 'For kg service "' . $serviceName . '", choose an item from its list. "' . $name . '" is not in that service.'
                    : 'Kg items must be picked from the catalog. "' . $name . '" was not recognised.';
            }
            continue;
        }

        $catalogRate = $entry !== null
            ? (float) $entry['rate']
            : ($service !== null ? (float) $service['rate'] : 0.0);

        $postedRate = round(max(0.0, min(100000.0, (float) ($rates[$index] ?? 0))), 2);
        $rate = $postedRate > 0 ? $postedRate : $catalogRate;

        // Customers and guests never set the price.
        if (!$perms['editable']) {
            $rate = $catalogRate > 0 ? $catalogRate : $postedRate;
        } elseif ($postedRate <= 0) {
            $rate = $catalogRate;
        }

        $qty = max(1, min(9999, (int) ($qtys[$index] ?? 1)));
        $weight = round(max(0.0, min(500.0, (float) ($weights[$index] ?? 0))), 3);

        if ($mode === 'per_kg') {
            $minWeight = $entry !== null
                ? (float) $entry['min_weight_kg']
                : ($service !== null ? (float) $service['min_weight_kg'] : 0.0);
            if ($weight <= 0) {
                if ($errors !== null) {
                    $errors[] = 'Enter the estimated weight for "' . $name . '".';
                }
                continue;
            }
            if ($minWeight > 0 && $weight + 0.0001 < $minWeight) {
                if ($errors !== null) {
                    $errors[] = 'Minimum weight for "' . $name . '" is '
                        . rtrim(rtrim(number_format($minWeight, 3, '.', ''), '0'), '.')
                        . ' kg. You entered ' . rtrim(rtrim(number_format($weight, 3, '.', ''), '0'), '.') . ' kg.';
                }
                continue;
            }
        }

        $reason = trim((string) ($reasons[$index] ?? ''));
        $rateChanged = $catalogRate > 0 && abs($rate - $catalogRate) > 0.004;
        if ($rateChanged && $perms['requires_reason'] && $reason === '') {
            if ($errors !== null) {
                $errors[] = 'Add a reason for changing the rate of "' . $name . '" from ' . money($catalogRate) . ' to ' . money($rate) . '.';
            }
            continue;
        }
        if ($rateChanged && $reason === '') {
            $reason = 'Adjusted by ' . role_label((string) ($user['role'] ?? 'owner'));
        }

        $items[] = [
            'item_name' => $entry !== null ? (string) $entry['name'] : $name,
            'service_id' => $serviceId,
            'subcategory_id' => $subcategoryId > 0 ? $subcategoryId : null,
            'subcategory_name' => $subcategoryName !== '' ? $subcategoryName : null,
            'service_type' => (string) ($serviceTypes[$index] ?? ''),
            'price_mode' => $mode,
            'qty' => $qty,
            'weight_kg' => $weight,
            'weight_verified' => $weightsVerified ? 1 : 0,
            'weight_verified_by' => $weightsVerified ? ($user['id'] ?? null) : null,
            'rate' => $rate,
            'catalog_rate' => $catalogRate,
            'rate_changed_by' => $rateChanged ? ($user['id'] ?? null) : null,
            'rate_changed_by_name' => $rateChanged ? ($user['name'] ?? null) : null,
            'rate_change_reason' => $rateChanged ? $reason : null,
            'rate_changed_at' => $rateChanged ? now() : null,
            'notes' => (string) ($notes[$index] ?? ''),
        ];
    }

    return merge_duplicate_order_items($items);
}
