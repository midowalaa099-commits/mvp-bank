import test from 'node:test';
import assert from 'node:assert/strict';
import {
    normalizeAmount,
    minorUnits,
    formatMoney,
    formatMinor,
    isIncoming,
} from '../../resources/js/bank.ts';

test('money stays exact beyond JavaScript floating point precision', () => {
    assert.equal(
        normalizeAmount('92233720368547758.07'),
        '92233720368547758.07',
    );
    assert.equal(minorUnits('92233720368547758.07'), 9223372036854775807n);
    assert.equal(
        formatMoney('92233720368547758.07'),
        '92,233,720,368,547,758.07',
    );
    assert.equal(formatMinor(minorUnits('0.10') + minorUnits('0.20')), '0.30');
});

test('money inputs normalize without rounding', () => {
    assert.equal(normalizeAmount(' 00125.5 '), '125.50');
    assert.equal(normalizeAmount('10'), '10.00');
    assert.equal(normalizeAmount('0.01'), '0.01');
});

for (const value of [
    '0',
    '-1',
    '1e3',
    '1.005',
    'NaN',
    '1,000',
    '',
    '.50',
    '92233720368547758.08',
]) {
    test(`rejects invalid money input ${JSON.stringify(value)}`, () =>
        assert.throws(() => normalizeAmount(value)));
}

test('incoming transfers use the receiving account rather than the operation type', () => {
    const transfer = {
        type: 'transfer',
        source_account: 'sender',
        destination_account: 'recipient',
    };
    assert.equal(isIncoming(transfer, { reference: 'recipient' }), true);
    assert.equal(isIncoming(transfer, { reference: 'sender' }), false);
});
