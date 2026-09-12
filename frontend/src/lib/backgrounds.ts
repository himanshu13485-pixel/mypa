import type { CSSProperties } from 'react'

/**
 * Backgrounds that are more than a colour.
 *
 * Gradients with several stops, meshes of overlapping light, and patterns
 * drawn as tiny inline SVGs - written as plain CSS values rather than
 * Tailwind classes, because a five-stop gradient or an SVG tile is not
 * something a class name can carry, and Tailwind cannot see classes built
 * at runtime anyway.
 *
 * Every preset has a light and a dark rendering. A pastel mesh behind white
 * cards in light mode becomes a deep version of itself in dark mode, rather
 * than a bright rectangle glaring behind dark cards.
 *
 * The keys mirror the server's Appearance::BACKGROUNDS - it refuses one it
 * does not know, so the lists have to agree.
 */
export interface BackgroundPreset {
  key: string
  label: string
  kind: 'gradient' | 'mesh' | 'pattern'
  light: string
  dark: string
}

/** An SVG tile as a CSS url(), coloured by the caller. */
const tile = (svg: string) => `url("data:image/svg+xml;utf8,${encodeURIComponent(svg)}")`

const dots = (color: string) =>
  tile(`<svg xmlns='http://www.w3.org/2000/svg' width='22' height='22'><circle cx='2' cy='2' r='1.4' fill='${color}'/></svg>`)
const grid = (color: string) =>
  tile(`<svg xmlns='http://www.w3.org/2000/svg' width='32' height='32'><path d='M32 0H0V32' fill='none' stroke='${color}' stroke-width='1'/></svg>`)
const waves = (color: string) =>
  tile(`<svg xmlns='http://www.w3.org/2000/svg' width='80' height='24'><path d='M0 12 Q20 0 40 12 T80 12' fill='none' stroke='${color}' stroke-width='1.5'/></svg>`)
const diagonal = (color: string) =>
  tile(`<svg xmlns='http://www.w3.org/2000/svg' width='16' height='16'><path d='M-4 4l8-8M0 16L16 0M12 20l8-8' stroke='${color}' stroke-width='1'/></svg>`)
const hex = (color: string) =>
  tile(`<svg xmlns='http://www.w3.org/2000/svg' width='28' height='49'><path d='M13.99 9.25l13 7.5v15l-13 7.5L1 31.75v-15l12.99-7.5zM3 17.9v12.7l10.99 6.34 11-6.35V17.9l-11-6.34L3 17.9zM0 15l12.98-7.5V0h-2v6.35L0 12.69v2.3zm0 18.5L12.98 41v8h-2v-6.85L0 35.81v-2.3zM15 0v7.5L27.99 15H28v-2.31h-.01L17 6.35V0h-2zm0 49v-8l12.99-7.5H28v2.31h-.01L17 42.15V49h-2z' fill='${color}'/></svg>`)
const confetti = (a: string, b: string, c: string) =>
  tile(`<svg xmlns='http://www.w3.org/2000/svg' width='60' height='60'><rect x='6' y='8' width='4' height='8' rx='1' fill='${a}' transform='rotate(25 8 12)'/><circle cx='42' cy='14' r='2.5' fill='${b}'/><rect x='30' y='40' width='8' height='3' rx='1' fill='${c}' transform='rotate(-30 34 41)'/><circle cx='12' cy='46' r='2' fill='${a}'/><rect x='48' y='34' width='3' height='7' rx='1' fill='${b}' transform='rotate(60 49 37)'/></svg>`)

