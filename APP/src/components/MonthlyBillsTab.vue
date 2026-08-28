<template>
  <div>
    <div v-if="loading" class="flex justify-center py-12">
      <LoadingSpinner />
    </div>

    <div v-else>
      <!-- Balance Banner -->
      <div class="relative mb-8 rounded-2xl overflow-hidden bg-white/80 backdrop-blur-lg border border-gray-200/50 shadow-soft p-6">
        <div class="absolute inset-0 opacity-30">
          <div class="absolute top-0 left-1/4 w-64 h-64 bg-primary-200 rounded-full blur-3xl"></div>
          <div class="absolute bottom-0 right-1/4 w-64 h-64 bg-purple-200 rounded-full blur-3xl"></div>
        </div>
        <div class="relative z-10 flex flex-col md:flex-row items-center justify-between gap-4">
          <div class="flex items-center gap-4">
            <div class="w-14 h-14 rounded-2xl bg-gradient-to-br from-primary-100 to-purple-100 flex items-center justify-center">
              <span class="text-3xl">⚖️</span>
            </div>
            <div>
              <p class="text-sm font-medium text-gray-500">Saldo Mensal Recorrente</p>
              <p :class="['text-3xl font-bold', balance >= 0 ? 'text-success-600' : 'text-error-600']">
                R$ {{ formatCurrency(balance) }}
              </p>
            </div>
          </div>
          <div class="flex items-center gap-6">
            <div class="text-center">
              <p class="text-xs font-medium text-gray-500 uppercase tracking-wider">Receitas</p>
              <p class="text-lg font-bold text-success-600">R$ {{ formatCurrency(totalIncome) }}</p>
            </div>
            <div class="w-px h-10 bg-gray-200"></div>
            <div class="text-center">
              <p class="text-xs font-medium text-gray-500 uppercase tracking-wider">Despesas</p>
              <p class="text-lg font-bold text-error-600">R$ {{ formatCurrency(totalExpenses) }}</p>
              <p v-if="invoicesTotalCredits > 0" class="text-[11px] text-gray-400">
                líquido de R$ {{ formatCurrency(invoicesTotalCredits) }}
              </p>
            </div>
            <div class="w-px h-10 bg-gray-200"></div>
            <div class="flex gap-1 items-center">
              <button
                v-for="tab in statusTabs"
                :key="tab.value"
                :class="[
                  'px-3 py-1.5 rounded-lg text-xs font-semibold transition-all duration-200',
                  filterStatus === tab.value
                    ? 'bg-primary-100 text-primary-700'
                    : 'text-gray-500 hover:text-gray-700 hover:bg-gray-100',
                ]"
                @click="filterStatus = tab.value"
              >
                {{ tab.label }}
              </button>
            </div>
          </div>
        </div>
      </div>

      <!-- Two Column Layout -->
      <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">

        <!-- Despesas Column -->
        <div class="rounded-2xl border border-red-200/60 bg-gradient-to-b from-red-50/50 to-white overflow-hidden">
          <!-- Column Header -->
          <div class="px-5 py-4 border-b border-red-100 bg-white/60 backdrop-blur">
            <div class="flex items-center justify-between">
              <div class="flex items-center gap-3">
                <div class="w-10 h-10 rounded-xl bg-gradient-to-br from-red-500 to-rose-600 flex items-center justify-center shadow-lg shadow-red-500/25">
                  <svg class="w-5 h-5 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 14l-7 7m0 0l-7-7m7 7V3" />
                  </svg>
                </div>
                <div>
                  <h3 class="font-bold text-gray-900">Despesas</h3>
                  <p class="text-xs text-gray-500">{{ expenseBills.length + invoiceSummaries.length }} item(ns)</p>
                </div>
              </div>
              <div class="flex items-center gap-3">
                <button
                  class="p-2 rounded-lg text-gray-400 hover:text-purple-600 hover:bg-purple-50 transition-all duration-200"
                  title="Adicionar fatura"
                  @click="openInvoiceModal()"
                >
                  <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 003-3V8a3 3 0 00-3-3H6a3 3 0 00-3 3v8a3 3 0 003 3z" />
                  </svg>
                </button>
                <p class="text-xl font-bold text-red-600">R$ {{ formatCurrency(totalExpenses) }}</p>
              </div>
            </div>
          </div>

          <!-- Expense Items -->
          <div class="p-3">
            <div v-if="expenseBills.length === 0 && invoiceSummaries.length === 0" class="text-center py-10">
              <div class="w-12 h-12 mx-auto mb-3 rounded-full bg-red-100 flex items-center justify-center">
                <span class="text-xl">📭</span>
              </div>
              <p class="text-sm text-gray-400">Nenhuma despesa cadastrada</p>
            </div>
            <div v-else class="space-y-1.5">
              <!-- Invoice items (faturas) -->
              <div
                v-for="invoice in invoiceSummaries"
                :key="'inv-' + invoice.id"
                class="group flex items-center gap-3 px-3 py-3 rounded-xl cursor-pointer transition-all duration-200 hover:bg-white hover:shadow-md border border-dashed border-red-200/60"
                @click="editInvoiceConfig(invoice)"
              >
                <div class="w-9 h-9 rounded-lg flex items-center justify-center flex-shrink-0 bg-gradient-to-br from-purple-500 to-indigo-600 transition-transform duration-200 group-hover:scale-110">
                  <svg class="w-5 h-5 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 003-3V8a3 3 0 00-3-3H6a3 3 0 00-3 3v8a3 3 0 003 3z" />
                  </svg>
                </div>
                <div class="flex-1 min-w-0">
                  <p class="text-sm font-semibold text-gray-900 truncate">Fatura {{ invoice.bank_name }}</p>
                  <p class="text-xs text-gray-400">{{ formatPeriod(invoice.period_start, invoice.period_end) }}</p>
                  <p v-if="invoice.total_credits > 0" class="text-[11px] text-gray-400 mt-0.5">
                    <span class="text-gray-500">R$ {{ formatCurrency(invoice.total_charges) }}</span>
                    em gastos
                    <span class="text-emerald-600 font-medium">&minus; R$ {{ formatCurrency(invoice.total_credits) }}</span>
                    em abatimentos
                  </p>
                </div>
                <div class="text-right whitespace-nowrap">
                  <p class="text-sm font-bold text-red-600">
                    R$ {{ formatCurrency(invoice.total) }}
                  </p>
                  <p v-if="invoice.total_credits > 0" class="text-[10px] uppercase tracking-wider text-gray-400">
                    a pagar
                  </p>
                </div>
                <div class="flex items-center gap-1 opacity-0 group-hover:opacity-100 transition-opacity duration-200">
                  <button
                    @click.stop="confirmDeleteInvoice(invoice)"
                    class="p-1 text-gray-300 hover:text-red-500 rounded transition-colors"
                  >
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                    </svg>
                  </button>
                </div>
              </div>

              <!-- Separator if there are both invoices and bills -->
              <div v-if="invoiceSummaries.length > 0 && expenseBills.length > 0" class="border-t border-red-100 my-2"></div>

              <!-- Recurring expense items -->
              <div
                v-for="bill in expenseBills"
                :key="bill.id"
                class="group flex items-center gap-3 px-3 py-3 rounded-xl cursor-pointer transition-all duration-200 hover:bg-white hover:shadow-md"
                @click="editBill(bill)"
              >
                <div
                  class="w-9 h-9 rounded-lg flex items-center justify-center flex-shrink-0 transition-transform duration-200 group-hover:scale-110"
                  :style="{
                    backgroundColor: (bill.category?.color || '#ef4444') + '18',
                  }"
                >
                  <span v-if="bill.category?.icon" class="text-lg">{{ bill.category.icon }}</span>
                  <span v-else class="text-sm">💸</span>
                </div>
                <div class="flex-1 min-w-0">
                  <p :class="['text-sm font-semibold truncate', !bill.is_active ? 'text-gray-400 line-through' : 'text-gray-900']">
                    {{ bill.description }}
                  </p>
                  <p class="text-xs text-gray-400">{{ bill.category?.name || 'Sem categoria' }}</p>
                </div>
                <p :class="['text-sm font-bold whitespace-nowrap', !bill.is_active ? 'text-gray-400' : 'text-red-600']">
                  R$ {{ formatCurrency(Number(bill.amount)) }}
                </p>
                <div class="flex items-center gap-1 opacity-0 group-hover:opacity-100 transition-opacity duration-200">
                  <button
                    @click.stop="toggleActive(bill)"
                    :class="[
                      'relative inline-flex h-5 w-9 items-center rounded-full transition-colors duration-200',
                      bill.is_active ? 'bg-success-500' : 'bg-gray-300',
                    ]"
                    :title="bill.is_active ? 'Desativar' : 'Ativar'"
                  >
                    <span
                      :class="[
                        'inline-block h-3.5 w-3.5 transform rounded-full bg-white transition-transform duration-200 shadow-sm',
                        bill.is_active ? 'translate-x-4' : 'translate-x-0.5',
                      ]"
                    />
                  </button>
                  <button
                    @click.stop="confirmDelete(bill)"
                    class="p-1 text-gray-300 hover:text-red-500 rounded transition-colors"
                  >
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                    </svg>
                  </button>
                </div>
              </div>
            </div>
          </div>
        </div>

        <!-- Receitas Column -->
        <div class="rounded-2xl border border-emerald-200/60 bg-gradient-to-b from-emerald-50/50 to-white overflow-hidden">
          <!-- Column Header -->
          <div class="px-5 py-4 border-b border-emerald-100 bg-white/60 backdrop-blur">
            <div class="flex items-center justify-between">
              <div class="flex items-center gap-3">
                <div class="w-10 h-10 rounded-xl bg-gradient-to-br from-emerald-500 to-teal-600 flex items-center justify-center shadow-lg shadow-emerald-500/25">
                  <svg class="w-5 h-5 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 10l7-7m0 0l7 7m-7-7v18" />
                  </svg>
                </div>
                <div>
                  <h3 class="font-bold text-gray-900">Receitas</h3>
                  <p class="text-xs text-gray-500">{{ incomeBills.length }} conta(s)</p>
                </div>
              </div>
              <p class="text-xl font-bold text-emerald-600">R$ {{ formatCurrency(totalIncome) }}</p>
            </div>
          </div>

          <!-- Income Items -->
          <div class="p-3">
            <div v-if="incomeBills.length === 0" class="text-center py-10">
              <div class="w-12 h-12 mx-auto mb-3 rounded-full bg-emerald-100 flex items-center justify-center">
                <span class="text-xl">📭</span>
              </div>
              <p class="text-sm text-gray-400">Nenhuma receita cadastrada</p>
            </div>
            <div v-else class="space-y-1.5">
              <div
                v-for="bill in incomeBills"
                :key="bill.id"
                class="group flex items-center gap-3 px-3 py-3 rounded-xl cursor-pointer transition-all duration-200 hover:bg-white hover:shadow-md"
                @click="editBill(bill)"
              >
                <div
                  class="w-9 h-9 rounded-lg flex items-center justify-center flex-shrink-0 transition-transform duration-200 group-hover:scale-110"
                  :style="{
                    backgroundColor: (bill.category?.color || '#10b981') + '18',
                  }"
                >
                  <span v-if="bill.category?.icon" class="text-lg">{{ bill.category.icon }}</span>
                  <span v-else class="text-sm">💰</span>
                </div>
                <div class="flex-1 min-w-0">
                  <p :class="['text-sm font-semibold truncate', !bill.is_active ? 'text-gray-400 line-through' : 'text-gray-900']">
                    {{ bill.description }}
                  </p>
                  <p class="text-xs text-gray-400">{{ bill.category?.name || 'Sem categoria' }}</p>
                </div>
                <p :class="['text-sm font-bold whitespace-nowrap', !bill.is_active ? 'text-gray-400' : 'text-emerald-600']">
                  R$ {{ formatCurrency(Number(bill.amount)) }}
                </p>
                <div class="flex items-center gap-1 opacity-0 group-hover:opacity-100 transition-opacity duration-200">
                  <button
                    @click.stop="toggleActive(bill)"
                    :class="[
                      'relative inline-flex h-5 w-9 items-center rounded-full transition-colors duration-200',
                      bill.is_active ? 'bg-success-500' : 'bg-gray-300',
                    ]"
                    :title="bill.is_active ? 'Desativar' : 'Ativar'"
                  >
                    <span
                      :class="[
                        'inline-block h-3.5 w-3.5 transform rounded-full bg-white transition-transform duration-200 shadow-sm',
                        bill.is_active ? 'translate-x-4' : 'translate-x-0.5',
                      ]"
                    />
                  </button>
                  <button
                    @click.stop="confirmDelete(bill)"
                    class="p-1 text-gray-300 hover:text-red-500 rounded transition-colors"
                  >
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                    </svg>
                  </button>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- Confirm Delete Bill Modal -->
    <ConfirmModal
      :show="showConfirmModal"
      title="Confirmar exclusão"
      :message="deleteMessage"
      @confirm="handleConfirmDelete"
      @cancel="showConfirmModal = false"
    />

    <!-- Confirm Delete Invoice Config Modal -->
    <ConfirmModal
      :show="showConfirmInvoiceDelete"
      title="Remover fatura"
      :message="deleteInvoiceMessage"
      @confirm="handleConfirmDeleteInvoice"
      @cancel="showConfirmInvoiceDelete = false"
    />

    <!-- Create/Edit Bill Modal -->
    <Modal
      :show="showModal"
      :title="editingBill ? 'Editar Conta' : 'Nova Conta'"
      :show-footer="true"
      @close="closeModal"
    >
      <template #default>
        <form ref="formRef" @submit.prevent="saveBill" id="bill-form">
          <div class="space-y-4">
            <Input
              v-model="form.description"
              label="Descrição"
              placeholder="Ex: Escola, Luz, Internet..."
              required
              :error="errors.description?.[0]"
            />
            <Input
              v-model="form.amount"
              type="number"
              step="0.01"
              label="Valor"
              required
              :error="errors.amount?.[0]"
            />
            <Select
              v-model="form.type"
              :options="typeOptions"
              label="Tipo"
              required
              :error="errors.type?.[0]"
            />
            <Select
              v-model="form.category_id"
              :options="filteredCategoryOptions"
              label="Categoria"
              required
              :error="errors.category_id?.[0]"
            />
            <Select
              v-model="form.frequency"
              :options="frequencyOptions"
              label="Frequência"
              required
              :error="errors.frequency?.[0]"
            />
            <Input
              v-model="form.start_date"
              type="date"
              label="Data Início"
              required
              :error="errors.start_date?.[0]"
            />
            <Input
              v-model="form.end_date"
              type="date"
              label="Data Fim (opcional)"
              :error="errors.end_date?.[0]"
            />
          </div>
        </form>
      </template>
      <template #footer>
        <Button variant="secondary" @click="closeModal">Cancelar</Button>
        <Button type="submit" form="bill-form" variant="primary" :loading="saving">Salvar</Button>
      </template>
    </Modal>

    <!-- Invoice Config Modal -->
    <Modal
      :show="showInvoiceModal"
      :title="editingInvoice ? 'Editar Fatura' : 'Nova Fatura'"
      :show-footer="true"
      @close="closeInvoiceModal"
    >
      <template #default>
        <form @submit.prevent="saveInvoiceConfig" id="invoice-form">
          <div class="space-y-4">
            <Select
              v-model="invoiceForm.bank_name"
              :options="bankOptions"
              label="Banco"
              required
              :error="invoiceErrors.bank_name?.[0]"
            />
            <Input
              v-if="invoiceForm.bank_name === 'Outro'"
              v-model="invoiceForm.custom_bank_name"
              label="Nome do banco"
              placeholder="Digite o nome do banco"
              required
            />
            <Input
              v-model="invoiceForm.closing_day"
              type="number"
              label="Dia de fechamento"
              placeholder="Ex: 1, 10, 15..."
              required
              :error="invoiceErrors.closing_day?.[0]"
            />
          </div>
        </form>
      </template>
      <template #footer>
        <Button variant="secondary" @click="closeInvoiceModal">Cancelar</Button>
        <Button type="submit" form="invoice-form" variant="primary" :loading="savingInvoice">Salvar</Button>
      </template>
    </Modal>
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue'
import { recurringTransactionService } from '@/services/recurringTransactionService'
import { categoryService } from '@/services/categoryService'
import { invoiceConfigService } from '@/services/invoiceConfigService'
import Button from '@/components/Button.vue'
import Input from '@/components/Input.vue'
import Select from '@/components/Select.vue'
import Modal from '@/components/Modal.vue'
import ConfirmModal from '@/components/ConfirmModal.vue'
import LoadingSpinner from '@/components/LoadingSpinner.vue'
import { useToast } from '@/composables/useToast'

