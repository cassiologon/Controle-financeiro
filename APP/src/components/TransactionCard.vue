<template>
  <div
    :class="[
      'group relative overflow-hidden rounded-2xl bg-white/80 backdrop-blur-sm shadow-soft hover:shadow-soft-lg transition-all duration-300 cursor-pointer animate-slide-up',
      transaction.status === 'pending' 
        ? 'border-2 border-warning-200' 
        : 'border border-gray-100'
    ]"
    :style="{ animationDelay: `${index * 50}ms` }"
    @click="$emit('click', transaction)"
  >
    <!-- Gradient overlay on hover -->
    <div
      :class="[
        'absolute inset-0 opacity-0 group-hover:opacity-100 transition-opacity duration-300',
        transaction.status === 'pending'
          ? 'bg-gradient-to-r from-warning-50/50 to-warning-100/30'
          : transaction.type === 'income'
          ? 'bg-gradient-to-r from-success-50/50 to-success-100/30'
          : 'bg-gradient-to-r from-error-50/50 to-error-100/30',
      ]"
    ></div>

    <div class="relative z-10 flex items-center justify-between p-5">
      <!-- Left Section: Icon and Info -->
      <div class="flex items-center gap-4 flex-1 min-w-0">
        <!-- Icon Container -->
        <div
          :class="[
            'relative w-14 h-14 rounded-2xl flex items-center justify-center flex-shrink-0',
            'transition-transform duration-300 group-hover:scale-110 group-hover:rotate-3',
            transaction.status === 'pending'
              ? 'bg-gradient-to-br from-warning-400 to-warning-600 shadow-lg shadow-warning-500/30'
              : transaction.type === 'income'
              ? 'bg-gradient-to-br from-success-400 to-success-600 shadow-lg shadow-success-500/30'
              : 'bg-gradient-to-br from-error-400 to-error-600 shadow-lg shadow-error-500/30',
          ]"
        >
          <span
            v-if="transaction.status === 'pending'"
            class="text-2xl filter drop-shadow-sm"
          >
            ⚠️
          </span>
          <span
            v-else-if="transaction.category?.icon"
            class="text-2xl filter drop-shadow-sm"
          >
            {{ transaction.category.icon }}
          </span>
          <span
            v-else
            class="text-xl filter drop-shadow-sm"
          >
            {{ transaction.type === 'income' ? '💰' : '💸' }}
          </span>

          <!-- Pulse effect -->
          <span
            :class="[
              'absolute inset-0 rounded-2xl animate-ping opacity-20',
              transaction.status === 'pending'
                ? 'bg-warning-400'
                : transaction.type === 'income' 
                ? 'bg-success-400' 
                : 'bg-error-400',
            ]"
          ></span>
        </div>

        <!-- Transaction Info -->
        <div class="flex-1 min-w-0">
          <h4 class="font-bold text-gray-900 truncate mb-1 group-hover:text-primary-700 transition-colors">
            {{ transaction.description }}
          </h4>
          <div class="flex items-center gap-2 mb-1">
            <span
              v-if="transaction.status === 'pending'"
              class="inline-flex items-center px-2 py-0.5 rounded-lg text-xs font-semibold bg-warning-100 text-warning-700"
            >
              Pendente
            </span>
            <span
              v-else
              :class="[
                'inline-flex items-center px-2 py-0.5 rounded-lg text-xs font-semibold',
                transaction.type === 'income'
                  ? 'bg-success-100 text-success-700'
                  : 'bg-error-100 text-error-700',
              ]"
            >
              {{ transaction.category?.name || 'Sem categoria' }}
            </span>
            <span
              v-if="transaction.is_installment"
              class="inline-flex items-center px-2 py-0.5 rounded-lg text-xs font-semibold bg-primary-100 text-primary-700"
            >
              Parcelada
            </span>
          </div>
          <p class="text-xs text-gray-400 font-medium">
            {{ formatDate(transaction.date) }}
          </p>
        </div>
      </div>

      <!-- Right Section: Amount and Actions -->
      <div class="flex-shrink-0 ml-4 text-right">
        <div class="flex items-center gap-2 justify-end mb-2">
          <button
            @click.stop="$emit('delete', transaction)"
            class="p-2 rounded-xl text-red-500 hover:text-red-700 hover:bg-red-50 transition-all"
            title="Deletar transação"
          >
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
            </svg>
          </button>
        </div>
        <p
          :class="[
            'text-2xl font-bold mb-1 transition-all duration-300',
            'group-hover:scale-105',
            transaction.type === 'income'
              ? 'text-success-600'
              : 'text-error-600',
          ]"
        >
          {{ transaction.type === 'income' ? '+' : '-' }}R$ {{ formatCurrency(transaction.amount) }}
        </p>
        <p
          :class="[
            'text-xs font-medium',
            transaction.type === 'income' ? 'text-success-500' : 'text-error-500',
          ]"
        >
          {{ transaction.type === 'income' ? 'Receita' : 'Despesa' }}
        </p>
      </div>
    </div>

    <!-- Bottom border accent -->
    <div
      :class="[
        'absolute bottom-0 left-0 right-0 h-1 transition-all duration-300',
        transaction.status === 'pending'
          ? 'bg-gradient-to-r from-warning-400 to-warning-600'
          : transaction.type === 'income'
          ? 'bg-gradient-to-r from-success-400 to-success-600'
          : 'bg-gradient-to-r from-error-400 to-error-600',
        'group-hover:h-1.5',
      ]"
    ></div>
  </div>
</template>

<script setup>
defineProps({
  transaction: {
    type: Object,
    required: true
  },
  index: Number
})

defineEmits(['click', 'delete'])

function formatCurrency(value) {
  const numValue = typeof value === 'string' ? parseFloat(value) : Number(value)
  if (isNaN(numValue)) {
    return '0,00'
  }
  return numValue.toFixed(2).replace('.', ',')
}

function formatDate(date) {
  if (!date) return ''

  // Evita deslocamento de fuso ao converter datas que chegam como
  // "YYYY-MM-DD" ou "YYYY-MM-DDTHH:mm:ss...".
  let d
  if (typeof date === 'string' && /^\d{4}-\d{2}-\d{2}/.test(date)) {
    const [year, month, day] = date.slice(0, 10).split('-').map(Number)
    d = new Date(year, month - 1, day)
  } else {
    d = new Date(date)
  }

  if (Number.isNaN(d.getTime())) return ''

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
