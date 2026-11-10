/**
 * 全局错误处理模块
 *
 * 统一错误捕获与可见兜底：Vue 运行时错误、全局脚本错误、未处理 Promise、
 * 静态资源/懒加载 chunk 失败、应用启动失败。
 *
 * 可见兜底的用户约定（H1）：
 * - 文案固定为「系统出了点问题，请稍后再试。」
 * - 仅两个操作：〔重新加载〕〔复制问题信息〕
 * - 复制内容只含错误码、时间、版本、request id；绝不包含绝对路径、
 *   堆栈、密钥或任何源码细节
 * - 重新加载有界：30 秒内最多自动/手动重试 1 次，避免无限 reload 循环
 *
 * @module utils/sys/error-handle
 */
import type { App, ComponentPublicInstance } from 'vue'

/** 致命错误兜底容器的节点 id */
const FATAL_FALLBACK_ID = 'app-fatal-fallback'

/** 可见重载提示的节点 id */
const RELOAD_PROMPT_ID = 'app-reload-prompt'

/** 统一用户提示文案 */
const FALLBACK_MESSAGE = '系统出了点问题，请稍后再试。'

/** 重载节流窗口（毫秒）：窗口内再次触发重载会被忽略 */
const RELOAD_WINDOW_MS = 30_000

/** 最近一次重载触发时间（模块内记忆，防无限 reload） */
let lastReloadAt = 0

/** 最近一次可诊断错误码（供复制问题信息；仅存错误码，不含详情） */
let lastErrorCode = 'UNKNOWN'

/**
 * 把未知错误归一成可读摘要（仅用于控制台诊断，不进入页面或复制内容）
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
 * 清空本地持久化状态（供异常页「重置本地状态」显式操作使用）
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
 * 归一错误为稳定错误码：优先 Error.name，其次消息首词大写蛇形。
 * 输出只含字母数字下划线，供「复制问题信息」使用，绝不携带路径或堆栈。
 */
export function errorCodeOf(error: unknown): string {
  if (error instanceof Error && error.name) {
    const name = error.name.replace(/[^A-Za-z0-9]+/g, '_').replace(/^_+|_+$/g, '')
    if (name !== '') return name.toUpperCase()
  }
  if (error instanceof Event && error.type) {
    return `EVENT_${error.type.toUpperCase()}`
  }
  return 'UNKNOWN'
}

/** 有界重载：30 秒内最多一次，窗口内重复触发只提示不重载 */
export function guardedReload(notify?: (message: string) => void): boolean {
  const now = Date.now()
  if (now - lastReloadAt < RELOAD_WINDOW_MS) {
    notify?.('刚刚已尝试重新加载，请稍等片刻后再试。')
    return false
  }
  lastReloadAt = now
  window.location.reload()
  return true
}

/**
 * 组装「复制问题信息」的脱敏内容：仅错误码、时间、版本、request id。
 * request id 来自最近一个带 X-Request-Id 的响应（可选，无则省略）。
 */
export function problemReport(errorCode = lastErrorCode): string {
  const lines = [`error_code: ${errorCode}`, `time: ${new Date().toISOString()}`]
  const version = import.meta.env?.VITE_VERSION
  if (version) lines.push(`version: ${version}`)
  const requestId = lastRequestId()
  if (requestId) lines.push(`request_id: ${requestId}`)
  return lines.join('\n')
}

/** 读取最近一次服务端 request id（由 http 层登记；无则空串） */
function lastRequestId(): string {
  try {
    return sessionStorage.getItem('sand:last-request-id') || ''
  } catch {
    return ''
  }
}

/** 供 http 层登记最近一次 request id（仅 request id 字符串本身） */
export function recordRequestId(id: string): void {
  try {
    if (/^[A-Za-z0-9_-]{1,128}$/.test(id)) sessionStorage.setItem('sand:last-request-id', id)
  } catch {
    /* storage 不可用时静默 */
  }
}

/** 复制脱敏问题信息到剪贴板；失败时回退到 textarea 复制 */
export async function copyProblemReport(errorCode = lastErrorCode): Promise<boolean> {
  const text = problemReport(errorCode)
  try {
    await navigator.clipboard.writeText(text)
    return true
  } catch {
    try {
      const area = document.createElement('textarea')
      area.value = text
      area.style.cssText = 'position:fixed;left:-9999px;top:0'
      document.body.appendChild(area)
      area.select()
      const ok = document.execCommand('copy')
      area.remove()
      return ok
    } catch {
      return false
    }
  }
}

