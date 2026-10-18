import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { violations } from '../test/a11y.js'

vi.mock('../api.js', () => ({ api: { search: vi.fn(), folders: vi.fn(), reread: vi.fn() } }))
vi.mock('@nextcloud/router', () => ({ generateUrl: (p, params = {}) => p.replace(/\{(\w+)\}/g, (_, k) => params[k]) }))
const { api } = await import('../api.js')
const { default: SearchApp } = await import('./SearchApp.vue')

const hit = (id, extra = {}) => ({ file_id: id, title: `Circular ${id}.pdf`, path: '/Equipo/Circulares', mtime: 1790000000, owner: 'M. Rojas', uncertain: false, page_no: 3, snippet: 'campaña de <mark>vacunación</mark> para adultos', ...extra })
const ok = (hits, extra = {}) => ({ status: 'ok', message: '', notice: null, hits, page: { from: hits.length ? 1 : 0, to: hits.length, total: hits.length, capped: false, size: 25 }, cursors: { first: null, prev: null, next: null, last: null }, ...extra })
const ready = { semantic: true, reason: null, coverage: 1 }

async function open(search = '', readiness = ready) {
	window.history.replaceState(null, '', `/apps/buscador_documental/${search}`)
	const w = mount(SearchApp, { props: { readiness }, attachTo: document.body })
	await flushPromises()
	return w
}

beforeEach(() => {
	vi.resetAllMocks()
	api.folders.mockResolvedValue({ folders: [{ id: 7, path: '/Equipo/Circulares' }] })
	document.body.innerHTML = '<div id="skip-actions"></div>'   // Nextcloud's page provides it; NcContent teleports into it
})

describe('SearchApp states', () => {
	it('starts idle without calling the server when there is no query', async () => {
		const w = await open()
		expect(api.search).not.toHaveBeenCalled()
		expect(w.text()).toContain('Escriba palabras o una pregunta')
		w.unmount()
	})

	it('searches from the URL and shows results with the summary line', async () => {
		api.search.mockResolvedValue(ok([hit(1), hit(2, { uncertain: true })]))
		const w = await open('?q=vacunacion')
		expect(api.search.mock.calls[0][0]).toMatchObject({ query: 'vacunacion', mode: 'mixta', sort: 'relevance', page_size: 25, filters: {} })
		expect(w.findAll('li.bd-card')).toHaveLength(2)
		expect(w.text()).toContain('Mostrando 1–2 de 2 documentos')
		expect(w.text()).toContain('Lectura incierta')
		expect(w.find('mark').text()).toBe('vacunación')
		w.unmount()
	})

	it('shows the no-results state and offers to clear filters only when some are on', async () => {
		api.search.mockResolvedValue(ok([]))
		let w = await open('?q=zzz')
		expect(w.text()).toContain('Sin resultados')
		expect(w.text()).not.toContain('Quitar filtros')
		w.unmount()
		w = await open('?q=zzz&propiedad=mine')
		expect(w.text()).toContain('Sin resultados con estos filtros')
		expect(w.text()).toContain('Quitar filtros')
		w.unmount()
	})

	it('says the service is down with role=alert and reassures about the files', async () => {
		api.search.mockResolvedValue({ status: 'unavailable' })
		const w = await open('?q=x')
		const alert = w.find('[role="alert"]')
		expect(alert.exists()).toBe(true)
		expect(alert.text()).toContain('Sus archivos están a salvo')
		w.unmount()
	})

	it('shows the semantic fallback notice from the server', async () => {
		api.search.mockResolvedValue(ok([hit(1)], { notice: { code: 'semantic_unavailable', message: 'La búsqueda por significado aún no está disponible; se muestran resultados por palabras.' } }))
		const w = await open('?q=x&modo=significado')
		expect(w.text()).toContain('se muestran resultados por palabras')
		w.unmount()
	})

	it('shows preparation progress and disables the meaning modes with an explanation', async () => {
		api.search.mockResolvedValue(ok([hit(1)]))
		const w = await open('?q=x', { semantic: false, reason: 'coverage', coverage: 0.38 })
		expect(w.text()).toContain('Preparando: 38 %')
		const buttons = w.findAll('.bd-seg button')
		expect(buttons.map((b) => b.attributes('aria-disabled'))).toEqual([undefined, 'true', 'true'])
		expect(buttons[1].attributes('title')).toBeTruthy()
		expect(buttons[0].attributes('aria-pressed')).toBe('true')
		w.unmount()
	})

	it('reports rate limiting with a countdown', async () => {
		api.search.mockResolvedValue({ status: 'rate_limited', retryAfter: 12 })
		const w = await open('?q=x')
		expect(w.text()).toContain('Espere 12 s')
		w.unmount()
	})

	it('repeats a search once when its cursor expired', async () => {
		api.search.mockResolvedValueOnce({ status: 'cursor_expired' }).mockResolvedValueOnce(ok([hit(1)]))
		const w = await open('?q=x')
		expect(api.search).toHaveBeenCalledTimes(2)
		expect(api.search.mock.calls[1][0].cursor).toBeUndefined()
		expect(w.findAll('li.bd-card')).toHaveLength(1)
		w.unmount()
	})
})

