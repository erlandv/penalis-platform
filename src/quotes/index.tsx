import React from 'react';
import { createRoot } from 'react-dom/client';
import { QuotesDirectory } from './QuotesDirectory';
import './quotes.css';

const rootEl = document.getElementById('penalis-quotes-root');

if (rootEl) {
  createRoot(rootEl).render(
    <React.StrictMode>
      <QuotesDirectory />
    </React.StrictMode>,
  );
}
