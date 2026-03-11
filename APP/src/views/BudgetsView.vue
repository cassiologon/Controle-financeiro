<template>
  <div>
    <div class="flex justify-between items-center mb-6">
      <h1 class="text-2xl font-bold text-gray-900">Orçamentos</h1>
      <Button variant="primary" @click="showModal = true">
        + Novo Orçamento
      </Button>
    </div>

    <!-- Month/Year Selector -->
    <Card class="mb-6">
      <div class="flex gap-4 items-end">
        <div class="flex-1">
          <Select
            v-model="selectedMonth"
            :options="monthOptions"
            label="Mês"
          />
        </div>
        <div class="flex-1">
          <Input
            v-model="selectedYear"
            type="number"
            label="Ano"
            :min="2020"
            :max="2100"
          />
        </div>
        <Button variant="secondary" @click="loadBudgets">Filtrar</Button>
      </div>
    </Card>

    <div v-if="loading" class="flex justify-center py-8">
      <LoadingSpinner />
    </div>

    <div v-else-if="budgets.length === 0" class="text-center py-12 text-gray-500">
      Nenhum orçamento encontrado para este período
    </div>

    <div v-else class="space-y-4">
      <Card
        v-for="budget in budgets"
        :key="budget.id"
        class="cursor-pointer hover:shadow-lg transition-shadow"
        @click="editBudget(budget)"
      >
        <div class="flex items-center justify-between mb-4">
          <div class="flex items-center gap-3">
            <div
              v-if="budget.category"
              class="w-12 h-12 rounded-full flex items-center justify-center"
              :style="{
                backgroundColor: (budget.category.color || '#6366f1') + '20',
                color: budget.category.color || '#6366f1',
              }"
            >
              <span v-if="budget.category.icon" class="text-2xl">{{ budget.category.icon }}</span>
              <span v-else class="text-xl">💰</span>
            </div>
            <div>
              <h3 class="font-semibold text-gray-900">{{ budget.category?.name || 'Sem categoria' }}</h3>
              <p class="text-sm text-gray-500">
                {{ getMonthName(budget.month) }} / {{ budget.year }}
              </p>
            </div>
          </div>
          <div class="text-right">
            <p class="text-lg font-semibold text-gray-900">
              R$ {{ formatCurrency(budget.amount) }}
            </p>
          </div>
        </div>
          <button
            @click.stop="deleteBudget(budget)"
            class="text-red-500 hover:text-red-700 text-sm"
          >
            Excluir
          </button>
      </Card>
    </div>

    <!-- Confirm Delete Modal -->
    <ConfirmModal
      :show="showConfirmModal"
      title="Confirmar exclusão"
      :message="deleteBudgetMessage"
      @confirm="handleConfirmDelete"
      @cancel="showConfirmModal = false"
    />

    <Modal
      :show="showModal"
      :title="editingBudget ? 'Editar Orçamento' : 'Novo Orçamento'"
      :show-footer="true"
      @close="closeModal"
    >
      <template #default>
        <form ref="formRef" @submit.prevent="saveBudget" id="budget-form">
          <div class="space-y-4">
            <Select
              v-model="form.category_id"
              :options="categoryOptions"
              label="Categoria"
              required
              :error="errors.category_id"
            />
            <Input
              v-model="form.amount"
              type="number"
              step="0.01"
              label="Valor"
              required
              :error="errors.amount"
            />
            <Select
              v-model="form.month"
              :options="monthOptions"
              label="Mês"
              required
              :error="errors.month"
            />
            <Input
              v-model="form.year"
              type="number"
              label="Ano"
              :min="2020"
              :max="2100"
              required
              :error="errors.year"
            />
          </div>
        </form>
      </template>
      <template #footer>
        <Button variant="secondary" @click="closeModal">Cancelar</Button>
        <Button type="submit" form="budget-form" variant="primary" :loading="saving">Salvar</Button>
      </template>
    </Modal>
  </div>
</template>

<script setup>
import { ref, onMounted, computed } from 'vue'
import { budgetService } from '@/services/budgetService'
import { categoryService } from '@/services/categoryService'
import Card from '@/components/Card.vue'
import Button from '@/components/Button.vue'
import Input from '@/components/Input.vue'
import Select from '@/components/Select.vue'
import Modal from '@/components/Modal.vue'
import LoadingSpinner from '@/components/LoadingSpinner.vue'
import ConfirmModal from '@/components/ConfirmModal.vue'
import { useToast } from '@/composables/useToast'

const { success, error: showError } = useToast()

