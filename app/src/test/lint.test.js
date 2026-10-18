import { readdirSync, readFileSync, statSync } from 'node:fs'
import { join } from 'node:path'
import { describe, expect, it } from 'vitest'

// A-UX-7 (no barrel import of @nextcloud/vue) and A-UX-9 (colours only from the theme variables), as tests over the sources.
const files = []
const walk = (dir) => {
	for (const f of readdirSync(dir)) {
		const p = join(dir, f)
		if (statSync(p).isDirectory()) walk(p)
		else if (/\.(vue|js)$/.test(f) && !/\.test\.js$/.test(f)) files.push(p)
	}
}
walk('src')

describe('source rules', () => {
	it('finds the sources it is meant to check', () => {
		expect(files.length).toBeGreaterThan(10)
	})

	it('imports @nextcloud/vue components by subpath only', () => {
		const bad = files.filter((f) => /from\s+['"]@nextcloud\/vue['"]/.test(readFileSync(f, 'utf8')))
		expect(bad).toEqual([])
	})

	it('hard-codes no colour: the theme variables are the only source', () => {
		const bad = files.filter((f) => /(?<![&;])#[0-9a-fA-F]{3,8}\b|rgba?\(|hsla?\(/.test(readFileSync(f, 'utf8').replace(/&#\d+;|\\u[0-9a-f]{4}/gi, '')))
		expect(bad).toEqual([])
	})

	it('loads nothing from a third-party host', () => {
		const bad = files.filter((f) => /https?:\/\/(?!www\.w3\.org)/.test(readFileSync(f, 'utf8')))
		expect(bad).toEqual([])
	})
})
