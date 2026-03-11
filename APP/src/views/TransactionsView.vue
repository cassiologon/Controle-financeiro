<template>
  <div>
    <div class="flex justify-between items-center mb-8">
      <div>
        <h1 class="text-3xl font-bold text-gray-900 mb-2">Transações</h1>
        <p class="text-gray-600">Gerencie suas receitas e despesas</p>
      </div>
      <div class="flex gap-3">
        <template v-if="activeTab === 'transactions'">
          <Button variant="secondary" @click="showImportModal = true" class="shadow-lg">
            <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12" />
            </svg>
            Importar Fatura
          </Button>
          <Button variant="primary" @click="showModal = true" class="shadow-lg">
            <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
            </svg>
            Nova Transação
          </Button>
        </template>
        <template v-else>
          <Button variant="primary" @click="$refs.monthlyBillsTab.openCreateModal()" class="shadow-lg">
            <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
            </svg>
            Nova Conta
          </Button>
        </template>
      </div>
    </div>

    <!-- Main Tabs -->
    <div class="flex gap-1 mb-6 bg-gray-100 p-1 rounded-2xl w-fit">
      <button
        :class="[
          'px-6 py-2.5 rounded-xl text-sm font-semibold transition-all duration-200',
          activeTab === 'transactions'
            ? 'bg-white text-primary-700 shadow-sm'
            : 'text-gray-600 hover:text-gray-900',
        ]"
        @click="activeTab = 'transactions'"
      >
        Transações
      </button>
      <button
        :class="[
          'px-6 py-2.5 rounded-xl text-sm font-semibold transition-all duration-200',
          activeTab === 'monthly-bills'
            ? 'bg-white text-primary-700 shadow-sm'
            : 'text-gray-600 hover:text-gray-900',
        ]"
        @click="activeTab = 'monthly-bills'"
      >
        Contas Mensais
      </button>
    </div>

    <!-- Monthly Bills Tab -->
    <MonthlyBillsTab v-if="activeTab === 'monthly-bills'" ref="monthlyBillsTab" />

    <!-- Transactions Tab -->
    <template v-if="activeTab === 'transactions'">

    <!-- Statistics Cards -->
    <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-6">
      <!-- Total Transactions Card -->
      <Card class="relative overflow-hidden">
        <div class="absolute top-0 right-0 w-32 h-32 bg-gradient-to-br from-primary-400/20 to-purple-400/20 rounded-full blur-3xl"></div>
        <div class="relative z-10 flex items-center justify-between">
          <div class="flex-1">
            <p class="text-sm font-semibold text-gray-600 mb-2">Total de Transações</p>
            <p class="text-3xl font-bold text-primary-600 mb-1">
              {{ transactions.total }}
            </p>
            <p class="text-xs text-gray-500">{{ transactions.data.length }} nesta página</p>
          </div>
          <div class="w-16 h-16 rounded-2xl bg-gradient-to-br from-primary-400 to-primary-600 flex items-center justify-center shadow-lg shadow-primary-500/30">
            <span class="text-3xl">📊</span>
          </div>
        </div>
      </Card>

      <!-- Income Card -->
      <Card class="relative overflow-hidden">
        <div class="absolute top-0 right-0 w-32 h-32 bg-gradient-to-br from-success-400/20 to-green-400/20 rounded-full blur-3xl"></div>
        <div class="relative z-10 flex items-center justify-between">
          <div class="flex-1">
            <p class="text-sm font-semibold text-gray-600 mb-2">Receitas</p>
            <p class="text-3xl font-bold text-success-600 mb-1">
              R$ {{ formatCurrency(totalIncome) }}
            </p>
            <p class="text-xs text-gray-500">{{ incomeCount }} transação(ões)</p>
          </div>
          <div class="w-16 h-16 rounded-2xl bg-gradient-to-br from-success-400 to-success-600 flex items-center justify-center shadow-lg shadow-success-500/30">
            <span class="text-3xl">📈</span>
          </div>
        </div>
      </Card>

      <!-- Expense Card -->
      <Card class="relative overflow-hidden">
        <div class="absolute top-0 right-0 w-32 h-32 bg-gradient-to-br from-error-400/20 to-red-400/20 rounded-full blur-3xl"></div>
        <div class="relative z-10 flex items-center justify-between">
          <div class="flex-1">
            <p class="text-sm font-semibold text-gray-600 mb-2">Despesas</p>
            <p class="text-3xl font-bold text-error-600 mb-1">
              R$ {{ formatCurrency(totalExpense) }}
            </p>
            <p class="text-xs text-gray-500">{{ expenseCount }} transação(ões)</p>
          </div>
          <div class="w-16 h-16 rounded-2xl bg-gradient-to-br from-error-400 to-error-600 flex items-center justify-center shadow-lg shadow-error-500/30">
            <span class="text-3xl">📉</span>
          </div>
        </div>
      </Card>
    </div>

    <!-- Filters -->
    <Card class="mb-6">
      <div class="grid grid-cols-1 md:grid-cols-5 gap-4">
        <Select
          v-model="filters.type"
          :options="[
            { value: '', label: 'Todos os tipos' },
            { value: 'income', label: 'Receitas' },
            { value: 'expense', label: 'Despesas' },
          ]"
          placeholder="Tipo"
        />
        <Select
          v-model="filters.category_id"
          :options="categoryOptions"
          placeholder="Categoria"
        />
        <Select
          v-model="filters.bank_name"
          :options="bankFilterOptions"
          placeholder="Banco"
        />
        <Input
          v-model="filters.start_date"
          type="date"
          label="Data Inicial"
        />
        <Input
          v-model="filters.end_date"
          type="date"
          label="Data Final"
        />
      </div>
      <div class="mt-4 flex gap-2">
        <Input
          v-model="filters.search"
          type="text"
          placeholder="Buscar..."
          class="flex-1"
        />
        <Button variant="secondary" @click="clearFilters">Limpar</Button>
        <Button variant="danger" @click="showDeleteAllModal = true">
          <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
          </svg>
          Deletar Todas
        </Button>
      </div>
    </Card>

    <!-- Pending Transactions Section -->
    <Card v-if="pendingTransactions.data.length > 0" class="mb-6 border-2 border-warning-200">
      <div class="flex items-center justify-between mb-4">
        <div class="flex items-center gap-3">
          <div class="w-10 h-10 rounded-xl bg-warning-100 flex items-center justify-center">
            <span class="text-xl">⚠️</span>
          </div>
          <div>
            <h3 class="font-bold text-gray-900">Transações Pendentes</h3>
            <p class="text-sm text-gray-500">{{ pendingTransactions.total }} transação(ões) aguardando categorização</p>
          </div>
        </div>
        <Button variant="secondary" size="sm" @click="showPending = !showPending">
          {{ showPending ? 'Ocultar' : 'Mostrar' }}
        </Button>
      </div>
      <div v-if="showPending" class="space-y-4">
        <PendingTransactionCard
          v-for="(transaction, index) in pendingTransactions.data"
          :key="transaction.id"
          :transaction="transaction"
          :index="index"
          :categories="categories"
          @categorized="handleCategorized"
          @delete="deletePendingTransaction"
        />
      </div>
    </Card>

    <!-- Transactions List -->
    <div v-if="loading" class="flex justify-center py-8">
      <LoadingSpinner />
    </div>

    <div v-else-if="transactions.data.length === 0" class="text-center py-16">
      <div class="w-24 h-24 mx-auto mb-6 rounded-full bg-gradient-to-br from-gray-100 to-gray-200 flex items-center justify-center">
        <span class="text-4xl">📋</span>
      </div>
      <h3 class="text-xl font-bold text-gray-900 mb-2">Nenhuma transação encontrada</h3>
      <p class="text-gray-500 mb-6">Comece adicionando sua primeira transação financeira</p>
      <Button variant="primary" @click="showModal = true">
        + Adicionar Transação
      </Button>
    </div>

    <div v-else class="space-y-4 mb-8">
      <TransactionCard
        v-for="(transaction, index) in transactions.data"
        :key="transaction.id"
        :transaction="transaction"
        :index="index"
        @click="editTransaction(transaction)"
        @delete="deleteTransaction"
      />
    </div>

    <!-- Pagination -->
    <div v-if="transactions.last_page > 1" class="flex items-center justify-center gap-3">
      <Button
        variant="secondary"
        size="sm"
        :disabled="transactions.current_page === 1"
        @click="loadPage(transactions.current_page - 1)"
      >
        <svg class="w-4 h-4 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7" />
        </svg>
        Anterior
      </Button>
      <div class="px-4 py-2 bg-white rounded-xl border border-gray-200 shadow-sm">
        <span class="text-sm font-semibold text-gray-700">
          Página {{ transactions.current_page }} de {{ transactions.last_page }}
        </span>
      </div>
      <Button
        variant="secondary"
        size="sm"
        :disabled="transactions.current_page === transactions.last_page"
        @click="loadPage(transactions.current_page + 1)"
      >
        Próxima
        <svg class="w-4 h-4 ml-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7" />
        </svg>
      </Button>
    </div>

    <!-- Confirm Delete Modal -->
    <ConfirmModal
      :show="showConfirmModal"
      title="Confirmar exclusão"
      :message="deleteTransactionMessage"
      @confirm="handleConfirmDelete"
      @cancel="showConfirmModal = false"
    />

    <!-- Confirm Delete All Modal -->
    <ConfirmModal
      :show="showDeleteAllModal"
      title="Confirmar exclusão de todas as transações"
      message="Tem certeza que deseja deletar TODAS as transações? Esta ação não pode ser desfeita."
      @confirm="handleDeleteAll"
      @cancel="showDeleteAllModal = false"
    />

    <!-- Transaction Modal -->
    <Modal
      :show="showModal"
      :title="editingTransaction ? 'Editar Transação' : 'Nova Transação'"
      :show-footer="true"
      @close="closeModal"
    >
      <template #default>
        <form ref="formRef" @submit.prevent="saveTransaction" id="transaction-form">
          <div class="space-y-4">
            <Select
              v-model="form.category_id"
              :options="categoryOptions.filter(c => c.value !== '')"
              label="Categoria"
              required
              :error="errors.category_id"
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
            <Input
              v-model="form.amount"
              type="number"
              step="0.01"
              label="Valor"
              required
              :error="errors.amount"
            />
            <Input
              v-model="form.description"
              type="text"
              label="Descrição"
              required
              :error="errors.description"
            />
            <Input
              v-model="form.date"
              type="date"
              label="Data"
              required
              :error="errors.date"
            />
            <Select
              v-model="form.bank_name"
              :options="bankFormOptions"
              label="Banco"
              :error="errors.bank_name"
            />
            <Input
              v-if="form.bank_name === 'Outro'"
              v-model="customBankNameForm"
              type="text"
              label="Nome do banco"
              placeholder="Digite o nome do banco"
              :error="errors.bank_name"
            />
          </div>
        </form>
      </template>
      <template #footer>
        <Button variant="secondary" @click="closeModal">Cancelar</Button>
        <Button type="submit" form="transaction-form" variant="primary" :loading="saving">Salvar</Button>
      </template>
    </Modal>

    <!-- Import Invoice Modal -->
    <Modal
      :show="showImportModal"
      title="Importar Fatura"
      :show-footer="true"
      @close="closeImportModal"
    >
      <template #default>
        <div class="space-y-4">
          <div class="border-2 border-dashed border-gray-300 rounded-xl p-8 text-center hover:border-primary-400 transition-colors">
            <input
              ref="fileInput"
              type="file"
              accept=".pdf,.csv"
              @change="handleFileSelect"
              class="hidden"
            />
            <div v-if="!selectedFile" class="space-y-4">
              <div class="w-16 h-16 mx-auto rounded-full bg-primary-100 flex items-center justify-center">
                <svg class="w-8 h-8 text-primary-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12" />
                </svg>
              </div>
              <div>
                <p class="text-gray-700 font-semibold mb-1">Arraste o arquivo aqui ou clique para selecionar</p>
                <p class="text-sm text-gray-500">Formatos aceitos: PDF (Mercado Pago) ou CSV (Nubank)</p>
              </div>
              <Button variant="primary" @click="$refs.fileInput.click()">
                Selecionar Arquivo
              </Button>
            </div>
            <div v-else class="space-y-3">
              <div class="w-16 h-16 mx-auto rounded-full bg-success-100 flex items-center justify-center">
                <svg class="w-8 h-8 text-success-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
                </svg>
              </div>
              <div>
                <p class="text-gray-700 font-semibold">{{ selectedFile.name }}</p>
                <p class="text-sm text-gray-500">{{ formatFileSize(selectedFile.size) }}</p>
              </div>
              <Button variant="secondary" size="sm" @click="selectedFile = null">
                Remover
              </Button>
            </div>
          </div>
          <div v-if="selectedFile" class="space-y-4">
            <Select
              v-model="selectedBank"
              :options="bankOptions"
              label="Banco"
              required
              :error="importBankError"
            />
            <Input
              v-if="selectedBank === 'Outro'"
              v-model="customBankName"
              type="text"
              label="Nome do banco"
              placeholder="Digite o nome do banco"
              required
              :error="importBankError"
            />
          </div>
          <div v-if="importError" class="p-3 rounded-lg bg-error-50 border border-error-200">
            <p class="text-sm text-error-600">{{ importError }}</p>
          </div>
          <div v-if="importSuccess" class="p-3 rounded-lg bg-success-50 border border-success-200">
            <p class="text-sm text-success-600">{{ importSuccess }}</p>
          </div>
        </div>
      </template>
      <template #footer>
        <Button variant="secondary" @click="closeImportModal">Cancelar</Button>
        <Button @click="handleImport" variant="primary" :loading="importing" :disabled="!selectedFile || !selectedBank || (selectedBank === 'Outro' && !customBankName) || importing">
          Importar
        </Button>
      </template>
    </Modal>
    </template>
  </div>
