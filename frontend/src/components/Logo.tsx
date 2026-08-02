/**
 * The Netvork mark: a rounded N inside a network circle with four nodes,
 * in the brand blue gradient — recreated as SVG from the brand logo so it
 * stays crisp at every size.
 */
export default function NetvorkMark({ className = 'size-8' }: { className?: string }) {
  return (
    <svg viewBox="0 0 100 100" className={className} aria-label="Netvork">
      <defs>
        <linearGradient id="nv-grad" x1="0%" y1="100%" x2="100%" y2="0%">
          <stop offset="0%" stopColor="#1e3a8a" />
          <stop offset="55%" stopColor="#2563eb" />
          <stop offset="100%" stopColor="#3b82f6" />
        </linearGradient>
      </defs>
      {/* network ring */}
      <circle cx="50" cy="50" r="38" fill="none" stroke="url(#nv-grad)" strokeWidth="5" />
      {/* four nodes on the ring */}
      <circle cx="50" cy="12" r="8" fill="url(#nv-grad)" />
      <circle cx="88" cy="50" r="8" fill="url(#nv-grad)" />
      <circle cx="50" cy="88" r="8" fill="url(#nv-grad)" />
      <circle cx="12" cy="50" r="8" fill="url(#nv-grad)" />
      {/* rounded N */}
      <g stroke="url(#nv-grad)" strokeWidth="13" strokeLinecap="round" fill="none">
        <path d="M 36 68 L 36 34" />
        <path d="M 36 36 L 63 66" />
        <path d="M 63 66 L 63 32" />
      </g>
    </svg>
  )
}
