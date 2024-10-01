<template>
	<div id="openai_prefs" class="section">
		<h2>
			<OpenAiIcon class="icon" />
			{{ t('integration_openai', 'OpenAI and LocalAI integration') }}
		</h2>
		<div id="openai-content">
			<div>
				<div class="line">
					<NcTextField
						id="openai-url"
						class="input"
						:value.sync="state.url"
						:label="t('integration_openai', 'Service URL')"
						:placeholder="t('integration_openai', 'Example: {example}', { example: 'http://localhost:8080' })"
						:show-trailing-button="!!state.url"
						@update:value="onSensitiveInput(true)"
						@trailing-button-click="state.url = '' ; onSensitiveInput(true)">
						<EarthIcon />
					</NcTextField>
					<NcButton type="tertiary"
						:title="t('integration_openai', 'Leave empty to use {openaiApiUrl}', { openaiApiUrl: 'https://api.openai.com' })">
						<template #icon>
							<HelpCircleIcon />
						</template>
					</NcButton>
				</div>
				<NcNoteCard type="info">
					<p>
						{{ t('integration_openai', 'This should be the address of your LocalAI instance (or any service implementing an API similar to OpenAI). This URL will be accessed by your Nextcloud server.') }}
					</p>
					<p>
						{{ t('integration_openai', 'This can be a local address with a port like {example}. In this case make sure \'allow_local_remote_servers\' is set to true in config.php', { example : 'http://localhost:8080' }) }}
					</p>
				</NcNoteCard>
				<div v-if="state.url !== ''" class="line">
					<NcTextField
						id="openai-service-name"
						class="input"
						:value.sync="state.service_name"
						:label="t('integration_openai', 'Service name (optional)')"
						:placeholder="t('integration_openai', 'Example: LocalAI of university ABC')"
						:show-trailing-button="!!state.service_name"
						@update:value="onInput()"
						@trailing-button-click="state.service_name = '' ; onInput()" />
					<NcButton type="tertiary"
						:title="t('integration_openai', 'This name will be displayed as provider name in the AI admin settings')">
						<template #icon>
							<HelpCircleIcon />
						</template>
					</NcButton>
				</div>
			</div>
			<div>
				<h2>
					{{ t('integration_openai', 'Authentication') }}
				</h2>
				<div v-show="state.url !== ''" class="line column">
					<label>
						{{ t('integration_openai', 'Authentication method') }}
					</label>
					<div class="radios">
						<NcCheckboxRadioSwitch
							:button-variant="true"
							:checked="!state.use_basic_auth"
							type="radio"
							button-variant-grouped="horizontal"
							name="auth_method"
							@update:checked="onCheckboxChanged(false, 'use_basic_auth')">
							{{ t('assistant', 'API key') }}
						</NcCheckboxRadioSwitch>
						<NcCheckboxRadioSwitch
							:button-variant="true"
							:checked="state.use_basic_auth"
							type="radio"
							button-variant-grouped="horizontal"
							name="auth_method"
							@update:checked="onCheckboxChanged(true, 'use_basic_auth')">
							{{ t('assistant', 'Basic Authentication') }}
						</NcCheckboxRadioSwitch>
					</div>
				</div>
				<div v-show="state.url === '' || !state.use_basic_auth" class="line">
					<NcTextField
						id="openai-api-key"
						class="input"
						:value.sync="state.api_key"
						type="password"
						:label="t('integration_openai', 'API key (mandatory with OpenAI)')"
						:show-trailing-button="!!state.api_key"
						@update:value="onSensitiveInput(true)"
						@trailing-button-click="state.api_key = '' ; onSensitiveInput(true)">
						<KeyIcon />
					</NcTextField>
				</div>
				<NcNoteCard v-show="state.url === ''" type="info">
					{{ t('integration_openai', 'You can create an API key in your OpenAI account settings') }}:
					&nbsp;
					<a :href="apiKeyUrl" target="_blank" class="external">
						{{ apiKeyUrl }}
					</a>
				</NcNoteCard>
				<div v-show="state.url !== '' && state.use_basic_auth">
					<div class="line">
						<NcTextField
							id="openai-basic-user"
							class="input"
							:value.sync="state.basic_user"
							:label="t('integration_openai', 'Basic Auth user')"
							:show-trailing-button="!!state.basic_user"
							@update:value="onSensitiveInput(true)"
							@trailing-button-click="state.basic_user = '' ; onSensitiveInput(true)">
							<AccountIcon />
						</NcTextField>
					</div>
					<div class="line">
						<NcTextField
							id="openai-basic-password"
							class="input"
							:value.sync="state.basic_password"
							type="password"
							:label="t('integration_openai', 'Basic Auth password')"
							:show-trailing-button="!!state.basic_password"
							@update:value="onSensitiveInput(true)"
							@trailing-button-click="state.basic_password = '' ; onSensitiveInput(true)">
							<KeyIcon />
						</NcTextField>
					</div>
				</div>
			</div>
			<div>
				<h2>
					{{ t('integration_openai', 'Text generation') }}
				</h2>
				<div v-if="state.url !== ''" class="line column">
					<label>
						<EarthIcon :size="20" class="icon" />
						{{ t('integration_openai', 'Text completion endpoint') }}
					</label>
					<div class="radios">
						<NcCheckboxRadioSwitch
							:button-variant="true"
							:checked="state.chat_endpoint_enabled"
							type="radio"
							button-variant-grouped="horizontal"
							name="chat_endpoint"
							@update:checked="onCheckboxChanged(true, 'chat_endpoint_enabled', false)">
							{{ t('assistant', 'Chat completions') }}
						</NcCheckboxRadioSwitch>
						<NcCheckboxRadioSwitch
							:button-variant="true"
							:checked="!state.chat_endpoint_enabled"
							type="radio"
							button-variant-grouped="horizontal"
							name="chat_endpoint"
							@update:checked="onCheckboxChanged(false, 'chat_endpoint_enabled', false)">
							{{ t('assistant', 'Completions') }}
						</NcCheckboxRadioSwitch>
					</div>
				</div>
				<NcNoteCard type="info">
					{{ state.url === ''
						? t('integration_openai', 'Selection of chat/completion endpoint is not available for OpenAI since it implicitly uses chat completions for "instruction following" fine-tuned models.')
						: t('integration_openai', 'Using the chat endpoint may improve text generation quality for "instruction following" fine-tuned models.') }}
				</NcNoteCard>
				<div v-if="models"
					class="line line-select">
					<NcSelect
						v-model="selectedModel.text"
						class="model-select"
						:clearable="state.default_completion_model_id !== DEFAULT_MODEL_ITEM.id"
						:options="formattedModels"
						:input-label="t('integration_openai', 'Default completion model to use')"
						:no-wrap="true"
						input-id="openai-model-select"
						@input="onModelSelected('text', $event)" />
					<a v-if="state.url === ''"
						:title="t('integration_openai', 'More information about OpenAI models')"
						href="https://beta.openai.com/docs/models"
						target="_blank">
						<NcButton type="tertiary" aria-label="openai-info">
							<template #icon>
								<HelpCircleIcon />
							</template>
						</NcButton>
					</a>
					<a v-else
						:title="t('integration_openai', 'More information about LocalAI models')"
						href="https://localai.io/model-compatibility/index.html"
						target="_blank">
						<NcButton type="tertiary" aria-label="localai-info">
							<template #icon>
								<HelpCircleIcon />
							</template>
						</NcButton>
					</a>
				</div>
				<div class="line">
					<NcTextField
						id="llm-extra-params"
						class="input"
						:value.sync="state.llm_extra_params"
						:label="t('integration_openai', 'Extra completion model parameters')"
						:show-trailing-button="!!state.llm_extra_params"
						@update:value="onInput()"
						@trailing-button-click="state.llm_extra_params = '' ; onInput()" />
					<NcButton type="tertiary"
						:title="llmExtraParamHint">
						<template #icon>
							<HelpCircleIcon />
						</template>
					</NcButton>
				</div>
				<div class="line">
					<NcInputField
						id="openai-api-timeout"
						class="input"
						:value.sync="state.request_timeout"
						type="number"
						:label="t('integration_openai', 'Request timeout (seconds)')"
						:placeholder="t('integration_openai', 'Example: {example}', { example: '240' })"
						:show-trailing-button="!!state.request_timeout"
						@update:value="onInput()"
						@trailing-button-click="state.request_timeout = '' ; onInput()">
						<TimerAlertOutlineIcon />
						<template #trailing-button-icon>
							<CloseIcon :size="20" />
						</template>
					</NcInputField>
				</div>
			</div>
			<div>
				<h2>
					{{ t('integration_openai', 'Image generation') }}
				</h2>
				<div v-if="models"
					class="line line-select">
					<NcSelect
						v-model="selectedModel.image"
						class="model-select"
						:clearable="state.default_image_model_id !== DEFAULT_MODEL_ITEM.id"
						:options="formattedModels"
						:input-label="t('integration_openai', 'Default image generation model to use')"
						:no-wrap="true"
						input-id="openai-model-select"
						@input="onModelSelected('image', $event)" />
					<a v-if="state.url === ''"
						:title="t('integration_openai', 'More information about OpenAI models')"
						href="https://beta.openai.com/docs/models"
						target="_blank">
						<NcButton type="tertiary" aria-label="openai-info">
							<template #icon>
								<HelpCircleIcon />
							</template>
						</NcButton>
					</a>
					<a v-else
						:title="t('integration_openai', 'More information about LocalAI models')"
						href="https://localai.io/model-compatibility/index.html"
						target="_blank">
						<NcButton type="tertiary" aria-label="localai-info">
							<template #icon>
								<HelpCircleIcon />
							</template>
						</NcButton>
					</a>
				</div>
				<NcNoteCard v-else type="info">
					{{ t('integration_openai', 'No models to list') }}
				</NcNoteCard>
				<div class="line">
					<NcTextField
						id="default-image-size"
						class="input"
						:value.sync="state.default_image_size"
						:label="t('integration_openai', 'Default image size')"
						:show-trailing-button="!!state.default_image_size"
						@update:value="onInput()"
						@trailing-button-click="state.default_image_size = '' ; onInput()" />
					<NcButton type="tertiary"
						:title="defaultImageSizeParamHint">
						<template #icon>
							<HelpCircleIcon />
						</template>
					</NcButton>
				</div>
			</div>
			<div>
				<h2>
					{{ t('integration_openai', 'Usage limits') }}
				</h2>
				<div class="line">
					<!--Time period in days for the token usage-->
					<NcInputField
						id="openai-api-quota-period"
						class="input"
						type="number"
						:value.sync="state.quota_period"
						:label="t('integration_openai', 'Quota enforcement time period (days)')"
						:show-trailing-button="!!state.quota_period"
						@update:value="onInput()"
						@trailing-button-click="state.quota_period = '' ; onInput()">
						<template #trailing-button-icon>
							<CloseIcon :size="20" />
						</template>
					</NcInputField>
				</div>
				<h4>
					{{ t('integration_openai', 'Usage quotas per time period') }}
				</h4>
				<!--Loop through all quota types and list an input for them on this line-->
				<!--Only enforced if the user has not provided an own API key (in the case of OpenAI)-->
				<table class="quota-table">
					<thead>
						<tr>
							<th width="120px">
								{{ t('integration_openai', 'Quota type') }}
							</th>
							<th>{{ t('integration_openai', 'Per-user quota / period') }}</th>
							<th v-if="quotaInfo !== null">
								{{ t('integration_openai', 'Current system-wide usage / period') }}
							</th>
						</tr>
					</thead>
					<tbody v-if="quotaInfo !== null">
						<tr v-for="(_,index) in state.quotas" :key="index">
							<td class="text-cell">
								{{ quotaInfo[index].type }}
							</td>
							<td>
								<input :id="'openai-api-quota-' + index"
									v-model.number="state.quotas[index]"
									:title="t('integration_openai', 'A per-user limit for usage of this API type (0 for unlimited)')"
									type="number"
									@input="onInput()">
								<span v-if="quotaInfo !== null" class="text-cell">
									{{ quotaInfo[index].unit }}
								</span>
							</td>
							<td v-if="quotaInfo !== null" class="text-cell">
								{{ quotaInfo[index].used }}
							</td>
						</tr>
					</tbody>
				</table>
				<div class="line">
					<!--A input for max number of tokens to generate for a single request-->
					<!--Only enforced if the user has not provided an own API key (in the case of OpenAI)-->
					<NcInputField
						id="openai-api-max-tokens"
						class="input"
						type="number"
						:value.sync="state.max_tokens"
						:label="t('integration_openai', 'Max new tokens per request')"
						:show-trailing-button="!!state.max_tokens"
						@update:value="onInput()"
						@trailing-button-click="state.max_tokens = '' ; onInput()">
						<template #trailing-button-icon>
							<CloseIcon :size="20" />
						</template>
					</NcInputField>
					<NcButton type="tertiary"
						:title="t('integration_openai', 'Maximum number of new tokens generated for a single text generation prompt')">
						<template #icon>
							<HelpCircleIcon />
						</template>
					</NcButton>
				</div>
			</div>
			<div>
				<h2>
					{{ t('integration_openai', 'Select enabled features') }}
				</h2>
				<NcCheckboxRadioSwitch
					:checked="state.translation_provider_enabled"
					@update:checked="onCheckboxChanged($event, 'translation_provider_enabled', false)">
					{{ t('integration_openai', 'Translation provider (to translate Talk messages for example)') }}
				</NcCheckboxRadioSwitch>
				<NcCheckboxRadioSwitch
					:checked="state.llm_provider_enabled"
					@update:checked="onCheckboxChanged($event, 'llm_provider_enabled', false)">
					{{ t('integration_openai', 'Text processing providers (to generate text, summarize, context write etc...)') }}
				</NcCheckboxRadioSwitch>
				<NcCheckboxRadioSwitch
					:checked="state.t2i_provider_enabled"
					@update:checked="onCheckboxChanged($event, 't2i_provider_enabled', false)">
					{{ t('integration_openai', 'Image generation provider') }}
				</NcCheckboxRadioSwitch>
				<NcCheckboxRadioSwitch
					:checked="state.stt_provider_enabled"
					@update:checked="onCheckboxChanged($event, 'stt_provider_enabled', false)">
					{{ t('integration_openai', 'Speech-to-text provider (to transcribe Talk recordings for example)') }}
				</NcCheckboxRadioSwitch>
			</div>
		</div>
	</div>
