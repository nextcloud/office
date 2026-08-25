/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { generateUrl } from '@nextcloud/router'
import { createRouter, createWebHistory } from 'vue-router'
import OfficeOverview from './views/OfficeOverview.vue'
import type { RouteRecordRaw } from 'vue-router'

// creatorId is a category id (see categoryId()). Omitted, the overview falls
// back to the first creator and rewrites the URL to its id.
export const routes: RouteRecordRaw[] = [
	{
		name: 'creator',
		path: '/:creatorId?',
		component: OfficeOverview,
	},
	{
		// PageController's catch-all serves the shell for any path under the app,
		// so anything deeper than a creator id has to land somewhere: the overview,
		// not a blank page.
		path: '/:pathMatch(.*)*',
		redirect: '/',
	},
]

const APP_PATH = '/apps/office'

// The app answers under both /apps/office and /index.php/apps/office, while
// generateUrl() always returns the single form the instance advertises
// (modRewriteWorking). A base in the other form is never stripped off the
// pathname, so no route matches and every deep link lands on the fallback —
// hence the base comes from the URL the page was actually loaded from.
export function historyBase(pathname: string): string {
	const appPathEnd = pathname.lastIndexOf(APP_PATH)
	return appPathEnd === -1
		? generateUrl(APP_PATH)
		: pathname.slice(0, appPathEnd + APP_PATH.length)
}

export const router = createRouter({
	// PageController registers a catch-all route so deep-link reloads still serve
	// the app shell.
	history: createWebHistory(historyBase(window.location.pathname)),
	routes,
})
