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
					{{ t('integration_openai', 'API key') }}
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
			<div v-if="!state.isCustomService">
				<p class="settings-hint">
					<InformationOutlineIcon :size="20" class="icon" />
					{{ t('integration_openai', 'You can create a free API key in your OpenAI account settings:') }}
					&nbsp;
					<a :href="apiKeyUrl" target="_blank" class="external">
						{{ apiKeyUrl }}
					</a>
				</p>
			</div>
			<div class="line">
				<label for="clear-prompt-history">
					<DeleteIcon :size="20" class="icon" />
					{{ t('integration_openai', 'Clear prompt history') }}
				</label>
				<button id="clear-text-prompt-history"
					@click="clearPromptHistory(true, false)">
					{{ t('integration_openai', 'Clear text prompts') }}
				</button>
				<button id="clear-image-prompt-history"
					@click="clearPromptHistory(false, true)">
					{{ t('integration_openai', 'Clear image prompts') }}
				</button>
			</div>
			<div v-if="quotaInfo !== null" class="line">
				<!-- Show quota info -->
				<label>
					<InformationOutlineIcon :size="20" class="icon" />
					{{ t('integration_openai', 'Usage quota info') }}
				</label>
				<!-- Loop through all quota types-->
				<table class="quota-table">
					<thead>
						<tr>
							<th width="120px">
								{{ t('integration_openai', 'Quota type') }}
							</th>
							<th>{{ t('integration_openai', 'Usage') }}</th>
						</tr>
					</thead>
					<tbody>
						<tr v-for="quota in quotaInfo.quota_usage" :key="quota.type">
							<td>{{ t('integration_openai', capitalizedWord(quota.type)) }} </td>
							<td v-if="quota.limit > 0">
								{{ Math.round(quota.used / quota.limit * 100) + ' %' }}
							</td>
							<td v-else>
								{{ quota.used + ' ' + quota.unit }}
							</td>
						</tr>
					</tbody>
				</table>
			</div>
			<div v-if="!state.isCustomService">
				<p class="settings-hint">
					<InformationOutlineIcon :size="20" class="icon" />
					{{ t('integration_openai', 'Specifying your own API key will allow unlimited usage') }}
				</p>
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
			quotaInfo: null,
			showQuotaRemovalInfo: false,
		}
	},

	computed: {
	},

	watch: {
	},

	mounted() {
		this.loadQuotaInfo()
	},

	methods: {
		onInput() {
			delay(() => {
				this.saveOptions({
					api_key: this.state.api_key,
				})
			}, 2000)()
		},
		loadQuotaInfo() {
			const url = generateUrl('/apps/integration_openai/quota-info')
			axios.get(url)
				.then((response) => {
					this.quotaInfo = response.data
				})
				.catch((error) => {
					showError(
						t('integration_openai', 'Failed to load quota info')
						+ ': ' + error.response?.request?.responseText
					)
				})
			if (this.quotaInfo === null) {
				return
			}
			// Loop through all quota types and check if any are limited by admin
			// If so, show a hint that the user can provide their own api key to remove the limit
			for (const quota of this.quotaInfo) {
				if (quota.limit > 0) {
					this.showQuotaRemovalInfo = true
					break
				}
			}
		},
		capitalizedWord(word) {
			return word.charAt(0).toUpperCase() + word.slice(1)
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
		.spacer {
			display: inline-block;
			width: 36px;
		}

		.quota-table {
			padding: 4px 8px 4px 8px;
			border: 2px solid var(--color-border);
			border-radius: var(--border-radius);
			tbody {
				opacity: 0.5;
			}
			th, td {
				width: 200px;
				text-align: left;
			}
		}
	}

	button {
		margin-right: 24px;
	}
}
</style>
