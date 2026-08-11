(function (Drupal, once) {
  'use strict';

  function decimalParts(value) {
    const parts = value.split('.');
    return {
      digits: BigInt((parts[0] || '0') + (parts[1] || '')),
      scale: (parts[1] || '').length,
    };
  }

  function multiply(left, right) {
    const first = decimalParts(left);
    const second = decimalParts(right);
    return {
      digits: first.digits * second.digits,
      scale: first.scale + second.scale,
    };
  }

  function add(left, right) {
    const scale = Math.max(left.scale, right.scale);
    return {
      digits: left.digits * (10n ** BigInt(scale - left.scale))
        + right.digits * (10n ** BigInt(scale - right.scale)),
      scale,
    };
  }

  function format(value) {
    let digits = value.digits.toString();
    if (value.scale === 0) {
      return digits;
    }
    digits = digits.padStart(value.scale + 1, '0');
    const whole = digits.slice(0, -value.scale);
    const decimals = digits.slice(-value.scale).replace(/0+$/, '');
    return decimals === '' ? whole : `${whole}.${decimals}`;
  }

  Drupal.behaviors.commerceNovaPayRefund = {
    attach(context) {
      once('commerce-novapay-refund', '.commerce-novapay-refund-items', context)
        .forEach((table) => {
          const inputs = table.querySelectorAll(
            '.commerce-novapay-refund-quantity',
          );
          const total = table.closest('form')
            .querySelector('.commerce-novapay-refund-total');
          const update = () => {
            let sum = {digits: 0n, scale: 0};
            let currency = '';
            inputs.forEach((input) => {
              currency = input.dataset.currency || currency;
              const quantity = /^\d+(?:\.\d+)?$/.test(input.value)
                ? input.value
                : '0';
              const line = multiply(
                input.dataset.unitPrice || '0',
                quantity,
              );
              sum = add(sum, line);
              input.closest('tr')
                .querySelector('.commerce-novapay-refund-line-total')
                .textContent = `${format(line)} ${currency}`;
            });
            if (total) {
              total.textContent = `${format(sum)} ${currency}`;
            }
          };
          inputs.forEach((input) => input.addEventListener('input', update));
          update();
        });
    },
  };
})(Drupal, once);
