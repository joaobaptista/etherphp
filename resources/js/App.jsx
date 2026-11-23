import React, { useState, useEffect } from 'react';
import LoginView from './modules/auth/presentation/LoginView.jsx';
import { readGoogleAuthCallback, clearGoogleAuthCallback } from './modules/auth/infrastructure/authRepository.js';
import MainApp from './modules/dashboard/presentation/MainApp.jsx';
import { I18nProvider } from './core/i18n/presentation/I18nContext.jsx';
import TopProgressBar from './core/components/TopProgressBar.jsx';
import ErrorBoundary from './core/components/ErrorBoundary.jsx';
import { ensureLocalDataOwner, wipeLocalUserData } from './core/services/localDataGuard.js';

const AUTH_TOKEN_KEY = 'ethernity_auth_token';

export default function App() {
  const [token, setToken] = useState(() => {
    try {
      if (typeof window !== 'undefined' && (window.location.search || window.location.hash)) {
        const { token: urlToken, rawUser } = readGoogleAuthCallback();

        if (urlToken) {
          localStorage.setItem(AUTH_TOKEN_KEY, urlToken);
          if (rawUser) {
            try {
              localStorage.setItem('ethernity_user', rawUser);
            } catch {}
          }
          clearGoogleAuthCallback();
          return urlToken;
        }
      }

      return localStorage.getItem(AUTH_TOKEN_KEY) || null;
    } catch {
      return null;
    }
  });

  // O cache local só é usado depois de confirmado que pertence à conta deste token.
  const [isLocalDataReady, setIsLocalDataReady] = useState(false);

  useEffect(() => {
    if (!token) return undefined;
    let active = true;
    ensureLocalDataOwner(token).finally(() => {
      if (active) setIsLocalDataReady(true);
    });
    return () => {
      active = false;
    };
  }, [token]);

  const handleLoginSuccess = async (authToken) => {
    if (authToken) {
      try {
        localStorage.setItem(AUTH_TOKEN_KEY, authToken);
      } catch (err) {
        console.error('Erro ao salvar token de autenticação:', err);
      }
    }
    // Se havia dados de outra conta neste navegador, expurga e recarrega para limpar a memória.
    if (authToken && (await ensureLocalDataOwner(authToken))) {
      window.location.reload();
      return;
    }
    setToken(authToken);
  };

  const handleLogout = async () => {
    try {
      // Envia ao servidor o que ainda está só neste aparelho antes de apagar o cache local.
      const { default: capsulesService } = await import('./modules/capsules/application/capsulesService.js');
      await Promise.race([capsulesService.syncPending(), new Promise((resolve) => setTimeout(resolve, 4000))]);
    } catch {
      // sem conexão: o expurgo local segue mesmo assim
    }
    await wipeLocalUserData();
    window.location.replace('/');
  };

  return (
    <I18nProvider>
      <ErrorBoundary>
        <TopProgressBar />
        {!token ? (
          <LoginView onLoginSuccess={handleLoginSuccess} />
        ) : (
          isLocalDataReady && <MainApp token={token} onLogout={handleLogout} />
        )}
      </ErrorBoundary>
    </I18nProvider>
  );
}
