import { generateUrl } from '@nextcloud/router'

/** Where "Abrir" goes. The Files app opens the document in its viewer; the viewer does not honour a page fragment (spikes/s6), so the page is stated in words (ADR-0016). */
export function fileUrl(hit, origin = window.location.origin) {
	return origin + generateUrl('/f/{id}', { id: hit.file_id })
}

export function openLabel(hit) {
	return hit.page_no ? `Abrir documento (página ${hit.page_no})` : 'Abrir documento'
}
