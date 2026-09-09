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
			<h4>
				{{ t('integration_openai', 'Speech to Text Default Language') }}
			</h4>
			<NcSelect
				v-model="state.stt_language"
				:options="languages"
				:input-label="t('integration_openai', 'Default language')"
				@update:model-value="onInput()" />

			<h4>
				{{ t('integration_openai', 'Your own credentials') }}
			</h4>
			<NcNoteCard v-if="services.length === 0" type="info">
				{{ t('integration_openai', 'Your administrator has not connected any service yet.') }}
			</NcNoteCard>
			<NcNoteCard v-else type="info">
				{{ t('integration_openai', 'For each service, you can use your own credentials instead of the ones your administrator configured. Leave the fields empty to use theirs. Using your own credentials for a service lifts the usage quotas of that service.') }}
			</NcNoteCard>

			<div v-for="service in services" :key="service.id" class="service">
				<h5 class="service__title">
					{{ service.display_name }}
					<span class="service__url">{{ service.url }}</span>
				</h5>
				<div v-if="!service.use_basic_auth">
					<div class="line">
						<NcTextField
							:id="'openai-api-key-' + service.id"
							:model-value="credentials[service.id].api_key"
							class="input"
							:readonly="readonly"
							type="password"
							:label="t('integration_openai', 'API key')"
							:show-trailing-button="!!credentials[service.id].api_key"
							@update:model-value="onSensitiveInput(service.id, { api_key: $event })"
							@trailing-button-click="onSensitiveInput(service.id, { api_key: '' })"
							@focus="readonly = false">
							<template #icon>
								<KeyOutlineIcon :size="20" />
							</template>
						</NcTextField>
					</div>
					<p v-if="service.is_using_openai" class="settings-hint">
						{{ t('integration_openai', 'You can create an API key in your OpenAI account settings') }}:
						&nbsp;
						<a :href="apiKeyUrl" target="_blank" class="external">{{ apiKeyUrl }}</a>
					</p>
				</div>
				<div v-else>
					<div class="line">
						<NcTextField
							:id="'openai-basic-user-' + service.id"
							:model-value="credentials[service.id].basic_user"
							class="input"
							:readonly="readonly"
							:label="t('integration_openai', 'Basic Auth user')"
							@update:model-value="onSensitiveInput(service.id, { basic_user: $event })"
							@focus="readonly = false">
							<template #icon>
								<AccountOutlineIcon :size="20" />
							</template>
						</NcTextField>
					</div>
					<div class="line">
						<NcTextField
							:id="'openai-basic-password-' + service.id"
							:model-value="credentials[service.id].basic_password"
							class="input"
							type="password"
							:readonly="readonly"
							:label="t('integration_openai', 'Basic Auth password')"
							@update:model-value="onSensitiveInput(service.id, { basic_password: $event })"
							@focus="readonly = false">
							<template #icon>
								<KeyOutlineIcon :size="20" />
							</template>
						</NcTextField>
					</div>
				</div>
			</div>

			<div v-if="quotaInfo !== null && quotaInfo.services.length > 0">
				<h4>
					{{ t('integration_openai', 'Usage quota info') }}
				</h4>
				<NcNoteCard v-if="poolUsed" type="info">
					{{ t('integration_openai', 'If you see a shared quota usage of 50% and a usage of 10% that means that you have used 10% of the total shared quota, and the sum of all other users affected by this quota is 40%.') }}
				</NcNoteCard>
				<div v-for="service in quotaInfo.services" :key="service.id" class="service">
					<h5 class="service__title">
						{{ service.name }}
					</h5>
					<NcNoteCard v-if="service.has_own_credentials" type="success">
						{{ t('integration_openai', 'You use your own credentials for this service, so no quota applies to you.') }}
					</NcNoteCard>
					<table v-else class="quota-table">
						<thead>
							<tr>
								<th width="120px">
									{{ t('integration_openai', 'Quota type') }}
								</th>
								<th>{{ t('integration_openai', 'Usage') }}</th>
								<th v-if="poolUsed">
									{{ t('integration_openai', 'Shared Usage') }}
								</th>
							</tr>
						</thead>
						<tbody>
							<tr v-for="quota in service.quota_usage" :key="quota.type">
								<td>{{ quota.type }}</td>
								<td v-if="quota.limit > 0">
									{{ Math.round(quota.used / quota.limit * 100) + ' %' }}
								</td>
								<td v-else>
									{{ quota.used + ' ' + quota.unit }}
								</td>
								<td v-if="quota.used_pool">
									{{ quota.limit > 0 ? Math.round(quota.used_pool / quota.limit * 100) + ' %' : quota.used_pool + ' ' + quota.unit }}
								</td>
								<td v-else-if="poolUsed">
									{{ t('integration_openai', 'Not Shared') }}
								</td>
							</tr>
						</tbody>
					</table>
				</div>
				<NcNoteCard type="success">
					{{ quotaRangeText }}
				</NcNoteCard>
			</div>
		</div>
	</div>
