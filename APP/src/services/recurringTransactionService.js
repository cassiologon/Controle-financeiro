import api from './api'

export const recurringTransactionService = {
  async getAll(isActive) {
    const { data } = await api.get('/recurring-transactions', {
      params: { is_active: isActive },
    })
    return data
  },

  async getById(id) {
    const { data } = await api.get(`/recurring-transactions/${id}`)
    return data
  },

  async create(transaction) {
    const { data } = await api.post('/recurring-transactions', transaction)
    return data
  },

  async update(id, transaction) {
    const { data } = await api.put(`/recurring-transactions/${id}`, transaction)
    return data
  },

  async delete(id) {
    await api.delete(`/recurring-transactions/${id}`)
  },
}

