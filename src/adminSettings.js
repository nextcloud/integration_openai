/**
 * Nextcloud - OpenAI
 *
 *
 * This file is licensed under the Affero General Public License version 3 or
 * later. See the COPYING file.
 *
 * @author Julien Veyssier <eneiluj@posteo.net>
 * @copyright Julien Veyssier 2022
 */

import Vue from 'vue'
import './bootstrap.js'
import AdminSettings from './components/AdminSettings.vue'

const View = Vue.extend(AdminSettings)
new View().$mount('#openai_prefs')
