/**
 * Tiny SVG charts for the CRM — no library, one shared palette.
 *
 * The categorical order is fixed (never cycled) and was validated for
 * colorblind separation and 3:1 surface contrast in light and dark mode.
 * Labels always wear text colors, never the series color; every mark
 * carries a native tooltip via <title>.
 */

import { clsx } from 'clsx'

export const CHART_COLORS = ['#059669', '#0284c7', '#d97706', '#9333ea', '#e11d48']

export interface Slice {
  label: string
  value: number
  color?: string
}

const fmt = (v: number) => (Number.isInteger(v) ? v.toLocaleString('en-IN') : v.toFixed(1))

export function Legend({ data }: { data: Slice[] }) {
  return (
    <div className="mt-2 flex flex-wrap gap-x-4 gap-y-1">
      {data.map((d, i) => (
        <span key={d.label} className="flex items-center gap-1.5 text-xs text-slate-600 dark:text-slate-300">
          <span className="size-2.5 rounded-full" style={{ background: d.color ?? CHART_COLORS[i % CHART_COLORS.length] }} />
          {d.label}
          <span className="text-slate-400">{fmt(d.value)}</span>
        </span>
      ))}
    </div>
  )
}

/** Donut for shares of a whole (status splits, band distribution). */
export function DonutChart({ data, centerLabel, size = 140 }: { data: Slice[]; centerLabel?: string; size?: number }) {
  const total = data.reduce((s, d) => s + d.value, 0)
  if (total <= 0) {
    return <p className="py-6 text-center text-sm text-slate-400">No data yet.</p>
  }

  const r = size / 2 - 10
  const cx = size / 2
  const cy = size / 2
  const stroke = 18
  let angle = -90

  const arcs = data.filter((d) => d.value > 0).map((d, i) => {
    const share = d.value / total
    const sweep = share * 360
    // A 2° gap plays the role of the 2px spacer between fills.
    const a0 = angle + 1
    const a1 = angle + sweep - 1
    angle += sweep
    const large = a1 - a0 > 180 ? 1 : 0
    const p0 = [cx + r * Math.cos((a0 * Math.PI) / 180), cy + r * Math.sin((a0 * Math.PI) / 180)]
    const p1 = [cx + r * Math.cos((a1 * Math.PI) / 180), cy + r * Math.sin((a1 * Math.PI) / 180)]
    return { d, i, share, path: sweep >= 360 ? '' : `M ${p0[0]} ${p0[1]} A ${r} ${r} 0 ${large} 1 ${p1[0]} ${p1[1]}` }
  })

  return (
    <div className="flex flex-col items-center">
      <svg width={size} height={size} role="img" aria-label={centerLabel ?? 'Distribution'}>
        {arcs.map((a) =>
          a.path === '' ? (
            <circle key={a.d.label} cx={cx} cy={cy} r={r} fill="none" strokeWidth={stroke}
              stroke={a.d.color ?? CHART_COLORS[a.i % CHART_COLORS.length]}>
              <title>{`${a.d.label}: ${fmt(a.d.value)} (100%)`}</title>
            </circle>
          ) : (
            <path key={a.d.label} d={a.path} fill="none" strokeWidth={stroke} strokeLinecap="butt"
              stroke={a.d.color ?? CHART_COLORS[a.i % CHART_COLORS.length]}>
              <title>{`${a.d.label}: ${fmt(a.d.value)} (${Math.round(a.share * 100)}%)`}</title>
            </path>
          ),
        )}
        <text x={cx} y={cy - 2} textAnchor="middle" className="fill-slate-800 text-lg font-semibold dark:fill-slate-100">
          {fmt(total)}
        </text>
        {centerLabel && (
          <text x={cx} y={cy + 14} textAnchor="middle" className="fill-slate-400 text-[10px]">
            {centerLabel}
          </text>
        )}
      </svg>
      <Legend data={data} />
    </div>
  )
}

