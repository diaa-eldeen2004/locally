import { createContext, useCallback, useContext, useEffect, useMemo, useState, type ReactNode } from 'react'
import { apiFetch } from '../api'

export type AuthUser = {
  id: number
  email: string
  first_name: string
  last_name: string
  role: string
  theme_preference: string
}

type AuthCtx = {
  user: AuthUser | null
  loading: boolean
  refresh: () => Promise<void>
}

const Ctx = createContext<AuthCtx | null>(null)

export function AuthProvider({ children }: { children: ReactNode }) {
  const [user, setUser] = useState<AuthUser | null>(null)
  const [loading, setLoading] = useState(true)

  const refresh = useCallback(async () => {
    const res = await apiFetch<{ user: AuthUser | null }>('/auth/me', { method: 'GET' })
    if (res.ok) {
      setUser((res.data?.user as AuthUser) ?? null)
    } else {
      setUser(null)
    }
    setLoading(false)
  }, [])

  useEffect(() => {
    let cancelled = false
    void (async () => {
      const res = await apiFetch<{ user: AuthUser | null }>('/auth/me', { method: 'GET' })
      if (cancelled) {
        return
      }
      if (res.ok) {
        setUser((res.data?.user as AuthUser) ?? null)
      } else {
        setUser(null)
      }
      setLoading(false)
    })()

    return () => {
      cancelled = true
    }
  }, [])

  const value = useMemo(() => ({ user, loading, refresh }), [user, loading, refresh])

  return <Ctx.Provider value={value}>{children}</Ctx.Provider>
}

export function useAuth(): AuthCtx {
  const v = useContext(Ctx)
  if (!v) {
    throw new Error('useAuth must be used within AuthProvider')
  }

  return v
}
