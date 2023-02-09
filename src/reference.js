/**
 * @copyright Copyright (c) 2022 Julien Veyssier <eneiluj@posteo.net>
 *
 * @author Julien Veyssier <eneiluj@posteo.net>
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

import {
	registerCustomPickerElement,
	CustomPickerRenderResult,
} from '@nextcloud/vue-richtext'

__webpack_nonce__ = btoa(OC.requestToken) // eslint-disable-line
__webpack_public_path__ = OC.linkTo('integration_openai', 'js/') // eslint-disable-line

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
	return new CustomPickerRenderResult(vueElement.$el, vueElement)
}, (el, renderResult) => {
	console.debug('OpenAI image custom destroy callback. el', el, 'renderResult:', renderResult)
	renderResult.object.$destroy()
})

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
	return new CustomPickerRenderResult(vueElement.$el, vueElement)
}, (el, renderResult) => {
	console.debug('OpenAI ChatGPT custom destroy callback. el', el, 'renderResult:', renderResult)
	renderResult.object.$destroy()
})
