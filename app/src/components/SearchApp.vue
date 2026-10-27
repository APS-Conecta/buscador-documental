<template>
	<NcContent app-name="buscador_documental">
		<NcAppNavigation :aria-label="'Buscador Documental'">
			<NcAppNavigationList>
				<NcAppNavigationItem v-for="s in SCOPES"
					:key="s.id"
					:name="s.label"
					:active="scope === s.id"
					@click="pickScope(s)" />
			</NcAppNavigationList>
		</NcAppNavigation>
		<NcAppContent>
			<div class="bd-main">
				<h1>Buscador Documental</h1>
				<form class="bd-search" role="search" @submit.prevent="submit">
					<label class="bd-sr" for="bd-q">Buscar documentos</label>
					<input id="bd-q"
						v-model="draft"
						type="search"
						placeholder="Escriba palabras o una pregunta"
						maxlength="500"
						autocomplete="off">
					<NcButton type="submit" variant="primary">
						Buscar
					</NcButton>
				</form>

				<NcNoteCard v-if="!readiness.semantic" type="info" role="status">
					<template v-if="readiness.reason === 'coverage'">
						<strong>Preparando: {{ percent }} %</strong> · la búsqueda por palabras está lista; la búsqueda por significado, pendiente.
						<progress max="100" :value="percent" aria-label="Avance de preparación" />
					</template>
					<template v-else>
						La búsqueda por significado aún no está disponible: se usan palabras exactas.
					</template>
				</NcNoteCard>
				<NcNoteCard v-if="result.notice" type="warning" role="status">
					{{ result.notice.message }}
				</NcNoteCard>

				<div class="bd-toolbar">
					<ModeToggle v-model="state.mode" :semantic="readiness.semantic" :reason="semanticReason" @update:model-value="changed" />
				</div>
				<FilterBar v-model="filters" :folders="folders" :active="hasActiveFilters" @update:model-value="changed" @clear="clearFilters" />

				<div ref="region" tabindex="-1" class="bd-region">
				<div v-if="phase === 'loading'" role="status">
					<span class="bd-sr">Buscando…</span>
					<div class="bd-skeleton" aria-hidden="true" />
					<div class="bd-skeleton" aria-hidden="true" />
					<div class="bd-skeleton" aria-hidden="true" />
				</div>
				<NcNoteCard v-else-if="phase === 'unavailable'" type="error" role="alert">
					<strong>Búsqueda no disponible.</strong> Sus archivos están a salvo. Intente de nuevo en unos minutos.
				</NcNoteCard>
				<NcNoteCard v-else-if="phase === 'rate_limited'" type="warning" role="alert">
					Demasiadas búsquedas. <span v-if="countdown > 0" aria-hidden="true">Espere {{ countdown }} s.</span><span v-else>Ya puede volver a buscar.</span>
				</NcNoteCard>
				<NcNoteCard v-else-if="phase === 'too_many' || phase === 'invalid'" type="warning" role="alert">
					{{ result.message }}
				</NcNoteCard>
				<NcEmptyContent v-else-if="phase === 'idle'" role="status" name="Escriba palabras o una pregunta" description="Se buscan solo los documentos que usted puede leer." />
				<NcEmptyContent v-else-if="phase === 'empty'" role="status" :name="hasActiveFilters ? 'Sin resultados con estos filtros' : 'Sin resultados'" :description="hasActiveFilters ? 'Quite filtros o pruebe con menos palabras.' : 'Pruebe con menos palabras.'">
					<template v-if="hasActiveFilters" #action>
						<NcButton variant="secondary" @click="clearFilters">
							Quitar filtros
						</NcButton>
					</template>
				</NcEmptyContent>
				<template v-else>
					<div class="bd-summary">
						<span role="status" aria-live="polite">{{ summary }}</span>
						<span class="bd-summary__controls">
							<label class="bd-field">Ordenar por
								<select :value="state.sort || defaultSort" @change="state.sort = $event.target.value; changed()">
									<option v-for="o in SORTS" :key="o.id" :value="o.id">{{ o.label }}</option>
								</select>
							</label>
							<label class="bd-field">Resultados por página
								<select :value="state.size" @change="state.size = Number($event.target.value); changed()">
									<option v-for="n in [25, 50, 100]" :key="n" :value="n">{{ n }}</option>
								</select>
							</label>
						</span>
					</div>
					<p v-if="result.page.capped" class="bd-cap">
						Puede haber más documentos de los que se muestran; afine su búsqueda.
					</p>
					<ul class="bd-results">
						<ResultCard v-for="h in result.hits"
							:key="h.file_id"
							:hit="h"
							:selected="selected?.file_id === h.file_id"
							@select="select" />
					</ul>
					<ResultPager :cursors="result.cursors" @go="go" />
				</template>
				</div>
			</div>
		</NcAppContent>
		<DocumentSidebar v-if="selected" :hit="selected" @close="closeDetails" />
	</NcContent>
