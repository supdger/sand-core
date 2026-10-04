// Behavior regression: run real guard and user-store code with isolated services.
// FRONTEND_NODE_MODULES may point to an existing frontend dependency installation.
const { readFileSync } = require("node:fs");
const { resolve } = require("node:path");
const { createRequire } = require("node:module");
const vm = require("node:vm");
const test = require("node:test");
const assert = require("node:assert/strict");
const deps = createRequire(
  resolve(
    process.env.FRONTEND_NODE_MODULES ||
      resolve(__dirname, "../sandadmin-artd/node_modules"),
    "package.json",
  ),
);
const ts = deps("typescript");
const { createRouter, createMemoryHistory } = deps("vue-router");
const { ref, computed, reactive } = deps("vue");
const root =
  process.env.FRONTEND_SOURCE || resolve(__dirname, "../sandadmin-artd/src");

function fixture() {
  let failure = null;
  let menuCalls = 0;
  let now = 0;
  const requests = [];
  const responses = new Map();
  const messages = [];
  let pause = null;
  const timers = new Map();
  const home = ref("");
  let menu = [{ name: "User", path: "/system/user", meta: {} }];
  const staticRoutes = [
    { path: "/auth/login", name: "Login", component: {} },
    { path: "/403", name: "Exception403", component: {} },
    { path: "/500", name: "Exception500", component: {} },
    { path: "/:pathMatch(.*)*", name: "Exception404", component: {} },
  ];
  const router = createRouter({
    history: createMemoryHistory(),
    routes: staticRoutes,
  });
  const menuStore = {
    menuList: [],
    setMenuList(v) {
      this.menuList = v;
      home.value = v[0]?.path || "";
    },
    setHomePath(v) {
      home.value = v;
    },
    addRemoveRouteFns() {},
    removeAllDynamicRoutes() {},
  };
  class Registry {
    ready = false;
    removers = [];
    isRegistered() {
      return this.ready;
    }
    register(list) {
      this.removers = list.map((r) => router.addRoute({ ...r, component: {} }));
      this.ready = true;
    }
    unregister() {
      this.removers.forEach((fn) => fn());
      this.removers = [];
      this.ready = false;
    }
    getRemoveRouteFns() {
      return this.removers;
    }
  }
  class Processor {
    async getMenuList(readMenu) {
      return readMenu();
    }
    validateMenuList(list) {
      return list.length > 0;
    }
  }
  let user;
  const storage = {
    getItem() {
      return null;
    },
    setItem() {},
    removeItem() {},
  };
  const stubs = {
    ref,
    computed,
    defineStore: (_id, factory) => () => reactive(factory()),
    useSettingStore: () => ({ showNprogress: false }),
    useUserStore: () => user,
    useMenuStore: () => menuStore,
    useCommon: () => ({ homePath: home }),
    useWorktabStore: () => ({ validateWorktabs() {} }),
    useDictStore: () => ({ setDictList() {} }),
    $t: (key) => key,
    isHttpError: (e) => typeof e?.code === "number",
    ApiStatus: {
      unauthorized: 401,
      forbidden: 403,
      error: 0,
      requestTimeout: 408,
    },
    RoutesAlias: { Login: "/auth/login" },
    RouteRegistry: Registry,
    MenuProcessor: Processor,
    IframeRouteManager: { getInstance: () => ({ save() {}, clear() {} }) },
    staticRoutes,
    loadingService: { showLoading() {}, hideLoading() {} },
    nextTick: (fn) => fn(),
    setWorktab() {},
    setPageTitle() {},
    default: {
      start() {},
      async get(config) {
        const name = config.url.split("/").pop();
        requests.push({ name, ...config, at: now });
        if (name === "menu") menuCalls++;
        const queue = responses.get(name);
        if (queue?.length) {
          const action = queue.shift();
          if (typeof action === "function") return action(config);
          throw action;
        }
        if (name === "menu") {
          const wait = pause;
          if (wait) await wait;
          if (failure) throw failure;
          return menu;
        }
        return name === "user" ? { id: 1 } : [];
      },
    },
    router,
    LanguageEnum: { ZH: "zh" },
    StorageConfig: { LAST_USER_ID_KEY: "last" },
  };
  function load(file) {
    const module = { exports: {} };
    const code = ts.transpileModule(readFileSync(resolve(root, file), "utf8"), {
      compilerOptions: {
        module: ts.ModuleKind.CommonJS,
        target: ts.ScriptTarget.ES2022,
      },
    }).outputText;
    vm.runInNewContext(code, {
      module,
      exports: module.exports,
      require: () => stubs,
      console: { warn() {}, error() {} },
      localStorage: storage,
      sessionStorage: storage,
      Date: class extends Date {
        static now() {
          return now;
        }
      },
      ElMessage: { error: (message) => messages.push(message) },
      setTimeout(fn, delay = 0) {
        const id = {};
        timers.set(id, { fn, at: now + delay });
        return id;
      },
      clearTimeout(id) {
        timers.delete(id);
      },
    });
    return module.exports;
  }
  const errors = load("utils/http/error.ts");
  Object.assign(stubs, errors);
  Object.assign(stubs, load("api/auth.ts"));
  const guard = load("router/guards/beforeEach.ts");
  Object.assign(stubs, guard);
  user = load("store/modules/user.ts").useUserStore();
  guard.setupBeforeEachGuard(router);
  return {
    router,
    user,
    guard,
    errors,
    requests,
    messages,
    sequence(name, ...actions) {
      responses.set(name, actions);
    },
    time: () => now,
    jump(ms) {
      now += ms;
    },
    async tick() {
      for (let i = 0; i < 20; i++) await Promise.resolve();
      if (timers.size) {
        now = Math.min(...[...timers.values()].map((timer) => timer.at));
        const due = [...timers.entries()].filter(
          ([, timer]) => timer.at <= now,
        );
        for (const [id, timer] of due) {
          timers.delete(id);
          timer.fn();
        }
      }
      for (let i = 0; i < 20; i++) await Promise.resolve();
    },
    fail(value) {
      failure = value;
    },
    calls: () => menuCalls,
    emptyMenu() {
      menu = [];
    },
    pause(value) {
      pause = value;
    },
    flushTimers() {
      for (const { fn } of timers.values()) fn();
      timers.clear();
    },
  };
}

