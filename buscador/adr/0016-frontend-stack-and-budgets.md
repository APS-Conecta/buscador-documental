# ADR-0016 — Frontend: Vue 3 on Vite, committed bundle, measured budgets

Status: accepted (2026-10-19)
Affects: `app/src`, `app/js`, `app/package.json`, CI, S6 acceptance rows A-UX-4..11

## Context
The mockup (`buscador/mockup/v1`) was accepted at U0 and the guidelines (`03-ui-guidelines.md`) fix the components, theme and states. The sibling apps use Vue 3 with `@nextcloud/vue` 9; one (epidemiologia) uses Vite and cut its bundle from 1.08 MB to 359 KB only by importing every component by subpath. None of them has an automated accessibility check or a size budget, and none has a browser image for layout tests.

## Decision
- Vue 3 + `@nextcloud/vue` 9 by subpath imports only, built with Vite 7 and `@nextcloud/vite-config` inside a pinned Node container (nothing runs on the host). Three entries: `main` (search page), `admin`, `files-tab`.
- The built `js/` and `css/` are committed, as in the sibling apps; CI rebuilds and fails on any difference.
- Budget: the first-load chunk of `main` is at most 250 KB gzip, checked in CI.
- Tests: vitest + `@vue/test-utils` + jsdom with axe-core on every component and state; a Playwright run in a pinned image against the live stack for the keyboard-only path, 360 px without horizontal scroll, `prefers-contrast: more`, reduced motion and real-browser axe.
- Nothing is loaded from a third-party host: fonts and icons come from the server.
- Snippets contain only `<mark>` from the server; the client renders them through a tiny allow-list parser, never `v-html` on raw text.

## Consequences
The bundle is part of review (a diff of minified code is noise: reviewers check the source and the drift gate). Adding a dependency needs the size gate to stay green.
