import api from './api'

export const aiCategorizationService = {
  async suggest({ scope = 'pending', transactionIds = null, limit = null } = {}) {
    const payload = { scope }
    if (transactionIds && transactionIds.length > 0) payload.transaction_ids = transactionIds
    if (limit) payload.limit = limit

    const { data } = await api.post('/transactions/ai-suggestions', payload)
    return data
  },

  async apply(suggestions) {
    const { data } = await api.post('/transactions/ai-suggestions/apply', {
      suggestions: suggestions.map(s => ({
        transaction_id: s.transaction_id,
        category_id: s.category_id,
        keywords: s.keywords || [],
      })),
    })
    return data
  },
}
