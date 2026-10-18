import { describe, expect, it } from 'vitest'
import { duration, etaText, health, progress } from './admin.js'

describe('admin helpers', () => {
	it('the worst check decides the headline', () => {
		expect(health([{ status: 'ok' }, { status: 'warn' }]).id).toBe('warn')
		expect(health([{ status: 'ok' }, { status: 'fail' }, { status: 'warn' }]).id).toBe('fail')
		expect(health([]).label).toBe('Sano')
	})

	it('speaks of durations in plain Spanish', () => {
		expect(duration(600)).toBe('menos de una hora')
		expect(duration(5 * 3600)).toBe('unas 5 horas')
		expect(duration(3 * 86400)).toBe('unos 3 días')
		expect(duration(null)).toBeNull()
	})

	it('explains every estimate state', () => {
		expect(etaText({ state: 'done' })).toBe('Al día')
		expect(etaText({ state: 'paused' })).toBe('Lectura en pausa')
		expect(etaText({ state: 'unknown' })).toContain('Calculando')
		expect(etaText({ state: 'ok', eta_seconds: 7200 })).toBe('Tiempo estimado: unas 2 horas')
		expect(etaText({ state: 'window_closed', opens_in_seconds: 7200 })).toContain('se reanuda')
	})

	it('computes progress from indexed and waiting counts', () => {
		expect(progress({ indexed: 38 }, { text: { remaining: 62 } })).toBe(38)
		expect(progress({}, { text: { remaining: 0 } })).toBe(100)
	})
})