const budgets = ref([])
const categories = ref([])
const loading = ref(false)
const saving = ref(false)
const showModal = ref(false)
const editingBudget = ref(null)
const selectedMonth = ref('')
const selectedYear = ref(new Date().getFullYear())
const showConfirmModal = ref(false)
const budgetToDelete = ref(null)

const form = ref({
  category_id: '',
  amount: '',
  month: new Date().getMonth() + 1,
  year: new Date().getFullYear(),
})

const errors = ref({})

const monthOptions = computed(() => {
  const months = [
    { value: '', label: 'Todos os meses' },
    { value: 1, label: 'Janeiro' },
    { value: 2, label: 'Fevereiro' },
    { value: 3, label: 'Março' },
    { value: 4, label: 'Abril' },
    { value: 5, label: 'Maio' },
    { value: 6, label: 'Junho' },
    { value: 7, label: 'Julho' },
    { value: 8, label: 'Agosto' },
    { value: 9, label: 'Setembro' },
    { value: 10, label: 'Outubro' },
    { value: 11, label: 'Novembro' },
    { value: 12, label: 'Dezembro' },
  ]
  return months
})

const categoryOptions = computed(() => {
  const options = [{ value: '', label: 'Selecione uma categoria' }]
  categories.value.forEach(cat => {
    options.push({ value: cat.id, label: cat.name })
  })
  return options
})

const deleteBudgetMessage = computed(() => {
  if (!budgetToDelete.value) return ''
  const monthName = getMonthName(budgetToDelete.value.month)
  const categoryName = budgetToDelete.value.category?.name || 'Sem categoria'
  return `Tem certeza que deseja excluir o orçamento de ${monthName}/${budgetToDelete.value.year} para a categoria "${categoryName}"? Esta ação não pode ser desfeita.`
})

onMounted(async () => {
  await loadCategories()
  await loadBudgets()
})

async function loadCategories() {
  try {
    categories.value = await categoryService.getAll()
  } catch (error) {
    console.error('Error loading categories:', error)
  }
}

async function loadBudgets() {
  loading.value = true
  try {
    budgets.value = await budgetService.getAll(selectedMonth.value, selectedYear.value)
  } catch (error) {
    console.error('Error loading budgets:', error)
  } finally {
    loading.value = false
  }
}

function editBudget(budget) {
  editingBudget.value = budget
  form.value = {
    category_id: budget.category_id,
    amount: budget.amount,
    month: budget.month,
    year: budget.year,
  }
  showModal.value = true
}

function closeModal() {
  showModal.value = false
  editingBudget.value = null
  form.value = {
    category_id: '',
    amount: '',
    month: new Date().getMonth() + 1,
    year: new Date().getFullYear(),
  }
  errors.value = {}
}

async function saveBudget() {
  errors.value = {}
  saving.value = true

  try {
    const payload = {
      category_id: Number(form.value.category_id),
      amount: Number(form.value.amount),
      month: Number(form.value.month),
      year: Number(form.value.year),
    }

    if (editingBudget.value) {
      await budgetService.update(editingBudget.value.id, payload)
    } else {
      await budgetService.create(payload)
    }
    success('Orçamento salvo com sucesso!')
    selectedMonth.value = payload.month
    selectedYear.value = payload.year
    closeModal()
    await loadBudgets()
  } catch (err) {
    if (err.response?.data?.errors) {
      errors.value = err.response.data.errors
    } else {
      showError('Erro ao salvar orçamento. Tente novamente.')
    }
  } finally {
    saving.value = false
  }
}

function deleteBudget(budget) {
  budgetToDelete.value = budget
  showConfirmModal.value = true
}

async function handleConfirmDelete() {
  if (!budgetToDelete.value) return

  showConfirmModal.value = false
  const budgetId = budgetToDelete.value.id

  try {
    await budgetService.delete(budgetId)
    await loadBudgets()
    success('Orçamento excluído com sucesso!')
  } catch (err) {
    console.error('Error deleting budget:', err)
    showError('Erro ao excluir orçamento. Tente novamente.')
  } finally {
    budgetToDelete.value = null
  }
}

function formatCurrency(value) {
  return Number(value).toFixed(2).replace('.', ',')
}

function getMonthName(month) {
  const months = [
    'Janeiro', 'Fevereiro', 'Março', 'Abril', 'Maio', 'Junho',
    'Julho', 'Agosto', 'Setembro', 'Outubro', 'Novembro', 'Dezembro'
  ]
  return months[month - 1] || ''
}
</script>

