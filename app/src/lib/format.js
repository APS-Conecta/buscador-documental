const MESES = ['ene', 'feb', 'mar', 'abr', 'may', 'jun', 'jul', 'ago', 'sept', 'oct', 'nov', 'dic']

/** "12 sept 2026" from a Unix time in seconds (the server's modification time). */
export function formatDate(seconds) {
	const d = new Date(seconds * 1000)
	return `${d.getUTCDate()} ${MESES[d.getUTCMonth()]} ${d.getUTCFullYear()}`
}

/** Chilean thousands grouping with a no-break space: 1 214. */
export function formatNumber(n) {
	return String(Math.trunc(n)).replace(/\B(?=(\d{3})+(?!\d))/g, ' ')
}

/** "26–50 de 137 documentos" for the summary line. */
export function summaryLine(page) {
	if (!page || page.total === 0) {
		return ''
	}
	const total = page.capped ? `más de ${formatNumber(page.total - 1)}` : formatNumber(page.total)
	return `Mostrando ${formatNumber(page.from)}–${formatNumber(page.to)} de ${total} documentos`
}
