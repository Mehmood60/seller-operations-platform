'use client';

import {
  createContext,
  useCallback,
  useContext,
  useEffect,
  useState,
} from 'react';
import type { User } from '@/types';
import {
  clearSessionToken,
  getSessionToken,
  setSessionToken,
  userAuth,
} from '@/lib/api';

// ── Context shape ─────────────────────────────────────────────────────────────

interface AuthContextValue {
  user: User | null;
  isAuthenticated: boolean;
  isLoading: boolean;
  /** Call after a successful login response to persist the session. */
  login: (token: string, user: User) => void;
  /** Clear session locally and call the backend logout endpoint. */
  logout: () => Promise<void>;
}

const AuthContext = createContext<AuthContextValue | null>(null);

// ── Provider ──────────────────────────────────────────────────────────────────

export default function AuthProvider({ children }: { children: React.ReactNode }) {
  const [user, setUser]         = useState<User | null>(null);
  const [isLoading, setLoading] = useState(true);

  // On mount: check for an existing token and validate it with the backend.
  useEffect(() => {
    const token = getSessionToken();

    if (!token) {
      setLoading(false);
      return;
    }

    userAuth
      .me()
      .then((res) => {
        setUser(res.data);
      })
      .catch(() => {
        // Token invalid or expired — clear it silently.
        clearSessionToken();
      })
      .finally(() => {
        setLoading(false);
      });
  }, []);

  const login = useCallback((token: string, userData: User) => {
    setSessionToken(token);
    setUser(userData);
  }, []);

  const logout = useCallback(async () => {
    try {
      await userAuth.logout();
    } catch {
      // Best-effort — clear locally regardless.
    } finally {
      clearSessionToken();
      setUser(null);
    }
  }, []);

  return (
    <AuthContext.Provider
      value={{
        user,
        isAuthenticated: user !== null,
        isLoading,
        login,
        logout,
      }}
    >
      {children}
    </AuthContext.Provider>
  );
}

// ── Hook ──────────────────────────────────────────────────────────────────────

export function useAuth(): AuthContextValue {
  const ctx = useContext(AuthContext);
  if (!ctx) {
    throw new Error('useAuth must be used inside <AuthProvider>');
  }
  return ctx;
}
