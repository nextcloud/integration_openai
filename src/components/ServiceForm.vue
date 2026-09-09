<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="service">
		<div class="service__header">
			<NcButton variant="tertiary" @click="expanded = !expanded">
				<template #icon>
					<UnfoldLessHorizontalIcon v-if="expanded" :size="20" />
					<UnfoldMoreHorizontalIcon v-else :size="20" />
				</template>
			</NcButton>
			<h3 class="service__title">
				{{ service.display_name }}
			</h3>
			<span class="service__summary">
				{{ summary }}
			</span>
			<NcButton variant="tertiary" :aria-label="t('integration_openai', 'Remove this service')" @click="$emit('delete')">
				<template #icon>
					<DeleteOutlineIcon :size="20" />
				</template>
			</NcButton>
		</div>

		<div v-show="expanded" class="service__body">
			<!-- Connection -->
			<div class="line">
				<NcTextField
					:id="'openai-url-' + service.id"
					:model-value="service.url"
					class="input"
					:label="t('integration_openai', 'Service URL')"
					:placeholder="t('integration_openai', 'Example: {example}', { example: 'http://localhost:8080/v1' })"
					:show-trailing-button="!!service.url"
					@update:model-value="onSensitiveInput({ url: $event })"
					@trailing-button-click="onSensitiveInput({ url: '' })">
					<template #icon>
						<EarthIcon :size="20" />
					</template>
				</NcTextField>
				<NcButton variant="tertiary"
					:title="t('integration_openai', 'Leave empty to use {openaiApiUrl}', { openaiApiUrl: 'https://api.openai.com/v1' })">
					<template #icon>
						<HelpCircleOutlineIcon />
					</template>
				</NcButton>
			</div>
			<NcNoteCard type="info">
				{{ t('integration_openai', 'With the current configuration, the target URL used to get the models is:') }}
				<br>
				<strong>{{ service.model_endpoint_url }}</strong>
			</NcNoteCard>
			<div class="line">
				<NcTextField
					:id="'openai-service-name-' + service.id"
					:model-value="service.name"
					class="input"
					:label="t('integration_openai', 'Service name (optional)')"
					:placeholder="t('integration_openai', 'Example: LocalAI of university ABC')"
					:show-trailing-button="!!service.name"
					@update:model-value="onInput({ name: $event })"
					@trailing-button-click="onInput({ name: '' })" />
				<NcButton variant="tertiary"
					:title="t('integration_openai', 'This name is part of the provider names in the AI admin settings')">
					<template #icon>
						<HelpCircleOutlineIcon />
					</template>
				</NcButton>
			</div>
			<div class="line">
				<NcInputField
					:id="'openai-api-timeout-' + service.id"
					:model-value="String(service.request_timeout)"
					class="input"
					type="number"
					:label="t('integration_openai', 'Request timeout (seconds)')"
					@update:model-value="onInput({ request_timeout: parseInt($event) || 1 })" />
			</div>

			<!-- Authentication -->
			<h4>{{ t('integration_openai', 'Authentication') }}</h4>
			<div v-show="service.url !== ''" class="line column">
				<label>{{ t('integration_openai', 'Authentication method') }}</label>
				<div class="radios">
					<NcCheckboxRadioSwitch
						:button-variant="true"
						:model-value="!service.use_basic_auth"
						type="radio"
						button-variant-grouped="horizontal"
						:name="'auth_method_' + service.id"
						@update:model-value="onInput({ use_basic_auth: false })">
						{{ t('integration_openai', 'API key') }}
					</NcCheckboxRadioSwitch>
					<NcCheckboxRadioSwitch
						:button-variant="true"
						:model-value="service.use_basic_auth"
						type="radio"
						button-variant-grouped="horizontal"
						:name="'auth_method_' + service.id"
						@update:model-value="onInput({ use_basic_auth: true })">
						{{ t('integration_openai', 'Basic Authentication') }}
					</NcCheckboxRadioSwitch>
				</div>
			</div>
			<div v-show="service.url === '' || !service.use_basic_auth" class="line">
				<NcTextField
					:id="'openai-api-key-' + service.id"
					:model-value="service.api_key"
					class="input"
					type="password"
					:readonly="readonly"
					:label="t('integration_openai', 'API key (mandatory with OpenAI)')"
					:show-trailing-button="!!service.api_key"
					@update:model-value="onSensitiveInput({ api_key: $event })"
					@trailing-button-click="onSensitiveInput({ api_key: '' })"
					@focus="readonly = false">
					<template #icon>
						<KeyOutlineIcon :size="20" />
					</template>
				</NcTextField>
			</div>
			<div v-show="service.url !== '' && service.use_basic_auth">
				<div class="line">
					<NcTextField
						:id="'openai-basic-user-' + service.id"
						:model-value="service.basic_user"
						class="input"
						:readonly="readonly"
						:label="t('integration_openai', 'Basic Auth user')"
						@update:model-value="onSensitiveInput({ basic_user: $event })"
						@focus="readonly = false">
						<template #icon>
							<AccountOutlineIcon :size="20" />
						</template>
					</NcTextField>
				</div>
				<div class="line">
					<NcTextField
						:id="'openai-basic-password-' + service.id"
						:model-value="service.basic_password"
						class="input"
						type="password"
						:readonly="readonly"
						:label="t('integration_openai', 'Basic Auth password')"
						@update:model-value="onSensitiveInput({ basic_password: $event })"
						@focus="readonly = false">
						<template #icon>
							<KeyOutlineIcon :size="20" />
						</template>
					</NcTextField>
				</div>
			</div>

			<!-- Models per modality -->
			<h4>{{ t('integration_openai', 'Exposed models') }}</h4>
			<NcNoteCard type="info">
				{{ t('integration_openai', 'Every model you select here is registered as a provider for each task type of its modality. The provider is named after the model, so users and the AI admin settings can tell them apart.') }}
			</NcNoteCard>
			<div class="line">
				<NcButton :disabled="loadingModels" @click="loadModels">
					<template #icon>
						<NcLoadingIcon v-if="loadingModels" :size="20" />
						<RefreshIcon v-else :size="20" />
					</template>
					{{ t('integration_openai', 'Refresh model list') }}
				</NcButton>
				<NcButton :disabled="detecting" @click="autoDetectModalities">
					<template #icon>
						<NcLoadingIcon v-if="detecting" :size="20" />
						<AutoFixIcon v-else :size="20" />
					</template>
					{{ t('integration_openai', 'Detect supported modalities') }}
				</NcButton>
			</div>

			<div v-for="modality in modalities" :key="modality.key" class="modality">
				<NcCheckboxRadioSwitch
					:model-value="service[modality.key + '_enabled']"
					type="switch"
					@update:model-value="onInput({ [modality.key + '_enabled']: $event })">
					{{ modality.label }}
				</NcCheckboxRadioSwitch>
				<div v-show="service[modality.key + '_enabled']" class="modality__body">
					<ModelSelector
						:model-value="service[modality.key + '_models']"
						:options="modelOptions"
						:loading="loadingModels"
						:label="modality.selectorLabel"
						@update:model-value="onInput({ [modality.key + '_models']: $event })" />

					<!-- Text options -->
					<template v-if="modality.key === 'text'">
						<div class="line column">
							<label>{{ t('integration_openai', 'Text completion endpoint') }}</label>
							<div class="radios">
								<NcCheckboxRadioSwitch
									:button-variant="true"
									:model-value="!service.chat_endpoint_enabled"
									type="radio"
									button-variant-grouped="horizontal"
									:name="'endpoint_' + service.id"
									:disabled="service.is_using_openai"
									@update:model-value="onInput({ chat_endpoint_enabled: false })">
									{{ t('integration_openai', 'Completions') }}
								</NcCheckboxRadioSwitch>
								<NcCheckboxRadioSwitch
									:button-variant="true"
									:model-value="service.chat_endpoint_enabled"
									type="radio"
									button-variant-grouped="horizontal"
									:name="'endpoint_' + service.id"
									:disabled="service.is_using_openai"
									@update:model-value="onInput({ chat_endpoint_enabled: true })">
									{{ t('integration_openai', 'Chat completions') }}
								</NcCheckboxRadioSwitch>
							</div>
						</div>
						<div class="line">
							<NcTextField
								:id="'openai-llm-extra-params-' + service.id"
								v-model="llmExtraParams"
								class="input"
								:error="!llmExtraParamsValid"
								:helper-text="llmExtraParamsValid ? '' : t('integration_openai', 'Not a JSON object yet, so it is not saved')"
								:label="t('integration_openai', 'Extra completion model parameters')"
								:placeholder="'{&quot;temperature&quot;:0.7}'"
								@update:model-value="onLlmExtraParamsInput" />
							<NcButton variant="tertiary" :title="llmExtraParamHint">
								<template #icon>
									<HelpCircleOutlineIcon />
								</template>
							</NcButton>
						</div>
						<div class="line">
							<NcInputField
								:id="'openai-chunk-size-' + service.id"
								:model-value="String(service.chunk_size)"
								class="input"
								type="number"
								:label="t('integration_openai', 'Max input tokens per request')"
								@update:model-value="onInput({ chunk_size: parseInt($event) || 0 })" />
							<NcButton variant="tertiary"
								:title="t('integration_openai', 'Split the prompt into chunks with each chunk being no more than the specified number of tokens (0 disables chunking)')">
								<template #icon>
									<HelpCircleOutlineIcon />
								</template>
							</NcButton>
						</div>
						<div class="line">
							<NcInputField
								:id="'openai-max-tokens-' + service.id"
								:model-value="String(service.max_tokens)"
								class="input"
								type="number"
								:label="t('integration_openai', 'Max output tokens per request')"
								@update:model-value="onInput({ max_tokens: parseInt($event) || 1 })" />
						</div>
						<NcCheckboxRadioSwitch
							:model-value="service.use_max_completion_tokens_param ?? service.is_using_openai"
							type="switch"
							@update:model-value="onInput({ use_max_completion_tokens_param: $event })">
							{{ t('integration_openai', 'Use "{newParam}" parameter instead of the deprecated "{deprecatedParam}"', { newParam: 'max_completion_tokens', deprecatedParam: 'max_tokens' }) }}
						</NcCheckboxRadioSwitch>
						<NcCheckboxRadioSwitch
							:model-value="service.translation_enabled"
							type="switch"
							@update:model-value="onInput({ translation_enabled: $event })">
							{{ t('integration_openai', 'Offer translation') }}
						</NcCheckboxRadioSwitch>
						<h5>{{ t('integration_openai', 'Multimodal LLM Support') }}</h5>
						<NcNoteCard type="info">
							{{ t('integration_openai', 'Which kinds of attachments the models of this service accept.') }}
						</NcNoteCard>
						<NcCheckboxRadioSwitch
							:model-value="service.multimodal_image_enabled"
							type="switch"
							@update:model-value="onInput({ multimodal_image_enabled: $event })">
							{{ t('integration_openai', 'Image attachments') }}
						</NcCheckboxRadioSwitch>
						<NcCheckboxRadioSwitch
							:model-value="service.multimodal_audio_enabled"
							type="switch"
							@update:model-value="onInput({ multimodal_audio_enabled: $event })">
							{{ t('integration_openai', 'Audio attachments') }}
						</NcCheckboxRadioSwitch>
						<NcCheckboxRadioSwitch
							:model-value="service.multimodal_video_enabled"
							type="switch"
							@update:model-value="onInput({ multimodal_video_enabled: $event })">
							{{ t('integration_openai', 'Video attachments') }}
						</NcCheckboxRadioSwitch>
						<NcCheckboxRadioSwitch
							:model-value="service.multimodal_document_enabled"
							type="switch"
							@update:model-value="onInput({ multimodal_document_enabled: $event })">
							{{ t('integration_openai', 'Document attachments') }}
						</NcCheckboxRadioSwitch>
					</template>

					<!-- Image options -->
					<template v-if="modality.key === 'image'">
						<div class="line">
							<NcTextField
								:id="'openai-image-size-' + service.id"
								:model-value="service.default_image_size"
								class="input"
								:label="t('integration_openai', 'Default image size')"
								@update:model-value="onInput({ default_image_size: $event })" />
							<NcButton variant="tertiary" :title="defaultImageSizeParamHint">
								<template #icon>
									<HelpCircleOutlineIcon />
								</template>
							</NcButton>
						</div>
						<NcCheckboxRadioSwitch
							:model-value="service.image_request_auth ?? !service.is_using_openai"
							type="switch"
							@update:model-value="onInput({ image_request_auth: $event })">
							{{ t('integration_openai', 'Use authentication for image retrieval request') }}
						</NcCheckboxRadioSwitch>
					</template>

					<!-- Speech options -->
					<template v-if="modality.key === 'tts'">
						<div class="line">
							<NcSelect
								:model-value="service.tts_voices"
								:options="service.tts_voices"
								:multiple="true"
								:taggable="true"
								:close-on-select="false"
								:input-label="t('integration_openai', 'TTS Voices')"
								:create-option="option => option.trim()"
								@update:model-value="onInput({ tts_voices: $event })" />
							<NcButton variant="tertiary"
								:title="t('integration_openai', 'A list of voices supported by the endpoint you are using. Defaults to openai\'s list.')">
								<template #icon>
									<HelpCircleOutlineIcon />
								</template>
							</NcButton>
						</div>
						<div class="line">
							<NcSelect
								:model-value="service.default_tts_voice"
								:options="service.tts_voices"
								:input-label="t('integration_openai', 'Default voice to use')"
								@update:model-value="onInput({ default_tts_voice: $event ?? '' })" />
						</div>
					</template>
				</div>
			</div>

			<!-- Quotas -->
			<h4>{{ t('integration_openai', 'Usage quotas of this service') }}</h4>
			<NcNoteCard type="info">
				{{ t('integration_openai', 'A per-user quota for each quota type can be set. If the user has not provided their own credentials for this service, and a quota rule is not specified for this user or any of their groups, this quota will be enforced.') }}
				{{ t('integration_openai', '"0" means unlimited usage for a particular quota type.') }}
			</NcNoteCard>
			<table class="quota-table">
				<thead>
					<tr>
						<th width="120px">
							{{ t('integration_openai', 'Quota type') }}
						</th>
						<th>{{ t('integration_openai', 'Per-user quota / period') }}</th>
						<th v-if="usage !== null">
							{{ t('integration_openai', 'Current system-wide usage / period') }}
						</th>
					</tr>
				</thead>
				<tbody>
					<tr v-for="(quota, index) in quotaRows" :key="index">
						<td class="text-cell">
							{{ quota.type }}
						</td>
						<td>
							<input :id="'openai-api-quota-' + service.id + '-' + index"
								:value="service.quotas[index]"
								:title="t('integration_openai', 'A per-user limit for usage of this API type (0 for unlimited)')"
								type="number"
								@input="onQuotaInput(index, $event.target.value)">
							<span class="text-cell">{{ quota.unit }}</span>
						</td>
						<td v-if="usage !== null" class="text-cell">
							{{ quota.used }}
						</td>
					</tr>
				</tbody>
			</table>
		</div>
	</div>
