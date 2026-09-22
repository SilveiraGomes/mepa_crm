import { ApiError, apiRequest } from '../../services/api'

export interface AuthUser {
  public_id: string
  login: string
  account_kind: string
}

interface LoginResponse {
  data: {
    session: { token: string; token_type: 'Bearer'; expires_at: string }
    user: AuthUser
  }
}

export async function login(loginId: string, password: string): Promise<LoginResponse['data']> {
  return (await apiRequest<LoginResponse>('auth/login', { method: 'POST', body: { login: loginId, password } })).data
}

export async function logout(token: string): Promise<void> {
  try {
    await apiRequest<void>('auth/logout', { method: 'POST', token })
  } catch (error) {
    if (!(error instanceof ApiError) || error.status >= 500) throw error
  }
}
