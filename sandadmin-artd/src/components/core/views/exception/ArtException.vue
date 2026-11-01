<template>
  <div class="page-content !border-0 !bg-transparent min-h-screen flex-cc">
    <div class="flex-cc max-md:!block max-md:text-center">
      <ThemeSvg :src="data.imgUrl" size="100%" class="!w-100" />
      <div class="ml-15 w-75 max-md:mx-auto max-md:mt-10 max-md:w-full max-md:text-center">
        <p class="text-xl leading-7 text-g-600 max-md:text-lg">{{ data.desc }}</p>
        <ElButton type="primary" size="large" @click="backHome" v-ripple class="mt-5">{{
          data.btnText
        }}</ElButton>
        <ElButton
          v-if="routeInitRetryable"
          type="warning"
          size="large"
          @click="retryInit"
          v-ripple
          class="mt-5"
        >
          <template #icon>
            <ArtSvgIcon icon="ri:refresh-line" />
          </template>
          重试
        </ElButton>
        <ElButton type="danger" size="large" @click="backLogin" v-ripple class="mt-5">
          <template #icon>
            <ArtSvgIcon icon="ri:login-box-line" />
          </template>
          重新登录
        </ElButton>
        <div class="mt-4">
          <ElButton link type="info" size="small" @click="resetLocal">重置本地状态</ElButton>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
  import { ElMessageBox } from 'element-plus'
  import { useCommon } from '@/hooks/core/useCommon'
  import { getRouteInitFailed, resetRouteInitState } from '@/router/guards/beforeEach'
  import { resetLocalState } from '@/utils/sys/error-handle'

  const router = useRouter()

  interface ExceptionData {
    /** 标题 */
    title: string
    /** 描述 */
    desc: string
    /** 按钮文本 */
    btnText: string
    /** 图片地址 */
    imgUrl: string
  }

  withDefaults(
    defineProps<{
      data: ExceptionData
    }>(),
    {}
  )

  const { homePath } = useCommon()

  /** 本页是否由路由初始化失败引发：是则提供显式重试出口 */
  const routeInitRetryable = ref(false)

  onMounted(() => {
    routeInitRetryable.value = getRouteInitFailed()
  })

  const backHome = () => {
    // 先清除粘性的初始化失败状态，否则守卫会把首页再次打回 /500 形成死循环
    resetRouteInitState()
    routeInitRetryable.value = false
    router.replace(homePath.value || '/')
  }

  const retryInit = () => {
    resetRouteInitState()
    routeInitRetryable.value = false
    // 整页重载是最彻底的重试：bootstrap、store、路由注册全部重新来过
    window.location.reload()
  }

  const backLogin = () => {
    resetRouteInitState()
    routeInitRetryable.value = false
    router.replace('/auth/login')
  }

  const resetLocal = async () => {
    try {
      await ElMessageBox.confirm(
        '将清空本地缓存的登录态、标签页与个性化设置，并重新加载页面。确定继续？',
        '重置本地状态',
        { type: 'warning', confirmButtonText: '确定重置', cancelButtonText: '取消' }
      )
    } catch {
      return
    }
    resetLocalState()
    window.location.reload()
  }
</script>
