<template>
  <aside class="login-left-view" :class="{ 'is-content-hidden': hideContent }" aria-hidden="true">
    <div class="login-atmosphere" />
    <div class="login-grid" />

    <svg class="login-orbits" viewBox="0 0 1600 1000" preserveAspectRatio="none" focusable="false">
      <g fill="none" stroke="currentColor">
        <circle cx="394" cy="484" r="218" />
        <circle cx="394" cy="484" r="154" />
        <path d="M 80 144 H 720 M 80 760 H 720" />
        <path d="M 80 144 L 710 760 M 710 144 L 80 760" />
        <path d="M 394 250 V 792 M 122 484 H 666" />
      </g>
    </svg>

    <section class="brand-stage">
      <div class="brand-lockup">
        <img class="sand-mark" src="@/assets/images/common/sandadmin-login-mark-v2.png" alt="" />

        <h1 class="brand-name"><span>Sand</span>Admin</h1>
        <p class="brand-slogan">稳定、清晰、可控</p>
      </div>

      <ul class="brand-capabilities">
        <li>
          <span class="capability-icon">
            <svg viewBox="0 0 48 48" focusable="false">
              <path d="M24 5 39 11v11c0 10-6 17-15 21C15 39 9 32 9 22V11l15-6Z" />
              <circle cx="24" cy="20" r="4" />
              <path d="M17 33c1-5 13-5 14 0" />
            </svg>
          </span>
          <span><strong>身份与访问</strong><small>统一身份&ensp;安全可控</small></span>
        </li>
        <li>
          <span class="capability-icon">
            <svg viewBox="0 0 48 48" focusable="false">
              <path
                d="M24 8c-4-4-10-3-12 1-5-1-9 4-7 9-5 3-4 10 1 12-1 5 4 9 9 7 3 5 10 5 13 0 5 2 10-2 9-7 5-2 6-9 1-12 2-5-2-10-7-9-2-4-8-5-12-1Z"
              />
              <path d="M24 13v22M16 19l8 5 8-5M16 30l8-5 8 5" />
            </svg>
          </span>
          <span><strong>智能能力</strong><small>数据驱动&ensp;智能洞察</small></span>
        </li>
        <li>
          <span class="capability-icon">
            <svg viewBox="0 0 48 48" focusable="false">
              <circle cx="24" cy="10" r="5" />
              <circle cx="10" cy="34" r="5" />
              <circle cx="38" cy="34" r="5" />
              <path d="M21 14 13 29M27 14l8 15M15 34h18" />
            </svg>
          </span>
          <span><strong>流程协作</strong><small>高效协同&ensp;流程闭环</small></span>
        </li>
      </ul>
    </section>

    <div class="sand-dunes-frame">
      <img class="sand-dunes" src="@/assets/images/common/sandadmin-login-dunes-v2.png" alt="" />
    </div>
  </aside>
</template>

<script setup lang="ts">
  defineProps<{
    hideContent?: boolean
  }>()
</script>

