<!-- 登录页面 -->
<template>
  <div class="login-page">
    <LoginLeftView />

    <main class="login-shell">
      <div class="auth-right-wrap">
        <section class="login-card" aria-labelledby="login-page-title">
          <div class="form">
            <h3 id="login-page-title" class="title">{{ $t('login.title') }}</h3>
            <p class="sub-title">{{ $t('login.subTitle') }}</p>
            <ElForm
              ref="formRef"
              :model="formData"
              :rules="rules"
              :key="formKey"
              @keyup.enter="handleSubmit"
              class="login-form"
            >
              <div class="form-field">
                <label class="field-label" for="login-username">账号</label>
                <ElFormItem class="login-form-item" prop="username">
                  <ElInput
                    id="login-username"
                    class="custom-height"
                    :placeholder="$t('login.placeholder.username')"
                    v-model.trim="formData.username"
                  >
                    <template #prefix><ArtSvgIcon icon="ri:user-3-line" /></template>
                  </ElInput>
                </ElFormItem>
              </div>

              <div class="form-field">
                <label class="field-label" for="login-password">密码</label>
                <ElFormItem class="login-form-item" prop="password">
                  <ElInput
                    id="login-password"
                    class="custom-height"
                    :placeholder="$t('login.placeholder.password')"
                    v-model.trim="formData.password"
                    type="password"
                    autocomplete="off"
                    show-password
                  >
                    <template #prefix><ArtSvgIcon icon="ri:lock-2-line" /></template>
                  </ElInput>
                </ElFormItem>
              </div>

              <div class="form-field">
                <label class="field-label" for="login-code">验证码</label>
                <ElFormItem class="login-form-item" prop="code">
                  <div class="captcha-row">
                    <ElInput
                      id="login-code"
                      class="custom-height"
                      :placeholder="$t('login.placeholder.code')"
                      v-model.trim="formData.code"
                      type="text"
                      autocomplete="off"
                    >
                      <template #prefix><ArtSvgIcon icon="ri:shield-check-line" /></template>
                    </ElInput>
                    <button
                      class="captcha-button"
                      type="button"
                      aria-label="刷新验证码"
                      @click="refreshCaptcha"
                    >
                      <img :src="captcha" class="captcha-image" alt="验证码" />
                    </button>
                  </div>
                </ElFormItem>
              </div>

              <div class="login-options">
                <ElCheckbox v-model="formData.rememberPassword">{{
                  $t('login.rememberPwd')
                }}</ElCheckbox>
                <span class="forgot-password">{{ $t('login.forgetPwd') }}</span>
              </div>

              <div class="login-submit-wrap">
                <ElButton
                  class="w-full custom-height"
                  type="primary"
                  @click="handleSubmit"
                  :loading="loading"
                  v-ripple
                >
                  {{ $t('login.btnText') }} SandAdmin
                </ElButton>
              </div>

              <p class="login-security-note">
                <ArtSvgIcon icon="ri:shield-check-line" />
                安全连接已启用，数据传输全程加密
              </p>

              <!-- <div class="mt-5 text-sm text-gray-600">
                <span>{{ $t('login.noAccount') }}</span>
                <RouterLink class="text-theme" :to="{ name: 'Register' }">{{
                  $t('login.register')
                }}</RouterLink>
              </div> -->
            </ElForm>
          </div>
        </section>
      </div>
    </main>
  </div>
</template>

<script setup lang="ts">
  import AppConfig from '@/config'
  import { useUserStore } from '@/store/modules/user'
  import { useI18n } from 'vue-i18n'
  import { HttpError } from '@/utils/http/error'
  import { fetchCaptcha, fetchLogin, fetchGetUserInfo } from '@/api/auth'
  import { ElNotification, type FormInstance, type FormRules } from 'element-plus'

  defineOptions({ name: 'Login' })

  const { t, locale } = useI18n()
  const formKey = ref(0)

  // 监听语言切换，重置表单
  watch(locale, () => {
    formKey.value++
  })

  const userStore = useUserStore()
  const router = useRouter()

  const captcha = ref(
    'data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7'
  )

  const systemName = AppConfig.systemInfo.name
  const formRef = ref<FormInstance>()

  const formData = reactive({
    username: '',
    password: '',
    code: '',
    uuid: '',
    rememberPassword: true
  })

  const rules = computed<FormRules>(() => ({
    username: [{ required: true, message: t('login.placeholder.username'), trigger: 'blur' }],
    password: [{ required: true, message: t('login.placeholder.password'), trigger: 'blur' }],
    code: [{ required: true, message: t('login.placeholder.code'), trigger: 'blur' }]
  }))

  const loading = ref(false)

  onMounted(() => {
    refreshCaptcha()
  })

  // 登录
  const handleSubmit = async () => {
    if (!formRef.value) return

    try {
      // 表单验证
      const valid = await formRef.value.validate()
      if (!valid) return

      loading.value = true

      // 登录请求
      const { access_token, refresh_token } = await fetchLogin({
        username: formData.username,
        password: formData.password,
        code: formData.code,
        uuid: formData.uuid
      })

      // 验证token
      if (!access_token) {
        throw new Error('Login failed - no token received')
      }

      // 存储token和用户信息
      userStore.setToken(access_token, refresh_token)
      const userInfo = await fetchGetUserInfo()
      userStore.setUserInfo(userInfo)
      userStore.setLoginStatus(true)

      // 登录成功处理
      showLoginSuccessNotice()
      router.push('/')
    } catch (error) {
      // 处理 HttpError
      if (error instanceof HttpError) {
        // console.log(error.code)
      } else {
        // 处理非 HttpError
        // ElMessage.error('登录失败，请稍后重试')
        console.error('[Login] Unexpected error:', error)
      }
    } finally {
      refreshCaptcha()
      loading.value = false
    }
  }

  // 获取验证码
  const refreshCaptcha = async () => {
    fetchCaptcha().then((res) => {
      formData.uuid = res.uuid
      captcha.value = res.image
    })
  }

  // 登录成功提示
  const showLoginSuccessNotice = () => {
    setTimeout(() => {
      ElNotification({
        title: t('login.success.title'),
        type: 'success',
        duration: 2500,
        zIndex: 10000,
        message: `${t('login.success.message')}, ${systemName}!`
      })
    }, 150)
  }
</script>

<style scoped>
  @import './style.css';
</style>

<style lang="scss" scoped>
  :deep(.el-select__wrapper) {
    height: 40px !important;
  }
</style>
