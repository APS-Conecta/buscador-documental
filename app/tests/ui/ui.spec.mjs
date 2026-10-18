import AxeBuilder from '@axe-core/playwright'
import { expect, test } from '@playwright/test'

const PW = 'ui-pass-12345'
const APP = '/apps/buscador_documental/'
const ADMIN = '/settings/admin/buscador_documental'
const WORD = process.env.UI_WORD // appears in the indexed documents of ui_a

async function login(browser, user, pass) {
	const ctx = await browser.newContext({ locale: 'es-CL' })
	const page = await ctx.newPage()
	await page.goto('/login')
	await page.fill('#user', user)
	await page.fill('#password', pass)
	await page.click('button[type=submit]')
	await page.waitForURL(/\/apps\//, { timeout: 30000 })
	return { ctx, page }
}

let a
let admin
test.beforeAll(async ({ browser }) => {
	a = await login(browser, 'ui_a', PW)
	admin = await login(browser, 'admin', 'admin')
})
test.afterAll(async () => { await a.ctx.close(); await admin.ctx.close() })

async function search(page, q) {
	await page.goto(`${APP}?q=${encodeURIComponent(q)}`)
	await page.locator('li.bd-card').first().waitFor({ timeout: 20000 })
}

test('keyboard only: type, search, reach a result, open its details, open the document', async () => {
	const page = a.page
	await page.goto(APP)
	await page.locator('#bd-q').waitFor()
	await page.locator('#bd-q').focus()
	await page.keyboard.type(WORD)
	await page.keyboard.press('Enter')
	await page.locator('li.bd-card').first().waitFor({ timeout: 20000 })
	await expect(page.locator('span[role=status]', { hasText: /Mostrando 1–\d+ de \d+ documentos/ })).toBeVisible()
	const link = page.locator('li.bd-card h2 a').first()
	await link.focus()
	await page.keyboard.press('Enter')
	await expect(page.getByText('Copiar enlace')).toBeVisible()
	await expect(page.locator('li.bd-card').first().locator('mark').first()).toBeVisible()
	// the focus ring is visible on whatever has focus (3 px outline per the guidelines)
	const outline = await page.evaluate(() => { const e = document.activeElement; return e ? getComputedStyle(e).outlineWidth : '' })
	expect(parseFloat(outline)).toBeGreaterThanOrEqual(2)
})

test('the URL keeps the search: reload and back work', async () => {
	const page = a.page
	await search(page, WORD)
	await page.getByLabel('Propiedad').selectOption('mine')
	await expect(page).toHaveURL(/propiedad=mine/)
	await page.reload()
	await expect(page.getByLabel('Propiedad')).toHaveValue('mine')
	await page.goBack()
	await expect(page.getByLabel('Propiedad')).toHaveValue('')
})

test('360 px wide: no horizontal scroll on the search page, with a result open', async () => {
	const page = a.page
	await page.setViewportSize({ width: 360, height: 740 })
	await search(page, WORD)
	await page.locator('li.bd-card h2 a').first().click()
	const [scrollW, clientW] = await page.evaluate(() => [document.documentElement.scrollWidth, document.documentElement.clientWidth])
	expect(scrollW).toBeLessThanOrEqual(clientW)
	await page.setViewportSize({ width: 1280, height: 720 })
})

test('reduced motion stops the loading animation', async () => {
	const page = a.page
	await page.emulateMedia({ reducedMotion: 'reduce' })
	await page.route('**/apps/buscador_documental/search', async (route) => { await new Promise((r) => setTimeout(r, 1500)); await route.continue() })
	await page.goto(`${APP}?q=${encodeURIComponent(WORD)}`)
	const sk = page.locator('.bd-skeleton').first()
	await sk.waitFor({ timeout: 10000 })
	expect(await sk.evaluate((e) => getComputedStyle(e).animationName)).toBe('none')
	await page.locator('li.bd-card').first().waitFor({ timeout: 20000 })
	await page.unroute('**/apps/buscador_documental/search')
	await page.emulateMedia({ reducedMotion: 'no-preference' })
})

test('prefers-contrast: more: text stays readable (axe, real contrast)', async () => {
	const page = a.page
	await page.emulateMedia({ contrast: 'more' })
	await search(page, WORD)
	const r = await new AxeBuilder({ page }).include('#content').withRules(['color-contrast']).analyze()
	expect(r.violations.map((v) => `${v.id}: ${v.nodes.map((n) => n.target.join(' ')).join(', ')}`)).toEqual([])
	await page.emulateMedia({ contrast: 'no-preference' })
})

test('axe finds nothing on the search page (results and selected details)', async () => {
	const page = a.page
	await search(page, WORD)
	await page.locator('li.bd-card h2 a').first().click()
	await page.getByText('Copiar enlace').waitFor()
	const r = await new AxeBuilder({ page }).include('#content').analyze()
	expect(r.violations.map((v) => `${v.id}: ${v.help} (${v.nodes.map((n) => n.target.join(' ')).join(', ')})`)).toEqual([])
})

test('a person who reads no document of the index sees an empty state, not an error', async ({ browser }) => {
	const b = await login(browser, 'ui_b', PW)
	await b.page.goto(`${APP}?q=${encodeURIComponent(WORD)}`)
	await expect(b.page.getByText('Sin resultados')).toBeVisible({ timeout: 20000 })
	await b.ctx.close()
})

test('the unified search bar offers documents', async () => {
	const page = a.page
	const r = await page.request.get(`/ocs/v2.php/search/providers/buscador_documental/search?term=${encodeURIComponent(WORD)}&format=json`, { headers: { 'OCS-APIRequest': 'true' } })
	const body = await r.json()
	expect(body.ocs.data.entries.length).toBeGreaterThan(0)
	expect(body.ocs.data.entries.length).toBeLessThanOrEqual(5)
})

test('the Files sidebar has the "Texto reconocido" tab with the recognised text', async () => {
	const page = a.page
	await page.goto(process.env.UI_FILE_URL)
	await page.getByRole('tab', { name: 'Texto reconocido' }).or(page.getByText('Texto reconocido').first()).first().click({ timeout: 30000 })
	await expect(page.locator('buscador-documental-texto')).toBeVisible()
	await expect(page.locator('buscador-documental-texto pre').first()).toContainText(WORD, { timeout: 20000 })
	const r = await new AxeBuilder({ page }).include('buscador-documental-texto').analyze()
	expect(r.violations.map((v) => v.id)).toEqual([])
})

test('administration: health, progress, pause and resume; a non-administrator gets nothing', async ({ browser }) => {
	const page = admin.page
	await page.goto(ADMIN)
	await expect(page.getByText('Salud').or(page.getByText('Servicio y base de datos')).first()).toBeVisible({ timeout: 20000 })
	await expect(page.getByText('Documentos leídos')).toBeVisible()
	await page.getByRole('button', { name: 'Pausar lectura' }).click()
	await expect(page.getByRole('button', { name: 'Reanudar lectura' })).toBeVisible()
	await page.getByRole('button', { name: 'Reanudar lectura' }).click()
	await expect(page.getByRole('button', { name: 'Pausar lectura' })).toBeVisible()
	const r = await new AxeBuilder({ page }).include('#buscador-documental-admin').analyze()
	expect(r.violations.map((v) => `${v.id}: ${v.help}`)).toEqual([])
	await page.setViewportSize({ width: 360, height: 740 })
	await page.goto(ADMIN)
	await page.getByText('Documentos leídos').waitFor()
	const [sw, cw] = await page.evaluate(() => [document.documentElement.scrollWidth, document.documentElement.clientWidth])
	expect(sw).toBeLessThanOrEqual(cw)
	await page.setViewportSize({ width: 1280, height: 720 })
	const status = await a.page.request.get('/apps/buscador_documental/admin/overview', { headers: { 'OCS-APIRequest': 'true' } })
	expect(status.status()).toBe(403)
})
