/**
 * 全局错误处理模块
 *
 * 提供统一的错误捕获和处理机制
 *
 * ## 主要功能
 *
 * - Vue 运行时错误捕获（组件错误、生命周期错误等）
 * - 全局脚本错误捕获（语法错误、运行时错误等）
 * - Promise 未捕获错误处理（unhandledrejection）
 * - 静态资源加载错误监控（图片、脚本、样式等）
 * - 错误日志记录和上报
 * - 统一的错误处理入口
 *
 * ## 使用场景
 * - 应用启动时安装全局错误处理器
 * - 捕获和记录所有类型的错误
 * - 错误上报到监控平台
 * - 提升应用稳定性和可维护性
 * - 问题排查和调试
 *
 * ## 错误类型
 *
 * - VueError: Vue 组件相关错误
 * - ScriptError: JavaScript 脚本错误
 * - PromiseError: Promise 未捕获的 rejection
 * - ResourceError: 静态资源加载失败
 *
 * @module utils/sys/error-handle
 * @author Art Design Pro Team
 */
import type { App, ComponentPublicInstance } from 'vue'

/** 致命错误兜底容器的节点 id */
const FATAL_FALLBACK_ID = 'app-fatal-fallback'

/** 可见重载提示的节点 id */
const RELOAD_PROMPT_ID = 'app-reload-prompt'

/**
 * 把未知错误归一成可读摘要（不依赖任何组件库）
 */
export function describeError(error: unknown): string {
  if (error instanceof Error) {
    return error.stack || `${error.name}: ${error.message}`
  }
  if (typeof error === 'string') {
    return error
  }
  try {
    return JSON.stringify(error)
  } catch {
    return String(error)
  }
}

/**
 * 清空本地持久化状态（供错误页「重置本地状态」使用）
 */
export function resetLocalState(): void {
  try {
    localStorage.clear()
  } catch (error) {
    console.warn('[ErrorHandle] 清理 localStorage 失败:', error)
  }
  try {
    sessionStorage.clear()
  } catch (error) {
    console.warn('[ErrorHandle] 清理 sessionStorage 失败:', error)
  }
}

/**
 * 渲染一个可见的重载提示条（纯 DOM，不依赖 Vue / Element Plus）
 *
 * 用于脚本/样式资源加载失败、懒加载 chunk 失败等「页面已经不可信」的场景。
 */
export function showReloadPrompt(message: string, detail?: string): void {
  if (typeof document === 'undefined' || !document.body) return
  if (document.getElementById(RELOAD_PROMPT_ID)) return

  const bar = document.createElement('div')
  bar.id = RELOAD_PROMPT_ID
  bar.setAttribute('role', 'alert')
  bar.style.cssText = [
    'position:fixed',
    'z-index:99999',
    'top:0',
    'left:0',
    'right:0',
    'padding:12px 16px',
    'background:#fff3cd',
    'color:#7a5c00',
    'border-bottom:1px solid #ffe08a',
    'font-size:14px',
    'line-height:1.6',
    'display:flex',
    'align-items:center',
    'gap:12px',
    'box-shadow:0 2px 8px rgba(0,0,0,.08)'
  ].join(';')

  const text = document.createElement('span')
  text.style.cssText = 'flex:1;word-break:break-all'
  text.textContent = detail ? `${message}（${detail}）` : message
  bar.appendChild(text)

  const reload = document.createElement('button')
  reload.type = 'button'
  reload.textContent = '刷新重试'
  reload.style.cssText =
    'flex:none;padding:5px 14px;border:none;border-radius:4px;background:#7a5c00;color:#fff;cursor:pointer;font-size:14px'
  reload.onclick = () => window.location.reload()
  bar.appendChild(reload)

  const dismiss = document.createElement('button')
  dismiss.type = 'button'
  dismiss.textContent = '忽略'
  dismiss.style.cssText =
    'flex:none;padding:5px 14px;border:1px solid #ffe08a;border-radius:4px;background:transparent;color:#7a5c00;cursor:pointer;font-size:14px'
  dismiss.onclick = () => bar.remove()
  bar.appendChild(dismiss)

  document.body.appendChild(bar)
}

/**
 * 应用 bootstrap / mount 失败时，向 #app 写入纯静态兜底标记
 *
 * 不依赖 Vue、Element Plus 和自动导入组件：此时它们可能正是失败原因。
 */
