<template>
  <div class="relative">
    <label v-if="label" :for="id" class="block text-sm font-medium text-gray-700 mb-1">
      {{ label }}
      <span v-if="required" class="text-red-500">*</span>
    </label>
    
    <!-- Input Button -->
    <button
      :id="id"
      type="button"
      :disabled="disabled"
      :class="[
        'w-full px-4 py-3 rounded-xl border-2 text-left transition-all duration-300',
        'bg-white/80 backdrop-blur-sm',
        'focus:outline-none focus:ring-2 focus:ring-primary-500/50',
        error 
          ? 'border-error-500 focus:ring-error-500/50' 
          : 'border-gray-200 hover:border-primary-300',
        disabled ? 'opacity-50 cursor-not-allowed' : 'cursor-pointer'
      ]"
      @click="showModal = true"
    >
      <div class="flex items-center justify-between">
        <div class="flex items-center gap-3 flex-1 min-w-0">
          <div v-if="selectedCategory" class="flex items-center gap-3 flex-1 min-w-0">
            <!-- Emoji Circle -->
            <div
              class="w-10 h-10 rounded-xl flex items-center justify-center flex-shrink-0 shadow-sm transition-transform duration-300"
              :style="{
                backgroundColor: (selectedCategory.color || '#6366f1') + '20',
                color: selectedCategory.color || '#6366f1',
              }"
            >
              <span class="text-xl">{{ selectedCategory.icon || '📁' }}</span>
            </div>
            <!-- Category Name -->
            <span class="font-medium text-gray-900 truncate">{{ selectedCategory.name }}</span>
          </div>
          <span v-else class="text-gray-400 font-medium">{{ placeholder || 'Selecione uma categoria' }}</span>
        </div>
        <!-- Arrow Icon -->
        <svg
          class="w-5 h-5 text-gray-400 flex-shrink-0"
          fill="none"
          stroke="currentColor"
          viewBox="0 0 24 24"
        >
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7" />
        </svg>
      </div>
    </button>

    <!-- Modal for Category Selection -->
    <Modal
      :show="showModal"
      title="Selecione uma categoria"
      :show-footer="false"
      @close="showModal = false"
    >
      <!-- Transaction Info -->
      <div v-if="transactionInfo" class="mb-6 p-4 bg-gradient-to-r from-warning-50 to-warning-100/50 rounded-xl border-2 border-warning-200">
        <div class="flex items-center justify-between">
          <div class="flex-1 min-w-0">
            <p class="text-sm font-medium text-gray-600 mb-1">Transação:</p>
            <h4 class="font-bold text-gray-900 text-lg truncate">{{ transactionInfo.description }}</h4>
          </div>
          <div class="ml-4 text-right flex-shrink-0">
            <p class="text-sm font-medium text-gray-600 mb-1">Valor:</p>
            <p class="text-2xl font-bold text-error-600">-R$ {{ transactionInfo.amount }}</p>
          </div>
        </div>
      </div>

      <div class="max-h-[60vh] overflow-y-auto">
        <div class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 gap-3">
          <div
            v-for="category in categories"
            :key="category.id"
            :class="[
              'px-4 py-4 flex flex-col items-center gap-2 cursor-pointer transition-all duration-200 rounded-xl relative',
              'hover:bg-gray-50 hover:shadow-md hover:scale-105',
              String(selectedCategoryId) === String(category.id) ? 'bg-primary-50 ring-2 ring-primary-500 shadow-md' : 'bg-gray-50'
            ]"
            @click="selectCategory(category)"
          >
            <!-- Emoji Circle -->
            <div
              class="w-16 h-16 rounded-xl flex items-center justify-center flex-shrink-0 shadow-sm transition-transform duration-300"
              :style="{
                backgroundColor: (category.color || '#6366f1') + '20',
                color: category.color || '#6366f1',
              }"
            >
              <span class="text-3xl">{{ category.icon || '📁' }}</span>
            </div>
            <!-- Category Name -->
            <span class="font-medium text-gray-900 text-sm text-center leading-tight">{{ category.name }}</span>
            <!-- Check Icon -->
            <div
              v-if="String(selectedCategoryId) === String(category.id)"
              class="absolute top-2 right-2 w-6 h-6 bg-primary-500 rounded-full flex items-center justify-center shadow-md"
            >
              <svg
                class="w-4 h-4 text-white"
                fill="none"
                stroke="currentColor"
                viewBox="0 0 24 24"
              >
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M5 13l4 4L19 7" />
              </svg>
            </div>
          </div>
        </div>
        
        <!-- Empty State -->
        <div v-if="categories.length === 0" class="px-4 py-12 text-center text-gray-500">
          <span class="text-4xl block mb-3">📭</span>
          <p class="text-base font-medium">Nenhuma categoria disponível</p>
        </div>
      </div>
    </Modal>

    <!-- Error Message -->
    <p v-if="error" class="mt-1 text-sm text-error-600">{{ error }}</p>
  </div>
</template>

<script setup>
import { ref, computed } from 'vue'
import Modal from './Modal.vue'

const props = defineProps({
  id: String,
  label: String,
  modelValue: {
    type: [String, Number],
    default: ''
  },
  categories: {
    type: Array,
    required: true,
    default: () => []
  },
  placeholder: String,
  disabled: Boolean,
  required: Boolean,
  error: String,
  transactionInfo: {
    type: Object,
    default: null
  }
})

const emit = defineEmits(['update:modelValue'])

const showModal = ref(false)

const selectedCategoryId = computed({
  get: () => props.modelValue,
  set: (value) => emit('update:modelValue', value)
})

const selectedCategory = computed(() => {
  if (!selectedCategoryId.value && selectedCategoryId.value !== 0) return null
  return props.categories.find(cat => String(cat.id) === String(selectedCategoryId.value)) || null
})

function selectCategory(category) {
  selectedCategoryId.value = category.id
  showModal.value = false
}
</script>

<style scoped>
/* Custom scrollbar for modal */
div::-webkit-scrollbar {
  width: 8px;
}

div::-webkit-scrollbar-track {
  background: #f1f1f1;
  border-radius: 10px;
}

div::-webkit-scrollbar-thumb {
  background: #cbd5e1;
  border-radius: 10px;
}

div::-webkit-scrollbar-thumb:hover {
  background: #94a3b8;
}
</style>