test("initial failure stays an initialization error, successful login recovers home", async () => {
  const f = fixture();
  f.user.setLoginStatus(true);
  f.fail(new Error("menu unavailable"));
  await f.router.push("/");
  assert.equal(f.router.currentRoute.value.name, "Exception500");
  await f.router.push("/");
  assert.equal(f.router.currentRoute.value.name, "Exception500");
  assert.equal(f.calls(), 1);
  await f.router.push("/auth/login");
  f.fail(null);
  f.user.setLoginStatus(true);
  await f.router.push("/");
  assert.equal(f.router.currentRoute.value.path, "/system/user");
  assert.equal(f.calls(), 2);
  await f.router.push("/unknown");
  assert.equal(f.router.currentRoute.value.name, "Exception404");
});
test("permission refusal stays 403 and does not retry on root navigation", async () => {
  const f = fixture();
  f.user.setLoginStatus(true);
  f.fail(new f.errors.HttpError("denied", 403));
  await f.router.push("/");
  assert.equal(f.router.currentRoute.value.name, "Exception403");
  await f.router.push("/");
  assert.equal(f.router.currentRoute.value.name, "Exception403");
  assert.equal(f.calls(), 1);
});
test("fast logout/login cancels old deferred route removal", async () => {
  const f = fixture();
  f.user.setLoginStatus(true);
  await f.router.push("/");
  f.user.logOut();
  await f.router.push("/auth/login");
  f.user.setLoginStatus(true);
  await f.router.push("/");
  f.flushTimers();
  assert.ok(f.router.hasRoute("User"));
  assert.equal(f.router.currentRoute.value.path, "/system/user");
});
test("obsolete initialization cannot register routes in a new session", async () => {
  const f = fixture();
  f.user.setLoginStatus(true);
  let release;
  f.pause(
    new Promise((resolve) => {
      release = resolve;
    }),
  );
  const old = f.guard.ensureDynamicRoutesReady(f.router);
  f.user.setLoginStatus(true);
  f.pause(null);
  release();
  await assert.rejects(old);
  assert.equal(f.router.hasRoute("User"), false);
  assert.equal(f.guard.getRouteInitFailed(), false);
  await f.router.push("/");
  assert.equal(f.router.currentRoute.value.path, "/system/user");
});
test("fresh authenticated page load initializes routes; logged-out root stays protected", async () => {
  const f = fixture();
  await f.router.push("/");
  assert.equal(f.router.currentRoute.value.name, "Login");
  assert.equal(f.calls(), 0);
  f.user.setLoginStatus(true);
  await f.router.push("/system/user");
  assert.equal(f.router.currentRoute.value.path, "/system/user");
});

test("empty authorized menu stays 403 without automatic retries", async () => {
  const f = fixture();
  f.user.setLoginStatus(true);
  f.emptyMenu();
  await f.router.push("/");
  assert.equal(f.router.currentRoute.value.name, "Exception403");
  await f.router.push("/");
  assert.equal(f.router.currentRoute.value.name, "Exception403");
  assert.equal(f.calls(), 1);
});

