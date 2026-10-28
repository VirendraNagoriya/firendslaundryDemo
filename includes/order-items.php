<?php
declare(strict_types=1);

require_once __DIR__ . '/extra-charges.php';

/**
 * Active catalog rows joined with their service. An item always belongs to a
 * service, and the service decides whether it is billed per piece or per kg.
 */
function active_catalog(): array
{
    return db_all(
        'SELECT i.*, s.service_name, s.price_mode AS service_price_mode, s.rate AS service_rate
         FROM laundry_items i
         JOIN services s ON s.id = i.service_id
         WHERE i.is_active = 1 AND s.is_active = 1
         ORDER BY s.service_name, i.item_name'
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
    $mode = $mode === 'per_kg' ? 'per_kg' : 'per_piece';
    $name = (string) ($item['item_name'] ?? '');
    $qty = max(1, (int) ($item['qty'] ?? 1));
    $weight = (float) ($item['weight_kg'] ?? 0);
    $rate = (float) ($item['rate'] ?? 0);
    if ($rate <= 0 && $selectedService !== null) {
        $rate = (float) $selectedService['rate'];
    }

    $serviceOptions = '';
    foreach ($services as $service) {
        $serviceMode = (string) $service['price_mode'] === 'per_kg' ? 'per_kg' : 'per_piece';
        $serviceOptions .= '<option value="' . (int) $service['id'] . '"'
            . ' data-mode="' . e($serviceMode) . '"'
            . ' data-type="' . e(service_type_for_name((string) $service['service_name'])) . '"'
            . ' data-rate="' . e((string) (float) $service['rate']) . '"'
            . ((int) $service['id'] === $serviceId ? ' selected' : '') . '>'
            . e((string) $service['service_name'])
            . ' (' . ($serviceMode === 'per_kg' ? 'per kg' : 'per piece') . ')'
            . '</option>';
    }

    ob_start();
    ?>
    <div class="item-card" data-item-row data-service-mode="<?= e($mode) ?>">
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
                <select name="item_service_id[]" data-service-select><?= $serviceOptions ?></select>
            </label>
            <div class="item-field" data-field-qty<?= $mode === 'per_kg' ? ' hidden' : '' ?>>
                <span class="item-field-label">How many</span>
                <div class="stepper">
                    <button type="button" data-step="-1" data-target="qty" aria-label="One less">&minus;</button>
                    <input type="number" name="item_qty[]" min="1" step="1" value="<?= e((string) $qty) ?>" inputmode="numeric">
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
                <?php if ($mode === 'per_kg'): ?>
                    <span class="item-field-hint">Weight will be measured / verified by the pickup boy. Total cost: <?= e(APP_CURRENCY) ?><span class="weight-cost">0.00</span></span>
                <?php endif; ?>
            </div>
            <label class="item-field">
                <span class="item-field-label">Rate (<?= e(APP_CURRENCY) ?>)</span>
                <input type="number" name="item_rate[]" min="0" step="0.5" value="<?= e((string) $rate) ?>" inputmode="decimal" data-rate-input>
            </label>
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
            'service_type' => (string) $row['service_type'],
            'mode' => $mode,
            'rate' => $rate,
        ];
    }

    $catalogJson = (string) json_encode($quickPicks, JSON_UNESCAPED_UNICODE);
    $serviceChips = '';
    foreach ($services as $service) {
        $mode = (string) $service['price_mode'] === 'per_kg' ? 'per_kg' : 'per_piece';
        $serviceChips .= '<label class="chip-choice"><input type="radio" name="sheet_service_id" value="' . (int) $service['id'] . '"'
            . ' data-mode="' . e($mode) . '" data-rate="' . e((string) (float) $service['rate']) . '"'
            . ($serviceChips === '' ? ' checked' : '') . '><span>' . e((string) $service['service_name']) . '</span></label>';
    }
    ?>
    <div class="item-picker" data-item-picker data-currency="<?= e(APP_CURRENCY) ?>">
        <?php if (!empty($options['intro'])): ?>
            <p class="subtle item-picker-intro"><?= e((string) $options['intro']) ?></p>
        <?php endif; ?>
        <p class="subtle item-picker-hint">For kg services, pick an item from the service list. The pickup boy verifies the actual weight.</p>

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
                <strong><?= e(APP_CURRENCY) ?><span data-subtotal>0.00</span></strong>
            </div>
            <button type="button" class="btn btn-primary btn-add-item" data-add-item>
                <span aria-hidden="true">+</span> Add item
            </button>
        </div>
        <input type="hidden" name="total_amount" value="0">
        <script type="application/json" data-catalog><?= $catalogJson ?></script>
        <template data-item-template><?= item_card_html(['item_name' => '', 'qty' => 1, 'weight_kg' => 0, 'rate' => 0], $catalogListId, 0, $services) ?></template>

        <div class="sheet-backdrop" data-sheet-backdrop hidden></div>
        <div class="sheet" data-sheet role="dialog" aria-modal="true" aria-label="Add an item" hidden>
            <div class="sheet-grip"></div>
            <div class="sheet-head">
                <h3>Add an item</h3>
                <button type="button" class="sheet-close" data-sheet-close aria-label="Close">&times;</button>
            </div>
            <div class="sheet-body">
                <div class="sheet-section">
                    <div class="sheet-section-header">
                        <span class="sheet-label">Add Item</span>
                        <div class="selection-mode-toggle">
                            <button type="button" class="mode-btn" data-mode="service-first">Service First</button>
                            <button type="button" class="mode-btn active" data-mode="item-first">Item First</button>
                        </div>
                    </div>
                </div>

                <div class="sheet-section service-first-mode">
                    <span class="sheet-label">Service</span>
                    <div class="chip-group"><?= $serviceChips ?></div>
                </div>

                <div class="sheet-section item-first-mode">
                    <span class="sheet-label">Item name</span>
                    <input type="text" class="sheet-input" data-sheet-name list="<?= e($catalogListId) ?>" placeholder="e.g. Shirt, Saree, Curtains" autocomplete="off">
                    <p class="subtle">Select an item to see available services</p>
                </div>

                <?php if ($quickPicks !== []): ?>
                    <div class="sheet-section service-first-mode">
                        <span class="sheet-label">Tap to add</span>
                        <div class="quick-picks" data-quick-picks>
                            <?php foreach ($quickPicks as $pick): ?>
                                <button type="button" class="quick-pick" data-service-id="<?= (int) $pick['service_id'] ?>" data-quick-item="<?= e((string) json_encode($pick, JSON_UNESCAPED_UNICODE)) ?>">
                                    <strong><?= e($pick['name']) ?></strong>
                                    <span><?= e(APP_CURRENCY) ?><?= e(number_format((float) $pick['rate'], 2)) ?><?= $pick['mode'] === 'per_kg' ? ' / kg' : ' / pc' ?></span>
                                </button>
                            <?php endforeach; ?>
                        </div>
                        <p class="subtle quick-picks-empty" data-quick-empty hidden>This service has no catalog items yet. Add them in Admin &rarr; Services &amp; items.</p>
                    </div>
                <?php endif; ?>

                <div class="sheet-grid">
                    <div class="sheet-field" data-sheet-qty>
                        <span class="sheet-label">How many</span>
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
                        <span class="item-field-hint">Weight will be measured / verified by the pickup boy.</span>
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

function collect_items_from_post(bool $weightsVerified = false): array
{
    $names = $_POST['item_name'] ?? [];
    $serviceIds = $_POST['item_service_id'] ?? [];
    $serviceTypes = $_POST['item_service_type'] ?? [];
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
            'service_id' => (int) ($serviceIds[$index] ?? 0),
            'service_type' => (string) ($serviceTypes[$index] ?? ''),
            'price_mode' => (string) ($modes[$index] ?? 'per_piece'),
            'qty' => (int) ($qtys[$index] ?? 1),
            'weight_kg' => (float) ($weights[$index] ?? 0),
            'weight_verified' => $weightsVerified ? 1 : 0,
            'weight_verified_by' => $weightsVerified ? (current_user()['id'] ?? null) : null,
            'rate' => (float) ($rates[$index] ?? 0),
            'notes' => (string) ($notes[$index] ?? ''),
        ];
    }
    return $items;
}
