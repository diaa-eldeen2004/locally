import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useMemo, useState } from 'react'
import { Link, useParams } from 'react-router-dom'
import { apiDeleteCsrf, apiFetch, apiPostCsrf } from '../api'
import { useAuth } from '../context/AuthContext'
import { useToast } from '../context/ToastContext'
import { qk } from '../lib/queryKeys'

type Product = {
  id: number
  name: string
  slug: string
  description: string | null
  effective_price: number
  availability_status: string
  average_rating: number
  review_count: number
}

type Variant = {
  id: number
  sku: string | null
  size: string
  color: string
  stock_quantity: number
  price_adjustment: number
}

type Image = { id: number; path: string; alt_text: string | null; is_primary: boolean }

type ReviewRow = {
  id: number
  rating: number
  title: string | null
  body: string | null
  created_at: string
  author: { first_name: string }
}

type DetailPayload = {
  product: Product
  images: Image[]
  variants: Variant[]
  reviews: ReviewRow[]
  is_favorite: boolean
}

export default function ProductPage() {
  const { slug = '' } = useParams()

  if (!slug) {
    return (
      <div className="page">
        <p className="banner banner--error">Missing product.</p>
        <Link to="/products">Back to shop</Link>
      </div>
    )
  }

  return <ProductPageBody key={slug} slug={slug} />
}

