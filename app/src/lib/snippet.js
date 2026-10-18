// The server sends snippets HTML-escaped with <mark> around matches and nothing else. This is the whole "renderer":
// it returns plain text segments and the page puts them in the DOM as text, never as HTML.
const ENTITIES = { '&amp;': '&', '&lt;': '<', '&gt;': '>', '&quot;': '"', '&#039;': '\'' }
const decode = (s) => s.replace(/&(?:amp|lt|gt|quot|#0?39);/g, (m) => ENTITIES[m])

export function parseSnippet(html) {
	if (typeof html !== 'string' || html === '') {
		return []
	}
	const out = []
	for (const part of html.split(/(<mark>|<\/mark>)/)) {
		if (part === '<mark>' || part === '</mark>') {
			out.push({ marker: part === '<mark>' })
		} else if (part !== '') {
			out.push({ text: decode(part) })
		}
	}
	let open = false
	const segments = []
	for (const p of out) {
		if ('marker' in p) {
			open = p.marker
		} else {
			segments.push({ text: p.text, mark: open })
		}
	}
	return segments
}