function makeButton(label: string, primary: boolean, onClick: () => void): HTMLButtonElement {
  const button = document.createElement('button')
  button.type = 'button'
  button.textContent = label
  button.style.cssText = primary
    ? 'padding:8px 20px;border:none;border-radius:6px;background:#409eff;color:#fff;cursor:pointer;font-size:14px'
    : 'padding:8px 20px;border:1px solid #dcdfe6;border-radius:6px;background:#fff;color:#606266;cursor:pointer;font-size:14px'
  button.onclick = onClick
  return button
}

function makeActions(context: string): HTMLDivElement {
  const actions = document.createElement('div')
  actions.style.cssText = 'display:flex;gap:12px;flex-wrap:wrap;justify-content:center'

  const reload = makeButton('重新加载', true, () =>
    guardedReload((note) => {
      reload.textContent = note
      reload.disabled = true
    })
  )
  actions.appendChild(reload)

  const copy = makeButton('复制问题信息', false, () => {
    void copyProblemReport().then((ok) => {
      copy.textContent = ok ? '已复制' : '复制失败'
      if (ok) setTimeout(() => (copy.textContent = '复制问题信息'), 2000)
    })
  })
  actions.appendChild(copy)

  const note = document.createElement('p')
  note.textContent = context
  note.style.cssText = 'margin:0;font-size:12px;color:#909399;width:100%;text-align:center'
  actions.appendChild(note)

  return actions
}

/**
 * 渲染一个可见的重载提示条（纯 DOM，不依赖 Vue / Element Plus）
 *
 * 用于脚本/样式资源加载失败、懒加载 chunk 失败等「页面已经不可信」的场景。
 */
export function showReloadPrompt(_message?: string, detail?: string): void {
  if (typeof document === 'undefined' || !document.body) return
  if (document.getElementById(RELOAD_PROMPT_ID)) return
  if (detail) console.error('[ReloadPrompt] detail:', detail)

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
  text.textContent = FALLBACK_MESSAGE
  bar.appendChild(text)

  const reload = document.createElement('button')
  reload.type = 'button'
  reload.textContent = '重新加载'
  reload.style.cssText =
    'flex:none;padding:5px 14px;border:none;border-radius:4px;background:#7a5c00;color:#fff;cursor:pointer;font-size:14px'
  reload.onclick = () =>
    guardedReload((note) => {
      reload.textContent = note
      reload.disabled = true
    })
  bar.appendChild(reload)

  const copy = document.createElement('button')
  copy.type = 'button'
  copy.textContent = '复制问题信息'
  copy.style.cssText =
    'flex:none;padding:5px 14px;border:1px solid #ffe08a;border-radius:4px;background:transparent;color:#7a5c00;cursor:pointer;font-size:14px'
  copy.onclick = () => {
    void copyProblemReport().then((ok) => {
      copy.textContent = ok ? '已复制' : '复制失败'
      if (ok) setTimeout(() => (copy.textContent = '复制问题信息'), 2000)
    })
  }
  bar.appendChild(copy)

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
 * 页面只展示固定文案与两个操作；错误码进入「复制问题信息」，堆栈只进控制台。
 */
export function renderFatalFallback(error: unknown, context = '应用启动失败'): void {
  if (typeof document === 'undefined') return
  console.error('[FatalError]', context, error)
  lastErrorCode = errorCodeOf(error)

  const root = document.getElementById('app')
  if (!root) return
  if (document.getElementById(FATAL_FALLBACK_ID)) return

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
  title.textContent = FALLBACK_MESSAGE
  title.style.cssText = 'margin:0;font-size:20px;font-weight:600'
  container.appendChild(title)

  container.appendChild(makeActions(`错误码：${lastErrorCode}`))

  root.innerHTML = ''
  root.appendChild(container)
}

/**
 * Vue 运行时错误处理：渲染失败即不可信，弹出与路由失败一致的兜底条。
 */
export function vueErrorHandler(
  err: unknown,
  instance: ComponentPublicInstance | null,
  info: string
): void {
  console.error('[VueError]', err, info, instance)
  lastErrorCode = errorCodeOf(err)
  showReloadPrompt()
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
  lastErrorCode = errorCodeOf(error ?? message)
  return false
}

/**
 * Promise 未捕获错误处理
 */
export function registerPromiseErrorHandler() {
  window.addEventListener('unhandledrejection', (event) => {
    console.error('[PromiseError]', event.reason)
    lastErrorCode = errorCodeOf(event.reason)
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
        lastErrorCode = 'RESOURCE_LOAD_FAILED'

        // 脚本/样式加载失败意味着页面已不完整（常见于发布后旧 hash 资源 404），
        // 必须给出可见的重载出口，不能只留一行控制台日志。
        if (target.tagName === 'SCRIPT' || target.tagName === 'LINK') {
          showReloadPrompt()
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