export function renderFatalFallback(error: unknown, context = '应用启动失败'): void {
  if (typeof document === 'undefined') return
  console.error('[FatalError]', context, error)

  const root = document.getElementById('app')
  if (!root) return
  if (document.getElementById(FATAL_FALLBACK_ID)) return

  const summary = describeError(error)
  const container = document.createElement('div')
  container.id = FATAL_FALLBACK_ID
  container.style.cssText = [
    'box-sizing:border-box',
    'min-height:100vh',
    'padding:48px 24px',
    'display:flex',
    'flex-direction:column',
    'align-items:center',
    'justify-content:center',
    'gap:16px',
    'background:#fafbfc',
    'color:#303133',
    'font-size:14px',
    'line-height:1.7',
    'font-family:system-ui,-apple-system,"Segoe UI","Microsoft YaHei",sans-serif'
  ].join(';')

  const title = document.createElement('h1')
  title.textContent = context
  title.style.cssText = 'margin:0;font-size:20px;font-weight:600'
  container.appendChild(title)

  const tip = document.createElement('p')
  tip.textContent =
    '页面未能正常启动。可先尝试刷新；若反复失败，请重置本地状态后重新登录，并把下方错误信息反馈给管理员。'
  tip.style.cssText = 'margin:0;max-width:720px;text-align:center;color:#606266'
  container.appendChild(tip)

  const actions = document.createElement('div')
  actions.style.cssText = 'display:flex;gap:12px;flex-wrap:wrap;justify-content:center'

  const reload = document.createElement('button')
  reload.type = 'button'
  reload.textContent = '重新加载'
  reload.style.cssText =
    'padding:8px 20px;border:none;border-radius:6px;background:#409eff;color:#fff;cursor:pointer;font-size:14px'
  reload.onclick = () => window.location.reload()
  actions.appendChild(reload)

  const reset = document.createElement('button')
  reset.type = 'button'
  reset.textContent = '重置本地状态'
  reset.style.cssText =
    'padding:8px 20px;border:1px solid #dcdfe6;border-radius:6px;background:#fff;color:#606266;cursor:pointer;font-size:14px'
  reset.onclick = () => {
    resetLocalState()
    window.location.reload()
  }
  actions.appendChild(reset)
  container.appendChild(actions)

  const details = document.createElement('details')
  details.style.cssText = 'max-width:960px;width:100%'
  const summaryNode = document.createElement('summary')
  summaryNode.textContent = '错误详情'
  summaryNode.style.cssText = 'cursor:pointer;color:#909399'
  details.appendChild(summaryNode)
  const pre = document.createElement('pre')
  pre.textContent = summary
  pre.style.cssText =
    'margin:12px 0 0;padding:12px;max-height:40vh;overflow:auto;background:#f5f7fa;border:1px solid #e4e7ed;border-radius:6px;white-space:pre-wrap;word-break:break-all;color:#f56c6c'
  details.appendChild(pre)
  container.appendChild(details)

  root.innerHTML = ''
  root.appendChild(container)
}

/**
 * Vue 运行时错误处理
 */
export function vueErrorHandler(
  err: unknown,
  instance: ComponentPublicInstance | null,
  info: string
): void {
  console.error('[VueError]', err, info, instance)
  // 这里可以上报到服务端，比如：
  // reportError({ type: 'vue', err, info })
}

/**
 * 全局脚本错误处理
 *
 * 返回 false：不吞掉默认行为，浏览器控制台保留原生堆栈，便于定位白屏根因。
 */
export function scriptErrorHandler(
  message: Event | string,
  source?: string,
  lineno?: number,
  colno?: number,
  error?: Error
): boolean {
  console.error('[ScriptError]', { message, source, lineno, colno, error })
  // reportError({ type: 'script', message, source, lineno, colno, error })
  return false
}

/**
 * Promise 未捕获错误处理
 */
export function registerPromiseErrorHandler() {
  window.addEventListener('unhandledrejection', (event) => {
    console.error('[PromiseError]', event.reason)
    // reportError({ type: 'promise', reason: event.reason })
  })
}

/**
 * 资源加载错误处理 (img, script, css...)
 */
export function registerResourceErrorHandler() {
  window.addEventListener(
    'error',
    (event: Event) => {
      const target = event.target
      if (!(target instanceof HTMLElement)) return
      if (
        target.tagName === 'IMG' ||
        target.tagName === 'SCRIPT' ||
        target.tagName === 'LINK'
      ) {
        const src =
          target instanceof HTMLImageElement
            ? target.src
            : target instanceof HTMLScriptElement
              ? target.src
              : target instanceof HTMLLinkElement
                ? target.href
                : ''
        console.error('[ResourceError]', { tagName: target.tagName, src })
        // reportError({ type: 'resource', target })

        // 脚本/样式加载失败意味着页面已不完整（常见于发布后旧 hash 资源 404），
        // 必须给出可见的重载出口，不能只留一行控制台日志。
        if (target.tagName === 'SCRIPT' || target.tagName === 'LINK') {
          showReloadPrompt('检测到前端资源加载失败，页面可能显示不完整。', src)
        }
      }
    },
    true // 捕获阶段才能监听到资源错误
  )
}

/**
 * 安装统一错误处理
 */
export function setupErrorHandle(app: App) {
  app.config.errorHandler = vueErrorHandler
  window.onerror = scriptErrorHandler
  registerPromiseErrorHandler()
  registerResourceErrorHandler()
}
