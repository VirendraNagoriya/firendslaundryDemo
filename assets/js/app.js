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

        function syncSheetService() {
            if (!sheet) { return; }
            var chip = selectedServiceChip();
            var mode = chipMode(chip);
            var qtyField = sheet.querySelector('[data-sheet-qty]');
            var weightField = sheet.querySelector('[data-sheet-weight]');
            var custom = sheet.querySelector('[data-custom-name]');
            var kgNote = sheet.querySelector('[data-kg-note]');
            var rateInput = sheet.querySelector('[data-sheet-rate]');
            var serviceId = chip ? chip.value : '';

            if (qtyField) { qtyField.hidden = mode === 'per_kg'; }
            if (weightField) { weightField.hidden = mode !== 'per_kg'; }
            if (custom) { custom.hidden = mode === 'per_kg'; }
            if (kgNote) { kgNote.hidden = mode !== 'per_kg'; }
            if (rateInput && chip) { rateInput.value = chip.getAttribute('data-rate') || 0; }

            var visible = 0;
            picker.querySelectorAll('[data-quick-item]').forEach(function (button) {
                var match = String(button.getAttribute('data-service-id')) === String(serviceId);
                button.hidden = !match;
                if (match) { visible++; }
            });
            var empty = picker.querySelector('[data-quick-empty]');
            if (empty) { empty.hidden = visible > 0; }

            var nameInput = sheet.querySelector('[data-sheet-name]');
            if (nameInput) { nameInput.value = ''; }
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

        function applyService(card, mode, rate, setRate) {
            var modeValue = card.querySelector('[data-mode-value]');
            if (modeValue) { modeValue.value = mode; }
            var serviceType = card.querySelector('[data-service-type]');
            var select = card.querySelector('[data-service-select]');
            if (serviceType && select && select.selectedIndex >= 0) {
                serviceType.value = select.options[select.selectedIndex].getAttribute('data-type') || '';
            }
            var qtyField = card.querySelector('[data-field-qty]');
            var weightField = card.querySelector('[data-field-weight]');
            if (qtyField) { qtyField.hidden = mode === 'per_kg'; }
            if (weightField) { weightField.hidden = mode !== 'per_kg'; }
            card.setAttribute('data-service-mode', mode);
            if (setRate) {
                var rateInput = card.querySelector('[data-rate-input]');
                if (rateInput) { rateInput.value = rate; }
            }
            syncServiceDatalist(card, mode);
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
            if (rate) { rate.value = data.rate === undefined ? 0 : data.rate; }
            applyService(card, mode, data.rate === undefined ? 0 : data.rate, false);
        }

        function addCard(data) {
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

            // Set default mode to item-first
            setSelectionMode('item-first');

            syncSheetService();
            sheet.hidden = false;
            if (backdrop) { backdrop.hidden = false; }
            document.body.classList.add('sheet-open');
        }

        function setSelectionMode(mode) {
            // Update toggle buttons
            var toggleButtons = sheet.querySelectorAll('.mode-btn');
            toggleButtons.forEach(function(btn) {
                if (btn.dataset.mode === mode) {
                    btn.classList.add('active');
                } else {
                    btn.classList.remove('active');
                }
            });

            // Show/hide sections based on mode
            var serviceSections = sheet.querySelectorAll('.service-first-mode');
            var itemSections = sheet.querySelectorAll('.item-first-mode');

            if (mode === 'service-first') {
                serviceSections.forEach(function(section) {
                    section.classList.add('active');
                });
                itemSections.forEach(function(section) {
                    section.classList.remove('active');
                });
            } else {
                serviceSections.forEach(function(section) {
                    section.classList.remove('active');
                });
                itemSections.forEach(function(section) {
                    section.classList.add('active');
                });
            }
        }

        function closeSheet() {
            if (!sheet) { return; }
            sheet.hidden = true;
            if (backdrop) { backdrop.hidden = true; }
            document.body.classList.remove('sheet-open');
        }

        function addFromSheet() {
            if (!sheet) { return; }
            var nameInput = sheet.querySelector('[data-sheet-name]');
            var rateInput = sheet.querySelector('[data-sheet-rate]');
            var qtyInput = sheet.querySelector('[data-sheet-qty]');
            var weightInput = sheet.querySelector('[data-sheet-weight]');
            var chip = selectedServiceChip();
            var mode = chipMode(chip);
            var serviceId = chip ? chip.value : '';
            var name = nameInput ? nameInput.value.trim() : '';
            var extraChargeAmount = num(sheet.querySelector('[data-extra-charge-amount]') ? sheet.querySelector('[data-extra-charge-amount]').value : 0);
            var extraChargeNotes = sheet.querySelector('[data-extra-charge-notes]') ? sheet.querySelector('[data-extra-charge-notes]').value : '';

            if (mode === 'per_kg') {
                var match = null;
                catalogForService(serviceId).forEach(function (entry) {
                    if (!match && entry.name.toLowerCase() === name.toLowerCase()) { match = entry; }
                });
                if (!match) {
                    var first = picker.querySelector('[data-quick-item][data-service-id="' + serviceId + '"]');
                    if (first && first.scrollIntoView) {
                        first.scrollIntoView({ behavior: 'smooth', block: 'center' });
                    }
                    return;
                }
                name = match.name;
            } else if (name === '') {
                if (nameInput) { nameInput.focus(); }
                return;
            }

            var card = addCard({
                name: name,
                service_id: serviceId,
                mode: mode,
                qty: num(qtyInput ? qtyInput.value : 1) || 1,
                weight: num(weightInput ? weightInput.value : 1) || 1,
                rate: num(rateInput ? rateInput.value : 0),
                extra_charge: extraChargeAmount > 0 ? {
                    amount: extraChargeAmount,
                    notes: extraChargeNotes
                } : null
            });

            // Show confirmation message
            var serviceName = chip ? chip.nextElementSibling.textContent : name;
            showNotification('Item added: ' + name + ' (' + serviceName + ')');

            closeSheet();
            if (card && card.scrollIntoView) {
                card.scrollIntoView({ behavior: 'smooth', block: 'center' });
            }
        }

        function applyCatalogDefaults(nameInput) {
            var term = nameInput.value.trim().toLowerCase();
            if (term === '') { return; }
            var chip = selectedServiceChip();
            var serviceId = chip ? chip.value : '';
            var found = null;
            catalog.forEach(function (entry) {
                if (!found && entry.name.toLowerCase() === term && String(entry.service_id) === String(serviceId)) {
                    found = entry;
                }
            });
            if (!found || !sheet) { return; }
            var rate = sheet.querySelector('[data-sheet-rate]');
            if (rate) { rate.value = found.rate; }
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
                    service_id: pick.service_id,
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
            if (target.matches('[data-service-select]')) {
                var row = target.closest('[data-item-row]');
                if (row) {
                    var option = target.options[target.selectedIndex];
                    var mode = option && option.getAttribute('data-mode') === 'per_kg' ? 'per_kg' : 'per_piece';
                    var rate = option ? num(option.getAttribute('data-rate')) : 0;
                    var nameInput = row.querySelector('[name="item_name[]"]');
                    if (nameInput) { nameInput.value = ''; }
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

    // Setup mode toggle buttons
    document.querySelectorAll('.mode-btn').forEach(function(btn) {
        btn.addEventListener('click', function() {
            setSelectionMode(this.dataset.mode);
        });
    });

    // Item picker initialization
    document.querySelectorAll('[data-item-picker]').forEach(function(picker) {
        var list = picker.querySelector('[data-item-list]');
        var addButton = picker.querySelector('[data-add-item]');
        var sheet = picker.querySelector('[data-sheet]');
        var backdrop = picker.querySelector('[data-sheet-backdrop]');
        var template = picker.querySelector('[data-item-template]');
        var catalogJson = picker.querySelector('[data-catalog]');
        var serviceDatalist = document.createElement('datalist');
        serviceDatalist.id = 'service-datalist-' + Math.random().toString(36).substr(2, 9);
        document.body.appendChild(serviceDatalist);
        
        if (!list || !addButton || !sheet || !backdrop || !template || !catalogJson) {
            return;
        }
        
        var catalog = JSON.parse(catalogJson.textContent);
        var items = [];
        var catalogListId = 'catalog-list-' + Math.random().toString(36).substr(2, 9);
        
        // Add event listeners
        addButton.addEventListener('click', openSheet);
        backdrop.addEventListener('click', closeSheet);
        sheet.querySelector('[data-sheet-close]').addEventListener('click', closeSheet);
        
        // Add service selection
        var serviceChips = '';
        var services = {};
        catalog.forEach(function(item) {
            if (!services[item.service_id]) {
                services[item.service_id] = {
                    id: item.service_id,
                    name: item.service,
                    rate: item.rate,
                    mode: item.mode
                };
                serviceChips += '<button type="button" class="chip" data-mode="' + item.mode + '" data-rate="' + item.rate + '" data-service-id="' + item.service_id + '">' + item.service + '</button>';
            }
        });
        
        var serviceChipContainer = sheet.querySelector('.chip-group');
        if (serviceChipContainer) {
            serviceChipContainer.innerHTML = serviceChips;
            serviceChipContainer.addEventListener('click', function(event) {
                var chip = event.target.closest('.chip');
                if (!chip) { return; }
                serviceChipContainer.querySelectorAll('.chip').forEach(function(c) {
                    c.classList.remove('active');
                });
                chip.classList.add('active');
                syncSheetService();
            });
        }
        
        // Add item selection
        var quickItems = '';
        catalog.forEach(function(item) {
            quickItems += '<button type="button" class="chip" data-quick-item data-service-id="' + item.service_id + '">' + item.name + '</button>';
        });
        
        var quickItemContainer = sheet.querySelector('.item-first-mode .chip-group');
        if (quickItemContainer) {
            quickItemContainer.innerHTML = quickItems;
            quickItemContainer.addEventListener('click', function(event) {
                var chip = event.target.closest('.chip');
                if (!chip || !chip.hasAttribute('data-quick-item')) { return; }
                var serviceId = chip.getAttribute('data-service-id');
                var name = chip.textContent.trim();
                
                // Set service chip
                var serviceChip = serviceChipContainer.querySelector('.chip[data-service-id="' + serviceId + '"]');
                if (serviceChip) {
                    serviceChipContainer.querySelectorAll('.chip').forEach(function(c) {
                        c.classList.remove('active');
                    });
                    serviceChip.classList.add('active');
                }
                
                // Set item name
                var nameInput = sheet.querySelector('[data-sheet-name]');
                if (nameInput) {
                    nameInput.value = name;
                }
                
                syncSheetService();
            });
        }
        
        // Add submit button
        var submitButton = sheet.querySelector('[data-sheet-add]');
        if (submitButton) {
            submitButton.addEventListener('click', addFromSheet);
        }
        
        // Initialize with first service selected
        var firstService = serviceChipContainer.querySelector('.chip');
        if (firstService) {
            firstService.classList.add('active');
        }
        
        function catalogForService(serviceId) {
            return catalog.filter(function(item) {
                return String(item.service_id) === String(serviceId);
            });
        }
        
        function selectedServiceChip() {
            return serviceChipContainer ? serviceChipContainer.querySelector('.chip.active') : null;
        }
        
        function syncSheetService() {
            if (!sheet) { return; }
            var chip = selectedServiceChip();
            var mode = chipMode(chip);
            var qtyField = sheet.querySelector('[data-sheet-qty]');
            var weightField = sheet.querySelector('[data-sheet-weight]');
            var custom = sheet.querySelector('[data-custom-name]');
            var kgNote = sheet.querySelector('[data-kg-note]');
            var rateInput = sheet.querySelector('[data-sheet-rate]');
            var serviceId = chip ? chip.value : '';
            
            if (qtyField) { qtyField.hidden = mode === 'per_kg'; }
            if (weightField) { weightField.hidden = mode !== 'per_kg'; }
            if (custom) { custom.hidden = mode === 'per_kg'; }
            if (kgNote) { kgNote.hidden = mode !== 'per_kg'; }
            if (rateInput && chip) { rateInput.value = chip.getAttribute('data-rate') || 0; }
            
            var visible = 0;
            picker.querySelectorAll('[data-quick-item]').forEach(function(button) {
                var match = String(button.getAttribute('data-service-id')) === String(serviceId);
                button.hidden = !match;
                if (match) { visible++; }
            });
            var empty = picker.querySelector('[data-quick-empty]');
            if (empty) { empty.hidden = visible > 0; }
            
            var nameInput = sheet.querySelector('[data-sheet-name]');
            if (nameInput) { nameInput.value = ''; }
        }
        
        function openSheet() {
            if (!sheet) { return; }
            var qtyInput = sheet.querySelector('[data-sheet-qty]');
            var weightInput = sheet.querySelector('[data-sheet-weight]');
            if (qtyInput) { qtyInput.value = 1; }
            if (weightInput) { weightInput.value = 1; }
            
            // Set default mode to item-first
            setSelectionMode('item-first');
            
            syncSheetService();
            sheet.hidden = false;
            if (backdrop) { backdrop.hidden = false; }
            document.body.classList.add('sheet-open');
        }
        
        function closeSheet() {
            if (!sheet) { return; }
            sheet.hidden = true;
            if (backdrop) { backdrop.hidden = true; }
            document.body.classList.remove('sheet-open');
        }
        
        function setSelectionMode(mode) {
            // Update toggle buttons
            var toggleButtons = sheet.querySelectorAll('.mode-btn');
            toggleButtons.forEach(function(btn) {
                if (btn.dataset.mode === mode) {
                    btn.classList.add('active');
                } else {
                    btn.classList.remove('active');
                }
            });
            
            // Show/hide sections based on mode
            var serviceSections = sheet.querySelectorAll('.service-first-mode');
            var itemSections = sheet.querySelectorAll('.item-first-mode');
            
            if (mode === 'service-first') {
                serviceSections.forEach(function(section) {
                    section.classList.add('active');
                });
                itemSections.forEach(function(section) {
                    section.classList.remove('active');
                });
            } else {
                serviceSections.forEach(function(section) {
                    section.classList.remove('active');
                });
                itemSections.forEach(function(section) {
                    section.classList.add('active');
                });
            }
        }
        
        function chipMode(chip) {
            return chip && chip.getAttribute('data-mode') === 'per_kg' ? 'per_kg' : 'per_piece';
        }
        
        function addFromSheet() {
            if (!sheet) { return; }
            var nameInput = sheet.querySelector('[data-sheet-name]');
            var rateInput = sheet.querySelector('[data-sheet-rate]');
            var qtyInput = sheet.querySelector('[data-sheet-qty]');
            var weightInput = sheet.querySelector('[data-sheet-weight]');
            var chip = selectedServiceChip();
            var mode = chipMode(chip);
            var serviceId = chip ? chip.value : '';
            var name = nameInput ? nameInput.value.trim() : '';
            var extraChargeAmount = num(sheet.querySelector('[data-extra-charge-amount]') ? sheet.querySelector('[data-extra-charge-amount]').value : 0);
            var extraChargeNotes = sheet.querySelector('[data-extra-charge-notes]') ? sheet.querySelector('[data-extra-charge-notes]').value : '';
            
            if (mode === 'per_kg') {
                name = nameInput ? nameInput.value.trim() : '';
                if (name === '') {
                    if (nameInput) { nameInput.focus(); }
                    return;
                }
                
                // Try to match catalog item
                var match = null;
                catalogForService(serviceId).forEach(function (entry) {
                    if (!match && entry.name.toLowerCase() === name.toLowerCase()) { match = entry; }
                });
                if (!match) {
                    var first = picker.querySelector('[data-quick-item][data-service-id="' + serviceId + '"]');
                    if (first && first.scrollIntoView) {
                        first.scrollIntoView({ behavior: 'smooth', block: 'center' });
                    }
                    return;
                }
                name = match.name;
            } else if (name === '') {
                if (nameInput) { nameInput.focus(); }
                return;
            }
            
            var card = addCard({
                name: name,
                service_id: serviceId,
                mode: mode,
                qty: num(qtyInput ? qtyInput.value : 1) || 1,
                weight: num(weightInput ? weightInput.value : 1) || 1,
                rate: num(rateInput ? rateInput.value : 0),
                extra_charge: extraChargeAmount > 0 ? {
                    amount: extraChargeAmount,
                    notes: extraChargeNotes
                } : null
            });
            
            // Show confirmation message
            var serviceName = chip ? chip.nextElementSibling.textContent : name;
            showNotification('Item added: ' + name + ' (' + serviceName + ')');
            
            closeSheet();
            if (card && card.scrollIntoView) {
                card.scrollIntoView({ behavior: 'smooth', block: 'center' });
            }
        }
        
        function addCard(data) {
            var card = template.content.cloneNode(true);
            if (!card || !list) { return null; }
            
            // Set values
            var nameInput = card.querySelector('[name="item_name[]"]');
            if (nameInput) { nameInput.value = data.name; }
            
            var serviceSelect = card.querySelector('[data-service-select]');
            if (serviceSelect && data.service_id) { serviceSelect.value = String(data.service_id); }
            
            var mode = data.mode === 'per_kg' ? 'per_kg' : 'per_piece';
            var qty = card.querySelector('[name="item_qty[]"]');
            if (qty) { qty.value = data.qty === undefined ? 1 : data.qty; }
            
            var weight = card.querySelector('[name="item_weight[]"]');
            if (weight) { weight.value = mode === 'per_kg' ? (data.weight === undefined ? 1 : data.weight) : 0; }
            
            var rate = card.querySelector('[name="item_rate[]"]');
            if (rate) { rate.value = data.rate === undefined ? 0 : data.rate; }
            
            applyService(card, mode, data.rate === undefined ? 0 : data.rate, false);
            
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
        
        function applyService(card, mode, rate, setRate) {
            var modeValue = card.querySelector('[data-mode-value]');
            if (modeValue) { modeValue.value = mode; }
            
            var serviceType = card.querySelector('[data-service-type]');
            var select = card.querySelector('[data-service-select]');
            if (serviceType && select && select.selectedIndex >= 0) {
                serviceType.value = select.options[select.selectedIndex].getAttribute('data-type') || '';
            }
            
            var qtyField = card.querySelector('[data-field-qty]');
            var weightField = card.querySelector('[data-field-weight]');
            if (qtyField) { qtyField.hidden = mode === 'per_kg'; }
            if (weightField) { weightField.hidden = mode !== 'per_kg'; }
            
            card.setAttribute('data-service-mode', mode);
            if (setRate) {
                var rateInput = card.querySelector('[data-rate-input]');
                if (rateInput) { rateInput.value = rate; }
            }
            syncServiceDatalist(card, mode);
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
        
        function refresh() {
            var subtotal = 0;
            list.querySelectorAll('.item-row').forEach(function(row) {
                var qty = parseFloat(row.querySelector('[name="item_qty[]"]')?.value || 0);
                var weight = parseFloat(row.querySelector('[name="item_weight[]"]')?.value || 0);
                var rate = parseFloat(row.querySelector('[name="item_rate[]"]')?.value || 0);
                var amount = row.getAttribute('data-service-mode') === 'per_kg' ? weight * rate : qty * rate;
                
                var amountCell = row.querySelector('[data-amount]');
                if (amountCell) {
                    amountCell.textContent = amount.toFixed(2);
                }
                
                subtotal += amount;
            });
            
            var subtotalOutput = picker.querySelector('[data-subtotal]');
            if (subtotalOutput) {
                subtotalOutput.textContent = subtotal.toFixed(2);
            }
            
            var totalOutput = picker.querySelector('[data-total]');
            if (totalOutput) {
                var deliveryCharge = parseFloat(picker.querySelector('[name="delivery_charge"]')?.value || 0);
                var taxPercent = parseFloat(picker.getAttribute('data-tax-percent') || 0);
                var tax = (subtotal + deliveryCharge) * (taxPercent / 100);
                var total = subtotal + deliveryCharge + tax;
                totalOutput.textContent = total.toFixed(2);
            }
            
            var countOutput = picker.querySelector('[data-item-count]');
            if (countOutput) {
                countOutput.textContent = list.querySelectorAll('.item-row').length + ' items';
            }
        }
    });

    // Item picker initialization
    document.querySelectorAll('[data-item-picker]').forEach(function(picker) {
        var list = picker.querySelector('[data-item-list]');
        var addButton = picker.querySelector('[data-add-item]');
        var sheet = picker.querySelector('[data-sheet]');
        var backdrop = picker.querySelector('[data-sheet-backdrop]');
        var template = picker.querySelector('[data-item-template]');
        var catalogJson = picker.querySelector('[data-catalog]');
        var serviceDatalist = document.createElement('datalist');
        serviceDatalist.id = 'service-datalist-' + Math.random().toString(36).substr(2, 9);
        document.body.appendChild(serviceDatalist);
        
        if (!list || !addButton || !sheet || !backdrop || !template || !catalogJson) {
            return;
        }
        
        var catalog = JSON.parse(catalogJson.textContent);
        var items = [];
        var catalogListId = 'catalog-list-' + Math.random().toString(36).substr(2, 9);
        
        // Add event listeners
        addButton.addEventListener('click', openSheet);
        backdrop.addEventListener('click', closeSheet);
        sheet.querySelector('[data-sheet-close]').addEventListener('click', closeSheet);
        
        // Add service selection
        var serviceChips = '';
        var services = {};
        catalog.forEach(function(item) {
            if (!services[item.service_id]) {
                services[item.service_id] = {
                    id: item.service_id,
                    name: item.service_name,
                    rate: item.service_rate,
                    mode: item.service_price_mode
                };
                serviceChips += '<button type="button" class="chip" data-mode="' + item.service_price_mode + '" data-rate="' + item.service_rate + '" data-service-id="' + item.service_id + '">' + item.service_name + '</button>';
            }
        });
        
        var serviceChipContainer = sheet.querySelector('.chip-group');
        if (serviceChipContainer) {
            serviceChipContainer.innerHTML = serviceChips;
            serviceChipContainer.addEventListener('click', function(event) {
                var chip = event.target.closest('.chip');
                if (!chip) { return; }
                serviceChipContainer.querySelectorAll('.chip').forEach(function(c) {
                    c.classList.remove('active');
                });
                chip.classList.add('active');
                syncSheetService();
            });
        }
        
        // Add item selection
        var quickItems = '';
        catalog.forEach(function(item) {
            quickItems += '<button type="button" class="chip" data-quick-item data-service-id="' + item.service_id + '">' + item.item_name + '</button>';
        });
        
        var quickItemContainer = sheet.querySelector('.item-first-mode .chip-group');
        if (quickItemContainer) {
            quickItemContainer.innerHTML = quickItems;
            quickItemContainer.addEventListener('click', function(event) {
                var chip = event.target.closest('.chip');
                if (!chip || !chip.hasAttribute('data-quick-item')) { return; }
                var serviceId = chip.getAttribute('data-service-id');
                var name = chip.textContent.trim();
                
                // Set service chip
                var serviceChip = serviceChipContainer.querySelector('.chip[data-service-id="' + serviceId + '"]');
                if (serviceChip) {
                    serviceChipContainer.querySelectorAll('.chip').forEach(function(c) {
                        c.classList.remove('active');
                    });
                    serviceChip.classList.add('active');
                }
                
                // Set item name
                var nameInput = sheet.querySelector('[data-sheet-name]');
                if (nameInput) {
                    nameInput.value = name;
                }
                
                syncSheetService();
            });
        }
        
        // Add submit button
        var submitButton = sheet.querySelector('[data-sheet-submit]');
        if (submitButton) {
            submitButton.addEventListener('click', addFromSheet);
        }
        
        // Initialize with first service selected
        var firstService = serviceChipContainer.querySelector('.chip');
        if (firstService) {
            firstService.classList.add('active');
        }
        
        function catalogForService(serviceId) {
            return catalog.filter(function(item) {
                return String(item.service_id) === String(serviceId);
            });
        }
        
        function selectedServiceChip() {
            return serviceChipContainer ? serviceChipContainer.querySelector('.chip.active') : null;
        }
        
        function refresh() {
            var subtotal = 0;
            list.querySelectorAll('.item-row').forEach(function(row) {
                var qty = parseFloat(row.querySelector('[name="item_qty[]"]')?.value || 0);
                var weight = parseFloat(row.querySelector('[name="item_weight[]"]')?.value || 0);
                var rate = parseFloat(row.querySelector('[name="item_rate[]"]')?.value || 0);
                var amount = row.getAttribute('data-service-mode') === 'per_kg' ? weight * rate : qty * rate;
                
                var amountCell = row.querySelector('[data-amount]');
                if (amountCell) {
                    amountCell.textContent = amount.toFixed(2);
                }
                
                subtotal += amount;
            });
            
            var subtotalOutput = picker.querySelector('[data-subtotal]');
            if (subtotalOutput) {
                subtotalOutput.textContent = subtotal.toFixed(2);
            }
            
            var totalOutput = picker.querySelector('[data-total]');
            if (totalOutput) {
                var deliveryCharge = parseFloat(picker.querySelector('[name="delivery_charge"]')?.value || 0);
                var taxPercent = parseFloat(picker.getAttribute('data-tax-percent') || 0);
                var tax = (subtotal + deliveryCharge) * (taxPercent / 100);
                var total = subtotal + deliveryCharge + tax;
                totalOutput.textContent = total.toFixed(2);
            }
            
            var countOutput = picker.querySelector('[data-item-count]');
            if (countOutput) {
                countOutput.textContent = list.querySelectorAll('.item-row').length + ' items';
            }
        }
    });
})();
