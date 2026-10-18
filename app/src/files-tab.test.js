import { beforeEach, describe, expect, it, vi } from 'vitest'
import { violations } from './test/a11y.js'

window.__BD_TEST__ = true
vi.mock('@nextcloud/files', () => ({ getSidebar: () => ({ registerTab: () => {} }) }))
vi.mock('./api.js', () => ({ api: { fileText: vi.fn(), reread: vi.fn() } }))
const { api } = await import('./api.js')
const { RecognisedText, TAG, registerTab } = await import('./files-tab.js')

if (!customElements.get(TAG)) customElements.define(TAG, RecognisedText)
const tick = () => new Promise((r) => setTimeout(r, 0))

async function tab(answer) {
	api.fileText.mockResolvedValue(answer)
	const el = document.createElement(TAG)
	el.node = { fileid: 42, type: 'file' }
	document.body.append(el)
	await tick()
	return el
}

beforeEach(() => { vi.resetAllMocks(); document.body.innerHTML = '' })

describe('registerTab', () => {
	it('registers one tab for files only, defining its element on init', () => {
		const sidebar = { registerTab: vi.fn() }
		registerTab(sidebar)
		const t = sidebar.registerTab.mock.calls[0][0]
		expect(t).toMatchObject({ id: 'buscador_documental', displayName: 'Texto reconocido', tagName: TAG })
		expect(t.enabled({ node: { type: 'file' } })).toBe(true)
		expect(t.enabled({ node: { type: 'folder' } })).toBe(false)
	})
})

describe('Texto reconocido tab', () => {
	it('shows recognised text as text, never as HTML', async () => {
		const el = await tab({ status: 'indexed', truncated: false, pages: [{ page_no: 1, text: '<img src=x onerror=alert(1)> hemoglobina', ocr_conf: 90 }] })
		const root = el.shadowRoot
		expect(root.querySelector('img')).toBeNull()
		expect(root.querySelector('pre').textContent).toContain('<img src=x')
		expect(api.fileText).toHaveBeenCalledWith(42)
	})

	it('shows the uncertain-reading warning and page headings', async () => {
		const el = await tab({ status: 'indexed', truncated: true, pages: [{ page_no: 1, text: 'a', ocr_conf: 40 }, { page_no: 2, text: 'b', ocr_conf: 50 }] })
		const text = el.shadowRoot.textContent
		expect(text).toContain('Lectura incierta (45 %)')
		expect(text).toContain('Página 2')
		expect(text).toContain('solo el comienzo')
	})

	it('shows no text and no copy button when the answer is restricted', async () => {
		const el = await tab({ status: 'restricted' })
		expect(el.shadowRoot.textContent).toContain('no permite descargar')
		expect([...el.shadowRoot.querySelectorAll('button')].map((b) => b.textContent)).toEqual([])
	})

	it('asks for a new reading and says what happened', async () => {
		api.reread.mockResolvedValue({ status: 'forbidden' })
		const el = await tab({ status: 'indexed', truncated: false, pages: [{ page_no: 1, text: 'a', ocr_conf: 90 }] })
		const again = [...el.shadowRoot.querySelectorAll('button')].find((b) => b.textContent.includes('Volver a leer'))
		again.click()
		await tick()
		expect(api.reread).toHaveBeenCalledWith(42)
		expect(el.shadowRoot.textContent).toContain('Solo la persona propietaria')
	})

	it('loads again when the sidebar shows another file', async () => {
		const el = await tab({ status: 'none' })
		api.fileText.mockResolvedValue({ status: 'pending' })
		el.node = { fileid: 43, type: 'file' }
		await tick()
		expect(api.fileText).toHaveBeenLastCalledWith(43)
		expect(el.shadowRoot.textContent).toContain('aún no se ha leído')
	})

	it('has no axe violations', async () => {
		const el = await tab({ status: 'indexed', truncated: false, pages: [{ page_no: 1, text: 'texto', ocr_conf: 40 }] })
		const host = document.createElement('div')
		host.innerHTML = el.shadowRoot.innerHTML.replace(/<style>[\s\S]*?<\/style>/, '')
		expect((await violations(host)).join('\n')).toBe('')
	})
})
