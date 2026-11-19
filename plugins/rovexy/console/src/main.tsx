import { StrictMode } from 'react';
import { createRoot } from 'react-dom/client';
import { BrowserRouter } from 'react-router-dom';
import { App } from './App';
import { AuthProvider } from './lib/auth';
import { SuperApp } from './pages/Super';
import { AccountsApp } from './pages/Accounts';
import { ROOT, consoleBase, setApiBase } from './lib/api';
import '@fontsource-variable/inter';
import './styles.css';

// One build, three consoles: /super = Ametecs staff (Super Admin), /accounts = Ametecs accountants, /admin = a company's console.
const under = (p: string) => location.pathname === ROOT + p || location.pathname.startsWith(ROOT + p + '/');
const isSuper = under('/super');
const isAccounts = under('/accounts');
if (isSuper || isAccounts) setApiBase('/api/super');

createRoot(document.getElementById('root')!).render(
  <StrictMode>
    {isSuper ? (
      <BrowserRouter basename={ROOT + '/super'}><SuperApp /></BrowserRouter>
    ) : isAccounts ? (
      <BrowserRouter basename={ROOT + '/accounts'}><AccountsApp /></BrowserRouter>
    ) : (
      <BrowserRouter basename={consoleBase}>
        <AuthProvider>
          <App />
        </AuthProvider>
      </BrowserRouter>
    )}
  </StrictMode>,
);
