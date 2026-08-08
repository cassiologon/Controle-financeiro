<template>
  <Card class="mb-6" variant="gradient">
    <template #header>
      <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div>
          <h3 class="text-lg font-semibold text-gray-900 flex items-center gap-2">
            <span class="text-2xl">✨</span>
            Insights da IA
          </h3>
          <p class="text-sm text-gray-500 mt-1">
            Sugestões inteligentes para reduzir seus gastos no período selecionado
          </p>
        </div>
        <Button
          v-if="!hasInsights || insights?.is_stale"
          variant="primary"
          size="sm"
          :loading="loading"
          :disabled="loading"
          @click="$emit('generate', insights?.is_stale === true)"
        >
          {{ hasInsights ? 'Atualizar análise' : 'Analisar meus gastos com IA' }}
        </Button>
        <span
          v-else
          class="text-xs text-green-600 font-medium bg-green-50 px-3 py-1.5 rounded-lg"
        >
          Análise em dia
        </span>
      </div>
    </template>

    <div v-if="loading" class="space-y-4">
      <div class="animate-pulse space-y-3">
        <div class="h-4 bg-gray-200 rounded w-3/4"></div>
        <div class="h-4 bg-gray-200 rounded w-full"></div>
        <div class="h-24 bg-gray-100 rounded-xl"></div>
        <div class="h-24 bg-gray-100 rounded-xl"></div>
        <div class="h-24 bg-gray-100 rounded-xl"></div>
      </div>
      <p class="text-sm text-gray-500 text-center">Analisando seus gastos...</p>
    </div>

    <div v-else-if="error" class="text-center py-6">
      <p class="text-red-600 mb-4">{{ error }}</p>
      <Button variant="secondary" size="sm" @click="$emit('generate', true)">
        Tentar novamente
      </Button>
    </div>

    <div v-else-if="!hasInsights" class="text-center py-8">
      <p class="text-gray-500 mb-2">
        Clique no botão acima para receber dicas personalizadas com base nos seus gastos filtrados.
      </p>
      <p class="text-xs text-gray-400">
        A análise considera categorias, despesas recorrentes, média diária e orçamentos.
      </p>
    </div>

    <div v-else class="space-y-5">
      <div
        v-if="insights.is_stale"
        class="rounded-xl bg-amber-50 border border-amber-200 p-3 text-sm text-amber-800"
      >
        Seus gastos mudaram desde a última análise. Clique em "Atualizar análise" para gerar novos insights.
      </div>

      <div class="rounded-xl bg-gradient-to-r from-primary-50 to-purple-50 border border-primary-100 p-4">
        <p class="text-sm text-gray-600 mb-1">Resumo da análise</p>
        <p class="text-gray-900">{{ insights.summary }}</p>
        <div v-if="insights.total_potential_savings > 0" class="mt-3 flex items-center gap-2">
          <span class="text-sm text-gray-600">Economia potencial estimada:</span>
          <span class="text-lg font-bold text-green-600">
            R$ {{ formatCurrency(insights.total_potential_savings) }}
          </span>
        </div>
      </div>

      <div class="space-y-3">
        <div
          v-for="(item, index) in insights.insights"
          :key="`${item.title}-${index}`"
          class="rounded-xl border border-gray-200 bg-white p-4 hover:shadow-sm transition-shadow"
        >
          <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
            <div class="flex-1 min-w-0">
              <div class="flex flex-wrap items-center gap-2 mb-2">
                <h4 class="font-semibold text-gray-900">{{ item.title }}</h4>
                <span :class="impactBadgeClass(item.impact)">
                  {{ impactLabel(item.impact) }}
                </span>
              </div>
              <p class="text-sm text-gray-600 leading-relaxed">{{ item.description }}</p>
              <p v-if="item.category" class="text-xs text-gray-400 mt-2">
                Categoria: {{ item.category }}
              </p>
            </div>
            <div v-if="item.estimated_savings > 0" class="text-right shrink-0">
              <p class="text-xs text-gray-500">Economia estimada</p>
              <p class="text-base font-bold text-green-600">
                R$ {{ formatCurrency(item.estimated_savings) }}
              </p>
            </div>
          </div>
        </div>
      </div>

      <p class="text-xs text-gray-400 text-center pt-2">
        Gerado por IA — use como orientação.
        <span v-if="insights.generated_at">
          Atualizado em {{ formatGeneratedAt(insights.generated_at) }}.
        </span>
      </p>
    </div>
  </Card>
</template>

<script setup>
import { computed } from 'vue'
import Card from '@/components/Card.vue'
import Button from '@/components/Button.vue'

const props = defineProps({
  insights: {
    type: Object,
    default: null,
  },
  loading: {
    type: Boolean,
    default: false,
  },
  error: {
    type: String,
    default: null,
  },
})

defineEmits(['generate'])

const hasInsights = computed(() => {
  return Boolean(props.insights?.insights?.length)
})

function formatCurrency(value) {
  const numValue = typeof value === 'number' ? value : parseFloat(value) || 0
  return numValue.toFixed(2).replace('.', ',')
}

function impactLabel(impact) {
  const labels = {
    high: 'Alto impacto',
    medium: 'Médio impacto',
    low: 'Baixo impacto',
  }
  return labels[impact] || 'Médio impacto'
}

function impactBadgeClass(impact) {
  const base = 'inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium'
  const variants = {
    high: `${base} bg-red-100 text-red-700`,
    medium: `${base} bg-amber-100 text-amber-700`,
    low: `${base} bg-blue-100 text-blue-700`,
  }
  return variants[impact] || variants.medium
}

function formatGeneratedAt(isoDate) {
  const date = new Date(isoDate)
  if (Number.isNaN(date.getTime())) return ''
  return date.toLocaleString('pt-BR', {
    day: '2-digit',
    month: '2-digit',
    year: 'numeric',
    hour: '2-digit',
    minute: '2-digit',
  })
}
</script>