</template>

<script setup>
import { ref, onMounted, computed, watch } from 'vue'
import { transactionService } from '@/services/transactionService'
import { categoryService } from '@/services/categoryService'
import { invoiceImportService } from '@/services/invoiceImportService'
import { useToast } from '@/composables/useToast'
import Card from '@/components/Card.vue'
import Button from '@/components/Button.vue'
import Input from '@/components/Input.vue'
import Select from '@/components/Select.vue'
import Modal from '@/components/Modal.vue'
import TransactionCard from '@/components/TransactionCard.vue'
import PendingTransactionCard from '@/components/PendingTransactionCard.vue'
import LoadingSpinner from '@/components/LoadingSpinner.vue'
import ConfirmModal from '@/components/ConfirmModal.vue'
import MonthlyBillsTab from '@/components/MonthlyBillsTab.vue'

const { success, error: showError } = useToast()

const activeTab = ref('transactions')

const transactions = ref({
  data: [],
  current_page: 1,
  last_page: 1,
  per_page: 15,
  total: 0,
  totals: {
    total_income: 0,
    total_expense: 0,
    income_count: 0,
    expense_count: 0,
  },
})

const categories = ref([])
const banks = ref([])
const loading = ref(false)
const saving = ref(false)
const showModal = ref(false)
const editingTransaction = ref(null)
const showImportModal = ref(false)
const selectedFile = ref(null)
const selectedBank = ref('')
const customBankName = ref('')
const customBankNameForm = ref('')
const importing = ref(false)
const importError = ref(null)
const importSuccess = ref(null)
const importBankError = ref(null)
const showPending = ref(true)
const pendingTransactions = ref({
  data: [],
  current_page: 1,
  last_page: 1,
  per_page: 100,
  total: 0,
})
const showConfirmModal = ref(false)
const transactionToDelete = ref(null)
const isPendingTransaction = ref(false)
const showDeleteAllModal = ref(false)

