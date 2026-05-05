import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { type ReactNode, useState } from 'react'
import { AuthProvider } from '../context/AuthContext'
import { ThemeProvider } from '../context/ThemeContext'
import { ToastProvider } from '../context/ToastContext'
import ToastHost from '../components/ToastHost'

export default function AppProviders({ children }: { children: ReactNode }) {
  const [client] = useState(
    () =>
      new QueryClient({
        defaultOptions: {
          queries: {
            staleTime: 45_000,
            retry: 1,
            refetchOnWindowFocus: false,
          },
        },
      }),
  )

  return (
    <QueryClientProvider client={client}>
      <AuthProvider>
        <ThemeProvider>
          <ToastProvider>
            {children}
            <ToastHost />
          </ToastProvider>
        </ThemeProvider>
      </AuthProvider>
    </QueryClientProvider>
  )
}
