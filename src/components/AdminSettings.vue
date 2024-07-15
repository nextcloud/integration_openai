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
						@update:value="onInput(true)"
						@trailing-button-click="state.url = '' ; onInput(true)">
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
						@update:value="onInput(false)"
						@trailing-button-click="state.service_name = '' ; onInput(false)" />
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
				<div v-show="state.url !== ''" class="line">
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
						@update:value="onInput(true)"
						@trailing-button-click="state.api_key = '' ; onInput(true)">
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
							@update:value="onInput(true)"
							@trailing-button-click="state.basic_user = '' ; onInput(true)">
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
							@update:value="onInput(true)"
							@trailing-button-click="state.basic_password = '' ; onInput(true)">
							<KeyIcon />
						</NcTextField>
					</div>
				</div>
			</div>
			<div>
				<h2>
					{{ t('integration_openai', 'Text generation') }}
				</h2>
				<div v-show="state.url !== ''" class="line">
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
				<NcNoteCard v-show="state.url !== ''" type="info">
					{{ t('integration_openai', 'Using the chat endpoint may improve text generation quality for "instruction following" fine-tuned models.') }}
				</NcNoteCard>
				<div v-if="models"
					class="line line-select">
					<NcSelect
						v-model="selectedModel"
						class="model-select"
						:options="formattedModels"
						:input-label="t('integration_openai', 'Default completion model to use')"
						:no-wrap="true"
						input-id="openai-model-select"
						@input="onModelSelected" />
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
						@update:value="onInput(false)"
						@trailing-button-click="state.llm_extra_params = '' ; onInput(false)" />
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
						@update:value="onInput(false)"
						@trailing-button-click="state.request_timeout = '' ; onInput(false)">
						<TimerAlertOutlineIcon />
						<template #trailing-button-icon>
							<CloseIcon :size="20" />
						</template>
					</NcInputField>
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
						@update:value="onInput(false)"
						@trailing-button-click="state.quota_period = '' ; onInput(false)">
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
									@input="onInput(false)">
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
						@update:value="onInput(false)"
						@trailing-button-click="state.max_tokens = '' ; onInput(false)">
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
import TimerAlertOutlineIcon from 'vue-material-design-icons/TimerAlertOutline.vue'
import EarthIcon from 'vue-material-design-icons/Earth.vue'
import KeyIcon from 'vue-material-design-icons/Key.vue'
import CloseIcon from 'vue-material-design-icons/Close.vue'
import AccountIcon from 'vue-material-design-icons/Account.vue'
import HelpCircleIcon from 'vue-material-design-icons/HelpCircle.vue'

import OpenAiIcon from './icons/OpenAiIcon.vue'

import NcButton from '@nextcloud/vue/dist/Components/NcButton.js'
import NcSelect from '@nextcloud/vue/dist/Components/NcSelect.js'
import NcCheckboxRadioSwitch from '@nextcloud/vue/dist/Components/NcCheckboxRadioSwitch.js'
import NcTextField from '@nextcloud/vue/dist/Components/NcTextField.js'
import NcInputField from '@nextcloud/vue/dist/Components/NcInputField.js'
import NcNoteCard from '@nextcloud/vue/dist/Components/NcNoteCard.js'

import { loadState } from '@nextcloud/initial-state'
import { generateUrl } from '@nextcloud/router'
import axios from '@nextcloud/axios'
import { delay } from '../utils.js'
import { showSuccess, showError } from '@nextcloud/dialogs'

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

	props: [],

	data() {
		return {
			state: loadState('integration_openai', 'admin-config'),
			// to prevent some browsers to fill fields with remembered passwords
			readonly: true,
			models: null,
			selectedModel: null,
			apiKeyUrl: 'https://platform.openai.com/account/api-keys',
			quotaInfo: null,
			llmExtraParamHint: t('integration_openai', 'JSON object. Check the API documentation to get the list of all available parameters. For example: {example}', { example: '{"stop":".","temperature":0.7}' }, null, { escape: false, sanitize: false }),
		}
	},

	computed: {
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

	watch: {
	},

	mounted() {
		if (this.configured) {
			this.getModels()
		}
		this.loadQuotaInfo()
	},

	methods: {
		getModels() {
			const url = generateUrl('/apps/integration_openai/models')
			return axios.get(url)
				.then((response) => {
					this.models = response.data?.data
					const defaultModelId = this.state.default_completion_model_id ?? response.data?.default_completion_model_id
					const defaultModel = this.models.find(m => m.id === defaultModelId)
					const modelToSelect = defaultModel
						?? this.models.find(m => m.id === 'gpt-3.5-turbo')
						?? this.models[0]
						?? null
					if (modelToSelect) {
						this.selectedModel = {
							id: modelToSelect.id,
							value: modelToSelect.id,
							label: modelToSelect.id
								+ (modelToSelect.owned_by ? ' (' + modelToSelect.owned_by + ')' : ''),
						}
					}
				})
				.catch((error) => {
					console.error(error)
				})
		},
		onModelSelected(selected) {
			if (selected === null) {
				return
			}
			this.state.default_completion_model_id = selected.id
			this.saveOptions({ default_completion_model_id: this.state.default_completion_model_id })
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
		onCheckboxChanged(newValue, key, getModels = true) {
			this.state[key] = newValue
			this.saveOptions({ [key]: this.state[key] }).then(() => {
				if (getModels) {
					this.models = null
					if (this.configured) {
						this.getModels().then(() => {
							const selectedModelId = this.selectedModel?.id ?? ''
							this.saveOptions({ default_completion_model_id: selectedModelId }, false)
						})
					}
				}
			})
		},
		onInput(getModels = true) {
			delay(() => {
				this.saveOptions({
					use_basic_auth: this.state.use_basic_auth,
					api_key: this.state.api_key,
					basic_user: this.state.basic_user,
					basic_password: this.state.basic_password,
					url: this.state.url,
					service_name: this.state.service_name,
					chat_endpoint_enabled: this.state.chat_endpoint_enabled,
					request_timeout: parseInt(this.state.request_timeout),
					max_tokens: parseInt(this.state.max_tokens),
					llm_extra_params: this.state.llm_extra_params,
					quota_period: parseInt(this.state.quota_period),
					quotas: this.state.quotas,
				}).then(() => {
					if (getModels) {
						this.models = null
						if (this.configured) {
							this.getModels().then(() => {
								const selectedModelId = this.selectedModel?.id ?? ''
								this.saveOptions({ default_completion_model_id: selectedModelId }, false)
							})
						}
					}
				})
			}, 2000)()
		},
		saveOptions(values, notify = true) {
			const req = {
				values,
			}
			const url = generateUrl('/apps/integration_openai/admin-config')
			return axios.put(url, req)
				.then((response) => {
					if (notify) {
						showSuccess(t('integration_openai', 'OpenAI admin options saved'))
					}
				})
				.catch((error) => {
					showError(
						t('integration_openai', 'Failed to save OpenAI admin options')
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
	}

	.model-select {
		min-width: 350px;
	}
}
</style>