const filters = ref({
  type: '',
  category_id: '',
  bank_name: '',
  start_date: '',
  end_date: '',
  search: '',
})

const form = ref({
  category_id: 0,
  type: 'expense',
  amount: 0,
  description: '',
  date: new Date().toISOString().split('T')[0],
  bank_name: '',
})

const errors = ref({})

const categoryOptions = computed(() => {
  const options = [{ value: '', label: 'Todas as categorias' }]
  categories.value.forEach(cat => {
    options.push({ value: cat.id, label: cat.name })
  })
  return options
})

const bankOptions = [
  { value: 'Nubank', label: 'Nubank' },
  { value: 'Mercado Pago', label: 'Mercado Pago' },
  { value: 'Itaú', label: 'Itaú' },
  { value: 'Bradesco', label: 'Bradesco' },
  { value: 'Santander', label: 'Santander' },
  { value: 'Outro', label: 'Outro' },
]

const bankFilterOptions = computed(() => {
  const options = [{ value: '', label: 'Todos os bancos' }]
  banks.value.forEach(bank => {
    options.push({ value: bank, label: bank })
  })
  return options
})

const bankFormOptions = computed(() => {
  const options = [{ value: '', label: 'Selecione um banco' }]
  // Adiciona bancos do banco de dados
  banks.value.forEach(bank => {
    options.push({ value: bank, label: bank })
  })
  // Adiciona opções padrão que ainda não estão no banco
  const defaultBanks = ['Nubank', 'Mercado Pago', 'Itaú', 'Bradesco', 'Santander', 'Outro']
  defaultBanks.forEach(bank => {
    if (!banks.value.includes(bank)) {
      options.push({ value: bank, label: bank })
    }
  })
  return options
})

