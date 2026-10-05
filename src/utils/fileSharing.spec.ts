/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { describe, expect, it } from 'vitest'
import { makeNode } from '../test-utils/fixtures.ts'
import { isIncomingShare, isShared, outgoingShareTypes } from './fileSharing.ts'

describe('outgoingShareTypes', () => {
	it('returns [] for a file with no share types', () => {
		expect(outgoingShareTypes(makeNode())).toEqual([])
	})

	it('flattens the nested DAV shape for a single share type', () => {
		expect(outgoingShareTypes(makeNode({ shareTypes: [3] }))).toEqual([3])
	})

	it('flattens the nested DAV shape for several share types', () => {
		expect(outgoingShareTypes(makeNode({ shareTypes: [0, 3] }))).toEqual([0, 3])
	})
})

describe('isIncomingShare', () => {
	it('is true when the file is owned by someone other than the current user', () => {
		expect(isIncomingShare(makeNode({ owner: 'bob' }), 'alice')).toBe(true)
	})

	it('is false when the current user owns the file', () => {
		expect(isIncomingShare(makeNode({ owner: 'alice' }), 'alice')).toBe(false)
	})

	it('is false when the current user is unknown', () => {
		expect(isIncomingShare(makeNode({ owner: 'bob' }), null)).toBe(false)
	})
})

describe('isShared', () => {
	it('is true for a file the current user has shared out', () => {
		expect(isShared(makeNode({ owner: 'alice', shareTypes: [0] }), 'alice')).toBe(true)
	})

	it('is true for a file shared with the current user', () => {
		expect(isShared(makeNode({ owner: 'bob' }), 'alice')).toBe(true)
	})

	it('is false for an unshared file the current user owns', () => {
		expect(isShared(makeNode({ owner: 'alice' }), 'alice')).toBe(false)
	})
})
