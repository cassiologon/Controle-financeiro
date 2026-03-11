import api from './api'

export const dashboardService = {
  async getDashboard(startDate, endDate) {
    const { data } = await api.get('/dashboard', {
      params: { start_date: startDate, end_date: endDate },
    })
    return data
  },
}

