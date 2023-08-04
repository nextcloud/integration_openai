<template>
	<div id="openai_prefs" class="section">
		<h2>
			<OpenAiIcon class="icon" />
			{{ t('integration_openai', 'OpenAI and LocalAI integration') }}
		</h2>
		<div id="openai-content">
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
			<div class="line">
				<label for="openai-api-key">
					<KeyIcon :size="20" class="icon" />
					{{ t('integration_openai', 'API key (optional with LocalAI)') }}
				</label>
				<input id="openai-api-key"
					v-model="state.api_key"
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
			<div v-if="models"
				class="line">
				<label for="size">
					{{ t('integration_openai', 'Default completion model to use') }}
				</label>
				<div class="spacer" />
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
					<NcButton type="tertiary">
						<template #icon>
							<HelpCircleIcon />
						</template>
					</NcButton>
				</a>
				<a v-else
					:title="t('integration_openai', 'More information about LocalAI models')"
					href="https://localai.io/model-compatibility/index.html"
					target="_blank">
					<NcButton type="tertiary">
						<template #icon>
							<HelpCircleIcon />
						</template>
					</NcButton>
				</a>
			</div>
			<div class="line">
				<label for="openai-api-timeout">
					<TimerAlertOutlineIcon :size="20" class="icon" />
					{{ t('integration_openai', 'Request timeout (seconds)') }}
				</label>
				<input id="openai-api-timeout"
					v-model="state.request_timeout"
					type="number"
					@input="onInput(false)">
			</div>
			<div>
				<h3>
					{{ t('integration_openai', 'Select which features you want to enable') }}
				</h3>
				<NcCheckboxRadioSwitch
					:checked="state.whisper_picker_enabled"
					@update:checked="onCheckboxChanged($event, 'whisper_picker_enabled')">
					{{ t('integration_openai', 'Whisper transcription/translation with the Smart Picker') }}
				</NcCheckboxRadioSwitch>
				<NcCheckboxRadioSwitch
					:checked="state.image_picker_enabled"
					@update:checked="onCheckboxChanged($event, 'image_picker_enabled')">
					{{ t('integration_openai', 'Image generation with the Smart Picker') }}
				</NcCheckboxRadioSwitch>
				<NcCheckboxRadioSwitch
					:checked="state.text_completion_picker_enabled"
					@update:checked="onCheckboxChanged($event, 'text_completion_picker_enabled')">
					{{ t('integration_openai', 'Text generation with the Smart Picker') }}
				</NcCheckboxRadioSwitch>
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
		}
	},

	computed: {
		configured() {
			return !!this.state.url || !!this.state.api_key
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
	},

	methods: {
		getModels() {
			const url = generateUrl('/apps/integration_openai/models')
			return axios.get(url)
				.then((response) => {
					this.models = response.data?.data
					const defaultModelId = this.state.default_completion_model_id ?? response.data?.default_completion_model_id
					const defaultModel = this.models.find(m => m.id === defaultModelId)
					const modelToSelect = defaultModel ?? this.models[0] ?? null
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
		onCheckboxChanged(newValue, key) {
			this.state[key] = newValue
			this.saveOptions({ [key]: this.state[key] ? '1' : '0' })
		},
		onInput(getModels = true) {
			delay(() => {
				this.saveOptions({
					api_key: this.state.api_key,
					url: this.state.url,
					request_timeout: this.state.request_timeout,
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
						+ ': ' + error.response?.request?.responseText
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
	}

	.model-select {
		min-width: 350px;
	}
}
</style>
