/**
 * 布局关键组件的确定性注册
 *
 * `unplugin-vue-components` 只在编译期维护一张内存映射：目录被整体 rename 交换
 * （宿主升级/恢复的在线目录交换）后映射失效，`AppLayout` 依赖的 `Art*` 组件会
 * 退化成未知元素，页面直接白屏且无任何报错出口。
 *
 * 这里对布局关键组件做静态 import + 全局注册，保证解析不依赖 watcher 状态。
 * 自动导入仍然生效时本模块不改变任何行为，仅作为确定性兜底。
 *
 * @module components/core/layouts/layout-components
 */
import type { App, Component } from 'vue'

import ArtSidebarMenu from './art-menus/art-sidebar-menu/index.vue'
import ArtHeaderBar from './art-header-bar/index.vue'
import ArtPageContent from './art-page-content/index.vue'
import ArtGlobalComponent from './art-global-component/index.vue'
import ArtLogo from '../base/art-logo/index.vue'

/** 布局关键组件表（名称与模板中引用的标签名一致） */
export const layoutComponents: Record<string, Component> = {
  ArtSidebarMenu,
  ArtHeaderBar,
  ArtPageContent,
  ArtGlobalComponent,
  ArtLogo
}

/**
 * 全局注册布局关键组件
 */
export function setupLayoutComponents(app: App<Element>): void {
  for (const [name, component] of Object.entries(layoutComponents)) {
    if (!app.component(name)) {
      app.component(name, component)
    }
  }
}

export { ArtSidebarMenu, ArtHeaderBar, ArtPageContent, ArtGlobalComponent, ArtLogo }
