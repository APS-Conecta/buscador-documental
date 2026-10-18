import { getSidebar } from '@nextcloud/files'
import { api } from './api.js'
import { describe } from './lib/tab.js'

const ICON = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><path fill="currentColor" d="M5 3h10l4 4v14H5V3Zm9 1.5V8h3.5L14 4.5ZM7 11v1.5h10V11H7Zm0 3.5V16h10v-1.5H7Zm0 3.5v1.5h6V18H7Z"/></svg>'

const STYLE = `
:host { display: block; padding: 8px 16px 16px; color: var(--color-main-text); font: inherit; }
.note { padding: 10px 14px; border-radius: var(--border-radius-element, 8px); border-left: 4px solid var(--color-primary-element); background: var(--color-background-hover); margin: 0 0 12px; }
.note.warning { border-color: var(--color-warning-text); }
.note.error { border-color: var(--color-error-text); }
h3 { margin: 12px 0 4px; font-size: 14px; }
pre { white-space: pre-wrap; overflow-wrap: anywhere; font: inherit; margin: 0; }
.actions { display: flex; gap: 8px; flex-wrap: wrap; margin-top: 12px; }
button { min-height: var(--default-clickable-area, 44px); padding: 0 16px; border-radius: var(--border-radius-large, 16px); border: 1px solid var(--color-border-maxcontrast); background: var(--color-main-background); color: inherit; font: inherit; font-weight: 600; cursor: pointer; }
button:focus-visible { outline: 3px solid var(--color-primary-element); outline-offset: 2px; }
button:disabled { opacity: .55; cursor: not-allowed; }
.badge { font-weight: 700; color: var(--color-warning-text); }
`

export class RecognisedText extends HTMLElement {
	#node = null
	#active = false
	#seq = 0

	connectedCallback() {
		this.attachShadow({ mode: 'open' })
		this.#render({ kind: 'message', tone: 'info', text: 'Cargando…' }, true)
		this.#load()
	}

	get node() { return this.#node }
	set node(value) { this.#node = value; if (this.isConnected) this.#load() }
	get active() { return this.#active }
	set active(value) { const was = this.#active; this.#active = Boolean(value); if (this.#active && !was && this.isConnected) this.#load() }
	// The sidebar also sets these; the tab does not use them.
	folder = undefined
	view = undefined

	async #load() {
		const id = this.#node?.fileid
		if (!id || !this.shadowRoot) return
		const mine = ++this.#seq
		const answer = await api.fileText(id)
		if (mine !== this.#seq) return
		this.#render(describe(answer))
	}

	#render(d, loading = false) {
		const root = this.shadowRoot
		root.replaceChildren()
		const style = document.createElement('style')
		style.textContent = STYLE
		root.append(style)
		const box = document.createElement('div')
		box.setAttribute('role', loading ? 'status' : d.tone === 'error' ? 'alert' : 'status')
		if (d.kind === 'message') {
			const p = document.createElement('p')
			p.className = `note ${d.tone}`
			p.textContent = d.text
			box.append(p)
		} else {
			if (d.uncertain) {
				const p = document.createElement('p')
				p.className = 'note warning'
				const b = document.createElement('span')
				b.className = 'badge'
				b.textContent = `Lectura incierta (${d.confidence} %).`
				p.append(b, ' Una copia más nítida mejora el resultado.')
				box.append(p)
			} else if (d.confidence !== null) {
				const p = document.createElement('p')
				p.textContent = `Confianza de la lectura: ${d.confidence} %`
				box.append(p)
			}
			for (const page of d.pages) {
				if (d.pages.length > 1) {
					const h = document.createElement('h3')
					h.textContent = `Página ${page.page_no}`
					box.append(h)
				}
				const pre = document.createElement('pre')
				pre.textContent = page.text
				box.append(pre)
			}
			if (d.truncated) {
				const p = document.createElement('p')
				p.className = 'note'
				p.textContent = 'El texto es muy largo: se muestra solo el comienzo.'
				box.append(p)
			}
		}
		root.append(box)
		if (d.kind === 'text' || d.reread) {
			const actions = document.createElement('div')
			actions.className = 'actions'
			if (d.kind === 'text') {
				actions.append(this.#button('Copiar texto', (b) => this.#copy(b, d)))
			}
			actions.append(this.#button('Volver a leer este documento', (b) => this.#reread(b, box)))
			root.append(actions)
		}
	}

	#button(label, onClick) {
		const b = document.createElement('button')
		b.type = 'button'
		b.textContent = label
		b.addEventListener('click', () => onClick(b))
		return b
	}

	async #copy(button, d) {
		try {
			await navigator.clipboard.writeText(d.pages.map((p) => p.text).join('\n\n'))
			button.textContent = 'Texto copiado'
		} catch {
			button.textContent = 'No se pudo copiar'
		}
	}

	async #reread(button, box) {
		button.disabled = true
		const r = await api.reread(this.#node.fileid)
		button.disabled = false
		const note = document.createElement('p')
		note.className = 'note'
		note.setAttribute('role', 'status')
		note.textContent = r.status === 'queued' ? 'El documento se leerá de nuevo en unos minutos.'
			: r.status === 'rate_limited' ? 'Demasiados intentos: espere un momento.'
				: 'Solo la persona propietaria o un administrador puede pedir una nueva lectura.'
		box.prepend(note)
	}
}

export const TAG = 'buscador-documental-texto'

export function registerTab(sidebar = getSidebar()) {
	sidebar.registerTab({
		id: 'buscador_documental',
		order: 50,
		displayName: 'Texto reconocido',
		iconSvgInline: ICON,
		tagName: TAG,
		enabled: ({ node }) => node?.type === 'file',
		onInit: () => { if (!customElements.get(TAG)) customElements.define(TAG, RecognisedText) },
	})
}

if (typeof window !== 'undefined' && !window.__BD_TEST__) {
	registerTab()
}
