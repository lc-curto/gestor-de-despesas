import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

const template = readFileSync(new URL('../../resources/views/financial/accounts.blade.php', import.meta.url), 'utf8');
const functionSource = template.match(/function parseMoney\(value, allowNegative = false\) \{[\s\S]*?\n    \}/)?.[0];
assert.ok(functionSource, 'parseMoney deve existir na página de contas');
const parseMoney = new Function(`return (${functionSource})`)();

test('normaliza entradas portuguesas e ponto decimal sem multiplicar por 100', () => {
    assert.equal(parseMoney('90,50'), '90.50');
    assert.equal(parseMoney('1.000,00'), '1000.00');
    assert.equal(parseMoney('1000,00'), '1000.00');
    assert.equal(parseMoney('12345,67'), '12345.67');
    assert.equal(parseMoney('90.50'), '90.50');
    assert.equal(parseMoney('90'), '90.00');
});

test('preserva saldo inicial negativo e rejeita negativos em pagamentos/transferências', () => {
    assert.equal(parseMoney('-25,40', true), '-25.40');
    assert.throws(() => parseMoney('-25,40'), /valor monetário válido/);
});

test('rejeita separadores ambíguos, casas excessivas e zero em valores positivos', () => {
    assert.throws(() => parseMoney('1.000'), /no máximo duas casas/);
    assert.throws(() => parseMoney('90,500'), /formato/);
    assert.throws(() => parseMoney('0'), /maior que zero/);
    assert.throws(() => parseMoney('90,50abc'), /valor monetário válido/);
});

test('campos monetários usam texto e teclado decimal', () => {
    for (const id of ['openingBalance', 'transferAmount']) {
        const field = template.match(new RegExp(`<input[^>]+id="${id}"[^>]*>`))?.[0] || '';
        assert.match(field, /type="text"/);
        assert.match(field, /inputmode="decimal"/);
        assert.match(field, /data-money=/);
    }
});
