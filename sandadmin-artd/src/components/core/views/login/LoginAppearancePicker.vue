<template>
  <div v-if="isLoginRoute" class="login-appearance-picker" aria-label="登录页外观">
    <span class="login-appearance-picker__label">登录外观</span>
    <div class="login-appearance-picker__options" role="group" aria-label="选择登录页外观">
      <button
        class="login-appearance-picker__option login-appearance-picker__option--dark"
        type="button"
        :aria-pressed="appearance === 'dark'"
        @click="setAppearance('dark')"
      >
        深色
      </button>
      <button
        class="login-appearance-picker__option login-appearance-picker__option--light"
        type="button"
        :aria-pressed="appearance === 'light'"
        @click="setAppearance('light')"
      >
        晨光蓝
      </button>
    </div>
  </div>
</template>

<script setup lang="ts">
  import { computed, onMounted, ref } from 'vue'
  import { useRoute } from 'vue-router'

  type LoginAppearance = 'dark' | 'light'

  const storageKey = 'sandadmin-login-appearance'
  const route = useRoute()
  const appearance = ref<LoginAppearance>('dark')
  const isLoginRoute = computed(() => route.name === 'Login')

  function isLoginAppearance(value: string | null): value is LoginAppearance {
    return value === 'dark' || value === 'light'
  }

  function applyAppearance(value: LoginAppearance) {
    document.documentElement.dataset.loginAppearance = value
  }

  function setAppearance(value: LoginAppearance) {
    appearance.value = value
    applyAppearance(value)

    try {
      window.localStorage.setItem(storageKey, value)
    } catch {
      // Storage can be unavailable in private or restricted browser contexts.
    }
  }

  onMounted(() => {
    try {
      const savedAppearance = window.localStorage.getItem(storageKey)
      if (isLoginAppearance(savedAppearance)) {
        appearance.value = savedAppearance
      }
    } catch {
      // Keep the existing dark default when storage is unavailable.
    }

    applyAppearance(appearance.value)
  })
</script>

<style>
  .login-appearance-picker {
    position: fixed;
    z-index: 10;
    top: 26px;
    right: 30px;
    display: inline-flex;
    align-items: center;
    gap: 10px;
    padding: 7px 8px 7px 13px;
    border: 1px solid rgb(148 163 184 / 22%);
    border-radius: 999px;
    background: rgb(255 255 255 / 7%);
    color: rgb(226 232 240 / 80%);
    backdrop-filter: blur(14px);
  }

  .login-appearance-picker__label {
    font-size: 12px;
    letter-spacing: 0.04em;
  }

  .login-appearance-picker__options {
    display: inline-flex;
    padding: 2px;
    border-radius: 999px;
    background: rgb(2 6 23 / 32%);
  }

  .login-appearance-picker__option {
    min-height: 28px;
    padding: 0 11px;
    border: 0;
    border-radius: 999px;
    background: transparent;
    color: inherit;
    cursor: pointer;
    font: inherit;
    font-size: 12px;
    transition:
      background-color 160ms ease,
      color 160ms ease,
      box-shadow 160ms ease;
  }

  .login-appearance-picker__option[aria-pressed='true'] {
    background: rgb(255 255 255 / 92%);
    color: #0f172a;
    box-shadow: 0 1px 4px rgb(15 23 42 / 18%);
  }

  .login-appearance-picker__option:focus-visible {
    outline: 2px solid #2563eb;
    outline-offset: 2px;
  }

  html[data-login-appearance='light'] .login-appearance-picker {
    border-color: rgb(148 163 184 / 24%);
    background: rgb(255 255 255 / 78%);
    color: #475569;
    box-shadow: 0 8px 28px rgb(71 85 105 / 10%);
  }

  html[data-login-appearance='light'] .login-appearance-picker__options {
    background: #eaf2ff;
  }

  html[data-login-appearance='light'] .login-appearance-picker__option[aria-pressed='true'] {
    background: #2563eb;
    color: #fff;
    box-shadow: 0 2px 8px rgb(37 99 235 / 25%);
  }

  html[data-login-appearance='light'] .login-page {
    background: #f7faff;
  }

  html[data-login-appearance='light'] .login-page .login-shell {
    background:
      radial-gradient(circle at 76% 14%, rgb(204 251 241 / 74%), transparent 27%),
      radial-gradient(circle at 14% 88%, rgb(219 234 254 / 72%), transparent 31%), #f7faff;
  }

  html[data-login-appearance='light'] .login-page .login-card {
    border: 1px solid rgb(226 232 240 / 90%);
    background: rgb(255 255 255 / 94%);
    box-shadow:
      0 22px 65px rgb(71 85 105 / 13%),
      0 2px 8px rgb(71 85 105 / 6%);
  }

  html[data-login-appearance='light'] .login-page .title,
  html[data-login-appearance='light'] .login-page .field-label {
    color: #1e293b;
  }

  html[data-login-appearance='light'] .login-page .sub-title,
  html[data-login-appearance='light'] .login-page .login-options,
  html[data-login-appearance='light'] .login-page .login-security-note {
    color: #64748b;
  }

  html[data-login-appearance='light'] .login-page .login-form-item .el-input__wrapper {
    background: #fff;
    box-shadow: 0 0 0 1px #d8e1ee inset;
  }

  html[data-login-appearance='light'] .login-page .login-form-item .el-input__wrapper.is-focus,
  html[data-login-appearance='light'] .login-page .login-form-item .el-input__wrapper:hover {
    box-shadow: 0 0 0 1px #2563eb inset;
  }

  html[data-login-appearance='light'] .login-page .login-submit-wrap .el-button {
    background: #2563eb;
    box-shadow: 0 10px 22px rgb(37 99 235 / 23%);
  }

  @media only screen and (width <= 640px) {
    .login-appearance-picker {
      top: 14px;
      right: 14px;
      gap: 6px;
      padding-left: 9px;
    }

    .login-appearance-picker__label {
      display: none;
    }
  }
</style>
