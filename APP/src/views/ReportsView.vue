<template>
  <div>
    <div class="flex justify-between items-center mb-6">
      <h1 class="text-2xl font-bold text-gray-900">Relatórios</h1>
    </div>

    <!-- Date Range Selector -->
    <Card class="mb-6">
      <div class="flex gap-4 items-end">
        <div class="flex-1">
          <Input
            v-model="startDate"
            type="date"
            label="Data Inicial"
          />
        </div>
        <div class="flex-1">
          <Input
            v-model="endDate"
            type="date"
            label="Data Final"
          />
        </div>
        <Button variant="primary" @click="loadReports">Gerar Relatório</Button>
      </div>
    </Card>

    <div v-if="loading" class="flex justify-center py-8">
      <LoadingSpinner />
    </div>

    <div v-else>
      <!-- Summary -->
      <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-6">
        <Card v-if="typeFilter !== 'expense'">
          <div class="text-center">
            <p class="text-sm text-gray-600 mb-2">Total de Receitas</p>
            <p class="text-3xl font-bold text-green-600">
              R$ {{ formatCurrency(filteredTotalIncome) }}
            </p>
          </div>
        </Card>
        <Card v-if="typeFilter !== 'income'">
          <div class="text-center">
            <p class="text-sm text-gray-600 mb-2">Total de Despesas</p>
            <p class="text-3xl font-bold text-red-600">
              R$ {{ formatCurrency(filteredTotalExpense) }}
            </p>
          </div>
        </Card>
        <Card>
          <div class="text-center">
            <p class="text-sm text-gray-600 mb-2">Saldo</p>
            <p
              :class="[
                'text-3xl font-bold',
                filteredBalance >= 0 ? 'text-green-600' : 'text-red-600',
              ]"
            >
              R$ {{ formatCurrency(filteredBalance) }}
            </p>
          </div>
        </Card>
      </div>

      <!-- Gastos diários (bar chart) -->
      <Card title="Gastos diários" class="mb-6">
        <div v-if="!dailyExpensesChartData.labels?.length" class="h-64 flex items-center justify-center text-gray-500">
          Nenhum gasto no período selecionado
        </div>
        <div v-else class="h-64 cursor-pointer">
          <Bar :data="dailyExpensesChartData" :options="dailyExpensesChartOptions" />
        </div>
      </Card>

      <!-- Modal: gastos do dia -->
      <Modal
        :show="showDayModal"
        :title="dayModalTitle"
        :show-footer="false"
        @close="closeDayModal"
      >
        <div v-if="loadingDayExpenses" class="flex justify-center py-8">
          <LoadingSpinner />
        </div>
        <div v-else>
          <div v-if="dayExpenses.length === 0" class="text-center py-6 text-gray-500">
            Nenhuma despesa encontrada neste dia.
          </div>
          <ul v-else class="divide-y divide-gray-100 max-h-96 overflow-y-auto">
            <li
              v-for="tx in dayExpenses"
              :key="tx.id"
              class="flex items-center justify-between py-3 first:pt-0"
            >
              <div class="min-w-0 flex-1 pr-3">
                <p class="font-medium text-gray-900 truncate">{{ tx.description || 'Sem descrição' }}</p>
                <p class="text-sm text-gray-500">{{ tx.category?.name || 'Sem categoria' }}</p>
              </div>
              <p class="text-right font-semibold text-red-600 whitespace-nowrap">
                R$ {{ formatCurrency(tx.amount) }}
              </p>
            </li>
          </ul>
          <div class="mt-4 pt-4 border-t border-gray-200 flex justify-between items-center">
            <span class="font-medium text-gray-700">Total do dia</span>
            <span class="text-lg font-bold text-red-600">
              R$ {{ formatCurrency(dayExpensesTotal) }}
            </span>
          </div>
        </div>
      </Modal>

      <!-- Transactions by Category -->
      <Card>
        <template #header>
          <div class="flex items-center justify-between">
            <h3 class="text-xl font-bold text-gray-900 flex items-center gap-2">
              <span class="text-2xl">📊</span>
              Transações por Categoria
            </h3>
            <div class="flex gap-1 bg-gray-100 p-1 rounded-xl">
              <button
                v-for="opt in typeFilterOptions"
                :key="opt.value"
                :class="[
                  'px-4 py-1.5 rounded-lg text-xs font-semibold transition-all duration-200',
                  typeFilter === opt.value
                    ? 'bg-white text-primary-700 shadow-sm'
                    : 'text-gray-500 hover:text-gray-700',
                ]"
                @click="typeFilter = opt.value"
              >
                {{ opt.label }}
              </button>
            </div>
          </div>
        </template>
        <div v-if="filteredCategoriesByType.length === 0" class="text-center py-8 text-gray-500">
          Nenhuma transação no período
        </div>
        <div v-else class="space-y-2">
          <div
            v-for="item in filteredCategoriesByType"
            :key="`${item.category_id}-${item.type}`"
            class="border border-gray-200 rounded-lg overflow-hidden"
          >
            <!-- Category Header -->
            <div
              class="flex items-center justify-between p-4 bg-gray-50 cursor-pointer hover:bg-gray-100 transition-colors"
              @click="toggleCategoryTransactions(item)"
            >
              <div class="flex items-center gap-3">
                <div
                  class="w-10 h-10 rounded-full flex items-center justify-center"
                  :style="{
                    backgroundColor: (item.category?.color || '#6366f1') + '20',
                    color: item.category?.color || '#6366f1',
                  }"
                >
                  <span v-if="item.category?.icon" class="text-xl">{{ item.category.icon }}</span>
                  <span v-else class="text-lg">💰</span>
                </div>
                <div>
                  <p class="font-medium text-gray-900">{{ item.category?.name || 'Sem categoria' }}</p>
                  <CategoryBadge
                    :name="item.type === 'income' ? 'Receita' : 'Despesa'"
                    :type="item.type"
                  />
                </div>
              </div>
              <div class="flex items-center gap-3">
                <div class="text-right">
                  <p
                    :class="[
                      'text-lg font-semibold',
                      item.type === 'income' ? 'text-green-600' : 'text-red-600',
                    ]"
                  >
                    {{ item.type === 'income' ? '+' : '-' }}R$ {{ formatCurrency(item.total) }}
                  </p>
                  <p v-if="item.recurring_total > 0" class="text-xs text-purple-500 font-medium">
                    R$ {{ formatCurrency(item.recurring_total) }} recorrente
                  </p>
                </div>
                <svg
                  :class="[
                    'w-5 h-5 text-gray-400 transition-transform duration-200',
                    expandedCategories[`${item.category_id}-${item.type}`] ? 'rotate-90' : ''
                  ]"
                  fill="none"
                  stroke="currentColor"
                  viewBox="0 0 24 24"
                >
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7" />
                </svg>
              </div>
            </div>

            <!-- Transactions Dropdown -->
            <Transition name="dropdown">
              <div
                v-if="expandedCategories[`${item.category_id}-${item.type}`]"
                class="border-t border-gray-200 bg-white"
              >
                <div v-if="loadingTransactions[`${item.category_id}-${item.type}`]" class="flex justify-center py-8">
                  <LoadingSpinner />
                </div>
                <div
                  v-else-if="(categoryTransactionsMap[`${item.category_id}-${item.type}`]?.length === 0) && (categoryRecurringMap[`${item.category_id}-${item.type}`]?.length === 0)"
                  class="text-center py-8 text-gray-500"
                >
                  Nenhuma transação encontrada
                </div>
                <div v-else class="divide-y divide-gray-100">
                  <!-- Recurring items -->
                  <div
                    v-for="bill in (categoryRecurringMap[`${item.category_id}-${item.type}`] || [])"
                    :key="'rec-' + bill.id"
                    class="flex items-center justify-between p-4 bg-purple-50/40"
                  >
                    <div class="flex items-center gap-3 flex-1">
                      <svg class="w-4 h-4 text-purple-400 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
                      </svg>
                      <div>
                        <p class="font-medium text-gray-900 mb-1">{{ bill.description }}</p>
                        <p class="text-xs text-purple-500 font-medium">Recorrente</p>
                      </div>
                    </div>
                    <p
                      :class="[
                        'text-lg font-semibold',
                        bill.type === 'income' ? 'text-green-600' : 'text-red-600',
                      ]"
                    >
                      {{ bill.type === 'income' ? '+' : '-' }}R$ {{ formatCurrency(bill.amount) }}
                    </p>
                  </div>
                  <!-- Regular transactions -->
                  <div
                    v-for="transaction in categoryTransactionsMap[`${item.category_id}-${item.type}`]"
                    :key="transaction.id"
                    class="flex items-center justify-between p-4 hover:bg-gray-50 transition-colors"
                  >
                    <div class="flex-1">
                      <p class="font-medium text-gray-900 mb-1">{{ transaction.description }}</p>
                      <p class="text-sm text-gray-500">{{ formatDate(transaction.date) }}</p>
                    </div>
                    <p
                      :class="[
                        'text-lg font-semibold',
                        transaction.type === 'income' ? 'text-green-600' : 'text-red-600',
                      ]"
                    >
                      {{ transaction.type === 'income' ? '+' : '-' }}R$ {{ formatCurrency(transaction.amount) }}
                    </p>
                  </div>
                </div>
              </div>
            </Transition>
          </div>
        </div>
      </Card>
    </div>
  </div>
