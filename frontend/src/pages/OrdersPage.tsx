import { useQuery } from '@tanstack/react-query'
import { Link } from 'react-router-dom'
import { apiFetch } from '../api'
import { qk } from '../lib/queryKeys'

type OrderRow = {
  id: number
  order_number: string
  status: string
  grand_total: number
  currency: string
  created_at: string
  line_count: number
}

const PAGE = 1
const PER = 50

export default function OrdersPage() {
  const { data, error, isPending } = useQuery({
    queryKey: qk.ordersList(PAGE, PER),
    queryFn: async () => {
      const res = await apiFetch<{ items: OrderRow[]; total: number }>(
        `/orders?page=${PAGE}&per_page=${PER}`,
        { method: 'GET' },
      )
      if (!res.ok || !res.data) {
        const msg = res.error?.message ?? 'Could not load orders.'
        const e = new Error(msg) as Error & { code?: string }
        e.code = res.error?.code

        throw e
      }

      return res.data
    },
  })

  const items = data?.items ?? []
  const total = data?.total ?? 0
  const err = error instanceof Error ? error.message : ''
  const needLogin =
    error instanceof Error &&
    'code' in error &&
    (error as Error & { code?: string }).code === 'UNAUTHENTICATED'

  return (
    <div className="page">
      <header className="page__head">
        <h1>Your orders</h1>
        <p className="muted">{total} total</p>
      </header>

      {isPending ? <p className="muted">Loading orders…</p> : null}

      {err ? (
        <p className="banner banner--error">
          {needLogin ? 'Sign in on API dev to view your orders.' : err}{' '}
          {needLogin ? <Link to="/dev">Open API dev</Link> : null}
        </p>
      ) : null}

      {items.length === 0 && !err && !isPending ? (
        <div className="empty">
          <p>No orders yet.</p>
          <Link className="btn" to="/cart">
            Go to cart
          </Link>
        </div>
      ) : null}

      {items.length > 0 ? (
        <div className="order-list">
          {items.map((o) => (
            <Link key={o.id} className="order-list__row" to={`/orders/${o.id}`}>
              <div>
                <strong>{o.order_number}</strong>
                <div className="muted">{new Date(o.created_at).toLocaleString()}</div>
              </div>
              <div className="order-list__status">{o.status.replace('_', ' ')}</div>
              <div className="order-list__total">
                {o.currency} ${o.grand_total.toFixed(2)}
              </div>
              <div className="muted">{o.line_count} items</div>
            </Link>
          ))}
        </div>
      ) : null}
    </div>
  )
}
