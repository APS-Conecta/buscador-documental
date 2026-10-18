import axe from 'axe-core'

// Runs axe on a mounted element; returns the violations as readable lines (empty array = clean).
// jsdom has no layout, so colour-contrast is skipped here; the Playwright suite checks it in a real browser.
export async function violations(element) {
	const attached = element.isConnected
	if (!attached) {
		document.body.appendChild(element)
	}
	try {
		const result = await axe.run(element, { rules: { 'color-contrast': { enabled: false }, region: { enabled: false } } })
		return result.violations.map((v) => `${v.id}: ${v.help} (${v.nodes.map((n) => n.target.join(' ')).join(', ')})`)
	} finally {
		if (!attached) {
			element.remove()
		}
	}
}
