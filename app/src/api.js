import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

const url = (path) => generateUrl(`/apps/buscador_documental${path}`)

// Every call resolves to a plain object, never throws: the UI turns the status into a message. 429 carries Retry-After.
async function call(method, path, data) {
	try {
		const r = await axios.request({ method, url: url(path), data, validateStatus: () => true })
		if (r.status === 429) {
			return { status: 'rate_limited', retryAfter: Number(r.headers?.['retry-after']) || 15 }
		}
		if (r.status >= 500 && !r.data?.status) {
			return { status: 'unavailable' }
		}
		return r.data && typeof r.data === 'object' ? { httpStatus: r.status, ...r.data } : { status: 'unavailable' }
	} catch {
		return { status: 'unavailable' }
	}
}

export const api = {
	search: (body) => call('POST', '/search', body),
	folders: () => call('GET', '/folders'),
	fileText: (id) => call('GET', `/file/${id}/text`),
	reread: (id) => call('POST', `/file/${id}/reread`),
	overview: () => call('GET', '/admin/overview'),
	skipped: (reason, offset) => call('GET', `/admin/skipped?${new URLSearchParams({ ...(reason ? { reason } : {}), offset: String(offset ?? 0) })}`),
	pause: () => call('POST', '/admin/pause'),
	resume: () => call('POST', '/admin/resume'),
	acknowledge: () => call('POST', '/admin/notice/ack'),
	removeRule: (id) => call('DELETE', `/admin/scope/${id}`),
	retry: (id) => call('POST', `/admin/skipped/${id}/retry`),
}
