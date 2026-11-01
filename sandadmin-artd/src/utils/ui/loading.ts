/**
 * 全局 Loading 加载管理模块
 *
 * 提供统一的全屏加载动画管理
 *
 * ## 主要功能
 *
 * - 全屏 Loading 显示和隐藏
 * - 自动适配明暗主题背景色
 * - 自定义 SVG 加载动画
 * - 单例模式防止重复创建
 * - 锁定页面交互
 *
 * ## 使用场景
 *
 * - 页面初始化加载
 * - 大量数据请求
 * - 路由切换过渡
 * - 异步操作等待
 *
 * ## 特性
 *
 * - 自动检测当前主题并应用对应背景色
 * - 使用自定义 SVG 动画（四点旋转）
 * - 单例模式确保同时只有一个 Loading
 * - 提供便捷的显示/隐藏方法
 *
 * @module utils/ui/loading
 * @author Art Design Pro Team
 */
import { fourDotsSpinnerSvg } from '@/assets/svg/loading'

/**
 * 获取当前主题对应的loading背景色
 * @returns 背景色字符串
 */
const getLoadingBackground = (): string => {
  const isDark = document.documentElement.classList.contains('dark')
  return isDark ? 'rgba(7, 7, 7, 0.85)' : '#fff'
}

const DEFAULT_LOADING_CONFIG = {
  lock: true,
  get background() {
    return getLoadingBackground()
  },
  svg: fourDotsSpinnerSvg,
  svgViewBox: '0 0 40 40',
  customClass: 'art-loading-fix'
} as const

interface LoadingInstance {
  close: () => void
}

let loadingInstance: LoadingInstance | null = null
let loadingOwner: object | null = null
let watchdogTimer: ReturnType<typeof setTimeout> | null = null

/**
 * 遮罩绝对看门狗上限（毫秒）
 *
 * 任何 owner 不一致、守卫早退、热替换或异常路径都可能漏掉 hideLoading，
 * 残留的全屏遮罩在用户眼里就是白屏死局，必须有硬上限强制关闭。
 */
const LOADING_WATCHDOG_TIMEOUT = 20000

function clearWatchdog(): void {
  if (watchdogTimer !== null) {
    clearTimeout(watchdogTimer)
    watchdogTimer = null
  }
}

function armWatchdog(): void {
  clearWatchdog()
  watchdogTimer = setTimeout(() => {
    watchdogTimer = null
    if (!loadingInstance) return
    console.warn('[Loading] 全屏遮罩超过上限未关闭，已强制关闭')
    loadingService.forceHide()
    ElMessage({
      type: 'warning',
      offset: 40,
      duration: 6000,
      message: '页面加载超时，已自动结束等待。请重试或刷新页面。'
    })
  }, LOADING_WATCHDOG_TIMEOUT)
}

export const loadingService = {
  /**
   * 显示 loading
   * @returns 关闭 loading 的函数
   */
  showLoading(owner: object = {}): () => void {
    if (!loadingInstance) {
      // 每次显示时获取最新的配置，确保背景色与当前主题同步
      const config = {
        ...DEFAULT_LOADING_CONFIG,
        background: getLoadingBackground()
      }
      loadingInstance = ElLoading.service(config)
    }
    loadingOwner = owner
    armWatchdog()
    return () => this.hideLoading(owner)
  },

  isLoading(): boolean {
    return loadingInstance !== null
  },

  /**
   * 隐藏 loading
   */
  hideLoading(owner?: object): void {
    // A cancelled navigation must not close a later navigation's loading.
    if (owner && owner !== loadingOwner) return
    this.forceHide()
  },

  /**
   * 无视 owner 强制关闭遮罩（看门狗、全局异常兜底使用）
   */
  forceHide(): void {
    clearWatchdog()
    if (loadingInstance) {
      try {
        loadingInstance.close()
      } catch (error) {
        console.warn('[Loading] 关闭遮罩失败:', error)
      }
      loadingInstance = null
      loadingOwner = null
    }
  }
}

if (import.meta.hot) {
  import.meta.hot.dispose(() => loadingService.forceHide())
}