</template>

<script>
import AccountIcon from 'vue-material-design-icons/Account.vue'
import CloseIcon from 'vue-material-design-icons/Close.vue'
import EarthIcon from 'vue-material-design-icons/Earth.vue'
import HelpCircleIcon from 'vue-material-design-icons/HelpCircle.vue'
import KeyIcon from 'vue-material-design-icons/Key.vue'
import TimerAlertOutlineIcon from 'vue-material-design-icons/TimerAlertOutline.vue'

import OpenAiIcon from './icons/OpenAiIcon.vue'

import NcButton from '@nextcloud/vue/dist/Components/NcButton.js'
import NcCheckboxRadioSwitch from '@nextcloud/vue/dist/Components/NcCheckboxRadioSwitch.js'
import NcInputField from '@nextcloud/vue/dist/Components/NcInputField.js'
import NcNoteCard from '@nextcloud/vue/dist/Components/NcNoteCard.js'
import NcSelect from '@nextcloud/vue/dist/Components/NcSelect.js'
import NcTextField from '@nextcloud/vue/dist/Components/NcTextField.js'

import axios from '@nextcloud/axios'
import { showError, showSuccess } from '@nextcloud/dialogs'
import { loadState } from '@nextcloud/initial-state'
import { confirmPassword } from '@nextcloud/password-confirmation'
import { generateUrl } from '@nextcloud/router'
import debounce from 'debounce'

