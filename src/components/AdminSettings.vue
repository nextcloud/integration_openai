<!--
  - SPDX-FileCopyrightText: 2022 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div id="watsonx_prefs" class="section">
		<h2>
			{{ t('integration_watsonx', 'Watsonx integration') }}
		</h2>
		<div id="watsonx-content">
			<div>
				<NcNoteCard v-if="!state.assistant_enabled" type="warning">
					{{ t('integration_watsonx', 'The Assistant app is not enabled. You need it to use the features provided by the watsonx integration app.') }}
					<a class="external" :href="appSettingsAssistantUrl" target="_blank">
						{{ t('integration_watsonx', 'Assistant app') }}
					</a>
				</NcNoteCard>
				<NcNoteCard type="info">
					{{ t('integration_watsonx', 'Services with a watsonx.ai-compatible API:') }}
					<div class="services">
						<a class="external" href="https://docs.ionos.com/cloud/ai/ai-model-hub" target="_blank">IONOS AI Model Hub</a>
						<a class="external" href="https://console.groq.com" target="_blank">Groqcloud</a>
						<a class="external" href="https://ollama.com/" target="_blank">Ollama</a>
						<a class="external" href="https://mistral.ai" target="_blank">MistralAI</a>
						<a class="external" href="https://www.plusserver.com/en/ai-platform/" target="_blank">Plusserver</a>
					</div>
				</NcNoteCard>
				<div class="line">
					<NcTextField
						id="watsonx-url"
						class="input"
						:value.sync="state.url"
						:label="t('integration_watsonx', 'Service URL')"
						:placeholder="t('integration_watsonx', 'Example: {example}', { example: 'http://localhost:8080/v1' })"
						:show-trailing-button="!!state.url"
						@update:value="onSensitiveInput(true)"
						@trailing-button-click="state.url = '' ; onSensitiveInput(true)">
						<EarthIcon />
					</NcTextField>
					<NcButton type="tertiary"
						:title="t('integration_watsonx', 'Leave empty to use {watsonxApiUrl}', { watsonxApiUrl: 'https://us-south.ml.cloud.ibm.com/ml/v1' })">
						<template #icon>
							<HelpCircleIcon />
						</template>
					</NcButton>
				</div>
				<NcNoteCard type="info">
					{{ t('integration_watsonx', 'With the current configuration, the target URL used to get the models is:') }}
					<br>
					<strong>{{ modelEndpointUrl }}</strong>
				</NcNoteCard>
				<NcNoteCard type="info">
					{{ t('integration_watsonx', 'This should include the address of your Watsonx instance (or any service implementing an API similar to Watsonx) along with the root path of the API. This URL will be accessed by your Nextcloud server.') }}
					<br>
					{{ t('integration_watsonx', 'This can be a local address with a port like {example}. In this case, make sure \'allow_local_remote_servers\' is set to true in config.php.', { example: 'http://localhost:8080/v1' }) }}
				</NcNoteCard>
				<div v-if="state.url !== ''" class="line">
					<NcTextField
						id="watsonx-service-name"
						class="input"
						:value.sync="state.service_name"
						:label="t('integration_watsonx', 'Service name (optional)')"
						:placeholder="t('integration_watsonx', 'Example: Watsonx of university ABC')"
						:show-trailing-button="!!state.service_name"
						@update:value="onInput()"
						@trailing-button-click="state.service_name = '' ; onInput()" />
					<NcButton type="tertiary"
						:title="t('integration_watsonx', 'This name will be displayed as provider name in the AI admin settings')">
						<template #icon>
							<HelpCircleIcon />
						</template>
					</NcButton>
				</div>
				<div class="line">
					<NcInputField
						id="watsonx-api-timeout"
						class="input"
						:value.sync="state.request_timeout"
						type="number"
						:label="t('integration_watsonx', 'Request timeout (seconds)')"
						:placeholder="t('integration_watsonx', 'Example: {example}', { example: '240' })"
						:show-trailing-button="!!state.request_timeout"
						@update:value="onInput()"
						@trailing-button-click="state.request_timeout = '' ; onInput()">
						<TimerAlertOutlineIcon />
						<template #trailing-button-icon>
							<CloseIcon :size="20" />
						</template>
					</NcInputField>
					<NcButton type="tertiary"
						:title="t('integration_watsonx', 'Timeout for the request to the external API')">
						<template #icon>
							<HelpCircleIcon />
						</template>
					</NcButton>
				</div>
			</div>
			<div>
				<h2>
					{{ t('integration_watsonx', 'Authentication') }}
				</h2>
				<div v-show="state.url !== ''" class="line column">
					<label>
						{{ t('integration_watsonx', 'Authentication method') }}
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
						id="watsonx-api-key"
						class="input"
						:value.sync="state.api_key"
						type="password"
						:readonly="readonly"
						:label="t('integration_watsonx', 'API key (mandatory with Watsonx)')"
						:show-trailing-button="!!state.api_key"
						@update:value="onSensitiveInput(true)"
						@trailing-button-click="state.api_key = '' ; onSensitiveInput(true)"
						@focus="readonly = false">
						<KeyIcon />
					</NcTextField>
				</div>
				<NcNoteCard v-show="state.url === ''" type="info">
					{{ t('integration_watsonx', 'You can create an API key in your IBM Cloud IAM account settings') }}:
					&nbsp;
					<a :href="apiKeyUrl" target="_blank" class="external">
						{{ apiKeyUrl }}
					</a>
				</NcNoteCard>
				<div v-show="state.url !== '' && state.use_basic_auth">
					<div class="line">
						<NcTextField
							id="watsonx-basic-user"
							class="input"
							:value.sync="state.basic_user"
							:readonly="readonly"
							:label="t('integration_watsonx', 'Basic Auth user')"
							:show-trailing-button="!!state.basic_user"
							@update:value="onSensitiveInput(true)"
							@trailing-button-click="state.basic_user = '' ; onSensitiveInput(true)"
							@focus="readonly = false">
							<AccountIcon />
						</NcTextField>
					</div>
					<div class="line">
						<NcTextField
							id="watsonx-basic-password"
							class="input"
							:value.sync="state.basic_password"
							type="password"
							:readonly="readonly"
							:label="t('integration_watsonx', 'Basic Auth password')"
							:show-trailing-button="!!state.basic_password"
							@update:value="onSensitiveInput(true)"
							@trailing-button-click="state.basic_password = '' ; onSensitiveInput(true)"
							@focus="readonly = false">
							<KeyIcon />
						</NcTextField>
					</div>
				</div>
			</div>
			<div>
				<h2>
					{{ t('integration_watsonx', 'Text generation') }}
				</h2>
				<div v-if="state.url !== ''" class="line column">
					<label>
						<EarthIcon :size="20" class="icon" />
						{{ t('integration_watsonx', 'Text completion endpoint') }}
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
						? t('integration_watsonx', 'Selection of chat/completion endpoint is not available for Watsonx since it implicitly uses chat completions for "instruction following" fine-tuned models.')
						: t('integration_watsonx', 'Using the chat endpoint may improve text generation quality for "instruction following" fine-tuned models.') }}
				</NcNoteCard>
				<div v-if="models"
					class="line line-select">
					<NcSelect
						v-model="selectedModel.text"
						class="model-select"
						:clearable="state.default_completion_model_id !== DEFAULT_MODEL_ITEM.id"
						:options="formattedModels"
						:input-label="t('integration_watsonx', 'Default completion model to use')"
						:no-wrap="true"
						input-id="watsonx-model-select"
						@input="onModelSelected('text', $event)" />
					<a v-if="state.url === ''"
						:title="t('integration_watsonx', 'More information about IBM watsonx.ai as a Service')"
						href="https://cloud.ibm.com/apidocs/watsonx-ai"
						target="_blank">
						<NcButton type="tertiary" aria-label="watsonx-info">
							<template #icon>
								<HelpCircleIcon />
							</template>
						</NcButton>
					</a>
					<a v-else
						:title="t('integration_watsonx', 'More information about the IBM watsonx.ai software')"
						href="https://cloud.ibm.com/apidocs/watsonx-ai-cp"
						target="_blank">
						<NcButton type="tertiary" aria-label="watsonx-info">
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
						:label="t('integration_watsonx', 'Extra completion model parameters')"
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
					<!--Input for max chunk size (prompt length) for a single request-->
					<NcInputField
						id="watsonx-chunk-size"
						class="input"
						type="number"
						:value.sync="state.chunk_size"
						:label="t('integration_watsonx', 'Max input tokens per request')"
						:show-trailing-button="!!state.chunk_size"
						@update:value="onInput()"
						@trailing-button-click="state.chunk_size = '' ; onInput()">
						<template #trailing-button-icon>
							<CloseIcon :size="20" />
						</template>
					</NcInputField>
					<NcButton type="tertiary"
						:title="t('integration_watsonx', 'Split the prompt into chunks with each chunk being no more than the specified number of tokens (0 disables chunking)')">
						<template #icon>
							<HelpCircleIcon />
						</template>
					</NcButton>
				</div>
			</div>
			<div>
				<h2>
					{{ t('integration_watsonx', 'Usage limits') }}
				</h2>
				<div class="line">
					<!--Time period in days for the token usage-->
					<NcInputField
						id="watsonx-api-quota-period"
						class="input"
						type="number"
						:value.sync="state.quota_period"
						:label="t('integration_watsonx', 'Quota enforcement time period (days)')"
						:show-trailing-button="!!state.quota_period"
						@update:value="onInput()"
						@trailing-button-click="state.quota_period = '' ; onInput()">
						<template #trailing-button-icon>
							<CloseIcon :size="20" />
						</template>
					</NcInputField>
				</div>
				<h4>
					{{ t('integration_watsonx', 'Usage quotas per time period') }}
				</h4>
				<!--Loop through all quota types and list an input for them on this line-->
				<!--Only enforced if the user has not provided an own API key (in the case of Watsonx)-->
				<table class="quota-table">
					<thead>
						<tr>
							<th width="120px">
								{{ t('integration_watsonx', 'Quota type') }}
							</th>
							<th>{{ t('integration_watsonx', 'Per-user quota / period') }}</th>
							<th v-if="quotaInfo !== null">
								{{ t('integration_watsonx', 'Current system-wide usage / period') }}
							</th>
						</tr>
					</thead>
					<tbody v-if="quotaInfo !== null">
						<tr v-for="(_,index) in state.quotas" :key="index">
							<td class="text-cell">
								{{ quotaInfo[index].type }}
							</td>
							<td>
								<input :id="'watsonx-api-quota-' + index"
									v-model.number="state.quotas[index]"
									:title="t('integration_watsonx', 'A per-user limit for usage of this API type (0 for unlimited)')"
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
					<!--Input for max number of tokens to generate for a single request-->
					<!--Only enforced if the user has not provided an own API key (in the case of Watsonx)-->
					<NcInputField
						id="watsonx-api-max-tokens"
						class="input"
						type="number"
						:value.sync="state.max_tokens"
						:label="t('integration_watsonx', 'Max new tokens per request')"
						:show-trailing-button="!!state.max_tokens"
						@update:value="onInput()"
						@trailing-button-click="state.max_tokens = '' ; onInput()">
						<template #trailing-button-icon>
							<CloseIcon :size="20" />
						</template>
					</NcInputField>
					<NcButton type="tertiary"
						:title="t('integration_watsonx', 'Maximum number of new tokens generated for a single text generation prompt')">
						<template #icon>
							<HelpCircleIcon />
						</template>
					</NcButton>
				</div>
				<NcCheckboxRadioSwitch
					:checked="state.use_max_completion_tokens_param"
					@update:checked="onCheckboxChanged($event, 'use_max_completion_tokens_param', false)">
					{{ t('integration_watsonx', 'Use "{newParam}" parameter instead of the deprecated "{deprecatedParam}"', { newParam: 'max_completion_tokens', deprecatedParam: 'max_tokens' }) }}
				</NcCheckboxRadioSwitch>
			</div>
			<div>
				<h2>
					{{ t('integration_watsonx', 'Select enabled features') }}
				</h2>
				<NcCheckboxRadioSwitch
					:checked="state.llm_provider_enabled"
					@update:checked="onCheckboxChanged($event, 'llm_provider_enabled', false)">
					{{ t('integration_watsonx', 'Text processing providers (to generate text, summarize, context write etc...)') }}
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
			state: loadState('integration_watsonx', 'admin-config'),
			// to prevent some browsers to fill fields with remembered passwords
			readonly: true,
			models: null,
			selectedModel: {
				text: null,
			},
			apiKeyUrl: 'https://cloud.ibm.com/docs/account?topic=account-iamtoken_from_apikey',
			quotaInfo: null,
			llmExtraParamHint: t('integration_watsonx', 'JSON object. Check the API documentation to get the list of all available parameters. For example: {example}', { example: '{"stop":".","temperature":0.7}' }, null, { escape: false, sanitize: false }),
			DEFAULT_MODEL_ITEM,
			appSettingsAssistantUrl: generateUrl('/settings/apps/integration/assistant'),
		}
	},

	computed: {
		modelEndpointUrl() {
			if (this.state.url === '') {
				return 'https://us-south.ml.cloud.ibm.com/ml/v1/text/generation'
			}
			return this.state.url.replace(/\/*$/, '/text/generation')
		},
		isUsingWatsonx() {
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
			const url = generateUrl('/apps/integration_watsonx/models')
			return axios.get(url)
				.then((response) => {
					this.models = response.data?.data ?? []
					if (this.isUsingWatsonx) {
						this.models.unshift(DEFAULT_MODEL_ITEM)
					}
					const defaultCompletionModelId = this.state.default_completion_model_id || response.data?.default_completion_model_id
					const completionModelToSelect = this.models.find(m => m.id === defaultCompletionModelId)
						|| this.models.find(m => m.id === 'gpt-3.5-turbo')
						|| this.models[1]
						|| this.models[0]

					this.selectedModel.text = this.modelToNcSelectObject(completionModelToSelect)

					// save if url/credentials were changed OR if the values are not up-to-date in the stored settings
					if (shouldSave
						|| this.state.default_completion_model_id !== this.selectedModel.text.id) {
						this.saveOptions({
							default_completion_model_id: this.selectedModel.text.id,
						}, false)
					}

					this.state.default_completion_model_id = completionModelToSelect.id
				})
				.catch((error) => {
					showError(
						t('integration_watsonx', 'Failed to load models')
						+ ': ' + this.reduceStars(error.response?.data?.error),
						{ timeout: 10000 },
					)
					console.error(error)
				})
		},
		onModelSelected(type, selected) {
			console.debug(`Selected model: ${type}: ${selected}`)
			if (selected == null) {
				if (type === 'text') {
					this.selectedModel.text = this.modelToNcSelectObject(DEFAULT_MODEL_ITEM)
					this.state.default_completion_model_id = DEFAULT_MODEL_ITEM.id
				}
			} else {
				if (type === 'text') {
					this.state.default_completion_model_id = selected.id
				}
			}
			this.saveOptions({
				default_completion_model_id: this.state.default_completion_model_id,
			})
		},
		loadQuotaInfo() {
			const url = generateUrl('/apps/integration_watsonx/admin-quota-info')
			return axios.get(url)
				.then((response) => {
					this.quotaInfo = response.data
				})
				.catch((error) => {
					showError(
						t('integration_watsonx', 'Failed to load quota info')
						+ ': ' + this.reduceStars(error.response?.data?.error),
						{ timeout: 10000 },
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
				basic_user: (this.state.basic_user ?? '').trim(),
				url: (this.state.url ?? '').trim(),
			}
			if (this.state.api_key !== 'dummyApiKey') {
				values.api_key = (this.state.api_key ?? '').trim()
			}
			if (this.state.basic_password !== 'dummyPassword') {
				values.basic_password = (this.state.basic_password ?? '').trim()
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
				chunk_size: parseInt(this.state.chunk_size),
				max_tokens: parseInt(this.state.max_tokens),
				llm_extra_params: this.state.llm_extra_params,
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
			const url = sensitive ? generateUrl('/apps/integration_watsonx/admin-config/sensitive') : generateUrl('/apps/integration_watsonx/admin-config')
			try {
				await axios.put(url, req)
				if (notify) {
					showSuccess(t('integration_watsonx', 'Watsonx admin options saved'))
				}
			} catch (error) {
				console.error(error)
				showError(
					t('integration_watsonx', 'Failed to save Watsonx admin options')
					+ ': ' + this.reduceStars(error.response?.data?.error),
					{ timeout: 10000 },
				)
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
#watsonx_prefs {
	#watsonx-content {
		margin-left: 40px;
	}

	h2 {
		justify-content: start;
		display: flex;
		align-items: center;
		gap: 8px;
		margin-top: 8px;
	}

	.services {
		display: flex;
		gap: 4px;
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