const { success, error: showError } = useToast()

const bills = ref([])
const categories = ref([])
const invoiceSummaries = ref([])
const loading = ref(false)
const saving = ref(false)
const savingInvoice = ref(false)
const showModal = ref(false)
const showInvoiceModal = ref(false)
const showConfirmModal = ref(false)
const showConfirmInvoiceDelete = ref(false)
const editingBill = ref(null)
const editingInvoice = ref(null)
const billToDelete = ref(null)
const invoiceToDelete = ref(null)
const filterStatus = ref('active')

const statusTabs = [
  { label: 'Ativas', value: 'active' },
  { label: 'Inativas', value: 'inactive' },
  { label: 'Todas', value: 'all' },
]

const typeOptions = [
  { value: 'expense', label: 'Despesa' },
  { value: 'income', label: 'Receita' },
]

const frequencyOptions = [
  { value: 'monthly', label: 'Mensal' },
  { value: 'weekly', label: 'Semanal' },
  { value: 'yearly', label: 'Anual' },
]

const bankOptions = [
  { value: 'Nubank', label: 'Nubank' },
  { value: 'Mercado Pago', label: 'Mercado Pago' },
  { value: 'Itaú', label: 'Itaú' },
  { value: 'Bradesco', label: 'Bradesco' },
  { value: 'Santander', label: 'Santander' },
  { value: 'Outro', label: 'Outro' },
]

