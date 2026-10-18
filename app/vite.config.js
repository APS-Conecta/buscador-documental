import { createAppConfig } from '@nextcloud/vite-config'

// Three entries: the search page, the administration view and the Files sidebar tab. App id and version come from
// appinfo/info.xml. Output: js/buscador_documental-<entry>.mjs and one stylesheet in css/.
export default createAppConfig(
	{
		main: 'src/main.js',
		admin: 'src/admin.js',
		'files-tab': 'src/files-tab.js',
	},
	{
		emptyOutputDirectory: { additionalDirectories: ['css'] },
		// One stylesheet per build rather than a stub that @imports a hashed chunk (cached stubs would import a deleted file).
		config: { build: { cssCodeSplit: false } },
		// Test runner: same sources, jsdom, no network.
		// (vitest reads `test` from the same file)
	},
)
