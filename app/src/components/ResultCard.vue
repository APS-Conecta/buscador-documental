<template>
	<li class="bd-card" :class="{ 'bd-card--selected': selected }">
		<h2 class="bd-card__title">
			<a :href="url" @click.prevent="$emit('select', hit)">{{ hit.title }}<span v-if="selected" class="bd-sr"> (seleccionado)</span></a>
			<span v-if="hit.uncertain" class="bd-badge">Lectura incierta</span>
		</h2>
		<p class="bd-card__meta">
			{{ meta }}
		</p>
		<Snippet :html="hit.snippet" />
		<NcButton variant="tertiary" :href="url">
			{{ label }}
		</NcButton>
	</li>
</template>

<script setup>
import { computed } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import Snippet from './Snippet.vue'
import { formatDate } from '../lib/format.js'
import { fileUrl, openLabel } from '../lib/links.js'

const props = defineProps({ hit: { type: Object, required: true }, selected: { type: Boolean, default: false } })
defineEmits(['select'])

const url = computed(() => fileUrl(props.hit))
const label = computed(() => openLabel(props.hit))
const meta = computed(() => [props.hit.path, props.hit.page_no ? `p. ${props.hit.page_no}` : null, props.hit.mtime ? formatDate(props.hit.mtime) : null, props.hit.owner].filter(Boolean).join(' · '))
</script>

<style scoped>
.bd-card { list-style: none; border: 1px solid var(--color-border); border-radius: var(--border-radius-large); padding: 12px 16px; }
.bd-card--selected { border-color: var(--color-primary-element); box-shadow: 0 0 0 2px var(--color-primary-element-light); }
.bd-card__title { font-family: var(--font-face); font-size: 16px; font-weight: 700; margin: 0; }
.bd-card__title a { color: var(--color-main-text); text-decoration: underline; }
.bd-card__meta { color: var(--color-text-maxcontrast); font-size: 13px; margin: 0; overflow-wrap: anywhere; }
.bd-sr { position: absolute; width: 1px; height: 1px; overflow: hidden; clip: rect(0 0 0 0); }
.bd-badge { display: inline-flex; align-items: center; gap: 4px; font-size: 12px; font-weight: 700; border: 1px solid var(--color-warning-text); color: var(--color-warning-text); border-radius: 999px; padding: 0 8px; margin-left: 8px; }
.bd-badge::before { content: "\26A0"; }
</style>
