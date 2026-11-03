import type { Router, RouteLocationNormalized, NavigationGuardNext } from 'vue-router'
import type { AppRouteRecord } from '@/types/router'
import { nextTick } from 'vue'
import NProgress from 'nprogress'
import { useSettingStore } from '@/store/modules/setting'
import { useUserStore } from '@/store/modules/user'
import { useMenuStore } from '@/store/modules/menu'
import { useDictStore } from '@/store/modules/dict'
import { setWorktab } from '@/utils/navigation'
import { setPageTitle } from '@/utils/router'
import { RoutesAlias } from '../routesAlias'
import { staticRoutes } from '../routes/staticRoutes'
import { loadingService } from '@/utils/ui/loading'
import { useCommon } from '@/hooks/core/useCommon'
import { useWorktabStore } from '@/store/modules/worktab'
import { fetchGetUserInfo, fetchGetDictList, fetchGetMenuList } from '@/api/auth'
import type { InitializationRequestOptions } from '@/api/auth'
import { ApiStatus } from '@/utils/http/status'
import {
  HttpError,
  isHttpError,
  isRecoverableInitializationError,
  showError
} from '@/utils/http/error'
import { RouteRegistry, MenuProcessor, IframeRouteManager } from '../core'

let routeRegistry: RouteRegistry | null = null
const menuProcessor = new MenuProcessor()

let routeInitFailed = false
let routeInitErrorPage = 'Exception500'
let routeGeneration = 0
let resetTimer: ReturnType<typeof setTimeout> | null = null

class RouteInitializationCancelled extends Error {}
class NoAccessibleRoutesError extends Error {}
let routeInitPromise: Promise<AppRouteRecord[]> | null = null

export function getPendingLoading(): boolean {
  return loadingService.isLoading()
}

export function resetPendingLoading(): void {
  loadingService.hideLoading()
}

export function getRouteInitFailed(): boolean {
  return routeInitFailed
}

export function resetRouteInitState(): void {
  loadingService.hideLoading()
  routeGeneration += 1
  routeInitFailed = false
  routeInitPromise = null
}

export function setupBeforeEachGuard(router: Router): void {
  ensureRouteRegistry(router)

  router.beforeEach(
    async (
      to: RouteLocationNormalized,
      from: RouteLocationNormalized,
      next: NavigationGuardNext
    ) => {
      try {
        await handleRouteGuard(to, from, next, router)
      } catch (error) {
        console.error('[RouteGuard] Failed to process beforeEach guard:', error)
        // 守卫自身抛错属于不可控路径，遮罩必须无条件释放
        loadingService.forceHide()
        next({ name: 'Exception500' })
      }
    }
  )
}

export async function ensureDynamicRoutesReady(
  router: Router,
  options: { showLoading?: boolean; loadingOwner?: object } = {}
): Promise<AppRouteRecord[]> {
  ensureRouteRegistry(router)

  const userStore = useUserStore()
  if (!userStore.isLogin) {
    return []
  }

  if (routeRegistry?.isRegistered()) {
    return useMenuStore().menuList
  }

  if (options.showLoading) {
    openLoading(options.loadingOwner)
  }

  if (!routeInitPromise) {
    const generation = routeGeneration
    routeInitPromise = initializeDynamicRoutes(router, generation)
      .then((menuList) => {
        if (generation === routeGeneration) routeInitFailed = false
        return menuList
      })
      .catch((error) => {
        if (generation !== routeGeneration) throw new RouteInitializationCancelled()
        if (!isUnauthorizedError(error)) {
          routeInitFailed = true
          routeInitErrorPage = initializationErrorPage(error)
          if (isHttpError(error)) showError(error)
        }
        throw error
      })
      .finally(() => {
        if (generation === routeGeneration) routeInitPromise = null
      })
  }

  return routeInitPromise
}

function ensureRouteRegistry(router: Router): void {
  if (!routeRegistry) {
    routeRegistry = new RouteRegistry(router)
  }
}

function closeLoading(owner: object): void {
  nextTick(() => loadingService.hideLoading(owner))
}

function openLoading(owner?: object): void {
  loadingService.showLoading(owner)
}

function shouldShowRouteLoading(
  to: RouteLocationNormalized,
  userStore: ReturnType<typeof useUserStore>
): boolean {
  return userStore.isLogin && shouldInitializeDynamicRoutes(to)
}

