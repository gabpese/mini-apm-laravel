/* oxlint-disable typescript/no-floating-promises -- node:test registers tests by calling test(), which returns a promise nobody needs to await */
import assert from 'node:assert/strict';
import { test } from 'node:test';
import { MiniApm } from '../mini-apm.js';

function setup(options = {}, respond = () => ({ ok: true, status: 202 })) {
    const requests = [];
    const fetch = async (url, init) => {
        requests.push({ url, init, body: JSON.parse(init.body) });
        return respond(requests.length);
    };
    const apm = new MiniApm({
        endpoint: 'https://apm.test/',
        apiKey: 'apm_key',
        appVersion: '1.2.0',
        userRef: 'u_test',
        flushInterval: 0,
        autoCapture: false,
        env: { os: 'Windows 11', ram_mb: 16384, gpu: 'GTX 1660' },
        fetch,
        ...options,
    });

    return { apm, requests };
}

test('requires endpoint, apiKey and appVersion', () => {
    assert.throws(
        () => new MiniApm({ endpoint: 'x', apiKey: 'y' }),
        /needs endpoint, apiKey and appVersion/,
    );
});

test('start opens a session with the machine data', async () => {
    const { apm, requests } = setup();

    apm.start();
    await apm.flush();

    const [event] = requests[0].body.events;
    assert.equal(event.type, 'session_start');
    assert.equal(event.app_version, '1.2.0');
    assert.equal(event.user_ref, 'u_test');
    assert.deepEqual(event.env, {
        os: 'Windows 11',
        ram_mb: 16384,
        gpu: 'GTX 1660',
    });
    assert.ok(!Number.isNaN(Date.parse(event.occurred_at)));
});

test('sends to the versioned endpoint with the key as a Bearer token', async () => {
    const { apm, requests } = setup();

    apm.track('export_pdf');
    await apm.flush();

    assert.equal(requests[0].url, 'https://apm.test/api/v1/events');
    assert.equal(requests[0].init.method, 'POST');
    assert.equal(requests[0].init.headers.Authorization, 'Bearer apm_key');
    assert.equal(requests[0].init.keepalive, true);
});

test('track records a feature with its properties', async () => {
    const { apm, requests } = setup();

    apm.track('export_pdf', { pages: 3 });
    await apm.flush();

    const [event] = requests[0].body.events;
    assert.equal(event.type, 'feature_used');
    assert.equal(event.name, 'export_pdf');
    assert.deepEqual(event.properties, { pages: 3 });
});

test('captureException records an error, or a crash when fatal', async () => {
    const { apm, requests } = setup();

    apm.captureException(new TypeError('boom'));
    apm.captureException(new Error('dead'), { fatal: true });
    apm.captureException('just a string');
    await apm.flush();

    const events = requests[0].body.events;
    assert.deepEqual(
        events.map((e) => e.type),
        ['error', 'crash', 'error'],
    );
    assert.equal(events[0].message, 'TypeError: boom');
    assert.match(events[0].stack, /boom/);
    assert.equal(events[2].message, 'Error: just a string');
});

test('truncates a message and a stack beyond the API limits', async () => {
    const { apm, requests } = setup();
    const error = new Error('x'.repeat(5000));
    error.stack = 'y'.repeat(30000);

    apm.captureException(error);
    await apm.flush();

    const [event] = requests[0].body.events;
    assert.equal(event.message.length, 2000);
    assert.equal(event.stack.length, 20000);
});

test('sends by itself once maxBatchSize events are queued', async () => {
    const { apm, requests } = setup({ maxBatchSize: 3 });

    apm.track('a');
    apm.track('b');
    assert.equal(requests.length, 0);

    apm.track('c');
    await apm.flush();

    assert.equal(requests.length, 1);
    assert.equal(requests[0].body.events.length, 3);
});

test('splits a large queue into requests of at most 100 events', async () => {
    const { apm, requests } = setup({ maxBatchSize: 100 });

    for (let i = 0; i < 250; i++) apm.track(`f${i}`);
    await apm.flush();

    assert.deepEqual(
        requests.map((r) => r.body.events.length),
        [100, 100, 50],
    );
});

test('keeps the events and retries when the server is down', async () => {
    const { apm, requests } = setup({}, (n) =>
        n === 1 ? { ok: false, status: 503 } : { ok: true, status: 202 },
    );

    apm.track('a');
    assert.equal(await apm.flush(), false);
    assert.equal(apm.queue.length, 1);

    assert.equal(await apm.flush(), true);
    assert.equal(apm.queue.length, 0);
    assert.equal(requests[1].body.events[0].name, 'a');
});

test('keeps the events when the network fails', async () => {
    const apm = new MiniApm({
        endpoint: 'https://apm.test',
        apiKey: 'k',
        appVersion: '1',
        userRef: 'u',
        flushInterval: 0,
        autoCapture: false,
        fetch: async () => {
            throw new TypeError('network down');
        },
    });

    apm.track('a');

    assert.equal(await apm.flush(), false);
    assert.equal(apm.queue.length, 1);
});

test('drops a batch the API rejects for good, such as a bad key', async () => {
    const { apm } = setup({}, () => ({ ok: false, status: 401 }));

    apm.track('a');

    assert.equal(await apm.flush(), false); // not accepted...
    assert.equal(apm.queue.length, 0); // ...but not kept either
});

test('retries after rate limiting', async () => {
    const { apm } = setup({}, () => ({ ok: false, status: 429 }));

    apm.track('a');
    await apm.flush();

    assert.equal(apm.queue.length, 1);
});

test('never queues more than 500 events', () => {
    const { apm } = setup({ maxBatchSize: 100 }, () => new Promise(() => {})); // the server never answers

    for (let i = 0; i < 700; i++) apm.track(`f${i}`);

    assert.ok(apm.queue.length <= 500);
});

test('pauses sending on batch size after a failure, but flush still tries', async () => {
    const { apm, requests } = setup(
        { maxBatchSize: 2, retryDelay: 60000 },
        () => ({ ok: false, status: 503 }),
    );

    for (let i = 0; i < 6; i++) apm.track(`f${i}`);
    await apm.flush();

    // the first batch-size send failed; the other tracks did not try again; then flush() made one more attempt
    assert.equal(requests.length, 2);
    assert.equal(apm.queue.length, 6);
});

test('sends on batch size again once the pause is over', async () => {
    const { apm, requests } = setup({ maxBatchSize: 2, retryDelay: 0 }, (n) =>
        n === 1 ? { ok: false, status: 503 } : { ok: true, status: 202 },
    );

    apm.track('a');
    apm.track('b');
    await apm.flush(); // the batch-size send failed, this one succeeds
    apm.track('c');
    apm.track('d');
    await apm.flush();

    assert.equal(apm.queue.length, 0);
    assert.ok(requests.length >= 2);
});

test('start is idempotent', async () => {
    const { apm, requests } = setup();

    apm.start();
    apm.start();
    await apm.flush();

    assert.equal(
        requests[0].body.events.filter((e) => e.type === 'session_start')
            .length,
        1,
    );
});
