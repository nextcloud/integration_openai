<template>
	<div id="openai_prefs" class="section">
		<h2>
			<OpenAiIcon class="icon" />
			{{ t('integration_openai', 'OpenAI integration') }}
		</h2>
		<div id="openai-content">
			<div class="line">
				<label for="openai-api-key">
					<KeyIcon :size="20" class="icon" />
					{{ t('integration_openai', 'OpenAI API key') }}
				</label>
				<input id="openai-api-key"
					v-model="state.api_key"
					type="password"
					:readonly="readonly"
					:placeholder="t('integration_openai', 'your API key')"
					@input="onInput"
					@focus="readonly = false">
			</div>
			<p class="settings-hint">
				<InformationOutlineIcon :size="20" class="icon" />
				<a href="https://openai.com/api" target="_blank">
					{{ t('integration_openai', 'You can create a free API key in your user settings in https://openai.com/api') }}
				</a>
			</p>
		</div>
	</div>
</template>

<script>
import InformationOutlineIcon from 'vue-material-design-icons/InformationOutline.vue'
import KeyIcon from 'vue-material-design-icons/Key.vue'

import OpenAiIcon from './icons/OpenAiIcon.vue'

import { loadState } from '@nextcloud/initial-state'
import { generateUrl } from '@nextcloud/router'
import axios from '@nextcloud/axios'
import { delay } from '../utils.js'
import { showSuccess, showError } from '@nextcloud/dialogs'

export default {
	name: 'AdminSettings',

	components: {
		OpenAiIcon,
		KeyIcon,
		InformationOutlineIcon,
	},

	props: [],

	data() {
		return {
			state: loadState('integration_openai', 'admin-config'),
			// to prevent some browsers to fill fields with remembered passwords
			readonly: true,
		}
	},

	watch: {
	},

	mounted() {
	},

	methods: {
		onInput() {
			delay(() => {
				this.saveOptions({
					api_key: this.state.api_key,
				})
			}, 2000)()
		},
		saveOptions(values) {
			const req = {
				values,
			}
			const url = generateUrl('/apps/integration_openai/admin-config')
			axios.put(url, req)
				.then((response) => {
					showSuccess(t('integration_openai', 'OpenAI admin options saved'))
				})
				.catch((error) => {
					showError(
						t('integration_openai', 'Failed to save OpenAI admin options')
						+ ': ' + error.response?.request?.responseText
					)
				})
				.then(() => {
				})
		},
	},
}
</script>

<style scoped lang="scss">
#openai_prefs {
	#openai-content {
		margin-left: 40px;
	}
	h2,
	.line,
	.settings-hint {
		display: flex;
		align-items: center;
		margin-top: 12px;
		.icon {
			margin-right: 4px;
		}
	}

	h2 .icon {
		margin-right: 8px;
	}

	.line {
		> label {
			width: 300px;
			display: flex;
			align-items: center;
		}
		> input {
			width: 300px;
		}
	}
}
</style>
