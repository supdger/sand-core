const test = require('node:test');
const assert = require('node:assert/strict');
const { loginUrl, probe, init } = require('../server/plugin/sandadmin/public/assets/install-entry.js');

test('default and IPv6 addresses use the configured port and deployment base', () => {
    assert.equal(loginUrl('', 'http://localhost:8787/core/install', 3006, '/').href, 'http://localhost:3006/#/auth/login');
    assert.equal(loginUrl('', 'http://[::1]:8787/core/install', 3010, '/admin/').href, 'http://[::1]:3010/admin/#/auth/login');
});

test('explicit public URL and same-origin path override development port', () => {
    assert.equal(loginUrl('/admin', 'https://host.example:8443/core/install', 3006, '/').href, 'https://host.example:8443/admin/#/auth/login');
    assert.equal(loginUrl('https://admin.example/panel/index.html', 'http://localhost:8787', 3006, '/').href, 'https://admin.example/panel/index.html#/auth/login');
});

for (const value of ['javascript:alert(1)', 'data:text/html,hi', '//foreign.example/', '/\\foreign.example/', 'https://user:secret@example.com/', 'https://example.com/\nattack', 'not-a-url', 'http://localhost:99999/']) {
    test(`reject unsafe or malformed entry: ${JSON.stringify(value)}`, () => {
        assert.throws(() => loginUrl(value, 'http://localhost:8787', 3006, '/'));
    });
}

class FakeImage {
    static instances = [];
    constructor() { FakeImage.instances.push(this); }
    removeAttribute(name) { if (name === 'src') this.src = ''; }
    load(width, height) {
        this.naturalWidth = width;
        this.naturalHeight = height;
        this.onload?.();
    }
}
function start(timeout) {
    const operation = probe(new URL('https://admin.example/panel/#/auth/login'), { Image: FakeImage, timeout });
    return { ...operation, image: FakeImage.instances.at(-1) };
}

test('only the expected decoded image dimensions pass; target preserves base path', async () => {
    const operation = start();
    assert.match(operation.image.src, /^https:\/\/admin\.example\/panel\/sandadmin-entry-check\.svg\?_check=/);
    operation.image.load(113, 29);
    assert.equal(await operation.promise, 'available');
});

test('HTML fallback or refused connection reports failure rather than availability', async () => {
    const operation = start();
    operation.image.onerror();
    assert.equal(await operation.promise, 'unavailable');
});

test('a different decoded image cannot pass', async () => {
    const operation = start();
    operation.image.load(260, 306);
    assert.equal(await operation.promise, 'invalid');
});

test('a non-responsive resource terminates at the timeout', async () => {
    const operation = start(5);
    assert.equal(await operation.promise, 'timeout');
    assert.equal(operation.image.onload, null);
});

test('cancelled and late callbacks cannot convert failure into success', async () => {
    const first = start();
    const lateLoad = first.image.onload;
    first.cancel();
    first.image.naturalWidth = 113;
    first.image.naturalHeight = 29;
    lateLoad();
    assert.equal(await first.promise, 'cancelled');
    const second = start();
    second.image.onerror();
    second.image.load(113, 29);
    assert.equal(await second.promise, 'unavailable');
});

test('each check issues a fresh resource URL', async () => {
    const first = start();
    const second = start();
    assert.notEqual(first.image.src, second.image.src);
    first.cancel();
    second.cancel();
    await Promise.all([first.promise, second.promise]);
});

test('completion stays put on failure, ignores obsolete replies, and rechecks before navigation', async () => {
    const saved = { location: global.location, Image: global.Image, addEventListener: global.addEventListener };
    const navigations = [];
    const elements = new Map();
    function element(id) {
        if (!elements.has(id)) elements.set(id, {
            value: '', textContent: '', disabled: true, hidden: true, listeners: {},
            addEventListener(type, callback) { this.listeners[type] = callback; },
            removeAttribute(name) { delete this[name]; }
        });
        return elements.get(id);
    }
    let cleanup;
    global.location = { href: 'http://localhost:8787/core/install', assign: (url) => navigations.push(url) };
    global.Image = FakeImage;
    global.addEventListener = (_event, callback) => { cleanup = callback; };
    try {
        const ui = init({
            dataset: { frontendPort: '3006', frontendBase: '/', frontendUrl: '' },
            querySelector: element
        });
        const failed = ui.check(false);
        FakeImage.instances.at(-1).onerror();
        await failed;
        assert.equal(element('#adminEntry').disabled, true);
        assert.equal(element('#frontendRecovery').hidden, false);
        assert.deepEqual(navigations, []);

        const obsolete = ui.check(false);
        const old = FakeImage.instances.at(-1);
        const late = old.onload;
        element('#frontendAddress').value = 'http://localhost:3010/';
        element('#frontendAddress').listeners.input();
        old.naturalWidth = 113;
        old.naturalHeight = 29;
        late();
        await obsolete;
        assert.equal(element('#adminEntry').disabled, true);

        const ready = ui.check(false);
        FakeImage.instances.at(-1).load(113, 29);
        await ready;
        assert.equal(element('#adminEntry').disabled, false);
        assert.deepEqual(navigations, []);
        const clicked = element('#adminEntry').listeners.click();
        assert.equal(element('#adminEntry').disabled, true);
        assert.deepEqual(navigations, []);
        FakeImage.instances.at(-1).load(113, 29);
        await clicked;
        assert.deepEqual(navigations, ['http://localhost:3010/#/auth/login']);
    } finally {
        cleanup?.();
        for (const [name, value] of Object.entries(saved)) {
            if (value === undefined) delete global[name];
            else global[name] = value;
        }
    }
});
