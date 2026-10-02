// Behavior regression: real Vue Router, registry, transformer, validator, menu processor,
// menu/user stores, home selector and guard; isolate HTTP, component modules and UI services.
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
  let pause = null;
  const timers = new Map();

  let menu = [
    {
      name: "System",
      path: "/system",
      component: "/index/index",
      meta: {},
      children: [
        { name: "User", path: "user", component: "/system/user", meta: {} },
      ],
    },
  ];
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
  let menuStore;
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
    useCommon: () => ({ homePath: computed(() => menuStore.getHomePath()) }),
    useWorktabStore: () => ({ validateWorktabs() {} }),
    useDictStore: () => ({ setDictList() {} }),
    fetchGetUserInfo: async () => ({ id: 1 }),
    fetchGetDictList: async () => [],
    isHttpError: (e) => typeof e?.code === "number",
    ApiStatus: { unauthorized: 401, forbidden: 403 },
    RoutesAlias: { Login: "/auth/login", Layout: "/index/index" },
    HOME_PAGE_PATH: "",
    useAppMode: () => ({ isFrontendMode: { value: false } }),
    fetchGetMenuList: async () => {
      menuCalls++;
      const wait = pause;
      if (wait) await wait;
      if (failure) throw failure;
      return menu;
    },
    ComponentLoader: class {
      load() {
        return {};
      }
      loadLayout() {
        return {};
      }
      loadIframe() {
        return {};
      }
    },
    IframeRouteManager: {
      getInstance: () => ({ save() {}, clear() {}, add() {} }),
    },
    staticRoutes,
    loadingService: { showLoading() {}, hideLoading() {} },
    nextTick: (fn) => fn(),
    setWorktab() {},
    setPageTitle() {},
    default: { start() {} },
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
      setTimeout(fn) {
        const id = {};
        timers.set(id, fn);
        return id;
      },
      clearTimeout(id) {
        timers.delete(id);
      },
    });
    return module.exports;
  }
  for (const file of [
    "utils/navigation/route.ts",
    "router/core/MenuProcessor.ts",
    "router/core/RouteValidator.ts",
    "router/core/RouteTransformer.ts",
    "router/core/RouteRegistry.ts",
  ])
    Object.assign(stubs, load(file));
  menuStore = load("store/modules/menu.ts").useMenuStore();
  const guard = load("router/guards/beforeEach.ts");
  Object.assign(stubs, guard);
  user = load("store/modules/user.ts").useUserStore();
  guard.setupBeforeEachGuard(router);
  return {
    router,
    user,
    guard,
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
      for (const fn of timers.values()) fn();
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
  f.fail({ code: 403, message: "denied" });
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

test("successful re-login after an initialization failure recovers root instead of catch-all 404", async () => {
  const f = fixture();
  f.user.setLoginStatus(true);
  f.fail(new Error("temporary menu outage"));
  await f.router.push("/");
  await f.router.push("/auth/login");
  f.fail(null);
  f.user.setLoginStatus(true);
  await f.router.push("/");
  assert.equal(f.router.currentRoute.value.name, "User");
  assert.equal(f.router.currentRoute.value.path, "/system/user");
});
