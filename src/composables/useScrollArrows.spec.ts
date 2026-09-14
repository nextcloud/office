/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { nextTick, ref } from 'vue'
import { describe, expect, it, vi } from 'vitest'
import { useScrollArrows } from './useScrollArrows.ts'
import { withSetup } from '../test-utils/withSetup.ts'

function makeScrollableElement({ scrollLeft = 0, scrollWidth = 0, clientWidth = 0 } = {}) {
	const el = document.createElement('ul')
	Object.defineProperty(el, 'scrollLeft', { value: scrollLeft, writable: true, configurable: true })
	Object.defineProperty(el, 'scrollWidth', { value: scrollWidth, configurable: true })
	Object.defineProperty(el, 'clientWidth', { value: clientWidth, configurable: true })
	el.scrollBy = vi.fn()
	document.body.appendChild(el)
	return el
}

const resizeObserverInstances: { disconnect: ReturnType<typeof vi.fn> }[] = []

class ResizeObserverStub {

	observe = vi.fn()
	unobserve = vi.fn()
	disconnect = vi.fn()

	constructor(public callback: ResizeObserverCallback) {
		resizeObserverInstances.push(this)
	}

}

describe('useScrollArrows', () => {
	it('reflects the element\'s scroll position at creation', () => {
		const el = makeScrollableElement({ scrollLeft: 0, scrollWidth: 500, clientWidth: 200 })

		const { canScrollLeft, canScrollRight } = useScrollArrows(ref(el))

		expect(canScrollLeft.value).toBe(false)
		expect(canScrollRight.value).toBe(true)
	})

	it('hides both arrows when the content does not overflow', () => {
		const el = makeScrollableElement({ scrollLeft: 0, scrollWidth: 100, clientWidth: 100 })

		const { canScrollLeft, canScrollRight } = useScrollArrows(ref(el))

		expect(canScrollLeft.value).toBe(false)
		expect(canScrollRight.value).toBe(false)
	})

	it('updates on a real scroll event', async () => {
		const el = makeScrollableElement({ scrollLeft: 0, scrollWidth: 500, clientWidth: 200 })
		const { canScrollLeft, canScrollRight } = useScrollArrows(ref(el))

		Object.defineProperty(el, 'scrollLeft', { value: 300, configurable: true })
		el.dispatchEvent(new Event('scroll'))
		await nextTick()

		expect(canScrollLeft.value).toBe(true)
		expect(canScrollRight.value).toBe(false)
	})

	describe('scrollByStep', () => {
		it('scrolls by the element\'s clientWidth by default', () => {
			const el = makeScrollableElement({ clientWidth: 250 })
			const { scrollByStep } = useScrollArrows(ref(el))

			scrollByStep(1)

			expect(el.scrollBy).toHaveBeenCalledWith({ left: 250, behavior: 'smooth' })
		})

		it('scrolls left as a negative step', () => {
			const el = makeScrollableElement({ clientWidth: 250 })
			const { scrollByStep } = useScrollArrows(ref(el))

			scrollByStep(-1)

			expect(el.scrollBy).toHaveBeenCalledWith({ left: -250, behavior: 'smooth' })
		})

		it('uses the step option over the element\'s clientWidth when given', () => {
			const el = makeScrollableElement({ clientWidth: 250 })
			const { scrollByStep } = useScrollArrows(ref(el), { step: 172 })

			scrollByStep(1)

			expect(el.scrollBy).toHaveBeenCalledWith({ left: 172, behavior: 'smooth' })
		})
	})

	it('scrollToStart resets scrollLeft to 0', () => {
		const el = makeScrollableElement({ scrollLeft: 300, scrollWidth: 500, clientWidth: 200 })
		const { scrollToStart } = useScrollArrows(ref(el))

		scrollToStart()

		expect(el.scrollLeft).toBe(0)
	})

	// Verifies the composable actually releases its resize observer on
	// unmount — needs a real component lifecycle (withSetup), not a bare
	// call, since that's the one thing that can't be exercised without one.
	it('disconnects its resize observer on unmount', () => {
		const OriginalResizeObserver = globalThis.ResizeObserver
		resizeObserverInstances.length = 0
		globalThis.ResizeObserver = ResizeObserverStub as unknown as typeof ResizeObserver

		const el = makeScrollableElement()
		const { unmount } = withSetup(() => useScrollArrows(ref(el)))
		unmount()

		expect(resizeObserverInstances[0].disconnect).toHaveBeenCalled()
		globalThis.ResizeObserver = OriginalResizeObserver
	})
})
