(function (global) {
    'use strict';

    function loginUrl(value, locationHref, port, base) {
        value = value.trim();
        if (/[\u0000-\u0020\\]/.test(value)) {
            throw new Error('后台地址不能包含空白、控制字符或反斜杠。');
        }
        const location = new URL(locationHref);
        let url;
        if (value) {
            if (!/^https?:\/\//i.test(value) && !/^\/(?!\/)/.test(value)) {
                throw new Error('请填写完整的 HTTP(S) 后台地址，或以 / 开头的同源路径。');
            }
            url = new URL(value, location.origin);
        } else {
            url = new URL(location.origin);
            url.port = String(port);
            url.pathname = base || '/';
        }
        if (!['http:', 'https:'].includes(url.protocol) || url.username || url.password) {
            throw new Error('后台地址仅支持不含用户名和密码的 HTTP(S) 地址。');
        }
        if (!url.pathname.endsWith('/') && !url.pathname.endsWith('/index.html')) {
            url.pathname += '/';
        }
        url.hash = '/auth/login';
        return url;
    }

    // A decoded product marker only proves static resources responded at this
    // moment. It neither authenticates a remote peer nor executes the app.
    function probe(url, options) {
        const settings = options || {};
        const image = new (settings.Image || global.Image)();
        const marker = new URL('sandadmin-entry-check.svg', url);
        marker.searchParams.set('_check', `${Date.now()}-${Math.random().toString(36).slice(2)}`);
        let finish;
        const promise = new Promise((resolve) => {
            let settled = false;
            const timer = setTimeout(() => finish('timeout'), settings.timeout || 8000);
            finish = (state) => {
                if (settled) return;
                settled = true;
                clearTimeout(timer);
                image.onload = image.onerror = null;
                image.removeAttribute('src');
                resolve(state);
            };
            image.onload = () => finish(
                image.naturalWidth === 113 && image.naturalHeight === 29 ? 'available' : 'invalid'
            );
            image.onerror = () => finish('unavailable');
            image.referrerPolicy = 'no-referrer';
            image.src = marker.href;
        });
        return { promise, cancel: () => finish('cancelled') };
    }

    function init(root) {
        const input = root.querySelector('#frontendAddress');
        const status = root.querySelector('#frontendStatus');
        const target = root.querySelector('#frontendTarget');
        const enter = root.querySelector('#adminEntry');
        const retry = root.querySelector('#frontendRetry');
        const recovery = root.querySelector('#frontendRecovery');
        const manual = root.querySelector('#frontendManual');
        let currentProbe = null;
        let generation = 0;

        function selectedUrl() {
            return loginUrl(input.value, global.location.href, root.dataset.frontendPort, root.dataset.frontendBase);
        }

        function cancel() {
            generation++;
            if (currentProbe) currentProbe.cancel();
            currentProbe = null;
            enter.disabled = true;
            retry.disabled = false;
            manual.removeAttribute('href');
        }

        function showTarget() {
            const url = selectedUrl();
            target.textContent = url.href;
            manual.href = url.href;
            return url;
        }

        async function check(navigate) {
            cancel();
            const active = generation;
            let url;
            try {
                url = showTarget();
            } catch (error) {
                status.textContent = error.message;
                recovery.hidden = false;
                return;
            }
            status.textContent = '正在检查管理端静态资源…';
            retry.disabled = true;
            currentProbe = probe(url);
            const result = await currentProbe.promise;
            if (active !== generation) return;
            currentProbe = null;
            retry.disabled = false;
            if (result === 'available') {
                status.textContent = '管理端静态资源已响应。进入后请使用账号登录。';
                recovery.hidden = true;
                enter.disabled = false;
                if (navigate) global.location.assign(url.href);
            } else {
                status.textContent = result === 'timeout'
                    ? '检查超时，暂时无法确认后台入口。安装结果已保留，请按下方步骤恢复后重试。'
                    : '暂时无法确认后台入口。前端未运行、地址不符或浏览器安全策略均可能导致检查失败。';
                recovery.hidden = false;
            }
        }

        input.value = root.dataset.frontendUrl || '';
        try {
            input.value = selectedUrl().href;
            showTarget();
        } catch (error) {
            status.textContent = error.message;
        }
        input.addEventListener('input', () => {
            cancel();
            status.textContent = '地址已修改，请重新检查。';
            try { showTarget(); } catch (error) { status.textContent = error.message; }
        });
        retry.addEventListener('click', () => check(false));
        enter.addEventListener('click', () => check(true));
        global.addEventListener('pagehide', cancel);
        return { check };
    }

    const api = { loginUrl, probe, init };
    if (typeof module !== 'undefined' && module.exports) module.exports = api;
    else global.SandAdminInstallEntry = api;
})(typeof window === 'undefined' ? globalThis : window);
