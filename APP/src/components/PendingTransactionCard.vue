<template>
  <div
    class="group relative rounded-2xl bg-white/80 backdrop-blur-sm border-2 border-warning-200 shadow-soft hover:shadow-soft-lg transition-all duration-300 animate-slide-up"
    :style="{ animationDelay: `${index * 50}ms` }"
  >
    <!-- Warning gradient overlay -->
    <div
      class="absolute inset-0 opacity-0 group-hover:opacity-100 transition-opacity duration-300 bg-gradient-to-r from-warning-50/50 to-warning-100/30"
    ></div>

    <div class="relative z-10 p-5">
      <div class="flex items-center justify-between mb-4">
        <!-- Left Section: Icon and Info -->
        <div class="flex items-center gap-4 flex-1 min-w-0">
          <!-- Warning Icon -->
          <div
            class="relative w-14 h-14 rounded-2xl flex items-center justify-center flex-shrink-0 bg-gradient-to-br from-warning-400 to-warning-600 shadow-lg shadow-warning-500/30 transition-transform duration-300 group-hover:scale-110 group-hover:rotate-3"
          >
            <span class="text-2xl filter drop-shadow-sm">⚠️</span>
            <span class="absolute inset-0 rounded-2xl animate-ping opacity-20 bg-warning-400"></span>
          </div>

          <!-- Transaction Info -->
          <div class="flex-1 min-w-0">
            <h4 class="font-bold text-gray-900 truncate mb-1 group-hover:text-warning-700 transition-colors">
              {{ transaction.description }}
            </h4>
            <p class="text-xs text-gray-400 font-medium mb-2">
              {{ formatDate(transaction.date) }}
            </p>
            <span class="inline-flex items-center px-2 py-0.5 rounded-lg text-xs font-semibold bg-warning-100 text-warning-700">
              Pendente
            </span>
          </div>
        </div>

        <!-- Right Section: Amount -->
        <div class="flex-shrink-0 ml-4 text-right">
          <p class="text-2xl font-bold mb-1 transition-all duration-300 group-hover:scale-105 text-error-600">
            -R$ {{ formatCurrency(transaction.amount) }}
          </p>
          <p class="text-xs font-medium text-error-500">
            Despesa
          </p>
        </div>
      </div>

      <!-- Category Selection -->
      <div class="mt-4 pt-4 border-t border-gray-100">
        <div class="flex items-center gap-3 mb-3">
          <CategorySelect
            v-model="selectedCategoryId"
            :categories="categoryOptions"
            placeholder="Selecione uma categoria"
            class="flex-1"
            :error="error"
            :transaction-info="{
              description: transaction.description,
              amount: formatCurrency(transaction.amount)
            }"
            @category-created="emit('category-created', $event)"
          />
          <Button
            variant="primary"
            @click="handleCategorize"
            :loading="categorizing"
            :disabled="!selectedCategoryId || categorizing"
            class="flex-shrink-0"
          >
            Categorizar
          </Button>
        </div>
        <!-- Palavras-chave que serão adicionadas à categoria -->
        <div v-if="selectedCategoryId" class="mt-3 space-y-2">
          <label class="text-xs font-medium text-gray-500">
            Palavras-chave para a categoria
          </label>
          <div v-if="loadingKeywords" class="text-xs text-gray-400">
            Carregando...
          </div>
          <div v-else class="flex flex-wrap gap-2 items-center">
            <span
              v-for="(kw, idx) in keywordsToAdd"
              :key="idx"
              class="inline-flex items-center gap-1 px-2 py-1 rounded-lg text-xs font-medium bg-primary-100 text-primary-700"
            >
              {{ kw }}
              <button
                type="button"
                @click="removeKeyword(idx)"
                class="hover:bg-primary-200 rounded p-0.5 transition-colors"
                aria-label="Remover"
              >
                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                </svg>
              </button>
            </span>
            <input
              v-model="newKeywordInput"
              type="text"
              placeholder="+ Adicionar"
              class="w-24 px-2 py-1 text-xs border border-gray-200 rounded-lg focus:outline-none focus:ring-2 focus:ring-primary-500 focus:border-transparent"
              @keydown="onKeywordInputKeydown"
              @blur="addKeyword"
            />
          </div>
        </div>
        <div class="flex items-center justify-end mt-3">
          <button
            @click="handleDelete"
            class="p-2 rounded-xl text-red-500 hover:text-red-700 hover:bg-red-50 transition-all text-sm font-medium"
            :disabled="categorizing"
          >
            <svg class="w-5 h-5 inline mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
            </svg>
            Deletar
          </button>
        </div>
        <p v-if="error" class="text-xs text-error-600 mt-2">{{ error }}</p>
      </div>
    </div>

    <!-- Bottom border accent -->
    <div
      class="absolute bottom-0 left-0 right-0 h-1 bg-gradient-to-r from-warning-400 to-warning-600 transition-all duration-300 group-hover:h-1.5"
    ></div>
  </div>
