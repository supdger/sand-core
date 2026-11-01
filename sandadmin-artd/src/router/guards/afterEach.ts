import { nextTick } from 'vue'
import { useSettingStore } from '@/store/modules/setting'
import { Router } from 'vue-router'
import NProgress from 'nprogress'
import { useCommon } from '@/hooks/core/useCommon'
import { loadingService } from '@/utils/ui/loading'
import { describeError, showReloadPrompt } from '@/utils/sys/error-handle'

/** 懒加载 chunk 失败的典型特征（跨浏览器文案不统一，只能按关键字匹配） */
const CHUNK_ERROR_PATTERNS = [
  'failed to fetch dynamically imported module',
  'error loading dynamically imported module',
  'importing a module script failed',
  'unable to preload css',
  'failed to import',
  'dynamically imported module'
]

/** 判断是否为动态 import / chunk 加载失败 */
function isChunkLoadError(error: unknown): boolean {
  const text = describeError(error).toLowerCase()
  return CHUNK_ERROR_PATTERNS.some((pattern) => text.includes(pattern))
}

/**
 * 监听 vite 预加载失败
 *
 * 发布后浏览器仍持有旧 index.html 时，hashed chunk 已 404：
 * 默认行为是静默终止导航留下空屏，这里改成可见提示 + 一键重载。
 */
function setupPreloadErrorListener(): void {
  window.addEventListener('vite:preloadError', (event: Event) => {
    // 阻止默认抛出，交由提示条引导用户重载
    event.preventDefault()
    console.error('[PreloadError]', event)
    showReloadPrompt('检测到前端资源已更新，当前页面的部分模块无法加载。')
  })
}

/** 路由全局后置守卫 */
export function setupAfterEachGuard(router: Router) {
  const { scrollToTop } = useCommon()

  router.afterEach((to) => {
    scrollToTop()

    // 关闭进度条
    const settingStore = useSettingStore()
    if (settingStore.showNprogress) {
      NProgress.done()
      // 确保进度条完全移除，避免残影
      setTimeout(() => {
        NProgress.remove()
      }, 600)
    }

    // 关闭 loading 效果
    nextTick(() => loadingService.hideLoading(to))
  })

  router.onError((error) => {
    // 导航异常时遮罩必须无条件消失，否则用户只看到一片空白
    loadingService.forceHide()
    console.error('[RouterError]', error)

    if (isChunkLoadError(error)) {
      showReloadPrompt('页面资源加载失败，可能是系统刚完成更新。')
      return
    }

    ElMessage({
      type: 'error',
      offset: 40,
      duration: 5000,
      message: '页面跳转失败，请重试或刷新页面。'
    })
  })

  setupPreloadErrorListener()
}
