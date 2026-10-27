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

	it('does not take inherited object keys for modes', () => {
		expect(parseState('?modo=constructor').mode).toBe('mixta')
		expect(parseState('?modo=toString').mode).toBe('mixta')
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

	it('counts relative ranges back from the Santiago calendar day', () => {
		const lateEvening = new Date('2026-10-28T02:30:00Z') // 2026-10-27 23:30 -03
		const from = (when, today) => toRequest({ ...DEFAULTS, q: 'x', when }, null, today).filters.modified_from
		expect(from('week', lateEvening)).toBe('2026-10-20')
		expect(from('month', lateEvening)).toBe('2026-09-27')
		expect(from('year', lateEvening)).toBe('2025-10-27')
		// 2026-09-12 00:30 -03: 7 x 24 h back lands on 2026-09-04 23:30 -04 across the 23-hour 2026-09-06, so it must count calendar days.
		expect(from('week', new Date('2026-09-12T03:30:00Z'))).toBe('2026-09-05')
	})

	it('sends the cursor only when there is one and clears all filters on request', () => {
		expect(toRequest({ ...DEFAULTS, q: 'x' }, 'abc').cursor).toBe('abc')
		expect('cursor' in toRequest({ ...DEFAULTS, q: 'x' })).toBe(false)
		const f = { ...DEFAULTS, owner: 'mine', uncertain: true, types: ['pdf'] }
		expect(hasFilters(f)).toBe(true)
		expect(hasFilters(withoutFilters(f))).toBe(false)
	})
})
