import type { TemplateCreator } from './templates.ts'

import { describe, expect, it, vi } from 'vitest'

const getMock = vi.fn()
const postMock = vi.fn()

vi.mock('@nextcloud/axios', () => ({
	default: { get: getMock, post: postMock },
}))

const { getTemplates, createFromTemplate } = await import('./templates.ts')

describe('getTemplates', () => {
	it('requests the OCS templates endpoint and unwraps the envelope', async () => {
		const creators: TemplateCreator[] = [
			{ app: 'richdocuments', label: 'Document', extension: '.odt', mimetypes: [], templates: [] },
		]
		getMock.mockResolvedValue({ data: { ocs: { data: creators } } })

		const result = await getTemplates()

		expect(getMock).toHaveBeenCalledWith('apps/files/api/v1/templates')
		expect(result).toBe(creators)
	})
})

describe('createFromTemplate', () => {
	it('posts the expected request body and unwraps the envelope', async () => {
		const created = { fileid: 42, basename: 'doc.odt', filename: '/doc.odt' }
		postMock.mockResolvedValue({ data: { ocs: { data: created } } })

		const result = await createFromTemplate('/doc.odt', 'tpl-1', 'user_system')

		expect(postMock).toHaveBeenCalledWith('apps/files/api/v1/templates/create', {
			filePath: '/doc.odt',
			templatePath: 'tpl-1',
			templateType: 'user_system',
		})
		expect(result).toBe(created)
	})
})
