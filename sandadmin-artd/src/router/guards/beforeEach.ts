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
import { fetchGetUserInfo, fetchGetDictList } from '@/api/auth'
import { ApiStatus } from '@/utils/http/status'
import { isHttpError } from '@/utils/http/error'
import { RouteRegistry, MenuProcessor, IframeRouteManager } from '../core'

let routeRegistry: RouteRegistry | null = null
const menuProcessor = new MenuProcessor()

let pendingLoading = false
let routeInitFailed = false
let routeInitErrorPage = 'Exception500'
let routeGeneration = 0
let resetTimer: ReturnType<typeof setTimeout> | null = null

class RouteInitializationCancelled extends Error {}
class NoAccessibleRoutesError extends Error {}
let routeInitPromise: Promise<AppRouteRecord[]> | null = null

export function getPendingLoading(): boolean {
  return pendingLoading
}

export function resetPendingLoading(): void {
  pendingLoading = false
}

export function getRouteInitFailed(): boolean {
  return routeInitFailed
}

export function resetRouteInitState(): void {
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
        closeLoading()
        next({ name: 'Exception500' })
      }
    }
  )
}

export async function ensureDynamicRoutesReady(
  router: Router,
  options: { showLoading?: boolean } = {}
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
    openLoading()
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

function closeLoading(): void {
  if (!pendingLoading) {
    return
  }

  nextTick(() => {
    loadingService.hideLoading()
    pendingLoading = false
  })
}

function openLoading(): void {
  if (pendingLoading) {
    return
  }

  pendingLoading = true
  loadingService.showLoading()
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

  if (shouldShowRouteLoading(to, userStore)) {
    openLoading()
  }

  if (routeInitFailed) {
    closeLoading()
    if (isStaticRoute(to.path)) {
      next()
    } else {
      next({ name: routeInitErrorPage, replace: true })
    }
    return
  }

  if (shouldInitializeDynamicRoutes(to) && !routeRegistry?.isRegistered() && userStore.isLogin) {
    try {
      const menuList = await ensureDynamicRoutesReady(router, { showLoading: true })
      continueWithDynamicRoutes(to, next, router, menuList)
    } catch (error) {
      console.error('[RouteGuard] Failed to initialize dynamic routes:', error)
      closeLoading()

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

  if (handleRootPathRedirect(to, next)) {
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
    next({
      path: to.path,
      query: to.query,
      hash: to.hash,
      replace: true
    })
    return
  }

  if (!isRegisteredRouteTarget(router, to)) {
    closeLoading()
    const { homePath } = useCommon()
    const fallbackPath = homePath.value || findDefaultMenuPath(menuList) || '/'
    console.warn(`[RouteGuard] Missing route for path "${to.path}", redirecting to home page.`)
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

function findDefaultMenuPath(menuList: AppRouteRecord[]): string {
  for (const item of menuList) {
    if (typeof item.redirect === 'string' && item.redirect !== '/') {
      return item.redirect
    }

    if (item.children?.length) {
      const childPath = findDefaultMenuPath(item.children)
      if (childPath) {
        return childPath
      }
    }

    if (item.path && item.path !== '/' && item.meta?.isHide !== true) {
      return item.path
    }
  }

  return ''
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
  // 并行获取用户信息、字典数据和菜单列表，减少串行等待的网络延迟
  const [userInfo, dictList, menuList] = await Promise.all([
    fetchGetUserInfo(),
    fetchGetDictList(),
    menuProcessor.getMenuList()
  ])
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

function handleRootPathRedirect(to: RouteLocationNormalized, next: NavigationGuardNext): boolean {
  if (to.path !== '/') {
    return false
  }

  const { homePath } = useCommon()
  if (homePath.value && homePath.value !== '/') {
    next({ path: homePath.value, replace: true })
    return true
  }

  return false
}

function isUnauthorizedError(error: unknown): boolean {
  return isHttpError(error) && error.code === ApiStatus.unauthorized
}
