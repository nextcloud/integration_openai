<!--
  - SPDX-FileCopyrightText: 2023 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div id="watsonx_prefs" class="section">
		<h2>
			{{ t('integration_watsonx', 'IBM watsonx.ai integration') }}
		</h2>
		<div id="watsonx-content">
			<p v-if="state.is_custom_service" class="settings-hint">
				<InformationOutlineIcon :size="20" class="icon" />
				{{ t('integration_watsonx', 'Your administrator defined a custom service address') }}
			</p>
			<div>
				<NcNoteCard type="info">
					{{ t('integration_watsonx', 'Leave the API key empty to use the one defined by administrators') }}
				</NcNoteCard>
				<div class="line">
					<NcTextField
						id="watsonx-api-key"
						class="input"
						:value.sync="state.api_key"
						:readonly="readonly"
						type="password"
						:label="t('integration_watsonx', 'API key')"
						:show-trailing-button="!!state.api_key"
						@update:value="onSensitiveInput"
						@trailing-button-click="state.api_key = '' ; onSensitiveInput()"
						@focus="readonly = false">
						<KeyIcon />
					</NcTextField>
				</div>
				<NcNoteCard type="info">
					{{ t('integration_watsonx', 'A watsonx.ai project ID or space ID is required if an API key is specified') }}
				</NcNoteCard>
				<div class="line">
					<NcTextField
						id="watsonx-project-id"
						class="input"
						:value.sync="state.project_id"
						:readonly="readonly"
						type="password"
						:label="t('integration_watsonx', 'Project ID')"
						:show-trailing-button="!!state.project_id"
						@update:value="onSensitiveInput"
						@trailing-button-click="state.project_id = '' ; onSensitiveInput()"
						@focus="readonly = false">
						<KeyIcon />
					</NcTextField>
				</div>
				<div class="line">
					<NcTextField
						id="watsonx-space-id"
						class="input"
						:value.sync="state.space_id"
						:readonly="readonly"
						type="password"
						:label="t('integration_watsonx', 'Space ID')"
						:show-trailing-button="!!state.space_id"
						@update:value="onSensitiveInput"
						@trailing-button-click="state.space_id = '' ; onSensitiveInput()"
						@focus="readonly = false">
						<KeyIcon />
					</NcTextField>
				</div>
				<div v-if="!state.is_custom_service">
					<NcNoteCard type="info">
						{{ t('integration_watsonx', 'You can create an API key in your IBM Cloud IAM account settings') }}:
						&nbsp;
						<a :href="apiKeyUrl" target="_blank" class="external">
							{{ apiKeyUrl }}
						</a>
					</NcNoteCard>
				</div>
			</div>
			<div v-if="quotaInfo !== null">
				<!-- Show quota info -->
				<h4>
					{{ t('integration_watsonx', 'Usage quota info') }}
				</h4>
				<!-- Loop through all quota types-->
				<table class="quota-table">
					<thead>
						<tr>
							<th width="120px">
								{{ t('integration_watsonx', 'Quota type') }}
							</th>
							<th>{{ t('integration_watsonx', 'Usage') }}</th>
						</tr>
					</thead>
					<tbody>
						<tr v-for="quota in quotaInfo.quota_usage" :key="quota.type">
							<td>{{ t('integration_watsonx', capitalizedWord(quota.type)) }} </td>
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
				<NcNoteCard type="info">
					{{ t('integration_watsonx', 'Specifying your own API key will allow unlimited usage') }}
				</NcNoteCard>
			</div>
		</div>
	</div>
</template>

<script>
import InformationOutlineIcon from 'vue-material-design-icons/InformationOutline.vue'
import KeyIcon from 'vue-material-design-icons/Key.vue'

import NcTextField from '@nextcloud/vue/dist/Components/NcTextField.js'
import NcNoteCard from '@nextcloud/vue/dist/Components/NcNoteCard.js'

import { loadState } from '@nextcloud/initial-state'
import { generateUrl } from '@nextcloud/router'
import axios from '@nextcloud/axios'
import { showSuccess, showError } from '@nextcloud/dialogs'
import { confirmPassword } from '@nextcloud/password-confirmation'
import debounce from 'debounce'

export default {
	name: 'PersonalSettings',

	components: {
		KeyIcon,
		InformationOutlineIcon,
		NcNoteCard,
		NcTextField,
	},

	props: [],

	data() {
		return {
			state: loadState('integration_watsonx', 'config'),
			// to prevent some browsers to fill fields with remembered passwords
			readonly: true,
			apiKeyUrl: 'https://cloud.ibm.com/docs/account?topic=account-iamtoken_from_apikey',
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
		onInput: debounce(function() {
			this.saveOptions({
			})
		}, 2000),
		onSensitiveInput: debounce(function() {
			if (this.state.api_key !== 'dummyApiKey') {
				const values = {
					api_key: this.state.api_key,
				}
				if (this.state.project_id !== 'dummyProject') {
					values.project_id = (this.state.project_id ?? '').trim()
				}
				if (this.state.space_id !== 'dummySpaceId') {
					values.space_id = (this.state.space_id ?? '').trim()
				}
				this.saveOptions(values, true)
			}
		}, 2000),
		async loadQuotaInfo() {
			const url = generateUrl('/apps/integration_watsonx/quota-info')
			try {
				const response = await axios.get(url)
				this.quotaInfo = response.data
				if (this.quotaInfo === null) {
					return
				}
				// Loop through all quota types and check if any are limited by admin
				// If so, show a hint that the user can provide their own api key to remove the limit
				for (const quota of this.quotaInfo.quota_usage) {
					if (quota.limit > 0) {
						this.showQuotaRemovalInfo = true
						break
					}
				}
			} catch (error) {
				showError(t('integration_watsonx', 'Failed to load quota info'))
				console.error(error)
			}
		},
		capitalizedWord(word) {
			return word.charAt(0).toUpperCase() + word.slice(1)
		},
		async saveOptions(values, sensitive = false) {
			if (sensitive) {
				await confirmPassword()
			}
			try {
				const req = {
					values,
				}
				const url = sensitive ? generateUrl('/apps/integration_watsonx/config/sensitive') : generateUrl('/apps/integration_watsonx/config')
				await axios.put(url, req)
				showSuccess(t('integration_watsonx', 'Watsonx.ai options saved'))
			} catch (error) {
				showError(t('integration_watsonx', 'Failed to save watsonx.ai options'))
				console.error(error)
			}
		},
	},
}
</script>

<style scoped lang="scss">
#watsonx_prefs {
	#watsonx-content {
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

	.line {
		> label {
			width: 300px;
			display: flex;
			align-items: center;
		}
		> input, .input {
			width: 300px;
		}
		.spacer {
			display: inline-block;
			width: 36px;
		}
	}

	button {
		margin-right: 24px;
	}
}
</style>
