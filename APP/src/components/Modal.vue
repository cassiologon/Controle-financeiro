<template>
  <Teleport to="body">
    <Transition name="modal">
      <div
        v-if="show"
        class="fixed inset-0 z-50 overflow-y-auto"
        @click.self="$emit('close')"
      >
        <div class="flex min-h-screen items-center justify-center p-4">
          <!-- Backdrop -->
          <div
            class="fixed inset-0 bg-black/60 backdrop-blur-sm transition-opacity"
            @click="$emit('close')"
          ></div>
          
          <!-- Modal Content -->
          <div
            class="relative bg-white/95 backdrop-blur-xl rounded-3xl shadow-soft-lg max-w-md w-full p-8 transform transition-all border border-white/50"
          >
            <!-- Header -->
            <div class="flex justify-between items-center mb-6">
              <h3 class="text-2xl font-bold text-gray-900">{{ title }}</h3>
              <button
                @click="$emit('close')"
                class="w-10 h-10 rounded-xl bg-gray-100 hover:bg-gray-200 flex items-center justify-center transition-colors group"
              >
                <svg
                  class="w-5 h-5 text-gray-500 group-hover:text-gray-700 transition-colors"
                  fill="none"
                  stroke="currentColor"
                  viewBox="0 0 24 24"
                >
                  <path
                    stroke-linecap="round"
                    stroke-linejoin="round"
                    stroke-width="2"
                    d="M6 18L18 6M6 6l12 12"
                  />
                </svg>
              </button>
            </div>
            
            <!-- Content -->
            <div class="mb-6">
              <slot />
            </div>
            
            <!-- Footer -->
            <div v-if="showFooter" class="flex justify-end gap-3 pt-6 border-t border-gray-200">
              <slot name="footer">
                <Button variant="secondary" @click="$emit('close')">Cancelar</Button>
                <Button variant="primary" @click="$emit('confirm')">Confirmar</Button>
              </slot>
            </div>
          </div>
        </div>
      </div>
    </Transition>
  </Teleport>
</template>

<script setup>
import Button from './Button.vue'

defineProps({
  show: {
    type: Boolean,
    required: true
  },
  title: {
    type: String,
    required: true
  },
  showFooter: Boolean
})

defineEmits(['close', 'confirm'])
</script>

<style scoped>
.modal-enter-active {
  transition: opacity 0.3s ease;
}

.modal-leave-active {
  transition: opacity 0.2s ease;
}

.modal-enter-active .relative {
  animation: scaleIn 0.3s ease-out;
}

.modal-leave-active .relative {
  animation: scaleOut 0.2s ease-in;
}

.modal-enter-from,
.modal-leave-to {
  opacity: 0;
}

@keyframes scaleIn {
  from {
    opacity: 0;
    transform: scale(0.95) translateY(-10px);
  }
  to {
    opacity: 1;
    transform: scale(1) translateY(0);
  }
}

@keyframes scaleOut {
  from {
    opacity: 1;
    transform: scale(1) translateY(0);
  }
  to {
    opacity: 0;
    transform: scale(0.95) translateY(-10px);
  }
}
</style>
