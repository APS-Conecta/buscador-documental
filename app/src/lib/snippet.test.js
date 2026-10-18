import { describe, expect, it } from 'vitest'
import { parseSnippet } from './snippet.js'

describe('parseSnippet', () => {
	it('marks only what the server wrapped in <mark>', () => {
		expect(parseSnippet('campaña de <mark>vacunación</mark> para adultos')).toEqual([
			{ text: 'campaña de ', mark: false }, { text: 'vacunación', mark: true }, { text: ' para adultos', mark: false },
		])
	})

	it('turns escaped markup into text, never into elements', () => {
		const s = parseSnippet('&lt;img src=x onerror=alert(1)&gt; &amp; <mark>a</mark>')
		expect(s[0]).toEqual({ text: '<img src=x onerror=alert(1)> & ', mark: false })
		expect(s.every((x) => typeof x.text === 'string')).toBe(true)
	})

	it('copes with empty, missing and unbalanced input', () => {
		expect(parseSnippet(undefined)).toEqual([])
		expect(parseSnippet('')).toEqual([])
		expect(parseSnippet('a</mark>b<mark>c')).toEqual([{ text: 'a', mark: false }, { text: 'b', mark: false }, { text: 'c', mark: true }])
	})
})
