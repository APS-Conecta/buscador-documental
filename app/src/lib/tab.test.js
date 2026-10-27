import { describe as suite, expect, it } from 'vitest'
import { describe } from './tab.js'

suite('describe (Files tab states)', () => {
	it('treats unknown and unreadable files alike', () => {
		expect(describe({ status: 'not_found' }).text).toBe('No hay texto reconocido para este documento.')
	})

	it('refuses to show text when the resource forbids downloads', () => {
		const d = describe({ status: 'restricted' })
		expect(d.kind).toBe('message')
		expect(d.text).toContain('no permite descargar')
	})

	it('explains a skipped file in plain words and offers a new reading', () => {
		const d = describe({ status: 'skipped', reason: 'encrypted' })
		expect(d.text).toBe('No se pudo leer el documento: está protegido con contraseña.')
		expect(d.reread).toBe(true)
	})

	it('says a server-encrypted file is encrypted by the server, not protected by a password', () => {
		const d = describe({ status: 'skipped', reason: 'server_encrypted' })
		expect(d.text).toBe('No se pudo leer el documento: está cifrado en el servidor.')
		expect(d.text).not.toContain('contraseña')
	})

	it('flags an uncertain reading from the average confidence', () => {
		const d = describe({ status: 'indexed', truncated: false, pages: [{ page_no: 1, text: 'a', ocr_conf: 40, uncertain: true }, { page_no: 2, text: 'b', ocr_conf: 70, uncertain: false }] })
		expect(d.confidence).toBe(55)
		expect(d.uncertain).toBe(true)
		expect(describe({ status: 'indexed', pages: [{ page_no: 1, text: 'a', ocr_conf: 90, uncertain: false }] }).uncertain).toBe(false)
		expect(describe({ status: 'indexed', pages: [{ page_no: 1, text: 'a', ocr_conf: null }] }).confidence).toBeNull()
	})

	it('reports an unreachable service as an error', () => {
		expect(describe({ status: 'unavailable' }).tone).toBe('error')
	})
})
