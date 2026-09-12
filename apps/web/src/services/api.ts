const apiBaseUrl = import.meta.env.VITE_API_URL

if (!apiBaseUrl) console.warn('VITE_API_URL não está definida.')

export async function apiRequest<T>(path: string, init: RequestInit = {}): Promise<T> {
  const response = await fetch(`${apiBaseUrl ?? ''}/${path.replace(/^\//, '')}`, {
    ...init,
    headers: {
      Accept: 'application/json',
      'Content-Type': 'application/json',
      ...init.headers,
    },
  })

  if (!response.ok) throw new Error(`Pedido à API falhou com o estado ${response.status}.`)

  return response.json() as Promise<T>
}

export { apiBaseUrl }

