(() => {
    'use strict';

    const invalidFormat = 'Indica um valor válido com até duas casas decimais, por exemplo 90,50. Para milhares usa 1.234,56.';

    // Strings de cêntimos permitem comparar montantes sem erros de vírgula flutuante.
    const cents = (integer, decimals) => (integer + decimals.padEnd(2, '0')).replace(/^0+/, '') || '0';
    const canonical = (integer, decimals) => (integer.replace(/^0+(?=\d)/, '') || '0') + '.' + decimals.padEnd(2, '0');
    const greater = (left, right) => left.length !== right.length ? left.length > right.length : left > right;

    function validate(input, maximum) {
        const value = String(input).trim();
        if (value.length > 40 || !/^(?:\d+(?:[.,]\d{1,2})?|\d{1,3}(?:\.\d{3})+,\d{1,2})$/.test(value)) {
            return { ok: false, error: invalidFormat };
        }

        const normalized = value.includes(',') ? value.replace(/\./g, '').replace(',', '.') : value;
        const [integer, decimals = ''] = normalized.split('.');
        const amountCents = cents(integer, decimals);
        if (amountCents === '0') return { ok: false, error: 'O valor deve ser superior a zero.' };

        const max = String(maximum);
        if (!/^\d+\.\d{2}$/.test(max) || max.length > 40) {
            return { ok: false, error: 'Não foi possível verificar o montante em falta. Atualiza a página antes de pagar.' };
        }
        const [maxInteger, maxDecimals] = max.split('.');
        if (greater(amountCents, cents(maxInteger, maxDecimals))) {
            return { ok: false, error: 'O valor não pode ultrapassar o montante em falta (' + max.replace('.', ',') + ').' };
        }
        return { ok: true, value: canonical(integer, decimals) };
    }

    function bind(form, token, feedback, showError) {
        const amount = form.querySelector('[data-payment-amount]');
        const button = form.querySelector('[type="submit"]');
        const amountError = form.querySelector('[data-payment-error]');
        if (!amount || !button) return false;

        const clearAmountError = () => {
            amount.setCustomValidity('');
            amount.removeAttribute('aria-invalid');
            if (amountError) amountError.textContent = '';
        };
        amount.addEventListener('input', clearAmountError);
        form.addEventListener('submit', async (event) => {
            event.preventDefault();
            if (button.disabled) return;
            const result = validate(amount.value, amount.dataset.maxAmount);
            if (!result.ok) {
                amount.setCustomValidity(result.error);
                amount.setAttribute('aria-invalid', 'true');
                if (amountError) amountError.textContent = result.error;
                amount.reportValidity();
                amount.focus();
                return;
            }
            clearAmountError();
            const values = Object.fromEntries(new FormData(form).entries());
            values.amount = result.value;
            button.disabled = true;
            const uncertainMessage = 'Não foi possível confirmar o pagamento. Atualiza a página e verifica o histórico e o saldo antes de tentar novamente.';
            try {
                const response = await fetch(form.dataset.endpoint, {
                    method: form.dataset.method || 'POST', credentials: 'same-origin',
                    headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': token },
                    body: JSON.stringify(values),
                });
                const payload = await response.json().catch(() => ({}));
                if (!response.ok) {
                    if (response.status >= 400 && response.status < 500) {
                        showError(feedback, Object.values(payload.errors || {}).flat().join(' ') || payload.message || 'Não foi possível guardar.');
                        button.disabled = false;
                    } else {
                        showError(feedback, uncertainMessage);
                    }
                    feedback.scrollIntoView({ behavior: 'smooth', block: 'center' });
                    return;
                }
                window.location.reload();
            } catch (error) {
                showError(feedback, uncertainMessage);
                feedback.scrollIntoView({ behavior: 'smooth', block: 'center' });
            }
        });
        button.disabled = false;
        return true;
    }

    globalThis.CommitmentPayment = Object.freeze({ validate, bind });
})();
