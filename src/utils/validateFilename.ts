import { translate as t } from '@nextcloud/l10n'

export function validateFilename(name: string): string | null {
	const trimmed = name.trim()
	if (!trimmed) return t('office', 'Filename cannot be empty')
	if (/[/\\]/.test(trimmed) || trimmed.includes('\x00')) return t('office', 'Filename contains invalid characters')
	return null
}
