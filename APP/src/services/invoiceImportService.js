import api from './api'

export const invoiceImportService = {
  async uploadFile(file, bankName) {
    const formData = new FormData()
    formData.append('file', file)
    formData.append('bank_name', bankName)
    
    const { data } = await api.post('/invoice-import/upload', formData, {
      headers: {
        'Content-Type': 'multipart/form-data',
      },
    })
    return data
  },

  async getPending(params = {}) {
    const { data } = await api.get('/invoice-import/pending', { params })
    return data
  },

  async previewKeywords(description) {
    const { data } = await api.post('/invoice-import/preview-keywords', { description })
    return data
  },

  async categorize(transactionId, categoryId, keywords = null) {
    const payload = { category_id: categoryId }
    if (keywords && keywords.length > 0) {
      payload.keywords = keywords
    }
    const { data } = await api.put(`/invoice-import/transactions/${transactionId}/categorize`, payload)
    return data
  },
}

