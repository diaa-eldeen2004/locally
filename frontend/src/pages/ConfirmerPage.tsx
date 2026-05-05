import { useMutation, useQuery, useQueryClient, type UseMutationResult } from '@tanstack/react-query'
import { useMemo, useState } from 'react'
import { Link } from 'react-router-dom'
import { apiFetch, apiPostCsrf } from '../api'
import { useAuth } from '../context/AuthContext'
import { useToast } from '../context/ToastContext'
import { useDebouncedValue } from '../lib/useDebouncedValue'
import { qk } from '../lib/queryKeys'

type QueueRow = {
  id: number
  order_number: string
  status: string
  grand_total: number
  currency: string
  created_at: string
  line_count: number
  customer: { email: string; first_name: string; last_name: string }
}

type StaffLine = {
  id: number
  product_id: number
  variant_id: number | null
  product_name: string
  variant_label: string
  unit_price: number
  quantity: number
  line_total: number
}

type StaffOrder = {
  id: number
  order_number: string
  status: string
  currency: string
  subtotal: number
  tax_total: number
  shipping_total: number
  grand_total: number
  customer_note: string | null
  internal_note: string | null
  created_at: string
  customer: { email: string; first_name: string; last_name: string }
  items: StaffLine[]
}

function statusLabel(s: string): string {
  return s.replace(/_/g, ' ')
}

type ApproveVars = { id: number; note: string | null }
type RejectVars = { id: number; reason: string }

function ConfirmerOrderQueueList({
  status,
  debouncedQ,
  searchInput,
  onSearchInputChange,
  onStatusChange,
  selectedId,
  onSelectId,
  approveMut,
  rejectMut,
}: {
  status: string
  debouncedQ: string
  searchInput: string
  onSearchInputChange: (v: string) => void
  onStatusChange: (s: string) => void
  selectedId: number | null
  onSelectId: (id: number) => void
  approveMut: UseMutationResult<unknown, Error, ApproveVars, unknown>
  rejectMut: UseMutationResult<unknown, Error, RejectVars, unknown>
}) {
  const [page, setPage] = useState(1)
  const perPage = 20

  const listQs = useMemo(() => {
    const p = new URLSearchParams()
    p.set('status', status)
    p.set('page', String(page))
    p.set('per_page', String(perPage))
    if (debouncedQ.trim() !== '') {
      p.set('q', debouncedQ.trim())
    }

    return p.toString()
  }, [status, page, perPage, debouncedQ])

  const listQ = useQuery({
    queryKey: qk.confirmerOrders(listQs),
    queryFn: async () => {
      const res = await apiFetch<{ items: QueueRow[]; total: number; page: number; per_page: number }>(
        `/confirmer/orders?${listQs}`,
        { method: 'GET' },
      )
      if (!res.ok || !res.data) {
        throw new Error(res.error?.message ?? 'Could not load queue.')
      }

      return res.data
    },
  })

  const items = listQ.data?.items ?? []
  const total = listQ.data?.total ?? 0
  const listErr = listQ.error instanceof Error ? listQ.error.message : ''
  const totalPages = Math.max(1, Math.ceil(total / perPage))

  return (
    <div>
      <p className="muted" style={{ marginBottom: '0.75rem' }}>
        {total} order{total === 1 ? '' : 's'} in this view
      </p>
      <div className="filters" style={{ marginBottom: '1rem', flexWrap: 'wrap', gap: '0.75rem' }}>
        <label className="filters__field">
          <span>Status</span>
          <select
            value={status}
            onChange={(e) => {
              onStatusChange(e.target.value)
            }}
          >
            <option value="pending_approval">Pending approval</option>
            <option value="approved">Approved</option>
            <option value="rejected">Rejected</option>
            <option value="processing">Processing</option>
            <option value="shipped">Shipped</option>
            <option value="delivered">Delivered</option>
            <option value="cancelled">Cancelled</option>
            <option value="all">All</option>
          </select>
        </label>
        <label className="filters__field" style={{ minWidth: '12rem', flex: '1 1 200px' }}>
          <span>Search</span>
          <input
            value={searchInput}
            onChange={(e) => onSearchInputChange(e.target.value)}
            placeholder="Order #, email, customer name…"
            autoComplete="off"
          />
        </label>
      </div>

      {listQ.isPending ? <p className="muted">Loading queue…</p> : null}
      {listErr ? <p className="banner banner--error">{listErr}</p> : null}

      <div className="order-list">
        {items.map((o) => (
          <div
            key={o.id}
            role="button"
            tabIndex={0}
            className={
              'order-list__row order-list__row--clickable' +
              (selectedId === o.id ? ' order-list__row--selected' : '')
            }
            onClick={() => onSelectId(o.id)}
            onKeyDown={(e) => {
              if (e.key === 'Enter' || e.key === ' ') {
                e.preventDefault()
                onSelectId(o.id)
              }
            }}
          >
            <div>
              <strong>{o.order_number}</strong>
              <div className="muted">#{o.id}</div>
              <div className="muted">
                {o.customer.first_name} {o.customer.last_name} · {o.customer.email}
              </div>
              {o.status === 'pending_approval' ? (
                <div className="confirmer-row-actions">
                  <button
                    type="button"
                    className="btn btn--sm"
                    disabled={approveMut.isPending || rejectMut.isPending}
                    onClick={(e) => {
                      e.stopPropagation()
                      void approveMut.mutateAsync({ id: o.id, note: null })
                    }}
                  >
                    Approve
                  </button>
                  <button
                    type="button"
                    className="btn btn--ghost btn--sm"
                    disabled={approveMut.isPending || rejectMut.isPending}
                    onClick={(e) => {
                      e.stopPropagation()
                      void rejectMut.mutateAsync({ id: o.id, reason: 'Rejected' })
                    }}
                  >
                    Reject
                  </button>
                </div>
              ) : null}
            </div>
            <div className="order-list__status">{statusLabel(o.status)}</div>
            <div className="order-list__total">
              {o.currency} ${o.grand_total.toFixed(2)}
            </div>
            <div className="muted">{o.line_count} lines</div>
          </div>
        ))}
      </div>

      {total > 0 ? (
        <p className="muted" style={{ marginTop: '1rem' }}>
          Page {page} of {totalPages}
          <button
            type="button"
            className="btn btn--ghost btn--sm"
            style={{ marginLeft: '0.75rem' }}
            disabled={page <= 1 || listQ.isPending}
            onClick={() => setPage((p) => Math.max(1, p - 1))}
          >
            Previous
          </button>
          <button
            type="button"
            className="btn btn--ghost btn--sm"
            style={{ marginLeft: '0.35rem' }}
            disabled={page >= totalPages || listQ.isPending}
            onClick={() => setPage((p) => p + 1)}
          >
            Next
          </button>
        </p>
      ) : null}
    </div>
  )
}

