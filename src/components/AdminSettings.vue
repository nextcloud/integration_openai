<!--
  - SPDX-FileCopyrightText: 2022 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div id="openai_prefs" class="section">
		<h2>
			<OpenAiIcon class="icon" />
			{{ t('integration_openai', 'OpenAI and LocalAI integration') }}
		</h2>
		<div id="openai-content">
			<NcNoteCard v-if="!state.assistant_enabled" type="warning">
				{{ t('integration_openai', 'The Assistant app is not enabled. You need it to use the features provided by the OpenAI/LocalAI integration app.') }}
				<a class="external" :href="appSettingsAssistantUrl" target="_blank">
					{{ t('integration_openai', 'Assistant app') }}
				</a>
			</NcNoteCard>

			<h3>{{ t('integration_openai', 'Connected services') }}</h3>
			<NcNoteCard type="info">
				{{ t('integration_openai', 'Connect as many OpenAI-compatible services as you need. For each of them, select the models you want to expose per modality: every selected model becomes a provider you can pick in the AI admin settings.') }}
				<div class="services">
					<a class="external" href="https://platform.openai.com/api-keys" target="_blank">OpenAI</a>
					<a class="external" href="https://docs.ionos.com/cloud/ai/ai-model-hub" target="_blank">IONOS AI Model Hub</a>
					<a class="external" href="https://console.groq.com" target="_blank">Groqcloud</a>
					<a class="external" href="https://localai.io/" target="_blank">LocalAI</a>
					<a class="external" href="https://ollama.com/" target="_blank">Ollama</a>
					<a class="external" href="https://mistral.ai" target="_blank">MistralAI</a>
					<a class="external" href="https://www.plusserver.com/en/ai-platform/" target="_blank">Plusserver</a>
				</div>
			</NcNoteCard>

			<NcEmptyContent v-if="services.length === 0"
				:name="t('integration_openai', 'No service connected yet')"
				:description="t('integration_openai', 'Connect a service to expose its models as providers.')">
				<template #icon>
					<OpenAiIcon />
				</template>
			</NcEmptyContent>

			<ServiceForm v-for="service in services"
				:key="service.id"
				:service="service"
				:usage="usageOf(service.id)"
				:initially-expanded="services.length === 1"
				@save="values => saveService(service.id, values, false)"
				@save-sensitive="values => saveService(service.id, values, true)"
				@detected="values => applyToService(service.id, values)"
				@delete="deleteService(service)" />

			<div class="line">
				<NcButton variant="primary" :disabled="adding" @click="addService">
					<template #icon>
						<NcLoadingIcon v-if="adding" :size="20" />
						<PlusIcon v-else :size="20" />
					</template>
					{{ t('integration_openai', 'Connect a service') }}
				</NcButton>
			</div>

			<h3>{{ t('integration_openai', 'Usage limits') }}</h3>
			<div class="line">
				<QuotaPeriodPicker
					v-model:value="state.quota_period"
					@update:value="onInput()" />
			</div>
			<NcNoteCard type="info">
				{{ t('integration_openai', 'The quota amounts themselves are configured per service. Quota rules apply across all services.') }}
			</NcNoteCard>
			<div class="line">
				<NcInputField
					id="openai-api-usage-storage-time"
					v-model="state.usage_storage_time"
					class="input"
					type="number"
					:label="t('integration_openai', 'Time period (days) for usage storage')"
					@update:model-value="onInput()" />
			</div>
			<div class="line-gap">
				<NcDateTimePickerNative
					v-model="quotaUsage.start_date"
					:label="t('integration_openai', 'Start date')" />
				<NcDateTimePickerNative
					v-model="quotaUsage.end_date"
					:label="t('integration_openai', 'End date')" />
				<NcSelect
					v-model="quotaUsage.quota_type"
					:options="quotaTypes"
					:input-label="t('integration_openai', 'Quota type')" />
				<NcSelect
					v-model="quotaUsage.service"
					:options="serviceOptions"
					:input-label="t('integration_openai', 'Service')" />
				<NcButton :href="downloadQuotaUsageUrl" class="download-button">
					{{ t('integration_openai', 'Download quota usage') }}
				</NcButton>
			</div>

			<h3>{{ t('integration_openai', 'Quota Rules') }}</h3>
			<QuotaRules :quota-info="quotaRuleTypes" />
		</div>
	</div>
</template>

<script>
import PlusIcon from 'vue-material-design-icons/Plus.vue'

import OpenAiIcon from './icons/OpenAiIcon.vue'
import QuotaRules from './Rules/QuotaRules.vue'

import NcButton from '@nextcloud/vue/components/NcButton'
import NcDateTimePickerNative from '@nextcloud/vue/components/NcDateTimePickerNative'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcInputField from '@nextcloud/vue/components/NcInputField'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcSelect from '@nextcloud/vue/components/NcSelect'

import axios from '@nextcloud/axios'
import { showError, showSuccess } from '@nextcloud/dialogs'
import { loadState } from '@nextcloud/initial-state'
import { confirmPassword } from '@nextcloud/password-confirmation'
import { generateUrl } from '@nextcloud/router'
import debounce from 'debounce'
import QuotaPeriodPicker from './QuotaPeriodPicker.vue'
import ServiceForm from './ServiceForm.vue'