const form = ref({
  description: '',
  amount: 0,
  type: 'expense',
  category_id: '',
  frequency: 'monthly',
  start_date: new Date().toISOString().split('T')[0],
  end_date: '',
})

const invoiceForm = ref({
  bank_name: 'Nubank',
  custom_bank_name: '',
  closing_day: 1,
})

const errors = ref({})
const invoiceErrors = ref({})

const filteredCategoryOptions = computed(() => {
  const filtered = categories.value.filter(c => {
    if (!form.value.type) return true
    return c.type === form.value.type
  })
  return [
    { value: '', label: 'Selecione uma categoria' },
    ...filtered.map(c => ({ value: c.id, label: `${c.icon || ''} ${c.name}`.trim() })),
  ]
})

const statusFilteredBills = computed(() => {
  return bills.value.filter(bill => {
    if (filterStatus.value === 'active' && !bill.is_active) return false
    if (filterStatus.value === 'inactive' && bill.is_active) return false
    return true
  })
})

const expenseBills = computed(() => statusFilteredBills.value.filter(b => b.type === 'expense'))
const incomeBills = computed(() => statusFilteredBills.value.filter(b => b.type === 'income'))

const activeBills = computed(() => bills.value.filter(b => b.is_active))

// Gastos brutos lançados nas faturas do período.
const invoicesTotalCharges = computed(() =>
  invoiceSummaries.value.reduce((sum, inv) => sum + Number(inv.total_charges ?? inv.total), 0)
)

