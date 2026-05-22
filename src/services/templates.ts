/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import axios from '@nextcloud/axios'
import { generateOcsUrl } from '@nextcloud/router'

export interface TemplateFile {
	fileid: number
	basename: string
	filename: string
	templateId: string
	templateType: string
	hasPreview: boolean
	previewUrl?: string
}

export interface TemplateCreator {
	app: string
	label: string
	extension: string
	iconClass?: string
	iconSvgInline?: string
	mimetypes: string[]
	templates: TemplateFile[]
}

export interface CreatedFile {
	fileid: number
	basename: string
	filename: string
}

export async function getTemplates(): Promise<TemplateCreator[]> {
	const response = await axios.get(generateOcsUrl('apps/files/api/v1/templates'))
	return response.data.ocs.data
}

export async function createFromTemplate(
	filePath: string,
	templatePath: string,
	templateType: string,
): Promise<CreatedFile> {
	const response = await axios.post(generateOcsUrl('apps/files/api/v1/templates/create'), {
		filePath,
		templatePath,
		templateType,
	})
	return response.data.ocs.data
}
