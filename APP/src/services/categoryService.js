import api from './api'

export const categoryService = {
  async getAll() {
    const { data } = await api.get('/categories')
    return data
  },

  async getById(id) {
    const { data } = await api.get(`/categories/${id}`)
    return data
  },

  async create(category) {
    const { data } = await api.post('/categories', category)
    return data
  },

  async update(id, category) {
    const { data } = await api.put(`/categories/${id}`, category)
    return data
  },

  async delete(id) {
    await api.delete(`/categories/${id}`)
  },
}