function ProductPageBody({ slug }: { slug: string }) {
  const qc = useQueryClient()
  const toast = useToast()
  const { user } = useAuth()
  const [variantId, setVariantId] = useState(0)
  const [qty, setQty] = useState(1)
  const [mainImageId, setMainImageId] = useState<number | null>(null)
  const [reviewRating, setReviewRating] = useState(5)
  const [reviewTitle, setReviewTitle] = useState('')
  const [reviewBody, setReviewBody] = useState('')

  const {
    data: detail,
    error: loadErr,
    isPending,
  } = useQuery({
    queryKey: qk.catalogProduct(slug),
    queryFn: async () => {
      const res = await apiFetch<DetailPayload>(`/catalog/products/${encodeURIComponent(slug)}`, { method: 'GET' })
      if (!res.ok || !res.data?.product) {
        throw new Error(res.error?.message ?? 'Product not found.')
      }

      return res.data
    },
  })

  const product = detail?.product ?? null
  const firstVariantId = (detail?.variants ?? [])[0]?.id ?? 0
  const effectiveVariantId = variantId || firstVariantId

  const displayImage = useMemo(() => {
    const images = detail?.images ?? []
    if (images.length === 0) {
      return null
    }
    if (mainImageId != null) {
      const hit = images.find((i) => i.id === mainImageId)
      if (hit) {
        return hit
      }
    }

    return images.find((i) => i.is_primary) ?? images[0]
  }, [detail, mainImageId])

  const selectedVariant = useMemo(() => {
    const variants = detail?.variants ?? []

    return variants.find((x) => x.id === effectiveVariantId) ?? null
  }, [detail, effectiveVariantId])

  const addMutation = useMutation({
    mutationFn: async () => {
      if (!effectiveVariantId) {
        throw new Error('Pick a size / color option.')
      }
      const res = await apiPostCsrf<{ lines: unknown[]; subtotal: number }>('/cart/items', {
        variant_id: effectiveVariantId,
        quantity: qty,
      })
      if (!res.ok) {
        throw new Error(res.error?.message ?? 'Could not update cart.')
      }

      return res.data
    },
    onSuccess: async () => {
      await qc.invalidateQueries({ queryKey: qk.cart })
      toast.pushToast('Added to cart.')
    },
    onError: (e: Error) => {
      toast.pushToast(e.message, 'error')
    },
  })

  const favAdd = useMutation({
    mutationFn: async (productId: number) => {
      const res = await apiPostCsrf<{ saved: boolean }>('/favorites', { product_id: productId })
      if (!res.ok) {
        throw new Error(res.error?.message ?? 'Could not save favorite.')
      }

      return res.data
    },
    onSuccess: async () => {
      await qc.invalidateQueries({ queryKey: qk.catalogProduct(slug) })
      await qc.invalidateQueries({ queryKey: qk.favoritesList })
      toast.pushToast('Saved to favorites.')
    },
    onError: (e: Error) => {
      toast.pushToast(e.message, 'error')
    },
  })

  const favRemove = useMutation({
    mutationFn: async (productId: number) => {
      const res = await apiDeleteCsrf<{ removed: boolean }>(
        `/favorites?product_id=${encodeURIComponent(String(productId))}`,
      )
      if (!res.ok) {
        throw new Error(res.error?.message ?? 'Could not remove favorite.')
      }

      return res.data
    },
    onSuccess: async () => {
      await qc.invalidateQueries({ queryKey: qk.catalogProduct(slug) })
      await qc.invalidateQueries({ queryKey: qk.favoritesList })
      toast.pushToast('Removed from favorites.')
    },
    onError: (e: Error) => {
      toast.pushToast(e.message, 'error')
    },
  })

  const reviewMutation = useMutation({
    mutationFn: async () => {
      if (!product) {
        throw new Error('Missing product.')
      }
      const res = await apiPostCsrf<{ review: unknown }>('/reviews', {
        product_id: product.id,
        rating: reviewRating,
        title: reviewTitle.trim() || null,
        body: reviewBody.trim() || null,
      })
      if (!res.ok) {
        throw new Error(res.error?.message ?? 'Could not post review.')
      }

      return res.data
    },
    onSuccess: async () => {
      setReviewTitle('')
      setReviewBody('')
      setReviewRating(5)
      await qc.invalidateQueries({ queryKey: qk.catalogProduct(slug) })
      toast.pushToast('Review posted.')
    },
    onError: (e: Error) => {
      toast.pushToast(e.message, 'error')
    },
  })

  const unitPrice = useMemo(() => {
    if (!detail?.product) {
      return 0
    }
    const variants = detail.variants ?? []
    const v = variants.find((x) => x.id === effectiveVariantId)
    const adj = v?.price_adjustment ?? 0

    return detail.product.effective_price + adj
  }, [detail, effectiveVariantId])

  const err = loadErr instanceof Error ? loadErr.message : ''

  if (err) {
    return (
      <div className="page">
        <p className="banner banner--error">{err}</p>
        <Link to="/products">Back to shop</Link>
      </div>
    )
  }

  if (isPending || !product) {
    return (
      <div className="page">
        <p className="muted">Loading…</p>
      </div>
    )
  }

  const images = detail?.images ?? []
  const reviews = detail?.reviews ?? []
  const isFav = detail?.is_favorite ?? false
  const stock = selectedVariant?.stock_quantity ?? 0

  return (
    <div className="page product">
      <div className="product__grid">
        <div className="product__media">
          <div className="product__img">
            {displayImage ? (
              <img src={displayImage.path} alt={displayImage.alt_text ?? product.name} loading="lazy" />
            ) : (
              <span>No image</span>
            )}
          </div>
          {images.length > 1 ? (
            <div className="product__thumbs" role="list">
              {images.map((im) => (
                <button
                  key={im.id}
                  type="button"
                  role="listitem"
                  className={`product__thumb ${displayImage?.id === im.id ? 'is-active' : ''}`}
                  onClick={() => setMainImageId(im.id)}
                  aria-label="Show image"
                >
                  <img src={im.path} alt="" loading="lazy" />
                </button>
              ))}
            </div>
          ) : null}
        </div>
        <div>
          <p className="eyebrow">{product.availability_status.replace('_', ' ')}</p>
          <div className="product__title-row">
            <h1>{product.name}</h1>
            {user ? (
              isFav ? (
                <button
                  type="button"
                  className="btn btn--ghost product__fav"
                  disabled={favRemove.isPending}
                  onClick={() => void favRemove.mutateAsync(product.id)}
                  aria-label="Remove from favorites"
                >
                  ♥ Saved
                </button>
              ) : (
                <button
                  type="button"
                  className="btn btn--ghost product__fav"
                  disabled={favAdd.isPending}
                  onClick={() => void favAdd.mutateAsync(product.id)}
                  aria-label="Add to favorites"
                >
                  ♡ Save
                </button>
              )
            ) : (
              <Link className="btn btn--ghost product__fav" to="/dev">
                Sign in to save
              </Link>
            )}
          </div>
          <p className="product__meta muted">
            {product.review_count > 0 ? (
              <>
                ★ {product.average_rating.toFixed(1)} · {product.review_count} review{product.review_count === 1 ? '' : 's'}
              </>
            ) : (
              'No reviews yet'
            )}
          </p>
          <p className="product__price">${unitPrice.toFixed(2)}</p>
          {product.description ? <p className="product__desc">{product.description}</p> : null}

          <div className="product__controls">
            <label className="field">
              <span>Variant</span>
              <select
                value={effectiveVariantId || ''}
                onChange={(e) => setVariantId(Number(e.target.value))}
              >
                {(detail?.variants ?? []).length === 0 ? <option value="">No variants</option> : null}
                {(detail?.variants ?? []).map((v) => (
                  <option key={v.id} value={v.id}>
                    {v.color} · {v.size} · stock {v.stock_quantity}
                    {v.sku ? ` · ${v.sku}` : ''}
                  </option>
                ))}
              </select>
            </label>
            <div className="product__stock-row">
              <span
                className={`stock-badge ${stock <= 0 ? 'stock-badge--out' : stock < 6 ? 'stock-badge--low' : 'stock-badge--ok'}`}
              >
                {stock <= 0 ? 'Out of stock' : stock < 6 ? `Only ${stock} left` : `${stock} in stock`}
              </span>
            </div>
            <label className="field">
              <span>Quantity</span>
              <input
                type="number"
                min={1}
                max={Math.max(1, stock)}
                value={qty}
                onChange={(e) => setQty(Math.max(1, Number(e.target.value) || 1))}
              />
            </label>
            <button
              type="button"
              className="btn"
              disabled={addMutation.isPending || stock <= 0}
              onClick={() => void addMutation.mutateAsync()}
            >
              {addMutation.isPending ? 'Adding…' : 'Add to cart'}
            </button>
          </div>

          <p className="muted" style={{ marginTop: '1rem' }}>
            <Link to="/cart">View cart</Link> · <Link to="/products">Continue shopping</Link>
          </p>
        </div>
      </div>

      <section className="reviews" aria-label="Customer reviews">
        <h2 className="reviews__title">Reviews</h2>
        {user ? (
          <form
            className="review-form"
            onSubmit={(e) => {
              e.preventDefault()
              void reviewMutation.mutateAsync()
            }}
          >
            <p className="muted" style={{ marginBottom: '0.75rem' }}>
              One review per product per account. Sign in on API dev if needed.
            </p>
            <label className="field">
              <span>Rating</span>
              <select value={reviewRating} onChange={(e) => setReviewRating(Number(e.target.value))}>
                <option value={5}>5 — Excellent</option>
                <option value={4}>4</option>
                <option value={3}>3</option>
                <option value={2}>2</option>
                <option value={1}>1</option>
              </select>
            </label>
            <label className="field">
              <span>Title (optional)</span>
              <input value={reviewTitle} onChange={(e) => setReviewTitle(e.target.value)} maxLength={200} />
            </label>
            <label className="field">
              <span>Review (optional)</span>
              <textarea value={reviewBody} onChange={(e) => setReviewBody(e.target.value)} rows={3} maxLength={4000} />
            </label>
            <button type="submit" className="btn" disabled={reviewMutation.isPending}>
              {reviewMutation.isPending ? 'Posting…' : 'Post review'}
            </button>
          </form>
        ) : (
          <p className="muted">
            <Link to="/dev">Sign in</Link> to write a review.
          </p>
        )}
        {reviews.length > 0 ? (
          <ul className="reviews__list">
            {reviews.map((r) => (
              <li key={r.id} className="reviews__item">
                <div className="reviews__stars">{'★'.repeat(r.rating)}{'☆'.repeat(5 - r.rating)}</div>
                {r.title ? <div className="reviews__head">{r.title}</div> : null}
                <p className="reviews__body">{r.body ?? ''}</p>
                <p className="reviews__meta muted">
                  {r.author.first_name || 'Customer'} · {new Date(r.created_at).toLocaleDateString()}
                </p>
              </li>
            ))}
          </ul>
        ) : (
          <p className="muted">No reviews yet — be the first.</p>
        )}
      </section>
    </div>
  )
}
