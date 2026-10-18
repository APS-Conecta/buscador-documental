// S6 phase 8 spike: can a link open the Files PDF viewer on page N? Variants are tried against the live stack and the page
// pdf.js actually shows is read back. Output: results-s6/pdf_page.json
import { chromium } from 'playwright'
import { mkdirSync, writeFileSync } from 'node:fs'

const BASE = process.env.BASE ?? 'http://127.0.0.1:8191'
const ID = process.env.FILE_ID
const variants = {
	'/f/ID#page=3': `/f/${ID}#page=3`,
	'/f/ID?page=3': `/f/${ID}?page=3`,
	'/f/ID (no page)': `/f/${ID}`,
	'viewer iframe src with #page=3 (direct)': `/apps/files_pdfviewer/?file=%2FDocs%2Ftres-paginas.pdf#page=3`,
}

const browser = await chromium.launch()
const ctx = await browser.newContext()
const page = await ctx.newPage()
await page.goto(`${BASE}/login`)
await page.fill('#user', 'admin')
await page.fill('#password', 'admin')
await page.click('button[type=submit]')
await page.waitForURL(/apps\//, { timeout: 30000 })

const results = {}
for (const [name, path] of Object.entries(variants)) {
	const p = await ctx.newPage()
	await p.goto(BASE + path)
	let shown = null
	let src = null
	try {
		const frameEl = await p.waitForSelector('iframe[src*="pdfviewer"]', { timeout: 15000 }).catch(() => null)
		const frame = frameEl ? await frameEl.contentFrame() : p.mainFrame()
		src = frameEl ? await frameEl.getAttribute('src') : p.url()
		await frame.waitForSelector('#pageNumber', { timeout: 20000 })
		await p.waitForTimeout(2500)
		shown = Number(await frame.inputValue('#pageNumber'))
	} catch (e) {
		shown = `error: ${String(e).slice(0, 120)}`
	}
	results[name] = { requested: path, iframeSrc: src, pageShown: shown }
	await p.close()
}
await browser.close()
mkdirSync('results-s6', { recursive: true })
writeFileSync('results-s6/pdf_page.json', JSON.stringify(results, null, 2))
console.log(JSON.stringify(results, null, 2))
