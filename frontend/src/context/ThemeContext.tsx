import { createContext, useCallback, useContext, useEffect, useMemo, useState, type ReactNode } from 'react'
import { useAuth } from './AuthContext'

export type ThemeMode = 'system' | 'light' | 'dark'

const STORAGE_KEY = 'locally_theme'

function readStoredMode(): ThemeMode | null {
  const raw = localStorage.getItem(STORAGE_KEY)
  if (raw === 'light' || raw === 'dark' || raw === 'system') {
    return raw
  }

  return null
}

type ThemeCtx = {
  mode: ThemeMode
  setMode: (m: ThemeMode) => void
}

const Ctx = createContext<ThemeCtx | null>(null)

export function ThemeProvider({ children }: { children: ReactNode }) {
  const { user, loading: authLoading } = useAuth()
  const [mode, setModeState] = useState<ThemeMode>(() => readStoredMode() ?? 'system')

  const setMode = useCallback((next: ThemeMode) => {
    localStorage.setItem(STORAGE_KEY, next)
    setModeState(next)
  }, [])

  useEffect(() => {
    document.documentElement.dataset.theme = mode
    if (mode === 'dark') {
      document.documentElement.style.colorScheme = 'dark'
    } else if (mode === 'light') {
      document.documentElement.style.colorScheme = 'light'
    } else {
      document.documentElement.style.removeProperty('color-scheme')
    }
  }, [mode])

  useEffect(() => {
    if (authLoading) {
      return
    }
    if (readStoredMode() !== null) {
      return
    }
    const pref = user?.theme_preference
    if (pref === 'light' || pref === 'dark' || pref === 'system') {
      queueMicrotask(() => {
        setModeState(pref)
      })
    }
  }, [authLoading, user?.id, user?.theme_preference])

  const value = useMemo(() => ({ mode, setMode }), [mode, setMode])

  return <Ctx.Provider value={value}>{children}</Ctx.Provider>
}

export function useTheme(): ThemeCtx {
  const v = useContext(Ctx)
  if (!v) {
    throw new Error('useTheme must be used within ThemeProvider')
  }

  return v
}