const deleteTransactionMessage = computed(() => {
  if (!transactionToDelete.value) return ''
  return `Tem certeza que deseja excluir a transação "${transactionToDelete.value.description}"? Esta ação não pode ser desfeita.`
})

// Usa totais retornados pelo backend (todas as transações que correspondem aos filtros)
const totalIncome = computed(() => {
  return transactions.value.totals?.total_income || 0
})

const totalExpense = computed(() => {
  return transactions.value.totals?.total_expense || 0
})

const incomeCount = computed(() => {
  return transactions.value.totals?.income_count || 0
})

const expenseCount = computed(() => {
  return transactions.value.totals?.expense_count || 0
})

function formatCurrency(value) {
  const numValue = typeof value === 'number' ? value : parseFloat(value) || 0
  return numValue.toFixed(2).replace('.', ',')
}

onMounted(async () => {
  await loadCategories()
  await loadBanks()
  await loadTransactions()
  await loadPendingTransactions()
})

// Recarrega pendentes quando necessário
watch([showPending, showImportModal], async ([showPendingVal, showImportModalVal]) => {
  if (showPendingVal || showImportModalVal) {
    await loadPendingTransactions()
  }
})

// Watcher para filtros com debounce no campo de pesquisa
let searchTimeout = null
watch(() => filters.value.search, (newValue) => {
  if (searchTimeout) {
    clearTimeout(searchTimeout)
  }
  searchTimeout = setTimeout(() => {
    loadTransactions(1)
  }, 500) // Debounce de 500ms
})

