const MESES = ['ene', 'feb', 'mar', 'abr', 'may', 'jun', 'jul', 'ago', 'sept', 'oct', 'nov', 'dic']

/** "12 sept 2026" from a Unix time in seconds (the server's modification time). */
export function formatDate(seconds, timeZone = 'America/Santiago') {
	const parts = Object.fromEntries(new Intl.DateTimeFormat('en-US', { timeZone, day: 'numeric', month: 'numeric', year: 'numeric' }).formatToParts(new Date(seconds * 1000)).map((p) => [p.type, p.value]))
	return `${parts.day} ${MESES[Number(parts.month) - 1]} ${parts.year}`
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
