// jsdom lacks these; components use them.
if (!window.matchMedia) {
	window.matchMedia = (query) => ({ matches: false, media: query, addEventListener() {}, removeEventListener() {}, addListener() {}, removeListener() {} })
}
