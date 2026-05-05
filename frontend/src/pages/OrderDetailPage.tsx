import { useQuery } from '@tanstack/react-query'
import { Link, useParams } from 'react-router-dom'
import { apiFetch } from '../api'
import { qk } from '../lib/queryKeys'

type Line = {
  id: number
  product_name: string
  variant_label: string
  unit_price: number
  quantity: number
  line_total: number
}

type Order = {
  id: number
  order_number: string
  status: string
  grand_total: number
  currency: string
  created_at: string
  items: Line[]
}

export default function OrderDetailPage() {
  const { id = '' } = useParams()

  const { data: order, error, isPending } = useQuery({
    queryKey: qk.orderDetail(id),
    enabled: Boolean(id),
    queryFn: async () => {
      const res = await apiFetch<{ order: Order }>(`/orders/${encodeURIComponent(id)}`, { method: 'GET' })
      if (!res.ok || !res.data?.order) {
        throw new Error(res.error?.message ?? 'Order not found.')
      }

      return res.data.order
    },
  })

  const err = error instanceof Error ? error.message : ''

  if (err) {
    return (
      <div className="page">
        <p className="banner banner--error">{err}</p>
        <Link to="/orders">Back to orders</Link>
      </div>
    )
  }

  if (isPending || !order) {
    return (
      <div className="page">
        <p className="muted">Loading…</p>
      </div>
    )
  }

  return (
    <div className="page">
      <p className="muted">
        <Link to="/orders">← Orders</Link>
      </p>
      <header className="page__head">
        <h1>{order.order_number}</h1>
        <p className="muted">{order.status.replace('_', ' ')}</p>
      </header>

      <p className="muted">Placed {new Date(order.created_at).toLocaleString()}</p>

      <div className="cart" style={{ marginTop: '1rem' }}>
        {order.items.map((line) => (
          <div key={line.id} className="cart__row" style={{ gridTemplateColumns: '1fr 90px 90px' }}>
            <div>
              <div style={{ fontWeight: 600 }}>{line.product_name}</div>
              <div className="muted">{line.variant_label}</div>
            </div>
            <div>×{line.quantity}</div>
            <div>${line.line_total.toFixed(2)}</div>
          </div>
        ))}
      </div>

      <div className="cart__summary" style={{ marginTop: '1rem' }}>
        <div>
          <strong>Total</strong>
        </div>
        <div>
          {order.currency} ${order.grand_total.toFixed(2)}
        </div>
      </div>
    </div>
  )
}
