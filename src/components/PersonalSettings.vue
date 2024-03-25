<template>
	<div id="openai_prefs" class="section">
		<h2>
			<OpenAiIcon class="icon" />
			{{ t('integration_openai', 'OpenAI and LocalAI integration') }}
		</h2>
		<div id="openai-content">
			<p v-if="state.is_custom_service" class="settings-hint">
				<InformationOutlineIcon :size="20" class="icon" />
				{{ t('integration_openai', 'Your administrator defined a custom service address') }}
			</p>
			<div v-if="!state.is_custom_service || !state.use_basic_auth">
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
				<div v-if="!state.is_custom_service">
					<p class="settings-hint">
						<InformationOutlineIcon :size="20" class="icon" />
						{{ t('integration_openai', 'You can create a free API key in your OpenAI account settings:') }}
						&nbsp;
						<a :href="apiKeyUrl" target="_blank" class="external">
							{{ apiKeyUrl }}
						</a>
					</p>
				</div>
			</div>
			<div v-else>
				<p class="settings-hint">
					<InformationOutlineIcon :size="20" class="icon" />
					{{ t('integration_openai', 'Leave the username and password empty to use the ones defined by your administrator') }}
				</p>
				<div class="line">
					<label for="basic-user">
						<KeyIcon :size="20" class="icon" />
						{{ t('integration_openai', 'Username') }}
					</label>
					<input id="openai-basic-user"
						v-model="state.basic_user"
						type="text"
						:readonly="readonly"
						:placeholder="t('integration_openai', 'your Basic Auth user')"
						@input="onInput"
						@focus="readonly = false">
				</div>
				<div class="line">
					<label for="basic-password">
						<KeyIcon :size="20" class="icon" />
						{{ t('integration_openai', 'Password') }}
					</label>
					<input id="openai-basic-password"
						v-model="state.basic_password"
						type="password"
						:readonly="readonly"
						:placeholder="t('integration_openai', 'your Basic Auth password')"
						@input="onInput"
						@focus="readonly = false">
				</div>
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
			<div v-if="!state.is_custom_service">
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
					basic_user: this.state.basic_user,
					basic_password: this.state.basic_password,
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
						+ ': ' + error.response?.request?.responseText,
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
						+ ': ' + error.response?.request?.responseText,
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
