/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { describe, expect, it } from 'vitest'
import { translate } from '@nextcloud/l10n'

// vitest.setup.ts mocks translate() for every spec in the suite. Pin its
// contract against the real implementation's default behaviour — real
// translate() escapes every placeholder value (escape-html) before
// substitution, and a mock that skips this hides a whole class of bug: a
// component whose translated string reaches an HTML sink (or, conversely,
// an ARIA attribute, where the escaping is actually wrong) behaves
// differently under test than for a real user.
describe('translate() mock', () => {
	it('escapes HTML-significant characters in a placeholder value', () => {
		const result = translate('office', 'Shared by {owner}', { owner: '<b>&"\'' })

		expect(result).toBe('Shared by &lt;b&gt;&amp;&quot;&#39;')
	})

	it('leaves the surrounding template text alone', () => {
		const result = translate('office', 'Shared by {owner}', { owner: 'Bob' })

		expect(result).toBe('Shared by Bob')
	})
})
