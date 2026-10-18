import { REASONS } from './admin.js'

/** What the tab shows for each answer of /file/{id}/text. Pure: returns a description, the element renders it. */
export function describe(answer, uncertainBelow = 60) {
	if (!answer || answer.status === 'unavailable') return { kind: 'message', tone: 'error', text: 'No se pudo cargar el texto. Intente de nuevo en unos minutos.' }
	if (answer.status === 'not_found') return { kind: 'message', tone: 'info', text: 'No hay texto reconocido para este documento.' }
	switch (answer.status) {
	case 'restricted': return { kind: 'message', tone: 'info', text: 'El texto no se muestra porque este recurso no permite descargar.' }
	case 'pending': return { kind: 'message', tone: 'info', text: 'Este documento aún no se ha leído o cambió hace poco. Estará listo en unos minutos.' }
	case 'none': return { kind: 'message', tone: 'info', text: 'Este documento no tiene texto reconocido.' }
	case 'skipped': return { kind: 'message', tone: 'warning', text: `No se pudo leer: ${REASONS[answer.reason] ?? 'motivo desconocido'}.`, reread: true }
	case 'indexed': {
		const confs = answer.pages.map((p) => p.ocr_conf).filter((c) => c !== null)
		const avg = confs.length ? confs.reduce((a, b) => a + b, 0) / confs.length : null
		return {
			kind: 'text',
			pages: answer.pages,
			truncated: answer.truncated,
			confidence: avg === null ? null : Math.round(avg),
			uncertain: avg !== null && avg < uncertainBelow,
			reread: true,
		}
	}
	default: return { kind: 'message', tone: 'error', text: 'No se pudo cargar el texto.' }
	}
}