function transportError(
  f,
  { status, data = "", code = "ERR_NETWORK", method = "get" } = {},
) {
  try {
    f.errors.handleError({
      code,
      message: "transport failure",
      config: { url: "/core/system/menu", method },
      ...(status ? { response: { status, data } } : {}),
    });
  } catch (error) {
    return error;
  }
}

for (const status of [undefined, 500, 502, 503]) {
  test(`initialization recovers ${status || "network"}, retains successful GETs and suppresses intermediate errors`, async () => {
    const f = fixture();
    f.user.setLoginStatus(true);
    f.sequence("menu", transportError(f, { status }));
    const ready = f.guard.ensureDynamicRoutesReady(f.router);
    const shared = f.guard.ensureDynamicRoutesReady(f.router);
    await f.tick();
    await Promise.all([ready, shared]);
    assert.ok(f.router.hasRoute("User"));
    assert.equal(f.calls(), 2);
    assert.equal(f.requests.filter((r) => r.name === "user").length, 1);
    assert.equal(f.requests.filter((r) => r.name === "dictAll").length, 1);
    assert.equal(f.messages.length, 0);
    assert.ok(f.requests.every((r) => r.showErrorMessage === false));
    assert.deepEqual(
      f.requests.filter((r) => r.name === "menu").map((r) => r.timeout),
      [15000, 14000],
    );
  });
}

for (const input of [
  { code: "ECONNABORTED" },
  { code: "ETIMEDOUT" },
  { code: "ERR_CANCELED" },
  { code: "ERR_BAD_OPTION" },
  { status: 401 },
  { status: 403 },
  { status: 500, data: { code: 500, message: "database failure" } },
  { status: 502, data: "upstream invalid" },
  { status: 503, data: {} },
  { status: 504 },
  { method: "post" },
]) {
  test(`non-recoverable initialization failure is not retried: ${JSON.stringify(input)}`, async () => {
    const f = fixture();
    f.user.setLoginStatus(true);
    const error = transportError(f, input);
    f.sequence("menu", error);
    await assert.rejects(
      f.guard.ensureDynamicRoutesReady(f.router),
      (actual) => actual === error,
    );
    await f.tick();
    assert.equal(f.calls(), 1);
    assert.equal(f.router.hasRoute("User"), false);
  });
}

test("structured business 500 without transport origin is not retried", async () => {
  const f = fixture();
  f.user.setLoginStatus(true);
  f.sequence("menu", new f.errors.HttpError("business failure", 500));
  await assert.rejects(f.guard.ensureDynamicRoutesReady(f.router));
  assert.equal(f.calls(), 1);
  assert.equal(f.messages.length, 1);
});

test("retry exhaustion terminates with one final error and no routes", async () => {
  const f = fixture();
  f.user.setLoginStatus(true);
  f.sequence("menu", ...Array.from({ length: 6 }, () => transportError(f)));
  const failed = assert.rejects(f.guard.ensureDynamicRoutesReady(f.router));
  for (let i = 0; i < 7; i++) await f.tick();
  await failed;
  assert.equal(f.calls(), 6);
  assert.equal(f.time(), 5000);
  assert.equal(f.messages.length, 1);
  assert.equal(f.router.hasRoute("User"), false);
});

test("one fatal read stops a sibling waiting to retry", async () => {
  const f = fixture();
  f.user.setLoginStatus(true);
  f.sequence("user", transportError(f));
  f.sequence("menu", transportError(f, { status: 403 }));
  await assert.rejects(f.guard.ensureDynamicRoutesReady(f.router));
  await f.tick();
  assert.equal(f.requests.filter((r) => r.name === "user").length, 1);
  assert.equal(f.messages.length, 1);
});

test("expired shared deadline never starts another read", async () => {
  const f = fixture();
  f.user.setLoginStatus(true);
  f.sequence("menu", transportError(f));
  const failed = assert.rejects(f.guard.ensureDynamicRoutesReady(f.router));
  for (let i = 0; i < 20; i++) await Promise.resolve();
  f.jump(15000);
  f.flushTimers();
  await failed;
  assert.equal(f.calls(), 1);
  assert.equal(f.router.hasRoute("User"), false);
});