// Pagamentos antecipados, estornos e descontos creditados dentro da fatura.
const invoicesTotalCredits = computed(() =>
  invoiceSummaries.value.reduce((sum, inv) => sum + Number(inv.total_credits ?? 0), 0)
)

const invoicesTotalExpense = computed(() =>
  invoicesTotalCharges.value - invoicesTotalCredits.value
)

const totalExpenses = computed(() =>
  activeBills.value
    .filter(b => b.type === 'expense')
    .reduce((sum, b) => sum + Number(b.amount), 0)
  + invoicesTotalExpense.value
)

const totalIncome = computed(() =>
  activeBills.value
    .filter(b => b.type === 'income')
    .reduce((sum, b) => sum + Number(b.amount), 0)
)

const balance = computed(() => totalIncome.value - totalExpenses.value)

const deleteMessage = computed(() => {
  if (!billToDelete.value) return ''
  return `Tem certeza que deseja excluir "${billToDelete.value.description}"? Esta ação não pode ser desfeita.`
})

const deleteInvoiceMessage = computed(() => {
  if (!invoiceToDelete.value) return ''
  return `Tem certeza que deseja remover a fatura "${invoiceToDelete.value.bank_name}"? A configuração será excluída, mas as transações não serão afetadas.`
})

onMounted(async () => {
  await Promise.all([loadBills(), loadCategories(), loadInvoiceSummaries()])
})