// Watchers para outros filtros
watch(() => filters.value.type, () => {
  loadTransactions(1)
})

watch(() => filters.value.category_id, () => {
  loadTransactions(1)
})

watch(() => filters.value.start_date, () => {
  loadTransactions(1)
})

watch(() => filters.value.end_date, () => {
  loadTransactions(1)
})

watch(() => filters.value.bank_name, () => {
  loadTransactions(1)
})

async function loadCategories() {
  try {
    categories.value = await categoryService.getAll()
  } catch (error) {
    console.error('Error loading categories:', error)
  }
}

async function loadBanks() {
  try {
    banks.value = await transactionService.getBanks()
    console.log('Banks loaded:', banks.value)
  } catch (error) {
    console.error('Error loading banks:', error)
  }
}

async function loadTransactions(page = 1) {
  loading.value = true
  try {
    const params = {
      per_page: 15,
      page,
    }
    
    if (filters.value.type) params.type = filters.value.type
    if (filters.value.category_id) params.category_id = filters.value.category_id
    if (filters.value.bank_name) params.bank_name = filters.value.bank_name
    if (filters.value.start_date) params.start_date = filters.value.start_date
    if (filters.value.end_date) params.end_date = filters.value.end_date
    if (filters.value.search) params.search = filters.value.search

    const response = await transactionService.getAll(params)
    transactions.value = {
      ...response,
      totals: response.totals || {
        total_income: 0,
        total_expense: 0,
        income_count: 0,
        expense_count: 0,
      },
    }
  } catch (error) {
    console.error('Error loading transactions:', error)
  } finally {
    loading.value = false
  }
}

