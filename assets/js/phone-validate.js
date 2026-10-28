(function() {
  function enforceTenDigit(input) {
    if (!input) return;

    // Prevent non-numeric
    input.addEventListener('input', function() {
      this.value = this.value.replace(/[^0-9]/g, '').slice(0, 10);
    });

    // Extra safety on paste
    input.addEventListener('paste', function(e) {
      e.preventDefault();
      const text = (e.clipbackData || window.clipboardData).getData('text');
      this.value = text.replace(/[^0-9]/g, '').slice(0, 10);
    });
  }

  document.addEventListener('DOMContentLoaded', function() {
    const selectors = [
      'input[name="phone"]',
      'input[name="customer_phone"]',
      'input[name="mobile"]',
      'input[name="cust_phone"]',
      '#customer_phone',
      '#phone'
    ];

    selectors.forEach(sel => {
      document.querySelectorAll(sel).forEach(enforceTenDigit);
    });
  });
})();