export const BACKGROUNDS: BackgroundPreset[] = [
  // ------------------------------------------------------------ gradients --
  {
    key: 'aurora', label: 'Aurora', kind: 'gradient',
    light: 'linear-gradient(135deg, #e0f2fe 0%, #ede9fe 35%, #fce7f3 70%, #dcfce7 100%)',
    dark: 'linear-gradient(135deg, #082f49 0%, #2e1065 40%, #500724 75%, #052e16 100%)',
  },
  {
    key: 'sunset', label: 'Sunset', kind: 'gradient',
    light: 'linear-gradient(160deg, #fff7ed 0%, #ffedd5 30%, #fecdd3 65%, #e9d5ff 100%)',
    dark: 'linear-gradient(160deg, #431407 0%, #4c0519 50%, #3b0764 100%)',
  },
  {
    key: 'ocean', label: 'Ocean depth', kind: 'gradient',
    light: 'linear-gradient(180deg, #ecfeff 0%, #cffafe 40%, #dbeafe 100%)',
    dark: 'linear-gradient(180deg, #083344 0%, #0c4a6e 50%, #172554 100%)',
  },
  {
    key: 'meadow', label: 'Meadow', kind: 'gradient',
    light: 'linear-gradient(135deg, #f7fee7 0%, #dcfce7 45%, #ccfbf1 100%)',
    dark: 'linear-gradient(135deg, #1a2e05 0%, #052e16 50%, #042f2e 100%)',
  },
  {
    key: 'royal', label: 'Royal', kind: 'gradient',
    light: 'linear-gradient(120deg, #eef2ff 0%, #e0e7ff 40%, #f3e8ff 100%)',
    dark: 'linear-gradient(120deg, #1e1b4b 0%, #312e81 45%, #3b0764 100%)',
  },

  // ----------------------------------------------------------------- mesh --
  {
    key: 'mesh-candy', label: 'Candy mesh', kind: 'mesh',
    light: 'radial-gradient(at 12% 18%, #fbcfe8 0, transparent 50%), radial-gradient(at 85% 12%, #bfdbfe 0, transparent 45%), radial-gradient(at 70% 85%, #bbf7d0 0, transparent 50%), radial-gradient(at 20% 90%, #fde68a 0, transparent 45%), #f8fafc',
    dark: 'radial-gradient(at 12% 18%, #831843 0, transparent 50%), radial-gradient(at 85% 12%, #1e3a8a 0, transparent 45%), radial-gradient(at 70% 85%, #14532d 0, transparent 50%), radial-gradient(at 20% 90%, #713f12 0, transparent 45%), #020617',
  },
  {
    key: 'mesh-northern', label: 'Northern lights', kind: 'mesh',
    light: 'radial-gradient(at 0% 0%, #a7f3d0 0, transparent 55%), radial-gradient(at 100% 30%, #c4b5fd 0, transparent 50%), radial-gradient(at 40% 100%, #7dd3fc 0, transparent 55%), #f0fdfa',
    dark: 'radial-gradient(at 0% 0%, #065f46 0, transparent 55%), radial-gradient(at 100% 30%, #4c1d95 0, transparent 50%), radial-gradient(at 40% 100%, #075985 0, transparent 55%), #020617',
  },
  {
    key: 'mesh-peach', label: 'Peach glow', kind: 'mesh',
    light: 'radial-gradient(at 80% 0%, #fed7aa 0, transparent 50%), radial-gradient(at 0% 60%, #fecaca 0, transparent 50%), radial-gradient(at 90% 100%, #fef08a 0, transparent 45%), #fffbeb',
    dark: 'radial-gradient(at 80% 0%, #7c2d12 0, transparent 50%), radial-gradient(at 0% 60%, #7f1d1d 0, transparent 50%), radial-gradient(at 90% 100%, #713f12 0, transparent 45%), #0c0a09',
  },

  // ------------------------------------------------------------- patterns --
  {
    key: 'dots', label: 'Soft dots', kind: 'pattern',
    light: `${dots('#94a3b8')}, linear-gradient(180deg, #f8fafc, #eef2ff)`,
    dark: `${dots('#334155')}, linear-gradient(180deg, #020617, #0f172a)`,
  },
  {
    key: 'grid', label: 'Blueprint grid', kind: 'pattern',
    light: `${grid('#bfdbfe')}, linear-gradient(135deg, #f8fafc, #eff6ff)`,
    dark: `${grid('#1e293b')}, linear-gradient(135deg, #020617, #0b1220)`,
  },
  {
    key: 'waves', label: 'Waves', kind: 'pattern',
    light: `${waves('#a5f3fc')}, linear-gradient(180deg, #ecfeff, #f0f9ff)`,
    dark: `${waves('#155e75')}, linear-gradient(180deg, #042f2e, #0c1a2b)`,
  },
  {
    key: 'diagonal', label: 'Pinstripe', kind: 'pattern',
    light: `${diagonal('#e9d5ff')}, linear-gradient(135deg, #faf5ff, #fdf2f8)`,
    dark: `${diagonal('#3b0764')}, linear-gradient(135deg, #0f0a1a, #1a0b16)`,
  },
  {
    key: 'honeycomb', label: 'Honeycomb', kind: 'pattern',
    light: `${hex('#fde68a')}, linear-gradient(180deg, #fffbeb, #fef3c7)`,
    dark: `${hex('#422006')}, linear-gradient(180deg, #0c0a09, #1c1917)`,
  },
  {
    key: 'confetti', label: 'Confetti', kind: 'pattern',
    light: `${confetti('#f9a8d4', '#93c5fd', '#fcd34d')}, #fdfcff`,
    dark: `${confetti('#831843', '#1e3a8a', '#713f12')}, #0a0a14`,
  },
]