export default function ConfirmerPage() {
  const { user, loading } = useAuth()
  const { pushToast } = useToast()
  const qc = useQueryClient()
  const [status, setStatus] = useState('pending_approval')
  const [searchInput, setSearchInput] = useState('')
  const debouncedQ = useDebouncedValue(searchInput, 400)
  const [selectedId, setSelectedId] = useState<number | null>(null)
  const [approveNote, setApproveNote] = useState('')
  const [rejectReason, setRejectReason] = useState('')

  const detailQ = useQuery({
    queryKey: selectedId != null ? qk.confirmerOrderDetail(selectedId) : ['confirmer', 'order', 'none'],
    enabled: !loading && selectedId != null && (user?.role === 'admin' || user?.role === 'confirmer'),
    queryFn: async () => {
      if (selectedId == null) {
        throw new Error('No order selected.')
      }
      const res = await apiFetch<{ order: StaffOrder }>(`/confirmer/orders/${selectedId}`, { method: 'GET' })
      if (!res.ok || !res.data?.order) {
        throw new Error(res.error?.message ?? 'Could not load order.')
      }

      return res.data.order
    },
  })

  const invalidateQueue = () => {
    void qc.invalidateQueries({ queryKey: ['confirmer', 'orders'] })
    void qc.invalidateQueries({ queryKey: ['orders'] })
  }

  const approveMut = useMutation({
    mutationFn: async ({ id, note }: ApproveVars) => {
      const res = await apiPostCsrf<{ order_id: number; status: string }>('/confirmer/orders/approve', {
        order_id: id,
        note: note === '' ? null : note,
      })
      if (!res.ok) {
        throw new Error(res.error?.message ?? 'Approve failed.')
      }

      return res.data
    },
    onSuccess: (_, v) => {
      pushToast(`Order #${v.id} approved.`, 'info')
      setApproveNote('')
      invalidateQueue()
      void qc.invalidateQueries({ queryKey: qk.confirmerOrderDetail(v.id) })
      if (selectedId === v.id) {
        void detailQ.refetch()
      }
    },
    onError: (e: Error) => {
      pushToast(e.message, 'error')
    },
  })

  const rejectMut = useMutation({
    mutationFn: async ({ id, reason }: RejectVars) => {
      const res = await apiPostCsrf<{ order_id: number; status: string }>('/confirmer/orders/reject', {
        order_id: id,
        reason,
      })
      if (!res.ok) {
        throw new Error(res.error?.message ?? 'Reject failed.')
      }

      return res.data
    },
    onSuccess: (_, v) => {
      pushToast(`Order #${v.id} rejected.`, 'info')
      setRejectReason('')
      invalidateQueue()
      void qc.invalidateQueries({ queryKey: qk.confirmerOrderDetail(v.id) })
      if (selectedId === v.id) {
        void detailQ.refetch()
      }
    },
    onError: (e: Error) => {
      pushToast(e.message, 'error')
    },
  })

  if (loading) {
    return (
      <div className="page">
        <p className="muted">Loading…</p>
      </div>
    )
  }

  if (user?.role !== 'admin' && user?.role !== 'confirmer') {
    return (
      <div className="page">
        <header className="page__head">
          <h1>Confirmer</h1>
        </header>
        <p className="banner banner--error">Confirmer or administrator access required.</p>
        <Link className="btn" to="/">
          Home
        </Link>
      </div>
    )
  }

  const detailErr = detailQ.error instanceof Error ? detailQ.error.message : ''
  const order = detailQ.data
  const canActOnSelected = order?.status === 'pending_approval'

  return (
    <div className="page">
      <header className="page__head">
        <h1>Confirmer queue</h1>
        <p className="muted">
          {user?.role === 'admin' ? (
            <>
              <Link to="/admin">Admin</Link>
            </>
          ) : (
            'Review and approve orders'
          )}
        </p>
      </header>

      <div className="confirmer-layout">
        <ConfirmerOrderQueueList
          key={`${status}-${debouncedQ}`}
          status={status}
          debouncedQ={debouncedQ}
          searchInput={searchInput}
          onSearchInputChange={setSearchInput}
          onStatusChange={(s) => {
            setStatus(s)
            setSelectedId(null)
          }}
          selectedId={selectedId}
          onSelectId={setSelectedId}
          approveMut={approveMut}
          rejectMut={rejectMut}
        />

        <aside className="panel confirmer-layout__detail" style={{ margin: 0 }}>
          {selectedId == null ? (
            <>
              <h2 className="panel__title">Order detail</h2>
              <p className="panel__hint muted">Select an order from the list to view line items, notes, and approve or reject when it is still pending.</p>
            </>
          ) : (
            <>
              <h2 className="panel__title">Order #{selectedId}</h2>
              {detailQ.isPending ? <p className="muted">Loading…</p> : null}
              {detailErr ? <p className="banner banner--error">{detailErr}</p> : null}
              {order ? (
                <>
                  <p className="muted" style={{ marginTop: '0.35rem' }}>
                    <strong>{order.order_number}</strong> · {statusLabel(order.status)}
                  </p>
                  <p className="muted">
                    {order.customer.first_name} {order.customer.last_name} · {order.customer.email}
                  </p>
                  <p className="muted">{order.created_at}</p>

                  <table className="confirmer-detail-lines">
                    <thead>
                      <tr>
                        <th>Item</th>
                        <th>Qty</th>
                        <th>Each</th>
                        <th>Line</th>
                      </tr>
                    </thead>
                    <tbody>
                      {order.items.map((line) => (
                        <tr key={line.id}>
                          <td>
                            {line.product_name}
                            <div className="muted" style={{ fontSize: '0.85em' }}>
                              {line.variant_label}
                            </div>
                          </td>
                          <td>{line.quantity}</td>
                          <td>
                            {order.currency} ${line.unit_price.toFixed(2)}
                          </td>
                          <td>
                            {order.currency} ${line.line_total.toFixed(2)}
                          </td>
                        </tr>
                      ))}
                    </tbody>
                  </table>

                  <div style={{ marginTop: '0.85rem', fontSize: '0.95rem' }}>
                    <div>
                      Subtotal: {order.currency} ${order.subtotal.toFixed(2)}
                    </div>
                    <div className="muted">Tax: {order.currency} ${order.tax_total.toFixed(2)}</div>
                    <div className="muted">Shipping: {order.currency} ${order.shipping_total.toFixed(2)}</div>
                    <div style={{ fontWeight: 700, marginTop: '0.35rem' }}>
                      Total: {order.currency} ${order.grand_total.toFixed(2)}
                    </div>
                  </div>

                  {order.customer_note ? (
                    <p style={{ marginTop: '0.85rem' }}>
                      <span className="muted">Customer note:</span> {order.customer_note}
                    </p>
                  ) : null}

                  {canActOnSelected ? (
                    <div style={{ marginTop: '1rem', paddingTop: '1rem', borderTop: '1px solid var(--border)' }}>
                      <label className="filters__field">
                        <span>Approve note (optional)</span>
                        <input value={approveNote} onChange={(e) => setApproveNote(e.target.value)} />
                      </label>
                      <label className="filters__field">
                        <span>Reject reason</span>
                        <input value={rejectReason} onChange={(e) => setRejectReason(e.target.value)} placeholder="Shown on rejection" />
                      </label>
                      <div className="panel__actions" style={{ marginTop: '0.75rem' }}>
                        <button
                          type="button"
                          className="btn"
                          disabled={approveMut.isPending || rejectMut.isPending}
                          onClick={() => void approveMut.mutateAsync({ id: order.id, note: approveNote.trim() || null })}
                        >
                          Approve
                        </button>
                        <button
                          type="button"
                          className="btn btn--ghost"
                          disabled={approveMut.isPending || rejectMut.isPending}
                          onClick={() =>
                            void rejectMut.mutateAsync({
                              id: order.id,
                              reason: rejectReason.trim() || 'Rejected',
                            })
                          }
                        >
                          Reject
                        </button>
                      </div>
                    </div>
                  ) : null}
                </>
              ) : null}
            </>
          )}
        </aside>
      </div>
    </div>
  )
}
