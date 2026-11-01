<!-- 布局内容 -->
<template>
  <div class="layout-content" :class="{ 'overflow-auto': isFullPage }" :style="containerStyle">
    <div id="app-content-header">
      <!-- 节日滚动 -->
      <ArtFestivalTextScroll v-if="!isFullPage" />

      <!-- 路由信息调试 -->
      <div
        v-if="isOpenRouteInfo === 'true'"
        class="px-2 py-1.5 mb-3 text-sm text-g-500 bg-g-200 border-full-d rounded-md"
      >
        router meta：{{ route.meta }}
      </div>
    </div>

    <RouterView v-if="isRefresh" v-slot="{ Component, route }" :style="contentStyle">
      <!-- 缓存路由动画 -->
      <Transition :name="showTransitionMask ? '' : actualTransition" mode="out-in" appear>
        <KeepAlive :max="10" :exclude="keepAliveExclude">
          <component
            class="art-page-view"
            :is="Component"
            :key="route.path"
            v-if="route.meta.keepAlive"
          />
        </KeepAlive>
      </Transition>

      <!-- 非缓存路由动画 -->
      <Transition :name="showTransitionMask ? '' : actualTransition" mode="out-in" appear>
        <component
          class="art-page-view"
          :is="Component"
          :key="route.path"
          v-if="!route.meta.keepAlive"
        />
      </Transition>
    </RouterView>

    <!-- 全屏页面切换过渡遮罩（用于提升页面切换视觉体验） -->
    <Teleport to="body">
      <div
        v-show="showTransitionMask"
        class="fixed top-0 left-0 z-[2000] w-screen h-screen pointer-events-none bg-box"
      />
    </Teleport>
  </div>
</template>
<script setup lang="ts">
  import type { CSSProperties } from 'vue'
  import { useRoute } from 'vue-router'
  import { useAutoLayoutHeight } from '@/hooks/core/useLayoutHeight'
  import { useSettingStore } from '@/store/modules/setting'
  import { useWorktabStore } from '@/store/modules/worktab'

  defineOptions({ name: 'ArtPageContent' })

  const route = useRoute()
  const { containerMinHeight } = useAutoLayoutHeight()
  const { pageTransition, containerWidth, refresh } = storeToRefs(useSettingStore())
  const { keepAliveExclude } = storeToRefs(useWorktabStore())

  const isRefresh = shallowRef(true)
  const isOpenRouteInfo = import.meta.env.VITE_OPEN_ROUTE_INFO
  const showTransitionMask = ref(false)

  // 标记是否是首次加载（浏览器刷新）
  const isFirstLoad = ref(true)

  // 检查当前路由是否需要使用无基础布局模式
  const isFullPage = computed(() => route.matched.some((r) => r.meta?.isFullPage))
  const prevIsFullPage = ref(isFullPage.value)

  // 切换动画名称：首次加载、从全屏返回时不使用动画
  const actualTransition = computed(() => {
    if (isFirstLoad.value) return ''
    if (prevIsFullPage.value && !isFullPage.value) return ''
    return pageTransition.value
  })

  /** 遮罩与刷新闩锁的释放定时器：任何异常路径都不得让内容区永久空白 */
  let maskTimer: ReturnType<typeof setTimeout> | null = null
  let maskWatchdog: ReturnType<typeof setTimeout> | null = null
  let refreshWatchdog: ReturnType<typeof setTimeout> | null = null

  const clearTimer = (timer: ReturnType<typeof setTimeout> | null): null => {
    if (timer !== null) clearTimeout(timer)
    return null
  }

  const hideTransitionMask = (): void => {
    maskTimer = clearTimer(maskTimer)
    maskWatchdog = clearTimer(maskWatchdog)
    showTransitionMask.value = false
  }

  // 监听全屏状态变化，显示过渡遮罩
  watch(isFullPage, (val, oldVal) => {
    if (val !== oldVal) {
      showTransitionMask.value = true
      maskTimer = clearTimer(maskTimer)
      maskWatchdog = clearTimer(maskWatchdog)
      // 延迟隐藏遮罩，给足时间让页面完成切换
      maskTimer = setTimeout(() => {
        maskTimer = null
        showTransitionMask.value = false
      }, 50)
      // 看门狗：主定时器被异常打断时兜底释放，避免全屏遮罩永久盖住内容
      maskWatchdog = setTimeout(hideTransitionMask, 1000)
    }

    nextTick(() => {
      prevIsFullPage.value = val
    })
  })

  const containerStyle = computed(
    (): CSSProperties =>
      isFullPage.value
        ? {
            position: 'fixed',
            top: 0,
            left: 0,
            width: '100%',
            height: '100vh',
            zIndex: 2500,
            background: 'var(--default-bg-color)'
          }
        : {
            maxWidth: containerWidth.value
          }
  )

  const contentStyle = computed(
    (): CSSProperties => ({
      minHeight: containerMinHeight.value
    })
  )

  /** 恢复渲染闩锁（幂等） */
  const releaseRefreshLatch = (): void => {
    refreshWatchdog = clearTimer(refreshWatchdog)
    isRefresh.value = true
  }

  const reload = () => {
    try {
      isRefresh.value = false
      // 看门狗：nextTick 回调抛错时兜底恢复，防止内容区永久空白
      refreshWatchdog = clearTimer(refreshWatchdog)
      refreshWatchdog = setTimeout(releaseRefreshLatch, 2000)

      nextTick()
        .then(releaseRefreshLatch)
        .catch((error: unknown) => {
          console.error('[ArtPageContent] 刷新内容区失败:', error)
          releaseRefreshLatch()
        })
    } catch (error) {
      console.error('[ArtPageContent] 刷新内容区异常:', error)
      releaseRefreshLatch()
    }
  }

  watch(refresh, reload, { flush: 'post' })

  // 组件挂载后标记首次加载完成
  onMounted(() => {
    // 延迟一帧，确保首次渲染完成
    nextTick(() => {
      isFirstLoad.value = false
    })
  })

  onUnmounted(() => {
    maskTimer = clearTimer(maskTimer)
    maskWatchdog = clearTimer(maskWatchdog)
    refreshWatchdog = clearTimer(refreshWatchdog)
  })
</script>