/** Horizontal bars for comparing people/categories by one measure. */
export function HBarChart({ data, color = CHART_COLORS[0], unit = '', maxBars = 10 }: {
  data: Slice[]
  color?: string
  unit?: string
  maxBars?: number
}) {
  const rows = data.slice(0, maxBars)
  const max = Math.max(...rows.map((d) => d.value), 1)

  if (rows.length === 0 || max <= 0) {
    return <p className="py-6 text-center text-sm text-slate-400">No data yet.</p>
  }

  return (
    <div className="space-y-1.5">
      {rows.map((d) => (
        <div key={d.label} className="flex items-center gap-2" title={`${d.label}: ${fmt(d.value)}${unit}`}>
          <span className="w-32 shrink-0 truncate text-xs text-slate-600 dark:text-slate-300">{d.label}</span>
          <div className="h-3.5 min-w-0 flex-1 rounded-r bg-slate-100 dark:bg-slate-800">
            <div
              className="h-full rounded-r"
              style={{ width: `${Math.max(1.5, (d.value / max) * 100)}%`, background: d.color ?? color }}
            />
          </div>
          <span className="w-16 shrink-0 text-right text-xs font-medium tabular-nums text-slate-700 dark:text-slate-200">
            {fmt(d.value)}{unit}
          </span>
        </div>
      ))}
    </div>
  )
}

/** Vertical columns for values over time (daily scores, trends). */
export function ColumnChart({ data, color = CHART_COLORS[0], unit = '', height = 120, yMax }: {
  data: { label: string; value: number; color?: string }[]
  color?: string
  unit?: string
  height?: number
  yMax?: number
}) {
  if (data.length === 0) {
    return <p className="py-6 text-center text-sm text-slate-400">No data yet.</p>
  }
  const max = yMax ?? Math.max(...data.map((d) => d.value), 1)

  return (
    <div>
      <div className="flex items-end gap-1" style={{ height }}>
        {data.map((d) => (
          <div key={d.label} className="group flex h-full min-w-0 flex-1 flex-col items-center justify-end"
            title={`${d.label}: ${fmt(d.value)}${unit}`}>
            <span className="mb-0.5 hidden text-[10px] font-medium tabular-nums text-slate-500 group-hover:block">
              {fmt(d.value)}
            </span>
            <div
              className="w-full max-w-7 rounded-t"
              style={{ height: `${Math.max(2, (d.value / max) * 100)}%`, background: d.color ?? color }}
            />
          </div>
        ))}
      </div>
      <div className="mt-1 flex gap-1 border-t border-slate-100 pt-1 dark:border-slate-800">
        {data.map((d) => (
          <span key={d.label} className="min-w-0 flex-1 truncate text-center text-[10px] text-slate-400">
            {d.label}
          </span>
        ))}
      </div>
    </div>
  )
}

/**
 * Two series of columns side by side over the same buckets — this period
 * against the one a year earlier — with the change written above each pair.
 * Used by the growth map, where the comparison IS the point.
 */
/**
 * A line over time, with one or more series.
 *
 * Bars answer "how big was each one"; a line answers "which way is this
 * going", and a period filter that offers a year of months is asking the
 * second question. Drawn as an SVG with a viewBox rather than absolutely
 * positioned divs, so it scales to whatever width it is given.
 */