</template>

<script setup>
import { ref, onMounted, computed } from 'vue'
import {
  Chart as ChartJS,
  CategoryScale,
  LinearScale,
  BarElement,
  Tooltip,
  Legend,
} from 'chart.js'
import { Bar } from 'vue-chartjs'
import { dashboardService } from '@/services/dashboardService'
import { transactionService } from '@/services/transactionService'
import { recurringTransactionService } from '@/services/recurringTransactionService'
import Card from '@/components/Card.vue'
import Button from '@/components/Button.vue'
import Input from '@/components/Input.vue'
import CategoryBadge from '@/components/CategoryBadge.vue'
import LoadingSpinner from '@/components/LoadingSpinner.vue'
import Modal from '@/components/Modal.vue'

ChartJS.register(CategoryScale, LinearScale, BarElement, Tooltip, Legend)

const reportData = ref(null)
const loading = ref(false)
const expandedCategories = ref({})
const categoryTransactionsMap = ref({})
const categoryRecurringMap = ref({})
const allRecurringBills = ref([])
const loadingTransactions = ref({})
const typeFilter = ref('all')

const typeFilterOptions = [
  { value: 'all', label: 'Todas' },
  { value: 'expense', label: 'Despesas' },
  { value: 'income', label: 'Receitas' },
]

