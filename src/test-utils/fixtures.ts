import { File } from '@nextcloud/files'
import type { Node } from '@nextcloud/files'
import type { TemplateCreator, TemplateFile } from '../services/templates.ts'

let sourceCounter = 0

interface MakeNodeOptions {
	id?: number
	owner?: string | null
	mountType?: string
	mime?: string
	favorite?: boolean
	mtime?: Date
	basename?: string
	/** Outgoing share-type numbers; stored in the nested shape DAV returns. */
	shareTypes?: number[]
}

// Owner/mount-type are the two axes that decide whether a file counts as
// "mine", "shared" or external in the Mine filter — pass them explicitly
// per case rather than relying on the defaults. id defaults to a fresh
// number per call so file.fileid is always real (Node.fileid is only
// derived when the backing id is a number) rather than silently undefined.
export function makeNode({
	id = ++sourceCounter,
	owner = 'alice',
	mountType,
	mime = 'application/vnd.oasis.opendocument.text',
	favorite = false,
	mtime = new Date('2024-01-01T00:00:00Z'),
	basename = `file-${id}.odt`,
	shareTypes,
}: MakeNodeOptions = {}): Node {
	const ownerSegment = owner ?? 'nobody'
	return new File({
		id,
		source: `https://cloud.example.com/remote.php/dav/files/${ownerSegment}/${basename}`,
		root: `/files/${ownerSegment}`,
		owner,
		mime,
		mtime,
		attributes: {
			...(mountType !== undefined ? { 'nc:mount-type': mountType } : {}),
			...(favorite ? { favorite: 1 } : {}),
			// The DAV `oc:share-types` property nests the numbers under a
			// `share-type` key — reproduce that so consumers exercise the real shape.
			...(shareTypes ? { 'share-types': { 'share-type': shareTypes } } : {}),
		},
	})
}

interface MakeCreatorOptions {
	app?: string
	label?: string
	extension?: string
	mimetypes?: string[]
	templates?: TemplateFile[]
}

export function makeCreator({
	app = 'richdocuments',
	label = 'Document',
	extension = '.odt',
	mimetypes = ['application/vnd.oasis.opendocument.text'],
	templates = [],
}: MakeCreatorOptions = {}): TemplateCreator {
	return { app, label, extension, mimetypes, templates }
}
