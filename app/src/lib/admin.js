// Plain-language labels for the administration page. Nothing here is a model, vendor or internal table name.
export const REASONS = {
	encrypted: 'Protegido con contraseña',
	too_large: 'Demasiado grande',
	corrupt: 'Dañado',
	unsupported: 'Formato no admitido',
	ocr_failed: 'No se pudo leer el texto',
	timeout: 'Tardó demasiado',
	internal: 'Error interno',
	unsafe: 'Archivo no seguro',
	server_encrypted: 'Cifrado en el servidor',
}

// For a sentence: "No se pudo leer el documento: <phrase>."
export const REASON_PHRASES = {
	encrypted: 'está protegido con contraseña',
	too_large: 'es demasiado grande',
	corrupt: 'está dañado',
	unsupported: 'su formato no es admitido',
	ocr_failed: 'no se pudo reconocer su texto',
	timeout: 'tardó demasiado en leerse',
	internal: 'falló la lectura; puede pedir que se vuelva a leer',
	unsafe: 'parece peligroso',
	server_encrypted: 'está cifrado en el servidor',
}

export const CHECKS = {
	schema: 'Versión de la base de datos',
	database_roles: 'Permisos de la base de datos',
	extension_unaccent: 'Búsqueda sin tildes',
	worker_secret: 'Clave del servicio de lectura',
	server_encryption: 'Cifrado en el servidor',
	paused: 'Lectura de documentos',
	worker_alive: 'Servicio de lectura',
	background_jobs: 'Tareas en segundo plano',
	outbox: 'Cola de cambios',
	permission_backlog: 'Permisos al día',
	reconciliation: 'Revisión periódica',
	embedding_endpoint: 'Servicio de búsqueda por significado',
	vector_index: 'Índice de búsqueda por significado',
	vector_failures: 'Documentos sin lectura por significado',
	night_window: 'Horario de lectura',
}

export const STATUS = { ok: 'Correcto', warn: 'Atención', fail: 'Falla' }

/** The worst status of a list of checks decides the headline. */
export function health(checks) {
	if (checks.some((c) => c.status === 'fail')) return { id: 'fail', label: 'Requiere atención' }
	if (checks.some((c) => c.status === 'warn')) return { id: 'warn', label: 'Con avisos' }
	return { id: 'ok', label: 'Sano' }
}

/** "unos 2 días", "unas 5 horas", "menos de una hora" from seconds; null when there is no estimate. */
export function duration(seconds) {
	if (seconds === null || seconds === undefined) return null
	if (seconds < 3600) return 'menos de una hora'
	const h = Math.round(seconds / 3600)
	if (h < 48) return `unas ${h} horas`
	return `unos ${Math.round(h / 24)} días`
}

/** One line for an estimate object from the status report. */
export function etaText(eta) {
	switch (eta?.state) {
	case 'done': return 'Al día'
	case 'paused': return 'Lectura en pausa'
	case 'window_closed': return eta.opens_in_seconds ? `Fuera de horario; se reanuda en ${duration(eta.opens_in_seconds)}` : 'Fuera de horario'
	case 'unknown': return 'Calculando el tiempo estimado…'
	case 'ok': return `Tiempo estimado: ${duration(eta.eta_seconds)}`
	default: return ''
	}
}

/** Progress of the text lane in percent, from what is indexed and what is still waiting. */
export function progress(states, eta) {
	const done = states?.indexed ?? 0
	const todo = eta?.text?.remaining ?? 0
	return done + todo === 0 ? 100 : Math.floor((done / (done + todo)) * 100)
}
