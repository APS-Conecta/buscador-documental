import { defineConfig } from '@playwright/test'

export default defineConfig({
	testDir: '.',
	testMatch: '*.spec.mjs',
	timeout: 60000,
	retries: 0,
	workers: 1,
	reporter: [['list']],
	use: { baseURL: process.env.BASE ?? 'http://127.0.0.1:8191', locale: 'es-CL', colorScheme: 'light' },
})