test("new session cancels a retry wait without corrupting its successful initialization", async () => {
  const f = fixture();
  f.user.setLoginStatus(true);
  f.sequence("menu", transportError(f));
  const old = assert.rejects(f.guard.ensureDynamicRoutesReady(f.router));
  for (let i = 0; i < 20; i++) await Promise.resolve();
  f.user.setLoginStatus(true);
  await f.guard.ensureDynamicRoutesReady(f.router);
  await f.tick();
  await old;
  assert.ok(f.router.hasRoute("User"));
  assert.equal(f.calls(), 2);
  assert.equal(f.guard.getRouteInitFailed(), false);
  assert.equal(f.messages.length, 0);
});

test("actual HTTP client distinguishes empty proxy failure from HTTP-200 business failure and preserves silent options", async () => {
  const axios = deps("axios");
  const messages = [];
  let response = { status: 500, data: "" };
  let calls = 0;
  let logout = 0;
  const timers = [];
  const modules = {
    "@/locales": { $t: (key) => key },
    "./status": {
      ApiStatus: {
        success: 200,
        error: 400,
        unauthorized: 401,
        forbidden: 403,
      },
    },
    "@/store/modules/user": {
      useUserStore: () => ({
        accessToken: "test",
        logOut() {
          logout++;
        },
      }),
    },
    axios: {
      ...axios,
      default: {
        create(config) {
          return axios.create({
            ...config,
            adapter: async (request) => {
              calls++;
              const result = {
                ...response,
                config: request,
                headers: {},
                statusText: "",
              };
              if (result.status >= 400)
                throw new axios.AxiosError(
                  "server error",
                  "ERR_BAD_RESPONSE",
                  request,
                  null,
                  result,
                );
              return result;
            },
          });
        },
      },
    },
  };
  function load(file) {
    const module = { exports: {} };
    const source = readFileSync(resolve(root, file), "utf8").replaceAll(
      "import.meta.env",
      "({})",
    );
    const code = ts.transpileModule(source, {
      compilerOptions: {
        module: ts.ModuleKind.CommonJS,
        target: ts.ScriptTarget.ES2022,
      },
    }).outputText;
    vm.runInNewContext(code, {
      module,
      exports: module.exports,
      require: (name) => modules[name] || {},
      ElMessage: {
        error(message) {
          messages.push(message);
        },
      },
      console,
      FormData,
      setTimeout(fn) {
        timers.push(fn);
        return timers.length;
      },
      clearTimeout() {},
    });
    return module.exports;
  }
  const errors = load("utils/http/error.ts");
  modules["./error"] = errors;
  const api = load("utils/http/index.ts").default;
  let observed;
  await assert.rejects(
    api.get({ url: "/core/system/menu", showErrorMessage: false }),
    (error) => {
      observed = error;
      return errors.isRecoverableInitializationError(error);
    },
  );
  assert.equal(observed.transport, "http");
  assert.equal(messages.length, 0);
  response = { status: 200, data: { code: 500, data: null } };
  await assert.rejects(
    api.get({ url: "/core/system/menu", showErrorMessage: false }),
    (error) =>
      !errors.isRecoverableInitializationError(error) && error.code === 500,
  );
  response = { status: 500, data: "" };
  await assert.rejects(
    api.post({ url: "/install", showErrorMessage: false }),
    (error) => !errors.isRecoverableInitializationError(error),
  );
  assert.equal(calls, 3);
  response = { status: 401, data: "" };
  await assert.rejects(
    api.get({ url: "/core/system/menu", showErrorMessage: false }),
    (error) => error.code === 401,
  );
  for (const timer of timers) timer();
  assert.equal(logout, 1);
  assert.equal(messages.length, 1);
});

test("late successful data beyond the deadline never registers routes", async () => {
  const f = fixture();
  f.user.setLoginStatus(true);
  f.sequence("menu", () => {
    f.jump(15001);
    return [{ name: "User", path: "/system/user", meta: {} }];
  });
  await assert.rejects(f.guard.ensureDynamicRoutesReady(f.router));
  assert.equal(f.router.hasRoute("User"), false);
});

test("invalid menu payload is not retried or registered", async () => {
  const f = fixture();
  f.user.setLoginStatus(true);
  f.sequence("menu", () => null);
  await assert.rejects(f.guard.ensureDynamicRoutesReady(f.router));
  assert.equal(f.calls(), 1);
  assert.equal(f.router.hasRoute("User"), false);
});

test("slow failed request consumes the shared retry deadline", async () => {
  const f = fixture();
  f.user.setLoginStatus(true);
  f.sequence("menu", () => {
    f.jump(9000);
    throw transportError(f);
  });
  const ready = f.guard.ensureDynamicRoutesReady(f.router);
  await f.tick();
  await ready;
  assert.deepEqual(
    f.requests.filter((r) => r.name === "menu").map((r) => r.timeout),
    [15000, 5000],
  );
});
