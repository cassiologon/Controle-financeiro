<template>
  <div class="space-y-8">
    <!-- Header -->
    <div class="flex justify-between items-center">
      <div>
        <h1 class="text-3xl font-bold text-gray-900 mb-2">Categorias</h1>
        <p class="text-gray-600">Organize suas receitas e despesas</p>
      </div>
      <Button variant="primary" @click="showModal = true" class="shadow-lg">
        <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
        </svg>
        Nova Categoria
      </Button>
    </div>

    <!-- Loading State -->
    <div v-if="loading" class="flex justify-center py-16">
      <LoadingSpinner message="Carregando categorias..." />
    </div>

    <!-- Categories Grid -->
    <div v-if="!loading && hasCategories" class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
      <Card
        v-for="(category, index) in categories"
        :key="index"
        class="cursor-pointer group animate-slide-up"
        :style="{ animationDelay: (index * 50) + 'ms' }"
        @click="editCategory(category)"
      >
        <div class="flex items-center justify-between">
          <div class="flex items-center gap-4 flex-1 min-w-0">
            <div
              class="w-14 h-14 rounded-2xl flex items-center justify-center flex-shrink-0 shadow-lg transition-transform duration-300 group-hover:scale-110"
              :style="{
                backgroundColor: (category.color || '#6366f1') + '20',
                color: category.color || '#6366f1',
              }"
            >
              <span v-if="category.icon" class="text-2xl">{{ category.icon }}</span>
              <span v-else class="text-xl">{{ category.type === 'income' ? '💰' : '💸' }}</span>
            </div>
            <div class="flex-1 min-w-0">
              <h3 class="font-bold text-gray-900 mb-1 truncate">{{ category.name }}</h3>
              <CategoryBadge
                :name="category.type === 'income' ? 'Receita' : 'Despesa'"
                :type="category.type"
              />
            </div>
          </div>
          <button
            @click.stop="deleteCategory(category)"
            class="ml-4 p-2 rounded-xl text-red-500 hover:text-red-700 hover:bg-red-50 transition-all flex-shrink-0"
          >
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
            </svg>
          </button>
        </div>
      </Card>
    </div>

    <!-- Empty State -->
    <div v-if="!loading && !hasCategories" class="text-center py-16">
      <div class="w-24 h-24 mx-auto mb-6 rounded-full bg-gradient-to-br from-gray-100 to-gray-200 flex items-center justify-center">
        <span class="text-4xl">📁</span>
      </div>
      <h3 class="text-xl font-bold text-gray-900 mb-2">Nenhuma categoria encontrada</h3>
      <p class="text-gray-500 mb-6">Comece criando sua primeira categoria</p>
      <Button variant="primary" @click="showModal = true">
        + Criar Categoria
      </Button>
    </div>

    <!-- Confirm Delete Modal -->
    <ConfirmModal
      :show="showConfirmModal"
      title="Confirmar exclusão"
      :message="deleteCategoryMessage"
      @confirm="handleConfirmDelete"
      @cancel="showConfirmModal = false"
    />

    <!-- Modal -->
    <Modal
      :show="showModal"
      :title="editingCategory ? 'Editar Categoria' : 'Nova Categoria'"
      :show-footer="true"
      @close="closeModal"
    >
      <template #default>
        <form ref="formRef" @submit.prevent="saveCategory" id="category-form">
          <div class="space-y-5">
            <Input
              v-model="form.name"
              type="text"
              label="Nome"
              floating-label
              placeholder="Nome da categoria"
              required
              :error="errors.name"
            />
            <Select
              v-model="form.type"
              :options="[
                { value: 'income', label: 'Receita' },
                { value: 'expense', label: 'Despesa' },
              ]"
              label="Tipo"
              required
              :error="errors.type"
            />
            <div class="relative" ref="emojiPickerRef">
              <label class="block text-sm font-semibold text-gray-700 mb-2">
                Ícone (emoji)
                <span v-if="errors.icon" class="text-error-500">*</span>
              </label>
              <button
                type="button"
                @click.stop="showEmojiPicker = !showEmojiPicker"
                class="w-16 h-16 rounded-xl border-2 border-gray-300 hover:border-indigo-500 hover:bg-indigo-50 flex items-center justify-center text-3xl transition-all"
                :class="form.icon ? '' : 'bg-gray-50'"
                :title="form.icon || 'Selecione um emoji'"
              >
                <span>{{ form.icon || '😀' }}</span>
              </button>
              <div v-if="errors.icon" class="mt-2 flex items-center gap-2 text-sm text-error-600">
                <svg class="w-4 h-4 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20">
                  <path
                    fill-rule="evenodd"
                    d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7 4a1 1 0 11-2 0 1 1 0 012 0zm-1-9a1 1 0 00-1 1v4a1 1 0 102 0V6a1 1 0 00-1-1z"
                    clip-rule="evenodd"
                  />
                </svg>
                <span>{{ errors.icon }}</span>
              </div>
              <p v-if="!errors.icon" class="mt-2 text-sm text-gray-500">
                Use um emoji para representar a categoria
              </p>
              <div
                v-if="showEmojiPicker"
                class="absolute z-50 mt-2 left-0"
              >
                <EmojiPicker
                  :model-value="form.icon"
                  @update:model-value="handleEmojiSelect"
                  @close="showEmojiPicker = false"
                />
              </div>
            </div>
            <div>
              <label class="block text-sm font-semibold text-gray-700 mb-2">Cor</label>
              <div class="flex items-center gap-3">
                <input
                  :value="form.color || '#6366f1'"
                  @input="form.color = $event.target.value"
                  type="color"
                  class="w-16 h-16 rounded-xl border-2 border-gray-200 cursor-pointer"
                />
                <div class="flex-1">
                  <Input
                    :model-value="form.color || '#6366f1'"
                    @update:model-value="form.color = $event"
                    type="text"
                    placeholder="#6366f1"
                    :error="errors.color"
                  />
                </div>
              </div>
            </div>
            <div v-if="form.type === 'expense'">
              <label class="block text-sm font-semibold text-gray-700 mb-2">
                Palavras-chave para categorização automática
              </label>
              <Input
                :model-value="keywordsString"
                @update:model-value="updateKeywords($event)"
                type="text"
                placeholder="burguer, restaurante, comida, lanche"
                :error="errors.keywords"
                hint="Separe as palavras-chave por vírgula. Exemplo: 'burguer' categorizará automaticamente transações como 'Bruttus Burguer'"
              />
              <p class="text-xs text-gray-500 mt-1">
                Use palavras-chave para que transações importadas sejam categorizadas automaticamente
              </p>
            </div>
          </div>
        </form>
      </template>
      <template #footer>
        <Button variant="secondary" @click="closeModal">Cancelar</Button>
        <Button type="submit" form="category-form" variant="primary" :loading="saving">Salvar</Button>
      </template>
    </Modal>
  </div>