const filteredCategoriesByType = computed(() => {
  if (!reportData.value?.transactions_by_category) {
    return []
  }
  let items = reportData.value.transactions_by_category
  if (typeFilter.value !== 'all') {
    items = items.filter(item => item.type === typeFilter.value)
  }
  return [...items].sort((a, b) => {
    const valueA = parseFloat(a.total) || 0
    const valueB = parseFloat(b.total) || 0
    return valueB - valueA
  })
})

const filteredTotalIncome = computed(() => {
  if (typeFilter.value === 'expense') return 0
  return reportData.value?.summary?.total_income || 0
})

const filteredTotalExpense = computed(() => {
  if (typeFilter.value === 'income') return 0
  return reportData.value?.summary?.total_expense || 0
})

const filteredBalance = computed(() => {
  return filteredTotalIncome.value - filteredTotalExpense.value
})

function formatChartDate(dateStr) {
  const d = new Date(dateStr + 'T12:00:00')
  return d.toLocaleDateString('pt-BR', { day: '2-digit', month: '2-digit' })
}

const dailyExpensesChartData = computed(() => {
  const daily = reportData.value?.daily_expenses || []
  return {
    labels: daily.map((d) => formatChartDate(d.date)),
    datasets: [
      {
        label: 'Gastos (R$)',
        data: daily.map((d) => d.total),
        backgroundColor: 'rgba(239, 68, 68, 0.7)',
        borderColor: 'rgb(239, 68, 68)',
        borderWidth: 1,
      },
    ],
  }
})

const dailyExpensesChartOptions = {
  responsive: true,
  maintainAspectRatio: false,
  onClick(event, elements, chart) {
    if (!elements?.length) return
    const idx = elements[0].index
    const daily = reportData.value?.daily_expenses || []
    const dayInfo = daily[idx]
    if (!dayInfo) return
    selectedDayDate.value = dayInfo.date
    showDayModal.value = true
    loadDayExpenses(dayInfo.date)
  },
  onHover(event, elements) {
    event.native.target.style.cursor = elements?.length ? 'pointer' : 'default'
  },
  plugins: {
    legend: { display: false },
    tooltip: {
      callbacks: {
        label(context) {
          const v = context.raw
          return `R$ ${(typeof v === 'number' ? v : parseFloat(v) || 0).toFixed(2).replace('.', ',')}`
        },
      },
    },
  },
  scales: {
    y: {
      beginAtZero: true,
      ticks: {
        callback(value) {
          return 'R$ ' + (typeof value === 'number' ? value : 0).toFixed(0)
        },
      },
    },
  },
}

