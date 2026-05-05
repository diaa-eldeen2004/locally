import { useQuery } from '@tanstack/react-query'
import { useEffect, useRef } from 'react'
import { Link } from 'react-router-dom'
import { apiFetch, apiPostCsrf } from '../api'
import { qk } from '../lib/queryKeys'

type ProductCard = {
  id: number
  name: string
  slug: string
  effective_price: number
  image_path: string | null
  is_featured?: boolean
  is_trending?: boolean
  average_rating?: number
  review_count?: number
}

type HomeSection = {
  id: number
  title: string
  products: ProductCard[]
  category: { slug: string; name: string } | null
}

export default function HomePage() {
  const tracked = useRef(false)
  useEffect(() => {
    if (tracked.current) {
      return
    }
    tracked.current = true
    void apiPostCsrf('/analytics/track', {
      event_name: 'page_view',
      entity_type: 'page',
      properties: { path: '/' },
    }).catch(() => {})
  }, [])

  const { data: sections = [], error, isPending } = useQuery({
    queryKey: qk.catalogHomepage,
    queryFn: async () => {
      const res = await apiFetch<{ sections: HomeSection[] }>('/catalog/homepage', { method: 'GET' })
      if (!res.ok || !res.data?.sections) {
        throw new Error(res.error?.message ?? 'Unable to load homepage.')
      }

      return res.data.sections
    },
  })

  const err = error instanceof Error ? error.message : ''

  return (
    <div className="page">
      <section className="hero">
        <p className="eyebrow">Streetwear</p>
        <h1 className="hero__title">Built close to you.</h1>
        <p className="hero__sub">
          Mint, beige, and black tones — curated sections powered by your MySQL homepage config.
        </p>
        <div className="hero__actions">
          <Link className="btn" to="/products">
            Browse everything
          </Link>
          <Link className="btn btn--ghost" to="/products?sort=price_desc">
            Shop drops
          </Link>
        </div>
      </section>

      {isPending ? <p className="muted">Loading homepage…</p> : null}
      {err ? <p className="banner banner--error">{err}</p> : null}

      {sections.map((section) => (
        <section key={section.id} className="rail">
          <div className="rail__head">
            <h2>{section.title}</h2>
            {section.category?.slug ? (
              <Link className="rail__link" to={`/products?category=${encodeURIComponent(section.category.slug)}`}>
                View category
              </Link>
            ) : null}
          </div>
          <div className="rail__track" role="list">
            {section.products.length === 0 ? (
              <p className="muted">No products in this section yet.</p>
            ) : (
              section.products.map((p) => (
                <Link key={p.id} className="p-card p-card--lift" to={`/product/${encodeURIComponent(p.slug)}`} role="listitem">
                  <div className="p-card__img" aria-hidden>
                    {p.image_path ? <img src={p.image_path} alt="" loading="lazy" /> : <span>No image</span>}
                    <div className="p-card__badges">
                      {p.is_featured ? <span className="chip chip--featured">Featured</span> : null}
                      {p.is_trending ? <span className="chip chip--trending">Trending</span> : null}
                    </div>
                  </div>
                  <div className="p-card__meta">
                    <div className="p-card__name">{p.name}</div>
                    <div className="p-card__row">
                      <div className="p-card__price">${p.effective_price.toFixed(2)}</div>
                      {(p.review_count ?? 0) > 0 ? (
                        <div className="p-card__rating muted">★ {(p.average_rating ?? 0).toFixed(1)}</div>
                      ) : null}
                    </div>
                  </div>
                </Link>
              ))
            )}
          </div>
        </section>
      ))}
    </div>
  )
}
