<!--
  全局静态错误边界

  刻意不使用任何 `Art*` 自动导入组件与 Element Plus：布局组件解析失败、
  组件库初始化失败本身就是白屏的主要来源，兜底 UI 必须是纯 HTML/CSS。

  呈现契约与 utils/sys/error-handle 一致：固定文案「系统出了点问题，请稍后
  再试。」，仅〔重新加载〕〔复制问题信息〕两个操作；复制内容只含错误码、
  时间、版本、request id，绝不展示或复制绝对路径、堆栈、密钥；重载有界。
-->
<template>
  <slot v-if="!capturedError" />
  <div v-else class="static-error-boundary">
    <div class="static-error-boundary__panel">
      <h1 class="static-error-boundary__title">系统出了点问题，请稍后再试。</h1>
      <div class="static-error-boundary__actions">
        <button type="button" class="static-error-boundary__btn is-primary" @click="retry">
          重试
        </button>
        <button
          type="button"
          class="static-error-boundary__btn"
          :disabled="reloadLimited"
          @click="reload"
        >
          {{ reloadLimited ? '请稍等片刻后再试' : '重新加载' }}
        </button>
        <button type="button" class="static-error-boundary__btn" @click="copy">
          {{ copied ? '已复制' : '复制问题信息' }}
        </button>
      </div>
      <p class="static-error-boundary__code">错误码：{{ errorCode }}</p>
    </div>
  </div>
</template>

<script setup lang="ts">
  import { computed, onErrorCaptured, ref, shallowRef } from 'vue'
  import {
    copyProblemReport,
    errorCodeOf,
    guardedReload
  } from '@/utils/sys/error-handle'

  defineOptions({ name: 'StaticErrorBoundary' })

  const capturedError = shallowRef<unknown>(null)
  const reloadLimited = ref(false)
  const copied = ref(false)

  const errorCode = computed(() => errorCodeOf(capturedError.value))

  onErrorCaptured((error: unknown, _instance, info: string) => {
    capturedError.value = error ?? new Error('未知渲染错误')
    console.error('[StaticErrorBoundary]', info, error)
    // 返回 false 阻止继续向上冒泡，由本边界负责呈现
    return false
  })

  const retry = (): void => {
    capturedError.value = null
  }

  const reload = (): void => {
    guardedReload(() => {
      reloadLimited.value = true
    })
  }

  const copy = (): void => {
    void copyProblemReport(errorCode.value).then((ok) => {
      copied.value = ok
      if (ok) setTimeout(() => (copied.value = false), 2000)
    })
  }
</script>

<style scoped>
  .static-error-boundary {
    box-sizing: border-box;
    display: flex;
    align-items: center;
    justify-content: center;
    min-height: 100vh;
    padding: 48px 24px;
    font-family: system-ui, -apple-system, 'Segoe UI', 'Microsoft YaHei', sans-serif;
    font-size: 14px;
    line-height: 1.7;
    color: #303133;
    background: #fafbfc;
  }

  .static-error-boundary__panel {
    width: 100%;
    max-width: 960px;
    text-align: center;
  }

  .static-error-boundary__title {
    margin: 0 0 20px;
    font-size: 20px;
    font-weight: 600;
  }

  .static-error-boundary__actions {
    display: flex;
    flex-wrap: wrap;
    gap: 12px;
    justify-content: center;
    margin-bottom: 12px;
  }

  .static-error-boundary__btn {
    padding: 8px 20px;
    font-size: 14px;
    color: #606266;
    cursor: pointer;
    background: #fff;
    border: 1px solid #dcdfe6;
    border-radius: 6px;
  }

  .static-error-boundary__btn.is-primary {
    color: #fff;
    background: #409eff;
    border-color: #409eff;
  }

  .static-error-boundary__code {
    margin: 0;
    font-size: 12px;
    color: #909399;
  }
</style>
