<!--
  - SPDX-FileCopyrightText: 2023 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
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
				<NcNoteCard type="info">
					{{ t('integration_openai', 'Leave the API key empty to use the one defined by administrators') }}
				</NcNoteCard>
				<div class="line">
					<NcTextField
						id="openai-api-key"
						v-model="state.api_key"
						class="input"
						:readonly="readonly"
						type="password"
						:label="t('integration_openai', 'API key')"
						:show-trailing-button="!!state.api_key"
						@update:model-value="onSensitiveInput"
						@trailing-button-click="state.api_key = '' ; onSensitiveInput()"
						@focus="readonly = false">
						<template #icon>
							<KeyOutlineIcon :size="20" />
						</template>
					</NcTextField>
				</div>
				<div v-if="!state.is_custom_service">
					<NcNoteCard type="info">
						{{ t('integration_openai', 'You can create a free API key in your OpenAI account settings') }}:
						&nbsp;
						<a :href="apiKeyUrl" target="_blank" class="external">
							{{ apiKeyUrl }}
						</a>
					</NcNoteCard>
				</div>
			</div>
			<div v-else>
				<NcNoteCard type="info">
					{{ t('integration_openai', 'Leave the username and password empty to use the ones defined by your administrator') }}
				</NcNoteCard>
				<div class="line">
					<NcTextField
						id="openai-basic-user"
						v-model="state.basic_user"
						class="input"
						:readonly="readonly"
						:label="t('integration_openai', 'Basic Auth user')"
						:show-trailing-button="!!state.basic_user"
						@update:model-value="onSensitiveInput()"
						@trailing-button-click="state.basic_user = '' ; onSensitiveInput()"
						@focus="readonly = false">
						<template #icon>
							<AccountOutlineIcon :size="20" />
						</template>
					</NcTextField>
				</div>
				<div class="line">
					<NcTextField
						id="openai-basic-password"
						v-model="state.basic_password"
						class="input"
						type="password"
						:readonly="readonly"
						:label="t('integration_openai', 'Basic Auth password')"
						:show-trailing-button="!!state.basic_password"
						@update:model-value="onSensitiveInput()"
						@trailing-button-click="state.basic_password = '' ; onSensitiveInput()"
						@focus="readonly = false">
						<template #icon>
							<KeyOutlineIcon :size="20" />
						</template>
					</NcTextField>
				</div>
			</div>
			<div v-if="quotaInfo !== null">
				<!-- Show quota info -->
				<h4>
					{{ t('integration_openai', 'Usage quota info') }}
				</h4>
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
				<NcNoteCard type="success">
					{{ quotaRangeText }}
				</NcNoteCard>
			</div>
			<div v-if="!state.is_custom_service">
				<NcNoteCard type="info">
					{{ t('integration_openai', 'Specifying your own API key will allow unlimited usage') }}
				</NcNoteCard>
			</div>
		</div>
	</div>
</template>

<script>
import AccountOutlineIcon from 'vue-material-design-icons/AccountOutline.vue'
import InformationOutlineIcon from 'vue-material-design-icons/InformationOutline.vue'
import KeyOutlineIcon from 'vue-material-design-icons/KeyOutline.vue'

import OpenAiIcon from './icons/OpenAiIcon.vue'

import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcTextField from '@nextcloud/vue/components/NcTextField'

import axios from '@nextcloud/axios'
import { showError, showSuccess } from '@nextcloud/dialogs'
import { loadState } from '@nextcloud/initial-state'
import { confirmPassword } from '@nextcloud/password-confirmation'
import { generateUrl } from '@nextcloud/router'
import debounce from 'debounce'
import { formatRelativeTime } from '@nextcloud/l10n'

export default {
	name: 'PersonalSettings',

	components: {
		AccountOutlineIcon,
		OpenAiIcon,
		KeyOutlineIcon,
		InformationOutlineIcon,
		NcNoteCard,
		NcTextField,
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
		quotaRangeText() {
			return this.quotaInfo?.period?.unit === 'month'
				? t('integration_openai', 'This quota period is from {startDate} to {endDate}.', {
					startDate: formatRelativeTime(this.quotaInfo.start * 1000),
					endDate: formatRelativeTime(this.quotaInfo.end * 1000),
				})
				: n('integration_openai', 'The quota is kept over a floating period of the last %n day.',
					'The quota is kept over a floating period of the last %n days.', this.quotaInfo.period.length)
		},
	},

	watch: {},

	mounted() {
		this.loadQuotaInfo()
	},

	methods: {
		onInput: debounce(function() {
			this.saveOptions({
			})
		}, 2000),
		onSensitiveInput: debounce(async function() {
			const values = {
				basic_user: (this.state.basic_user ?? '').trim(),
			}
			if (this.state.api_key !== 'dummyApiKey') {
				values.api_key = (this.state.api_key ?? '').trim()
			}
			if (this.state.basic_password !== 'dummyPassword') {
				values.basic_password = (this.state.basic_password ?? '').trim()
			}
			await this.saveOptions(values, true)
		}, 2000),
		async loadQuotaInfo() {
			const url = generateUrl('/apps/integration_openai/quota-info')
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
				showError(t('integration_openai', 'Failed to load quota info'))
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
				const url = sensitive ? generateUrl('/apps/integration_openai/config/sensitive') : generateUrl('/apps/integration_openai/config')
				await axios.put(url, req)
				showSuccess(t('integration_openai', 'OpenAI options saved'))
			} catch (error) {
				showError(t('integration_openai', 'Failed to save OpenAI options'))
				console.error(error)
			}
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
		justify-content: start;
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
