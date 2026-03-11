<template>
  <button
    :type="type"
    :disabled="disabled || loading"
    :class="[
      'btn',
      variant === 'primary' ? 'btn-primary' : '',
      variant === 'secondary' ? 'btn-secondary' : '',
      variant === 'danger' ? 'btn-danger' : '',
      variant === 'success' ? 'btn-success' : '',
      disabled || loading ? 'opacity-60 cursor-not-allowed' : '',
      size === 'sm' ? 'px-4 py-2 text-sm' : '',
      size === 'lg' ? 'px-8 py-4 text-lg' : '',
      'group relative overflow-hidden',
    ]"
    @click="handleClick"
  >
    <!-- Ripple Effect -->
    <span
      v-if="!disabled && !loading"
      class="absolute inset-0 rounded-xl opacity-0 group-active:opacity-100 group-active:animate-ping"
      :class="rippleClass"
    ></span>

    <!-- Loading Spinner -->
    <span v-if="loading" class="inline-flex items-center mr-2">
      <svg
        class="animate-spin h-5 w-5"
        xmlns="http://www.w3.org/2000/svg"
        fill="none"
        viewBox="0 0 24 24"
      >
        <circle
          class="opacity-25"
          cx="12"
          cy="12"
          r="10"
          stroke="currentColor"
          stroke-width="4"
        ></circle>
        <path
          class="opacity-75"
          fill="currentColor"
          d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"
        ></path>
      </svg>
    </span>

    <!-- Button Content -->
    <span class="relative z-10 flex items-center justify-center">
      <slot />
    </span>

    <!-- Shine Effect on Hover -->
    <span
      v-if="!disabled && !loading"
      class="absolute inset-0 -translate-x-full group-hover:translate-x-full transition-transform duration-700 ease-in-out"
      :class="shineClass"
    ></span>
  </button>
</template>

<script setup>
import { computed } from 'vue'

const props = defineProps({
  variant: {
    type: String,
    default: 'primary',
    validator: (value) => ['primary', 'secondary', 'danger', 'success'].includes(value)
  },
  size: {
    type: String,
    default: 'md',
    validator: (value) => ['sm', 'md', 'lg'].includes(value)
  },
  type: {
    type: String,
    default: 'button',
    validator: (value) => ['button', 'submit', 'reset'].includes(value)
  },
  disabled: {
    type: Boolean,
    default: false
  },
  loading: {
    type: Boolean,
    default: false
  }
})

const emit = defineEmits(['click'])

const rippleClass = computed(() => {
  switch (props.variant) {
    case 'primary':
      return 'bg-white/30'
    case 'danger':
      return 'bg-white/30'
    case 'success':
      return 'bg-white/30'
    default:
      return 'bg-gray-200/50'
  }
})

const shineClass = computed(() => {
  switch (props.variant) {
    case 'primary':
      return 'bg-gradient-to-r from-transparent via-white/20 to-transparent'
    case 'danger':
      return 'bg-gradient-to-r from-transparent via-white/20 to-transparent'
    case 'success':
      return 'bg-gradient-to-r from-transparent via-white/20 to-transparent'
    default:
      return 'bg-gradient-to-r from-transparent via-gray-200/30 to-transparent'
  }
})

function handleClick(event) {
  if (!props.disabled && !props.loading) {
    emit('click', event)
  }
}
</script>

<style scoped>
.btn {
  transform: translateZ(0);
  backface-visibility: hidden;
  perspective: 1000px;
}
</style>