describe('SearchApp interaction', () => {
	it('submits the typed query, keeps it in the URL and returns to the first page', async () => {
		api.search.mockResolvedValue(ok([hit(1)]))
		const w = await open()
		await w.find('#bd-q').setValue('glucosa  ')
		await w.find('form').trigger('submit')
		await flushPromises()
		expect(api.search.mock.calls[0][0].query).toBe('glucosa')
		expect(window.location.search).toBe('?q=glucosa')
		w.unmount()
	})

	it('a navigation scope sets the ownership filter and searches again', async () => {
		api.search.mockResolvedValue(ok([hit(1)]))
		const w = await open('?q=x')
		const mine = w.findAll('a').find((e) => e.text() === 'Mis archivos')
		await mine.trigger('click')
		await flushPromises()
		expect(api.search.mock.calls.at(-1)[0].filters).toEqual({ owner: 'mine' })
		expect(window.location.search).toContain('propiedad=mine')
		w.unmount()
	})

	it('pages with the cursor the server gave', async () => {
		api.search.mockResolvedValue(ok([hit(1)], { cursors: { first: null, prev: null, next: 'CUR2', last: null }, page: { from: 1, to: 25, total: 60, capped: false, size: 25 } }))
		const w = await open('?q=x')
		const next = w.findAll('button').find((b) => b.text() === 'Siguiente')
		await next.trigger('click')
		await flushPromises()
		expect(api.search.mock.calls.at(-1)[0].cursor).toBe('CUR2')
		w.unmount()
	})

	it('ignores a slow answer that arrives after a newer search', async () => {
		let slow
		api.search.mockImplementationOnce(() => new Promise((r) => { slow = r })).mockResolvedValueOnce(ok([hit(2)]))
		const w = await open('?q=uno')
		await w.find('#bd-q').setValue('dos')
		await w.find('form').trigger('submit')
		await flushPromises()
		slow(ok([hit(1)]))
		await flushPromises()
		expect(w.findAll('li.bd-card')).toHaveLength(1)
		expect(w.find('li.bd-card a').text()).toBe('Circular 2.pdf')
		w.unmount()
	})

	it('selecting a result opens the details with the page action and the re-read request for uncertain readings', async () => {
		api.search.mockResolvedValue(ok([hit(1, { uncertain: true })]))
		api.reread.mockResolvedValue({ status: 'queued' })
		const w = await open('?q=x')
		await w.find('li.bd-card a').trigger('click')
		await flushPromises()
		const side = document.body.querySelector('aside, [class*="app-sidebar"]')
		expect(side).not.toBeNull()
		expect(document.body.textContent).toContain('Abrir en la página 3')
		const again = [...document.body.querySelectorAll('button')].find((b) => b.textContent.includes('Volver a leer'))
		again.click()
		await flushPromises()
		expect(api.reread).toHaveBeenCalledWith(1)
		expect(document.body.textContent).toContain('se leerá de nuevo')
		w.unmount()
	})
})

describe('SearchApp accessibility', () => {
	it.each([
		['results', () => api.search.mockResolvedValue(ok([hit(1), hit(2, { uncertain: true })]))],
		['no results', () => api.search.mockResolvedValue(ok([]))],
		['service down', () => api.search.mockResolvedValue({ status: 'unavailable' })],
	])('has no axe violations (%s)', async (_n, arrange) => {
		arrange()
		const w = await open('?q=x')
		const found = await violationsOf(w)
		expect(found.join('\n')).toBe('')
		w.unmount()
	})
})

const violationsOf = (w) => violations(w.element)
