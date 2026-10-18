import { createApp } from 'vue'
import AdminApp from './components/AdminApp.vue'

const mount = document.getElementById('buscador-documental-admin')
mount.classList.remove('aps-mount-loading')
createApp(AdminApp).mount(mount)
