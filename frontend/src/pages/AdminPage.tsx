import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useMemo, useRef, useState } from 'react'
import { Bar, BarChart, CartesianGrid, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts'
import { Link } from 'react-router-dom'
import { apiFetch, apiPatchCsrf, apiPostCsrf, apiPostMultipartCsrf, apiPutCsrf } from '../api'
import { useAuth } from '../context/AuthContext'
import { qk } from '../lib/queryKeys'

type Summary = {
  orders_by_status: Record<string, number>
  revenue_fulfilled_pipeline_usd: number
  users_total: number
  products_total: number
  categories_active: number
  low_stock_variants_under_6: number
}

type OrderRow = {
  id: number
  order_number: string
  status: string
  grand_total: number
  currency: string
  created_at: string
  line_count: number
  customer: { email: string; first_name: string; last_name: string }
}

type AdminCategory = {
  id: number
  parent_id: number | null
  name: string
  slug: string
  description: string | null
  sort_order: number
  is_active: number | string
}

type AdminProduct = {
  id: number
  category_id: number
  name: string
  slug: string
  price: string | number
  discount_price: string | number | null
  availability_status: string
  is_featured: number | string
  is_trending: number | string
  category_name: string | null
}

type HomepageSectionRow = {
  id: number
  title: string
  category_id: number | null
  display_order: number
  is_active: number | string
  category_slug: string | null
  category_name: string | null
}

type AdminUserRow = {
  id: number
  email: string
  first_name: string
  last_name: string
  is_active: boolean
  theme_preference: string
  role_id: number
  role_slug: string
  role_name: string
  created_at: string | null
  last_login_at: string | null
}

type AnalyticsSummary = {
  since: string
  events_by_name: { event_name: string; count: number }[]
  recent_events: {
    id: number
    user_id: number | null
    event_name: string
    entity_type: string | null
    entity_id: number | null
    created_at: string
  }[]
}

type AdminTab = 'overview' | 'categories' | 'products' | 'homepage' | 'users' | 'analytics'

function numActive(v: number | string | boolean): boolean {
  if (typeof v === 'boolean') {
    return v
  }

  return Number(v) === 1
}

export default function AdminPage() {
  const { user, loading } = useAuth()
  const qc = useQueryClient()
  const [tab, setTab] = useState<AdminTab>('overview')
  const [status, setStatus] = useState('pending_approval')
  const [prodPage, setProdPage] = useState(1)
  const [prodQ, setProdQ] = useState('')
  const [prodSearchInput, setProdSearchInput] = useState('')
  const [userPage, setUserPage] = useState(1)
  const [userQ, setUserQ] = useState('')
  const [userSearchInput, setUserSearchInput] = useState('')
  const [uploadPid, setUploadPid] = useState('')
  const [uploadPrimary, setUploadPrimary] = useState(false)
  const uploadFileRef = useRef<HTMLInputElement | null>(null)
  const [newCat, setNewCat] = useState({ name: '', slug: '', sort_order: '0', is_active: true })
  const [newProd, setNewProd] = useState({
    category_id: '',
    name: '',
    slug: '',
    price: '',
    discount_price: '',
    availability_status: 'in_stock',
    is_featured: false,
    is_trending: false,
  })

  const ordersQs = useMemo(() => {
    const p = new URLSearchParams()
    p.set('status', status)
    p.set('page', '1')
    p.set('per_page', '30')
    return p.toString()
  }, [status])

  const productsQs = useMemo(() => {
    const p = new URLSearchParams()
    p.set('page', String(prodPage))
    p.set('per_page', '15')
    if (prodQ.trim() !== '') {
      p.set('q', prodQ.trim())
    }

    return p.toString()
  }, [prodPage, prodQ])

  const usersQs = useMemo(() => {
    const p = new URLSearchParams()
    p.set('page', String(userPage))
    p.set('per_page', '15')
    if (userQ.trim() !== '') {
      p.set('q', userQ.trim())
    }

    return p.toString()
  }, [userPage, userQ])

  const sumQ = useQuery({
    queryKey: qk.adminSummary,
    enabled: user?.role === 'admin',
    queryFn: async () => {
      const res = await apiFetch<Summary>('/admin/summary', { method: 'GET' })
      if (!res.ok || !res.data) {
        throw new Error(res.error?.message ?? 'Unable to load summary.')
      }

      return res.data
    },
  })

  const ordQ = useQuery({
    queryKey: qk.adminOrders(ordersQs),
    enabled: user?.role === 'admin',
    queryFn: async () => {
      const res = await apiFetch<{ items: OrderRow[]; total: number }>(`/admin/orders?${ordersQs}`, { method: 'GET' })
      if (!res.ok || !res.data) {
        throw new Error(res.error?.message ?? 'Unable to load orders.')
      }

      return res.data
    },
  })

  const catQ = useQuery({
    queryKey: qk.adminCategories,
    enabled: user?.role === 'admin' && tab === 'categories',
    queryFn: async () => {
      const res = await apiFetch<{ items: AdminCategory[] }>('/admin/categories', { method: 'GET' })
      if (!res.ok || !res.data) {
        throw new Error(res.error?.message ?? 'Unable to load categories.')
      }

      return res.data.items
    },
  })

  const prodQry = useQuery({
    queryKey: qk.adminProducts(productsQs),
    enabled: user?.role === 'admin' && tab === 'products',
    queryFn: async () => {
      const res = await apiFetch<{ items: AdminProduct[]; total: number; page: number; per_page: number }>(
        `/admin/products?${productsQs}`,
        { method: 'GET' },
      )
      if (!res.ok || !res.data) {
        throw new Error(res.error?.message ?? 'Unable to load products.')
      }

      return res.data
    },
  })

  const usersQ = useQuery({
    queryKey: qk.adminUsers(usersQs),
    enabled: user?.role === 'admin' && tab === 'users',
    queryFn: async () => {
      const res = await apiFetch<{ items: AdminUserRow[]; total: number; page: number; per_page: number }>(
        `/admin/users?${usersQs}`,
        { method: 'GET' },
      )
      if (!res.ok || !res.data) {
        throw new Error(res.error?.message ?? 'Unable to load users.')
      }

      return res.data
    },
  })

  const analyticsQ = useQuery({
    queryKey: qk.adminAnalyticsSummary,
    enabled: user?.role === 'admin' && tab === 'analytics',
    queryFn: async () => {
      const res = await apiFetch<AnalyticsSummary>('/admin/analytics/summary', { method: 'GET' })
      if (!res.ok || !res.data) {
        throw new Error(res.error?.message ?? 'Unable to load analytics.')
      }

      return res.data
    },
  })

  const hpQ = useQuery({
    queryKey: qk.adminHomepageSections,
    enabled: user?.role === 'admin' && tab === 'homepage',
    queryFn: async () => {
      const res = await apiFetch<{ items: HomepageSectionRow[] }>('/admin/homepage/sections', { method: 'GET' })
      if (!res.ok || !res.data) {
        throw new Error(res.error?.message ?? 'Unable to load homepage sections.')
      }

      return res.data.items
    },
  })

  /** `null` = follow server section order; non-null = local reorder before save */
  const [hpEditedIds, setHpEditedIds] = useState<number[] | null>(null)

  const patchCategory = useMutation({
    mutationFn: async ({ id, body }: { id: number; body: Record<string, unknown> }) => {
      const res = await apiPatchCsrf<{ category: AdminCategory }>(`/admin/categories/${id}`, body)
      if (!res.ok) {
        throw new Error(res.error?.message ?? 'Save failed.')
      }

      return res.data
    },
    onSuccess: () => {
      void qc.invalidateQueries({ queryKey: qk.adminCategories })
      void qc.invalidateQueries({ queryKey: qk.adminSummary })
      void qc.invalidateQueries({ queryKey: qk.catalogCategories })
    },
  })

  const createCategory = useMutation({
    mutationFn: async (body: Record<string, unknown>) => {
      const res = await apiPostCsrf<{ category: AdminCategory }>('/admin/categories', body)
      if (!res.ok) {
        throw new Error(res.error?.message ?? 'Create failed.')
      }

      return res.data
    },
    onSuccess: () => {
      void qc.invalidateQueries({ queryKey: qk.adminCategories })
      void qc.invalidateQueries({ queryKey: qk.adminSummary })
      void qc.invalidateQueries({ queryKey: qk.catalogCategories })
    },
  })

  const patchProduct = useMutation({
    mutationFn: async ({ id, body }: { id: number; body: Record<string, unknown> }) => {
      const res = await apiPatchCsrf<{ product: AdminProduct }>(`/admin/products/${id}`, body)
      if (!res.ok) {
        throw new Error(res.error?.message ?? 'Save failed.')
      }

      return res.data
    },
    onSuccess: () => {
      void qc.invalidateQueries({ queryKey: ['admin', 'products'] })
      void qc.invalidateQueries({ queryKey: qk.adminSummary })
      void qc.invalidateQueries({ queryKey: ['catalog', 'products'] })
    },
  })

  const createProduct = useMutation({
    mutationFn: async (body: Record<string, unknown>) => {
      const res = await apiPostCsrf<{ product: AdminProduct }>('/admin/products', body)
      if (!res.ok) {
        throw new Error(res.error?.message ?? 'Create failed.')
      }

      return res.data
    },
    onSuccess: () => {
      void qc.invalidateQueries({ queryKey: ['admin', 'products'] })
      void qc.invalidateQueries({ queryKey: qk.adminSummary })
      void qc.invalidateQueries({ queryKey: ['catalog', 'products'] })
    },
  })

  const reorderHp = useMutation({
    mutationFn: async (ids: number[]) => {
      const res = await apiPutCsrf<{ items: HomepageSectionRow[] }>('/admin/homepage/sections/reorder', { ids })
      if (!res.ok) {
        throw new Error(res.error?.message ?? 'Reorder failed.')
      }

      return res.data
    },
    onSuccess: () => {
      setHpEditedIds(null)
      void qc.invalidateQueries({ queryKey: qk.adminHomepageSections })
      void qc.invalidateQueries({ queryKey: qk.catalogHomepage })
    },
  })

  const patchUser = useMutation({
    mutationFn: async ({ id, body }: { id: number; body: Record<string, unknown> }) => {
      const res = await apiPatchCsrf<{ user: AdminUserRow }>(`/admin/users/${id}`, body)
      if (!res.ok) {
        throw new Error(res.error?.message ?? 'Save failed.')
      }

      return res.data
    },
    onSuccess: () => {
      void qc.invalidateQueries({ queryKey: ['admin', 'users'] })
      void qc.invalidateQueries({ queryKey: qk.adminSummary })
    },
  })

  const uploadProductImage = useMutation({
    mutationFn: async ({ productId, file, isPrimary }: { productId: number; file: File; isPrimary: boolean }) => {
      const fd = new FormData()
      fd.append('product_id', String(productId))
      fd.append('file', file)
      if (isPrimary) {
        fd.append('is_primary', '1')
      }
      const res = await apiPostMultipartCsrf<{ image: { id: number; path: string } }>('/admin/product-images', fd)
      if (!res.ok) {
        throw new Error(res.error?.message ?? 'Upload failed.')
      }

      return res.data
    },
    onSuccess: () => {
      void qc.invalidateQueries({ queryKey: ['admin', 'products'] })
      void qc.invalidateQueries({ queryKey: ['catalog', 'products'] })
      void qc.invalidateQueries({ queryKey: ['catalog', 'product'] })
    },
  })

  if (loading) {
    return (
      <div className="page">
        <p className="muted">Loading…</p>
      </div>
    )
  }

  if (user?.role !== 'admin') {
    return (
      <div className="page">
        <header className="page__head">
          <h1>Admin</h1>
        </header>
        <p className="banner banner--error">Administrator role required.</p>
        <Link className="btn" to="/">
          Home
        </Link>
      </div>
    )
  }

  const s = sumQ.data
  const sErr = sumQ.error instanceof Error ? sumQ.error.message : ''
  const oErr = ordQ.error instanceof Error ? ordQ.error.message : ''
  const rows = ordQ.data?.items ?? []
  const chartData = s
    ? Object.entries(s.orders_by_status).map(([st, count]) => ({
        label: st.replace(/_/g, ' '),
        count,
      }))
    : []

  const serverHpIds = hpQ.data?.map((s) => s.id) ?? []
  const hpWorkingIds = hpEditedIds !== null ? hpEditedIds : serverHpIds

  return (
    <div className="page">
      <header className="page__head">
        <h1>Admin</h1>
        <p className="muted">Phase 7 — catalog, users, analytics, uploads</p>
      </header>

      <p className="muted">
        <Link to="/dev">API dev</Link> · <Link to="/confirmer">Confirmer queue</Link>
      </p>

      <div className="admin-tabs" role="tablist" aria-label="Admin sections">
        {(
          [
            ['overview', 'Overview'],
            ['categories', 'Categories'],
            ['products', 'Products'],
            ['homepage', 'Homepage'],
            ['users', 'Users'],
            ['analytics', 'Analytics'],
          ] as const
        ).map(([id, label]) => (
          <button
            key={id}
            type="button"
            role="tab"
            aria-selected={tab === id}
            className={'admin-tabs__btn' + (tab === id ? ' admin-tabs__btn--active' : '')}
            onClick={() => {
              if (tab === 'homepage' && id !== 'homepage') {
                setHpEditedIds(null)
              }
              setTab(id)
            }}
          >
            {label}
          </button>
        ))}
      </div>

      {tab === 'overview' ? (
        <>
          {sumQ.isPending ? <p className="muted">Loading summary…</p> : null}
          {sErr ? <p className="banner banner--error">{sErr}</p> : null}

          {s ? (
            <div className="admin-kpis">
              <div className="admin-kpi">
                <span className="muted">Revenue (approved→delivered)</span>
                <strong>${s.revenue_fulfilled_pipeline_usd.toFixed(2)}</strong>
              </div>
              <div className="admin-kpi">
                <span className="muted">Products</span>
                <strong>{s.products_total}</strong>
              </div>
              <div className="admin-kpi">
                <span className="muted">Categories (active)</span>
                <strong>{s.categories_active}</strong>
              </div>
              <div className="admin-kpi">
                <span className="muted">Users</span>
                <strong>{s.users_total}</strong>
              </div>
              <div className="admin-kpi admin-kpi--warn">
                <span className="muted">Low-stock variants (&lt;6)</span>
                <strong>{s.low_stock_variants_under_6}</strong>
              </div>
            </div>
          ) : null}

          {s && chartData.length > 0 ? (
            <div className="admin-chart">
              <p className="muted" style={{ marginBottom: '0.5rem' }}>
                Orders by status
              </p>
              <ResponsiveContainer width="100%" height="100%">
                <BarChart data={chartData} margin={{ top: 8, right: 8, left: 0, bottom: 0 }}>
                  <CartesianGrid strokeDasharray="3 3" stroke="var(--border)" />
                  <XAxis dataKey="label" tick={{ fill: 'var(--text-muted)', fontSize: 11 }} interval={0} angle={-18} textAnchor="end" height={70} />
                  <YAxis allowDecimals={false} tick={{ fill: 'var(--text-muted)', fontSize: 11 }} width={36} />
                  <Tooltip
                    contentStyle={{
                      background: 'var(--surface-elevated)',
                      border: '1px solid var(--border)',
                      borderRadius: '8px',
                    }}
                  />
                  <Bar dataKey="count" fill="var(--accent)" radius={[4, 4, 0, 0]} />
                </BarChart>
              </ResponsiveContainer>
            </div>
          ) : null}

          {s ? (
            <div className="admin-status-grid" style={{ marginTop: '1.25rem' }}>
              {Object.entries(s.orders_by_status).map(([st, n]) => (
                <div key={st} className="admin-kpi">
                  <span className="muted">{st.replace(/_/g, ' ')}</span>
                  <strong>{n}</strong>
                </div>
              ))}
            </div>
          ) : null}

          <h2 className="reviews__title" style={{ marginTop: '2rem' }}>
            Orders
          </h2>
          <div className="filters" style={{ marginBottom: '1rem' }}>
            <label className="filters__field">
              <span>Status</span>
              <select value={status} onChange={(e) => setStatus(e.target.value)}>
                <option value="all">All</option>
                <option value="pending_approval">Pending approval</option>
                <option value="approved">Approved</option>
                <option value="rejected">Rejected</option>
                <option value="shipped">Shipped</option>
                <option value="delivered">Delivered</option>
              </select>
            </label>
          </div>

          {ordQ.isPending ? <p className="muted">Loading orders…</p> : null}
          {oErr ? <p className="banner banner--error">{oErr}</p> : null}

          <div className="order-list">
            {rows.map((o) => (
              <div key={o.id} className="order-list__row" style={{ textDecoration: 'none', color: 'inherit' }}>
                <div>
                  <strong>{o.order_number}</strong>
                  <div className="muted">#{o.id}</div>
                  <div className="muted">
                    {o.customer.first_name} {o.customer.last_name} · {o.customer.email}
                  </div>
                </div>
                <div className="order-list__status">{o.status.replace('_', ' ')}</div>
                <div className="order-list__total">
                  {o.currency} ${o.grand_total.toFixed(2)}
                </div>
                <div className="muted">{o.line_count} items</div>
              </div>
            ))}
          </div>
        </>
      ) : null}

      {tab === 'categories' ? (
        <section>
          <h2 className="reviews__title">Categories</h2>
          <p className="muted">Edit slugs with care — storefront URLs depend on them.</p>

          <form
            className="filters"
            style={{ marginTop: '1rem', flexWrap: 'wrap', gap: '0.75rem' }}
            onSubmit={(e) => {
              e.preventDefault()
              void createCategory.mutateAsync({
                name: newCat.name.trim(),
                slug: newCat.slug.trim(),
                sort_order: Number(newCat.sort_order) || 0,
                is_active: newCat.is_active,
              })
              setNewCat({ name: '', slug: '', sort_order: '0', is_active: true })
            }}
          >
            <label className="filters__field">
              <span>New name</span>
              <input value={newCat.name} onChange={(e) => setNewCat((c) => ({ ...c, name: e.target.value }))} required />
            </label>
            <label className="filters__field">
              <span>New slug</span>
              <input value={newCat.slug} onChange={(e) => setNewCat((c) => ({ ...c, slug: e.target.value }))} required />
            </label>
            <label className="filters__field">
              <span>Sort</span>
              <input
                type="number"
                value={newCat.sort_order}
                onChange={(e) => setNewCat((c) => ({ ...c, sort_order: e.target.value }))}
              />
            </label>
            <label className="filters__field" style={{ alignSelf: 'flex-end' }}>
              <span>Active</span>
              <input
                type="checkbox"
                checked={newCat.is_active}
                onChange={(e) => setNewCat((c) => ({ ...c, is_active: e.target.checked }))}
              />
            </label>
            <button type="submit" className="btn btn--sm" disabled={createCategory.isPending}>
              Add category
            </button>
          </form>
          {createCategory.isError ? (
            <p className="banner banner--error">{(createCategory.error as Error).message}</p>
          ) : null}

          {catQ.isPending ? <p className="muted">Loading…</p> : null}
          {catQ.error instanceof Error ? <p className="banner banner--error">{catQ.error.message}</p> : null}

          <div className="admin-table-wrap">
            <table className="admin-table">
              <thead>
                <tr>
                  <th>ID</th>
                  <th>Name</th>
                  <th>Slug</th>
                  <th>Sort</th>
                  <th>Active</th>
                  <th />
                </tr>
              </thead>
              <tbody>
                {(catQ.data ?? []).map((cat) => (
                  <CategoryRow
                    key={`${cat.id}-${catQ.dataUpdatedAt}`}
                    cat={cat}
                    saving={patchCategory.isPending}
                    onSave={(body) => patchCategory.mutateAsync({ id: cat.id, body })}
                  />
                ))}
              </tbody>
            </table>
          </div>
          {patchCategory.isError ? (
            <p className="banner banner--error">{(patchCategory.error as Error).message}</p>
          ) : null}
        </section>
      ) : null}

      {tab === 'products' ? (
        <section>
          <h2 className="reviews__title">Products</h2>
          <form
            className="filters"
            style={{ flexWrap: 'wrap', gap: '0.75rem' }}
            onSubmit={(e) => {
              e.preventDefault()
              setProdPage(1)
              setProdQ(prodSearchInput)
            }}
          >
            <label className="filters__field">
              <span>Search</span>
              <input value={prodSearchInput} onChange={(e) => setProdSearchInput(e.target.value)} placeholder="name, slug…" />
            </label>
            <button type="submit" className="btn btn--sm" style={{ alignSelf: 'flex-end' }}>
              Search
            </button>
          </form>

          <form
            className="filters"
            style={{ marginTop: '1rem', flexWrap: 'wrap', gap: '0.75rem' }}
            onSubmit={(e) => {
              e.preventDefault()
              const cid = Number(newProd.category_id)
              const price = Number(newProd.price)
              void createProduct.mutateAsync({
                category_id: cid,
                name: newProd.name.trim(),
                slug: newProd.slug.trim(),
                price,
                discount_price: newProd.discount_price === '' ? null : Number(newProd.discount_price),
                availability_status: newProd.availability_status,
                is_featured: newProd.is_featured,
                is_trending: newProd.is_trending,
              })
              setNewProd({
                category_id: '',
                name: '',
                slug: '',
                price: '',
                discount_price: '',
                availability_status: 'in_stock',
                is_featured: false,
                is_trending: false,
              })
            }}
          >
            <label className="filters__field">
              <span>Category id</span>
              <input
                type="number"
                min={1}
                value={newProd.category_id}
                onChange={(e) => setNewProd((p) => ({ ...p, category_id: e.target.value }))}
                required
              />
            </label>
            <label className="filters__field">
              <span>Name</span>
              <input value={newProd.name} onChange={(e) => setNewProd((p) => ({ ...p, name: e.target.value }))} required />
            </label>
            <label className="filters__field">
              <span>Slug</span>
              <input value={newProd.slug} onChange={(e) => setNewProd((p) => ({ ...p, slug: e.target.value }))} required />
            </label>
            <label className="filters__field">
              <span>Price</span>
              <input
                type="number"
                step="0.01"
                min={0}
                value={newProd.price}
                onChange={(e) => setNewProd((p) => ({ ...p, price: e.target.value }))}
                required
              />
            </label>
            <label className="filters__field">
              <span>Discount</span>
              <input
                type="number"
                step="0.01"
                min={0}
                value={newProd.discount_price}
                onChange={(e) => setNewProd((p) => ({ ...p, discount_price: e.target.value }))}
              />
            </label>
            <label className="filters__field">
              <span>Availability</span>
              <select
                value={newProd.availability_status}
                onChange={(e) => setNewProd((p) => ({ ...p, availability_status: e.target.value }))}
              >
                <option value="in_stock">in_stock</option>
                <option value="out_of_stock">out_of_stock</option>
                <option value="preorder">preorder</option>
              </select>
            </label>
            <label className="filters__field" style={{ alignSelf: 'flex-end' }}>
              <span>Featured</span>
              <input
                type="checkbox"
                checked={newProd.is_featured}
                onChange={(e) => setNewProd((p) => ({ ...p, is_featured: e.target.checked }))}
              />
            </label>
            <label className="filters__field" style={{ alignSelf: 'flex-end' }}>
              <span>Trending</span>
              <input
                type="checkbox"
                checked={newProd.is_trending}
                onChange={(e) => setNewProd((p) => ({ ...p, is_trending: e.target.checked }))}
              />
            </label>
            <button type="submit" className="btn btn--sm" disabled={createProduct.isPending} style={{ alignSelf: 'flex-end' }}>
              Add product
            </button>
          </form>
          {createProduct.isError ? (
            <p className="banner banner--error">{(createProduct.error as Error).message}</p>
          ) : null}

          {prodQry.isPending ? <p className="muted">Loading…</p> : null}
          {prodQry.error instanceof Error ? <p className="banner banner--error">{prodQry.error.message}</p> : null}

          <div className="admin-table-wrap">
            <table className="admin-table">
              <thead>
                <tr>
                  <th>ID</th>
                  <th>Name</th>
                  <th>Slug</th>
                  <th>Cat</th>
                  <th>Price</th>
                  <th>Avail</th>
                  <th>Feat</th>
                  <th>Trend</th>
                  <th />
                </tr>
              </thead>
              <tbody>
                {(prodQry.data?.items ?? []).map((p) => (
                  <ProductRow
                    key={`${p.id}-${prodQry.dataUpdatedAt}`}
                    product={p}
                    saving={patchProduct.isPending}
                    onSave={(body) => patchProduct.mutateAsync({ id: p.id, body })}
                  />
                ))}
              </tbody>
            </table>
          </div>

          {prodQry.data ? (
            <p className="muted" style={{ marginTop: '0.75rem' }}>
              Page {prodQry.data.page} · {prodQry.data.total} total
              <button
                type="button"
                className="btn btn--ghost btn--sm"
                style={{ marginLeft: '0.75rem' }}
                disabled={prodPage <= 1 || prodQry.isPending}
                onClick={() => setProdPage((x) => Math.max(1, x - 1))}
              >
                Prev
              </button>
              <button
                type="button"
                className="btn btn--ghost btn--sm"
                style={{ marginLeft: '0.35rem' }}
                disabled={prodPage * prodQry.data.per_page >= prodQry.data.total || prodQry.isPending}
                onClick={() => setProdPage((x) => x + 1)}
              >
                Next
              </button>
            </p>
          ) : null}
          {patchProduct.isError ? (
            <p className="banner banner--error">{(patchProduct.error as Error).message}</p>
          ) : null}

          <h3 className="reviews__title" style={{ marginTop: '1.75rem', fontSize: '1.05rem' }}>
            Upload product image
          </h3>
          <p className="muted">JPEG, PNG, or WebP (about 2.5 MB max). Paths are stored as <code>/uploads/products/…</code>.</p>
          <form
            className="filters"
            style={{ marginTop: '0.75rem', flexWrap: 'wrap', gap: '0.75rem', alignItems: 'flex-end' }}
            onSubmit={(e) => {
              e.preventDefault()
              const pid = Number(uploadPid)
              const input = uploadFileRef.current
              const file = input?.files?.[0]
              if (!file || pid <= 0) {
                return
              }
              void uploadProductImage.mutateAsync({ productId: pid, file, isPrimary: uploadPrimary }).then(() => {
                setUploadPid('')
                setUploadPrimary(false)
                if (input) {
                  input.value = ''
                }
              })
            }}
          >
            <label className="filters__field">
              <span>Product id</span>
              <input type="number" min={1} value={uploadPid} onChange={(e) => setUploadPid(e.target.value)} required />
            </label>
            <label className="filters__field">
              <span>Image file</span>
              <input ref={uploadFileRef} type="file" accept="image/jpeg,image/png,image/webp" required />
            </label>
            <label className="filters__field">
              <span>Set primary</span>
              <input type="checkbox" checked={uploadPrimary} onChange={(e) => setUploadPrimary(e.target.checked)} />
            </label>
            <button type="submit" className="btn btn--sm" disabled={uploadProductImage.isPending}>
              Upload
            </button>
          </form>
          {uploadProductImage.isError ? (
            <p className="banner banner--error">{(uploadProductImage.error as Error).message}</p>
          ) : null}
        </section>
      ) : null}

      {tab === 'users' ? (
        <section>
          <h2 className="reviews__title">Users</h2>
          <p className="muted">Role and active flag; you cannot demote or deactivate yourself or the last admin.</p>
          <form
            className="filters"
            style={{ flexWrap: 'wrap', gap: '0.75rem' }}
            onSubmit={(e) => {
              e.preventDefault()
              setUserPage(1)
              setUserQ(userSearchInput)
            }}
          >
            <label className="filters__field">
              <span>Search</span>
              <input
                value={userSearchInput}
                onChange={(e) => setUserSearchInput(e.target.value)}
                placeholder="email, name, role…"
              />
            </label>
            <button type="submit" className="btn btn--sm" style={{ alignSelf: 'flex-end' }}>
              Search
            </button>
          </form>

          {usersQ.isPending ? <p className="muted">Loading…</p> : null}
          {usersQ.error instanceof Error ? <p className="banner banner--error">{usersQ.error.message}</p> : null}

          <div className="admin-table-wrap" style={{ marginTop: '0.75rem' }}>
            <table className="admin-table">
              <thead>
                <tr>
                  <th>ID</th>
                  <th>Email</th>
                  <th>Name</th>
                  <th>Role</th>
                  <th>Theme</th>
                  <th>Active</th>
                  <th />
                </tr>
              </thead>
              <tbody>
                {(usersQ.data?.items ?? []).map((u) => (
                  <UserRow
                    key={`${u.id}-${usersQ.dataUpdatedAt}`}
                    u={u}
                    saving={patchUser.isPending}
                    onSave={(body) => patchUser.mutateAsync({ id: u.id, body })}
                  />
                ))}
              </tbody>
            </table>
          </div>

          {usersQ.data ? (
            <p className="muted" style={{ marginTop: '0.75rem' }}>
              Page {usersQ.data.page} · {usersQ.data.total} total
              <button
                type="button"
                className="btn btn--ghost btn--sm"
                style={{ marginLeft: '0.75rem' }}
                disabled={userPage <= 1 || usersQ.isPending}
                onClick={() => setUserPage((x) => Math.max(1, x - 1))}
              >
                Prev
              </button>
              <button
                type="button"
                className="btn btn--ghost btn--sm"
                style={{ marginLeft: '0.35rem' }}
                disabled={userPage * usersQ.data.per_page >= usersQ.data.total || usersQ.isPending}
                onClick={() => setUserPage((x) => x + 1)}
              >
                Next
              </button>
            </p>
          ) : null}
          {patchUser.isError ? <p className="banner banner--error">{(patchUser.error as Error).message}</p> : null}
        </section>
      ) : null}

      {tab === 'analytics' ? (
        <section>
          <h2 className="reviews__title">Analytics</h2>
          <p className="muted">Last 7 days of <code>analytics_events</code> (e.g. home <code>page_view</code> from the storefront).</p>
          {analyticsQ.isPending ? <p className="muted">Loading…</p> : null}
          {analyticsQ.error instanceof Error ? <p className="banner banner--error">{analyticsQ.error.message}</p> : null}
          {analyticsQ.data && analyticsQ.data.events_by_name.length > 0 ? (
            <div className="admin-chart" style={{ marginTop: '0.75rem' }}>
              <ResponsiveContainer width="100%" height="100%">
                <BarChart
                  data={analyticsQ.data.events_by_name.map((r) => ({ label: r.event_name, count: r.count }))}
                  margin={{ top: 8, right: 8, left: 0, bottom: 0 }}
                >
                  <CartesianGrid strokeDasharray="3 3" stroke="var(--border)" />
                  <XAxis dataKey="label" tick={{ fill: 'var(--text-muted)', fontSize: 11 }} interval={0} angle={-18} textAnchor="end" height={70} />
                  <YAxis allowDecimals={false} tick={{ fill: 'var(--text-muted)', fontSize: 11 }} width={36} />
                  <Tooltip
                    contentStyle={{
                      background: 'var(--surface-elevated)',
                      border: '1px solid var(--border)',
                      borderRadius: '8px',
                    }}
                  />
                  <Bar dataKey="count" fill="var(--mint)" radius={[4, 4, 0, 0]} />
                </BarChart>
              </ResponsiveContainer>
            </div>
          ) : analyticsQ.data ? (
            <p className="muted" style={{ marginTop: '1rem' }}>
              No events in this window yet.
            </p>
          ) : null}
          {analyticsQ.data && analyticsQ.data.recent_events.length > 0 ? (
            <>
              <h3 className="reviews__title" style={{ marginTop: '1.5rem', fontSize: '1.05rem' }}>
                Recent events
              </h3>
              <div className="admin-table-wrap">
                <table className="admin-table">
                  <thead>
                    <tr>
                      <th>ID</th>
                      <th>When</th>
                      <th>Event</th>
                      <th>User</th>
                      <th>Entity</th>
                    </tr>
                  </thead>
                  <tbody>
                    {analyticsQ.data.recent_events.map((ev) => (
                      <tr key={ev.id}>
                        <td>{ev.id}</td>
                        <td className="muted">{ev.created_at}</td>
                        <td>{ev.event_name}</td>
                        <td>{ev.user_id ?? '—'}</td>
                        <td className="muted">
                          {ev.entity_type ?? '—'} {ev.entity_id != null ? `#${ev.entity_id}` : ''}
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            </>
          ) : null}
        </section>
      ) : null}

      {tab === 'homepage' ? (
        <section>
          <h2 className="reviews__title">Homepage sections</h2>
          <p className="muted">Reorder blocks on the storefront home. Save applies display order.</p>
          {hpQ.isPending ? <p className="muted">Loading…</p> : null}
          {hpQ.error instanceof Error ? <p className="banner banner--error">{hpQ.error.message}</p> : null}
          <ol style={{ marginTop: '1rem', paddingLeft: '1.25rem' }}>
            {hpWorkingIds.map((id, idx) => {
              const row = hpQ.data?.find((r) => r.id === id)
              if (!row) {
                return null
              }

              return (
                <li key={id} style={{ marginBottom: '0.65rem' }}>
                  <strong>{row.title}</strong>
                  <span className="muted">
                    {' '}
                    — {row.category_name ?? '—'} ({row.category_slug ?? '—'})
                  </span>
                  <div style={{ marginTop: '0.35rem' }}>
                    <button
                      type="button"
                      className="btn btn--ghost btn--sm"
                      disabled={idx === 0 || reorderHp.isPending}
                      onClick={() => {
                        setHpEditedIds((prev) => {
                          const currentServer = hpQ.data?.map((s) => s.id) ?? []
                          const base = prev !== null ? [...prev] : [...currentServer]
                          if (idx <= 0 || idx >= base.length) {
                            return base
                          }
                          const n = [...base]
                          ;[n[idx - 1], n[idx]] = [n[idx], n[idx - 1]]

                          return n
                        })
                      }}
                    >
                      Up
                    </button>
                    <button
                      type="button"
                      className="btn btn--ghost btn--sm"
                      style={{ marginLeft: '0.35rem' }}
                      disabled={idx >= hpWorkingIds.length - 1 || reorderHp.isPending}
                      onClick={() => {
                        setHpEditedIds((prev) => {
                          const currentServer = hpQ.data?.map((s) => s.id) ?? []
                          const base = prev !== null ? [...prev] : [...currentServer]
                          if (idx >= base.length - 1) {
                            return base
                          }
                          const n = [...base]
                          ;[n[idx + 1], n[idx]] = [n[idx], n[idx + 1]]

                          return n
                        })
                      }}
                    >
                      Down
                    </button>
                  </div>
                </li>
              )
            })}
          </ol>
          <button
            type="button"
            className="btn"
            style={{ marginTop: '0.75rem' }}
            disabled={reorderHp.isPending || hpWorkingIds.length === 0}
            onClick={() => void reorderHp.mutateAsync(hpWorkingIds)}
          >
            Save order
          </button>
          {reorderHp.isError ? <p className="banner banner--error">{(reorderHp.error as Error).message}</p> : null}
        </section>
      ) : null}
    </div>
  )
}

function UserRow({
  u,
  onSave,
  saving,
}: {
  u: AdminUserRow
  onSave: (body: Record<string, unknown>) => Promise<unknown>
  saving: boolean
}) {
  const [roleSlug, setRoleSlug] = useState(u.role_slug)
  const [active, setActive] = useState(u.is_active)
  const [theme, setTheme] = useState(u.theme_preference)

  return (
    <tr>
      <td>{u.id}</td>
      <td>{u.email}</td>
      <td>
        {u.first_name} {u.last_name}
      </td>
      <td>
        <select value={roleSlug} onChange={(e) => setRoleSlug(e.target.value)}>
          <option value="admin">admin</option>
          <option value="confirmer">confirmer</option>
          <option value="customer">customer</option>
        </select>
      </td>
      <td>
        <select value={theme} onChange={(e) => setTheme(e.target.value)}>
          <option value="system">system</option>
          <option value="light">light</option>
          <option value="dark">dark</option>
        </select>
      </td>
      <td>
        <input type="checkbox" checked={active} onChange={(e) => setActive(e.target.checked)} />
      </td>
      <td>
        <button
          type="button"
          className="btn btn--sm"
          disabled={saving}
          onClick={() =>
            void onSave({
              role_slug: roleSlug,
              is_active: active,
              theme_preference: theme,
            })
          }
        >
          Save
        </button>
      </td>
    </tr>
  )
}

function CategoryRow({
  cat,
  onSave,
  saving,
}: {
  cat: AdminCategory
  onSave: (body: Record<string, unknown>) => Promise<unknown>
  saving: boolean
}) {
  const [name, setName] = useState(cat.name)
  const [slug, setSlug] = useState(cat.slug)
  const [sortOrder, setSortOrder] = useState(String(cat.sort_order))
  const [active, setActive] = useState(numActive(cat.is_active))

  return (
    <tr>
      <td>{cat.id}</td>
      <td>
        <input value={name} onChange={(e) => setName(e.target.value)} />
      </td>
      <td>
        <input value={slug} onChange={(e) => setSlug(e.target.value)} />
      </td>
      <td style={{ maxWidth: '5rem' }}>
        <input type="number" value={sortOrder} onChange={(e) => setSortOrder(e.target.value)} />
      </td>
      <td>
        <input type="checkbox" checked={active} onChange={(e) => setActive(e.target.checked)} />
      </td>
      <td>
        <button
          type="button"
          className="btn btn--sm"
          disabled={saving}
          onClick={() =>
            void onSave({
              name: name.trim(),
              slug: slug.trim(),
              sort_order: Number(sortOrder) || 0,
              is_active: active,
            })
          }
        >
          Save
        </button>
      </td>
    </tr>
  )
}

function ProductRow({
  product,
  onSave,
  saving,
}: {
  product: AdminProduct
  onSave: (body: Record<string, unknown>) => Promise<unknown>
  saving: boolean
}) {
  const [name, setName] = useState(product.name)
  const [slug, setSlug] = useState(product.slug)
  const [categoryId, setCategoryId] = useState(String(product.category_id))
  const [price, setPrice] = useState(String(product.price))
  const [avail, setAvail] = useState(product.availability_status)
  const [feat, setFeat] = useState(numActive(product.is_featured))
  const [trend, setTrend] = useState(numActive(product.is_trending))

  return (
    <tr>
      <td>{product.id}</td>
      <td>
        <input value={name} onChange={(e) => setName(e.target.value)} />
      </td>
      <td>
        <input value={slug} onChange={(e) => setSlug(e.target.value)} />
      </td>
      <td style={{ maxWidth: '4rem' }}>
        <input type="number" min={1} value={categoryId} onChange={(e) => setCategoryId(e.target.value)} />
      </td>
      <td style={{ maxWidth: '5rem' }}>
        <input type="number" step="0.01" min={0} value={price} onChange={(e) => setPrice(e.target.value)} />
      </td>
      <td>
        <select value={avail} onChange={(e) => setAvail(e.target.value)}>
          <option value="in_stock">in_stock</option>
          <option value="out_of_stock">out_of_stock</option>
          <option value="preorder">preorder</option>
        </select>
      </td>
      <td>
        <input type="checkbox" checked={feat} onChange={(e) => setFeat(e.target.checked)} />
      </td>
      <td>
        <input type="checkbox" checked={trend} onChange={(e) => setTrend(e.target.checked)} />
      </td>
      <td>
        <button
          type="button"
          className="btn btn--sm"
          disabled={saving}
          onClick={() =>
            void onSave({
              name: name.trim(),
              slug: slug.trim(),
              category_id: Number(categoryId),
              price: Number(price),
              availability_status: avail,
              is_featured: feat,
              is_trending: trend,
            })
          }
        >
          Save
        </button>
      </td>
    </tr>
  )
}
