import { describe, expect, it } from 'vitest'
import { violations } from './a11y.js'

describe('axe harness', () => {
	it('is clean for a labelled input', async () => {
		const el = document.createElement('div')
		el.innerHTML = '<label for="q">Buscar</label><input id="q" type="search">'
		expect(await violations(el)).toEqual([])
	})

	it('catches an unlabelled input (the harness can fail)', async () => {
		const el = document.createElement('div')
		el.innerHTML = '<input type="text">'
		expect((await violations(el)).join()).toContain('label')
	})
})
