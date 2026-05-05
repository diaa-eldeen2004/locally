import { createContext, useCallback, useContext, useMemo, useRef, useState, type ReactNode } from 'react'

export type ToastVariant = 'info' | 'error'

export type ToastItem = {
  id: number
  message: string
  variant: ToastVariant
}

type ToastCtx = {
  toasts: ToastItem[]
  pushToast: (message: string, variant?: ToastVariant) => void
  dismiss: (id: number) => void
}

const Ctx = createContext<ToastCtx | null>(null)

const DEFAULT_MS = 4200

export function ToastProvider({ children }: { children: ReactNode }) {
  const [toasts, setToasts] = useState<ToastItem[]>([])
  const timers = useRef<Map<number, ReturnType<typeof setTimeout>>>(new Map())
  const idRef = useRef(0)

  const dismiss = useCallback((id: number) => {
    const t = timers.current.get(id)
    if (t) {
      clearTimeout(t)
      timers.current.delete(id)
    }
    setToasts((prev) => prev.filter((x) => x.id !== id))
  }, [])

  const pushToast = useCallback(
    (message: string, variant: ToastVariant = 'info') => {
      const id = ++idRef.current
      setToasts((prev) => [...prev, { id, message, variant }])
      const t = setTimeout(() => dismiss(id), DEFAULT_MS)
      timers.current.set(id, t)
    },
    [dismiss],
  )

  const value = useMemo(() => ({ toasts, pushToast, dismiss }), [toasts, pushToast, dismiss])

  return <Ctx.Provider value={value}>{children}</Ctx.Provider>
}

export function useToast(): ToastCtx {
  const v = useContext(Ctx)
  if (!v) {
    throw new Error('useToast must be used within ToastProvider')
  }

  return v
}
