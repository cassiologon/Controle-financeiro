<template>
  <span
    :class="[
      'inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-sm font-medium',
      type === 'income' ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800',
    ]"
    :style="{ backgroundColor: color ? `${color}20` : undefined, color: color || undefined }"
  >
    <span v-if="icon" class="text-base">{{ icon }}</span>
    {{ name }}
  </span>
</template>

<script setup>
defineProps({
  name: {
    type: String,
    required: true
  },
  type: {
    type: String,
    required: true,
    validator: (value) => ['income', 'expense'].includes(value)
  },
  icon: String,
  color: String
})
</script>

