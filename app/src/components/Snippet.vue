<template>
	<p v-if="segments.length" class="bd-snippet">
		<template v-for="(s, i) in segments" :key="i">
			<mark v-if="s.mark">{{ s.text }}</mark>
			<template v-else>{{ s.text }}</template>
		</template>
	</p>
</template>

<script setup>
import { computed } from 'vue'
import { parseSnippet } from '../lib/snippet.js'

const props = defineProps({ html: { type: String, default: '' } })
const segments = computed(() => parseSnippet(props.html))
</script>

<style scoped>
.bd-snippet { margin: 6px 0; overflow-wrap: anywhere; }
mark { background: var(--color-primary-element-light); color: inherit; text-decoration: underline; text-decoration-thickness: 2px; border-radius: 3px; padding: 0 2px; }
</style>
