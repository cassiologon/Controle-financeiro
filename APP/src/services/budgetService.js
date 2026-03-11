import api from './api'

export const budgetService = {
  async getAll(month, year) {
    const params = {}
    if (month) params.month = month
    if (year) params.year = year
    const { data } = await api.get('/budgets', { params })
    return data
  },

  async getById(id) {
    const { data } = await api.get(`/budgets/${id}`)
    return data
  },

  async create(budget) {
    const { data } = await api.post('/budgets', budget)
    return data
  },

  async update(id, budget) {
    const { data } = await api.put(`/budgets/${id}`, budget)
    return data
  },

  async delete(id) {
    await api.delete(`/budgets/${id}`)
  },
}

