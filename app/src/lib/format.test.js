import { describe, expect, it } from 'vitest'
import { formatDate, formatNumber, summaryLine } from './format.js'

describe('format', () => {
	it('writes dates as 12 sept 2026', () => {
		expect(formatDate(Date.UTC(2026, 8, 12, 15) / 1000)).toBe('12 sept 2026')
	})

	it('uses Chilean time: late evening stays on its day', () => {
		expect(formatDate(Date.UTC(2026, 8, 13, 1, 30) / 1000)).toBe('12 sept 2026')
	})

	it('groups thousands with a no-break space', () => {
		expect(formatNumber(1214)).toBe('1 214')
		expect(formatNumber(38)).toBe('38')
	})

	it('says "más de" when the list was capped', () => {
		expect(summaryLine({ from: 1, to: 25, total: 137, capped: false })).toBe('Mostrando 1–25 de 137 documentos')
		expect(summaryLine({ from: 976, to: 1000, total: 1000, capped: true })).toContain('más de 999')
		expect(summaryLine({ from: 0, to: 0, total: 0, capped: false })).toBe('')
	})
})
