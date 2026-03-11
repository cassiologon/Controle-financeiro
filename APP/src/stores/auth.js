import { defineStore } from 'pinia'
import { ref, computed } from 'vue'
import { authService } from '@/services/authService'

export const useAuthStore = defineStore('auth', () => {
  const user = ref(null)
  const token = ref(localStorage.getItem('auth_token'))
  const loading = ref(false)

  const isAuthenticated = computed(() => !!token.value && !!user.value)

  function setAuth(userData, authToken) {
    user.value = userData
    token.value = authToken
    localStorage.setItem('auth_token', authToken)
    localStorage.setItem('user', JSON.stringify(userData))
  }

  function clearAuth() {
    user.value = null
    token.value = null
    localStorage.removeItem('auth_token')
    localStorage.removeItem('user')
  }

  async function login(credentials) {
    loading.value = true
    try {
      const response = await authService.login(credentials)
      setAuth(response.user, response.token)
      return response
    } finally {
      loading.value = false
    }
  }

  async function register(userData) {
    loading.value = true
    try {
      const response = await authService.register(userData)
      setAuth(response.user, response.token)
      return response
    } finally {
      loading.value = false
    }
  }

  async function logout() {
    try {
      await authService.logout()
    } finally {
      clearAuth()
    }
  }

  async function fetchUser() {
    if (!token.value) return
    
    loading.value = true
    try {
      const userData = await authService.getUser()
      user.value = userData
      localStorage.setItem('user', JSON.stringify(userData))
    } catch (error) {
      clearAuth()
    } finally {
      loading.value = false
    }
  }

  // Initialize user from localStorage
  const storedUser = localStorage.getItem('user')
  if (storedUser && token.value) {
    try {
      user.value = JSON.parse(storedUser)
    } catch (e) {
      clearAuth()
    }
  }

  return {
    user,
    token,
    loading,
    isAuthenticated,
    login,
    register,
    logout,
    fetchUser,
  }
})