</template>

<script>
import AccountOutlineIcon from 'vue-material-design-icons/AccountOutline.vue'
import AutoFixIcon from 'vue-material-design-icons/AutoFix.vue'
import DeleteOutlineIcon from 'vue-material-design-icons/DeleteOutline.vue'
import EarthIcon from 'vue-material-design-icons/Earth.vue'
import HelpCircleOutlineIcon from 'vue-material-design-icons/HelpCircleOutline.vue'
import KeyOutlineIcon from 'vue-material-design-icons/KeyOutline.vue'
import RefreshIcon from 'vue-material-design-icons/Refresh.vue'
import UnfoldLessHorizontalIcon from 'vue-material-design-icons/UnfoldLessHorizontal.vue'
import UnfoldMoreHorizontalIcon from 'vue-material-design-icons/UnfoldMoreHorizontal.vue'

import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcInputField from '@nextcloud/vue/components/NcInputField'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import NcTextField from '@nextcloud/vue/components/NcTextField'

import axios from '@nextcloud/axios'
import { showError } from '@nextcloud/dialogs'
import { generateUrl } from '@nextcloud/router'
import ModelSelector from './ModelSelector.vue'

export default {
	name: 'ServiceForm',

	components: {
		ModelSelector,
		AccountOutlineIcon,
		AutoFixIcon,
		DeleteOutlineIcon,
		EarthIcon,
		HelpCircleOutlineIcon,
		KeyOutlineIcon,
		RefreshIcon,
		UnfoldLessHorizontalIcon,
		UnfoldMoreHorizontalIcon,
		NcButton,
		NcCheckboxRadioSwitch,
		NcInputField,
		NcLoadingIcon,
		NcNoteCard,
		NcSelect,
		NcTextField,
	},

	props: {
		/** The service as the backend returned it, with redacted secrets */
		service: {
			type: Object,
			required: true,
		},
		/** Instance-wide usage of this service, per quota type */
		usage: {
			type: Object,
			default: null,
		},
		/** Whether this service starts out expanded */
		initiallyExpanded: {
			type: Boolean,
			default: false,
		},
	},

	emits: ['save', 'save-sensitive', 'delete', 'detected'],

	data() {
		return {
			expanded: this.initiallyExpanded,
			// edited locally so that a half-typed JSON object is not sent to
			// the backend, which rejects it
			llmExtraParams: this.service.llm_extra_params ?? '',
			// to prevent some browsers from filling fields with remembered passwords
			readonly: true,
			models: null,
			loadingModels: false,
			detecting: false,
			llmExtraParamHint: t('integration_openai', 'JSON object. Check the API documentation to get the list of all available parameters. For example: {example}', { example: '{"stop":".","temperature":0.7}' }, null, { escape: false, sanitize: false }),
			defaultImageSizeParamHint: t('integration_openai', 'Must be in 256x256 format (default is {default})', { default: '1024x1024' }),
		}
	},

	computed: {
		llmExtraParamsValid() {
			if (this.llmExtraParams.trim() === '') {
				return true
			}
			try {
				const parsed = JSON.parse(this.llmExtraParams)
				return parsed !== null && typeof parsed === 'object'
			} catch {
				return false
			}
		},
		modalities() {
			return [
				{
					key: 'text',
					label: t('integration_openai', 'Text generation'),
					selectorLabel: t('integration_openai', 'Text models to expose'),
				},
				{
					key: 'image',
					label: t('integration_openai', 'Image generation'),
					selectorLabel: t('integration_openai', 'Image models to expose'),
				},
				{
					key: 'stt',
					label: t('integration_openai', 'Audio transcription'),
					selectorLabel: t('integration_openai', 'Transcription models to expose'),
				},
				{
					key: 'tts',
					label: t('integration_openai', 'Text to speech'),
					selectorLabel: t('integration_openai', 'Speech models to expose'),
				},
			]
		},
		modelOptions() {
			if (this.models === null) {
				// the models the admin already picked are always offered
				return [...new Set([
					...this.service.text_models,
					...this.service.image_models,
					...this.service.stt_models,
					...this.service.tts_models,
				])]
			}
			return this.models
		},
		quotaRows() {
			return this.quotaTypeLabels.map((label, index) => ({
				type: this.usage?.[index]?.type ?? label,
				unit: this.usage?.[index]?.unit ?? '',
				used: this.usage?.[index]?.used ?? null,
			}))
		},
		quotaTypeLabels() {
			return [
				t('integration_openai', 'Text generation'),
				t('integration_openai', 'Image generation'),
				t('integration_openai', 'Audio transcription'),
				t('integration_openai', 'Text to speech'),
			]
		},
		/** The modalities this service exposes models for, for the collapsed header */
		summary() {
			const active = this.modalities
				.filter(modality => this.service[modality.key + '_enabled'] && this.service[modality.key + '_models'].length > 0)
				.map(modality => modality.label)
			if (active.length === 0) {
				return t('integration_openai', 'No models exposed')
			}
			return active.join(', ')
		},
	},

	watch: {
		'service.url'() {
			this.models = null
		},
		// the backend normalizes what it stored, so follow it unless the admin
		// is in the middle of typing something else
		'service.llm_extra_params'(value) {
			if (this.llmExtraParamsValid) {
				this.llmExtraParams = value ?? ''
			}
		},
	},

	methods: {
		onInput(values) {
			this.$emit('save', values)
		},
		onSensitiveInput(values) {
			this.$emit('save-sensitive', values)
		},
		onLlmExtraParamsInput() {
			if (this.llmExtraParamsValid) {
				this.onInput({ llm_extra_params: this.llmExtraParams.trim() })
			}
		},
		onQuotaInput(index, value) {
			const quotas = { ...this.service.quotas }
			const parsed = parseInt(value)
			quotas[index] = isNaN(parsed) || parsed < 0 ? 0 : parsed
			this.onInput({ quotas })
		},
		async loadModels() {
			this.loadingModels = true
			try {
				const url = generateUrl('/apps/integration_openai/services/{id}/models', { id: this.service.id })
				const response = await axios.get(url)
				this.models = (response.data?.data ?? []).map(model => model.id)
			} catch (error) {
				showError(
					t('integration_openai', 'Failed to load the models of {service}', { service: this.service.display_name })
					+ ': ' + (error.response?.data?.error ?? ''),
					{ timeout: 10000 },
				)
				console.error(error)
			} finally {
				this.loadingModels = false
			}
		},
		async autoDetectModalities() {
			this.detecting = true
			try {
				const url = generateUrl('/apps/integration_openai/services/{id}/auto-detect-modalities', { id: this.service.id })
				const response = await axios.post(url)
				this.$emit('detected', response.data ?? {})
			} catch (error) {
				showError(
					t('integration_openai', 'Failed to detect the supported modalities')
					+ ': ' + (error.response?.data?.error ?? ''),
					{ timeout: 10000 },
				)
				console.error(error)
			} finally {
				this.detecting = false
			}
		},
	},
}
</script>

<style scoped lang="scss">
.service {
	border: 2px solid var(--color-border);
	border-radius: var(--border-radius-large);
	padding: 8px 12px;
	margin-bottom: 12px;

	&__header {
		display: flex;
		align-items: center;
		gap: 8px;
	}

	&__title {
		margin: 0;
	}

	&__summary {
		flex-grow: 1;
		color: var(--color-text-maxcontrast);
	}

	&__body {
		padding: 0 8px 8px 8px;
	}
}

.modality {
	margin-top: 16px;

	&__body {
		margin: 8px 0 8px 24px;
	}
}

.line {
	display: flex;
	justify-content: start;
	align-items: center;
	margin-top: 12px;
	gap: 8px;

	&.column {
		flex-direction: column;
		align-items: start;
	}

	.input {
		width: 300px;
	}
}

.radios {
	display: flex;
}

.quota-table {
	padding: 4px 8px;
	border: 2px solid var(--color-border);
	border-radius: var(--border-radius);

	th, td {
		width: 200px;
		text-align: left;
	}
}
</style>
