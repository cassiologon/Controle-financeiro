import api from './api'

export const invoiceConfigService = {
  async getAll() {
    const { data } = await api.get('/invoice-configs')
    return data
  },

  async getSummary() {
    const { data } = await api.get('/invoice-configs/summary')
    return data
  },

  async create(config) {
    const { data } = await api.post('/invoice-configs', config)
    return data
  },

  async update(id, config) {
    const { data } = await api.put(`/invoice-configs/${id}`, config)
    return data
  },

  async delete(id) {
    await api.delete(`/invoice-configs/${id}`)
  },
}