export function LineChart({ data, series, height = 160, format }: {
  data: { label: string; values: number[] }[]
  series: { label: string; color?: string }[]
  height?: number
  format?: (v: number) => string
}) {
  if (data.length === 0) {
    return <p className="py-6 text-center text-sm text-slate-400">No data yet.</p>
  }

  const show = format ?? fmt
  const max = Math.max(...data.flatMap((d) => d.values), 1)
  // A single point has no line to draw, so it sits in the middle.
  const stepOf = (i: number) => (data.length === 1 ? 50 : (i / (data.length - 1)) * 100)
  const yOf = (v: number) => 100 - (v / max) * 100

  return (
    <div>
      <div className="relative" style={{ height }}>
        <svg viewBox="0 0 100 100" preserveAspectRatio="none" className="size-full overflow-visible">
          {/* Three rules behind the lines, so a height can be read off. */}
          {[0, 50, 100].map((y) => (
            <line
              key={y}
              x1="0" x2="100" y1={y} y2={y}
              stroke="currentColor"
              strokeWidth="0.3"
              className="text-slate-200 dark:text-slate-700"
              vectorEffect="non-scaling-stroke"
            />
          ))}

          {series.map((s, si) => (
            <polyline
              key={s.label}
              fill="none"
              stroke={s.color ?? CHART_COLORS[si % CHART_COLORS.length]}
              strokeWidth="2"
              strokeLinejoin="round"
              strokeLinecap="round"
              vectorEffect="non-scaling-stroke"
              points={data.map((d, i) => `${stepOf(i)},${yOf(d.values[si] ?? 0)}`).join(' ')}
            />
          ))}

          {/* A dot per reading, so one month on its own is still visible. */}
          {series.map((s, si) => data.map((d, i) => (
            <circle
              key={`${s.label}-${d.label}`}
              cx={stepOf(i)}
              cy={yOf(d.values[si] ?? 0)}
              r="2.5"
              fill={s.color ?? CHART_COLORS[si % CHART_COLORS.length]}
              vectorEffect="non-scaling-stroke"
            >
              <title>{`${d.label} · ${s.label}: ${show(d.values[si] ?? 0)}`}</title>
            </circle>
          )))}
        </svg>
      </div>

      <div className="mt-1 flex justify-between gap-2 border-t border-slate-100 pt-1 text-[10px] text-slate-400 dark:border-slate-800">
        {/* Every label on a short run; the ends and the middle on a long
            one, because twelve labels in a narrow card is a grey smudge. */}
        {data.map((d, i) => (
          <span
            key={d.label}
            className={clsx(
              'min-w-0 flex-1 truncate text-center',
              data.length > 8 && i % Math.ceil(data.length / 6) !== 0 && 'invisible',
            )}
          >
            {d.label}
          </span>
        ))}
      </div>

      <Legend data={series.map((s, i) => ({
        label: s.label,
        value: data.reduce((sum, d) => sum + (d.values[i] ?? 0), 0),
        color: s.color ?? CHART_COLORS[i % CHART_COLORS.length],
      }))} />
    </div>
  )
}

export function GrowthChart({ data, series, height = 150, format }: {
  data: { label: string; values: number[]; change?: number | null }[]
  series: { label: string; color?: string }[]
  height?: number
  format?: (v: number) => string
}) {
  if (data.length === 0) {
    return <p className="py-6 text-center text-sm text-slate-400">No data yet.</p>
  }
  const show = format ?? fmt
  const max = Math.max(...data.flatMap((d) => d.values), 1)

  return (
    <div>
      <div className="-mx-1 overflow-x-auto px-1">
        <div className="flex min-w-[420px] items-end gap-2" style={{ height }}>
          {data.map((d) => (
            <div key={d.label} className="flex h-full min-w-0 flex-1 flex-col justify-end">
              {/* The change rides above the pair, in the colour of its sign. */}
              <div className="mb-1 h-4 text-center text-[10px] font-semibold tabular-nums">
                {d.change === null || d.change === undefined ? (
                  <span className="text-slate-300 dark:text-slate-600">—</span>
                ) : (
                  <span className={d.change >= 0 ? 'text-emerald-600 dark:text-emerald-400' : 'text-red-500'}>
                    {d.change >= 0 ? '▲' : '▼'} {Math.abs(d.change)}%
                  </span>
                )}
              </div>
              <div className="flex h-full items-end justify-center gap-0.5">
                {d.values.map((v, i) => (
                  <div
                    key={series[i]?.label ?? i}
                    className="w-full max-w-5 rounded-t"
                    style={{
                      height: `${Math.max(v > 0 ? 3 : 1, (v / max) * 100)}%`,
                      background: series[i]?.color ?? CHART_COLORS[i % CHART_COLORS.length],
                      opacity: i === 0 ? 1 : 0.45,
                    }}
                    title={`${d.label} · ${series[i]?.label ?? ''}: ${show(v)}`}
                  />
                ))}
              </div>
            </div>
          ))}
        </div>
        <div className="mt-1 flex min-w-[420px] gap-2 border-t border-slate-100 pt-1 dark:border-slate-800">
          {data.map((d) => (
            <span key={d.label} className="min-w-0 flex-1 truncate text-center text-[10px] text-slate-400">
              {d.label}
            </span>
          ))}
        </div>
      </div>
      <div className="mt-2 flex flex-wrap gap-x-4 gap-y-1">
        {series.map((s, i) => (
          <span key={s.label} className="flex items-center gap-1.5 text-xs text-slate-600 dark:text-slate-300">
            <span
              className="size-2.5 rounded-full"
              style={{ background: s.color ?? CHART_COLORS[i % CHART_COLORS.length], opacity: i === 0 ? 1 : 0.45 }}
            />
            {s.label}
          </span>
        ))}
      </div>
    </div>
  )
}
