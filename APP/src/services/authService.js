import api from './api'

export const authService = {
  async login(credentials) {
    const { data } = await api.post('/auth/login', credentials)
    return data
  },

  async register(userData) {
    const { data } = await api.post('/auth/register', userData)
    return data
  },

  async logout() {
    await api.post('/auth/logout')
  },

  async getUser() {
    const { data } = await api.get('/auth/user')
    return data
  },
}

