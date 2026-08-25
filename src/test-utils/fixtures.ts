import { File } from '@nextcloud/files'
import type { Node } from '@nextcloud/files'

import type { CreatorCategory } from '../utils/fileCategories.ts'
import type { TemplateCreator, TemplateFile } from '../services/templates.ts'

type LoadState = typeof import('@nextcloud/initial-state').loadState

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

// What CreatorCategoryService provides as the 'creator-categories' initial state
// for the four ODF creators richdocuments registers — the shape specs have to
// hand loadState() for categoryName()/categoryId()/categoryMimes() to resolve.
export const CREATOR_CATEGORIES: CreatorCategory[] = [
	{
		app: 'richdocuments',
		extension: '.odt',
		id: 'documents',
		label: 'Documents',
		mimetypes: [
			'application/vnd.oasis.opendocument.text',
			'application/vnd.oasis.opendocument.text-template',
			'application/msword',
			'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
		],
	},
	{
		app: 'richdocuments',
		extension: '.ods',
		id: 'spreadsheets',
		label: 'Spreadsheets',
		mimetypes: [
			'application/vnd.oasis.opendocument.spreadsheet',
			'application/vnd.oasis.opendocument.spreadsheet-template',
			'application/vnd.ms-excel',
			'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
		],
	},
	{
		app: 'richdocuments',
		extension: '.odp',
		id: 'presentations',
		label: 'Presentations',
		mimetypes: [
			'application/vnd.oasis.opendocument.presentation',
			'application/vnd.oasis.opendocument.presentation-template',
			'application/vnd.ms-powerpoint',
			'application/vnd.openxmlformats-officedocument.presentationml.presentation',
		],
	},
	{
		app: 'richdocuments',
		extension: '.odg',
		id: 'diagrams',
		label: 'Diagrams',
		mimetypes: [
			'application/vnd.oasis.opendocument.graphics',
			'application/vnd.oasis.opendocument.graphics-template',
		],
	},
]

interface FakeLoadStateOptions {
	categories?: CreatorCategory[]
	editorUrl?: string | null
}

// loadState() is mocked globally (see vitest.setup.ts); this answers both of the
// keys the app reads. Hand it to mockImplementation().
export function fakeLoadState({
	categories = CREATOR_CATEGORIES,
	editorUrl = null,
}: FakeLoadStateOptions = {}): LoadState {
	return ((app: string, key: string) => (key === 'editor-url' ? editorUrl : categories)) as LoadState
}