const isDark = () =>
  typeof document !== 'undefined' && document.documentElement.classList.contains('dark')

export function backgroundPreset(key?: string | null): BackgroundPreset | null {
  if (!key) return null

  return BACKGROUNDS.find((b) => b.key === key) ?? null
}

/**
 * The style for a background key, in the current light or dark mode.
 *
 * Nothing at all for an unknown or empty key, so a caller can spread the
 * result without deciding what the default looks like - the element keeps
 * whatever its own classes already give it.
 */
export function backgroundStyle(key?: string | null): CSSProperties {
  const preset = backgroundPreset(key)
  if (!preset) return {}

  return {
    background: isDark() ? preset.dark : preset.light,
    backgroundAttachment: 'local',
  }
}

/**
 * The CRM sidebar, in more than one shade of slate.
 *
 * Deep colours only: the sidebar's text is white-ish, and a pale sidebar
 * would need a second set of text colours for every link in it.
 */
export interface SidebarPreset {
  key: string
  label: string
  background: string
}

export const SIDEBARS: SidebarPreset[] = [
  { key: 'midnight', label: 'Midnight', background: '#0f172a' },
  { key: 'indigo-night', label: 'Indigo night', background: 'linear-gradient(180deg, #1e1b4b 0%, #312e81 100%)' },
  { key: 'emerald-forest', label: 'Emerald forest', background: 'linear-gradient(180deg, #022c22 0%, #064e3b 100%)' },
  { key: 'plum', label: 'Plum', background: 'linear-gradient(180deg, #2e1065 0%, #581c87 60%, #701a75 100%)' },
  { key: 'ocean-deep', label: 'Ocean deep', background: 'linear-gradient(180deg, #082f49 0%, #0c4a6e 100%)' },
  { key: 'ember', label: 'Ember', background: 'linear-gradient(180deg, #1c1917 0%, #431407 70%, #7c2d12 100%)' },
  { key: 'charcoal', label: 'Charcoal', background: 'linear-gradient(180deg, #18181b 0%, #27272a 100%)' },
]

export function sidebarStyle(key?: string | null): CSSProperties {
  const preset = SIDEBARS.find((s) => s.key === key)

  return preset ? { background: preset.background } : {}
}

/**
 * A background as a stylesheet rule, for both modes at once.
 *
 * An inline style is worked out once, at render, so switching to dark mode
 * would leave a pastel mesh glaring behind dark cards until something else
 * re-rendered. A rule with a .dark variant follows the switch by itself.
 *
 * The attribute is doubled to outrank the element's own utility classes -
 * [data-x][data-x] beats .bg-slate-100 and .dark [data-x][data-x] beats
 * .dark .dark\:bg-slate-950 - without reaching for !important. The values
 * are only ever this file's own presets, never anything typed.
 */
export function backgroundRule(attribute: string, key?: string | null): string {
  const preset = backgroundPreset(key)
  if (!preset) return ''

  const selector = `[${attribute}][${attribute}]`

  return `${selector}{background:${preset.light};background-attachment:local}`
    + `.dark ${selector}{background:${preset.dark}}`
}
