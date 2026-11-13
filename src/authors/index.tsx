import React from 'react';
import { createRoot } from 'react-dom/client';
import { AuthorsDirectory } from './AuthorsDirectory';
import './authors.css';

const rootEl = document.getElementById('penalis-authors-root');

if (rootEl) {
  createRoot(rootEl).render(
    <React.StrictMode>
      <AuthorsDirectory />
    </React.StrictMode>,
  );
}