function loadPage(page) {
  loadTransactions(page)
}

function clearFilters() {
  filters.value = {
    type: '',
    category_id: '',
    bank_name: '',
    start_date: '',
    end_date: '',
    search: '',
  }
  loadTransactions(1)
}

function editTransaction(transaction) {
  editingTransaction.value = transaction
  // Formatar a data para o formato YYYY-MM-DD esperado pelo input type="date"
  let formattedDate = transaction.date
  if (transaction.date) {
    // Se a data vier com hora (ex: "2024-01-15 00:00:00" ou "2024-01-15T00:00:00.000000Z")
    // extrair apenas a parte da data
    formattedDate = transaction.date.split('T')[0].split(' ')[0]
  }
  form.value = {
    category_id: transaction.category_id,
    type: transaction.type,
    amount: transaction.amount,
    description: transaction.description,
    date: formattedDate,
    bank_name: transaction.bank_name || '',
  }
  // Se o banco não está nas opções padrão, define como "Outro" e preenche o campo customizado
  if (transaction.bank_name && !bankFormOptions.value.find(opt => opt.value === transaction.bank_name)) {
    form.value.bank_name = 'Outro'
    customBankNameForm.value = transaction.bank_name
  } else {
    customBankNameForm.value = ''
  }
  showModal.value = true
}

function closeModal() {
  showModal.value = false
  editingTransaction.value = null
  form.value = {
    category_id: 0,
    type: 'expense',
    amount: 0,
    description: '',
    date: new Date().toISOString().split('T')[0],
    bank_name: '',
  }
  customBankNameForm.value = ''
  errors.value = {}
}

async function saveTransaction() {
  errors.value = {}
  saving.value = true

  try {
    // Se "Outro" foi selecionado, usa o nome customizado
    const transactionData = { ...form.value }
    if (transactionData.bank_name === 'Outro' && customBankNameForm.value) {
      transactionData.bank_name = customBankNameForm.value
    } else if (transactionData.bank_name === 'Outro' && !customBankNameForm.value) {
      errors.value.bank_name = 'Digite o nome do banco'
      saving.value = false
      return
    }
    
    if (editingTransaction.value) {
      await transactionService.update(editingTransaction.value.id, transactionData)
    } else {
      await transactionService.create(transactionData)
    }
    closeModal()
    await loadBanks()
    await loadTransactions(transactions.value.current_page)
  } catch (error) {
    if (error.response?.data?.errors) {
      errors.value = error.response.data.errors
    }
  } finally {
    saving.value = false
  }
}

function handleFileSelect(event) {
  const file = event.target.files[0]
  if (file) {
    const extension = file.name.split('.').pop().toLowerCase()
    if (['pdf', 'csv'].includes(extension)) {
      selectedFile.value = file
      importError.value = null
    } else {
      importError.value = 'Formato de arquivo não suportado. Use PDF ou CSV.'
      selectedFile.value = null
    }
  }
}

async function handleImport() {
  if (!selectedFile.value) return
  
  // Validação do banco
  if (!selectedBank.value) {
    importBankError.value = 'Selecione um banco'
    return
  }
  
  if (selectedBank.value === 'Outro' && !customBankName.value) {
    importBankError.value = 'Digite o nome do banco'
    return
  }

  importing.value = true
  importError.value = null
  importSuccess.value = null
  importBankError.value = null

  try {
    const bankName = selectedBank.value === 'Outro' ? customBankName.value : selectedBank.value
    await invoiceImportService.uploadFile(selectedFile.value, bankName)
    importSuccess.value = 'Arquivo enviado para processamento. As transações serão importadas em breve.'
    selectedFile.value = null
    
    // Recarrega transações e pendentes após um delay
    setTimeout(async () => {
      await loadBanks()
      await loadTransactions(transactions.value.current_page)
      await loadPendingTransactions()
    }, 2000)
    
    // Fecha modal após 3 segundos
    setTimeout(() => {
      closeImportModal()
    }, 3000)
  } catch (error) {
    importError.value = error.response?.data?.message || 'Erro ao importar arquivo. Tente novamente.'
    console.error('Error importing file:', error)
  } finally {
    importing.value = false
  }
}

