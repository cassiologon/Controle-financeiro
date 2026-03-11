<template>
  <Teleport to="body">
    <Transition name="modal">
      <div
        v-if="show"
        class="fixed inset-0 z-50 overflow-y-auto"
        @click.self="$emit('cancel')"
      >
        <div class="flex min-h-screen items-center justify-center p-4">
          <!-- Backdrop -->
          <div
            class="fixed inset-0 bg-black/60 backdrop-blur-sm transition-opacity"
            @click="$emit('cancel')"
          ></div>
          
          <!-- Modal Content -->
          <div
            class="relative bg-white/95 backdrop-blur-xl rounded-3xl shadow-soft-lg max-w-md w-full p-8 transform transition-all border border-white/50"
          >
            <!-- Icon -->
            <div class="flex justify-center mb-6">
              <div class="w-20 h-20 rounded-full bg-error-100 flex items-center justify-center">
                <svg class="w-10 h-10 text-error-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                </svg>
              </div>
            </div>

            <!-- Title -->
            <h3 class="text-2xl font-bold text-gray-900 text-center mb-3">
              {{ title }}
            </h3>

            <!-- Message -->
            <p class="text-gray-600 text-center mb-8">
              {{ message }}
            </p>
            
            <!-- Footer -->
            <div class="flex justify-center gap-3 pt-6 border-t border-gray-200">
              <Button variant="secondary" @click="$emit('cancel')" class="min-w-[120px]">
                Cancelar
              </Button>
              <Button variant="primary" @click="$emit('confirm')" class="min-w-[120px] bg-error-500 hover:bg-error-600">
                Excluir
              </Button>
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
    default: 'Confirmar exclusão'
  },
  message: {
    type: String,
    required: true
  }
})

defineEmits(['confirm', 'cancel'])
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

