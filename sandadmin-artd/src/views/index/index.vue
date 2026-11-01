<!-- 布局容器 -->
<template>
  <div class="app-layout">
    <aside id="app-sidebar">
      <ArtSidebarMenu />
    </aside>

    <main id="app-main">
      <div id="app-header">
        <ArtHeaderBar />
      </div>
      <div id="app-content">
        <ArtPageContent />
      </div>
    </main>

    <div id="app-global">
      <ArtGlobalComponent />
    </div>
  </div>
</template>

<script setup lang="ts">
  // 静态 import：布局解析不得依赖 unplugin-vue-components 的内存映射，
  // 否则 src/components 被整体交换后会出现 "Failed to resolve component" 白屏。
  import {
    ArtSidebarMenu,
    ArtHeaderBar,
    ArtPageContent,
    ArtGlobalComponent
  } from '@/components/core/layouts/layout-components'

  defineOptions({ name: 'AppLayout' })
</script>

<style lang="scss" scoped>
  @use './style';
</style>
