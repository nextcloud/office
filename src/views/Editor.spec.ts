import { mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import Editor from './Editor.vue'

function setEditorData(data: Record<string, unknown>) {
	const el = document.createElement('script')
	el.id = 'office-editor-data'
	el.type = 'application/json'
	el.textContent = JSON.stringify(data)
	document.body.appendChild(el)
}

describe('Editor', () => {
	beforeEach(() => {
		document.body.innerHTML = ''
	})

	it('submits a hidden POST form instead of a GET iframe src, and keeps wopisrc in the URL while moving access_token to a form field', () => {
		// EO's WOPI editor route only registers a POST handler, so an
		// `<iframe src>` (always GET) 404s. EO also reads wopisrc from
		// req.query but access_token from req.body only, so the split here
		// isn't arbitrary - getting it backwards breaks EO's own routing.
		setEditorData({
			editorUrl: 'http://eo/hosting/wopi/word/edit?wopisrc=http%3A%2F%2Fnc%2Fwopi%2Ffiles%2F76&access_token=secret-token',
			postMessageOrigin: 'http://nc',
			fileName: 'test.docx',
		})

		const submit = vi.spyOn(HTMLFormElement.prototype, 'submit').mockImplementation(() => {})
		const wrapper = mount(Editor)

		const form = wrapper.get('form')
		const actionUrl = new URL(form.attributes('action')!)

		expect(actionUrl.searchParams.get('wopisrc')).toBe('http://nc/wopi/files/76')
		expect(actionUrl.searchParams.has('access_token')).toBe(false)

		const tokenInput = wrapper.get('input[name="access_token"]')
		expect(tokenInput.attributes('value')).toBe('secret-token')

		expect(form.attributes('method')).toBe('post')
		expect(form.attributes('target')).toBe(wrapper.get('iframe').attributes('name'))
		expect(submit).toHaveBeenCalledOnce()

		wrapper.unmount()
	})

	afterEach(() => {
		vi.restoreAllMocks()
	})
})
