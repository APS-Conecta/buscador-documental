import { calendarDay } from './format.js'

export const DEFAULTS = Object.freeze({ q: '', mode: 'mixta', sort: '', size: 25, types: [], when: '', from: '', to: '', folder: null, owner: '', uncertain: false })

const MODES = { exacta: 'exacta', significado: 'significado', mixta: 'mixta' }
const SORTS = ['relevance', 'modified_desc', 'modified_asc', 'name_asc', 'name_desc']
const TYPES = ['pdf', 'word', 'hoja', 'presentacion', 'texto', 'imagen']
const WHEN = ['week', 'month', 'year', 'range']
const DAY = /^\d{4}-\d{2}-\d{2}$/

/** URL query string to a state object; anything unknown or malformed falls back to the default, so a pasted link never breaks the page. */
export function parseState(search) {
	const p = new URLSearchParams(search)
	const s = { ...DEFAULTS, types: [] }
	s.q = (p.get('q') ?? '').slice(0, 500)
	s.mode = Object.hasOwn(MODES, p.get('modo')) ? MODES[p.get('modo')] : DEFAULTS.mode
	s.sort = SORTS.includes(p.get('orden')) ? p.get('orden') : ''
	const size = Number(p.get('por_pagina'))
	s.size = [25, 50, 100].includes(size) ? size : 25
	s.types = (p.get('tipo') ?? '').split(',').filter((t) => TYPES.includes(t))
	s.when = WHEN.includes(p.get('fecha')) ? p.get('fecha') : ''
	if (s.when === 'range') {
		s.from = DAY.test(p.get('desde') ?? '') ? p.get('desde') : ''
		s.to = DAY.test(p.get('hasta') ?? '') ? p.get('hasta') : ''
	}
	const folder = Number(p.get('carpeta'))
	s.folder = Number.isInteger(folder) && folder > 0 ? folder : null
	s.owner = ['mine', 'shared'].includes(p.get('propiedad')) ? p.get('propiedad') : ''
	s.uncertain = p.get('incierta') === '1'
	return s
}

/** State to a query string with only the values that differ from the defaults (short, shareable links). */
export function serializeState(s) {
	const p = new URLSearchParams()
	if (s.q) p.set('q', s.q)
	if (s.mode !== DEFAULTS.mode) p.set('modo', s.mode)
	if (s.sort) p.set('orden', s.sort)
	if (s.size !== 25) p.set('por_pagina', String(s.size))
	if (s.types.length) p.set('tipo', s.types.join(','))
	if (s.when) p.set('fecha', s.when)
	if (s.when === 'range') {
		if (s.from) p.set('desde', s.from)
		if (s.to) p.set('hasta', s.to)
	}
	if (s.folder) p.set('carpeta', String(s.folder))
	if (s.owner) p.set('propiedad', s.owner)
	if (s.uncertain) p.set('incierta', '1')
	return p.toString()
}

const ymd = (d) => d.toISOString().slice(0, 10)

/** The request body for POST /search. `today` is injectable so the relative ranges are testable. */
export function toRequest(s, cursor = null, today = new Date()) {
	const filters = {}
	if (s.types.length) filters.types = s.types
	if (s.when === 'range') {
		if (s.from) filters.modified_from = s.from
		if (s.to) filters.modified_to = s.to
	} else if (s.when) {
		const back = { week: 7, month: 30, year: 365 }[s.when]
		const [y, m, d] = calendarDay(today)
		filters.modified_from = ymd(new Date(Date.UTC(y, m - 1, d - back)))
	}
	if (s.folder) filters.folder_id = s.folder
	if (s.owner) filters.owner = s.owner
	if (s.uncertain) filters.uncertain = true
	const body = { query: s.q, mode: s.mode, sort: s.sort || (s.q ? 'relevance' : 'modified_desc'), page_size: s.size, filters }
	if (cursor) body.cursor = cursor
	return body
}

export function hasFilters(s) {
	return s.types.length > 0 || s.when !== '' || s.folder !== null || s.owner !== '' || s.uncertain
}

export function withoutFilters(s) {
	return { ...s, types: [], when: '', from: '', to: '', folder: null, owner: '', uncertain: false }
}
