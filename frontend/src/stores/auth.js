import { defineStore } from 'pinia'
import { api } from '../api'

export const useAuth = defineStore('auth', {
  state: () => ({ user: null, checked: false }),
  getters: {
    isStaff: (s) => ['lehrer', 'admin'].includes(s.user?.role),
    roleLabel: (s) => ({ teilnehmer: 'Teilnehmer', lehrer: 'Lehrer', admin: 'Admin' })[s.user?.role] ?? '',
  },
  actions: {
    async check() {
      if (this.checked) return this.user
      try {
        this.user = (await api.get('auth/me')).user
      } catch {
        this.user = null
      }
      this.checked = true
      return this.user
    },
    async login(username, password) {
      this.user = (await api.post('auth/login', { username, password })).user
      this.checked = true
    },
    async logout() {
      await api.post('auth/logout').catch(() => {})
      this.user = null
    },
  },
})
