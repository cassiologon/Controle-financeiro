<template>
  <Modal
    :show="show"
    title="Nova Categoria"
    :show-footer="true"
    @close="$emit('close')"
  >
    <template #default>
      <form ref="formRef" @submit.prevent="saveCategory" id="category-form-modal">
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
            <div v-if="showEmojiPicker" class="absolute z-50 mt-2 left-0">
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
              hint="Separe as palavras-chave por vírgula"
            />
          </div>
        </div>
      </form>
    </template>
    <template #footer>
      <Button variant="secondary" @click="$emit('close')">Cancelar</Button>
      <Button type="submit" form="category-form-modal" variant="primary" :loading="saving">Salvar</Button>
    </template>
  </Modal>
</template>

<script setup>
import { ref, computed, watch } from 'vue'
import { categoryService } from '@/services/categoryService'
import Input from '@/components/Input.vue'
import Select from '@/components/Select.vue'
import Modal from '@/components/Modal.vue'
import Button from '@/components/Button.vue'
import EmojiPicker from '@/components/EmojiPicker.vue'

const props = defineProps({
  show: {
    type: Boolean,
    default: false
  },
  defaultType: {
    type: String,
    default: 'expense'
  }
})

const emit = defineEmits(['close', 'saved'])

function getRandomColor() {
  return '#' + Math.floor(Math.random() * 16777215).toString(16).padStart(6, '0')
}

const form = ref({
  name: '',
  type: props.defaultType,
  icon: '',
  color: getRandomColor(),
  keywords: [],
})

const errors = ref({})
const saving = ref(false)
const showEmojiPicker = ref(false)

watch(() => props.show, (visible) => {
  if (visible) {
    form.value = {
      name: '',
      type: props.defaultType,
      icon: '',
      color: getRandomColor(),
      keywords: [],
    }
    errors.value = {}
  }
})

watch(() => props.defaultType, (type) => {
  form.value.type = type
})

const keywordsString = computed(() => {
  if (!form.value.keywords || !Array.isArray(form.value.keywords)) {
    return ''
  }
  return form.value.keywords.join(', ')
})

function updateKeywords(value) {
  if (!value || value.trim() === '') {
    form.value.keywords = []
    return
  }
  form.value.keywords = value
    .split(',')
    .map(k => k.trim())
    .filter(k => k.length > 0)
}

function handleEmojiSelect(emoji) {
  form.value.icon = emoji
  showEmojiPicker.value = false
}

async function saveCategory() {
  errors.value = {}
  saving.value = true

  try {
    const category = await categoryService.create(form.value)
    emit('saved', category)
    emit('close')
  } catch (error) {
    if (error.response?.data?.errors) {
      errors.value = error.response.data.errors
    }
  } finally {
    saving.value = false
  }
}
</script>
