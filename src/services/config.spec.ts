import { beforeEach, describe, expect, it } from 'vitest'
import { getOverviewGridView, setOverviewGridView } from './config.ts'

beforeEach(() => {
	localStorage.clear()
})

describe('getOverviewGridView', () => {
	it('defaults to grid when unset', () => {
		expect(getOverviewGridView()).toBe(true)
	})

	it('returns false only for an explicit "false"', () => {
		localStorage.setItem('office.overview.gridView', 'false')
		expect(getOverviewGridView()).toBe(false)
	})

	it('returns true for "true" or any other value', () => {
		localStorage.setItem('office.overview.gridView', 'true')
		expect(getOverviewGridView()).toBe(true)

		localStorage.setItem('office.overview.gridView', 'garbage')
		expect(getOverviewGridView()).toBe(true)
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