</template>

<script setup>
import { ref, computed, watch } from 'vue'
import { invoiceImportService } from '@/services/invoiceImportService'
import CategorySelect from '@/components/CategorySelect.vue'
import Button from '@/components/Button.vue'

const props = defineProps({
  transaction: {
    type: Object,
    required: true
  },
  index: Number,
  categories: {
    type: Array,
    default: () => []
  }
})

const emit = defineEmits(['categorized', 'delete', 'category-created'])

const selectedCategoryId = ref('')
const keywordsToAdd = ref([])
const newKeywordInput = ref('')
const loadingKeywords = ref(false)
const categorizing = ref(false)
const error = ref(null)

// Carrega preview das keywords quando seleciona categoria
watch([() => props.transaction.description, selectedCategoryId], async ([description, categoryId]) => {
  if (!categoryId || !description?.trim()) {
    keywordsToAdd.value = []
    return
  }
  loadingKeywords.value = true
  try {
    const { keywords } = await invoiceImportService.previewKeywords(description)
    keywordsToAdd.value = [...(keywords || [])]
  } catch {
    keywordsToAdd.value = []
  } finally {
    loadingKeywords.value = false
  }
}, { immediate: true })

function addKeyword() {
  const kw = newKeywordInput.value?.trim()?.toLowerCase()
  if (kw && kw.length > 3 && !keywordsToAdd.value.includes(kw)) {
    keywordsToAdd.value = [...keywordsToAdd.value, kw]
    newKeywordInput.value = ''
  }
}

function removeKeyword(index) {
  keywordsToAdd.value = keywordsToAdd.value.filter((_, i) => i !== index)
}

function onKeywordInputKeydown(e) {
  if (e.key === 'Enter') {
    e.preventDefault()
    addKeyword()
  }
}

const categoryOptions = computed(() =>
  props.categories
    .filter(cat => cat.type === 'expense')
    .map(cat => ({
      id: cat.id,
      name: cat.name,
      icon: cat.icon || '📁',
      color: cat.color || '#6366f1'
    }))
)

async function handleCategorize() {
  if (!selectedCategoryId.value || selectedCategoryId.value === '') {
    error.value = 'Selecione uma categoria'
    return
  }

  categorizing.value = true
  error.value = null

  try {
    const response = await invoiceImportService.categorize(
      props.transaction.id,
      selectedCategoryId.value,
      keywordsToAdd.value.length > 0 ? keywordsToAdd.value : null
    )
    
    // Coleta todos os IDs: a transação original + as categorizadas automaticamente
    const categorizedIds = [props.transaction.id]
    if (response.auto_categorized_ids && Array.isArray(response.auto_categorized_ids)) {
      categorizedIds.push(...response.auto_categorized_ids)
    }
    
    emit('categorized', categorizedIds)
  } catch (err) {
    error.value = err.response?.data?.message || 'Erro ao categorizar transação'
    console.error('Error categorizing transaction:', err)
  } finally {
    categorizing.value = false
  }
}

function handleDelete() {
  emit('delete', props.transaction)
}

function formatCurrency(value) {
  const numValue = typeof value === 'string' ? parseFloat(value) : Number(value)
  if (isNaN(numValue)) {
    return '0,00'
  }
  return numValue.toFixed(2).replace('.', ',')
}

function formatDate(date) {
  const d = new Date(date)
  const today = new Date()
  const yesterday = new Date(today)
  yesterday.setDate(yesterday.getDate() - 1)

  if (d.toDateString() === today.toDateString()) {
    return 'Hoje'
  } else if (d.toDateString() === yesterday.toDateString()) {
    return 'Ontem'
  }
  return d.toLocaleDateString('pt-BR', { day: '2-digit', month: 'short' })
}
</script>

<style scoped>
.animate-slide-up {
  animation: slideUp 0.4s ease-out backwards;
}

@keyframes slideUp {
  from {
    opacity: 0;
    transform: translateY(20px);
  }
  to {
    opacity: 1;
    transform: translateY(0);
  }
}
</style>

