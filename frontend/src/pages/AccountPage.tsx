import { useQuery } from '@tanstack/react-query'
import { useState } from 'react'
import { Link } from 'react-router-dom'
import { apiFetch } from '../api'
import { useAuth } from '../context/AuthContext'
import { qk } from '../lib/queryKeys'

type ProductCard = {
  id: number
  name: string
  slug: string
  effective_price: number
  image_path: string | null
}

type Tab = 'overview' | 'favorites'

export default function AccountPage() {
  const { user, loading } = useAuth()
  const [tab, setTab] = useState<Tab>('overview')

  const favQuery = useQuery({
    queryKey: qk.favoritesList,
    enabled: Boolean(user) && tab === 'favorites',
    queryFn: async () => {
      const res = await apiFetch<{ items: ProductCard[]; total: number }>('/favorites', { method: 'GET' })
      if (!res.ok || !res.data) {
        throw new Error(res.error?.message ?? 'Could not load favorites.')
      }

      return res.data
    },
  })

  if (loading) {
    return (
      <div className="page">
        <p className="muted">Loading…</p>
      </div>
    )
  }

  if (!user) {
    return (
      <div className="page">
        <header className="page__head">
          <h1>Account</h1>
        </header>
        <p className="banner banner--error">Sign in to use your account dashboard.</p>
        <Link className="btn" to="/dev">
          API dev (login)
        </Link>
      </div>
    )
  }

  const favErr = favQuery.error instanceof Error ? favQuery.error.message : ''
  const items = favQuery.data?.items ?? []

  return (
    <div className="page">
      <header className="page__head">
        <h1>Account</h1>
        <p className="muted">
          {user.first_name} {user.last_name}
        </p>
      </header>

      <div className="account-tabs" role="tablist" aria-label="Account sections">
        <button type="button" role="tab" className={tab === 'overview' ? 'is-active' : ''} onClick={() => setTab('overview')}>
          Overview
        </button>
        <button type="button" role="tab" className={tab === 'favorites' ? 'is-active' : ''} onClick={() => setTab('favorites')}>
          Favorites
        </button>
      </div>

      {tab === 'overview' ? (
        <div className="account-panel">
          <p className="muted">{user.email}</p>
          <p>Theme preference from profile: {user.theme_preference} (header toggle controls this device).</p>
          <div className="pager" style={{ justifyContent: 'flex-start', marginTop: '1rem' }}>
            <Link className="btn" to="/orders">
              Your orders
            </Link>
            <Link className="btn btn--ghost" to="/cart">
              Cart
            </Link>
          </div>
        </div>
      ) : null}

      {tab === 'favorites' ? (
        <div className="account-panel">
          {favQuery.isPending ? <p className="muted">Loading favorites…</p> : null}
          {favErr ? <p className="banner banner--error">{favErr}</p> : null}
          {items.length === 0 && !favQuery.isPending && !favErr ? (
            <div className="empty">
              <p>No saved favorites yet.</p>
              <Link className="btn" to="/products">
                Browse shop
              </Link>
            </div>
          ) : (
            <div className="grid">
              {items.map((p) => (
                <Link key={p.id} className="tile" to={`/product/${encodeURIComponent(p.slug)}`}>
                  <div className="tile__img">
                    {p.image_path ? <img src={p.image_path} alt="" loading="lazy" /> : <span>No image</span>}
                  </div>
                  <div className="tile__body">
                    <div className="tile__name">{p.name}</div>
                    <div className="tile__price">${p.effective_price.toFixed(2)}</div>
                  </div>
                </Link>
              ))}
            </div>
          )}
        </div>
      ) : null}
    </div>
  )
}
