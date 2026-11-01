<!--
  全局静态错误边界

  刻意不使用任何 `Art*` 自动导入组件与 Element Plus：布局组件解析失败、
  组件库初始化失败本身就是白屏的主要来源，兜底 UI 必须是纯 HTML/CSS。
-->
<template>
  <slot v-if="!capturedError" />
  <div v-else class="static-error-boundary">
    <div class="static-error-boundary__panel">
      <h1 class="static-error-boundary__title">页面渲染失败</h1>
      <p class="static-error-boundary__tip">
        当前页面在渲染过程中发生错误，已停止渲染以避免白屏。可先尝试重试；若反复失败，请重置本地状态后重新登录，并把下方错误信息反馈给管理员。
      </p>
      <div class="static-error-boundary__actions">
        <button type="button" class="static-error-boundary__btn is-primary" @click="retry">
          重试
        </button>
        <button type="button" class="static-error-boundary__btn" @click="reload">重新加载</button>
        <button type="button" class="static-error-boundary__btn" @click="resetState">
          重置本地状态
        </button>
      </div>
      <details class="static-error-boundary__details">
        <summary>错误详情</summary>
        <pre>{{ errorDetail }}</pre>
      </details>
    </div>
  </div>
</template>

<script setup lang="ts">
  import { computed, onErrorCaptured, ref, shallowRef } from 'vue'
  import { describeError, resetLocalState } from '@/utils/sys/error-handle'

  defineOptions({ name: 'StaticErrorBoundary' })

  const capturedError = shallowRef<unknown>(null)
  const capturedInfo = ref('')

  const errorDetail = computed(() => {
    const summary = describeError(capturedError.value)
    return capturedInfo.value ? `${capturedInfo.value}\n\n${summary}` : summary
  })

  onErrorCaptured((error: unknown, _instance, info: string) => {
    capturedError.value = error ?? new Error('未知渲染错误')
    capturedInfo.value = info
    console.error('[StaticErrorBoundary]', info, error)
    // 返回 false 阻止继续向上冒泡，由本边界负责呈现
    return false
  })

  const retry = (): void => {
    capturedError.value = null
    capturedInfo.value = ''
  }

  const reload = (): void => {
    window.location.reload()
  }

  const resetState = (): void => {
    resetLocalState()
    window.location.reload()
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
    margin: 0 0 12px;
    font-size: 20px;
    font-weight: 600;
  }

  .static-error-boundary__tip {
    margin: 0 auto 20px;
    max-width: 720px;
    color: #606266;
  }

  .static-error-boundary__actions {
    display: flex;
    flex-wrap: wrap;
    gap: 12px;
    justify-content: center;
    margin-bottom: 20px;
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

  .static-error-boundary__details {
    text-align: left;
  }

  .static-error-boundary__details summary {
    color: #909399;
    cursor: pointer;
  }

  .static-error-boundary__details pre {
    max-height: 40vh;
    margin: 12px 0 0;
    padding: 12px;
    overflow: auto;
    color: #f56c6c;
    word-break: break-all;
    white-space: pre-wrap;
    background: #f5f7fa;
    border: 1px solid #e4e7ed;
    border-radius: 6px;
  }
</style>
