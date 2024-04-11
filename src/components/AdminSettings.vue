<template>
	<div id="openai_prefs" class="section">
		<h2>
			<OpenAiIcon class="icon" />
			{{ t('integration_openai', 'OpenAI and LocalAI integration') }}
		</h2>
		<div id="openai-content">
			<div>
				<div class="line">
					<label for="openai-url">
						<EarthIcon :size="20" class="icon" />
						{{ t('integration_openai', 'LocalAI URL (leave empty to use openai.com)') }}
					</label>
					<input id="openai-url"
						v-model="state.url"
						type="text"
						:readonly="readonly"
						:placeholder="t('integration_openai', 'example:') + ' http://localhost:8080'"
						@input="onInput"
						@focus="readonly = false">
				</div>
				<p class="settings-hint">
					<InformationOutlineIcon :size="20" class="icon" />
					{{ t('integration_openai', 'This should be the address of your LocalAI instance from the point of view of your Nextcloud server. This can be a local address with a port like http://localhost:8080') }}
				</p>
				<div v-show="state.url !== ''" class="line">
					<label>
						<EarthIcon :size="20" class="icon" />
						{{ t('integration_openai', 'Choose endpoint: ') }}
					</label>
					<input id="openai-chat-endpoint-yes"
						v-model="state.chat_endpoint_enabled"
						:value="true"
						type="radio"
						name="chat_endpoint"
						@input="onInput">
					<label for="openai-chat-endpoint-yes">
						{{ t('integration_openai', 'Chat completions') }}
					</label>
					<input id="openai-chat-endpoint-no"
						v-model="state.chat_endpoint_enabled"
						:value="false"
						type="radio"
						name="chat_endpoint"
						@input="onInput">
					<label for="openai-chat-endpoint-no">
						{{ t('integration_openai', 'Completions') }}
					</label>
				</div>
				<p v-show="state.url !== ''" class="settings-hint">
					<InformationOutlineIcon :size="20" class="icon" />
					{{ t('integration_openai', 'Using the chat endpoint may improve text generation quality for "instruction following" fine-tuned models.') }}
				</p>
				<div v-if="models"
					class="line">
					<label for="size">
						{{ t('integration_openai', 'Default completion model to use') }}
					</label>
					<NcSelect
						v-model="selectedModel"
						class="model-select"
						:options="formattedModels"
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
					<label for="llm-extra-params">
						{{ t('integration_openai', 'Extra completion model parameters') }}
					</label>
					<NcTextField
						id="llm-extra-params"
						class="input"
						:value.sync="state.llm_extra_params"
						:label-outside="true"
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
					<label for="openai-api-timeout">
						<TimerAlertOutlineIcon :size="20" class="icon" />
						{{ t('integration_openai', 'Request timeout (seconds)') }}
					</label>
					<input id="openai-api-timeout"
						v-model.number="state.request_timeout"
						type="number"
						@input="onInput(false)">
				</div>
			</div>
			<div>
				<h2 class="mid-setting-heading">
					{{ t('integration_openai', 'Authentication') }}
				</h2>
				<div v-show="state.url !== ''" class="line">
					<label>
						{{ t('integration_openai', 'Authentication method') }}
					</label>
					<input id="openai-auth-method-key"
						v-model="state.use_basic_auth"
						:value="false"
						type="radio"
						name="auth_method"
						@input="onInput">
					<label for="openai-auth-method-key">
						{{ t('integration_openai', 'API key') }}
					</label>
					<input id="openai-auth-method-basic"
						v-model="state.use_basic_auth"
						:value="true"
						type="radio"
						name="auth_method"
						@input="onInput">
					<label for="openai-auth-method-basic">
						{{ t('integration_openai', 'Basic Authentication') }}
					</label>
				</div>
				<div v-show="state.url === '' || !state.use_basic_auth" class="line">
					<label for="openai-api-key">
						<KeyIcon :size="20" class="icon" />
						{{ t('integration_openai', 'API key (optional with LocalAI)') }}
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
				<p v-show="state.url === ''" class="settings-hint">
					<InformationOutlineIcon :size="20" class="icon" />
					{{ t('integration_openai', 'You can create an API key in your OpenAI account settings:') }}
					&nbsp;
					<a :href="apiKeyUrl" target="_blank" class="external">
						{{ apiKeyUrl }}
					</a>
				</p>
				<div v-show="state.url !== '' && state.use_basic_auth">
					<div class="line">
						<label for="basic-user">
							<KeyIcon :size="20" class="icon" />
							{{ t('integration_openai', 'Username') }}
						</label>
						<input id="openai-basic-user"
							v-model="state.basic_user"
							autocomplete="off"
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
							autocomplete="off"
							type="password"
							:readonly="readonly"
							:placeholder="t('integration_openai', 'your Basic Auth password')"
							@input="onInput"
							@focus="readonly = false">
					</div>
				</div>
			</div>
			<div>
				<h2 class="mid-setting-heading">
					{{ t('integration_openai', 'Usage limits') }}
				</h2>
				<div class="line">
					<!--Time period in days for the token usage-->
					<label for="openai-api-quota-period">
						{{ t('integration_openai', 'Quota enforcement time period (days)') }}
					</label>
					<input id="openai-api-quota-period"
						v-model.number="state.quota_period"
						type="number"
						@input="onInput(false)">
				</div>
				<div class="line">
					<!--Loop through all quota types and list an input for them on this line-->
					<!--Only enforced if the user has not provided an own API key (in the case of OpenAI)-->
					<label for="openai-api-quotas">
						{{ t('integration_openai', 'Usage quotas per time period') }}
					</label>
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
				</div>
				<div class="line">
					<!--A input for max number of tokens to generate for a single request-->
					<!--Only enforced if the user has not provided an own API key (in the case of OpenAI)-->
					<label for="openai-api-max-tokens">
						<InformationOutlineIcon :size="20" class="icon" />
						{{ t('integration_openai', 'Max new tokens per request') }}
					</label>
					<input id="openai-api-max-tokens"
						v-model.number="state.max_tokens"
						:title="t('integration_openai', 'Maximum number of new tokens generated for a single text generation prompt')"
						type="number"
						@input="onInput(false)">
				</div>
			</div>
			<div>
				<h2 class="mid-setting-heading">
					{{ t('integration_openai', 'Select enabled features') }}
				</h2>
				<NcCheckboxRadioSwitch
					:checked="state.translation_provider_enabled"
					@update:checked="onCheckboxChanged($event, 'translation_provider_enabled')">
					{{ t('integration_openai', 'Translation provider (to translate Talk messages for example)') }}
				</NcCheckboxRadioSwitch>
				<NcCheckboxRadioSwitch
					:checked="state.stt_provider_enabled"
					@update:checked="onCheckboxChanged($event, 'stt_provider_enabled')">
					{{ t('integration_openai', 'Speech-to-text provider (to transcribe Talk recordings for example)') }}
				</NcCheckboxRadioSwitch>
			</div>
		</div>
	</div>
