<template>
	<NcAppSidebar :name="hit.title" :subname="subname" @close="$emit('close')">
		<div class="bd-side">
			<NcNoteCard v-if="hit.uncertain" type="warning">
				<strong>Lectura incierta.</strong> Una copia más nítida mejora el resultado.
			</NcNoteCard>
			<p v-if="hit.page_no" class="bd-side__page">
				La coincidencia está en la página {{ hit.page_no }}.
			</p>
			<Snippet :html="hit.snippet" />
			<div class="bd-side__actions">
				<NcButton variant="primary" :href="url">
					{{ label }}
				</NcButton>
				<NcButton variant="secondary" @click="copy">
					{{ copied ? 'Enlace copiado' : 'Copiar enlace' }}
				</NcButton>
				<NcButton v-if="hit.uncertain" variant="tertiary" :disabled="rereading" @click="reread">
					Volver a leer este documento
				</NcButton>
			</div>
			<p v-if="message" role="status" class="bd-side__msg">
				{{ message }}
			</p>
		</div>
	</NcAppSidebar>
</template>

<script setup>
import { computed, ref, watch } from 'vue'
import NcAppSidebar from '@nextcloud/vue/components/NcAppSidebar'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import Snippet from './Snippet.vue'
import { api } from '../api.js'
import { formatDate } from '../lib/format.js'
import { fileUrl, openLabel } from '../lib/links.js'

const props = defineProps({ hit: { type: Object, required: true } })
defineEmits(['close'])

const copied = ref(false)
const rereading = ref(false)
const message = ref('')
watch(() => props.hit.file_id, () => { copied.value = false; message.value = '' })

const url = computed(() => fileUrl(props.hit))
const label = computed(() => openLabel(props.hit))
const subname = computed(() => [props.hit.path, props.hit.mtime ? formatDate(props.hit.mtime) : null, props.hit.owner].filter(Boolean).join(' · '))

async function copy() {
	try {
		await navigator.clipboard.writeText(url.value)
		copied.value = true
	} catch {
		message.value = 'No se pudo copiar el enlace: cópielo desde la barra del navegador.'
	}
}

async function reread() {
	rereading.value = true
	const r = await api.reread(props.hit.file_id)
	rereading.value = false
	message.value = r.status === 'queued' ? 'El documento se leerá de nuevo en unos minutos.' : r.status === 'rate_limited' ? 'Demasiados intentos: espere un momento.' : r.status === 'unavailable' ? 'No se pudo pedir la lectura ahora. Intente de nuevo en unos minutos.' : 'Solo la persona propietaria o un administrador puede pedir una nueva lectura.'
}
</script>

<style scoped>
.bd-side { padding: 0 16px 16px; }
.bd-side__actions { display: grid; gap: 8px; margin-top: 12px; }
.bd-side__page { font-weight: 600; margin: 8px 0 0; }
.bd-side__msg { margin-top: 12px; color: var(--color-text-maxcontrast); }
</style>
