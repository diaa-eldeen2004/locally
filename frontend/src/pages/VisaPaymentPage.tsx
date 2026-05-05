import { useState, type FormEvent } from 'react'
import { useNavigate } from 'react-router-dom'
import { useToast } from '../context/ToastContext'

type VisaDraft = {
  cardholder_name: string
  card_number: string
  exp_month: string
  exp_year: string
  cvv: string
}

const STORAGE_KEY = 'checkoutVisaDraft'

export default function VisaPaymentPage() {
  const navigate = useNavigate()
  const toast = useToast()
  const initial = readDraft()
  const [name, setName] = useState(initial.cardholder_name)
  const [number, setNumber] = useState(initial.card_number)
  const [month, setMonth] = useState(initial.exp_month)
  const [year, setYear] = useState(initial.exp_year)
  const [cvv, setCvv] = useState(initial.cvv)
  const [err, setErr] = useState('')

  const onSubmit = (e: FormEvent) => {
    e.preventDefault()
    setErr('')
    const digits = number.replace(/\D+/g, '')
    if (name.trim() === '') return setErr('Cardholder name is required.')
    if (digits.length < 13 || digits.length > 19 || !passesLuhn(digits)) return setErr('Card number is invalid.')
    const m = Number(month)
    const y = Number(year)
    const now = new Date()
    if (m < 1 || m > 12 || y < now.getFullYear() || (y === now.getFullYear() && m < now.getMonth() + 1)) {
      return setErr('Card expiry is invalid.')
    }
    if (!/^\d{3,4}$/.test(cvv.trim())) return setErr('CVV is invalid.')

    localStorage.setItem(
      STORAGE_KEY,
      JSON.stringify({
        cardholder_name: name.trim(),
        card_number: number.trim(),
        exp_month: String(m),
        exp_year: String(y),
        cvv: cvv.trim(),
      } satisfies VisaDraft)
    )
    toast.pushToast('Visa details saved for checkout.')
    navigate('/cart')
  }

  return (
    <div className="page">
      <header className="page__head">
        <h1>Visa payment</h1>
        <p className="muted">Enter and validate card details before placing your order.</p>
      </header>
      {err ? <p className="banner banner--error">{err}</p> : null}
      <form className="panel" onSubmit={onSubmit}>
        <label className="field">
          <span>Cardholder name</span>
          <input value={name} onChange={(e) => setName(e.target.value)} required />
        </label>
        <label className="field">
          <span>Card number</span>
          <input value={number} onChange={(e) => setNumber(e.target.value)} inputMode="numeric" required />
        </label>
        <label className="field">
          <span>Exp month</span>
          <input value={month} onChange={(e) => setMonth(e.target.value)} inputMode="numeric" required />
        </label>
        <label className="field">
          <span>Exp year</span>
          <input value={year} onChange={(e) => setYear(e.target.value)} inputMode="numeric" required />
        </label>
        <label className="field">
          <span>CVV</span>
          <input value={cvv} onChange={(e) => setCvv(e.target.value)} inputMode="numeric" required />
        </label>
        <div className="pager" style={{ justifyContent: 'flex-start' }}>
          <button type="submit" className="btn">
            Save visa details
          </button>
          <button type="button" className="btn btn--ghost" onClick={() => navigate('/cart')}>
            Back to cart
          </button>
        </div>
      </form>
    </div>
  )
}

function readDraft(): VisaDraft {
  try {
    const raw = localStorage.getItem(STORAGE_KEY)
    if (!raw) return { cardholder_name: '', card_number: '', exp_month: '', exp_year: '', cvv: '' }
    const parsed = JSON.parse(raw) as Partial<VisaDraft>
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
