/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { computed, toValue } from 'vue'
import { useResizeObserver, useScroll } from '@vueuse/core'
import type { MaybeRefOrGetter } from 'vue'

export interface UseScrollArrowsOptions {
	/**
	 * Distance to scroll per arrow click, in pixels. Defaults to the
	 * element's own `clientWidth` — a full page — when omitted.
	 */
	step?: MaybeRefOrGetter<number>
}

/**
 * Tracks whether a horizontally-scrollable element can still scroll left or
 * right, and exposes the scroll actions an arrow-button pair needs. Built on
 * `useScroll`'s `arrivedState` rather than reading `scrollLeft`/`scrollWidth`
 * by hand — it already recomputes on scroll, on mount, and (with
 * `observe.mutation`) when the content itself changes, so only element
 * resize (e.g. the window resizing) needs wiring up here.
 *
 * @param element the scrollable element, or a ref/getter for one
 * @param options behaviour overrides
 */
export function useScrollArrows(
	element: MaybeRefOrGetter<HTMLElement | null | undefined>,
	options: UseScrollArrowsOptions = {},
) {
	const { arrivedState, measure } = useScroll(element, { observe: { mutation: true } })

	useResizeObserver(element, () => measure())

	const canScrollLeft = computed(() => !arrivedState.left)
	const canScrollRight = computed(() => !arrivedState.right)

	function scrollByStep(direction: -1 | 1) {
		const el = toValue(element)
		if (!el) return
		const distance = toValue(options.step) ?? el.clientWidth
		el.scrollBy({ left: direction * distance, behavior: 'smooth' })
	}

	function scrollToStart() {
		const el = toValue(element)
		if (el) el.scrollLeft = 0
	}

	return { canScrollLeft, canScrollRight, scrollByStep, scrollToStart }
}
