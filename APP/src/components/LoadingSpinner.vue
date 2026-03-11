<template>
  <div class="flex flex-col items-center justify-center p-8">
    <div class="relative">
      <!-- Outer ring -->
      <div
        class="w-16 h-16 border-4 border-primary-200 rounded-full animate-spin"
        style="border-top-color: transparent; animation-duration: 1s;"
      ></div>
      <!-- Inner ring -->
      <div
        class="absolute top-2 left-2 w-12 h-12 border-4 border-primary-400 rounded-full animate-spin"
        style="border-top-color: transparent; animation-duration: 0.5s; animation-direction: reverse;"
      ></div>
      <!-- Center dot -->
      <div class="absolute top-1/2 left-1/2 -translate-x-1/2 -translate-y-1/2 w-3 h-3 bg-primary-600 rounded-full"></div>
    </div>
    <p v-if="message" class="mt-4 text-sm font-medium text-gray-600 animate-pulse">
      {{ message }}
    </p>
  </div>
</template>

<script setup>
defineProps({
  message: String
})
</script>