</template>

<script>
import TimerAlertOutlineIcon from 'vue-material-design-icons/TimerAlertOutline.vue'
import InformationOutlineIcon from 'vue-material-design-icons/InformationOutline.vue'
import EarthIcon from 'vue-material-design-icons/Earth.vue'
import KeyIcon from 'vue-material-design-icons/Key.vue'
import HelpCircleIcon from 'vue-material-design-icons/HelpCircle.vue'

import OpenAiIcon from './icons/OpenAiIcon.vue'

import NcButton from '@nextcloud/vue/dist/Components/NcButton.js'
import NcSelect from '@nextcloud/vue/dist/Components/NcSelect.js'
import NcCheckboxRadioSwitch from '@nextcloud/vue/dist/Components/NcCheckboxRadioSwitch.js'
import NcTextField from '@nextcloud/vue/dist/Components/NcTextField.js'

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
		EarthIcon,
		InformationOutlineIcon,
		TimerAlertOutlineIcon,
		HelpCircleIcon,
		NcButton,
		NcSelect,
		NcCheckboxRadioSwitch,
		NcTextField,
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
			llmExtraParamHint: t('integration_openai', 'Check the API documentation to get the list of all available parameters. For example: {example}', { example: '{"stop":".","temperature":0.7}' }, null, { escape: false, sanitize: false }),
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
		onCheckboxChanged(newValue, key) {
			this.state[key] = newValue
			this.saveOptions({ [key]: this.state[key] })
		},
		onInput(getModels = true) {
			delay(() => {
				this.saveOptions({
					use_basic_auth: this.state.use_basic_auth,
					api_key: this.state.api_key,
					basic_user: this.state.basic_user,
					basic_password: this.state.basic_password,
					url: this.state.url,
					chat_endpoint_enabled: this.state.chat_endpoint_enabled,
					request_timeout: this.state.request_timeout,
					max_tokens: this.state.max_tokens,
					llm_extra_params: this.state.llm_extra_params,
					quota_period: this.state.quota_period,
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

	.mid-setting-heading {
		margin-top: 32px;
		text-decoration: underline;
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
		> input:invalid {
			border-color: var(--color-error);
		}
		> input[type='radio'] {
			width: auto;
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
	}

	.model-select {
		min-width: 350px;
	}
}
</style>
