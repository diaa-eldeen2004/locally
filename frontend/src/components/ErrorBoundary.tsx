import { Component, type ErrorInfo, type ReactNode } from 'react'
import { Link } from 'react-router-dom'

type Props = { children: ReactNode }

type State = { error: Error | null }

export default class ErrorBoundary extends Component<Props, State> {
  constructor(props: Props) {
    super(props)
    this.state = { error: null }
  }

  static getDerivedStateFromError(error: Error): State {
    return { error }
  }

  override componentDidCatch(error: Error, info: ErrorInfo): void {
    console.error('UI error boundary:', error, info.componentStack)
  }

  override render(): ReactNode {
    if (this.state.error) {
      return (
        <div className="page">
          <header className="page__head">
            <h1>Something went wrong</h1>
          </header>
          <p className="banner banner--error">{this.state.error.message}</p>
          <p className="muted">Try reloading the page. If this keeps happening, check the browser console.</p>
          <div className="pager" style={{ justifyContent: 'flex-start' }}>
            <button type="button" className="btn" onClick={() => window.location.reload()}>
              Reload
            </button>
            <Link className="btn btn--ghost" to="/">
              Home
            </Link>
          </div>
        </div>
      )
    }

    return this.props.children
  }
}
