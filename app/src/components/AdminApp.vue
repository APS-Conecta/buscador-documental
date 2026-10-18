<template>
	<div class="bd-admin">
		<NcNoteCard v-if="loadFailed" type="error" role="alert">
			No se pudo cargar el estado. Intente de nuevo en unos minutos.
		</NcNoteCard>
		<template v-else-if="data">
			<NcNoteCard v-if="!data.status.notice_ack_at" type="info" role="status">
				<strong>Solo se leen las carpetas que usted incluya.</strong> Nada sale del servidor. Puede pausar la lectura en cualquier momento.
				<div class="bd-admin__row">
					<NcButton variant="secondary" :disabled="busy" @click="acknowledge">
						Entendido
					</NcButton>
				</div>
			</NcNoteCard>
			<NcNoteCard v-if="message" :type="messageType" role="status">
				{{ message }}
			</NcNoteCard>

			<NcSettingsSection name="Estado" description="Salud del servicio y avance de la lectura de documentos.">
				<div class="bd-kpis">
					<div class="bd-kpi">
						<strong :class="`bd-health--${healthState.id}`">{{ healthState.label }}</strong>
						<span>Servicio y base de datos</span>
					</div>
					<div class="bd-kpi">
						<strong>{{ percent }} %</strong>
						<span>Preparación del índice{{ eta ? ` · ${eta}` : '' }}</span>
						<progress max="100" :value="percent" :aria-label="`Preparación ${percent} por ciento`" />
					</div>
					<div class="bd-kpi">
						<strong>{{ number(data.status.states.indexed ?? 0) }}</strong>
						<span>Documentos leídos</span>
					</div>
					<div class="bd-kpi">
						<strong>{{ number(skippedTotal) }}</strong>
						<span>Omitidos</span>
					</div>
				</div>
				<NcButton v-if="!data.status.paused" variant="secondary" :disabled="busy" @click="pause">
					Pausar lectura
				</NcButton>
				<NcButton v-else variant="primary" :disabled="busy" @click="resume">
					Reanudar lectura
				</NcButton>
				<p v-if="data.status.paused" class="bd-muted">
					La lectura está en pausa: la búsqueda sigue funcionando con lo ya leído.
				</p>
			</NcSettingsSection>

			<NcSettingsSection name="Comprobaciones" description="Cada comprobación dice si algo necesita su atención.">
				<ul class="bd-checks">
					<li v-for="c in data.checks" :key="c.id">
						<span class="bd-status" :class="`bd-status--${c.status}`">{{ STATUS[c.status] ?? c.status }}</span>
						{{ CHECKS[c.id] ?? c.id }}
						<span v-if="c.status !== 'ok'" class="bd-muted"> · {{ c.detail }}</span>
					</li>
				</ul>
			</NcSettingsSection>

			<NcSettingsSection name="Perfil por significado" description="La búsqueda por significado usa un perfil según los recursos del servidor. Para cambiarlo use «occ buscador:profile».">
				<p v-if="!data.status.vectors.generations.length" class="bd-muted">
					Aún no hay un perfil en uso.
				</p>
				<ul v-else class="bd-checks">
					<li v-for="g in data.status.vectors.generations" :key="g.gen">
						<strong>{{ g.profile }}</strong> · {{ GEN_STATE[g.state] ?? g.state }} · {{ Math.round(g.coverage * 100) }} % leído
					</li>
				</ul>
			</NcSettingsSection>

			<NcSettingsSection name="Carpetas incluidas" description="Solo se leen estas carpetas. Para incluir otra use «occ buscador:scope:add».">
				<p v-if="!data.rules.length" class="bd-muted">
					No hay carpetas incluidas: no se lee ningún documento.
				</p>
				<div v-else class="bd-overflow">
					<table>
						<thead>
							<tr>
								<th scope="col">
									Carpeta
								</th>
								<th scope="col">
									<span class="bd-sr">Acciones</span>
								</th>
							</tr>
						</thead>
						<tbody>
							<tr v-for="r in data.rules" :key="r.id">
								<td>{{ ruleName(r) }}</td>
								<td>
									<NcButton variant="tertiary" :disabled="busy" :aria-label="`Excluir ${ruleName(r)}`" @click="confirming = r">
										Excluir
									</NcButton>
								</td>
							</tr>
						</tbody>
					</table>
				</div>
			</NcSettingsSection>

			<NcSettingsSection name="Documentos omitidos" description="Documentos que no se pudieron leer, y por qué.">
				<p v-if="!skippedTotal" class="bd-muted">
					Ningún documento omitido.
				</p>
				<template v-else>
					<label class="bd-field">Mostrar
						<select :value="reason" @change="pickReason($event.target.value)">
							<option value="">Todos los motivos</option>
							<option v-for="(n, k) in data.skipped" :key="k" :value="k">{{ REASONS[k] ?? k }} ({{ number(n) }})</option>
						</select>
					</label>
					<div class="bd-overflow">
						<table>
							<thead>
								<tr>
									<th scope="col">
										Archivo
									</th>
									<th scope="col">
										Motivo
									</th>
									<th scope="col">
										<span class="bd-sr">Acciones</span>
									</th>
								</tr>
							</thead>
							<tbody>
								<tr v-for="i in items" :key="i.file_id">
									<td>{{ i.name ?? 'Documento de otra persona' }}</td>
									<td>{{ REASONS[i.reason] ?? i.reason }}</td>
									<td>
										<NcButton variant="tertiary" :disabled="busy" :aria-label="`Reintentar ${i.name ?? 'documento de otra persona'}`" @click="retry(i)">
											Reintentar
										</NcButton>
									</td>
								</tr>
							</tbody>
						</table>
					</div>
					<NcButton v-if="hasMore" variant="secondary" :disabled="busy" @click="more">
						Mostrar más
					</NcButton>
				</template>
			</NcSettingsSection>
		</template>
		<div v-else role="status">
			Cargando…
		</div>

		<NcDialog v-if="confirming"
			name="Excluir carpeta"
			:message="`Se dejarán de leer los documentos de ${ruleName(confirming)}. Los textos ya leídos se retiran del buscador en unos minutos. Sus archivos no se tocan.`"
			:buttons="dialogButtons"
			@closing="confirming = null" />
	</div>
