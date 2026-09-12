import './App.css'
import { BrowserRouter, Route, Routes } from 'react-router-dom'

function FoundationPage() {
  return (
    <main className="foundation-shell">
      <section className="foundation-card" aria-labelledby="page-title">
        <span className="eyebrow">Missão Evangélica Pentecostal de Angola</span>
        <div className="brand-mark" aria-hidden="true">M</div>
        <h1 id="page-title">MEPA Gestão</h1>
        <p>Ambiente de desenvolvimento — Fundação P0</p>
        <div className="status" role="status">
          <span className="status-dot" aria-hidden="true" />
          Fundação técnica activa
        </div>
      </section>
    </main>
  )
}

function App() {
  return (
    <BrowserRouter>
      <Routes>
        <Route path="*" element={<FoundationPage />} />
      </Routes>
    </BrowserRouter>
  )
}

export default App
