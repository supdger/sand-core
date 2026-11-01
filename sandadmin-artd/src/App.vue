<template>
  <!-- 静态错误边界放在最外层：ElConfigProvider / 布局组件自身失败时也能兜底 -->
  <StaticErrorBoundary>
    <ElConfigProvider size="default" :locale="locales[language]" :z-index="3000">
      <RouterView></RouterView>
    </ElConfigProvider>
  </StaticErrorBoundary>
</template>

<script setup lang="ts">
  import { useUserStore } from './store/modules/user'
  import zh from 'element-plus/es/locale/lang/zh-cn'
  import en from 'element-plus/es/locale/lang/en'
  import { systemUpgrade } from './utils/sys'
  import { toggleTransition } from './utils/ui/animation'
  import { checkStorageCompatibility } from './utils/storage'
  import { initializeTheme } from './hooks/core/useTheme'
  // 静态引入：错误边界不能依赖自动导入的内存映射，否则映射丢失时连兜底都没有
  import StaticErrorBoundary from './components/StaticErrorBoundary.vue'

  const userStore = useUserStore()
  const { language } = storeToRefs(userStore)

  const locales = {
    zh: zh,
    en: en
  }

  onBeforeMount(() => {
    toggleTransition(true)
    initializeTheme()
  })

  onMounted(() => {
    // 传入真实登录态：否则腐坏检测被 requireAuth=false 永久短路，坏数据会直接喂给布局组件
    checkStorageCompatibility(userStore.isLogin)
    toggleTransition(false)
    systemUpgrade()
  })
</script>
