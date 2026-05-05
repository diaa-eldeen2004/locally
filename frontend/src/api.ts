const apiBase = () =>
  ((import.meta.env.VITE_API_BASE_URL as string | undefined) ?? '/api').replace(/\/$/, '')

export type ApiEnvelope<T> = {
  ok: boolean
  data?: T
  error?: { code: string; message: string }
}

let csrfCache: string | null = null

export function invalidateCsrf(): void {
  csrfCache = null
}

export async function getCsrf(force = false): Promise<string> {
  if (!force && csrfCache) {
    return csrfCache
  }
  const res = await apiFetch<{ csrf_token: string }>('/csrf', { method: 'GET' })
  if (!res.ok || !res.data?.csrf_token) {
    throw new Error(res.error?.message ?? 'Unable to load CSRF token.')
  }
  csrfCache = res.data.csrf_token

  return csrfCache
}

export async function apiPostCsrf<T>(path: string, body: unknown): Promise<ApiEnvelope<T>> {
  const token = await getCsrf()

  return apiFetch<T>(path, {
    method: 'POST',
    csrf: token,
    body: JSON.stringify(body),
  })
}

export async function apiDeleteCsrf<T>(path: string): Promise<ApiEnvelope<T>> {
  const token = await getCsrf()

  return apiFetch<T>(path, {
    method: 'DELETE',
    csrf: token,
  })
}

export async function apiPatchCsrf<T>(path: string, body: unknown): Promise<ApiEnvelope<T>> {
  const token = await getCsrf()

  return apiFetch<T>(path, {
    method: 'PATCH',
    csrf: token,
    body: JSON.stringify(body),
  })
}

export async function apiPutCsrf<T>(path: string, body: unknown): Promise<ApiEnvelope<T>> {
  const token = await getCsrf()

  return apiFetch<T>(path, {
    method: 'PUT',
    csrf: token,
    body: JSON.stringify(body),
  })
}

/** Multipart POST (e.g. file upload). Do not set Content-Type manually; the browser sets the boundary. */
export async function apiPostMultipartCsrf<T>(path: string, formData: FormData): Promise<ApiEnvelope<T>> {
  const token = await getCsrf()

  return apiFetch<T>(path, {
    method: 'POST',
    csrf: token,
    body: formData,
  })
}

export async function apiFetch<T>(
  path: string,
  init: RequestInit & { csrf?: string } = {},
): Promise<ApiEnvelope<T>> {
  const { csrf, headers: initHeaders, ...rest } = init
  const headers = new Headers(initHeaders)
  if (!headers.has('Content-Type') && rest.body && typeof rest.body === 'string') {
    headers.set('Content-Type', 'application/json')
  }
  if (csrf) {
    headers.set('X-CSRF-Token', csrf)
  }

  const res = await fetch(`${apiBase()}${path}`, {
    credentials: 'include',
    ...rest,
    headers,
  })

  return (await res.json()) as ApiEnvelope<T>
}