</template>

<script>
import AccountOutlineIcon from 'vue-material-design-icons/AccountOutline.vue'
import KeyOutlineIcon from 'vue-material-design-icons/KeyOutline.vue'

import OpenAiIcon from './icons/OpenAiIcon.vue'

import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import NcTextField from '@nextcloud/vue/components/NcTextField'

import axios from '@nextcloud/axios'
import { showError, showSuccess } from '@nextcloud/dialogs'
import { loadState } from '@nextcloud/initial-state'
import { formatRelativeTime } from '@nextcloud/l10n'
import { confirmPassword } from '@nextcloud/password-confirmation'
import { generateUrl } from '@nextcloud/router'
import debounce from 'debounce'

/** What the backend sends in place of a stored secret */
const SECRET_PLACEHOLDER = '**********'

export default {
	name: 'PersonalSettings',

	components: {
		AccountOutlineIcon,
		KeyOutlineIcon,
		OpenAiIcon,
		NcNoteCard,
		NcSelect,
		NcTextField,
	},

	data() {
		return {
			state: loadState('integration_openai', 'config'),
			languages: loadState('integration_openai', 'languages'),
			services: loadState('integration_openai', 'services'),
			credentials: loadState('integration_openai', 'user-credentials'),
			// to prevent some browsers from filling fields with remembered passwords
			readonly: true,
			apiKeyUrl: 'https://platform.openai.com/account/api-keys',
			quotaInfo: null,
		}
	},

	computed: {
		quotaRangeText() {
			return this.quotaInfo?.period?.unit === 'month'
				? t('integration_openai', 'This quota period is from {startDate} to {endDate}', {
					startDate: formatRelativeTime(this.quotaInfo.start * 1000),
					endDate: formatRelativeTime(this.quotaInfo.end * 1000),
				})
				: n('integration_openai', 'The quota is kept over a floating period of the last %n day',
					'The quota is kept over a floating period of the last %n days', this.quotaInfo.period.length)
		},
		poolUsed() {
			return (this.quotaInfo?.services ?? []).some(
				service => Object.values(service.quota_usage).some(quota => quota.used_pool),
			)
		},
	},

	mounted() {
		this.loadQuotaInfo()
	},

	methods: {
		onInput: debounce(function() {
			this.saveOptions({
				stt_language: this.state.stt_language.value,
			})
		}, 2000),
		onSensitiveInput(serviceId, values) {
			this.credentials[serviceId] = { ...this.credentials[serviceId], ...values }
			this.saveCredentialsDebounced(serviceId)
		},
		saveCredentialsDebounced: debounce(function(serviceId) {
			this.saveCredentials(serviceId)
		}, 2000),
		async saveCredentials(serviceId) {
			const stored = this.credentials[serviceId]
			const values = {
				basic_user: (stored.basic_user ?? '').trim(),
			}
			// secrets that were not touched come back as the placeholder and are
			// left alone by the backend
			if (stored.api_key !== SECRET_PLACEHOLDER) {
				values.api_key = (stored.api_key ?? '').trim()
			}
			if (stored.basic_password !== SECRET_PLACEHOLDER) {
				values.basic_password = (stored.basic_password ?? '').trim()
			}
			try {
				await confirmPassword()
				const url = generateUrl('/apps/integration_openai/services/{id}/user-credentials', { id: serviceId })
				await axios.put(url, { values })
				showSuccess(t('integration_openai', 'OpenAI options saved'))
				this.loadQuotaInfo()
			} catch (error) {
				showError(t('integration_openai', 'Failed to save OpenAI options'))
				console.error(error)
			}
		},
		async loadQuotaInfo() {
			try {
				const response = await axios.get(generateUrl('/apps/integration_openai/quota-info'))
				this.quotaInfo = response.data
			} catch (error) {
				showError(t('integration_openai', 'Failed to load quota info'))
				console.error(error)
			}
		},
		async saveOptions(values) {
			try {
				await axios.put(generateUrl('/apps/integration_openai/config'), { values })
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

	.service {
		margin-top: 16px;

		&__title {
			display: flex;
			align-items: baseline;
			gap: 8px;
			margin-bottom: 4px;
		}

		&__url {
			font-weight: normal;
			color: var(--color-text-maxcontrast);
		}
	}

	.quota-table {
		padding: 4px 8px;
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
	}
}
</style>