async function handleRouteGuard(
  to: RouteLocationNormalized,
  from: RouteLocationNormalized,
  next: NavigationGuardNext,
  router: Router
): Promise<void> {
  const settingStore = useSettingStore()
  const userStore = useUserStore()

  if (settingStore.showNprogress) {
    NProgress.start()
  }

  if (!handleLoginStatus(to, userStore, next)) {
    return
  }

  // 首次登录强制改密：改密前只允许停留在改密页（后端同样会拒绝其他接口）
  if (
    userStore.isLogin &&
    userStore.info?.must_change_password === true &&
    to.name !== 'ChangePassword'
  ) {
    next({ name: 'ChangePassword', replace: true })
    return
  }

  if (shouldShowRouteLoading(to, userStore)) {
    openLoading(to)
  }

  if (routeInitFailed) {
    closeLoading(to)
    if (isStaticRoute(to.path)) {
      next()
    } else {
      next({ name: routeInitErrorPage, replace: true })
    }
    return
  }

  if (shouldInitializeDynamicRoutes(to) && !routeRegistry?.isRegistered() && userStore.isLogin) {
    try {
      const menuList = await ensureDynamicRoutesReady(router, {
        showLoading: true,
        loadingOwner: to
      })
      continueWithDynamicRoutes(to, next, router, menuList)
    } catch (error) {
      console.error('[RouteGuard] Failed to initialize dynamic routes:', error)
      closeLoading(to)

      if (error instanceof RouteInitializationCancelled || isUnauthorizedError(error)) {
        next(false)
        return
      }

      if (isHttpError(error)) {
        console.error(`[RouteGuard] Http error ${error.code}: ${error.message}`)
      }

      next({ name: initializationErrorPage(error), replace: true })
    }
    return
  }

  if (handleRootPathRedirect(to, next, router)) {
    return
  }

  if (to.matched.length > 0) {
    setWorktab(to)
    setPageTitle(to)
    next()
    return
  }

  next({ name: 'Exception404' })
}

function shouldInitializeDynamicRoutes(to: RouteLocationNormalized): boolean {
  return !isStaticRoute(to.path)
}

function continueWithDynamicRoutes(
  to: RouteLocationNormalized,
  next: NavigationGuardNext,
  router: Router,
  menuList: AppRouteRecord[]
): void {
  if (isStaticRoute(to.path)) {
    // 目标是静态路由时后续导航不会再开遮罩，这里必须自己收尾，否则遮罩永久残留
    closeLoading(to)
    next({
      path: to.path,
      query: to.query,
      hash: to.hash,
      replace: true
    })
    return
  }

  if (!isRegisteredRouteTarget(router, to)) {
    closeLoading(to)
    const { homePath } = useCommon()
    const fallbackPath =
      resolvableFallbackPath(router, homePath.value) ||
      findDefaultMenuPath(router, menuList) ||
      '/'
    console.warn(`[RouteGuard] Missing route for path "${to.path}", redirecting to home page.`)
    // 静默跳首页会让用户以为「点了没反应」，必须给出可见解释
    notifyFallbackRedirect('该页面不可用或无访问权限，已返回首页。')
    next({
      path: fallbackPath,
      replace: true
    })
    return
  }

  next({
    path: to.path,
    query: to.query,
    hash: to.hash,
    replace: true
  })
}

function isRegisteredRouteTarget(router: Router, to: RouteLocationNormalized): boolean {
  const resolved = router.resolve({
    path: to.path,
    query: to.query,
    hash: to.hash
  })

  if (resolved.name === 'Exception404') {
    return false
  }

  return resolved.matched.length > 0
}

/**
 * 候选兜底路径必须先验证可解析，否则会把用户送进另一个 404/空白
 *
 * 只接受绝对路径：相对路径会被 vue-router 按「当前位置」解析，
 * 可能解析成一个与预期完全无关却恰好匹配的路由。
 */
function resolvableFallbackPath(router: Router, path: string | undefined): string {
  if (!path || path === '/' || !path.startsWith('/')) return ''
  try {
    const resolved = router.resolve({ path })
    if (resolved.name === 'Exception404' || resolved.matched.length === 0) return ''
    return path
  } catch (error) {
    console.warn(`[RouteGuard] Fallback path "${path}" is not resolvable:`, error)
    return ''
  }
}

function findDefaultMenuPath(router: Router, menuList: AppRouteRecord[]): string {
  for (const item of menuList) {
    if (typeof item.redirect === 'string') {
      const redirect = resolvableFallbackPath(router, item.redirect)
      if (redirect) {
        return redirect
      }
    }

    if (item.children?.length) {
      const childPath = findDefaultMenuPath(router, item.children)
      if (childPath) {
        return childPath
      }
    }

    if (item.path && item.meta?.isHide !== true) {
      const candidate = resolvableFallbackPath(router, item.path)
      if (candidate) {
        return candidate
      }
    }
  }

  return ''
}

/** 兜底重定向的可见提示（同一轮导航只提示一次，避免连环跳转刷屏） */
let lastFallbackNoticeAt = 0
function notifyFallbackRedirect(message: string): void {
  const now = Date.now()
  if (now - lastFallbackNoticeAt < 1000) return
  lastFallbackNoticeAt = now
  ElMessage({
    type: 'warning',
    offset: 40,
    duration: 4000,
    message
  })
}

function handleLoginStatus(
  to: RouteLocationNormalized,
  userStore: ReturnType<typeof useUserStore>,
  next: NavigationGuardNext
): boolean {
  const requiresAuth = to.matched.some((record) => record.meta?.requiresAuth === true)

  if (userStore.isLogin) {
    return true
  }

  if (!requiresAuth && (to.path === RoutesAlias.Login || isStaticRoute(to.path))) {
    return true
  }

  userStore.logOut()
  next({
    name: 'Login',
    query: { redirect: to.fullPath }
  })
  return false
}

