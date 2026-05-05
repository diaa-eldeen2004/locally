import { Link } from 'react-router-dom'

export default function NotFoundPage() {
  return (
    <div className="page">
      <header className="page__head">
        <h1>404</h1>
      </header>
      <p className="muted">That page does not exist.</p>
      <Link className="btn" to="/">
        Back home
      </Link>
    </div>
  )
}
