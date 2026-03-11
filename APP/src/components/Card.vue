<template>
  <div
    :class="[
      variant === 'glass' ? 'card-glass' : variant === 'gradient' ? 'card-gradient' : 'card',
      padding === 'none' ? 'p-0' : '',
      padding === 'sm' ? 'p-4' : '',
      'group',
      animate ? 'animate-slide-up' : '',
    ]"
    :style="animate ? { animationDelay: `${delay}ms` } : {}"
  >
    <div
      v-if="title || $slots.header"
      class="mb-6 pb-4 border-b border-gray-200/50"
    >
      <slot name="header">
        <h3
          v-if="title"
          class="text-xl font-bold text-gray-900 flex items-center gap-2"
        >
          <span v-if="icon" class="text-2xl">{{ icon }}</span>
          {{ title }}
        </h3>
      </slot>
    </div>
    <div class="relative z-10">
      <slot />
    </div>

    <!-- Decorative gradient overlay on hover -->
    <div
      v-if="variant !== 'glass'"
      class="absolute inset-0 opacity-0 group-hover:opacity-100 transition-opacity duration-300 rounded-2xl pointer-events-none"
      :class="overlayClass"
    ></div>
  </div>
</template>

<script setup>
import { computed } from 'vue'

const props = defineProps({
  title: String,
  padding: {
    type: String,
    default: 'md',
    validator: (value) => ['none', 'sm', 'md'].includes(value)
  },
  variant: {
    type: String,
    default: 'default',
    validator: (value) => ['default', 'glass', 'gradient'].includes(value)
  },
  icon: String,
  animate: Boolean,
  delay: {
    type: Number,
    default: 0
  }
})

const overlayClass = computed(() => {
  if (props.variant === 'gradient') {
    return 'bg-gradient-to-br from-primary-50/50 to-purple-50/50'
  }
  return 'bg-gradient-to-br from-white/50 to-gray-50/50'
})
</script>

<style scoped>
.card,
.card-glass,
.card-gradient {
  position: relative;
  transform: translateZ(0);
  backface-visibility: hidden;
}
</style>
