(function () {
    'use strict';

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

    function num(value) {
        var parsed = parseFloat(value);
        return isNaN(parsed) ? 0 : parsed;
    }

    function fieldValue(scope, selector) {
        var el = scope.querySelector(selector);
        return el ? num(el.value) : 0;
    }

    function rowInfo(row) {
        var mode = 'per_piece';
        var group = row.querySelector('[data-mode-group]');
        if (group) {
            var checked = group.querySelector('input:checked');
            mode = checked ? checked.value : 'per_piece';
        } else {
            var select = row.querySelector('select[name^="item_mode"]');
            if (select) { mode = select.value; }
        }
        return {
            qty: fieldValue(row, '[name="item_qty[]"], [name^="item_qty"]'),
            kg: fieldValue(row, '[name="item_weight[]"], [name^="item_weight"]'),
            rate: fieldValue(row, '[name="item_rate[]"], [name^="item_rate"]'),
            mode: mode
        };
    }

    function serviceLabel(row) {
        var select = row.querySelector('select[name^="item_service_type"]');
        if (!select || select.selectedIndex < 0) { return ''; }
        return select.options[select.selectedIndex].text;
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
        var counter = 5000 + pickerIndex * 100;
        var catalog = [];
        var catalogNode = picker.querySelector('[data-catalog]');
        if (catalogNode) {
            try { catalog = JSON.parse(catalogNode.textContent || '[]'); } catch (err) { catalog = []; }
        }

        function refresh() {
            recalc(picker);
        }

        function showEmptyState() {
            if (!list) { return; }
            var empty = list.querySelector('[data-item-empty]');
            if (!empty) {
                empty = document.createElement('div');
                empty.className = 'empty-state item-empty';
                empty.setAttribute('data-item-empty', '');
                empty.innerHTML = '<strong>No items added yet</strong>Tap <b>+ Add item</b> below - pick a service from the list or type your own.';
                list.appendChild(empty);
            }
        }

        function hideEmptyState() {
            var empty = list ? list.querySelector('[data-item-empty]') : null;
            if (empty) { empty.remove(); }
        }

        function newCard() {
            if (!template) { return null; }
            var card = template.content.firstElementChild.cloneNode(true);
            counter += 1;
            card.querySelectorAll('[data-mode-group] input').forEach(function (input) {
                input.name = 'mode_pick_' + counter;
            });
            return card;
        }

        function setCardValues(card, data) {
            var nameInput = card.querySelector('[name="item_name[]"]');
            if (nameInput) { nameInput.value = data.name || ''; }
            var serviceSelect = card.querySelector('select[name="item_service_type[]"]');
            if (serviceSelect && data.service) { serviceSelect.value = data.service; }
            var mode = data.mode === 'per_kg' ? 'per_kg' : 'per_piece';
            card.querySelectorAll('[data-mode-group] input').forEach(function (input) {
                input.checked = input.value === mode;
            });
            var modeValue = card.querySelector('[data-mode-value]');
            if (modeValue) { modeValue.value = mode; }
            var qty = card.querySelector('[name="item_qty[]"]');
            if (qty) { qty.value = data.qty === undefined ? 1 : data.qty; }
            var weight = card.querySelector('[name="item_weight[]"]');
            if (weight) { weight.value = mode === 'per_kg' ? (data.weight === undefined ? 1 : data.weight) : 0; }
            var rate = card.querySelector('[name="item_rate[]"]');
            if (rate) { rate.value = data.rate === undefined ? 0 : data.rate; }
            toggleModeFields(card, mode);
        }

        function toggleModeFields(card, mode) {
            var qtyField = card.querySelector('[data-field-qty]');
            var weightField = card.querySelector('[data-field-weight]');
            if (qtyField) { qtyField.hidden = mode === 'per_kg'; }
            if (weightField) { weightField.hidden = mode !== 'per_kg'; }
        }

        function addCard(data) {
            var card = newCard();
            if (!card || !list) { return null; }
            setCardValues(card, data);
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
            var nameInput = sheet.querySelector('[data-sheet-name]');
            var rateInput = sheet.querySelector('[data-sheet-rate]');
            var qtyInput = sheet.querySelector('[data-sheet-qty]');
            var weightInput = sheet.querySelector('[data-sheet-weight]');
            if (nameInput) { nameInput.value = ''; }
            if (rateInput) { rateInput.value = ''; }
            if (qtyInput) { qtyInput.value = 1; }
            if (weightInput) { weightInput.value = 1; }
            var service = sheet.querySelector('input[name="sheet_service"]:checked');
            if (service) { service.checked = true; }
            var mode = sheet.querySelector('input[name="sheet_mode"]:checked');
            if (mode) { mode.checked = true; }
            var modeValue = mode ? mode.value : 'per_piece';
            var qtyField = sheet.querySelector('[data-sheet-qty]');
            var weightField = sheet.querySelector('[data-sheet-weight]');
            if (qtyField) { qtyField.hidden = modeValue === 'per_kg'; }
            if (weightField) { weightField.hidden = modeValue !== 'per_kg'; }
            sheet.hidden = false;
            if (backdrop) { backdrop.hidden = false; }
            document.body.classList.add('sheet-open');
            if (nameInput) { window.setTimeout(function () { nameInput.focus(); }, 60); }
        }

        function closeSheet() {
            if (!sheet) { return; }
            sheet.hidden = true;
            if (backdrop) { backdrop.hidden = true; }
            document.body.classList.remove('sheet-open');
        }

        function sheetMode() {
            var mode = sheet ? sheet.querySelector('input[name="sheet_mode"]:checked') : null;
            return mode ? mode.value : 'per_piece';
        }

        function addFromSheet() {
            if (!sheet) { return; }
            var nameInput = sheet.querySelector('[data-sheet-name]');
            var rateInput = sheet.querySelector('[data-sheet-rate]');
            var qtyInput = sheet.querySelector('[data-sheet-qty]');
            var weightInput = sheet.querySelector('[data-sheet-weight]');
            var service = sheet.querySelector('input[name="sheet_service"]:checked');
            var name = nameInput ? nameInput.value.trim() : '';
            if (name === '') {
                if (nameInput) { nameInput.focus(); }
                return;
            }
            var mode = sheetMode();
            var data = {
                name: name,
                service: service ? service.value : 'wash_fold',
                mode: mode,
                qty: num(qtyInput ? qtyInput.value : 1) || 1,
                weight: num(weightInput ? weightInput.value : 1) || 1,
                rate: num(rateInput ? rateInput.value : 0)
            };
            var card = addCard(data);
            closeSheet();
            if (card && card.scrollIntoView) {
                card.scrollIntoView({ behavior: 'smooth', block: 'center' });
            }
        }

        function applyCatalogDefaults(nameInput) {
            var term = nameInput.value.trim().toLowerCase();
            if (term === '') { return; }
            var found = null;
            catalog.forEach(function (entry) {
                if (!found && entry.name.toLowerCase() === term) { found = entry; }
            });
            if (!found || !sheet) { return; }
            var service = sheet.querySelector('input[name="sheet_service"][value="' + found.service + '"]');
            if (service) { service.checked = true; }
            var mode = sheet.querySelector('input[name="sheet_mode"][value="' + found.mode + '"]');
            if (mode) { mode.checked = true; }
            var rate = sheet.querySelector('[data-sheet-rate]');
            if (rate && num(rate.value) === 0) { rate.value = found.rate; }
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
                var card = addCard({
                    name: pick.name,
                    service: pick.service,
                    mode: pick.mode,
                    qty: 1,
                    weight: 1,
                    rate: pick.rate
                });
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
            if (event.target.matches('[data-sheet-name]')) {
                applyCatalogDefaults(event.target);
                return;
            }
            refresh();
        });

        picker.addEventListener('change', function (event) {
            var target = event.target;
            if (target.matches('[data-mode-group] input')) {
                var card = target.closest('[data-item-row]');
                if (card) {
                    var modeValue = card.querySelector('[data-mode-value]');
                    if (modeValue) { modeValue.value = target.value; }
                    toggleModeFields(card, target.value);
                }
                refresh();
                return;
            }
            if (target.matches('input[name="sheet_mode"]')) {
                var sheetQ = picker.querySelector('[data-sheet-qty]');
                var sheetW = picker.querySelector('[data-sheet-weight]');
                if (sheetQ) { sheetQ.hidden = target.value === 'per_kg'; }
                if (sheetW) { sheetW.hidden = target.value !== 'per_kg'; }
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

        recalc(picker);
        var hostForm = picker.closest('form');
        if (hostForm) { recalc(hostForm); }
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
                            + ' · ' + Number(customer.last_due.amount).toFixed(2) + '</span>';
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

    var installButton = document.querySelector('[data-install-app]');
    var installPrompt = null;
    window.addEventListener('beforeinstallprompt', function (event) {
        event.preventDefault();
        installPrompt = event;
        if (installButton) { installButton.hidden = false; }
    });
    if (installButton) {
        installButton.addEventListener('click', function () {
            if (!installPrompt) { return; }
            installPrompt.prompt();
            installPrompt.userChoice.then(function () {
                installPrompt = null;
                installButton.hidden = true;
            });
        });
    }

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