<style lang="scss" scoped>
  .login-left-view {
    position: absolute;
    inset: 0;
    z-index: 0;
    display: block;
    min-height: 100svh;
    overflow: hidden;
    pointer-events: none;
    background: #07131f;
  }

  .login-atmosphere,
  .login-grid,
  .login-orbits {
    position: absolute;
    inset: 0;
    width: 100%;
    height: 100%;
  }

  .login-atmosphere {
    background:
      radial-gradient(circle at 13% 28%, rgb(48 129 138 / 34%), transparent 26%),
      radial-gradient(circle at 38% 86%, rgb(12 47 67 / 38%), transparent 34%),
      radial-gradient(circle at 80% 26%, rgb(20 49 66 / 20%), transparent 31%),
      linear-gradient(122deg, #06111b 0%, #071824 46%, #06111c 100%);
  }

  .login-atmosphere::after {
    position: absolute;
    inset: 0;
    content: '';
    opacity: 0.22;
    background-image:
      radial-gradient(rgb(255 255 255 / 42%) 0.55px, transparent 0.75px),
      radial-gradient(rgb(100 202 211 / 24%) 0.45px, transparent 0.7px);
    background-position:
      0 0,
      7px 13px;
    background-size:
      8px 8px,
      13px 13px;
    mix-blend-mode: screen;
  }

  .login-grid {
    opacity: 0.35;
    background-image:
      linear-gradient(90deg, rgb(134 173 185 / 19%) 1px, transparent 1px),
      linear-gradient(rgb(134 173 185 / 15%) 1px, transparent 1px);
    background-size: clamp(104px, 10vw, 160px) clamp(104px, 10vw, 160px);
    mask-image: linear-gradient(90deg, #000 0 52%, transparent 78%);
  }

  .login-orbits {
    color: rgb(123 180 191 / 16%);
    stroke-width: 1;
    opacity: 0.72;
  }

  .brand-stage {
    position: absolute;
    z-index: 1;
    top: 0;
    left: 0;
    display: flex;
    align-items: center;
    flex-direction: column;
    width: 50%;
    height: 100%;
    color: #f6f1e5;
    transition: opacity 180ms ease;
  }

  .brand-lockup {
    position: absolute;
    top: 25.6%;
    left: 50%;
    width: min(420px, 31vw);
    transform: translateX(-50%);
    text-align: center;
  }

  .sand-mark {
    display: block;
    width: clamp(126px, 10.5vw, 168px);
    height: auto;
    margin: 0 auto 12px;
    object-fit: contain;
  }

  .brand-name {
    margin: 0;
    font-family: Inter, 'PingFang SC', 'Microsoft YaHei', sans-serif;
    font-size: clamp(44px, 4.15vw, 66px);
    font-weight: 520;
    letter-spacing: -0.045em;
    line-height: 1.08;
    text-shadow: 0 4px 18px rgb(0 0 0 / 26%);
  }

  .brand-name span {
    color: #e6c17d;
  }

  .brand-slogan {
    margin: 17px 0 0;
    color: rgb(238 241 237 / 82%);
    font-size: clamp(20px, 1.7vw, 27px);
    font-weight: 360;
    letter-spacing: 0.18em;
  }

  .brand-capabilities {
    position: absolute;
    // Keep the capability row below the full brand lockup at every desktop height.
    // A percentage-only offset overlapped the logo slogan on 16:9 wide screens.
    top: calc(25.6% + clamp(19rem, 21vw, 23rem));
    left: 50%;
    display: grid;
    grid-template-columns: repeat(3, auto);
    gap: clamp(24px, 2.5vw, 43px);
    width: max-content;
    margin: 0;
    padding: 0;
    transform: translateX(-50%);
    list-style: none;
  }

  .brand-capabilities li {
    display: flex;
    align-items: center;
    gap: 12px;
    min-width: 152px;
  }

  .capability-icon {
    display: grid;
    flex: 0 0 auto;
    place-items: center;
    width: clamp(48px, 4.25vw, 68px);
    aspect-ratio: 1;
    border: 1px solid rgb(159 186 194 / 18%);
    border-radius: 50%;
    background: radial-gradient(circle at 35% 27%, rgb(41 70 85 / 72%), rgb(8 23 35 / 82%));
    box-shadow:
      inset 0 0 21px rgb(98 151 162 / 10%),
      0 9px 18px rgb(0 0 0 / 24%);
  }

  .capability-icon svg {
    width: 58%;
    height: 58%;
    fill: none;
    stroke: #56d0cc;
    stroke-linecap: round;
    stroke-linejoin: round;
    stroke-width: 1.75;
  }

  .brand-capabilities strong,
  .brand-capabilities small {
    display: block;
    white-space: nowrap;
  }

  .brand-capabilities strong {
    color: #f2f0e8;
    font-size: clamp(14px, 1.14vw, 18px);
    font-weight: 580;
    letter-spacing: 0.035em;
  }

  .brand-capabilities small {
    margin-top: 5px;
    color: rgb(197 208 208 / 62%);
    font-size: clamp(9px, 0.74vw, 12px);
    letter-spacing: 0.02em;
  }

  .sand-dunes-frame {
    position: absolute;
    inset: 0;
    width: 100%;
    overflow: hidden;
  }

  .sand-dunes {
    position: absolute;
    // The source keeps a transparent grain falloff above the dune crest.
    // Keep that complete falloff in view instead of cropping through it.
    bottom: -8svh;
    left: 50%;
    display: block;
    width: 100%;
    max-width: none;
    height: auto;
    transform: translateX(-50%);
    object-fit: contain;
    object-position: center bottom;
    mix-blend-mode: screen;
    -webkit-mask-image: linear-gradient(to bottom, transparent 0%, rgb(0 0 0 / 16%) 17%, #000 37%);
    mask-image: linear-gradient(to bottom, transparent 0%, rgb(0 0 0 / 16%) 17%, #000 37%);
  }

  .is-content-hidden .brand-stage {
    opacity: 0;
  }

  :global(html[data-login-appearance='light']) .login-left-view {
    background: #edf5ff;
  }

  :global(html[data-login-appearance='light']) .login-atmosphere {
    background:
      radial-gradient(circle at 16% 24%, rgb(191 219 254 / 75%), transparent 29%),
      radial-gradient(circle at 78% 31%, rgb(204 251 241 / 60%), transparent 36%),
      linear-gradient(135deg, #f8fbff 0%, #eaf3ff 52%, #f3fbfb 100%);
  }

  :global(html[data-login-appearance='light']) .login-atmosphere::after {
    opacity: 0.42;
    background-image: radial-gradient(rgb(37 99 235 / 24%) 0.55px, transparent 0.75px);
    mix-blend-mode: multiply;
  }

  :global(html[data-login-appearance='light']) .login-grid {
    opacity: 0.52;
    background-image:
      linear-gradient(90deg, rgb(96 165 250 / 18%) 1px, transparent 1px),
      linear-gradient(rgb(96 165 250 / 15%) 1px, transparent 1px);
  }

  :global(html[data-login-appearance='light']) .login-orbits {
    color: rgb(37 99 235 / 18%);
  }

  :global(html[data-login-appearance='light']) .sand-mark {
    width: clamp(86px, 7vw, 112px);
    margin-bottom: 20px;
    content: url('/sandadmin-mark.png');
    filter: drop-shadow(0 12px 18px rgb(37 99 235 / 18%));
  }

  :global(html[data-login-appearance='light']) .brand-stage,
  :global(html[data-login-appearance='light']) .brand-name,
  :global(html[data-login-appearance='light']) .brand-capabilities strong {
    color: #1e293b;
  }

  :global(html[data-login-appearance='light']) .brand-name {
    text-shadow: none;
  }

  :global(html[data-login-appearance='light']) .brand-name span {
    color: #2563eb;
  }

  :global(html[data-login-appearance='light']) .brand-slogan,
  :global(html[data-login-appearance='light']) .brand-capabilities small {
    color: #64748b;
  }

  :global(html[data-login-appearance='light']) .capability-icon {
    border-color: rgb(37 99 235 / 16%);
    background: rgb(255 255 255 / 76%);
    box-shadow: 0 10px 24px rgb(37 99 235 / 10%);
  }

  :global(html[data-login-appearance='light']) .capability-icon svg {
    stroke: #2563eb;
  }

  :global(html[data-login-appearance='light']) .sand-dunes {
    opacity: 0.58;
    mix-blend-mode: multiply;
  }

  @media only screen and (width <= 1180px) {
    .login-left-view {
      display: none;
    }
  }
</style>
