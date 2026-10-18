<template>
	<div class="bd-seg" role="group" aria-label="Modo de búsqueda">
		<button v-for="m in MODES"
			:key="m.id"
			type="button"
			:aria-pressed="String(effective === m.id)"
			:aria-disabled="disabled(m) ? 'true' : undefined"
			:title="disabled(m) ? reason : undefined"
			@click="pick(m)">
			{{ m.label }}
		</button>
	</div>
</template>

<script setup>
import { computed } from 'vue'

const props = defineProps({ modelValue: { type: String, required: true }, semantic: { type: Boolean, default: false }, reason: { type: String, default: '' } })
const emit = defineEmits(['update:modelValue'])

const MODES = [{ id: 'exacta', label: 'Exacta' }, { id: 'significado', label: 'Por significado' }, { id: 'mixta', label: 'Mixta' }]
const disabled = (m) => m.id !== 'exacta' && !props.semantic
// When meaning search is off the page works in Exacta; the choice is remembered in the URL for when it comes back.
const effective = computed(() => (props.semantic ? props.modelValue : 'exacta'))
const pick = (m) => { if (!disabled(m)) emit('update:modelValue', m.id) }
</script>

<style scoped>
.bd-seg { display: inline-flex; border: 1px solid var(--color-border-maxcontrast); border-radius: var(--border-radius-large); overflow: hidden; }
.bd-seg button { min-height: var(--default-clickable-area); padding: 0 14px; border: 0; background: transparent; cursor: pointer; color: inherit; font: inherit; }
.bd-seg button[aria-pressed="true"] { background: var(--color-primary-element); color: var(--color-primary-element-text); font-weight: 700; }
.bd-seg button[aria-disabled="true"] { color: var(--color-text-maxcontrast); cursor: not-allowed; }
.bd-seg button:focus-visible { outline: 3px solid var(--color-primary-element); outline-offset: -3px; }
</style>