async function loadBills() {
  loading.value = true
  try {
    bills.value = await recurringTransactionService.getAll()
  } catch (err) {
    showError('Erro ao carregar contas mensais')
  } finally {
    loading.value = false
  }
}

async function loadCategories() {
  try {
    categories.value = await categoryService.getAll()
  } catch (err) {
    showError('Erro ao carregar categorias')
  }
}

async function loadInvoiceSummaries() {
  try {
    invoiceSummaries.value = await invoiceConfigService.getSummary()
  } catch (err) {
    showError('Erro ao carregar faturas')
  }
}

function openCreateModal() {
  editingBill.value = null
  form.value = {
    description: '',
    amount: 0,
    type: 'expense',
    category_id: '',
    frequency: 'monthly',
    start_date: new Date().toISOString().split('T')[0],
    end_date: '',
  }
  errors.value = {}
  showModal.value = true
}

function editBill(bill) {
  editingBill.value = bill
  form.value = {
    description: bill.description,
    amount: Number(bill.amount),
    type: bill.type,
    category_id: bill.category_id,
    frequency: bill.frequency,
    start_date: bill.start_date?.split('T')[0] || '',
    end_date: bill.end_date?.split('T')[0] || '',
  }
  errors.value = {}
  showModal.value = true
}

function closeModal() {
  showModal.value = false
  editingBill.value = null
  errors.value = {}
}

