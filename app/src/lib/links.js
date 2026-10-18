import { generateUrl } from '@nextcloud/router'

/** Where "Abrir" goes. The Files app opens the document in its viewer; page deep links are decided by the S6 spike (ADR-0016). */
export function fileUrl(hit, origin = window.location.origin) {
	return origin + generateUrl('/f/{id}', { id: hit.file_id })
}

export function openLabel(hit) {
	return hit.page_no ? `Abrir en la página ${hit.page_no}` : 'Abrir documento'
}
