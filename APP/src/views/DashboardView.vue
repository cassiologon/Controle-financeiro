<template>
  <div class="space-y-8">
    <!-- Loading State -->
    <div v-if="loading" class="flex justify-center items-center h-96">
      <div class="text-center">
        <LoadingSpinner />
        <p class="mt-4 text-gray-500 font-medium">Carregando dados...</p>
      </div>
    </div>

    <div v-else class="space-y-8 animate-fade-in">
      <!-- Page Header -->
      <div class="animate-slide-down">
        <h1 class="text-3xl font-bold text-gray-900 mb-2">Dashboard</h1>
        <p class="text-gray-600">Visão geral das suas finanças</p>
      </div>

      <!-- Summary Cards -->
      <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
        <!-- Balance Card -->
        <Card
          variant="gradient"
          :animate="true"
          :delay="0"
          class="relative overflow-hidden"
        >
          <div class="absolute top-0 right-0 w-32 h-32 bg-gradient-to-br from-primary-400/20 to-purple-400/20 rounded-full blur-3xl"></div>
          <div class="relative z-10 flex items-center justify-between">
            <div class="flex-1">
              <p class="text-sm font-semibold text-gray-600 mb-2">Saldo Total</p>
              <p
                :class="[
                  'text-3xl font-bold mb-1',
                  dashboardData?.summary.balance >= 0 ? 'text-success-600' : 'text-error-600',
                ]"
              >
                R$ {{ formatCurrency(dashboardData?.summary.balance || 0) }}
              </p>
              <p class="text-xs text-gray-500">
                {{ dashboardData?.summary.balance >= 0 ? '💰 Positivo' : '⚠️ Negativo' }}
              </p>
            </div>
            <div
              :class="[
                'w-16 h-16 rounded-2xl flex items-center justify-center shadow-lg',
                dashboardData?.summary.balance >= 0
                  ? 'bg-gradient-to-br from-success-400 to-success-600'
                  : 'bg-gradient-to-br from-error-400 to-error-600',
              ]"
            >
              <span class="text-3xl">{{ dashboardData?.summary.balance >= 0 ? '💰' : '📉' }}</span>
            </div>
          </div>
        </Card>

        <!-- Income Card -->
        <Card
          variant="gradient"
          :animate="true"
          :delay="100"
          class="relative overflow-hidden"
        >
          <div class="absolute top-0 right-0 w-32 h-32 bg-gradient-to-br from-success-400/20 to-green-400/20 rounded-full blur-3xl"></div>
          <div class="relative z-10 flex items-center justify-between">
            <div class="flex-1">
              <p class="text-sm font-semibold text-gray-600 mb-2">Receitas</p>
              <p class="text-3xl font-bold text-success-600 mb-1">
                R$ {{ formatCurrency(dashboardData?.summary.total_income || 0) }}
              </p>
              <p class="text-xs text-gray-500">📈 Este mês</p>
            </div>
            <div class="w-16 h-16 rounded-2xl bg-gradient-to-br from-success-400 to-success-600 flex items-center justify-center shadow-lg shadow-success-500/30">
              <span class="text-3xl">📈</span>
            </div>
          </div>
        </Card>

        <!-- Expense Card -->
        <Card
          variant="gradient"
          :animate="true"
          :delay="200"
          class="relative overflow-hidden"
        >
          <div class="absolute top-0 right-0 w-32 h-32 bg-gradient-to-br from-error-400/20 to-red-400/20 rounded-full blur-3xl"></div>
          <div class="relative z-10 flex items-center justify-between">
            <div class="flex-1">
              <p class="text-sm font-semibold text-gray-600 mb-2">Despesas</p>
              <p class="text-3xl font-bold text-error-600 mb-1">
                R$ {{ formatCurrency(dashboardData?.summary.total_expense || 0) }}
              </p>
              <p class="text-xs text-gray-500">📉 Este mês</p>
            </div>
            <div class="w-16 h-16 rounded-2xl bg-gradient-to-br from-error-400 to-error-600 flex items-center justify-center shadow-lg shadow-error-500/30">
              <span class="text-3xl">📉</span>
            </div>
          </div>
        </Card>
      </div>

      <!-- Content Grid -->
      <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
        <!-- Recent Transactions -->
        <Card
          title="Últimas Transações"
          icon="💳"
          :animate="true"
          :delay="300"
        >
          <div
            v-if="dashboardData?.recent_transactions?.length === 0"
            class="text-center py-12"
          >
            <div class="w-16 h-16 mx-auto mb-4 rounded-full bg-gray-100 flex items-center justify-center">
              <span class="text-2xl">📝</span>
            </div>
            <p class="text-gray-500 font-medium">Nenhuma transação recente</p>
            <p class="text-sm text-gray-400 mt-1">Suas transações aparecerão aqui</p>
          </div>
          <div v-else class="space-y-3">
            <TransactionCard
              v-for="(transaction, index) in dashboardData?.recent_transactions"
              :key="transaction.id"
              :transaction="transaction"
              :index="index"
            />
          </div>
        </Card>

        <!-- Budgets Progress -->
        <Card
          title="Orçamentos do Mês"
          icon="🎯"
          :animate="true"
          :delay="400"
        >
          <div
            v-if="dashboardData?.budgets?.length === 0"
            class="text-center py-12"
          >
            <div class="w-16 h-16 mx-auto mb-4 rounded-full bg-gray-100 flex items-center justify-center">
              <span class="text-2xl">🎯</span>
            </div>
            <p class="text-gray-500 font-medium">Nenhum orçamento definido</p>
            <p class="text-sm text-gray-400 mt-1">Configure seus orçamentos mensais</p>
          </div>
          <div v-else class="space-y-6">
            <div
              v-for="budget in dashboardData?.budgets"
              :key="budget.id"
              class="group p-4 rounded-xl bg-gray-50/50 hover:bg-gray-50 transition-colors"
            >
              <div class="flex items-center justify-between mb-3">
                <div class="flex items-center gap-2">
                  <span v-if="budget.category.icon" class="text-xl">{{ budget.category.icon }}</span>
                  <span class="font-semibold text-gray-900">{{ budget.category.name }}</span>
                </div>
                <span class="text-sm font-bold text-gray-700">
                  R$ {{ formatCurrency(budget.spent_amount) }} / R$ {{ formatCurrency(budget.budget_amount) }}
                </span>
              </div>
              
              <!-- Progress Bar -->
              <div class="relative w-full h-3 bg-gray-200 rounded-full overflow-hidden">
                <div
                  :class="[
                    'h-full rounded-full transition-all duration-500 ease-out relative overflow-hidden',
                    budget.percentage > 100
                      ? 'bg-gradient-to-r from-error-500 to-error-600'
                      : budget.percentage > 80
                      ? 'bg-gradient-to-r from-warning-500 to-warning-600'
                      : 'bg-gradient-to-r from-success-500 to-success-600',
                  ]"
                  :style="{ width: `${Math.min(budget.percentage, 100)}%` }"
                >
                  <div class="absolute inset-0 bg-white/20 animate-shimmer"></div>
                </div>
              </div>
              
              <div class="flex items-center justify-between mt-2">
                <span
                  :class="[
                    'text-xs font-semibold',
                    budget.percentage > 100
                      ? 'text-error-600'
                      : budget.percentage > 80
                      ? 'text-warning-600'
                      : 'text-success-600',
                  ]"
                >
                  {{ (Number(budget.percentage) || 0).toFixed(1) }}% utilizado
                </span>
                <span
                  v-if="budget.remaining >= 0"
                  class="text-xs text-gray-500"
                >
                  Restante: R$ {{ formatCurrency(budget.remaining) }}
                </span>
                <span
                  v-else
                  class="text-xs text-error-600 font-semibold"
                >
                  Excedido: R$ {{ formatCurrency(Math.abs(budget.remaining)) }}
                </span>
              </div>
            </div>
          </div>
        </Card>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue'
import { dashboardService } from '@/services/dashboardService'
import Card from '@/components/Card.vue'
import TransactionCard from '@/components/TransactionCard.vue'
import LoadingSpinner from '@/components/LoadingSpinner.vue'

const dashboardData = ref(null)
const loading = ref(true)

onMounted(async () => {
  try {
    const startDate = new Date()
    startDate.setDate(1)
    const endDate = new Date()
    
    dashboardData.value = await dashboardService.getDashboard(
      startDate.toISOString().split('T')[0],
      endDate.toISOString().split('T')[0]
    )
  } catch (error) {
    console.error('Error loading dashboard:', error)
  } finally {
    loading.value = false
  }
})

function formatCurrency(value) {
  const num = Number(value)
  if (Number.isNaN(num)) return '0,00'
  return num.toFixed(2).replace('.', ',')
}
</script>
