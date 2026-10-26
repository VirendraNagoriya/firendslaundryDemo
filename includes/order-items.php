<?php
declare(strict_types=1);

function active_catalog(): array
{
    return db_all('SELECT * FROM laundry_items WHERE is_active = 1 ORDER BY item_name');
}

/**
 * One item card (mobile first): name, service, billing mode, qty / weight, rate and amount.
 *
 * The radio inputs of every card use their own name (radio groups are global inside a form),
 * the real value travels in a hidden "item_mode[]" field so the server keeps receiving the
 * same array of values as the old table editor.
 */
function item_card_html(array $item, string $catalogListId = '', int $index = 0): string
{
    $mode = (string) ($item['price_mode'] ?? 'per_piece');
    $service = (string) ($item['service_type'] ?? 'wash_fold');
    $name = (string) ($item['item_name'] ?? '');
    $qty = max(0, (int) ($item['qty'] ?? 1));
    $weight = (float) ($item['weight_kg'] ?? 0);
    $rate = (float) ($item['rate'] ?? 0);
    $radioName = 'mode_pick_' . $index;

    $serviceOptions = '';
    foreach (service_types() as $key => $label) {
        $serviceOptions .= '<option value="' . e($key) . '"' . ($service === $key ? ' selected' : '') . '>' . e($label) . '</option>';
    }

    ob_start();
    ?>
    <div class="item-card" data-item-row>
        <div class="item-card-top">
            <div class="item-card-title">
                <input type="text" class="item-name-input" name="item_name[]" value="<?= e($name) ?>"
                       placeholder="Item name"<?= $catalogListId !== '' ? ' list="' . e($catalogListId) . '"' : '' ?>
                       autocomplete="off" autocapitalize="words">
                <span class="item-card-sub" data-item-summary></span>
            </div>
            <button type="button" class="item-remove" data-remove-item aria-label="Remove item">&times;</button>
        </div>
        <div class="item-card-grid">
            <label class="item-field">
                <span class="item-field-label">Service</span>
                <select name="item_service_type[]"><?= $serviceOptions ?></select>
            </label>
            <div class="item-field">
                <span class="item-field-label">Billed by</span>
                <div class="segmented" data-mode-group>
                    <label><input type="radio" name="<?= e($radioName) ?>" value="per_piece"<?= $mode === 'per_piece' ? ' checked' : '' ?>><span>Per piece</span></label>
                    <label><input type="radio" name="<?= e($radioName) ?>" value="per_kg"<?= $mode === 'per_kg' ? ' checked' : '' ?>><span>Per kg</span></label>
                </div>
                <input type="hidden" name="item_mode[]" value="<?= e($mode) ?>" data-mode-value>
            </div>
            <div class="item-field" data-field-qty<?= $mode === 'per_kg' ? ' hidden' : '' ?>>
                <span class="item-field-label">How many</span>
                <div class="stepper">
                    <button type="button" data-step="-1" data-target="qty" aria-label="One less">&minus;</button>
                    <input type="number" name="item_qty[]" min="0" step="1" value="<?= e((string) $qty) ?>" inputmode="numeric">
                    <button type="button" data-step="1" data-target="qty" aria-label="One more">+</button>
                </div>
            </div>
            <div class="item-field" data-field-weight<?= $mode === 'per_piece' ? ' hidden' : '' ?>>
                <span class="item-field-label">Weight (kg)</span>
                <div class="stepper">
                    <button type="button" data-step="-0.5" data-target="weight" aria-label="Half kilo less">&minus;</button>
                    <input type="number" name="item_weight[]" min="0" step="0.5" value="<?= e((string) $weight) ?>" inputmode="decimal">
                    <button type="button" data-step="0.5" data-target="weight" aria-label="Half kilo more">+</button>
                </div>
            </div>
            <label class="item-field">
                <span class="item-field-label">Rate (<?= e(APP_CURRENCY) ?>)</span>
                <input type="number" name="item_rate[]" min="0" step="0.5" value="<?= e((string) $rate) ?>" inputmode="decimal">
            </label>
            <div class="item-field item-field-amount">
                <span class="item-field-label">Amount</span>
                <strong data-amount><?= e(number_format(0, 2)) ?></strong>
            </div>
        </div>
    </div>
    <?php
    return (string) ob_get_clean();
}

/**
 * Mobile first item entry: cards + quick picks + "add item" sheet + running total.
 * Posts the same fields as the old table editor, so every form keeps working.
 */