function closeImportModal() {
  showImportModal.value = false
  selectedFile.value = null
  selectedBank.value = ''
  customBankName.value = ''
  importError.value = null
  importSuccess.value = null
  importBankError.value = null
}

function formatFileSize(bytes) {
  if (bytes === 0) return '0 Bytes'
  const k = 1024
  const sizes = ['Bytes', 'KB', 'MB']
  const i = Math.floor(Math.log(bytes) / Math.log(k))
  return Math.round(bytes / Math.pow(k, i) * 100) / 100 + ' ' + sizes[i]
}

async function loadPendingTransactions() {
  try {
    const response = await invoiceImportService.getPending({ per_page: 100 })
    pendingTransactions.value = response
  } catch (error) {
    console.error('Error loading pending transactions:', error)
  }
}

function handleCategorized(transactionIds) {
  // Garante que transactionIds seja um array
  const idsToRemove = Array.isArray(transactionIds) ? transactionIds : [transactionIds]
  
  // Remove todas as transações categorizadas da lista de pendentes
  const removedCount = idsToRemove.length
  pendingTransactions.value.data = pendingTransactions.value.data.filter(
    t => !idsToRemove.includes(t.id)
  )
  pendingTransactions.value.total -= removedCount
  
  // Recarrega transações normais
  loadTransactions(transactions.value.current_page)
}

function deleteTransaction(transaction) {
  transactionToDelete.value = transaction
  isPendingTransaction.value = false
  showConfirmModal.value = true
}

function deletePendingTransaction(transaction) {
  transactionToDelete.value = transaction
  isPendingTransaction.value = true
  showConfirmModal.value = true
}

async function handleConfirmDelete() {
  if (!transactionToDelete.value) return

  showConfirmModal.value = false

  try {
    await transactionService.delete(transactionToDelete.value.id)
    
    if (isPendingTransaction.value) {
      // Remove da lista de pendentes
      pendingTransactions.value.data = pendingTransactions.value.data.filter(
        t => t.id !== transactionToDelete.value.id
      )
      pendingTransactions.value.total--
      success('Transação pendente excluída com sucesso!')
    } else {
      // Remove da lista local se estiver visível
      transactions.value.data = transactions.value.data.filter(
        t => t.id !== transactionToDelete.value.id
      )
      transactions.value.total--
      
      // Se a página ficou vazia e não é a primeira, volta uma página
      if (transactions.value.data.length === 0 && transactions.value.current_page > 1) {
        await loadTransactions(transactions.value.current_page - 1)
      } else {
        // Recarrega para garantir sincronização
        await loadTransactions(transactions.value.current_page)
      }
      
      success('Transação excluída com sucesso!')
    }
  } catch (err) {
    console.error('Error deleting transaction:', err)
    showError('Erro ao excluir transação. Tente novamente.')
  } finally {
    transactionToDelete.value = null
    isPendingTransaction.value = false
  }
}

async function handleDeleteAll() {
  showDeleteAllModal.value = false

  try {
    const response = await transactionService.deleteAll()
    
    // Limpa todas as transações
    transactions.value = {
      data: [],
      current_page: 1,
      last_page: 1,
      per_page: 15,
      total: 0,
      totals: {
        total_income: 0,
        total_expense: 0,
        income_count: 0,
        expense_count: 0,
      },
    }
    
    // Limpa pendentes também
    pendingTransactions.value = {
      data: [],
      current_page: 1,
      last_page: 1,
      per_page: 100,
      total: 0,
    }
    
    // Recarrega bancos (vai ficar vazio)
    await loadBanks()
    
    success(`Todas as transações foram deletadas com sucesso! (${response.deleted_count || 0} transações)`)
  } catch (error) {
    showError('Erro ao deletar todas as transações. Tente novamente.')
    console.error('Error deleting all transactions:', error)
  }
}
</script>

