<template>
  <div class="min-h-screen bg-gradient-to-br from-gray-50 via-blue-50/30 to-purple-50/20">
    <!-- Modern Header -->
    <header class="sticky top-0 z-40 bg-white/80 backdrop-blur-lg border-b border-gray-200/50 shadow-soft">
      <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="flex justify-between items-center h-20">
          <!-- Logo -->
          <div class="flex items-center gap-3">
            <div class="w-10 h-10 rounded-xl bg-gradient-to-br from-primary-500 to-primary-700 flex items-center justify-center shadow-lg shadow-primary-500/30">
              <span class="text-xl">💰</span>
            </div>
            <h1 class="text-xl font-bold text-gradient">Controle Financeiro</h1>
          </div>

          <!-- Desktop Navigation -->
          <nav class="hidden md:flex items-center gap-1">
            <router-link
              v-for="item in navigation"
              :key="item.name"
              :to="item.to"
              :class="[
                'group relative px-4 py-2 rounded-xl text-sm font-semibold transition-all duration-300',
                'flex items-center gap-2',
                $route.path === item.to
                  ? 'text-primary-700 bg-primary-50 shadow-sm'
                  : 'text-gray-600 hover:text-primary-600 hover:bg-gray-50',
              ]"
            >
              <component
                :is="item.icon"
                :class="[
                  'w-5 h-5 transition-transform duration-300',
                  $route.path === item.to ? 'scale-110' : 'group-hover:scale-110',
                ]"
              />
              <span>{{ item.name }}</span>
              
              <!-- Active indicator -->
              <span
                v-if="$route.path === item.to"
                class="absolute bottom-0 left-1/2 -translate-x-1/2 w-1 h-1 rounded-full bg-primary-600"
              ></span>
            </router-link>
          </nav>

          <!-- User Menu -->
          <div class="flex items-center gap-4">
            <div class="hidden sm:flex items-center gap-3 px-4 py-2 rounded-xl bg-gray-50 border border-gray-200">
              <div class="w-8 h-8 rounded-full bg-gradient-to-br from-primary-400 to-primary-600 flex items-center justify-center text-white text-sm font-bold">
                {{ authStore.user?.name?.charAt(0).toUpperCase() || 'U' }}
              </div>
              <span class="text-sm font-semibold text-gray-700">{{ authStore.user?.name }}</span>
            </div>
            <Button variant="secondary" size="sm" @click="handleLogout">
              <svg class="w-4 h-4 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1" />
              </svg>
              Sair
            </Button>
          </div>
        </div>
      </div>
    </header>

    <!-- Mobile Navigation -->
    <nav class="md:hidden bg-white/80 backdrop-blur-lg border-b border-gray-200/50 shadow-sm sticky top-20 z-30">
      <div class="px-4 py-2 overflow-x-auto">
        <div class="flex gap-2 min-w-max">
          <router-link
            v-for="item in navigation"
            :key="item.name"
            :to="item.to"
            :class="[
              'flex flex-col items-center gap-1 px-4 py-2 rounded-xl text-xs font-semibold transition-all duration-300 min-w-[80px]',
              $route.path === item.to
                ? 'text-primary-700 bg-primary-50'
                : 'text-gray-600 hover:text-primary-600 hover:bg-gray-50',
            ]"
          >
            <component :is="item.icon" class="w-5 h-5" />
            <span>{{ item.name }}</span>
          </router-link>
        </div>
      </div>
    </nav>

    <!-- Main Content -->
    <main class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
      <RouterView v-slot="{ Component }">
        <transition
          name="page"
          mode="out-in"
          enter-active-class="transition-all duration-300 ease-out"
          enter-from-class="opacity-0 translate-y-4"
          enter-to-class="opacity-100 translate-y-0"
          leave-active-class="transition-all duration-200 ease-in"
          leave-from-class="opacity-100 translate-y-0"
          leave-to-class="opacity-0 -translate-y-4"
        >
          <component :is="Component" />
        </transition>
      </RouterView>
    </main>
  </div>
</template>

<script setup>
import { RouterView, useRouter } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import Button from '@/components/Button.vue'
import {
  HomeIcon,
  CurrencyDollarIcon,
  TagIcon,
  ChartBarIcon,
  DocumentChartBarIcon,
} from '@heroicons/vue/24/outline'

const router = useRouter()
const authStore = useAuthStore()

const navigation = [
  { name: 'Dashboard', to: '/', icon: HomeIcon },
  { name: 'Transações', to: '/transactions', icon: CurrencyDollarIcon },
  { name: 'Categorias', to: '/categories', icon: TagIcon },
  { name: 'Orçamentos', to: '/budgets', icon: DocumentChartBarIcon },
  { name: 'Relatórios', to: '/reports', icon: ChartBarIcon },
]

async function handleLogout() {
  await authStore.logout()
  router.push('/login')
}
</script>

<style scoped>
.text-gradient {
  background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
  -webkit-background-clip: text;
  -webkit-text-fill-color: transparent;
  background-clip: text;
}
</style>
