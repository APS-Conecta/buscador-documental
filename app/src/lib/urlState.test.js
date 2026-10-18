import { describe, expect, it } from 'vitest'
import { DEFAULTS, hasFilters, parseState, serializeState, toRequest, withoutFilters } from './urlState.js'

describe('urlState', () => {
	it('round-trips every field', () => {
		const s = { ...DEFAULTS, q: 'vacunación invierno', mode: 'exacta', sort: 'name_asc', size: 50, types: ['pdf', 'imagen'], when: 'range', from: '2026-01-01', to: '2026-02-01', folder: 12, owner: 'mine', uncertain: true }
		expect(parseState(serializeState(s))).toEqual(s)
	})

	it('keeps links short: defaults are not written', () => {
		expect(serializeState({ ...DEFAULTS, q: 'glucosa' })).toBe('q=glucosa')
	})

	it('ignores anything unknown or malformed', () => {
		const s = parseState('?q=a&modo=hack&orden=%27&por_pagina=7&tipo=exe,pdf&fecha=ayer&carpeta=-3&propiedad=otros&incierta=2&desde=2026-1-1')
		expect(s).toEqual({ ...DEFAULTS, q: 'a', types: ['pdf'] })
	})

	it('builds the request: relevance with a query, newest first without', () => {
		expect(toRequest({ ...DEFAULTS, q: 'x' }).sort).toBe('relevance')
		expect(toRequest({ ...DEFAULTS }).sort).toBe('modified_desc')
		expect(toRequest({ ...DEFAULTS, q: 'x', sort: 'name_desc' }).sort).toBe('name_desc')
	})

	it('turns relative dates into a from-date and ranges into both', () => {
		const today = new Date('2026-10-15T12:00:00Z')
		expect(toRequest({ ...DEFAULTS, q: 'x', when: 'week' }, null, today).filters).toEqual({ modified_from: '2026-10-08' })
		expect(toRequest({ ...DEFAULTS, q: 'x', when: 'range', from: '2026-01-01', to: '2026-02-01' }).filters).toEqual({ modified_from: '2026-01-01', modified_to: '2026-02-01' })
	})

	it('sends the cursor only when there is one and clears all filters on request', () => {
		expect(toRequest({ ...DEFAULTS, q: 'x' }, 'abc').cursor).toBe('abc')
		expect('cursor' in toRequest({ ...DEFAULTS, q: 'x' })).toBe(false)
		const f = { ...DEFAULTS, owner: 'mine', uncertain: true, types: ['pdf'] }
		expect(hasFilters(f)).toBe(true)
		expect(hasFilters(withoutFilters(f))).toBe(false)
	})
})
