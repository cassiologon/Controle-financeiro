<template>
  <div class="min-h-screen flex items-center justify-center relative overflow-hidden bg-gradient-to-br from-primary-500 via-purple-600 to-pink-500">
    <!-- Animated Background -->
    <div class="absolute inset-0 overflow-hidden">
      <div class="absolute -top-40 -right-40 w-80 h-80 bg-white/10 rounded-full blur-3xl animate-float"></div>
      <div class="absolute -bottom-40 -left-40 w-80 h-80 bg-white/10 rounded-full blur-3xl animate-float" style="animation-delay: 1.5s;"></div>
      <div class="absolute top-1/2 left-1/2 -translate-x-1/2 -translate-y-1/2 w-96 h-96 bg-white/5 rounded-full blur-3xl animate-float" style="animation-delay: 3s;"></div>
    </div>

    <div class="relative z-10 w-full max-w-md px-4 sm:px-6 lg:px-8">
      <div class="animate-scale-in">
        <!-- Logo/Header -->
        <div class="text-center mb-8">
          <div class="inline-flex items-center justify-center w-20 h-20 rounded-3xl bg-white/20 backdrop-blur-md mb-4 shadow-soft-lg">
            <span class="text-4xl">💰</span>
          </div>
          <h1 class="text-4xl font-bold text-white mb-2">Bem-vindo de volta!</h1>
          <p class="text-white/80 text-sm">
            Ou
            <router-link
              to="/register"
              class="font-semibold text-white underline underline-offset-2 hover:text-white/90 transition-colors"
            >
              crie uma nova conta
            </router-link>
          </p>
        </div>

        <!-- Login Card -->
        <Card variant="glass" class="backdrop-blur-xl bg-white/90">
          <form class="space-y-6" @submit.prevent="handleLogin">
            <div class="space-y-5">
              <Input
                id="email"
                v-model="form.email"
                type="email"
                label="Email"
                placeholder="seu@email.com"
                floating-label
                required
                :error="errors.email"
              />
              <Input
                id="password"
                v-model="form.password"
                type="password"
                label="Senha"
                placeholder="••••••••"
                floating-label
                show-password-toggle
                required
                :error="errors.password"
              />
            </div>

            <!-- Error Message -->
            <transition
              enter-active-class="transition-all duration-300"
              enter-from-class="opacity-0 translate-y-2"
              enter-to-class="opacity-100 translate-y-0"
              leave-active-class="transition-all duration-200"
              leave-from-class="opacity-100 translate-y-0"
              leave-to-class="opacity-0 translate-y-2"
            >
              <div
                v-if="error"
                class="rounded-xl bg-error-50 border border-error-200 p-4 flex items-start gap-3"
              >
                <svg class="w-5 h-5 text-error-600 flex-shrink-0 mt-0.5" fill="currentColor" viewBox="0 0 20 20">
                  <path
                    fill-rule="evenodd"
                    d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z"
                    clip-rule="evenodd"
                  />
                </svg>
                <div class="flex-1">
                  <p class="text-sm font-semibold text-error-800">{{ error }}</p>
                </div>
              </div>
            </transition>

            <Button
              type="submit"
              variant="primary"
              :loading="loading"
              class="w-full text-lg py-4"
            >
              Entrar
            </Button>
          </form>
        </Card>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref } from 'vue'
import { useRouter } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import Input from '@/components/Input.vue'
import Button from '@/components/Button.vue'
import Card from '@/components/Card.vue'

const router = useRouter()
const authStore = useAuthStore()

const form = ref({
  email: '',
  password: '',
})

const errors = ref({})
const error = ref('')
const loading = ref(false)

async function handleLogin() {
  errors.value = {}
  error.value = ''
  loading.value = true

  try {
    await authStore.login(form.value)
    router.push('/')
  } catch (err) {
    if (err.response?.data?.errors) {
      errors.value = err.response.data.errors
    } else {
      error.value = err.response?.data?.message || 'Erro ao fazer login. Tente novamente.'
    }
  } finally {
    loading.value = false
  }
}
</script>