function render_item_picker(array $items, array $catalog, array $options = []): void
{
    static $instance = 0;
    $instance++;
    $catalogListId = 'catalogList' . $instance;

    $quickPicks = [];
    foreach ($catalog as $row) {
        $rate = $row['price_mode'] === 'per_kg' ? (float) $row['rate_per_kg'] : (float) $row['rate_per_piece'];
        $quickPicks[] = [
            'name' => (string) $row['item_name'],
            'service' => (string) $row['service_type'],
            'mode' => (string) $row['price_mode'],
            'rate' => $rate,
        ];
    }

    $catalogJson = (string) json_encode($quickPicks, JSON_UNESCAPED_UNICODE);
    $serviceChips = '';
    foreach (service_types() as $key => $label) {
        $serviceChips .= '<label class="chip-choice"><input type="radio" name="sheet_service" value="' . e($key) . '"'
            . ($key === 'wash_fold' ? ' checked' : '') . '><span>' . e($label) . '</span></label>';
    }
    ?>
    <div class="item-picker" data-item-picker data-currency="<?= e(APP_CURRENCY) ?>">
        <?php if (!empty($options['intro'])): ?>
            <p class="subtle item-picker-intro"><?= e((string) $options['intro']) ?></p>
        <?php endif; ?>

        <div class="item-list" data-item-list>
            <?php if ($items === []): ?>
                <div class="empty-state item-empty" data-item-empty>
                    <strong>No items added yet</strong>
                    Tap <b>+ Add item</b> below - pick a service from the list or type your own.
                </div>
            <?php endif; ?>
            <?php foreach (array_values($items) as $index => $item): ?>
                <?= item_card_html($item, $catalogListId, $index) ?>
            <?php endforeach; ?>
        </div>

        <div class="item-picker-bar">
            <div class="item-picker-total">
                <span class="subtle" data-item-count>0 items</span>
                <strong><?= e(APP_CURRENCY) ?><span data-subtotal>0.00</span></strong>
            </div>
            <button type="button" class="btn btn-primary btn-add-item" data-add-item>
                <span aria-hidden="true">+</span> Add item
            </button>
        </div>
        <input type="hidden" name="total_amount" value="0">
        <script type="application/json" data-catalog><?= $catalogJson ?></script>
        <template data-item-template><?= item_card_html(['item_name' => '', 'qty' => 1, 'weight_kg' => 0, 'rate' => 0], $catalogListId, 0) ?></template>

        <div class="sheet-backdrop" data-sheet-backdrop hidden></div>
        <div class="sheet" data-sheet role="dialog" aria-modal="true" aria-label="Add an item" hidden>
            <div class="sheet-grip"></div>
            <div class="sheet-head">
                <h3>Add an item</h3>
                <button type="button" class="sheet-close" data-sheet-close aria-label="Close">&times;</button>
            </div>
            <div class="sheet-body">
                <?php if ($quickPicks !== []): ?>
                    <div class="sheet-section">
                        <span class="sheet-label">Tap to add</span>
                        <div class="quick-picks" data-quick-picks>
                            <?php foreach ($quickPicks as $pick): ?>
                                <button type="button" class="quick-pick" data-quick-item="<?= e((string) json_encode($pick, JSON_UNESCAPED_UNICODE)) ?>">
                                    <strong><?= e($pick['name']) ?></strong>
                                    <span><?= e(APP_CURRENCY) ?><?= e(number_format((float) $pick['rate'], 2)) ?><?= $pick['mode'] === 'per_kg' ? ' / kg' : ' / pc' ?></span>
                                </button>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <div class="sheet-section">
                        <span class="sheet-label">Or type your own</span>
                    </div>
                <?php endif; ?>

                <div class="sheet-section">
                    <input type="text" class="sheet-input" data-sheet-name list="<?= e($catalogListId) ?>" placeholder="e.g. Shirt, Saree, Curtains" autocomplete="off">
                </div>

                <div class="sheet-section">
                    <span class="sheet-label">Service</span>
                    <div class="chip-group"><?= $serviceChips ?></div>
                </div>

                <div class="sheet-section">
                    <span class="sheet-label">Billed by</span>
                    <div class="segmented segmented-lg" data-sheet-mode>
                        <label><input type="radio" name="sheet_mode" value="per_piece" checked><span>Per piece</span></label>
                        <label><input type="radio" name="sheet_mode" value="per_kg"><span>Per kg</span></label>
                    </div>
                </div>

                <div class="sheet-grid">
                    <div class="sheet-field" data-sheet-qty>
                        <span class="sheet-label">How many</span>
                        <div class="stepper stepper-lg">
                            <button type="button" data-step="-1" data-target="sheet_qty" aria-label="One less">&minus;</button>
                            <input type="number" data-sheet-qty min="0" step="1" value="1" inputmode="numeric">
                            <button type="button" data-step="1" data-target="sheet_qty" aria-label="One more">+</button>
                        </div>
                    </div>
                    <div class="sheet-field" data-sheet-weight hidden>
                        <span class="sheet-label">Weight (kg)</span>
                        <div class="stepper stepper-lg">
                            <button type="button" data-step="-0.5" data-target="sheet_weight" aria-label="Half kilo less">&minus;</button>
                            <input type="number" data-sheet-weight min="0" step="0.5" value="1" inputmode="decimal">
                            <button type="button" data-step="0.5" data-target="sheet_weight" aria-label="Half kilo more">+</button>
                        </div>
                    </div>
                    <div class="sheet-field">
                        <span class="sheet-label">Rate (<?= e(APP_CURRENCY) ?>)</span>
                        <input type="number" class="sheet-input" data-sheet-rate min="0" step="0.5" value="0" inputmode="decimal">
                    </div>
                </div>
            </div>
            <div class="sheet-foot">
                <button type="button" class="btn btn-ghost" data-sheet-close>Cancel</button>
                <button type="button" class="btn btn-primary btn-lg" data-sheet-add>Add to order</button>
            </div>
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

function collect_items_from_post(): array
{
    $names = $_POST['item_name'] ?? [];
    $services = $_POST['item_service_type'] ?? [];
    $modes = $_POST['item_mode'] ?? [];
    $qtys = $_POST['item_qty'] ?? [];
    $weights = $_POST['item_weight'] ?? [];
    $rates = $_POST['item_rate'] ?? [];
    $notes = $_POST['item_notes'] ?? [];

    $items = [];
    if (!is_array($names)) {
        return $items;
    }
    foreach (array_values($names) as $index => $name) {
        $name = trim((string) $name);
        if ($name === '') {
            continue;
        }
        $items[] = [
            'item_name' => $name,
            'service_type' => (string) ($services[$index] ?? 'wash_fold'),
            'price_mode' => (string) ($modes[$index] ?? 'per_piece'),
            'qty' => (int) ($qtys[$index] ?? 1),
            'weight_kg' => (float) ($weights[$index] ?? 0),
            'rate' => (float) ($rates[$index] ?? 0),
            'notes' => (string) ($notes[$index] ?? ''),
        ];
    }
    return $items;
}