</template>

<script setup>
import { computed, onMounted, ref } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcSettingsSection from '@nextcloud/vue/components/NcSettingsSection'
import { api } from '../api.js'
import { CHECKS, REASONS, STATUS, etaText, health, progress } from '../lib/admin.js'
import { formatNumber as number } from '../lib/format.js'

const GEN_STATE = { building: 'en preparación', ready: 'lista', active: 'en uso', retired: 'retirada' }

const data = ref(null)
const loadFailed = ref(false)
const busy = ref(false)
const message = ref('')
const messageType = ref('info')
const confirming = ref(null)
const reason = ref('')
const items = ref([])
const hasMore = ref(false)

const healthState = computed(() => health(data.value.checks))
const percent = computed(() => progress(data.value.status.states, data.value.status.eta))
const eta = computed(() => etaText(data.value.status.eta?.text))
const skippedTotal = computed(() => Object.values(data.value.skipped).reduce((a, b) => a + b, 0))
const ruleName = (r) => r.path ?? (r.kind === 'all_groupfolders' ? 'Todas las carpetas de equipo' : 'Carpeta sin acceso para usted')
const dialogButtons = computed(() => [
	{ label: 'Cancelar', variant: 'tertiary', callback: () => {} },
	{ label: 'Excluir', variant: 'primary', callback: () => exclude() },
])

function say(text, type = 'info') {
	message.value = text
	messageType.value = type
}

async function load(reloadList = true) {
	const r = await api.overview()
	if (r.status && !r.checks) {
		loadFailed.value = true
		return
	}
	data.value = r
	if (reloadList) await loadSkipped(true)
}

async function loadSkipped(reset) {
	const r = await api.skipped(reason.value, reset ? 0 : items.value.length)
	if (!Array.isArray(r.items)) return
	items.value = reset ? r.items : [...items.value, ...r.items]
	hasMore.value = r.items.length >= 50
}

async function act(call, ok, reloadList = true) {
	busy.value = true
	const r = await call()
	busy.value = false
	if (r.status === 'unavailable' || r.ok === false || r.httpStatus >= 400) {
		say('No se pudo completar la acción. Intente de nuevo.', 'error')
		return false
	}
	say(ok, 'success')
	await load(reloadList)
	return true
}

const pause = () => act(() => api.pause(), 'Lectura en pausa.')
const resume = () => act(() => api.resume(), 'Lectura reanudada.')
const acknowledge = () => act(() => api.acknowledge(), 'Aviso confirmado.')
async function retry(i) {
	const ok = await act(() => api.retry(i.file_id), 'El documento se leerá de nuevo.', false)
	if (ok) {
		items.value = items.value.filter((x) => x.file_id !== i.file_id)
		if (reason.value && !data.value.skipped[reason.value]) { reason.value = ''; await loadSkipped(true) }
	}
}
async function exclude() {
	const rule = confirming.value
	confirming.value = null
	await act(() => api.removeRule(rule.id), 'Carpeta excluida.')
}
const pickReason = (value) => { reason.value = value; loadSkipped(true) }
const more = () => loadSkipped(false)

onMounted(load)
</script>

<style scoped>
.bd-admin { max-width: 960px; }
.bd-kpis { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 10px; margin: 10px 0 16px; }
.bd-kpi { border: 1px solid var(--color-border); border-radius: var(--border-radius-large); padding: 10px 14px; display: grid; gap: 4px; }
.bd-kpi strong { font-size: 22px; }
.bd-health--ok { color: var(--color-success-text); }
.bd-health--warn { color: var(--color-warning-text); }
.bd-health--fail { color: var(--color-error-text); }
.bd-checks { list-style: none; padding: 0; display: grid; gap: 6px; }
.bd-status { display: inline-block; min-width: 76px; font-weight: 700; }
.bd-status--warn { color: var(--color-warning-text); }
.bd-status--fail { color: var(--color-error-text); }
.bd-muted { color: var(--color-text-maxcontrast); }
.bd-overflow { overflow-x: auto; }
table { border-collapse: collapse; width: 100%; }
th, td { text-align: left; padding: 8px; border-bottom: 1px solid var(--color-border); vertical-align: top; }
.bd-field { display: inline-flex; flex-direction: column; gap: 2px; font-size: 13px; margin-bottom: 8px; }
.bd-field select { min-height: var(--default-clickable-area); }
.bd-admin__row { margin-top: 8px; }
.bd-sr { position: absolute; width: 1px; height: 1px; overflow: hidden; clip: rect(0 0 0 0); }
progress { width: 100%; height: 10px; }
</style>
