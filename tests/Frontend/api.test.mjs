import test from 'node:test';
import assert from 'node:assert/strict';
import {
    ApiError,
    request,
    readSession,
    saveSession,
    readPending,
    savePending,
} from '../../resources/js/api.ts';

function storage() {
    const values = new Map();
    return {
        getItem: (key) => values.get(key) ?? null,
        setItem: (key, value) => values.set(key, value),
        removeItem: (key) => values.delete(key),
    };
}

test('sends exact decimal strings and the same request key on an explicit retry', async (t) => {
    const calls = [];
    t.mock.method(globalThis, 'fetch', async (url, options) => {
        calls.push({ url, options });
        if (calls.length === 1) throw new TypeError('Network failure');
        return new Response(
            JSON.stringify({ data: { reference: 'receipt' } }),
            { status: 200 },
        );
    });
    const body = { amount: '92233720368547758.07' };
    await assert.rejects(
        request('/accounts/example/deposits', 'token', body, 'original-key'),
        (error) => error instanceof ApiError && error.status === 0,
    );
    assert.equal(calls.length, 1);
    const response = await request(
        '/accounts/example/deposits',
        'token',
        body,
        'original-key',
    );
    assert.equal(response.data.reference, 'receipt');
    assert.equal(calls[0].options.body, calls[1].options.body);
    assert.equal(calls[1].options.headers['Idempotency-Key'], 'original-key');
    assert.equal(calls[1].options.headers.Authorization, 'Bearer token');
    assert.equal(
        JSON.parse(calls[1].options.body).amount,
        '92233720368547758.07',
    );
});

test('preserves field errors for an unsuccessful money request', async (t) => {
    t.mock.method(
        globalThis,
        'fetch',
        async () =>
            new Response(
                JSON.stringify({
                    message: 'Invalid amount.',
                    errors: { amount: ['Enter an amount greater than zero.'] },
                }),
                { status: 422 },
            ),
    );
    await assert.rejects(
        request('/accounts/example/deposits', 'token', { amount: '0' }, 'key'),
        (error) =>
            error.status === 422 &&
            error.errors.amount[0] === 'Enter an amount greater than zero.',
    );
});

test('an unreadable success remains uncertain so a transfer can be retried safely', async (t) => {
    t.mock.method(
        globalThis,
        'fetch',
        async () => new Response('<html>Gateway error</html>', { status: 200 }),
    );
    await assert.rejects(
        request(
            '/accounts/example/transfers',
            'token',
            { amount: '20.00' },
            'key',
        ),
        (error) => error.status === 0,
    );
});

test('expired authentication triggers sign-in recovery', async (t) => {
    const original = globalThis.window;
    const events = [];
    globalThis.window = {
        dispatchEvent: (event) => events.push({ type: event.type, token: event.detail }),
    };
    t.after(() => {
        globalThis.window = original;
    });
    t.mock.method(
        globalThis,
        'fetch',
        async () =>
            new Response(JSON.stringify({ message: 'Unauthenticated.' }), {
                status: 401,
            }),
    );
    await assert.rejects(
        request('/accounts', 'expired-token'),
        (error) => error.status === 401,
    );
    assert.deepEqual(events, [{ type: 'bank:session-expired', token: 'expired-token' }]);
});

test('pending operations survive reloads and remain scoped to their owner', (t) => {
    const original = globalThis.sessionStorage;
    globalThis.sessionStorage = storage();
    t.after(() => {
        globalThis.sessionStorage = original;
    });
    const operation = {
        account: 'source',
        kind: 'transfer',
        key: 'original-key',
        body: { amount: '50.00', destination_account: 'recipient' },
    };
    savePending(1, operation);
    assert.deepEqual(readPending(1), operation);
    assert.equal(readPending(2), null);
    savePending(1, null);
    assert.equal(readPending(1), null);
});

test('expired or malformed stored sessions do not restore access', (t) => {
    const original = globalThis.sessionStorage;
    globalThis.sessionStorage = storage();
    t.after(() => {
        globalThis.sessionStorage = original;
    });
    saveSession({
        access_token: 'expired',
        user: { id: 1 },
        expires_at: '2000-01-01T00:00:00Z',
    });
    assert.equal(readSession(), null);
    sessionStorage.setItem('mvp-bank.session', '{broken');
    assert.equal(readSession(), null);
    saveSession({
        access_token: 'current',
        user: { id: 1 },
        expires_at: '2099-01-01T00:00:00Z',
    });
    assert.equal(readSession().access_token, 'current');
    saveSession(null);
    assert.equal(readSession(), null);
});
