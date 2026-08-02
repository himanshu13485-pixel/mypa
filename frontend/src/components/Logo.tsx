/**
 * The Netvork mark, taken 1:1 from the brand logo file
 * (public/icons/logo-full.svg): dashed orbital ring, four network nodes,
 * and the two-pillar N — in the official blue gradient.
 */
export default function NetvorkMark({ className = 'size-8' }: { className?: string }) {
  return (
    <svg viewBox="0 0 510 510" className={className} aria-label="Netvork">
      <defs>
        <linearGradient id="nv-grad" x1="0" y1="0" x2="1" y2="1">
          <stop offset="0%" stopColor="#1428B8" />
          <stop offset="55%" stopColor="#1769E8" />
          <stop offset="100%" stopColor="#14A8F4" />
        </linearGradient>
      </defs>
      {/* orbital ring */}
      <circle
        cx="255"
        cy="255"
        r="185"
        fill="none"
        stroke="url(#nv-grad)"
        strokeWidth="12"
        strokeDasharray="270 35 270 35 270 35 270 35"
        strokeLinecap="round"
        transform="rotate(-45 255 255)"
      />
      {/* nodes */}
      <circle cx="255" cy="55" r="25" fill="url(#nv-grad)" />
      <circle cx="455" cy="255" r="25" fill="url(#nv-grad)" />
      <circle cx="255" cy="455" r="25" fill="url(#nv-grad)" />
      <circle cx="55" cy="255" r="25" fill="url(#nv-grad)" />
      {/* stylized N */}
      <rect x="150" y="145" width="52" height="220" rx="26" fill="url(#nv-grad)" />
      <rect x="308" y="145" width="52" height="220" rx="26" fill="url(#nv-grad)" />
      <path
        d="M176 170 L334 340"
        fill="none"
        stroke="url(#nv-grad)"
        strokeWidth="56"
        strokeLinecap="round"
        strokeLinejoin="round"
      />
    </svg>
  )
}
