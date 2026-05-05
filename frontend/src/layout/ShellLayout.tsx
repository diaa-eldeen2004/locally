import { useEffect } from 'react'
import { Link, Outlet, useLocation } from 'react-router-dom'
import PageShell from '../components/PageShell'
import { useAuth } from '../context/AuthContext'
import { useTheme, type ThemeMode } from '../context/ThemeContext'
import { getCsrf } from '../api'
import './store.css'

export default function ShellLayout() {
  const location = useLocation()
  const { user, loading } = useAuth()
  const { mode, setMode } = useTheme()

  useEffect(() => {
    void getCsrf(true).catch(() => {
      /* backend may be offline */
    })
  }, [])

  const role = user?.role ?? ''
  const showStaff = role === 'admin' || role === 'confirmer'

  const themeBtn = (value: ThemeMode, label: string) => (
    <button
      key={value}
      type="button"
      className={mode === value ? 'is-active' : ''}
      onClick={() => setMode(value)}
      aria-pressed={mode === value}
    >
      {label}
    </button>
  )

  return (
    <div className="store">
      <header className="store__header">
        <Link to="/" className="store__brand">
          Locally
        </Link>
        <nav className="store__nav" aria-label="Primary">
          <Link to="/" className={location.pathname === '/' ? 'is-active' : ''}>
            Home
          </Link>
          <Link to="/products" className={location.pathname.startsWith('/products') ? 'is-active' : ''}>
            Shop
          </Link>
          <Link to="/cart" className={location.pathname === '/cart' ? 'is-active' : ''}>
            Cart
          </Link>
          {!loading && user ? (
            <>
              <Link to="/account" className={location.pathname.startsWith('/account') ? 'is-active' : ''}>
                Account
              </Link>
              <Link to="/orders" className={location.pathname.startsWith('/orders') ? 'is-active' : ''}>
                Orders
              </Link>
            </>
          ) : null}
          {user?.role === 'admin' ? (
            <Link to="/admin" className={location.pathname.startsWith('/admin') ? 'is-active' : ''}>
              Admin
            </Link>
          ) : null}
          {showStaff ? (
            <Link to="/confirmer" className={location.pathname.startsWith('/confirmer') ? 'is-active' : ''}>
              Confirmer
            </Link>
          ) : null}
          {user?.role === 'admin' ? (
            <Link to="/dev" className={location.pathname === '/dev' ? 'is-active' : ''}>
              API dev
            </Link>
          ) : null}
          <span className="store__nav-divider" aria-hidden />
          <div className="theme-toggle" role="group" aria-label="Theme">
            {themeBtn('system', 'Auto')}
            {themeBtn('light', 'Light')}
            {themeBtn('dark', 'Dark')}
          </div>
        </nav>
      </header>
      <main className="store__main">
        <PageShell>
          <Outlet />
        </PageShell>
      </main>
      <footer className="store__footer">Locally · Phase 6 store UX</footer>
    </div>
  )
}
