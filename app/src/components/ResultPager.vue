<template>
	<nav class="bd-pager" aria-label="Paginación de resultados">
		<NcButton variant="tertiary" :disabled="!cursors.first" @click="$emit('go', cursors.first)">
			Primera
		</NcButton>
		<NcButton variant="tertiary" :disabled="!cursors.prev" @click="$emit('go', cursors.prev)">
			Anterior
		</NcButton>
		<NcButton variant="tertiary" :disabled="!cursors.next" @click="$emit('go', cursors.next)">
			Siguiente
		</NcButton>
		<NcButton variant="tertiary" :disabled="!cursors.last" @click="$emit('go', cursors.last)">
			Última
		</NcButton>
	</nav>
</template>

<script setup>
import NcButton from '@nextcloud/vue/components/NcButton'

defineProps({ cursors: { type: Object, required: true } })
defineEmits(['go'])
</script>

<style scoped>
.bd-pager { display: flex; flex-wrap: wrap; gap: 8px; margin: 16px 0; padding-top: 12px; border-top: 1px solid var(--color-border); }
</style>