async function saveBill() {
  errors.value = {}
  saving.value = true

  const payload = { ...form.value }
  if (!payload.end_date) delete payload.end_date
  payload.is_active = true

  try {
    if (editingBill.value) {
      await recurringTransactionService.update(editingBill.value.id, payload)
      success('Conta atualizada com sucesso!')
    } else {
      await recurringTransactionService.create(payload)
      success('Conta criada com sucesso!')
    }
    closeModal()
    await loadBills()
  } catch (err) {
    if (err.response?.data?.errors) {
      errors.value = err.response.data.errors
    } else {
      showError('Erro ao salvar conta')
    }
  } finally {
    saving.value = false
  }
}

async function toggleActive(bill) {
  try {
    await recurringTransactionService.update(bill.id, { is_active: !bill.is_active })
    bill.is_active = !bill.is_active
    success(bill.is_active ? 'Conta ativada' : 'Conta desativada')
  } catch (err) {
    showError('Erro ao atualizar status')
  }
}

function confirmDelete(bill) {
  billToDelete.value = bill
  showConfirmModal.value = true
}

async function handleConfirmDelete() {
  if (!billToDelete.value) return
  showConfirmModal.value = false

  try {
    await recurringTransactionService.delete(billToDelete.value.id)
    await loadBills()
    success('Conta excluída com sucesso!')
  } catch (err) {
    showError('Erro ao excluir conta')
  } finally {
    billToDelete.value = null
  }
}

