import { type ReactNode } from 'react'
import { useLocation } from 'react-router-dom'

export default function PageShell({ children }: { children: ReactNode }) {
  const { pathname } = useLocation()

  return (
    <div key={pathname} className="page-shell">
      {children}
    </div>
  )
}
