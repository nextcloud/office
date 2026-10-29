import { getCurrentUser } from '@nextcloud/auth'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { getLastTargetFolder, getOverviewGridView, setLastTargetFolder, setOverviewGridView } from './config.ts'

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

describe('getLastTargetFolder', () => {
	it('defaults to the root folder when unset', () => {
		expect(getLastTargetFolder()).toBe('/')
	})

	it('round-trips an absolute path, team folders included', () => {
		setLastTargetFolder('/Team folder/Reports')
		expect(getLastTargetFolder()).toBe('/Team folder/Reports')
	})

	it('keeps the root folder', () => {
		setLastTargetFolder('/')
		expect(getLastTargetFolder()).toBe('/')
	})

	it.each([
		['a relative path', 'garbage'],
		['a parent segment', '/Documents/../../other'],
		['an empty segment', '//Documents'],
		['a trailing slash', '/Documents/'],
		['a current-folder segment', '/./Documents'],
	])('falls back to the root folder for %s', (_, path) => {
		setLastTargetFolder(path)
		expect(getLastTargetFolder()).toBe('/')
	})
})

describe('remembered folder per user', () => {
	afterEach(() => {
		vi.mocked(getCurrentUser).mockReturnValue(null)
	})

	function loginAs(uid: string) {
		vi.mocked(getCurrentUser).mockReturnValue({ uid } as ReturnType<typeof getCurrentUser>)
	}

	it('does not show one user the folder another user picked in the same browser', () => {
		loginAs('alice')
		setLastTargetFolder('/Projects/Confidential')

		loginAs('bob')
		expect(getLastTargetFolder()).toBe('/')

		loginAs('alice')
		expect(getLastTargetFolder()).toBe('/Projects/Confidential')
	})
})

describe('setLastTargetFolder', () => {
	it('stores the folder in the scope the server clears on logout, not under a plain key', () => {
		setLastTargetFolder('/Documents')

		const keys = Object.keys(localStorage)
		expect(keys).toHaveLength(1)
		expect(keys[0].startsWith('nextcloud_vol_')).toBe(true)
		expect(localStorage.getItem(keys[0])).toBe('/Documents')
	})
})
