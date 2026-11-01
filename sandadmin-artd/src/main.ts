import App from './App.vue'
import { createApp } from 'vue'
import { initStore } from './store'                 // Store
import { initRouter } from './router'               // Router
import language from './locales'                    // 国际化
import '@styles/core/tailwind.css'                  // tailwind
import '@styles/index.scss'                         // 样式
import '@utils/sys/console.ts'                      // 控制台输出内容
import { setupGlobDirectives } from './directives'
import { setupErrorHandle, renderFatalFallback } from './utils/sys/error-handle'
import { setupLayoutComponents } from './components/core/layouts/layout-components'

document.addEventListener(
  'touchstart',
  function () {},
  { passive: false }
)

// bootstrap 任一步骤抛错都必须落到可诊断的静态兜底页，绝不留空白 #app
try {
  const app = createApp(App)
  initStore(app)
  initRouter(app)
  setupGlobDirectives(app)
  setupErrorHandle(app)
  // 布局组件全局注册：不依赖 dev-server watcher 的内存映射
  setupLayoutComponents(app)

  app.use(language)
  app.mount('#app')
} catch (error) {
  renderFatalFallback(error, '应用启动失败')
}