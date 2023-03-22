/**
 * Nextcloud - OpenAI
 *
 *
 * This file is licensed under the Affero General Public License version 3 or
 * later. See the COPYING file.
 *
 * @author Julien Veyssier <julien-nc@posteo.net>
 * @copyright Julien Veyssier 2023
 */

import Vue from 'vue'
import ImageGenerationPage from './views/ImageGenerationPage.vue'
Vue.mixin({ methods: { t, n } })

const View = Vue.extend(ImageGenerationPage)
new View().$mount('#content')