function isStaticRoute(path: string): boolean {
  const checkRoute = (routes: any[], targetPath: string): boolean => {
    return routes.some((route) => {
      if (route.name === 'Exception404') {
        return false
      }

      const routePath = route.path
      const pattern = routePath.replace(/:[^/]+/g, '[^/]+').replace(/\*/g, '.*')
      const regex = new RegExp(`^${pattern}$`)

      if (regex.test(targetPath)) {
        return true
      }

      if (route.children?.length) {
        return checkRoute(route.children, targetPath)
      }

      return false
    })
  }

  return checkRoute(staticRoutes, path)
}

async function initializeDynamicRoutes(
  router: Router,
  generation: number
): Promise<AppRouteRecord[]> {
  // A shared deadline preserves the normal GET timeout, including retry waits.
  const deadline = Date.now() + 15000
  let stopped = false
  const assertCurrent = () => {
    if (stopped || generation !== routeGeneration || !useUserStore().isLogin) {
      throw new RouteInitializationCancelled()
    }
  }
  const timeoutError = () =>
    new HttpError('初始化请求超时，请稍后重试', ApiStatus.requestTimeout, {
      transport: 'timeout'
    })
  const read = async <T>(
    fetch: (options: InitializationRequestOptions) => Promise<T>
  ): Promise<T> => {
    for (let attempt = 0; ; attempt += 1) {
      assertCurrent()
      const remaining = deadline - Date.now()
      if (remaining <= 0) {
        throw timeoutError()
      }
      try {
        const result = await fetch({ timeout: remaining, showErrorMessage: false })
        assertCurrent()
        if (Date.now() >= deadline) throw timeoutError()
        return result
      } catch (error) {
        assertCurrent()
        if (
          !isRecoverableInitializationError(error) ||
          attempt >= 5 ||
          deadline - Date.now() <= 1000
        ) {
          throw error
        }
        await new Promise<void>((resolve) => setTimeout(resolve, 1000))
      }
    }
  }
  // Retry only the reads; menu processing and state mutation never run in a retry.
  const [userInfo, dictList, menuList] = await Promise.all([
    read(fetchGetUserInfo),
    read(fetchGetDictList),
    menuProcessor.getMenuList(() => read(fetchGetMenuList))
  ]).catch((error: unknown) => {
    stopped = true
    throw error
  })
  if (generation !== routeGeneration || !useUserStore().isLogin) {
    throw new RouteInitializationCancelled()
  }
  if (Array.isArray(menuList) && menuList.length === 0) {
    throw new NoAccessibleRoutesError('No accessible menu routes.')
  }
  if (!menuProcessor.validateMenuList(menuList)) {
    throw new Error('Failed to load menu list, please login again.')
  }

  const userStore = useUserStore()
  userStore.setUserInfo(userInfo)
  userStore.checkAndClearWorktabs()
  useDictStore().setDictList(dictList)

  routeRegistry?.register(menuList)

  const menuStore = useMenuStore()
  menuStore.setMenuList(menuList)
  menuStore.addRemoveRouteFns(routeRegistry?.getRemoveRouteFns() || [])

  IframeRouteManager.getInstance().save()
  useWorktabStore().validateWorktabs(router)

  return menuList
}

export function resetRouterState(delay: number): void {
  if (resetTimer !== null) {
    clearTimeout(resetTimer)
    resetTimer = null
  }
  // Invalidate pending requests immediately, even when route removal is delayed.
  resetRouteInitState()
  const reset = () => {
    resetTimer = null
    routeRegistry?.unregister()
    IframeRouteManager.getInstance().clear()

    const menuStore = useMenuStore()
    menuStore.removeAllDynamicRoutes()
    menuStore.setMenuList([])
  }
  if (delay > 0) {
    resetTimer = setTimeout(reset, delay)
  } else {
    reset()
  }
}

function initializationErrorPage(error: unknown): string {
  return error instanceof NoAccessibleRoutesError ||
    (isHttpError(error) && error.code === ApiStatus.forbidden)
    ? 'Exception403'
    : 'Exception500'
}

function handleRootPathRedirect(
  to: RouteLocationNormalized,
  next: NavigationGuardNext,
  router: Router
): boolean {
  if (to.path !== '/') {
    return false
  }

  const { homePath } = useCommon()
  const target = resolvableFallbackPath(router, homePath.value)
  if (target) {
    next({ path: target, replace: true })
    return true
  }

  if (homePath.value && homePath.value !== '/') {
    // 配置的首页解析不了：说明菜单/权限与配置不一致，必须让用户看见而不是静默落到 404
    console.warn(`[RouteGuard] Home path "${homePath.value}" is not resolvable.`)
    notifyFallbackRedirect('首页配置不可用，已停留在默认页面，请联系管理员检查菜单权限。')
  }

  return false
}

function isUnauthorizedError(error: unknown): boolean {
  return isHttpError(error) && error.code === ApiStatus.unauthorized
}
