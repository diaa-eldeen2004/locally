import { lazy, Suspense, type ReactNode } from 'react'
import { Route, Routes } from 'react-router-dom'
import ErrorBoundary from './components/ErrorBoundary'
import PageFallback from './components/PageFallback'
import ShellLayout from './layout/ShellLayout'
import CartPage from './pages/CartPage'
import HomePage from './pages/HomePage'
import NotFoundPage from './pages/NotFoundPage'
import ProductPage from './pages/ProductPage'
import ProductsPage from './pages/ProductsPage'

const AccountPage = lazy(() => import('./pages/AccountPage'))
const AdminPage = lazy(() => import('./pages/AdminPage'))
const ConfirmerPage = lazy(() => import('./pages/ConfirmerPage'))
const DevPage = lazy(() => import('./pages/DevPage'))
const OrderDetailPage = lazy(() => import('./pages/OrderDetailPage'))
const OrdersPage = lazy(() => import('./pages/OrdersPage'))
const VisaPaymentPage = lazy(() => import('./pages/VisaPaymentPage'))

function Lazy({ children }: { children: ReactNode }) {
  return <Suspense fallback={<PageFallback />}>{children}</Suspense>
}

export default function App() {
  return (
    <ErrorBoundary>
      <Routes>
        <Route element={<ShellLayout />}>
          <Route index element={<HomePage />} />
          <Route path="/products" element={<ProductsPage />} />
          <Route path="/product/:slug" element={<ProductPage />} />
          <Route path="/cart" element={<CartPage />} />
          <Route
            path="/login"
            element={
              <Lazy>
                <DevPage />
              </Lazy>
            }
          />
          <Route
            path="/signup"
            element={
              <Lazy>
                <DevPage />
              </Lazy>
            }
          />
          <Route
            path="/account"
            element={
              <Lazy>
                <AccountPage />
              </Lazy>
            }
          />
          <Route
            path="/orders"
            element={
              <Lazy>
                <OrdersPage />
              </Lazy>
            }
          />
          <Route
            path="/orders/:id"
            element={
              <Lazy>
                <OrderDetailPage />
              </Lazy>
            }
          />
          <Route
            path="/admin"
            element={
              <Lazy>
                <AdminPage />
              </Lazy>
            }
          />
          <Route
            path="/confirmer"
            element={
              <Lazy>
                <ConfirmerPage />
              </Lazy>
            }
          />
          <Route
            path="/dev"
            element={
              <Lazy>
                <DevPage />
              </Lazy>
            }
          />
          <Route
            path="/checkout/visa"
            element={
              <Lazy>
                <VisaPaymentPage />
              </Lazy>
            }
          />
          <Route path="/404" element={<NotFoundPage />} />
          <Route path="*" element={<NotFoundPage />} />
        </Route>
      </Routes>
    </ErrorBoundary>
  )
}
