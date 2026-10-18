<template>
	<div class="bd-filters" role="group" aria-label="Filtros">
		<fieldset class="bd-filters__types">
			<legend>Tipo</legend>
			<label v-for="t in TYPES" :key="t.id" class="bd-check">
				<input type="checkbox" :checked="modelValue.types.includes(t.id)" @change="toggleType(t.id, $event.target.checked)">
				{{ t.label }}
			</label>
		</fieldset>
		<label class="bd-field">Modificado
			<select :value="modelValue.when" @change="set({ when: $event.target.value })">
				<option v-for="w in WHEN" :key="w.id" :value="w.id">{{ w.label }}</option>
			</select>
		</label>
		<template v-if="modelValue.when === 'range'">
			<label class="bd-field">Desde
				<input type="date" :value="modelValue.from" @change="set({ from: $event.target.value })">
			</label>
			<label class="bd-field">Hasta
				<input type="date" :value="modelValue.to" @change="set({ to: $event.target.value })">
			</label>
		</template>
		<label v-if="folders.length" class="bd-field">Carpeta
			<select :value="modelValue.folder ?? ''" @change="set({ folder: $event.target.value ? Number($event.target.value) : null })">
				<option value="">Todas</option>
				<option v-for="f in folders" :key="f.id" :value="f.id">{{ f.path }}</option>
			</select>
		</label>
		<label class="bd-field">Propiedad
			<select :value="modelValue.owner" @change="set({ owner: $event.target.value })">
				<option value="">Todos</option>
				<option value="mine">Mis archivos</option>
				<option value="shared">Compartidos conmigo</option>
			</select>
		</label>
		<label class="bd-check bd-filters__uncertain">
			<input type="checkbox" :checked="modelValue.uncertain" @change="set({ uncertain: $event.target.checked })">
			Solo con lectura incierta
		</label>
		<NcButton v-if="active" variant="secondary" @click="$emit('clear')">
			Quitar filtros
		</NcButton>
	</div>
</template>

<script setup>
import NcButton from '@nextcloud/vue/components/NcButton'

const props = defineProps({ modelValue: { type: Object, required: true }, folders: { type: Array, default: () => [] }, active: { type: Boolean, default: false } })
const emit = defineEmits(['update:modelValue', 'clear'])

const TYPES = [
	{ id: 'pdf', label: 'PDF' }, { id: 'word', label: 'Documentos' }, { id: 'hoja', label: 'Planillas' },
	{ id: 'presentacion', label: 'Presentaciones' }, { id: 'texto', label: 'Texto' }, { id: 'imagen', label: 'Imágenes' },
]
const WHEN = [
	{ id: '', label: 'Cualquier fecha' }, { id: 'week', label: 'Última semana' }, { id: 'month', label: 'Último mes' },
	{ id: 'year', label: 'Último año' }, { id: 'range', label: 'Rango de fechas' },
]
const set = (patch) => emit('update:modelValue', { ...props.modelValue, ...patch })
const toggleType = (id, on) => set({ types: on ? [...props.modelValue.types, id] : props.modelValue.types.filter((t) => t !== id) })
</script>

<style scoped>
.bd-filters { display: flex; flex-wrap: wrap; gap: 8px 16px; align-items: end; margin: 12px 0; }
.bd-filters fieldset { border: 0; margin: 0; padding: 0; display: flex; flex-wrap: wrap; gap: 4px 12px; align-items: center; }
.bd-filters legend { font-size: 13px; color: var(--color-text-maxcontrast); padding: 0; }
.bd-field { display: inline-flex; flex-direction: column; gap: 2px; font-size: 13px; color: var(--color-text-maxcontrast); }
.bd-field select, .bd-field input { min-height: var(--default-clickable-area); max-width: 100%; }
.bd-check { display: inline-flex; align-items: center; gap: 8px; min-height: var(--default-clickable-area); color: var(--color-main-text); font-size: 15px; }
.bd-check input { width: 20px; height: 20px; }
</style>
