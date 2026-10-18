import { defineConfig } from 'vitest/config'
import vue from '@vitejs/plugin-vue'

export default defineConfig({
	plugins: [vue()],
	test: { server: { deps: { inline: [/@nextcloud\/vue/, /vue-material-design-icons/] } }, environment: 'jsdom', include: ['src/**/*.test.js'], setupFiles: ['src/test/setup.js'] },
})
