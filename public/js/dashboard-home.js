(() => {
    const monthSelect = document.getElementById('dashboardMonth');
    monthSelect?.addEventListener('change', () => monthSelect.form?.requestSubmit());

    const amount = document.getElementById('conversionAmount');
    const from = document.getElementById('conversionFrom');
    const to = document.getElementById('conversionTo');
    const button = document.getElementById('convertButton');
    const output = document.getElementById('convertedValue');
    const rateLabel = document.getElementById('conversionRate');
    const currency = window.meuBolsoHome?.currency || 'EUR';

    if (!amount || !from || !to || !button || !output || !rateLabel) return;

    to.value = currency;
    from.value = currency === 'USD' ? 'EUR' : 'USD';

    const format = (value, code) => new Intl.NumberFormat('pt-PT', {
        style: 'currency', currency: code
    }).format(value);

    async function convert() {
        const value = Number(amount.value);
        if (!Number.isFinite(value) || value <= 0) {
            output.textContent = '—';
            rateLabel.textContent = 'Introduz um valor válido.';
            return;
        }

        button.disabled = true;
        output.textContent = 'A calcular...';
        rateLabel.textContent = '';

        try {
            if (from.value === to.value) {
                output.textContent = format(value, to.value);
                rateLabel.textContent = 'As moedas selecionadas são iguais.';
                return;
            }

            const response = await fetch('https://api.frankfurter.dev/v2/rate/' + from.value.toLowerCase() + '/' + to.value.toLowerCase());
            if (!response.ok) throw new Error('Não foi possível consultar a taxa de câmbio.');
            const data = await response.json();
            const rate = Number(data.rate);
            if (!Number.isFinite(rate)) throw new Error('A API devolveu uma taxa inválida.');

            output.textContent = format(value * rate, to.value);
            rateLabel.textContent = '1 ' + from.value + ' = ' + rate.toFixed(4) + ' ' + to.value + (data.date ? ' · Taxa de ' + data.date : '');
        } catch (error) {
            output.textContent = 'Erro na conversão';
            rateLabel.textContent = error.message || 'Tenta novamente.';
        } finally {
            button.disabled = false;
        }
    }

    button.addEventListener('click', convert);
    amount.addEventListener('keydown', event => {
        if (event.key === 'Enter') convert();
    });
    document.getElementById('swapCurrencies')?.addEventListener('click', () => {
        const current = from.value;
        from.value = to.value;
        to.value = current;
        output.textContent = '—';
        rateLabel.textContent = 'Moedas invertidas. Seleciona Converter.';
    });
})();
