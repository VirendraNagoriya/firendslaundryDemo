(function () {
    'use strict';

    // Mobile menu toggle
    var toggle = document.querySelector('[data-toggle-sidebar]');
    var sidebar = document.getElementById('sidebar');
    if (toggle && sidebar) {
        toggle.addEventListener('click', function () {
            sidebar.classList.toggle('open');
        });
        document.addEventListener('click', function (event) {
            if (sidebar.classList.contains('open') && !sidebar.contains(event.target) && !toggle.contains(event.target)) {
                sidebar.classList.remove('open');
            }
        });
    }

    // Install app functionality
    var installButton = document.querySelector('[data-install-app]');
    if (installButton) {
        installButton.addEventListener('click', function() {
            if (window.matchMedia('(display-mode: standalone)').matches) {
                // Already installed
                return;
            }

            // Check if the browser supports PWA installation
            if ('launchQueue' in window) {
                // For Chrome 89+
                window.addEventListener('beforeinstallprompt', function(e) {
                    e.preventDefault();
                    installButton.style.display = 'block';

                    installButton.addEventListener('click', function() {
                        e.prompt();
                    });
                });
            } else if ('standalone' in navigator && navigator.standalone === false) {
                // For iOS Safari
                installButton.style.display = 'block';
            }
        });
    }

    function num(value) {
        var parsed = parseFloat(value);
        return isNaN(parsed) ? 0 : parsed;
    }

    function showNotification(message) {
        var notification = document.createElement('div');
        notification.className = 'notification';
        notification.textContent = message;
        document.body.appendChild(notification);

        // Animate in
        setTimeout(function() {
            notification.classList.add('show');
        }, 10);

        // Remove after 3 seconds
        setTimeout(function() {
            notification.classList.remove('show');
            setTimeout(function() {
                document.body.removeChild(notification);
            }, 300);
        }, 3000);
    }

    function fieldValue(scope, selector) {
        var el = scope.querySelector(selector);
        return el ? num(el.value) : 0;
    }

    function rowInfo(row) {
        var mode = row.getAttribute('data-service-mode') === 'per_kg' ? 'per_kg' : 'per_piece';
        var modeInput = row.querySelector('[data-mode-value]');
        if (modeInput && modeInput.value === 'per_kg') { mode = 'per_kg'; }
        return {
            qty: fieldValue(row, '[name="item_qty[]"], [name^="item_qty"]'),
            kg: fieldValue(row, '[name="item_weight[]"], [name^="item_weight"]'),
            rate: fieldValue(row, '[name="item_rate[]"], [name^="item_rate"]'),
            mode: mode
        };
    }

    function serviceLabel(row) {
        var select = row.querySelector('select[name^="item_service_id"]');
        if (!select || select.selectedIndex < 0) { return ''; }
        return select.options[select.selectedIndex].text.replace(/\s*\((per kg|per piece)\)$/, '');
    }

    function currencyOf(scope) {
        var picker = scope.closest ? scope.closest('[data-item-picker]') : null;
        if (!picker) { picker = scope.querySelector ? scope.querySelector('[data-item-picker]') : null; }
        return picker ? (picker.getAttribute('data-currency') || '') : '';
    }

    function updateSummary(row, info, amount, currency) {
        var summary = row.querySelector('[data-item-summary]');
        if (!summary) { return; }
        var parts = [];
        var service = serviceLabel(row);
        if (service) { parts.push(service); }
        if (info.mode === 'per_kg') {
            parts.push('Per kg');
            parts.push(info.kg + ' kg x ' + currency + info.rate.toFixed(2));
        } else {
            parts.push('Per piece');
            parts.push(info.qty + ' x ' + currency + info.rate.toFixed(2));
        }
        parts.push('= ' + currency + amount.toFixed(2));
        summary.textContent = parts.join(' \u00b7 ');
    }

    function recalc(scope) {
        if (!scope) { return 0; }
        var form = scope.tagName === 'FORM' ? scope : (scope.closest('form') || scope);
        var subtotal = 0;
        var rows = scope.querySelectorAll('[data-item-row], tbody tr.item-row');
        var currency = currencyOf(scope);
        rows.forEach(function (row) {
            var info = rowInfo(row);
            var amount = info.mode === 'per_kg' ? info.kg * info.rate : info.qty * info.rate;
            var cell = row.querySelector('[data-amount]');
            if (cell) { cell.textContent = amount.toFixed(2); }

            // Update weight cost display for weight-based services
            if (info.mode === 'per_kg') {
                var weightCostCell = row.querySelector('.weight-cost');
                if (weightCostCell) {
                    weightCostCell.textContent = amount.toFixed(2);
                }
            }

            subtotal += amount;
            updateSummary(row, info, amount, currency);
        });

        scope.querySelectorAll('[data-subtotal]').forEach(function (output) {
            output.textContent = subtotal.toFixed(2);
        });
        var countOutput = scope.querySelector('[data-item-count]');
        if (countOutput) {
            countOutput.textContent = rows.length + (rows.length === 1 ? ' item' : ' items');
        }

        var discount = fieldValue(form, '[name="discount_amount"]');
        var delivery = fieldValue(form, '[name="delivery_charge"]');
        var taxPercent = num((form.getAttribute && form.getAttribute('data-tax-percent')) || 0);
        var tax = form.querySelector('[name="tax_amount"]') ? fieldValue(form, '[name="tax_amount"]') : (subtotal * taxPercent) / 100;
        var total = subtotal - discount + delivery + tax;

        form.querySelectorAll('[data-total]').forEach(function (totalOutput) {
            totalOutput.textContent = total.toFixed(2);
        });
        form.querySelectorAll('[name="total_amount"]').forEach(function (input) {
            input.value = total.toFixed(2);
        });
        return subtotal;
    }

    var calcForms = document.querySelectorAll('[data-calc-items]');
    calcForms.forEach(function (form) {
        form.addEventListener('input', function () { recalc(form); });
        form.addEventListener('change', function () { recalc(form); });
    });

    /* ---------- Mobile item picker ---------- */
    var pickerList = document.querySelectorAll('[data-item-picker]');

    pickerList.forEach(function (picker, pickerIndex) {
        var list = picker.querySelector('[data-item-list]');
        var sheet = picker.querySelector('[data-sheet]');
        var backdrop = picker.querySelector('[data-sheet-backdrop]');
        var template = picker.querySelector('[data-item-template]');
        var currency = picker.getAttribute('data-currency') || '';
        var catalog = [];
        var catalogNode = picker.querySelector('[data-catalog]');
        if (catalogNode) {
            try { catalog = JSON.parse(catalogNode.textContent || '[]'); } catch (err) { catalog = []; }
        }

        var serviceDatalist = document.createElement('datalist');
        serviceDatalist.id = 'serviceItems' + (pickerIndex + 1);
        picker.appendChild(serviceDatalist);

        function refresh() {
            recalc(picker);
            saveDraft();
        }

        function catalogForService(serviceId) {
            return catalog.filter(function (entry) {
                return String(entry.service_id) === String(serviceId);
            });
        }

        function selectedServiceChip() {
            return sheet ? sheet.querySelector('input[name="sheet_service_id"]:checked') : null;
        }

        function chipMode(chip) {
            return chip && chip.getAttribute('data-mode') === 'per_kg' ? 'per_kg' : 'per_piece';
        }

        function catalogEntry(serviceId, name) {
            var term = String(name || '').trim().toLowerCase();
            if (term === '') { return null; }
            var found = null;
            catalogForService(serviceId).forEach(function (entry) {
                if (!found && entry.name.toLowerCase() === term) { found = entry; }
            });
            return found;
        }

        function sheetRateInput() {
            return sheet ? sheet.querySelector('[data-sheet-rate]') : null;
        }

        function markRateManual(input) {
            if (input && input.getAttribute('type') === 'number') {
                input.setAttribute('data-rate-manual', '1');
            }
        }

        function rateIsManual(input) {
            return !!(input && input.getAttribute('data-rate-manual') === '1');
        }

        function setSheetError(message) {
            var box = sheet ? sheet.querySelector('[data-sheet-error]') : null;
            if (!box) {
                if (message) { showNotification(message); }
                return;
            }
            box.textContent = message || '';
            box.hidden = !message;
            if (message && box.scrollIntoView) {
                box.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
            }
        }

        function updateSheetRateUI() {
            if (!sheet) { return; }
            var rateInput = sheetRateInput();
            if (!rateInput) { return; }
            var catalogRate = num(rateInput.getAttribute('data-catalog-rate'));
            var value = num(rateInput.value);
            var changed = catalogRate > 0 && Math.abs(value - catalogRate) > 0.004;
            var reason = sheet.querySelector('[data-sheet-rate-reason]');
            if (reason) { reason.hidden = !changed; }
            var original = sheet.querySelector('[data-sheet-rate-original]');
            if (original) {
                original.hidden = !changed;
                original.textContent = changed ? 'Catalog ' + currency + catalogRate.toFixed(2) : '';
            }
            var readonly = sheet.querySelector('[data-sheet-rate-readonly]');
            if (readonly) { readonly.textContent = currency + value.toFixed(2); }
        }

        function updateSheetMinWeight() {
            if (!sheet) { return; }
            var chip = selectedServiceChip();
            var nameInput = sheet.querySelector('[data-sheet-name]');
            var hint = sheet.querySelector('[data-sheet-min-weight]');
            if (!chip || !hint) { return; }
            var entry = catalogEntry(chip.value, nameInput ? nameInput.value : '');
            var minimum = entry ? num(entry.min_weight_kg) : 0;
            hint.hidden = minimum <= 0;
            hint.textContent = minimum > 0 ? ('Minimum ' + minimum + ' kg for ' + entry.name) : '';
        }

        function syncSheetService(resetRate) {
            if (!sheet) { return; }
            var chip = selectedServiceChip();
            var mode = chipMode(chip);
            var qtyField = sheet.querySelector('[data-sheet-qty]');
            var weightField = sheet.querySelector('[data-sheet-weight]');
            var custom = sheet.querySelector('[data-custom-name]');
            var kgNote = sheet.querySelector('[data-kg-note]');
            var rateInput = sheetRateInput();
            var serviceId = chip ? chip.value : '';

            if (qtyField) { qtyField.hidden = mode === 'per_kg'; }
            if (weightField) { weightField.hidden = mode !== 'per_kg'; }
            if (custom) { custom.hidden = mode === 'per_kg'; }
            if (kgNote) { kgNote.hidden = mode !== 'per_kg'; }

            if (rateInput) {
                var catalogRate = chip ? num(chip.getAttribute('data-rate')) : 0;
                rateInput.setAttribute('data-catalog-rate', String(catalogRate));
                if (resetRate || !rateIsManual(rateInput)) {
                    rateInput.value = catalogRate;
                    rateInput.removeAttribute('data-rate-manual');
                }
            }

            var visible = 0;
            picker.querySelectorAll('[data-quick-item]').forEach(function (button) {
                var match = String(button.getAttribute('data-service-id')) === String(serviceId);
                button.hidden = !match;
                if (match) { visible++; }
            });
            var empty = picker.querySelector('[data-quick-empty]');
            if (empty) { empty.hidden = visible > 0; }

            setSheetError('');
            updateSheetRateUI();
            updateSheetMinWeight();
        }

        function showEmptyState() {
            if (!list) { return; }
            var empty = list.querySelector('[data-item-empty]');
            if (!empty) {
                empty = document.createElement('div');
                empty.className = 'empty-state item-empty';
                empty.setAttribute('data-item-empty', '');
                empty.innerHTML = '<strong>No items added yet</strong>Tap <b>+ Add item</b> below and choose a service first.';
                list.appendChild(empty);
            }
        }

        function newCard() {
            if (!template) { return null; }
            return template.content.firstElementChild.cloneNode(true);
        }

        function syncServiceDatalist(card, mode) {
            var nameInput = card.querySelector('[name="item_name[]"]');
            if (!nameInput) { return; }
            if (mode !== 'per_kg') {
                nameInput.removeAttribute('list');
                if (template) {
                    var templateInput = template.content.querySelector('[name="item_name[]"]');
                    if (templateInput && templateInput.getAttribute('list')) {
                        nameInput.setAttribute('list', templateInput.getAttribute('list'));
                    }
                }
                return;
            }
            var select = card.querySelector('[data-service-select]');
            var serviceId = select ? select.value : '';
            serviceDatalist.innerHTML = '';
            catalogForService(serviceId).forEach(function (entry) {
                var option = document.createElement('option');
                option.value = entry.name;
                serviceDatalist.appendChild(option);
            });
            nameInput.setAttribute('list', serviceDatalist.id);
        }

        function updateCardRateUI(card) {
            if (!card) { return; }
            var rateInput = card.querySelector('[data-rate-input]');
            var catalogRate = num(card.getAttribute('data-catalog-rate'));
            var value = rateInput ? num(rateInput.value) : 0;
            var changed = catalogRate > 0 && Math.abs(value - catalogRate) > 0.004;
            var reasonInput = card.querySelector('[data-rate-reason]');
            var hasReason = !!(reasonInput && reasonInput.value.trim() !== '');
            var reasonField = card.querySelector('[data-rate-reason-field]');
            if (reasonField) { reasonField.hidden = !(changed || hasReason); }
            var original = card.querySelector('[data-rate-original]');
            if (original) {
                original.hidden = !changed;
                original.textContent = changed ? 'Catalog ' + currency + catalogRate.toFixed(2) : '';
            }
        }

        function warnIfKgNameMismatch(card) {
            if (!card) { return; }
            var warning = card.querySelector('[data-item-warning]');
            if (!warning) { return; }
            var modeValue = card.querySelector('[data-mode-value]');
            var mode = modeValue ? modeValue.value : 'per_piece';
            var select = card.querySelector('[data-service-select]');
            var nameInput = card.querySelector('[name="item_name[]"]');
            var name = nameInput ? nameInput.value.trim() : '';
            if (mode !== 'per_kg' || name === '') {
                warning.hidden = true;
                warning.textContent = '';
                return;
            }
            if (catalogEntry(select ? select.value : '', name)) {
                warning.hidden = true;
                warning.textContent = '';
                return;
            }
            warning.hidden = false;
            warning.textContent = 'Pick an item from this kg service, or switch to a piece service.';
        }

        function applyService(card, mode, rate, setRate) {
            var modeValue = card.querySelector('[data-mode-value]');
            if (modeValue) { modeValue.value = mode; }
            var serviceType = card.querySelector('[data-service-type]');
            var select = card.querySelector('[data-service-select]');
            var selectedOption = select && select.selectedIndex >= 0 ? select.options[select.selectedIndex] : null;
            if (serviceType && selectedOption) {
                serviceType.value = selectedOption.getAttribute('data-type') || '';
            }
            if (selectedOption) {
                card.setAttribute('data-service-category', selectedOption.getAttribute('data-category') || (mode === 'per_kg' ? 'kg' : 'piece'));
            }
            var qtyField = card.querySelector('[data-field-qty]');
            var weightField = card.querySelector('[data-field-weight]');
            if (qtyField) { qtyField.hidden = mode === 'per_kg'; }
            if (weightField) { weightField.hidden = mode !== 'per_kg'; }
            card.setAttribute('data-service-mode', mode);
            if (setRate) {
                card.setAttribute('data-catalog-rate', String(rate));
                var rateInput = card.querySelector('[data-rate-input]');
                if (rateInput && !rateIsManual(rateInput)) {
                    rateInput.value = rate;
                }
            }
            syncServiceDatalist(card, mode);
            warnIfKgNameMismatch(card);
            updateCardRateUI(card);
        }

        function setCardValues(card, data) {
            var nameInput = card.querySelector('[name="item_name[]"]');
            if (nameInput) { nameInput.value = data.name || ''; }
            var serviceSelect = card.querySelector('[data-service-select]');
            if (serviceSelect && data.service_id) { serviceSelect.value = String(data.service_id); }
            var mode = data.mode === 'per_kg' ? 'per_kg' : 'per_piece';
            var qty = card.querySelector('[name="item_qty[]"]');
            if (qty) { qty.value = data.qty === undefined ? 1 : data.qty; }
            var weight = card.querySelector('[name="item_weight[]"]');
            if (weight) { weight.value = mode === 'per_kg' ? (data.weight === undefined ? 1 : data.weight) : 0; }
            var rate = card.querySelector('[name="item_rate[]"]');
            var catalogRate = data.catalog_rate === undefined ? (data.rate === undefined ? 0 : data.rate) : data.catalog_rate;
            if (rate) { rate.value = data.rate === undefined ? 0 : data.rate; }
            card.setAttribute('data-catalog-rate', String(catalogRate));
            card.setAttribute('data-min-weight', String(data.min_weight === undefined ? 0 : data.min_weight));
            var reason = card.querySelector('[data-rate-reason]');
            if (reason) { reason.value = data.reason || ''; }
            applyService(card, mode, catalogRate, false);
            updateCardRateUI(card);
        }

        function findMatchingCard(data) {
            if (!list) { return null; }
            var mode = data.mode === 'per_kg' ? 'per_kg' : 'per_piece';
            var rate = num(data.rate);
            var cards = list.querySelectorAll('[data-item-row]');
            for (var i = 0; i < cards.length; i++) {
                var card = cards[i];
                var select = card.querySelector('[data-service-select]');
                var nameInput = card.querySelector('[name="item_name[]"]');
                var rateInput = card.querySelector('[data-rate-input]');
                var sameService = String(select ? select.value : '') === String(data.service_id);
                var sameName = String(nameInput ? nameInput.value.trim().toLowerCase() : '') === String(data.name || '').trim().toLowerCase();
                var sameMode = card.getAttribute('data-service-mode') === mode;
                var sameRate = Math.abs(num(rateInput ? rateInput.value : 0) - rate) < 0.005;
                if (sameService && sameName && sameMode && sameRate) { return card; }
            }
            return null;
        }

        function addCard(data) {
            var existing = findMatchingCard(data);
            if (existing) {
                if (data.mode === 'per_kg') {
                    var weight = existing.querySelector('[name="item_weight[]"]');
                    if (weight) { weight.value = (num(weight.value) + num(data.weight)).toFixed(1); }
                } else {
                    var qty = existing.querySelector('[name="item_qty[]"]');
                    if (qty) { qty.value = String(num(qty.value) + (num(data.qty) || 1)); }
                }
                refresh();
                existing.classList.add('is-new');
                window.setTimeout(function () { existing.classList.remove('is-new'); }, 400);
                return existing;
            }

            var card = newCard();
            if (!card || !list) { return null; }
            setCardValues(card, data);

            // Add special styling for weight-based services
            if (data.mode === 'per_kg') {
                card.classList.add('weight-service-card');
            }

            var empty = list.querySelector('[data-item-empty]');
            if (empty) { empty.remove(); }
            list.appendChild(card);
            card.classList.add('is-new');
            window.setTimeout(function () { card.classList.remove('is-new'); }, 400);
            refresh();
            return card;
        }

        function openSheet() {
            if (!sheet) { return; }
            var qtyInput = sheet.querySelector('[data-sheet-qty]');
            var weightInput = sheet.querySelector('[data-sheet-weight]');
            if (qtyInput) { qtyInput.value = 1; }
            if (weightInput) { weightInput.value = 1; }

            var nameInput = sheet.querySelector('[data-sheet-name]');
            if (nameInput) { nameInput.value = ''; }
            var rateInput = sheetRateInput();
            if (rateInput) {
                rateInput.removeAttribute('data-rate-manual');
                rateInput.value = 0;
            }
            var reasonInput = sheet.querySelector('[data-sheet-rate-reason]');
            if (reasonInput) { reasonInput.value = ''; }
            setSheetError('');

            // Start on the first tab that actually has a service. Piece-wise is
            // preferred; a kg-only or extra-only catalogue still opens cleanly.
            var startTab = 'piece';
            var groups = sheet.querySelectorAll('[data-chip-group]');
            for (var g = 0; g < groups.length; g++) {
                if (groups[g].querySelector('input[name="sheet_service_id"]')) {
                    startTab = groups[g].getAttribute('data-chip-group') || 'piece';
                    break;
                }
            }
            activateSheetTab(startTab);
            sheet.hidden = false;
            if (backdrop) { backdrop.hidden = false; }
            document.body.classList.add('sheet-open');
        }

        function activateSheetTab(category) {
            if (!sheet) { return; }
            var wanted = category || 'piece';
            sheet.querySelectorAll('[data-item-tab]').forEach(function (tab) {
                var active = tab.getAttribute('data-item-tab') === wanted;
                tab.classList.toggle('active', active);
                tab.setAttribute('aria-selected', active ? 'true' : 'false');
            });

            var activeGroup = null;
            sheet.querySelectorAll('[data-chip-group]').forEach(function (group) {
                var show = group.getAttribute('data-chip-group') === wanted;
                group.hidden = !show;
                if (show) { activeGroup = group; }
            });

            var chip = activeGroup ? activeGroup.querySelector('input[name="sheet_service_id"]') : null;
            var checked = sheet.querySelector('input[name="sheet_service_id"]:checked');
            if (chip && (!checked || checked.getAttribute('data-category') !== wanted)) {
                chip.checked = true;
            }
            var empty = sheet.querySelector('[data-tab-empty]');
            if (empty) {
                empty.hidden = !!(activeGroup && activeGroup.querySelector('input[name="sheet_service_id"]'));
            }
            var hint = sheet.querySelector('[data-sheet-name-hint]');
            if (hint) {
                hint.textContent = wanted === 'kg'
                    ? 'Choose one of this kg service\'s catalog items, then enter the estimated weight.'
                    : (wanted === 'extra'
                        ? 'Add an add-on such as express delivery, stain removal or extra detergent.'
                        : 'Pick a service, then type or choose the item.');
            }
            var nameField = sheet.querySelector('[data-sheet-name]');
            if (nameField) {
                nameField.placeholder = wanted === 'extra'
                    ? 'e.g. Express Delivery, Stain Removal'
                    : (wanted === 'kg' ? 'Choose a kg item' : 'e.g. Shirt, Saree, Curtains');
            }
            syncSheetService(true);
        }

        sheet.querySelectorAll('[data-item-tab]').forEach(function (tab) {
            tab.addEventListener('click', function () {
                activateSheetTab(tab.getAttribute('data-item-tab'));
            });
        });

        function closeSheet() {
            if (!sheet) { return; }
            sheet.hidden = true;
            if (backdrop) { backdrop.hidden = true; }
            document.body.classList.remove('sheet-open');
        }

        function addFromSheet() {
            if (!sheet) { return; }
            var nameInput = sheet.querySelector('[data-sheet-name]');
            var rateInput = sheetRateInput();
            var qtyInput = sheet.querySelector('[data-sheet-qty]');
            var weightInput = sheet.querySelector('[data-sheet-weight]');
            var chip = selectedServiceChip();
            var mode = chipMode(chip);
            var serviceId = chip ? chip.value : '';
            var name = nameInput ? nameInput.value.trim() : '';
            var entry = null;

            if (!chip) {
                setSheetError('Choose a service first.');
                return;
            }

            if (mode === 'per_kg') {
                if (name === '') {
                    setSheetError('Type or pick the item name first.');
                    if (nameInput) { nameInput.focus(); }
                    return;
                }
                entry = catalogEntry(serviceId, name);
                if (!entry) {
                    var names = catalogForService(serviceId).map(function (item) { return item.name; });
                    setSheetError(names.length
                        ? 'Choose one of this service\'s items: ' + names.slice(0, 6).join(', ') + (names.length > 6 ? '…' : '') + '.'
                        : 'This service has no catalog items yet. Add them in Admin → Services & items.');
                    return;
                }
                name = entry.name;
                var kg = num(weightInput ? weightInput.value : 0);
                if (kg <= 0) {
                    setSheetError('Enter the estimated weight in kg.');
                    if (weightInput) { weightInput.focus(); }
                    return;
                }
                if (num(entry.min_weight_kg) > 0 && kg + 0.0001 < num(entry.min_weight_kg)) {
                    setSheetError('Minimum weight for ' + entry.name + ' is ' + entry.min_weight_kg + ' kg.');
                    if (weightInput) { weightInput.focus(); }
                    return;
                }
            } else if (name === '') {
                setSheetError('Type the item name first.');
                if (nameInput) { nameInput.focus(); }
                return;
            }

            var rate = num(rateInput ? rateInput.value : 0);
            var catalogRate = rateInput ? num(rateInput.getAttribute('data-catalog-rate')) : 0;
            var reasonInput = sheet.querySelector('[data-sheet-rate-reason]');
            var reason = reasonInput ? reasonInput.value.trim() : '';
            var changed = catalogRate > 0 && Math.abs(rate - catalogRate) > 0.004;
            var reasonRequired = !!sheet.querySelector('[data-sheet-rate-field][data-requires-reason="1"]');
            if (changed && reasonRequired && reason === '') {
                setSheetError('Add a reason for changing the rate.');
                if (reasonInput) { reasonInput.focus(); }
                return;
            }

            var weight = mode === 'per_kg' ? num(weightInput ? weightInput.value : 0) : 0;
            var qty = Math.max(1, Math.floor(num(qtyInput ? qtyInput.value : 1)) || 1);

            var card = addCard({
                name: name,
                service_id: serviceId,
                mode: mode,
                qty: qty,
                weight: weight || 1,
                rate: rate > 0 ? rate : catalogRate,
                catalog_rate: catalogRate,
                min_weight: entry ? num(entry.min_weight_kg) : 0,
                reason: changed ? reason : ''
            });

            var extraChargeAmount = num(sheet.querySelector('[data-extra-charge-amount]') ? sheet.querySelector('[data-extra-charge-amount]').value : 0);
            var extraChargeNotes = sheet.querySelector('[data-extra-charge-notes]') ? sheet.querySelector('[data-extra-charge-notes]').value : '';
            if (card && extraChargeAmount > 0) {
                card.setAttribute('data-pending-extra-charge', String(extraChargeAmount));
                card.setAttribute('data-pending-extra-charge-notes', extraChargeNotes);
            }

            showNotification('Item added: ' + name + (mode === 'per_kg' ? ' · ' + (weight || 1) + ' kg' : ' · ' + qty + ' pc'));

            closeSheet();
            if (card && card.scrollIntoView) {
                card.scrollIntoView({ behavior: 'smooth', block: 'center' });
            }
        }

        function applyCatalogDefaults(nameInput) {
            var term = nameInput.value.trim().toLowerCase();
            if (term === '' || !sheet) { updateSheetMinWeight(); return; }
            var chip = selectedServiceChip();
            var found = catalogEntry(chip ? chip.value : '', term);
            if (!found) {
                // Typing an item in "Item first" mode should also pick the
                // service that owns that item, not silently keep the first one.
                catalog.forEach(function (entry) {
                    if (!found && entry.name.toLowerCase() === term) {
                        found = entry;
                    }
                });
                if (found) {
                    var matchingChip = sheet.querySelector('input[name="sheet_service_id"][value="' + found.service_id + '"]');
                    if (matchingChip) {
                        activateSheetTab(found.category || (found.mode === 'per_kg' ? 'kg' : 'piece'));
                        matchingChip.checked = true;
                        syncSheetService();
                        nameInput.value = found.name;
                    }
                }
            }
            if (!found) { updateSheetMinWeight(); return; }
            var rate = sheetRateInput();
            if (rate) {
                rate.setAttribute('data-catalog-rate', String(found.rate));
                if (!rateIsManual(rate)) { rate.value = found.rate; }
            }
            updateSheetRateUI();
            updateSheetMinWeight();
        }

        picker.addEventListener('click', function (event) {
            var addButton = event.target.closest('[data-add-item]');
            if (addButton) {
                event.preventDefault();
                openSheet();
                return;
            }

            var quick = event.target.closest('[data-quick-item]');
            if (quick) {
                event.preventDefault();
                var pick = null;
                try { pick = JSON.parse(quick.getAttribute('data-quick-item')); } catch (err) { pick = null; }
                if (!pick) { return; }

                activateSheetTab(pick.category || (pick.mode === 'per_kg' ? 'kg' : 'piece'));

                if (pick.mode === 'per_kg') {
                    // Weighing needs a deliberate weight, so pre-fill the sheet
                    // instead of silently adding 1 kg.
                    var quickChip = sheet.querySelector('input[name="sheet_service_id"][value="' + pick.service_id + '"]');
                    if (quickChip) {
                        quickChip.checked = true;
                        syncSheetService();
                    }
                    var quickName = sheet.querySelector('[data-sheet-name]');
                    if (quickName) { quickName.value = pick.name; }
                    var quickRate = sheetRateInput();
                    if (quickRate) {
                        quickRate.removeAttribute('data-rate-manual');
                        quickRate.value = pick.rate;
                        quickRate.setAttribute('data-catalog-rate', String(pick.rate));
                    }
                    var quickWeight = sheet.querySelector('[data-sheet-weight]');
                    if (quickWeight) { quickWeight.value = 1; }
                    var reason = sheet.querySelector('[data-sheet-rate-reason]');
                    if (reason) { reason.value = ''; }
                    updateSheetRateUI();
                    updateSheetMinWeight();
                    setSheetError('');
                    if (quickWeight) { quickWeight.focus(); }
                    showNotification(pick.name + ': enter the estimated kg, then tap Add to order.');
                    return;
                }

                var card = addCard({
                    name: pick.name,
                    service_id: pick.service_id,
                    mode: pick.mode,
                    qty: 1,
                    weight: 0,
                    rate: pick.rate,
                    catalog_rate: pick.rate,
                    min_weight: pick.min_weight_kg || 0
                });
                showNotification('Added ' + pick.name);
                closeSheet();
                if (card && card.scrollIntoView) {
                    card.scrollIntoView({ behavior: 'smooth', block: 'center' });
                }
                return;
            }

            var remove = event.target.closest('[data-remove-item]');
            if (remove) {
                event.preventDefault();
                var row = remove.closest('[data-item-row]');
                if (row) { row.remove(); }
                if (!list || !list.querySelector('[data-item-row]')) { showEmptyState(); }
                refresh();
                return;
            }

            var step = event.target.closest('[data-step]');
            if (step) {
                event.preventDefault();
                var stepper = step.closest('.stepper');
                var input = stepper ? stepper.querySelector('input') : null;
                if (!input) { return; }
                var min = input.min === '' ? 0 : num(input.min);
                var delta = num(step.getAttribute('data-step'));
                var current = num(input.value);
                var next = current + delta;
                if (next < min) { next = min; }
                var decimals = (input.getAttribute('step') || '').indexOf('.') >= 0 ? 1 : 0;
                input.value = decimals ? next.toFixed(decimals) : String(next);
                input.dispatchEvent(new Event('input', { bubbles: true }));
                return;
            }

            if (event.target.closest('[data-sheet-add]')) {
                event.preventDefault();
                addFromSheet();
                return;
            }

            if (event.target.closest('[data-sheet-close]') || event.target.closest('[data-sheet-backdrop]')) {
                event.preventDefault();
                closeSheet();
            }
        });

        picker.addEventListener('input', function (event) {
            var target = event.target;

            if (target.matches('[data-sheet-name]')) {
                applyCatalogDefaults(target);
                return;
            }
            if (target.matches('[data-sheet-rate]')) {
                markRateManual(target);
                updateSheetRateUI();
                return;
            }
            if (target.matches('[data-sheet-rate-reason]')) {
                updateSheetRateUI();
                return;
            }
            if (target.matches('[data-rate-input]')) {
                markRateManual(target);
                var rateRow = target.closest('[data-item-row]');
                if (rateRow) { updateCardRateUI(rateRow); }
                return;
            }
            if (target.matches('[data-rate-reason]')) {
                var reasonRow = target.closest('[data-item-row]');
                if (reasonRow) { updateCardRateUI(reasonRow); }
                return;
            }
            if (target.matches('[name="item_name[]"]')) {
                var nameRow = target.closest('[data-item-row]');
                if (nameRow) { warnIfKgNameMismatch(nameRow); }
            }
            refresh();
        });

        picker.addEventListener('change', function (event) {
            var target = event.target;
            if (target.matches('[data-service-select]')) {
                var row = target.closest('[data-item-row]');
                if (row) {
                    var option = target.options[target.selectedIndex];
                    var mode = option && option.getAttribute('data-mode') === 'per_kg' ? 'per_kg' : 'per_piece';
                    var rate = option ? num(option.getAttribute('data-rate')) : 0;
                    // Keep the typed item name. Only the billing mode and the
                    // default rate follow the service, and a manual rate wins.
                    applyService(row, mode, rate, true);
                }
                refresh();
                return;
            }
            if (target.matches('input[name="sheet_service_id"]')) {
                syncSheetService();
                return;
            }
            refresh();
        });

        picker.addEventListener('keydown', function (event) {
            if (event.key !== 'Enter' || !sheet || sheet.hidden) { return; }
            if (!event.target.matches('[data-sheet-name], [data-sheet-rate], [data-sheet-qty], [data-sheet-weight]')) { return; }
            event.preventDefault();
            addFromSheet();
        });

        // Existing rows: restore the correct qty/weight fields and kg item list.
        picker.querySelectorAll('[data-item-row]').forEach(function (row) {
            var mode = row.getAttribute('data-service-mode') === 'per_kg' ? 'per_kg' : 'per_piece';
            applyService(row, mode, 0, false);
        });

        /* ---------- Draft autosave (customer booking / new order) ---------- */
        var draftKey = picker.getAttribute('data-draft-key') || '';
        var restoringDraft = false;

        function draftStorageKey() {
            return 'lf-item-draft:' + window.location.pathname + ':' + draftKey;
        }

        function draftEntries() {
            var entries = [];
            if (!list) { return entries; }
            list.querySelectorAll('[data-item-row]').forEach(function (row) {
                var nameInput = row.querySelector('[name="item_name[]"]');
                var name = nameInput ? nameInput.value.trim() : '';
                if (name === '') { return; }
                var select = row.querySelector('[data-service-select]');
                var qty = row.querySelector('[name="item_qty[]"]');
                var weight = row.querySelector('[name="item_weight[]"]');
                var rate = row.querySelector('[data-rate-input]');
                var reason = row.querySelector('[data-rate-reason]');
                var notes = row.querySelector('[name="item_notes[]"]');
                entries.push({
                    name: name,
                    service_id: select ? select.value : '',
                    mode: row.getAttribute('data-service-mode') === 'per_kg' ? 'per_kg' : 'per_piece',
                    qty: qty ? qty.value : 1,
                    weight: weight ? weight.value : 0,
                    rate: rate ? rate.value : 0,
                    catalog_rate: row.getAttribute('data-catalog-rate') || 0,
                    min_weight: row.getAttribute('data-min-weight') || 0,
                    reason: reason ? reason.value : '',
                    notes: notes ? notes.value : ''
                });
            });
            return entries;
        }

        function saveDraft() {
            if (!draftKey || restoringDraft) { return; }
            try {
                window.localStorage.setItem(draftStorageKey(), JSON.stringify({
                    at: Date.now(),
                    items: draftEntries()
                }));
            } catch (err) { /* private mode / quota - drafts are optional */ }
        }

        function clearDraft() {
            if (!draftKey) { return; }
            try { window.localStorage.removeItem(draftStorageKey()); } catch (err) {}
        }

        function showDraftNotice(count) {
            if (!list) { return; }
            if (list.previousElementSibling && list.previousElementSibling.hasAttribute('data-draft-notice')) {
                return;
            }
            var notice = document.createElement('div');
            notice.className = 'draft-notice';
            notice.setAttribute('data-draft-notice', '');
            var text = document.createElement('span');
            text.textContent = 'Restored ' + count + ' item(s) from your last visit.';
            var fresh = document.createElement('button');
            fresh.type = 'button';
            fresh.className = 'btn btn-sm btn-ghost';
            fresh.textContent = 'Start fresh';
            fresh.addEventListener('click', function () {
                clearDraft();
                window.location.reload();
            });
            notice.appendChild(text);
            notice.appendChild(fresh);
            list.parentNode.insertBefore(notice, list);
        }

        function restoreDraft() {
            if (!draftKey) { return; }
            var raw = null;
            try { raw = window.localStorage.getItem(draftStorageKey()); } catch (err) { return; }
            if (!raw) { return; }
            var data = null;
            try { data = JSON.parse(raw); } catch (err) { clearDraft(); return; }
            if (!data || !data.items || !data.items.length || !data.at) { return; }
            if (Date.now() - Number(data.at) > 24 * 60 * 60 * 1000) {
                clearDraft();
                return;
            }
            restoringDraft = true;
            var restored = 0;
            data.items.forEach(function (entry) {
                if (!entry || !entry.name) { return; }
                var card = addCard({
                    name: entry.name,
                    service_id: entry.service_id,
                    mode: entry.mode,
                    qty: entry.qty,
                    weight: entry.weight,
                    rate: entry.rate,
                    catalog_rate: entry.catalog_rate,
                    min_weight: entry.min_weight,
                    reason: entry.reason
                });
                if (card) {
                    var notes = card.querySelector('[name="item_notes[]"]');
                    if (notes && entry.notes) { notes.value = entry.notes; }
                    restored++;
                }
            });
            restoringDraft = false;
            if (restored > 0) { showDraftNotice(restored); }
        }

        restoreDraft();

        recalc(picker);
        var hostForm = picker.closest('form');
        if (hostForm) {
            recalc(hostForm);
            hostForm.addEventListener('submit', clearDraft);
        }
    });

    var buttons = document.querySelectorAll('[data-confirm]');
    buttons.forEach(function (button) {
        button.addEventListener('click', function (event) {
            if (!window.confirm(button.getAttribute('data-confirm'))) {
                event.preventDefault();
                return;
            }
            var second = button.getAttribute('data-confirm-2');
            if (second && !window.confirm(second)) {
                event.preventDefault();
            }
        });
    });

    var autoSubmit = document.querySelectorAll('[data-auto-submit]');
    autoSubmit.forEach(function (field) {
        field.addEventListener('change', function () {
            if (field.form) {
                field.form.submit();
            }
        });
    });

    function escapeHtml(value) {
        return String(value === null || value === undefined ? '' : value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    var lookupForms = document.querySelectorAll('[data-customer-lookup]');
    lookupForms.forEach(function (form) {
        var endpoint = form.getAttribute('data-lookup-url');
        var box = form.querySelector('[data-lookup-results]');
        var nameField = form.querySelector('[data-lookup-field="name"]');
        var phoneField = form.querySelector('[data-lookup-field="phone"]');
        var addressField = form.querySelector('[data-lookup-field="address"]');
        if (!endpoint || !box) { return; }

        var timer = null;
        var current = [];

        function render(results) {
            current = results || [];
            if (!current.length) {
                box.innerHTML = '';
                box.hidden = true;
                clearDuesPanel();
                return;
            }
            var html = '<div class="lookup-title">Existing customer found</div>';
            current.forEach(function (customer, index) {
                var duesLine = '';
                if (customer.dues > 0) {
                    duesLine = '<span class="badge badge-danger">Old dues ' + Number(customer.dues).toFixed(2)
                        + ' (' + customer.due_orders + ' invoice' + (customer.due_orders > 1 ? 's' : '') + ')</span>';
                    if (customer.last_due) {
                        duesLine += '<span class="subtle">Last pending: ' + escapeHtml(customer.last_due.order_number)
                            + ' Â· ' + Number(customer.last_due.amount).toFixed(2) + '</span>';
                    }
                }
                html += '<div class="lookup-item">'
                    + '<div class="lookup-main">'
                    + '<strong>' + escapeHtml(customer.name) + '</strong>'
                    + '<span class="subtle">' + escapeHtml(customer.phone) + (customer.address ? ' &middot; ' + escapeHtml(customer.address) : '') + '</span>'
                    + duesLine
                    + '</div>'
                    + '<button type="button" class="btn btn-sm btn-soft" data-use-customer="' + index + '">Use details</button>'
                    + '</div>';
            });
            box.innerHTML = html;
            box.hidden = false;
            // show dues for top match
            var first = current[0];
            if (first && typeof first.dues === 'number') {
                showDuesPanel(first);
            } else {
                clearDuesPanel();
            }
        }

        function showDuesPanel(customer) {
            var panel = document.getElementById('customer-dues-panel');
            if (!panel) { return; }
            var total = panel.querySelector('[data-customer-dues-total]');
            var count = panel.querySelector('[data-customer-dues-count]');
            var link = panel.querySelector('[data-customer-dues-link]');
            if (total) { total.textContent = Number(customer.dues || 0).toFixed(2); }
            if (count) { count.textContent = String(customer.due_orders || 0); }
            if (link) {
                var id = customer.id || '';
                if (id) { link.href = 'customer-lookup.php?customer_id=' + encodeURIComponent(id); } else if (customer.phone) { link.href = 'customers.php?q=' + encodeURIComponent(customer.phone); } else if (customer.name) { link.href = 'customers.php?q=' + encodeURIComponent(customer.name); }
                else { link.href = 'dues.php'; }
            }
            panel.hidden = !(customer.dues > 0);
        }

        function clearDuesPanel() {
            var panel = document.getElementById('customer-dues-panel');
            if (!panel) { return; }
            panel.hidden = true;
        }

        function search(term) {
            if (!term || term.length < 3) {
                render([]);
                clearDuesPanel();
                return;
            }
            fetch(endpoint + '?q=' + encodeURIComponent(term), { headers: { 'Accept': 'application/json' } })
                .then(function (response) { return response.json(); })
                .then(function (data) { render(data.results || []); })
                .catch(function () { render([]); clearDuesPanel(); });
        }

        function schedule() {
            window.clearTimeout(timer);
            timer = window.setTimeout(function () {
                var term = (phoneField && phoneField.value.trim().length >= 3) ? phoneField.value.trim() : (nameField ? nameField.value.trim() : '');
                search(term);
            }, 350);
        }

        if (phoneField) { phoneField.addEventListener('input', schedule); }
        if (nameField) { nameField.addEventListener('input', schedule); }

        box.addEventListener('click', function (event) {
            var button = event.target.closest('[data-use-customer]');
            if (!button) { return; }
            event.preventDefault();
            var customer = current[parseInt(button.getAttribute('data-use-customer'), 10)];
            if (!customer) { return; }
            if (nameField) { nameField.value = customer.name || ''; }
            if (phoneField) { phoneField.value = customer.phone || ''; }
            if (addressField && customer.address) { addressField.value = customer.address; }
            box.hidden = true;
            showDuesPanel(customer);
        });
    });

    var installButtons = document.querySelectorAll('[data-install-app]');
    var installPrompt = null;
    var installHint = 'Open the browser menu and choose "Add to Home screen" / "Install app". On iPhone or iPad, tap Share then "Add to Home Screen".';

    window.addEventListener('beforeinstallprompt', function (event) {
        event.preventDefault();
        installPrompt = event;
    });

    function isStandalone() {
        return window.matchMedia('(display-mode: standalone)').matches || window.navigator.standalone === true;
    }

    installButtons.forEach(function (button) {
        button.addEventListener('click', function () {
            if (isStandalone()) {
                window.alert('Laundry Friends is already installed on this device.');
                return;
            }
            if (!installPrompt) {
                window.alert(installHint);
                return;
            }
            installPrompt.prompt();
            installPrompt.userChoice.then(function () {
                installPrompt = null;
            });
        });
    });

    var folds = document.querySelectorAll('details.fold');
    folds.forEach(function (fold) {
        var summary = fold.querySelector('summary');
        if (!summary) { return; }
        summary.addEventListener('click', function () {
            window.setTimeout(function () {
                summary.classList.toggle('is-open', fold.open);
            }, 0);
        });
    });

})();
