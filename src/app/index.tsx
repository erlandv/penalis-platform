import { StrictMode } from 'react';
import { createRoot } from 'react-dom/client';

import { App } from './App';
import '../styles/main.css';

const container = document.getElementById('penalis-dashboard-root');

if (container) {
  createRoot(container).render(
    <StrictMode>
      <App />
    </StrictMode>,
  );
}
