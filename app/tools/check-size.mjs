// Size gate (ADR-0016): the JS the search page loads first, gzipped, must stay within budget.
import { existsSync, readFileSync, readdirSync } from 'node:fs'
import { gzipSync } from 'node:zlib'

const BUDGET = 250 * 1024
const dir = new URL('../js/', import.meta.url)
const entry = 'buscador_documental-main.mjs'
const seen = new Set()
let total = 0
const visit = (file) => {
	if (seen.has(file)) return
	seen.add(file)
	const src = readFileSync(new URL(file, dir))
	total += gzipSync(src).length
	for (const m of src.toString().matchAll(/from\s*["']\.\/([^"']+\.m?js)["']/g)) visit(m[1])
}
visit(entry)
const cssDir = new URL('../css/', import.meta.url)
const css = (existsSync(cssDir) ? readdirSync(cssDir) : []).filter((f) => f.endsWith('.css'))
for (const f of css) total += gzipSync(readFileSync(new URL(`../css/${f}`, import.meta.url))).length
console.log(`first load: ${(total / 1024).toFixed(1)} KB gzip (budget ${BUDGET / 1024} KB; ${seen.size} script files, ${css.length} stylesheets)`)
if (total > BUDGET) {
	console.error('over budget')
	process.exit(1)
}
