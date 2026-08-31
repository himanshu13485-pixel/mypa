import { StrictMode } from 'react'
import { createRoot } from 'react-dom/client'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import './index.css'
import App from './App.tsx'
import { installErrorReporting } from './lib/report'
import { installNativeShell } from './lib/nativeShell'

const queryClient = new QueryClient({
  defaultOptions: {
    queries: {
      retry: 1,
      staleTime: 30_000,
      refetchOnWindowFocus: false,
      /*
       * How long an answer is kept after nothing is using it.
       *
       * The default is five minutes, which is shorter than the gap between
       * visits to most sections of this app. Open Tasks, do something else
       * for ten minutes, come back: the cache had been thrown away, so the
       * page loaded from cold with a spinner even though nothing about it
       * had changed. That cold reload was most of what made moving around
       * feel slow — not the request itself, which is fast, but the fact that
       * the screen had nothing to show while it ran.
       *
       * Half an hour means a section you have already opened comes back
       * instantly, populated, and refreshes underneath you. staleTime is
       * unchanged at 30 seconds, so what you see is still checked against
       * the server — it is simply checked while you read it rather than
       * before you are allowed to.
       *
       * The cost is memory: cached answers for pages nobody is looking at.
       * These are lists of tasks and notes, measured in kilobytes.
       */
      gcTime: 30 * 60_000,
    },
  },
})

// Before anything else, so a failure during startup is still reported.
installErrorReporting()
installNativeShell()

// Apply saved theme before first paint to avoid a flash.
const savedTheme = localStorage.getItem('mypa-theme')
if (savedTheme === 'dark' || (!savedTheme && window.matchMedia('(prefers-color-scheme: dark)').matches)) {
  document.documentElement.classList.add('dark')
}

// PWA: register the service worker in production builds only.
if ('serviceWorker' in navigator && import.meta.env.PROD) {
  window.addEventListener('load', () => {
    navigator.serviceWorker.register('/sw.js').catch(() => undefined)
  })
}

createRoot(document.getElementById('root')!).render(
  <StrictMode>
    <QueryClientProvider client={queryClient}>
      <App />
    </QueryClientProvider>
  </StrictMode>,
)
