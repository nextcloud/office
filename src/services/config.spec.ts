import { beforeEach, describe, expect, it } from 'vitest'
import { getOverviewGridView, setOverviewGridView } from './config.ts'

beforeEach(() => {
	localStorage.clear()
})

describe('getOverviewGridView', () => {
	it('returns false when unset', () => {
		expect(getOverviewGridView()).toBe(false)
	})

	it('returns true only for the exact string "true"', () => {
		localStorage.setItem('office.overview.gridView', 'true')
		expect(getOverviewGridView()).toBe(true)
	})

	it('returns false for "false" or any other value', () => {
		localStorage.setItem('office.overview.gridView', 'false')
		expect(getOverviewGridView()).toBe(false)

		localStorage.setItem('office.overview.gridView', 'garbage')
		expect(getOverviewGridView()).toBe(false)
	})
})

describe('setOverviewGridView', () => {
	it('round-trips true/false through localStorage as strings', () => {
		setOverviewGridView(true)
		expect(localStorage.getItem('office.overview.gridView')).toBe('true')

		setOverviewGridView(false)
		expect(localStorage.getItem('office.overview.gridView')).toBe('false')
	})
})
