import { loadState } from '@nextcloud/initial-state'
import { createApp } from 'vue'
import SearchApp from './components/SearchApp.vue'

const mount = document.getElementById('buscador-documental')
mount.classList.remove('aps-mount-loading')
createApp(SearchApp, { readiness: loadState('buscador_documental', 'readiness', { semantic: false, reason: null, coverage: 0 }) }).mount(mount)
