import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { Link, useNavigate } from 'react-router-dom'
import { apiDeleteCsrf, apiFetch, apiPostCsrf, invalidateCsrf } from '../api'
import { useToast } from '../context/ToastContext'
import { qk } from '../lib/queryKeys'

type CartLine = {
  line_id: number
  variant_id: number
  quantity: number
  product_name: string
  product_slug: string
  unit_price: number
  line_total: number
  image_path: string | null
  size: string
  color: string
  stock_quantity: number
}

type CartPayload = {
  lines: CartLine[]
  subtotal: number
  item_count: number
}

export default function CartPage() {
  const navigate = useNavigate()
  const qc = useQueryClient()
  const toast = useToast()
  const [err, setErr] = useState('')
  const [msg, setMsg] = useState('')

  const {
    data: cart,
    error: loadErr,
    isPending,
  } = useQuery({
    queryKey: qk.cart,
    queryFn: async () => {
      const res = await apiFetch<CartPayload>('/cart', { method: 'GET' })
      if (!res.ok || !res.data) {
        throw new Error(res.error?.message ?? 'Unable to load cart.')
      }

      return res.data
    },
  })

  const invalidateCart = () => qc.invalidateQueries({ queryKey: qk.cart })

  const updateMutation = useMutation({
    onMutate: () => setErr(''),
    mutationFn: async ({ variantId: vid, quantity }: { variantId: number; quantity: number }) => {
      const res = await apiPostCsrf<CartPayload>('/cart/items', { variant_id: vid, quantity })
      if (!res.ok || !res.data) {
        throw new Error(res.error?.message ?? 'Could not update line.')
      }

      return res.data
    },
    onSuccess: async () => {
      await invalidateCart()
    },
    onError: (e: Error) => setErr(e.message),
  })

  const removeMutation = useMutation({
    onMutate: () => setErr(''),
    mutationFn: async (vid: number) => {
      const res = await apiDeleteCsrf<CartPayload>(`/cart/items?variant_id=${encodeURIComponent(String(vid))}`)
      if (!res.ok || !res.data) {
        throw new Error(res.error?.message ?? 'Could not remove line.')
      }

      return res.data
    },
    onSuccess: async () => {
      await invalidateCart()
    },
    onError: (e: Error) => setErr(e.message),
  })

  const checkoutMutation = useMutation({
    onMutate: () => {
      setErr('')
      setMsg('')
    },
    mutationFn: async () => {
      invalidateCsrf()
      const res = await apiPostCsrf<{ order: { id: number } }>('/orders', {
        shipping_address: null,
        customer_note: null,
      })
      if (res.ok && res.data?.order?.id) {
        return res.data.order.id
      }
      if (res.error?.code === 'UNAUTHENTICATED') {
        throw new Error('UNAUTHENTICATED')
      }
      throw new Error(res.error?.message ?? 'Checkout failed.')
    },
    onSuccess: async (orderId) => {
      setMsg('Order placed — pending approval.')
      await invalidateCart()
      await qc.invalidateQueries({ queryKey: ['orders'] })
      toast.pushToast('Order submitted for approval.')
      navigate(`/orders/${orderId}`)
    },
    onError: (e: Error) => {
      if (e.message === 'UNAUTHENTICATED') {
        setErr('Sign in first (API dev → Login), then return here to checkout.')
      } else {
        setErr(e.message)
      }
    },
  })

  const loadErrMsg = loadErr instanceof Error ? loadErr.message : ''

  return (
    <div className="page">
      <header className="page__head">
        <h1>Cart</h1>
        <p className="muted">{cart?.item_count ?? 0} items</p>
      </header>

      {isPending ? <p className="muted">Loading cart…</p> : null}
      {loadErrMsg ? <p className="banner banner--error">{loadErrMsg}</p> : null}
      {err ? <p className="banner banner--error">{err}</p> : null}
      {msg ? <p className="banner">{msg}</p> : null}

      {!cart || cart.lines.length === 0 ? (
        !isPending && !loadErrMsg ? (
          <div className="empty">
            <p>Your cart is empty.</p>
            <Link className="btn" to="/products">
              Browse products
            </Link>
          </div>
        ) : null
      ) : (
        <>
          <div className="cart">
            {cart.lines.map((line) => (
              <div key={line.line_id} className="cart__row">
                <div className="cart__thumb">
                  {line.image_path ? <img src={line.image_path} alt="" loading="lazy" /> : <span>No image</span>}
                </div>
                <div className="cart__info">
                  <Link to={`/product/${encodeURIComponent(line.product_slug)}`}>{line.product_name}</Link>
                  <div className="muted">
                    {line.color} · {line.size}
                  </div>
                  <div className="muted">Stock {line.stock_quantity}</div>
                </div>
                <div className="cart__price">${line.unit_price.toFixed(2)}</div>
                <label className="cart__qty">
                  <span className="sr-only">Quantity</span>
                  <input
                    type="number"
                    min={1}
                    max={line.stock_quantity}
                    defaultValue={line.quantity}
                    key={`${line.line_id}-${line.quantity}`}
                    onBlur={(e) => {
                      const next = Math.max(0, Number(e.target.value) || 0)
                      if (next !== line.quantity) {
                        void updateMutation.mutateAsync({ variantId: line.variant_id, quantity: next })
                      }
                    }}
                  />
                </label>
                <div className="cart__line">${line.line_total.toFixed(2)}</div>
                <button
                  type="button"
                  className="btn btn--ghost"
                  disabled={removeMutation.isPending}
                  onClick={() => void removeMutation.mutateAsync(line.variant_id)}
                >
                  Remove
                </button>
              </div>
            ))}
          </div>
          <div className="cart__summary">
            <div>
              <strong>Subtotal</strong>
            </div>
            <div>${cart.subtotal.toFixed(2)}</div>
          </div>
          <div className="pager" style={{ justifyContent: 'flex-start' }}>
            <button
              type="button"
              className="btn"
              disabled={checkoutMutation.isPending}
              onClick={() => void checkoutMutation.mutateAsync()}
            >
              {checkoutMutation.isPending ? 'Placing order…' : 'Place order (pending approval)'}
            </button>
            <Link className="btn btn--ghost" to="/orders">
              View orders
            </Link>
          </div>
          <p className="muted">
            Stock is reserved when you place the order. A confirmer approves or rejects it; rejection restores stock.
          </p>
        </>
      )}
    </div>
  )
}
