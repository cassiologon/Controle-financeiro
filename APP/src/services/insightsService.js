import api from './api'

export const insightsService = {
  async get(startDate, endDate) {
    const { data } = await api.get('/insights', {
      params: { start_date: startDate, end_date: endDate },
    })
    return data
  },

  async generate(startDate, endDate, force = false) {
    const { data } = await api.post('/insights', {
      start_date: startDate,
      end_date: endDate,
      force,
    })
    return data
  },
}