</template>

<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, reactive, ref } from 'vue'
import NcAppContent from '@nextcloud/vue/components/NcAppContent'
import NcAppNavigation from '@nextcloud/vue/components/NcAppNavigation'
import NcAppNavigationItem from '@nextcloud/vue/components/NcAppNavigationItem'
import NcAppNavigationList from '@nextcloud/vue/components/NcAppNavigationList'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcContent from '@nextcloud/vue/components/NcContent'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import DocumentSidebar from './DocumentSidebar.vue'
import FilterBar from './FilterBar.vue'
import ModeToggle from './ModeToggle.vue'
import ResultCard from './ResultCard.vue'
import ResultPager from './ResultPager.vue'
import { api } from '../api.js'
import { summaryLine } from '../lib/format.js'
import { hasFilters, parseState, serializeState, toRequest, withoutFilters } from '../lib/urlState.js'

const props = defineProps({ readiness: { type: Object, default: () => ({ semantic: false, reason: null, coverage: 0 }) } })

const SCOPES = [
	{ id: 'all', label: 'Todos los documentos', patch: { owner: '', uncertain: false } },
	{ id: 'mine', label: 'Mis archivos', patch: { owner: 'mine', uncertain: false } },
	{ id: 'shared', label: 'Compartidos conmigo', patch: { owner: 'shared', uncertain: false } },
	{ id: 'low', label: 'Con lectura incierta', patch: { owner: '', uncertain: true } },
]
const SORTS = [
	{ id: 'relevance', label: 'Relevancia' }, { id: 'modified_desc', label: 'Más recientes' }, { id: 'modified_asc', label: 'Más antiguos' },
	{ id: 'name_asc', label: 'Nombre (A-Z)' }, { id: 'name_desc', label: 'Nombre (Z-A)' },
]
const EMPTY = () => ({ status: 'ok', message: '', notice: null, hits: [], page: { from: 0, to: 0, total: 0, capped: false, size: 25 }, cursors: { first: null, prev: null, next: null, last: null } })

const state = reactive(parseState(window.location.search))
const draft = ref(state.q)
const result = ref(EMPTY())
const phase = ref(state.q ? 'loading' : 'idle')
const selected = ref(null)
const folders = ref([])
const countdown = ref(0)
const region = ref(null)
let opener = null
let seq = 0
let timer = null

// FilterBar edits a copy and emits it back: the fields it owns are merged into the one state object.
const filters = computed({
	get: () => state,
	set: (v) => Object.assign(state, { types: v.types, when: v.when, from: v.from, to: v.to, folder: v.folder, owner: v.owner, uncertain: v.uncertain }),
})
const hasActiveFilters = computed(() => hasFilters(state))
const scope = computed(() => SCOPES.find((s) => s.patch.owner === state.owner && s.patch.uncertain === state.uncertain)?.id ?? null)
const defaultSort = computed(() => (state.q ? 'relevance' : 'modified_desc'))
const summary = computed(() => summaryLine(result.value.page))
const semanticReason = computed(() => (props.readiness.reason === 'coverage' ? 'Disponible cuando termine la preparación' : 'No disponible por ahora: la búsqueda por significado no responde'))
const percent = computed(() => Math.round((props.readiness.coverage ?? 0) * 100))

function push() {
	const qs = serializeState(state)
	const next = window.location.pathname + (qs ? `?${qs}` : '')
	if (next !== window.location.pathname + window.location.search) {
		window.history.pushState(null, '', next)
	}
}

function select(hit) {
	opener = document.activeElement
	selected.value = hit
	nextTick(() => document.querySelector('.app-sidebar button, .app-sidebar a')?.focus())
}

function closeDetails() {
	selected.value = null
	nextTick(() => opener?.focus?.())
}

// After a search the focus moves to the results region, so keyboard and screen-reader users continue from the new results.
function focusResults() {
	nextTick(() => { region.value?.focus({ preventScroll: false }); window.scrollTo?.(0, 0) })
}

