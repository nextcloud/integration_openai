<template>
	<div id="openai_prefs" class="section">
		<h2>
			<OpenAiIcon class="icon" />
			{{ t('integration_openai', 'OpenAI and LocalAI integration') }}
		</h2>
		<div id="openai-content">
			<p v-if="state.isCustomService" class="settings-hint">
				<InformationOutlineIcon :size="20" class="icon" />
				{{ t('integration_openai', 'Your administrator defined a custom service address') }}
			</p>
			<p class="settings-hint">
				<InformationOutlineIcon :size="20" class="icon" />
				{{ t('integration_openai', 'Leave the API key empty to use the one defined by administrators') }}
			</p>
			<div class="line">
				<label for="openai-api-key">
					<KeyIcon :size="20" class="icon" />
					{{ t('integration_openai', 'OpenAI API key') }}
				</label>
				<input id="openai-api-key"
					v-model="state.api_key"
					autocomplete="off"
					type="password"
					:readonly="readonly"
					:placeholder="t('integration_openai', 'your API key')"
					@input="onInput"
					@focus="readonly = false">
			</div>
			<p class="settings-hint">
				<InformationOutlineIcon :size="20" class="icon" />
				{{ t('integration_openai', 'You can create a free API key in your OpenAI account settings:') }}
				&nbsp;
				<a :href="apiKeyUrl" target="_blank" class="external">
					{{ apiKeyUrl }}
				</a>
			</p>
			<div class="line">
				<label for="clear-prompt-history">
					<DeleteIcon :size="20" class="icon" />
					{{ t('integration_openai', 'Clear prompt history') }}
				</label>
				<button id="clear-text-prompt-history"
					@click="clearPromptHistory(false,true)">
					{{ t('integration_openai', 'Clear text prompts') }}
				</button>
				<button id="clear-image-prompt-history"
					@click="clearPromptHistory(true,false)">
					{{ t('integration_openai', 'Clear image prompts') }}
				</button>
			</div>
		</div>
	</div>
</template>

<script>
import InformationOutlineIcon from 'vue-material-design-icons/InformationOutline.vue'
import KeyIcon from 'vue-material-design-icons/Key.vue'
import DeleteIcon from 'vue-material-design-icons/Delete.vue'

import OpenAiIcon from './icons/OpenAiIcon.vue'

import { loadState } from '@nextcloud/initial-state'
import { generateUrl } from '@nextcloud/router'
import axios from '@nextcloud/axios'
import { delay } from '../utils.js'
import { showSuccess, showError } from '@nextcloud/dialogs'

export default {
	name: 'PersonalSettings',

	components: {
		OpenAiIcon,
		KeyIcon,
		InformationOutlineIcon,
		DeleteIcon,
	},

	props: [],

	data() {
		return {
			state: loadState('integration_openai', 'config'),
			// to prevent some browsers to fill fields with remembered passwords
			readonly: true,
			apiKeyUrl: 'https://platform.openai.com/account/api-keys',
		}
	},

	computed: {
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
			const url = generateUrl('/apps/integration_openai/config')
			return axios.put(url, req)
				.then((response) => {
					showSuccess(t('integration_openai', 'OpenAI options saved'))
				})
				.catch((error) => {
					showError(
						t('integration_openai', 'Failed to save OpenAI options')
						+ ': ' + error.response?.request?.responseText
					)
				})
		},
		clearPromptHistory(clearImages, clearText) {
			const params = {
				clearTextPrompts: clearImages,
				clearImagePrompts: clearText,
			}
			const url = generateUrl('/apps/integration_openai/clear-prompt-history')
			return axios.post(url, params)
				.then((response) => {
					showSuccess(t('integration_openai', 'Prompt history cleared'))
				})
				.catch((error) => {
					showError(
						t('integration_openai', 'Failed to clear prompt history')
						+ ': ' + error.response?.request?.responseText
					)
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

	button {
		margin-right: 24px;
	}
}
</style>
