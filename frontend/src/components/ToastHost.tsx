import { useToast } from '../context/ToastContext'

export default function ToastHost() {
  const { toasts, dismiss } = useToast()

  if (toasts.length === 0) {
    return null
  }

  return (
    <div className="toast-host" role="region" aria-label="Notifications">
      {toasts.map((t) => (
        <div
          key={t.id}
          className={`toast toast--${t.variant}`}
          role="status"
        >
          <span className="toast__text">{t.message}</span>
          <button type="button" className="toast__close" onClick={() => dismiss(t.id)} aria-label="Dismiss">
            ×
          </button>
        </div>
      ))}
    </div>
  )
}