async function run(cursor = null, retried = false, moveFocus = false) {
	if (!state.q) {
		seq++                                              // a search still in flight must not bring its results back
		result.value = EMPTY()
		phase.value = 'idle'
		return
	}
	const mine = ++seq
	phase.value = 'loading'
	const r = await api.search(toRequest(props.readiness.semantic ? state : { ...state, mode: 'exacta' }, cursor))
	if (mine !== seq) {
		return
	}
	if (r.status === 'cursor_expired' && !retried) {
		return run(null, true, moveFocus)
	}
	result.value = { ...EMPTY(), ...r }
	if (moveFocus) focusResults()
	if (r.status === 'ok') {
		phase.value = r.hits?.length ? 'results' : 'empty'
		if (selected.value && !r.hits.some((h) => h.file_id === selected.value.file_id)) {
			selected.value = null
		}
	} else if (r.status === 'rate_limited') {
		phase.value = 'rate_limited'
		startCountdown(r.retryAfter)
	} else {
		phase.value = ['too_many', 'invalid'].includes(r.status) ? r.status : 'unavailable'
	}
}

function startCountdown(seconds) {
	clearInterval(timer)
	countdown.value = seconds
	timer = setInterval(() => {
		countdown.value = Math.max(0, countdown.value - 1)
		if (countdown.value === 0) {
			clearInterval(timer)
		}
	}, 1000)
}

function submit() {
	state.q = draft.value.trim()
	changed()
}

function changed() {
	push()
	run(null, false, true)
}

function pickScope(s) {
	Object.assign(state, s.patch)
	changed()
}

function clearFilters() {
	Object.assign(state, withoutFilters(state))
	changed()
}

const go = (cursor) => run(cursor, false, true)

function onPop() {
	Object.assign(state, parseState(window.location.search))
	draft.value = state.q
	run()
}

onMounted(async () => {
	window.addEventListener('popstate', onPop)
	if (state.q) {
		run()
	}
	const f = await api.folders()
	folders.value = Array.isArray(f.folders) ? f.folders : []
})
onBeforeUnmount(() => {
	window.removeEventListener('popstate', onPop)
	clearInterval(timer)
})
</script>

<style>
/* 3 px focus ring on everything focusable inside the app (guidelines: WCAG 2.2 AA, visible focus) */
.app-buscador_documental a:focus-visible,
.app-buscador_documental button:focus-visible,
.app-buscador_documental input:focus-visible,
.app-buscador_documental select:focus-visible { outline: 3px solid var(--color-primary-element); outline-offset: 2px; }
</style>

<style scoped>
.bd-main { padding: 20px 24px; max-width: 1100px; }
h1 { font-family: var(--font-face-title, inherit); font-size: 24px; margin: 0 0 14px; }
.bd-search { display: flex; gap: 8px; flex-wrap: wrap; }
.bd-search input[type="search"] { flex: 1 1 240px; min-height: var(--default-clickable-area); padding: 0 16px; border: 2px solid var(--color-border-maxcontrast); border-radius: var(--border-radius-large); }
.bd-sr { position: absolute; width: 1px; height: 1px; overflow: hidden; clip: rect(0 0 0 0); }
.bd-toolbar { margin: 12px 0 0; }
.bd-summary { display: flex; flex-wrap: wrap; justify-content: space-between; align-items: end; gap: 8px; color: var(--color-text-maxcontrast); margin: 8px 0; }
.bd-summary__controls { display: flex; gap: 12px; flex-wrap: wrap; }
.bd-field { display: inline-flex; flex-direction: column; gap: 2px; font-size: 13px; }
.bd-field select { min-height: var(--default-clickable-area); max-width: 100%; }
.bd-region:focus { outline: none; }
.bd-cap { color: var(--color-text-maxcontrast); }
.bd-results { margin: 0; padding: 0; display: grid; gap: 8px; }
.bd-skeleton { height: 84px; margin-bottom: 8px; border-radius: var(--border-radius-large); background: linear-gradient(90deg, var(--color-background-dark), var(--color-background-hover), var(--color-background-dark)); background-size: 200% 100%; animation: bd-shimmer 1.2s infinite; }
@keyframes bd-shimmer { to { background-position: -200% 0; } }
@media (prefers-reduced-motion: reduce) { .bd-skeleton { animation: none; } }
progress { width: 100%; height: 10px; }
@media (max-width: 720px) { .bd-main { padding: 16px; } }
</style>
