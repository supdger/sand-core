<!-- 首次登录强制改密页：修改成功后清除标志，需用新密码重新登录 -->
<template>
  <div class="flex w-full h-screen">
    <LoginLeftView />

    <div class="relative flex-1">
      <AuthTopBar />

      <div class="auth-right-wrap">
        <div class="form">
          <h3 class="title">{{ $t('changePassword.title') }}</h3>
          <p class="sub-title">{{ $t('changePassword.subTitle') }}</p>
          <ElForm ref="formRef" :model="formData" :rules="rules" @keyup.enter="handleSubmit">
            <ElFormItem prop="oldPassword">
              <ElInput
                class="custom-height"
                :placeholder="$t('changePassword.oldPassword')"
                v-model="formData.oldPassword"
                type="password"
                autocomplete="current-password"
                show-password
              />
            </ElFormItem>
            <ElFormItem prop="newPassword">
              <ElInput
                class="custom-height"
                :placeholder="$t('changePassword.newPassword')"
                v-model="formData.newPassword"
                type="password"
                autocomplete="new-password"
                show-password
              />
            </ElFormItem>
            <ElFormItem prop="confirmPassword">
              <ElInput
                class="custom-height"
                :placeholder="$t('changePassword.confirmPassword')"
                v-model="formData.confirmPassword"
                type="password"
                autocomplete="new-password"
                show-password
              />
            </ElFormItem>
            <ElButton
              class="w-full custom-height"
              type="primary"
              :loading="loading"
              @click="handleSubmit"
              v-ripple
            >
              {{ $t('changePassword.submitBtnText') }}
            </ElButton>
            <ElButton class="w-full custom-height mt-3" plain @click="handleSignOut">
              {{ $t('changePassword.backBtnText') }}
            </ElButton>
          </ElForm>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
  import { useI18n } from 'vue-i18n'
  import { useUserStore } from '@/store/modules/user'
  import { modifyPassword } from '@/api/auth'
  import { type FormInstance, type FormRules } from 'element-plus'

  defineOptions({ name: 'ChangePassword' })

  const MIN_PASSWORD_LENGTH = 12

  const { t } = useI18n()
  const router = useRouter()
  const userStore = useUserStore()
  const formRef = ref<FormInstance>()
  const loading = ref(false)

  const formData = reactive({
    oldPassword: '',
    newPassword: '',
    confirmPassword: ''
  })

  const rules = computed<FormRules>(() => ({
    oldPassword: [{ required: true, message: t('changePassword.oldPassword'), trigger: 'blur' }],
    newPassword: [
      { required: true, message: t('changePassword.newPassword'), trigger: 'blur' },
      { min: MIN_PASSWORD_LENGTH, message: t('changePassword.tooShort'), trigger: 'blur' },
      {
        validator: (_rule, value: string, callback) =>
          callback(
            value === formData.oldPassword ? new Error(t('changePassword.sameAsOld')) : undefined
          ),
        trigger: 'blur'
      }
    ],
    confirmPassword: [
      { required: true, message: t('changePassword.confirmPassword'), trigger: 'blur' },
      {
        validator: (_rule, value: string, callback) =>
          callback(
            value !== formData.newPassword ? new Error(t('changePassword.notMatch')) : undefined
          ),
        trigger: 'blur'
      }
    ]
  }))

  const handleSubmit = async () => {
    if (!formRef.value || loading.value) return
    const valid = await formRef.value.validate().catch(() => false)
    if (!valid) return

    loading.value = true
    try {
      // 后端在改密成功时清除标志；HttpError 由请求层统一提示
      await modifyPassword({
        oldPassword: formData.oldPassword,
        newPassword: formData.newPassword
      })
      ElMessage.success(t('changePassword.success'))
      handleSignOut()
    } catch (error) {
      console.error('[ChangePassword] Failed to change password:', error)
    } finally {
      loading.value = false
    }
  }

  // 退出登录并回到登录页（不携带改密页作为 redirect）
  const handleSignOut = () => {
    userStore.logOut()
    router.replace({ name: 'Login' })
  }
</script>

<style scoped>
  @import '../login/style.css';
</style>
