import api from './api'

export const invoiceImportService = {
  async uploadFiles(files, bankName) {
    const formData = new FormData()
    const list = Array.isArray(files) ? files : [files]
    list.forEach((file) => formData.append('files[]', file))
    formData.append('bank_name', bankName)

    const { data } = await api.post('/invoice-import/upload', formData, {
      headers: {
        'Content-Type': 'multipart/form-data',
      },
    })
    return data
  },

  async uploadFile(file, bankName) {
    return this.uploadFiles([file], bankName)
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