export default {
	name: 'AdminSettings',

	components: {
		OpenAiIcon,
		PlusIcon,
		QuotaPeriodPicker,
		QuotaRules,
		ServiceForm,
		NcButton,
		NcDateTimePickerNative,
		NcEmptyContent,
		NcInputField,
		NcLoadingIcon,
		NcNoteCard,
		NcSelect,
	},

	data() {
		const state = loadState('integration_openai', 'admin-config')
		return {
			state,
			services: loadState('integration_openai', 'services'),
			/** Instance-wide usage per service, keyed by service ID */
			usage: null,
			adding: false,
			appSettingsAssistantUrl: generateUrl('/settings/apps/integration/assistant'),
			quotaUsage: {
				quota_type: { id: 0, label: t('integration_openai', 'Text generation') },
				service: null,
				start_date: new Date(state.quota_start_date * 1000),
				end_date: new Date(state.quota_end_date * 1000),
			},
		}
	},

	computed: {
		/** The quota types, in the order the backend numbers them */
		quotaTypeDefinitions() {
			return [
				{ type: t('integration_openai', 'Text generation'), unit: t('integration_openai', 'tokens') },
				{ type: t('integration_openai', 'Image generation'), unit: t('integration_openai', 'images') },
				{ type: t('integration_openai', 'Audio transcription'), unit: t('integration_openai', 'seconds') },
				{ type: t('integration_openai', 'Text to speech'), unit: t('integration_openai', 'characters') },
			]
		},
		quotaTypes() {
			return this.quotaTypeDefinitions.map((quotaType, id) => ({ id, label: quotaType.type }))
		},
		/** The quota types, in the shape the rules component expects */
		quotaRuleTypes() {
			return this.quotaTypeDefinitions
		},
		serviceOptions() {
			return [
				{ id: null, label: t('integration_openai', 'All services') },
				...this.services.map(service => ({ id: service.id, label: service.display_name })),
			]
		},
		downloadQuotaUsageUrl() {
			const url = generateUrl('/apps/integration_openai/quota/download-usage?type={type}&startDate={startDate}&endDate={endDate}', {
				type: this.quotaUsage.quota_type?.id ?? 0,
				startDate: this.quotaUsage.start_date / 1000,
				endDate: this.quotaUsage.end_date / 1000,
			})
			// no serviceId at all for "All services": an empty one would be
			// taken as a filter and match no usage row
			const serviceId = this.quotaUsage.service?.id
			return serviceId
				? url + '&' + new URLSearchParams({ serviceId }).toString()
				: url
		},
	},

	created() {
		// debounced save per service, see debouncedSave()
		this.pendingSaves = {}
	},

	mounted() {
		this.loadUsage()
	},

	methods: {
		usageOf(serviceId) {
			return this.usage?.[serviceId] ?? null
		},
		async loadUsage() {
			try {
				const response = await axios.get(generateUrl('/apps/integration_openai/admin-quota-info'))
				this.usage = Object.fromEntries((response.data ?? []).map(service => [service.id, service.quota_usage]))
			} catch (error) {
				showError(
					t('integration_openai', 'Failed to load quota info')
					+ ': ' + this.reduceStars(error.response?.data?.error),
					{ timeout: 10000 },
				)
			}
		},
		applyToService(serviceId, values) {
			const index = this.services.findIndex(service => service.id === serviceId)
			if (index !== -1) {
				this.services.splice(index, 1, { ...this.services[index], ...values })
			}
		},
		async addService() {
			this.adding = true
			try {
				const response = await axios.post(generateUrl('/apps/integration_openai/services'))
				this.services.push(response.data)
			} catch (error) {
				showError(
					t('integration_openai', 'Failed to add the service')
					+ ': ' + this.reduceStars(error.response?.data?.error),
					{ timeout: 10000 },
				)
				console.error(error)
			} finally {
				this.adding = false
			}
		},
		async deleteService(service) {
			if (!window.confirm(t('integration_openai', 'Remove {service}? The providers it exposes will stop working.', { service: service.display_name }))) {
				return
			}
			try {
				await confirmPassword()
				this.cancelPendingSaves(service.id)
				await axios.delete(generateUrl('/apps/integration_openai/services/{id}', { id: service.id }))
				this.services = this.services.filter(s => s.id !== service.id)
				showSuccess(t('integration_openai', 'Service removed'))
			} catch (error) {
				showError(
					t('integration_openai', 'Failed to remove the service')
					+ ': ' + this.reduceStars(error.response?.data?.error),
					{ timeout: 10000 },
				)
				console.error(error)
			}
		},
		/**
		 * Apply the change locally right away and save it, so typing stays
		 * responsive while the debounced request is pending.
		 *
		 * @param {string} serviceId ID of the service to change
		 * @param {object} values properties to change
		 * @param {boolean} sensitive whether the change needs a confirmed password
		 */
		saveService(serviceId, values, sensitive) {
			this.applyToService(serviceId, values)
			this.debouncedSave(serviceId, sensitive)()
		},
		/**
		 * The debounced save of one service.
		 *
		 * Every service gets its own timer: a single shared one would drop the
		 * pending save of a service as soon as another one is edited, losing
		 * the first service's change.
		 *
		 * @param {string} serviceId ID of the service to save
		 * @param {boolean} sensitive whether the change needs a confirmed password
		 * @return {Function} the debounced save of this service
		 */
		debouncedSave(serviceId, sensitive) {
			const key = (sensitive ? 'sensitive:' : 'plain:') + serviceId
			if (this.pendingSaves[key] === undefined) {
				this.pendingSaves[key] = debounce(() => this.putService(serviceId, sensitive), 2000)
			}
			return this.pendingSaves[key]
		},
		/**
		 * Drop the pending saves of a service, so nothing is written after it
		 * has been removed
		 *
		 * @param {string} serviceId ID of the service
		 */
		cancelPendingSaves(serviceId) {
			for (const key of [`plain:${serviceId}`, `sensitive:${serviceId}`]) {
				this.pendingSaves[key]?.clear()
				delete this.pendingSaves[key]
			}
		},
		/**
		 * @param {string} serviceId ID of the service to save
		 * @param {boolean} sensitive whether to send the URL and the credentials
		 */
		async putService(serviceId, sensitive) {
			const service = this.services.find(s => s.id === serviceId)
			if (service === undefined) {
				return
			}
			const values = sensitive
				? {
					url: (service.url ?? '').trim(),
					basic_user: (service.basic_user ?? '').trim(),
					api_key: (service.api_key ?? '').trim(),
					basic_password: (service.basic_password ?? '').trim(),
				}
				: {
					name: service.name,
					use_basic_auth: service.use_basic_auth,
					request_timeout: parseInt(service.request_timeout) || 1,
					chat_endpoint_enabled: service.chat_endpoint_enabled,
					use_max_completion_tokens_param: service.use_max_completion_tokens_param,
					llm_extra_params: service.llm_extra_params,
					max_tokens: parseInt(service.max_tokens) || 1,
					chunk_size: parseInt(service.chunk_size) || 0,
					multimodal_image_enabled: service.multimodal_image_enabled,
					multimodal_audio_enabled: service.multimodal_audio_enabled,
					multimodal_video_enabled: service.multimodal_video_enabled,
					multimodal_document_enabled: service.multimodal_document_enabled,
					tts_voices: service.tts_voices,
					default_tts_voice: service.default_tts_voice,
					default_image_size: service.default_image_size,
					image_request_auth: service.image_request_auth,
					text_enabled: service.text_enabled,
					image_enabled: service.image_enabled,
					stt_enabled: service.stt_enabled,
					tts_enabled: service.tts_enabled,
					translation_enabled: service.translation_enabled,
					text_models: service.text_models,
					image_models: service.image_models,
					stt_models: service.stt_models,
					tts_models: service.tts_models,
					quotas: service.quotas,
				}

			try {
				if (sensitive) {
					await confirmPassword()
				}
				const url = sensitive
					? generateUrl('/apps/integration_openai/services/{id}/sensitive', { id: serviceId })
					: generateUrl('/apps/integration_openai/services/{id}', { id: serviceId })
				const response = await axios.put(url, { values })
				// the backend normalizes some values, and redacts the secrets again
				this.applyToService(serviceId, response.data)
				showSuccess(t('integration_openai', 'OpenAI admin options saved'))
			} catch (error) {
				showError(
					t('integration_openai', 'Failed to save OpenAI admin options')
					+ ': ' + this.reduceStars(error.response?.data?.error),
					{ timeout: 10000 },
				)
				console.error(error)
			}
		},
		onInput: debounce(async function() {
			await this.saveAdminConfig({
				quota_period: this.state.quota_period,
				usage_storage_time: parseInt(this.state.usage_storage_time) || 1,
			})
		}, 2000),
		async saveAdminConfig(values) {
			try {
				await axios.put(generateUrl('/apps/integration_openai/admin-config'), { values })
				showSuccess(t('integration_openai', 'OpenAI admin options saved'))
			} catch (error) {
				showError(
					t('integration_openai', 'Failed to save OpenAI admin options')
					+ ': ' + this.reduceStars(error.response?.data?.error),
					{ timeout: 10000 },
				)
				console.error(error)
			}
		},
		reduceStars(text) {
			if (!text) {
				return '(none)'
			}
			return text.replace(/[*]{4,}/g, '***')
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
	.line {
		display: flex;
		justify-content: start;
		align-items: center;
		margin-top: 12px;
		gap: 8px;
	}

	h2 .icon {
		margin-right: 8px;
	}

	h3 {
		margin-top: 24px;
	}

	.line-gap {
		display: flex;
		align-items: end;
		gap: 12px;
		margin-top: 12px;
		flex-wrap: wrap;
	}

	.line .input {
		width: 300px;
	}

	.services {
		display: flex;
		flex-wrap: wrap;
		gap: 12px;
		margin-top: 8px;
	}

	.download-button {
		margin-bottom: 2px;
	}
}
</style>