// Invoice config functions
function openInvoiceModal() {
  editingInvoice.value = null
  invoiceForm.value = { bank_name: 'Nubank', custom_bank_name: '', closing_day: 1 }
  invoiceErrors.value = {}
  showInvoiceModal.value = true
}

function editInvoiceConfig(invoice) {
  editingInvoice.value = invoice
  const isKnownBank = bankOptions.some(b => b.value === invoice.bank_name && b.value !== 'Outro')
  invoiceForm.value = {
    bank_name: isKnownBank ? invoice.bank_name : 'Outro',
    custom_bank_name: isKnownBank ? '' : invoice.bank_name,
    closing_day: invoice.closing_day,
  }
  invoiceErrors.value = {}
  showInvoiceModal.value = true
}

function closeInvoiceModal() {
  showInvoiceModal.value = false
  editingInvoice.value = null
  invoiceErrors.value = {}
}

async function saveInvoiceConfig() {
  invoiceErrors.value = {}
  savingInvoice.value = true

  const bankName = invoiceForm.value.bank_name === 'Outro'
    ? invoiceForm.value.custom_bank_name
    : invoiceForm.value.bank_name

  if (!bankName) {
    invoiceErrors.value = { bank_name: ['Informe o nome do banco'] }
    savingInvoice.value = false
    return
  }

  const payload = {
    bank_name: bankName,
    closing_day: Number(invoiceForm.value.closing_day),
  }

  try {
    if (editingInvoice.value) {
      await invoiceConfigService.update(editingInvoice.value.id, payload)
      success('Fatura atualizada com sucesso!')
    } else {
      await invoiceConfigService.create(payload)
      success('Fatura adicionada com sucesso!')
    }
    closeInvoiceModal()
    await loadInvoiceSummaries()
  } catch (err) {
    if (err.response?.data?.errors) {
      invoiceErrors.value = err.response.data.errors
    } else {
      showError('Erro ao salvar fatura')
    }
  } finally {
    savingInvoice.value = false
  }
}

function confirmDeleteInvoice(invoice) {
  invoiceToDelete.value = invoice
  showConfirmInvoiceDelete.value = true
}

async function handleConfirmDeleteInvoice() {
  if (!invoiceToDelete.value) return
  showConfirmInvoiceDelete.value = false

  try {
    await invoiceConfigService.delete(invoiceToDelete.value.id)
    await loadInvoiceSummaries()
    success('Fatura removida com sucesso!')
  } catch (err) {
    showError('Erro ao remover fatura')
  } finally {
    invoiceToDelete.value = null
  }
}

function formatCurrency(value) {
  return value.toFixed(2).replace('.', ',')
}

function formatPeriod(start, end) {
  const fmt = d => {
    const [y, m, day] = d.split('-')
    return `${day}/${m}`
  }
  return `${fmt(start)} - ${fmt(end)}`
}

defineExpose({ openCreateModal })
</script>
