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
  const [phone, setPhone] = useState('')
  const [recoveryPhone, setRecoveryPhone] = useState('')
  const [address1, setAddress1] = useState('')
  const [address2, setAddress2] = useState('')
  const [city, setCity] = useState('')
  const [state, setState] = useState('')
  const [postalCode, setPostalCode] = useState('')
  const [country, setCountry] = useState('')
  const [paymentType, setPaymentType] = useState<'cash' | 'visa'>('cash')
  const visaDraft = readVisaDraft()
  const [visaName, setVisaName] = useState(visaDraft.cardholder_name)
  const [visaNumber, setVisaNumber] = useState(visaDraft.card_number)
  const [visaExpMonth, setVisaExpMonth] = useState(visaDraft.exp_month)
  const [visaExpYear, setVisaExpYear] = useState(visaDraft.exp_year)
  const [visaCvv, setVisaCvv] = useState(visaDraft.cvv)

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
      const required = [
        ['Phone number', phone],
        ['Recovery number', recoveryPhone],
        ['Address line 1', address1],
        ['City', city],
        ['State', state],
        ['Postal code', postalCode],
        ['Country', country],
      ] as const
      for (const [label, value] of required) {
        if (value.trim() === '') {
          throw new Error(`${label} is required.`)
        }
      }
      if (paymentType === 'visa') {
        const digits = visaNumber.replace(/\D+/g, '')
        if (visaName.trim() === '') {
          throw new Error('Cardholder name is required.')
        }
        if (digits.length < 13 || digits.length > 19 || !passesLuhn(digits)) {
          throw new Error('Card number is invalid.')
        }
        const m = Number(visaExpMonth)
        const y = Number(visaExpYear)
        const now = new Date()
        if (m < 1 || m > 12 || y < now.getFullYear() || (y === now.getFullYear() && m < now.getMonth() + 1)) {
          throw new Error('Card expiry is invalid.')
        }
        if (!/^\d{3,4}$/.test(visaCvv.trim())) {
          throw new Error('CVV is invalid.')
        }
      }

      invalidateCsrf()
      const res = await apiPostCsrf<{ order: { id: number } }>('/orders', {
        shipping_address: {
          phone_number: phone.trim(),
          recovery_number: recoveryPhone.trim(),
          address_line_1: address1.trim(),
          address_line_2: address2.trim(),
          city: city.trim(),
          state: state.trim(),
          postal_code: postalCode.trim(),
          country: country.trim(),
        },
        payment_type: paymentType,
        visa:
          paymentType === 'visa'
            ? {
                cardholder_name: visaName.trim(),
                card_number: visaNumber,
                exp_month: Number(visaExpMonth),
                exp_year: Number(visaExpYear),
                cvv: visaCvv.trim(),
              }
            : null,
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
          <section className="panel" style={{ marginTop: '1rem' }}>
            <h2 className="panel__title">Checkout details</h2>
            <div className="panel__row">
              <label className="field">
                <span>Phone number</span>
                <input value={phone} onChange={(e) => setPhone(e.target.value)} required />
              </label>
              <label className="field">
                <span>Recovery number</span>
                <input value={recoveryPhone} onChange={(e) => setRecoveryPhone(e.target.value)} required />
              </label>
              <label className="field">
                <span>Address line 1</span>
                <input value={address1} onChange={(e) => setAddress1(e.target.value)} required />
              </label>
              <label className="field">
                <span>Address line 2</span>
                <input value={address2} onChange={(e) => setAddress2(e.target.value)} />
              </label>
              <label className="field">
                <span>City</span>
                <input value={city} onChange={(e) => setCity(e.target.value)} required />
              </label>
              <label className="field">
                <span>State</span>
                <input value={state} onChange={(e) => setState(e.target.value)} required />
              </label>
              <label className="field">
                <span>Postal code</span>
                <input value={postalCode} onChange={(e) => setPostalCode(e.target.value)} required />
              </label>
              <label className="field">
                <span>Country</span>
                <input value={country} onChange={(e) => setCountry(e.target.value)} required />
              </label>
              <label className="field">
                <span>Payment type</span>
                <select value={paymentType} onChange={(e) => setPaymentType(e.target.value as 'cash' | 'visa')}>
                  <option value="cash">Cash</option>
                  <option value="visa">Visa</option>
                </select>
              </label>
            </div>
            {paymentType === 'visa' ? (
              <div className="panel__row" style={{ marginTop: '0.75rem' }}>
                <label className="field">
                  <span>Cardholder name</span>
                  <input value={visaName} onChange={(e) => setVisaName(e.target.value)} required />
                </label>
                <label className="field">
                  <span>Card number</span>
                  <input value={visaNumber} onChange={(e) => setVisaNumber(e.target.value)} inputMode="numeric" required />
                </label>
                <label className="field">
                  <span>Exp month</span>
                  <input value={visaExpMonth} onChange={(e) => setVisaExpMonth(e.target.value)} inputMode="numeric" required />
                </label>
                <label className="field">
                  <span>Exp year</span>
                  <input value={visaExpYear} onChange={(e) => setVisaExpYear(e.target.value)} inputMode="numeric" required />
                </label>
                <label className="field">
                  <span>CVV</span>
                  <input value={visaCvv} onChange={(e) => setVisaCvv(e.target.value)} inputMode="numeric" required />
                </label>
                <div className="field">
                  <span>Visa page</span>
                  <Link className="btn btn--ghost" to="/checkout/visa">
                    Open Visa validation page
                  </Link>
                </div>
              </div>
            ) : null}
          </section>
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

function passesLuhn(digits: string): boolean {
  let sum = 0
  let alt = false
  for (let i = digits.length - 1; i >= 0; i -= 1) {
    let n = Number(digits[i])
    if (alt) {
      n *= 2
      if (n > 9) n -= 9
    }
    sum += n
    alt = !alt
  }
  return sum % 10 === 0
}

function readVisaDraft(): {
  cardholder_name: string
  card_number: string
  exp_month: string
  exp_year: string
  cvv: string
} {
  try {
    const raw = localStorage.getItem('checkoutVisaDraft')
    if (!raw) return { cardholder_name: '', card_number: '', exp_month: '', exp_year: '', cvv: '' }
    const parsed = JSON.parse(raw) as Partial<{
      cardholder_name: string
      card_number: string
      exp_month: string
      exp_year: string
      cvv: string
    }>
    return {
      cardholder_name: parsed.cardholder_name ?? '',
      card_number: parsed.card_number ?? '',
      exp_month: parsed.exp_month ?? '',
      exp_year: parsed.exp_year ?? '',
      cvv: parsed.cvv ?? '',
    }
  } catch {
    return { cardholder_name: '', card_number: '', exp_month: '', exp_year: '', cvv: '' }
  }
}
