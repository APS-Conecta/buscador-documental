const MESES = ['ene', 'feb', 'mar', 'abr', 'may', 'jun', 'jul', 'ago', 'sept', 'oct', 'nov', 'dic']
// The only zone literal on the client; it must match SearchRequest::TIME_ZONE.
const TIME_ZONE = 'America/Santiago'

/** [year, month, day] of `date` on the calendar of `timeZone`. */
export function calendarDay(date, timeZone = TIME_ZONE) {
	const parts = Object.fromEntries(new Intl.DateTimeFormat('en-US', { timeZone, day: 'numeric', month: 'numeric', year: 'numeric' }).formatToParts(date).map((p) => [p.type, p.value]))
	return [Number(parts.year), Number(parts.month), Number(parts.day)]
}

/** "12 sept 2026" from a Unix time in seconds (the server's modification time). */
export function formatDate(seconds, timeZone = TIME_ZONE) {
	const [year, month, day] = calendarDay(new Date(seconds * 1000), timeZone)
	return `${day} ${MESES[month - 1]} ${year}`
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