const dayModalTitle = computed(() =>
  selectedDayDate.value ? `Gastos em ${formatDate(selectedDayDate.value)}` : 'Gastos do dia'
)

const dayExpensesTotal = computed(() =>
  dayExpenses.value.reduce((s, t) => s + (parseFloat(t.amount) || 0), 0)
)

const getStartDate = () => {
  const date = new Date()
  date.setDate(1)
  return date.toISOString().split('T')[0]
}

const getEndDate = () => {
  const date = new Date()
  return date.toISOString().split('T')[0]
}

const startDate = ref(getStartDate())
const endDate = ref(getEndDate())

const showDayModal = ref(false)
const selectedDayDate = ref(null)
const dayExpenses = ref([])
const loadingDayExpenses = ref(false)

onMounted(async () => {
  await loadReports()
})

async function loadReports() {
  loading.value = true
  expandedCategories.value = {}
  categoryTransactionsMap.value = {}
  categoryRecurringMap.value = {}
  try {
    const [dashboard, recurring] = await Promise.all([
      dashboardService.getDashboard(startDate.value, endDate.value),
      recurringTransactionService.getAll(true),
    ])
    reportData.value = dashboard
    allRecurringBills.value = recurring
  } catch (error) {
    console.error('Error loading reports:', error)
  } finally {
    loading.value = false
  }
}

function formatCurrency(value) {
  const numValue = typeof value === 'number' ? value : parseFloat(value) || 0
  return numValue.toFixed(2).replace('.', ',')
}

function formatDate(date) {
  const d = new Date(date)
  return d.toLocaleDateString('pt-BR', { day: '2-digit', month: '2-digit', year: 'numeric' })
}

async function loadDayExpenses(dateStr) {
  loadingDayExpenses.value = true
  dayExpenses.value = []
  try {
    const res = await transactionService.getAll({
      start_date: dateStr,
      end_date: dateStr,
      type: 'expense',
      per_page: 500,
    })
    dayExpenses.value = res.data || []
  } catch (e) {
    console.error('Error loading day expenses:', e)
    dayExpenses.value = []
  } finally {
    loadingDayExpenses.value = false
  }
}

function closeDayModal() {
  showDayModal.value = false
  selectedDayDate.value = null
  dayExpenses.value = []
}

async function toggleCategoryTransactions(item) {
  const key = `${item.category_id}-${item.type}`
  
  // Se já está expandido, apenas fecha
  if (expandedCategories.value[key]) {
    expandedCategories.value[key] = false
    return
  }

  // Abre e carrega as transações
  expandedCategories.value[key] = true

  if (categoryTransactionsMap.value[key]) {
    return
  }

  loadingTransactions.value[key] = true

  try {
    const filters = {
      category_id: item.category_id,
      type: item.type,
      start_date: startDate.value,
      end_date: endDate.value,
      per_page: 1000
    }
    
    const response = await transactionService.getAll(filters)
    
    categoryTransactionsMap.value[key] = response.data.sort((a, b) => {
      return new Date(b.date) - new Date(a.date)
    })

    categoryRecurringMap.value[key] = allRecurringBills.value.filter(
      b => b.category_id === item.category_id && b.type === item.type
    )
  } catch (error) {
    console.error('Error loading category transactions:', error)
    categoryTransactionsMap.value[key] = []
    categoryRecurringMap.value[key] = []
  } finally {
    loadingTransactions.value[key] = false
  }
}
</script>

<style scoped>
.dropdown-enter-active {
  transition: opacity 0.3s ease, transform 0.3s ease;
  overflow: hidden;
}

.dropdown-leave-active {
  transition: opacity 0.2s ease, transform 0.2s ease;
  overflow: hidden;
}

.dropdown-enter-from {
  opacity: 0;
  transform: translateY(-10px);
}

.dropdown-enter-to {
  opacity: 1;
  transform: translateY(0);
}

.dropdown-leave-from {
  opacity: 1;
  transform: translateY(0);
}

.dropdown-leave-to {
  opacity: 0;
  transform: translateY(-10px);
}
</style>

