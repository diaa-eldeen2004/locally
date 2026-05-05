import { useQuery } from '@tanstack/react-query'
import { useEffect, useMemo, useState } from 'react'
import { Link, useSearchParams } from 'react-router-dom'
import { apiFetch } from '../api'
import { useDebouncedValue } from '../lib/useDebouncedValue'
import { qk } from '../lib/queryKeys'

type Category = { id: number; name: string; slug: string }

type ProductCard = {
  id: number
  name: string
  slug: string
  effective_price: number
  image_path: string | null
}

export default function ProductsPage() {
  const [params, setParams] = useSearchParams()
  const qUrl = params.get('q') ?? ''
  const [searchBox, setSearchBox] = useState(qUrl)

  useEffect(() => {
    queueMicrotask(() => {
      setSearchBox(qUrl)
    })
  }, [qUrl])

  const debouncedQ = useDebouncedValue(searchBox, 400)

  useEffect(() => {
    if (debouncedQ === qUrl) {
      return
    }
    const next = new URLSearchParams(params)
    if (debouncedQ) {
      next.set('q', debouncedQ)
    } else {
      next.delete('q')
    }
    next.set('page', '1')
    setParams(next, { replace: true })
  }, [debouncedQ, qUrl, params, setParams])

  const page = Math.max(1, Number(params.get('page') ?? '1') || 1)
  const category = params.get('category') ?? ''
  const sort = params.get('sort') ?? 'newest'

  const queryString = useMemo(() => {
    const p = new URLSearchParams()
    p.set('page', String(page))
    p.set('per_page', '24')
    if (debouncedQ) {
      p.set('q', debouncedQ)
    }
    if (category) {
      p.set('category', category)
    }
    if (sort) {
      p.set('sort', sort)
    }

    return p.toString()
  }, [page, debouncedQ, category, sort])

  const { data: categories = [] } = useQuery({
    queryKey: qk.catalogCategories,
    queryFn: async () => {
      const res = await apiFetch<{ categories: Category[] }>('/catalog/categories', { method: 'GET' })
      if (!res.ok || !res.data?.categories) {
        throw new Error(res.error?.message ?? 'Unable to load categories.')
      }

      return res.data.categories
    },
    staleTime: 5 * 60_000,
  })

  const {
    data: listPayload,
    error,
    isPending,
  } = useQuery({
    queryKey: qk.catalogProducts(queryString),
    queryFn: async () => {
      const res = await apiFetch<{ items: ProductCard[]; total: number }>(`/catalog/products?${queryString}`, {
        method: 'GET',
      })
      if (!res.ok || !res.data) {
        throw new Error(res.error?.message ?? 'Unable to load products.')
      }

      return res.data
    },
  })

  const items = listPayload?.items ?? []
  const total = listPayload?.total ?? 0
  const err = error instanceof Error ? error.message : ''

  const setField = (key: string, value: string) => {
    const next = new URLSearchParams(params)
    if (!value) {
      next.delete(key)
    } else {
      next.set(key, value)
    }
    if (key !== 'page') {
      next.set('page', '1')
    }
    setParams(next, { replace: true })
  }

  return (
    <div className="page">
      <header className="page__head">
        <h1>Shop</h1>
        <p className="muted">{total} products</p>
      </header>

      <div className="filters">
        <label className="filters__field">
          <span>Search</span>
          <input
            value={searchBox}
            placeholder="Name or description"
            onChange={(e) => setSearchBox(e.target.value)}
          />
        </label>
        <label className="filters__field">
          <span>Category</span>
          <select value={category} onChange={(e) => setField('category', e.target.value)}>
            <option value="">All</option>
            {categories.map((c) => (
              <option key={c.id} value={c.slug}>
                {c.name}
              </option>
            ))}
          </select>
        </label>
        <label className="filters__field">
          <span>Sort</span>
          <select value={sort} onChange={(e) => setField('sort', e.target.value)}>
            <option value="newest">Newest</option>
            <option value="price_asc">Price · low to high</option>
            <option value="price_desc">Price · high to low</option>
          </select>
        </label>
      </div>

      {isPending ? <p className="muted">Loading products…</p> : null}
      {err ? <p className="banner banner--error">{err}</p> : null}

      <div className="grid">
        {items.map((p) => (
          <Link key={p.id} className="tile tile--lift" to={`/product/${encodeURIComponent(p.slug)}`}>
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

      <div className="pager">
        <button type="button" className="btn btn--ghost" disabled={page <= 1} onClick={() => setField('page', String(page - 1))}>
          Previous
        </button>
        <span className="muted">
          Page {page} / {Math.max(1, Math.ceil(total / 24))}
        </span>
        <button
          type="button"
          className="btn btn--ghost"
          disabled={page >= Math.max(1, Math.ceil(total / 24))}
          onClick={() => setField('page', String(page + 1))}
        >
          Next
        </button>
      </div>
    </div>
  )
}
