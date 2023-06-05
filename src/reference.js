/**
 * @copyright Copyright (c) 2022 Julien Veyssier <julien-nc@posteo.net>
 *
 * @author Julien Veyssier <julien-nc@posteo.net>
 *
 * @license AGPL-3.0-or-later
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program. If not, see <http://www.gnu.org/licenses/>.
 */

import { registerWidget, registerCustomPickerElement, NcCustomPickerRenderResult } from '@nextcloud/vue/dist/Components/NcRichText.js'
import { loadState } from '@nextcloud/initial-state'
import { linkTo } from '@nextcloud/router'
import { getRequestToken } from '@nextcloud/auth'

__webpack_nonce__ = btoa(getRequestToken()) // eslint-disable-line
__webpack_public_path__ = linkTo('integration_openai', 'js/') // eslint-disable-line

const features = loadState('integration_openai', 'features')

if (features.image_picker_enabled) {
	registerWidget('integration_openai_image', async (el, { richObjectType, richObject, accessible }) => {
		const { default: Vue } = await import(/* webpackChunkName: "vue-lazy" */'vue')
		Vue.mixin({ methods: { t, n } })
		const { default: ImageReferenceWidget } = await import(/* webpackChunkName: "reference-image-lazy" */'./views/ImageReferenceWidget.vue')
		const Widget = Vue.extend(ImageReferenceWidget)
		new Widget({
			propsData: {
				richObjectType,
				richObject,
				accessible,
			},
		}).$mount(el)
	})

	registerCustomPickerElement('openai-image', async (el, { providerId, accessible }) => {
		const { default: Vue } = await import(/* webpackChunkName: "vue-lazy" */'vue')
		Vue.mixin({ methods: { t, n } })
		const { default: ImageCustomPickerElement } = await import(/* webpackChunkName: "image-picker-lazy" */'./views/ImageCustomPickerElement.vue')
		const Element = Vue.extend(ImageCustomPickerElement)
		const vueElement = new Element({
			propsData: {
				providerId,
				accessible,
			},
		}).$mount(el)
		return new NcCustomPickerRenderResult(vueElement.$el, vueElement)
	}, (el, renderResult) => {
		console.debug('OpenAI image custom destroy callback. el', el, 'renderResult:', renderResult)
		renderResult.object.$destroy()
	}, 'normal')
}

if (features.text_completion_picker_enabled) {
	registerCustomPickerElement('openai-chatgpt', async (el, { providerId, accessible }) => {
		const { default: Vue } = await import(/* webpackChunkName: "vue-lazy" */'vue')
		Vue.mixin({ methods: { t, n } })
		const { default: ChatGptCustomPickerElement } = await import(/* webpackChunkName: "gpt-picker-lazy" */'./views/ChatGptCustomPickerElement.vue')
		const Element = Vue.extend(ChatGptCustomPickerElement)
		const vueElement = new Element({
			propsData: {
				providerId,
				accessible,
			},
		}).$mount(el)
		return new NcCustomPickerRenderResult(vueElement.$el, vueElement)
	}, (el, renderResult) => {
		console.debug('OpenAI ChatGPT custom destroy callback. el', el, 'renderResult:', renderResult)
		renderResult.object.$destroy()
	}, 'normal')
}

if (features.whisper_picker_enabled) {
	registerCustomPickerElement('openai-whisper', async (el, { providerId, accessible }) => {
		const { default: Vue } = await import(/* webpackChunkName: "vue-lazy" */'vue')
		Vue.mixin({ methods: { t, n } })
		const { default: WhisperCustomPickerElement } = await import(/* webpackChunkName: "whisper-picker-lazy" */'./views/WhisperCustomPickerElement.vue')
		const Element = Vue.extend(WhisperCustomPickerElement)
		const vueElement = new Element({
			propsData: {
				providerId,
				accessible,
			},
		}).$mount(el)
		return new NcCustomPickerRenderResult(vueElement.$el, vueElement)
	}, (el, renderResult) => {
		console.debug('OpenAI Whisper custom destroy callback. el', el, 'renderResult:', renderResult)
		renderResult.object.$destroy()
	}, 'normal')
}