</template>

<script setup>
import { ref, computed, watch, onMounted } from 'vue'
import { categoryService } from '@/services/categoryService'
import Card from '@/components/Card.vue'
import Button from '@/components/Button.vue'
import Input from '@/components/Input.vue'
import Select from '@/components/Select.vue'
import Modal from '@/components/Modal.vue'
import CategoryBadge from '@/components/CategoryBadge.vue'
import LoadingSpinner from '@/components/LoadingSpinner.vue'
import ConfirmModal from '@/components/ConfirmModal.vue'
import EmojiPicker from '@/components/EmojiPicker.vue'
import { useToast } from '@/composables/useToast'

const { success, error: showError } = useToast()

const categories = ref([])
const loading = ref(false)
const saving = ref(false)
const showModal = ref(false)
const editingCategory = ref(null)
const showConfirmModal = ref(false)
const categoryToDelete = ref(null)
const showEmojiPicker = ref(false)
const emojiPickerRef = ref(null)

const form = ref({
  name: '',
  type: 'expense',
  icon: '',
  color: '#6366f1',
  keywords: [],
})

const errors = ref({})

function getRandomColor() {
  return '#' + Math.floor(Math.random() * 16777215).toString(16).padStart(6, '0')
}

watch(showModal, (visible) => {
  if (visible && !editingCategory.value) {
    form.value.color = getRandomColor()
  }
})

const keywordsString = computed(() => {
  if (!form.value.keywords || !Array.isArray(form.value.keywords)) {
    return ''
  }
  return form.value.keywords.join(', ')
})

const deleteCategoryMessage = computed(() => {
  if (!categoryToDelete.value) return ''
  return `Tem certeza que deseja excluir a categoria '${categoryToDelete.value.name}'? Esta ação não pode ser desfeita e todas as transações associadas perderão esta categoria.`
})

function updateKeywords(value) {
  if (!value || value.trim() === '') {
    form.value.keywords = []
    return
  }
  // Divide por vírgula, remove espaços e filtra vazios
  form.value.keywords = value
    .split(',')
    .map(k => k.trim())
    .filter(k => k.length > 0)
}

const hasCategories = computed(() => {
  return Array.isArray(categories.value) && categories.value.length > 0
})

onMounted(async () => {
  await loadCategories()
})

async function loadCategories() {
  loading.value = true
  try {
    const data = await categoryService.getAll()
    categories.value = Array.isArray(data) ? data : []
  } catch (error) {
    console.error('Error loading categories:', error)
    categories.value = []
  } finally {
    loading.value = false
  }
}

function editCategory(category) {
  editingCategory.value = category
  form.value = {
    name: category.name,
    type: category.type,
    icon: category.icon || '',
    color: category.color || '#6366f1',
    keywords: category.keywords || [],
  }
  showModal.value = true
}

function closeModal() {
  showModal.value = false
  editingCategory.value = null
  showEmojiPicker.value = false
  form.value = {
    name: '',
    type: 'expense',
    icon: '',
    color: getRandomColor(),
    keywords: [],
  }
  errors.value = {}
}

function handleEmojiSelect(emoji) {
  form.value.icon = emoji
  showEmojiPicker.value = false
}

async function saveCategory() {
  errors.value = {}
  saving.value = true

  try {
    if (editingCategory.value) {
      await categoryService.update(editingCategory.value.id, form.value)
    } else {
      await categoryService.create(form.value)
    }
    closeModal()
    await loadCategories()
  } catch (error) {
    if (error.response?.data?.errors) {
      errors.value = error.response.data.errors
    }
  } finally {
    saving.value = false
  }
}

function deleteCategory(category) {
  categoryToDelete.value = category
  showConfirmModal.value = true
}

async function handleConfirmDelete() {
  if (!categoryToDelete.value) return

  showConfirmModal.value = false
  const categoryId = categoryToDelete.value.id

  try {
    await categoryService.delete(categoryId)
    await loadCategories()
    success('Categoria excluída com sucesso!')
  } catch (err) {
    console.error('Error deleting category:', err)
    showError('Erro ao excluir categoria. Tente novamente.')
  } finally {
    categoryToDelete.value = null
  }
}
</script>
