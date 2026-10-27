import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { violations } from '../test/a11y.js'

vi.mock('../api.js', () => ({ api: { overview: vi.fn(), skipped: vi.fn(), pause: vi.fn(), resume: vi.fn(), acknowledge: vi.fn(), removeRule: vi.fn(), retry: vi.fn() } }))
const { api } = await import('../api.js')
const { default: AdminApp } = await import('./AdminApp.vue')

const overview = (over = {}) => ({
	checks: [{ id: 'schema', status: 'ok', detail: 'version 5' }, { id: 'worker_alive', status: 'warn', detail: 'no worker seen' }],
	rules: [{ id: 1, kind: 'folder', file_id: 7, path: '/Equipo/Circulares' }, { id: 2, kind: 'folder', file_id: 9, path: null }],
	skipped: { encrypted: 2, corrupt: 1 },
	...over,
	status: { mode: 'opt_in', paused: false, notice_ack_at: '2026-10-18T10:00:00Z', states: { indexed: 38 }, eta: { text: { state: 'ok', eta_seconds: 7200, remaining: 62 } }, vectors: { generations: [{ gen: 1, profile: 'bge-m3', state: 'active', coverage: 0.5 }] }, ...over.status },
})
const skipped = { counts: {}, items: [{ file_id: 5, reason: 'encrypted', name: 'Convenio.pdf' }, { file_id: 6, reason: 'corrupt', name: null }] }

async function open(over) {
	api.overview.mockResolvedValue(overview(over))
	api.skipped.mockResolvedValue(skipped)
	const w = mount(AdminApp, { attachTo: document.body })
	await flushPromises()
	return w
}
const button = (w, text) => w.findAll('button').find((b) => b.text() === text)

beforeEach(() => { vi.resetAllMocks(); document.body.innerHTML = '' })

describe('AdminApp', () => {
	it('shows health, progress with the estimate, and the counts', async () => {
		const w = await open()
		expect(w.text()).toContain('Con avisos')
		expect(w.text()).toContain('38 %')
		expect(w.text()).toContain('Tiempo estimado: unas 2 horas')
		expect(w.text()).toContain('Documentos leídos')
		w.unmount()
	})

	it('shows plain-language names and never a technical id for known checks', async () => {
		const w = await open()
		expect(w.text()).toContain('Servicio de lectura')
		expect(w.text()).not.toContain('worker_alive')
		w.unmount()
	})

	it('names a failing exclusion tag check in Spanish', async () => {
		const w = await open({ checks: [{ id: 'exclusion_tag', status: 'fail', detail: 'missing' }] })
		expect(w.text()).toContain('Etiqueta de exclusión')
		expect(w.text()).not.toContain('exclusion_tag')
		w.unmount()
	})

	it('shows the first-run notice until it is acknowledged, then not', async () => {
		let w = await open({ status: { notice_ack_at: null } })
		expect(w.text()).toContain('Solo se leen las carpetas que usted incluya')
		api.acknowledge.mockResolvedValue({ ok: true })
		await button(w, 'Entendido').trigger('click')
		await flushPromises()
		expect(api.acknowledge).toHaveBeenCalled()
		w.unmount()
		w = await open()
		expect(w.text()).not.toContain('Solo se leen las carpetas que usted incluya')
		w.unmount()
	})

	it('pauses and resumes', async () => {
		api.pause.mockResolvedValue({ paused: true })
		let w = await open()
		await button(w, 'Pausar lectura').trigger('click')
		await flushPromises()
		expect(api.pause).toHaveBeenCalled()
		w.unmount()
		w = await open({ status: { paused: true } })
		expect(button(w, 'Reanudar lectura')).toBeTruthy()
		expect(w.text()).toContain('La lectura está en pausa')
		w.unmount()
	})

	it('names folders it can show and does not reveal ones it cannot', async () => {
		const w = await open()
		expect(w.text()).toContain('/Equipo/Circulares')
		expect(w.text()).toContain('Carpeta sin acceso para usted')
		w.unmount()
	})

	it('asks for confirmation before excluding a folder and only then calls the server', async () => {
		api.removeRule.mockResolvedValue({ ok: true })
		const w = await open()
		await w.findAll('button').filter((b) => b.text() === 'Excluir')[0].trigger('click')
		await flushPromises()
		expect(api.removeRule).not.toHaveBeenCalled()
		const confirm = [...document.body.querySelectorAll('button')].find((b) => b.textContent.trim() === 'Excluir' && b.closest('[role="dialog"]'))
		expect(confirm).toBeTruthy()
		confirm.click()
		await flushPromises()
		expect(api.removeRule).toHaveBeenCalledWith(1)
		w.unmount()
	})

	it('lists skipped files with a reason and retries one; a file the admin cannot read has no name', async () => {
		api.retry.mockResolvedValue({ ok: true })
		const w = await open()
		expect(w.text()).toContain('Convenio.pdf')
		expect(w.text()).toContain('Protegido con contraseña')
		expect(w.text()).toContain('Documento de otra persona')
		await button(w, 'Reintentar').trigger('click')
		await flushPromises()
		expect(api.retry).toHaveBeenCalledWith(5)
		w.unmount()
	})

	it('names a server-encrypted skip and the server encryption check in plain words, never as a password', async () => {
		api.overview.mockResolvedValue(overview({ checks: [{ id: 'server_encryption', status: 'warn', detail: 'on' }], skipped: { server_encrypted: 1 } }))
		api.skipped.mockResolvedValue({ counts: {}, items: [{ file_id: 8, reason: 'server_encrypted', name: 'Acta.pdf' }] })
		const w = mount(AdminApp, { attachTo: document.body })
		await flushPromises()
		expect(w.find('.bd-checks').text()).toContain('Cifrado en el servidor')
		expect(w.find('select').text()).toContain('Cifrado en el servidor (1)')
		expect(w.findAll('tr').find((r) => r.text().includes('Acta.pdf')).text()).toContain('Cifrado en el servidor')
		expect(w.text()).not.toContain('Protegido con contraseña')
		expect(w.text()).not.toContain('server_encrypted')
		expect(w.text()).not.toContain('server_encryption')
		w.unmount()
	})

	it('does not say an action worked when the server refused it', async () => {
		api.pause.mockResolvedValue({ httpStatus: 412, message: 'CSRF check failed' })
		const w = await open()
		await button(w, 'Pausar lectura').trigger('click')
		await flushPromises()
		expect(w.text()).toContain('No se pudo completar la acción')
		expect(w.text()).not.toContain('Lectura en pausa.')
		w.unmount()
	})

	it('says so when the status cannot be loaded', async () => {
		api.overview.mockResolvedValue({ status: 'unavailable' })
		const w = mount(AdminApp)
		await flushPromises()
		expect(w.find('[role="alert"]').text()).toContain('No se pudo cargar el estado')
		w.unmount()
	})

	it('has no axe violations', async () => {
		const w = await open()
		const found = await violations(w.element)
		expect(found.join('\n')).toBe('')
		w.unmount()
	})
})