const DEFAULT_MODEL_ITEM = { id: 'Default' }

export default {
	name: 'AdminSettings',

	components: {
		OpenAiIcon,
		KeyIcon,
		CloseIcon,
		AccountIcon,
		EarthIcon,
		TimerAlertOutlineIcon,
		HelpCircleIcon,
		NcButton,
		NcSelect,
		NcCheckboxRadioSwitch,
		NcTextField,
		NcInputField,
		NcNoteCard,
	},

	data() {
		return {
			state: loadState('integration_openai', 'admin-config'),
			// to prevent some browsers to fill fields with remembered passwords
			readonly: true,
			models: null,
			selectedModel: {
				text: null,
				image: null,
			},
			apiKeyUrl: 'https://platform.openai.com/account/api-keys',
			quotaInfo: null,
			llmExtraParamHint: t('integration_openai', 'JSON object. Check the API documentation to get the list of all available parameters. For example: {example}', { example: '{"stop":".","temperature":0.7}' }, null, { escape: false, sanitize: false }),
			defaultImageSizeParamHint: t('integration_openai', 'Must be in 256x256 format (default is {default})', { default: '512x512' }),
			DEFAULT_MODEL_ITEM,
		}
	},

	computed: {
		isUsingOpenAI() {
			return this.state.url === ''
		},
		configured() {
			return !!this.state.url || !!this.state.api_key || !!this.state.basic_user || !!this.state.basic_password
		},
		formattedModels() {
			if (this.models) {
				return this.models.map(m => {
					return {
						id: m.id,
						value: m.id,
						label: m.id
							+ (m.owned_by ? ' (' + m.owned_by + ')' : ''),
					}
				})
			}
			return []
		},
	},

	mounted() {
		if (this.configured) {
			this.getModels(false)
		}
		this.loadQuotaInfo()
	},

	methods: {
		modelToNcSelectObject(model) {
			return {
				id: model.id,
				value: model.id,
				label: model.id + (model.owned_by ? ' (' + model.owned_by + ')' : ''),
			}
		},

		getModels(shouldSave = true) {
			this.models = null
			if (!this.configured) {
				return
			}
			const url = generateUrl('/apps/integration_openai/models')
			return axios.get(url)
				.then((response) => {
					this.models = response.data?.data ?? []
					if (this.isUsingOpenAI) {
						this.models.unshift(DEFAULT_MODEL_ITEM)
					}
					const defaultCompletionModelId = this.state.default_completion_model_id || response.data?.default_completion_model_id
					const completionModelToSelect = this.models.find(m => m.id === defaultCompletionModelId)
						|| this.models.find(m => m.id === 'gpt-3.5-turbo')
						|| this.models[1]
						|| this.models[0]

					const defaultImageModelId = this.state.default_image_model_id || response.data?.default_image_model_id
					const imageModelToSelect = this.models.find(m => m.id === defaultImageModelId)
						|| this.models.find(m => m.id === 'dall-e-2')
						|| this.models[1]
						|| this.models[0]

					this.selectedModel.text = this.modelToNcSelectObject(completionModelToSelect)
					this.selectedModel.image = this.modelToNcSelectObject(imageModelToSelect)

					// save if url/credentials were changed OR if the values are not up-to-date in the stored settings
					if (shouldSave
						|| this.state.default_completion_model_id !== this.selectedModel.text.id
						|| this.state.default_image_model_id !== this.selectedModel.image.id) {
						this.saveOptions({
							default_completion_model_id: this.selectedModel.text.id,
							default_image_model_id: this.selectedModel.image.id,
						}, false)
					}

					this.state.default_completion_model_id = completionModelToSelect.id
					this.state.default_image_model_id = imageModelToSelect.id
				})
				.catch((error) => {
					showError(t('integration_openai', 'Failed to load models'))
					console.error(error)
				})
		},
		onModelSelected(type, selected) {
			console.debug(`Selected model: ${type}: ${selected}`)
			if (selected == null) {
				if (type === 'image') {
					this.selectedModel.image = this.modelToNcSelectObject(DEFAULT_MODEL_ITEM)
					this.state.default_image_model_id = DEFAULT_MODEL_ITEM.id
				} else if (type === 'text') {
					this.selectedModel.text = this.modelToNcSelectObject(DEFAULT_MODEL_ITEM)
					this.state.default_completion_model_id = DEFAULT_MODEL_ITEM.id
				}
			} else {
				if (type === 'image') {
					this.state.default_image_model_id = selected.id
				} else if (type === 'text') {
					this.state.default_completion_model_id = selected.id
				}
			}
			this.saveOptions({
				default_completion_model_id: this.state.default_completion_model_id,
				default_image_model_id: this.state.default_image_model_id,
			})
		},
		loadQuotaInfo() {
			const url = generateUrl('/apps/integration_openai/admin-quota-info')
			return axios.get(url)
				.then((response) => {
					this.quotaInfo = response.data
				})
				.catch((error) => {
					showError(
						t('integration_openai', 'Failed to load quota info')
						+ ': ' + error.response?.request?.responseText,
					)
				})
		},
		capitalizedWord(word) {
			return word.charAt(0).toUpperCase() + word.slice(1)
		},
		async onCheckboxChanged(newValue, key, getModels = true, sensitive = false) {
			this.state[key] = newValue
			await this.saveOptions({ [key]: this.state[key] }, sensitive)
			if (getModels) {
				this.getModels()
			}
		},
		onSensitiveInput: debounce(async function(getModels = true) {
			const values = {
				basic_user: this.state.basic_user,
				url: this.state.url,
			}
			if (this.state.api_key !== 'dummyApiKey') {
				values.api_key = this.state.api_key
			}
			if (this.state.basic_password !== 'dummyPassword') {
				values.basic_password = this.state.basic_password
			}
			await this.saveOptions(values, true)
			if (getModels) {
				this.getModels()
			}
		}, 2000),
		onInput: debounce(async function() {
			const values = {
				service_name: this.state.service_name,
				request_timeout: parseInt(this.state.request_timeout),
				max_tokens: parseInt(this.state.max_tokens),
				llm_extra_params: this.state.llm_extra_params,
				default_image_size: this.state.default_image_size,
				quota_period: parseInt(this.state.quota_period),
				quotas: this.state.quotas,
			}
			await this.saveOptions(values, false)
		}, 2000),
		async saveOptions(values, sensitive = false, notify = true) {
			if (sensitive) {
				await confirmPassword()
			}

			const req = {
				values,
			}
			const url = sensitive ? generateUrl('/apps/integration_openai/admin-config/sensitive') : generateUrl('/apps/integration_openai/admin-config')
			try {
				await axios.put(url, req)
				if (notify) {
					showSuccess(t('integration_openai', 'OpenAI admin options saved'))
				}
			} catch (error) {
				console.error(error)
				showError(t('integration_openai', 'Failed to save OpenAI admin options'))
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

	h2 {
		display: flex;
		align-items: center;
		.icon {
			margin-right: 8px;
		}
	}

	.radios {
		display: flex;
	}

	.quota-table {
		padding: 4px 8px 4px 8px;
		border: 2px solid var(--color-border);
		border-radius: var(--border-radius);
		.text-cell {
			opacity: 0.5;
		}
		th, td {
			width: 300px;
			text-align: left;
			> input:invalid {
				border-color: var(--color-error);
			}
		}
	}

	.line {
		display: flex;
		align-items: center;
		margin-top: 12px;
		.icon {
			margin-right: 4px;
		}
		&.line-select {
			align-items: end;
		}
		> label {
			width: 350px;
			display: flex;
			align-items: center;
		}
		> input, .input {
			width: 350px;
			margin-top: 0;
		}
		> input:invalid {
			border-color: var(--color-error);
		}
		> input[type='radio'] {
			width: auto;
		}

		&.column {
			flex-direction: column;
			align-items: start;
			gap: 8px;
		}
	}

	.model-select {
		min-width: 350px;
		margin: 0 !important;
	}
}
</style>
