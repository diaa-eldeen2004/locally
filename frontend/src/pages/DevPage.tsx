import { useCallback, useEffect, useState, type FormEvent } from 'react'
import '../App.css'
import { apiFetch, apiPostCsrf, getCsrf, invalidateCsrf } from '../api'
import { useAuth } from '../context/AuthContext'

type HealthData = {
  service: string
  time: string
  database?: {
    configured: boolean
    connected?: boolean
    driver?: string
    latency_ms?: number
    server_version?: string | null
    message?: string
    error?: string
  }
}

type AuthOk = { user: Record<string, unknown> }

function formatDatabase(db: HealthData['database']): string {
  if (!db) {
    return 'Database: (no status)'
  }
  if (!db.configured) {
    return `Database: ${db.message ?? 'not configured'}`
  }
  if (!db.connected) {
    return `Database: disconnected — ${db.error ?? 'unknown error'}`
  }
  const ver = db.server_version ? ` — ${db.server_version}` : ''
  const ms = db.latency_ms != null ? ` · ${db.latency_ms} ms` : ''

  return `Database: connected (${db.driver ?? 'mysql'})${ms}${ver}`
}

export default function DevPage() {
  const { user: me, loading: authLoading, refresh } = useAuth()
  const [apiLine, setApiLine] = useState<string>('Checking API…')
  const [dbLine, setDbLine] = useState<string>('')
  const [csrfPreview, setCsrfPreview] = useState<string>('—')
  const [authMsg, setAuthMsg] = useState<string>('')

  const [loginEmail, setLoginEmail] = useState('admin@locally.test')
  const [loginPassword, setLoginPassword] = useState('password')
  const [regEmail, setRegEmail] = useState('')
  const [regPassword, setRegPassword] = useState('')
  const [regFirst, setRegFirst] = useState('')
  const [regLast, setRegLast] = useState('')

  const refreshCsrfPreview = useCallback(async () => {
    try {
      const token = await getCsrf(true)
      setCsrfPreview(token ? `${token.slice(0, 10)}…` : '—')
    } catch {
      setCsrfPreview('—')
    }
  }, [])

  useEffect(() => {
    void (async () => {
      const health = await apiFetch<HealthData>('/health', { method: 'GET' })
      if (health.ok && health.data?.service) {
        setApiLine(`API online — ${health.data.service} (${health.data.time})`)
        setDbLine(formatDatabase(health.data.database))
      } else {
        setApiLine(health.error?.message ?? 'API returned an unexpected payload.')
        setDbLine('')
      }

      await refreshCsrfPreview()
    })().catch(() => {
      setApiLine('API offline — start PHP on :8080')
      setDbLine('')
    })
  }, [refreshCsrfPreview])

  const onLogin = async (e: FormEvent) => {
    e.preventDefault()
    setAuthMsg('')
    invalidateCsrf()
    const body = await apiPostCsrf<AuthOk>('/auth/login', { email: loginEmail, password: loginPassword })
    if (body.ok && body.data?.user) {
      setAuthMsg('Logged in.')
      invalidateCsrf()
      await refresh()
      await refreshCsrfPreview()
    } else {
      setAuthMsg(body.error?.message ?? 'Login failed.')
    }
  }

  const onRegister = async (e: FormEvent) => {
    e.preventDefault()
    setAuthMsg('')
    invalidateCsrf()
    const body = await apiPostCsrf<AuthOk>('/auth/register', {
      email: regEmail,
      password: regPassword,
      first_name: regFirst,
      last_name: regLast,
    })
    if (body.ok && body.data?.user) {
      setAuthMsg('Account created and session started.')
      invalidateCsrf()
      await refresh()
      await refreshCsrfPreview()
    } else {
      setAuthMsg(body.error?.message ?? 'Registration failed.')
    }
  }

  const onLogout = async () => {
    setAuthMsg('')
    invalidateCsrf()
    const body = await apiPostCsrf<{ logged_out: boolean }>('/auth/logout', {})
    if (body.ok) {
      setAuthMsg('Logged out.')
      invalidateCsrf()
      await refresh()
      await refreshCsrfPreview()
    } else {
      setAuthMsg(body.error?.message ?? 'Logout failed.')
    }
  }

  const onAdminPing = async () => {
    setAuthMsg('')
    const body = await apiFetch<{ message: string }>('/admin/ping', { method: 'GET' })
    if (body.ok && body.data?.message) {
      setAuthMsg(body.data.message)
    } else {
      setAuthMsg(body.error?.message ?? 'Admin ping failed.')
    }
  }

  return (
    <div className="page">
      <header className="page__head">
        <h1>API dev</h1>
        <p className="muted">Auth, CSRF, and health checks</p>
      </header>

      <div className="cards" style={{ marginBottom: '1rem' }}>
        <div className="card" role="status">
          <span className="dot" aria-hidden />
          {apiLine}
        </div>
        {dbLine ? (
          <div className="card card--muted" role="status">
            <span className="dot dot--muted" aria-hidden />
            {dbLine}
          </div>
        ) : null}
      </div>

      <section className="panel">
        <h2 className="panel__title">Auth</h2>
        <p className="panel__hint">
          POST requests require <code>X-CSRF-Token</code> from <code>GET /api/csrf</code>.
        </p>

        <div className="panel__row">
          <form className="form" onSubmit={onLogin}>
            <h3 className="form__title">Login</h3>
            <label className="field">
              <span>Email</span>
              <input
                type="email"
                autoComplete="username"
                value={loginEmail}
                onChange={(e) => setLoginEmail(e.target.value)}
                required
              />
            </label>
            <label className="field">
              <span>Password</span>
              <input
                type="password"
                autoComplete="current-password"
                value={loginPassword}
                onChange={(e) => setLoginPassword(e.target.value)}
                required
              />
            </label>
            <button type="submit" className="btn">
              Sign in
            </button>
          </form>

          <form className="form" onSubmit={onRegister}>
            <h3 className="form__title">Register</h3>
            <label className="field">
              <span>Email</span>
              <input type="email" value={regEmail} onChange={(e) => setRegEmail(e.target.value)} required />
            </label>
            <label className="field">
              <span>Password</span>
              <input
                type="password"
                value={regPassword}
                onChange={(e) => setRegPassword(e.target.value)}
                required
                minLength={8}
              />
            </label>
            <label className="field">
              <span>First name</span>
              <input value={regFirst} onChange={(e) => setRegFirst(e.target.value)} required />
            </label>
            <label className="field">
              <span>Last name</span>
              <input value={regLast} onChange={(e) => setRegLast(e.target.value)} />
            </label>
            <button type="submit" className="btn btn--secondary">
              Create account
            </button>
          </form>
        </div>

        <div className="panel__actions">
          <button type="button" className="btn btn--ghost" onClick={() => void refresh()}>
            Refresh /me
          </button>
          <button type="button" className="btn btn--ghost" onClick={() => void onLogout()}>
            Logout
          </button>
          <button type="button" className="btn btn--ghost" onClick={() => void onAdminPing()}>
            Admin ping
          </button>
        </div>

        {authMsg ? <p className="panel__msg">{authMsg}</p> : null}
        {!authLoading ? <pre className="panel__pre">{JSON.stringify({ user: me }, null, 2)}</pre> : <p className="muted">Loading session…</p>}
        <p className="panel__foot">
          CSRF token (truncated): <code>{csrfPreview}</code>
        </p>
      </section>
    </div>
  )
}
