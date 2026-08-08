import api from './api'

export const transactionService = {
  async getAll(filters) {
    const { data } = await api.get('/transactions', { params: filters })
    return data
  },

  async getById(id) {
    const { data } = await api.get(`/transactions/${id}`)
    return data
  },

  async create(transaction) {
    const { data } = await api.post('/transactions', transaction)
    return data
  },

  async update(id, transaction) {
    const { data } = await api.put(`/transactions/${id}`, transaction)
    return data
  },

  async delete(id) {
    await api.delete(`/transactions/${id}`)
  },

  async getBanks() {
    const { data } = await api.get('/transactions/banks')
    return data
  },

  async deleteAll(filters = {}) {
    const { data } = await api.delete('/transactions/delete-all', {
      params: filters,
      data: filters,
    })
    return data
  },
}

