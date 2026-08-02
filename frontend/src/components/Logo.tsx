/**
 * The Netvork mark, taken 1:1 from the brand logo file
 * (public/icons/logo-full.svg): purple gradient app tile with the white
 * orbit, five network nodes, and the two-pillar N.
 */
export default function NetvorkMark({ className = 'size-8' }: { className?: string }) {
  return (
    <svg viewBox="0 0 340 340" className={className} aria-label="Netvork">
      <defs>
        <linearGradient id="nv-grad" x1="0" y1="0" x2="1" y2="1">
          <stop offset="0%" stopColor="#171A8F" />
          <stop offset="55%" stopColor="#4022B7" />
          <stop offset="100%" stopColor="#8B35F0" />
        </linearGradient>
      </defs>
      <rect x="0" y="0" width="340" height="340" rx="48" fill="url(#nv-grad)" />
      {/* orbit */}
      <circle cx="170" cy="170" r="112" fill="none" stroke="#FFFFFF" strokeWidth="7" opacity="0.98" />
      {/* orbit nodes */}
      <circle cx="170" cy="47" r="14" fill="#FFFFFF" />
      <circle cx="273" cy="92" r="14" fill="#FFFFFF" />
      <circle cx="281" cy="224" r="14" fill="#FFFFFF" />
      <circle cx="73" cy="222" r="14" fill="#FFFFFF" />
      <circle cx="71" cy="92" r="14" fill="#FFFFFF" />
      {/* stylized white N */}
      <rect x="104" y="104" width="42" height="136" rx="21" fill="#FFFFFF" />
      <rect x="204" y="104" width="42" height="136" rx="21" fill="#FFFFFF" />
      <path d="M125 125 L225 220" fill="none" stroke="#FFFFFF" strokeWidth="44" strokeLinecap="round" strokeLinejoin="round" />
    </svg>
  )
}
